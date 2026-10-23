<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthCondition;
use App\Models\StudentHealthRecord;
use App\Support\StudentRosterSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for: re-encoding a student who was promoted to the
 * next grade used to silently overwrite their student_health_records row,
 * clobbering the prior year's baseline/endline nutrition data with no year
 * boundary at all.
 *
 * Promotion now runs through the school-year handover: the outgoing adviser
 * closes the year with the learner marked Promoted, and the next grade's
 * adviser adds them by LRN. The learner's record carries over to a new row
 * for the new year; last year's row is never touched.
 */
class StudentPromotionSchoolYearTest extends TestCase
{
    use RefreshDatabase;

    private const LRN = '123456789012';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->institution = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adviserSession(string $grade, string $section): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'maria.santos',
            'active_institution_id' => $this->institution->id,
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
            'assigned_school_name' => 'Sta. Ana NHS',
        ];
    }

    private function submitStudent(string $grade, string $section, float $weightKg): void
    {
        $this->flushSession();
        $this->withSession($this->adviserSession($grade, $section))->post(route('adviser.store'), [
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'middle_name' => 'R',
            'lrn' => self::LRN,
            'birth_month' => 1,
            'birth_day' => 15,
            'birth_year' => 2014,
            'birthplace' => 'Davao City',
            'parent_guardian' => 'Pedro Dela Cruz',
            'address' => '123 Damaso Suazo St., Davao City',
            'region' => 'Region XI',
            'division' => 'DAVAO CITY',
            'telephone_no' => '09171234567',
            'gender' => 'Male',
            'height_cm' => 140,
            'weight_kg' => $weightKg,
            'grade_level' => $grade,
            'section' => $section,
        ]);
    }

    private function uploadCondition(string $grade, string $section, string $conditionName): void
    {
        $this->flushSession();
        $this->withSession($this->adviserSession($grade, $section))->post(route('medical-certificate.store'), [
            'lrn' => self::LRN,
            'condition_name' => $conditionName,
            'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
        ]);
    }

    /**
     * Grade 7 in 2025-2026, closed out as Promoted before the year ends, then
     * added to Grade 8 by LRN once 2026-2027 has begun.
     */
    private function promote(): void
    {
        Carbon::setTestNow('2025-09-01'); // SY 2025-2026
        $this->submitStudent('Grade 7/SPED', 'SPED-A', 32.0);

        Carbon::setTestNow('2026-03-16'); // still SY 2025-2026: the adviser closes it
        $this->flushSession();
        $this->withSession($this->adviserSession('Grade 7/SPED', 'SPED-A'))
            ->post(route('class-adviser.close-year.store'), [
                'school_year' => '2025-2026',
                'statuses' => [self::LRN => 'promoted'],
            ])
            ->assertRedirect(route('dashboard.class-adviser.close-year'))
            ->assertSessionHas('success');

        Carbon::setTestNow('2026-09-01'); // SY 2026-2027 — the Grade 8 adviser adds the learner
        $this->flushSession();
        $this->withSession($this->adviserSession('Grade 8/SPED', 'SPED-B'))
            ->post(route('adviser.add-to-section'), ['add_lrn' => self::LRN])
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertSessionHas('success');
    }

    #[Test]
    public function promoting_a_student_creates_a_new_years_row_without_touching_last_years_data(): void
    {
        Carbon::setTestNow('2025-09-01'); // SY 2025-2026
        $this->submitStudent('Grade 7/SPED', 'SPED-A', 32.0);

        $this->assertSame(1, StudentHealthRecord::where('student_id', self::LRN)->count());
        $firstYearRecord = StudentHealthRecord::where('student_id', self::LRN)->firstOrFail();
        $this->assertSame('2025-2026', $firstYearRecord->school_year);
        $this->assertSame('Grade 7/SPED / SPED-A', $firstYearRecord->section);
        $this->assertSame(32.0, (float) $firstYearRecord->baseline_weight_kg);

        $this->uploadCondition('Grade 7/SPED', 'SPED-A', 'Asthma');

        $this->promote();

        // The new year's row is opened from last year's record: nothing was
        // typed again, and the learner is waiting no longer.
        $carried = StudentHealthRecord::where('student_id', self::LRN)->where('school_year', '2026-2027')->firstOrFail();
        $this->assertSame('Grade 8/SPED / SPED-B', $carried->section);
        $this->assertSame('Pedro Dela Cruz', $carried->student_details['parent_guardian']);
        $this->assertSame('carried_over', $carried->joined_as);
        $this->assertNull($carried->baseline_weight_kg, 'This year\'s weighing is this year\'s to take.');

        // The Grade 8 adviser records this year's measurements on their own row.
        $this->submitStudent('Grade 8/SPED', 'SPED-B', 36.5);

        $records = StudentHealthRecord::where('student_id', self::LRN)->orderBy('school_year')->get();
        $this->assertCount(2, $records, 'Promotion must create a second row, not overwrite the first.');

        $lastYearRecord = $records->firstWhere('school_year', '2025-2026');
        $thisYearRecord = $records->firstWhere('school_year', '2026-2027');

        $this->assertNotNull($lastYearRecord);
        $this->assertNotNull($thisYearRecord);

        // Last year's data must be untouched — and it records how it ended.
        $this->assertSame('Grade 7/SPED / SPED-A', $lastYearRecord->section);
        $this->assertSame(32.0, (float) $lastYearRecord->baseline_weight_kg);
        $this->assertSame('promoted', $lastYearRecord->end_status);
        $this->assertSame('Grade 8/SPED', $lastYearRecord->wait_grade);

        // This year's row reflects the promotion.
        $this->assertSame('Grade 8/SPED / SPED-B', $thisYearRecord->section);
        $this->assertSame(36.5, (float) $thisYearRecord->baseline_weight_kg);

        // currentForStudent() resolves to the new year, not the old one.
        $current = StudentHealthRecord::currentForStudent(self::LRN, $this->institution->id);
        $this->assertSame($thisYearRecord->id, $current->id);
    }

    /**
     * Re-typing a learner the school already holds is refused rather than
     * silently opening a second record: the record moves with the learner.
     */
    #[Test]
    public function a_learner_from_last_year_cannot_be_re_encoded_through_the_form(): void
    {
        Carbon::setTestNow('2025-09-01');
        $this->submitStudent('Grade 7/SPED', 'SPED-A', 32.0);

        Carbon::setTestNow('2026-09-01');
        $this->submitStudent('Grade 8/SPED', 'SPED-B', 36.5);

        $this->assertSame(1, StudentHealthRecord::where('student_id', self::LRN)->count());
        $this->assertStringContainsString('has not closed the school year', (string) session('error'));
    }

    #[Test]
    public function health_conditions_carry_forward_across_promotion(): void
    {
        Carbon::setTestNow('2025-09-01');
        $this->submitStudent('Grade 7/SPED', 'SPED-A', 32.0);
        $this->uploadCondition('Grade 7/SPED', 'SPED-A', 'Asthma');

        Carbon::setTestNow('2026-03-16');
        $this->flushSession();
        $this->withSession($this->adviserSession('Grade 7/SPED', 'SPED-A'))
            ->post(route('class-adviser.close-year.store'), [
                'school_year' => '2025-2026',
                'statuses' => [self::LRN => 'promoted'],
            ]);

        Carbon::setTestNow('2026-09-01');
        $this->flushSession();
        $this->withSession($this->adviserSession('Grade 8/SPED', 'SPED-B'))
            ->post(route('adviser.add-to-section'), ['add_lrn' => self::LRN]);

        // The condition was recorded against last year but must still be
        // visible for the student now, without being re-entered.
        $conditions = StudentHealthCondition::forStudent(self::LRN, $this->institution->id)->get();
        $this->assertCount(1, $conditions);
        $this->assertSame('Asthma', $conditions->first()->condition_name);
    }

    #[Test]
    public function roster_sync_produces_one_entry_even_with_two_years_of_rows(): void
    {
        $this->promote();

        $this->assertSame(2, StudentHealthRecord::where('student_id', self::LRN)->count());

        $request = request();
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('active_institution_id', $this->institution->id);
        $request->session()->put('school_health_card_records', []);

        StudentRosterSync::syncToSession($request);

        $roster = collect($request->session()->get('school_health_card_records', []));
        $this->assertCount(1, $roster->where('lrn', self::LRN));
    }

    #[Test]
    public function health_history_endpoint_lists_every_school_year_on_file(): void
    {
        $this->promote();

        $this->flushSession();
        $response = $this->withSession($this->adviserSession('Grade 8/SPED', 'SPED-B'))
            ->getJson('/api/student-health-history?lrn='.self::LRN);

        $response->assertStatus(200);
        $years = $response->json('years');

        $this->assertCount(2, $years);
        $this->assertSame('2025-2026', $years[0]['school_year']);
        $this->assertSame('Grade 7/SPED / SPED-A', $years[0]['section']);
        $this->assertFalse($years[0]['is_current']);
        $this->assertSame('2026-2027', $years[1]['school_year']);
        $this->assertSame('Grade 8/SPED / SPED-B', $years[1]['section']);
        $this->assertTrue($years[1]['is_current']);
    }
}
