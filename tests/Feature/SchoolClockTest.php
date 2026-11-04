<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use App\Support\ClinicDashboard;
use App\Support\FeedingBeneficiarySummary;
use App\Support\SchoolClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The server runs on UTC and the school eight hours ahead of it.
 *
 * Every test here sits at 07:30 on a Thursday morning in Davao, when the server
 * still says it is 23:30 on Wednesday: the first hour of the school day, and the
 * one where a server-clock "today" is the wrong day altogether.
 */
class SchoolClockTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL_TODAY = '2026-11-05';

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-04 23:30:00', 'UTC'));
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Tala Tester',
            'active_username' => 'tala.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => $role === 'class_adviser' ? 'Grade 7' : null,
            'assigned_section' => $role === 'class_adviser' ? 'Maayos' : null,
        ];
    }

    private function learner(string $lrn = '100000000501', array $attributes = []): StudentHealthRecord
    {
        return StudentHealthRecord::create($attributes + [
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Dimaculangan, Tala',
            'student_id' => $lrn,
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Maayos',
            'weight' => 30,
            'bmi_value' => 14.5,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'feeding_enrolled_at' => '2026-08-01',
            'student_details' => ['gender' => 'Female'],
        ]);
    }

    #[Test]
    public function the_clock_reads_the_schools_time(): void
    {
        $this->assertSame(self::SCHOOL_TODAY, SchoolClock::today());
        $this->assertSame('07:30', SchoolClock::now()->format('H:i'));
    }

    #[Test]
    public function the_greeting_is_the_schools(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->assertSee('Good morning');
    }

    #[Test]
    public function a_new_consultation_starts_at_the_schools_time(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/consultation-log')
            ->assertOk()
            ->assertSee('value="'.self::SCHOOL_TODAY.'T07:30"', false);
    }

    #[Test]
    public function a_visit_this_morning_counts_as_today(): void
    {
        Consultation::create([
            'institution_id' => $this->school->id,
            'consulted_at' => self::SCHOOL_TODAY.' 07:15:00',
            'student_name' => 'Dimaculangan, Tala',
            'grade_section' => 'Grade 7 - Maayos',
            'condition' => 'Headache',
            'status' => 'treated',
        ]);

        $reading = ClinicDashboard::read($this->school->id, 'Test School');
        $this->assertSame(1, $reading['stats']['consultations_today'] ?? $reading['consultationsToday'] ?? $reading['today_consultations'] ?? null);
    }

    #[Test]
    public function todays_feeding_marks_are_counted_this_morning(): void
    {
        $record = $this->learner();

        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => self::SCHOOL_TODAY,
            'is_present' => true,
            'source' => FeedingAttendance::SOURCE_MANUAL_ENTRY,
            'needs_review' => false,
        ]);

        $marks = FeedingBeneficiarySummary::marksByRecord(collect([$record]));
        $this->assertCount(1, $marks[$record->id] ?? [], 'A mark for today on the school\'s clock is not a future mark.');
    }

    #[Test]
    public function a_follow_up_can_be_dated_today(): void
    {
        $record = $this->learner();

        $this->withSession($this->sessionFor('feeding_coor'))
            ->from('/dashboard/feedingcor-at-risk')
            ->post(route('feedingcor-at-risk.follow-up.store'), [
                'record_id' => $record->id,
                'student_health_record_id' => $record->id,
                'followed_up_on' => self::SCHOOL_TODAY,
                'status' => 'Monitoring',
            ])
            ->assertSessionDoesntHaveErrors(['followed_up_on']);
    }

    #[Test]
    public function a_medicine_can_be_received_today(): void
    {
        $medicine = Medicine::create([
            'institution_id' => $this->school->id,
            'name' => 'Paracetamol 500mg tablet',
            'stock_quantity' => 10,
            'minimum_threshold' => 2,
            'unit' => 'tablet',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/medicine-inventory')
            ->assertOk()
            ->assertSee('value="'.self::SCHOOL_TODAY.'" max="'.self::SCHOOL_TODAY.'"', false);

        $this->withSession($this->sessionFor('school_nurse'))
            ->from('/dashboard/medicine-inventory')
            ->post(route('medicine-inventory.receive', $medicine), [
                'quantity' => 5,
                'received_at' => self::SCHOOL_TODAY,
                'expiry_date' => '2027-11-05',
                'source' => 'Division Office',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(15, $medicine->fresh()->stock_quantity);
    }

    #[Test]
    public function an_examination_with_no_date_is_stamped_the_schools_today(): void
    {
        $record = $this->learner('100000000502');

        $this->withSession($this->sessionFor('school_nurse'))
            ->post('/nurse/0/examine', ['lrn' => '100000000502'])
            ->assertRedirect();

        $this->assertSame(self::SCHOOL_TODAY, $record->fresh()->examination['date_of_examination']);
    }

    #[Test]
    public function a_success_shows_one_confirmation_on_the_advisers_page(): void
    {
        $html = $this->withSession($this->sessionFor('class_adviser') + ['success' => 'Student enrolled.'])
            ->get('/dashboard/class-adviser')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'id="asbToast"'));
        $this->assertStringNotContainsString('id="successToast"', $html);
    }
}
