<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\HealthAssessment;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Consultation Log and the nurse's Health Assessments keep school years
 * apart. Each opens on the current year; the School Year filter opens an
 * earlier one, read only. A visit has no school_year column, so its year is
 * the one its date falls in, June to May.
 */
class ClinicSchoolYearTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        // 10 November 2026 in Manila: SY 2026-2027.
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sessionFor(string $role = 'school_nurse', ?Institution $school = null): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Nena Nurse',
            'active_username' => 'nurse.test',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => ($school ?? $this->school)->id,
        ];
    }

    private function visit(string $at, string $name, ?Institution $school = null, string $condition = 'Headache'): Consultation
    {
        return Consultation::create([
            'institution_id' => ($school ?? $this->school)->id,
            'consulted_at' => $at,
            'student_name' => $name,
            'grade_section' => 'Grade 7 - Maingat',
            'condition' => $condition,
            'treatment_given' => 'Rest',
            'status' => 'treated',
            'recorded_by_name' => 'Nena Nurse',
            'recorded_by_role' => 'school_nurse',
        ]);
    }

    #[Test]
    public function a_date_belongs_to_the_school_year_it_falls_in(): void
    {
        $this->assertSame('2025-2026', SchoolYear::containing(Carbon::parse('2026-05-31 23:59:00')));
        $this->assertSame('2026-2027', SchoolYear::containing(Carbon::parse('2026-06-01 00:00:00')));
        $this->assertSame(['2026-06-01 00:00:00', '2027-06-01 00:00:00'], SchoolYear::bounds('2026-2027'));
        $this->assertSame(['2026-2027', '2025-2026', '2024-2025'], SchoolYear::spanning('2024-08-01', '2026-07-01'));
        $this->assertSame([], SchoolYear::spanning(null, null));
        $this->assertCount(11, SchoolYear::spanning('1900-01-01', '2026-07-01'));
    }

    #[Test]
    public function the_log_opens_on_the_current_year_only(): void
    {
        $this->visit('2026-06-01 07:30:00', 'Bagani, Tala');
        $this->visit('2026-05-31 15:00:00', 'Dalisay, Amihan');

        $response = $this->withSession($this->sessionFor())->get(route('dashboard.consultation-log'));

        $response->assertOk()
            ->assertSee('Bagani, Tala')
            ->assertDontSee('Dalisay, Amihan')
            ->assertSee('SY 2026-2027 (current)')
            ->assertSee('<option value="2025-2026"', false)
            ->assertSee('class="btn btn-primary" data-bmodal-open="consultModal"', false);

        $this->assertSame(1, $response->viewData('stats')['total']);
    }

    #[Test]
    public function an_earlier_year_is_its_own_visits_and_read_only(): void
    {
        $this->visit('2026-06-01 07:30:00', 'Bagani, Tala');
        $this->visit('2026-05-31 15:00:00', 'Dalisay, Amihan', condition: 'Fever');
        $this->visit('2025-09-12 10:00:00', 'Malaya, Hiraya', condition: 'Fever');

        $response = $this->withSession($this->sessionFor('clinic_staff'))
            ->get(route('dashboard.consultation-log', ['school_year' => '2025-2026']));

        $response->assertOk()
            ->assertSee('Dalisay, Amihan')
            ->assertSee('Malaya, Hiraya')
            ->assertDontSee('Bagani, Tala')
            ->assertSee('Monthly Consultation Trend')
            ->assertDontSee('class="btn btn-primary" data-bmodal-open="consultModal"', false)
            ->assertDontSee('aria-label="Edit consultation for', false);

        $this->assertSame(2, $response->viewData('stats')['total']);
        $this->assertSame('fever', $response->viewData('topConditionStats')->first()->condition_name);

        $trend = collect($response->viewData('dailyTrend'));
        $this->assertSame('Jun', $trend->first()['label']);
        $this->assertSame('May', $trend->last()['label']);
        $this->assertSame(1, $trend->firstWhere('label', 'Sep')['count']);
        $this->assertSame(1, $trend->last()['count']);

        // The same role edits a visit on the current year.
        $this->withSession($this->sessionFor('clinic_staff'))
            ->get(route('dashboard.consultation-log'))
            ->assertSee('aria-label="Edit consultation for', false);
    }

    #[Test]
    public function a_year_not_on_record_opens_the_current_one(): void
    {
        $this->visit('2026-07-01 08:00:00', 'Bagani, Tala');

        $response = $this->withSession($this->sessionFor())
            ->get(route('dashboard.consultation-log', ['school_year' => '2019-2020']));

        $response->assertOk()->assertSee('Bagani, Tala');
        $this->assertSame('2026-2027', $response->viewData('selectedYear'));
    }

    #[Test]
    public function another_schools_visits_offer_no_year(): void
    {
        $other = Institution::create(['name' => 'Other NHS', 'status' => 'active']);
        $this->visit('2023-08-01 08:00:00', 'Lakan, Bayani', $other);

        $response = $this->withSession($this->sessionFor())->get(route('dashboard.consultation-log'));

        $this->assertSame(['2026-2027'], $response->viewData('schoolYears'));
    }

    #[Test]
    public function the_nurse_reads_an_earlier_years_assessments_read_only(): void
    {
        $record = fn (string $lrn, string $name, string $year) => StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => $lrn,
            'student_name' => $name,
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 7',
            'section' => 'Grade 7 / Maingat',
            'school_year' => $year,
        ]);

        $now = $record('100000000001', 'Bagani, Tala', '2026-2027');
        $then = $record('100000000002', 'Dalisay, Amihan', '2025-2026');
        HealthAssessment::create(['student_health_record_id' => $now->id, 'school_year' => '2026-2027']);
        HealthAssessment::create(['student_health_record_id' => $then->id, 'school_year' => '2025-2026']);

        $current = $this->withSession($this->sessionFor())->get(route('health-assessments.nurse-index'));
        $current->assertOk()
            ->assertSee('Bagani, Tala')
            ->assertDontSee('Dalisay, Amihan')
            ->assertSee('<option value="2025-2026"', false);
        $this->assertSame([$now->id], $current->viewData('assessments')->pluck('student_health_record_id')->all());

        // The learner search in the topbar lists the current roster on every
        // page, so the table is checked through what the page was given.
        $earlier = $this->withSession($this->sessionFor())
            ->get(route('health-assessments.nurse-index', ['school_year' => '2025-2026']));
        $earlier->assertOk()
            ->assertSee('Dalisay, Amihan')
            ->assertSee('Not recorded')
            ->assertDontSee('Fill Health Record')
            ->assertDontSee('Edit Health Record');
        $this->assertSame([$then->id], $earlier->viewData('assessments')->pluck('student_health_record_id')->all());
    }
}
