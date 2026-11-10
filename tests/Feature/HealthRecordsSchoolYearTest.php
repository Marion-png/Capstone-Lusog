<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\StudentVitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Health Records keeps school years apart.
 *
 * The page opens on the current year — the session roster the clinic works
 * in — and its School Year filter opens an earlier year from that year's own
 * rows, read only: no Fill Medical Record, no New Consultation, no Record
 * Vital Signs, and the profile's year-tied lookups read that year.
 */
class HealthRecordsSchoolYearTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function currentYear(): string
    {
        return StudentHealthRecord::currentSchoolYear();
    }

    private function previousYear(): string
    {
        [$start] = explode('-', $this->currentYear());

        return ((int) $start - 1).'-'.(int) $start;
    }

    private function sessionFor(string $role): array
    {
        $session = [
            'active_role' => $role,
            'active_name' => 'Ana Reyes',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];

        if ($role === 'class_adviser') {
            $session['assigned_grade_level'] = 'Grade 8';
            $session['assigned_section'] = 'Mabini';
        }

        return $session;
    }

    private function row(string $lrn, string $first, string $last, string $year, string $grade, string $section, array $details = [], ?int $institutionId = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $institutionId ?? $this->school->id,
            'student_id' => $lrn,
            'student_name' => "{$last}, {$first}",
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => $grade,
            'section' => "{$grade} / {$section}",
            'school_year' => $year,
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
            'student_details' => array_merge([
                'lrn' => $lrn,
                'first_name' => $first,
                'last_name' => $last,
                'grade_level' => $grade,
                'section' => $section,
                'gender' => 'Female',
            ], $details),
        ]);
    }

    /** One learner carried from last year into this one, one who left, one new. */
    private function twoYears(): void
    {
        $this->row('120000000001', 'Liza', 'Bantug', $this->previousYear(), 'Grade 7', 'Mabini', [
            'temperature_c' => '36.8',
            'blood_pressure' => '104/66',
        ])->forceFill(['left_at' => now(), 'end_status' => 'promoted'])->save();
        $this->row('120000000001', 'Liza', 'Bantug', $this->currentYear(), 'Grade 8', 'Mabini', [
            'temperature_c' => '37.3',
        ]);

        $this->row('120000000002', 'Nilo', 'Dagohoy', $this->previousYear(), 'Grade 7', 'Mabini')
            ->forceFill(['left_at' => now(), 'archived_at' => now(), 'end_status' => 'transferred_out'])->save();

        $this->row('120000000003', 'Rosa', 'Malvar', $this->currentYear(), 'Grade 8', 'Mabini');
    }

    #[Test]
    public function the_page_offers_every_school_year_on_record_and_opens_on_the_current_one(): void
    {
        $this->twoYears();

        $html = $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="shrYearFilter"', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$this->currentYear().'"\s+selected/', $html);
        $this->assertStringContainsString('<option value="'.$this->previousYear().'"', $html);

        // This year's roster: the carried-over learner and the new one.
        $this->assertStringContainsString('data-lrn="120000000003"', $html);
        $this->assertStringContainsString('data-lrn="120000000001"', $html);
        $this->assertStringNotContainsString('data-lrn="120000000002"', $html);

        // The clinic's work happens here.
        $this->assertStringContainsString('id="profileFillLink"', $html);
        $this->assertStringContainsString('id="profileConsultLink"', $html);
        $this->assertStringContainsString('id="vitalsEditBtn"', $html);
    }

    #[Test]
    public function an_earlier_year_shows_that_years_learners_read_only(): void
    {
        $this->twoYears();

        $html = $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.student-health-records', ['school_year' => $this->previousYear()]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="'.$this->previousYear().'"\s+selected/', $html);

        // Everyone placed that year, including the learner who left after it;
        // nobody who only joined this year.
        $this->assertStringContainsString('data-lrn="120000000001"', $html);
        $this->assertStringContainsString('data-lrn="120000000002"', $html);
        $this->assertStringNotContainsString('data-lrn="120000000003"', $html);

        // That year's own row: last year's section, not this year's.
        $this->assertStringContainsString('Grade 7', $html);

        // Read only.
        $this->assertStringNotContainsString('id="profileFillLink"', $html);
        $this->assertStringNotContainsString('id="profileConsultLink"', $html);
        $this->assertStringNotContainsString('id="vitalsEditBtn"', $html);
        $this->assertStringNotContainsString('/examine"', $html);

        // The profile's lookups and the MLAT ask for that year.
        $this->assertStringContainsString('school_year='.$this->previousYear(), $html);
    }

    #[Test]
    public function the_session_roster_stays_this_years(): void
    {
        $this->twoYears();

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.student-health-records', ['school_year' => $this->previousYear()]))
            ->assertOk();

        $roster = collect(session('school_health_card_records', []));

        $this->assertSame(['120000000001', '120000000003'], $roster->pluck('lrn')->sort()->values()->all());
        $this->assertSame('Mabini', $roster->firstWhere('lrn', '120000000001')['section']);
        $this->assertSame('Grade 8', $roster->firstWhere('lrn', '120000000001')['grade_level']);
    }

    #[Test]
    public function a_year_not_on_record_opens_the_current_one(): void
    {
        $this->twoYears();

        $html = $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.student-health-records', ['school_year' => '2001-2002']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<option value="2001-2002"', $html);
        $this->assertStringContainsString('data-lrn="120000000003"', $html);
        $this->assertStringContainsString('id="profileFillLink"', $html);
    }

    #[Test]
    public function another_schools_years_and_learners_never_appear(): void
    {
        $this->twoYears();
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);
        $this->row('120000000009', 'Tomas', 'Pinpin', '2019-2020', 'Grade 7', 'Rizal', [], $other->id);

        $html = $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.student-health-records', ['school_year' => '2019-2020']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<option value="2019-2020"', $html);
        $this->assertStringNotContainsString('Pinpin', $html);
    }

    #[Test]
    public function the_vital_signs_read_follows_the_year_and_the_write_stays_on_this_one(): void
    {
        $this->twoYears();

        $this->withSession($this->sessionFor('clinic_teacher'))
            ->getJson(route('student-vitals.show', ['lrn' => '120000000001', 'school_year' => $this->previousYear()]))
            ->assertOk()
            ->assertJsonPath('vitals.temperature_c', '36.8')
            ->assertJsonPath('vitals.blood_pressure', '104/66');

        $this->withSession($this->sessionFor('clinic_teacher'))
            ->getJson(route('student-vitals.show', '120000000001'))
            ->assertOk()
            ->assertJsonPath('vitals.temperature_c', '37.3');

        // A year on the query string never moves the write.
        $this->withSession($this->sessionFor('clinic_teacher'))
            ->postJson(route('student-vitals.store', ['lrn' => '120000000001', 'school_year' => $this->previousYear()]), ['temperature_c' => '38.2'])
            ->assertOk();

        $rows = StudentHealthRecord::where('student_id', '120000000001')->get()->keyBy('school_year');
        $this->assertSame('38.2', StudentVitalSigns::read($rows[$this->currentYear()])['temperature_c']);
        $this->assertSame('36.8', StudentVitalSigns::read($rows[$this->previousYear()])['temperature_c']);
    }

    #[Test]
    public function an_adviser_always_reads_this_year(): void
    {
        $this->twoYears();

        $this->withSession($this->sessionFor('class_adviser'))
            ->getJson(route('student-vitals.show', ['lrn' => '120000000001', 'school_year' => $this->previousYear()]))
            ->assertOk()
            ->assertJsonPath('vitals.temperature_c', '37.3');
    }
}
