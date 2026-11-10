<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Testers' report #4: test rows filed under 2027–2028 and 2028–2029 in
 * November 2026. `school-year:remove-future` lists them, and with --force
 * removes them — and nothing filed under the current or a past year.
 */
class FutureSchoolYearCleanupTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private Institution $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-09 10:00:00', 'Asia/Manila'));
        Storage::fake('local');

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Another NHS', 'status' => 'active']);
    }

    private function row(string $lrn, string $year, ?Institution $school = null, string $section = 'Grade 7 / RIZAL'): StudentHealthRecord
    {
        $school ??= $this->school;

        return StudentHealthRecord::create([
            'institution_id' => $school->id,
            'school_year' => $year,
            'student_name' => 'Magbanua, Esperanza',
            'student_id' => $lrn,
            'school_name' => $school->name,
            'section' => $section,
            'student_details' => ['first_name' => 'Esperanza', 'last_name' => 'Magbanua', 'gender' => 'Female'],
        ]);
    }

    private function consent(StudentHealthRecord $record, string $path): ParentalConsentForm
    {
        Storage::disk('local')->put($path, 'encrypted bytes');

        return ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type' => ParentalConsentForm::PROGRAM_HEALTH_SERVICES,
            'school_year' => $record->school_year,
            'consent_type' => 'full',
            'file_path' => $path,
            'uploaded_by_name' => 'Teacher Cruz',
        ]);
    }

    private function allergy(string $lrn): int
    {
        return (int) DB::table('learner_medicine_allergies')->insertGetId([
            'institution_id' => $this->school->id,
            'student_lrn' => $lrn,
            'entered_text' => 'Amoxicillin',
            'match_status' => 'matched',
            'source' => 'consent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function closure(string $year): int
    {
        return (int) DB::table('section_closures')->insertGetId([
            'institution_id' => $this->school->id,
            'school_year' => $year,
            'grade_level' => 'Grade 7',
            'section' => 'RIZAL',
            'closed_at' => now(),
            'closed_by' => 'Teacher Cruz',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function account(string $username, ?string $year): int
    {
        return (int) DB::table('accounts')->insertGetId([
            'name' => 'Teacher '.$username,
            'username' => $username,
            'role' => 'class_adviser',
            'institution_id' => $this->school->id,
            'school_name' => $this->school->name,
            'assigned_school_year' => $year,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> everything the scenario names */
    private function scenario(): array
    {
        // Carried into 2027-2028 by the testing aid: this year's row stays.
        $carriedNow = $this->row('500000000001', '2026-2027');
        $carriedNext = $this->row('500000000001', '2027-2028', section: 'Grade 8 / RIZAL');
        // Enrolled only in 2028-2029: nothing of theirs is left afterwards.
        $futureOnly = $this->row('500000000002', '2028-2029', section: 'Grade 9 / RIZAL');
        // Last year and this year: never touched.
        $past = $this->row('500000000003', '2025-2026');
        $pastNow = $this->row('500000000003', '2026-2027');
        // Another school's future row, outside --institution.
        $elsewhere = $this->row('500000000009', '2027-2028', $this->otherSchool);

        return [
            'keep_rows' => [$carriedNow->id, $past->id, $pastNow->id, $elsewhere->id],
            'gone_rows' => [$carriedNext->id, $futureOnly->id],
            'keep_consent' => $this->consent($carriedNow, 'parental-consents/'.$carriedNow->id.'/now.jpg'),
            'gone_consent' => $this->consent($carriedNext, 'parental-consents/'.$carriedNext->id.'/next.jpg'),
            'keep_allergy' => $this->allergy('500000000001'),
            'gone_allergy' => $this->allergy('500000000002'),
            'keep_closure' => $this->closure('2026-2027'),
            'gone_closure' => $this->closure('2027-2028'),
            'reset_account' => $this->account('teacher.next', '2027-2028'),
            'reset_account_2' => $this->account('teacher.later', '2028-2029'),
            'keep_account' => $this->account('teacher.past', '2025-2026'),
        ];
    }

    #[Test]
    public function the_dry_run_lists_what_would_go_and_deletes_nothing(): void
    {
        $s = $this->scenario();
        $rows = StudentHealthRecord::count();
        $auditBefore = DB::table('audit_logs')->max('id') ?? 0;

        $this->artisan('school-year:remove-future', ['--institution' => $this->school->id])
            ->expectsOutputToContain('Current school year (Asia/Manila calendar): 2026-2027')
            ->expectsOutputToContain('school years not yet begun: 2027-2028, 2028-2029')
            ->expectsOutputToContain('account #'.$s['reset_account'].' (class_adviser) — 2027-2028')
            ->expectsOutputToContain('[DRY RUN] Nothing was deleted.')
            ->assertSuccessful();

        $this->assertSame($rows, StudentHealthRecord::count());
        $this->assertNotNull(ParentalConsentForm::find($s['gone_consent']->id));
        Storage::disk('local')->assertExists('parental-consents/'.$s['gone_rows'][0].'/next.jpg');
        $this->assertSame('2027-2028', DB::table('accounts')->where('id', $s['reset_account'])->value('assigned_school_year'));
        $this->assertSame(0, DB::table('audit_logs')->where('id', '>', $auditBefore)->where('action', 'deleted')->count());
    }

    #[Test]
    public function force_removes_only_what_is_filed_under_a_year_that_has_not_begun(): void
    {
        $s = $this->scenario();

        $this->artisan('school-year:remove-future', ['--institution' => $this->school->id, '--force' => true])
            ->expectsOutputToContain('Done.')
            ->assertSuccessful();

        // Future-year rows, what hangs off them, and the future-only learner's
        // LRN-kept rows are gone, files first.
        $this->assertSame(0, StudentHealthRecord::whereIn('id', $s['gone_rows'])->count());
        $this->assertNull(ParentalConsentForm::find($s['gone_consent']->id));
        Storage::disk('local')->assertMissing('parental-consents/'.$s['gone_rows'][0].'/next.jpg');
        $this->assertFalse(DB::table('learner_medicine_allergies')->where('id', $s['gone_allergy'])->exists());
        $this->assertFalse(DB::table('section_closures')->where('id', $s['gone_closure'])->exists());
        $this->assertNull(DB::table('accounts')->where('id', $s['reset_account'])->value('assigned_school_year'));
        $this->assertNull(DB::table('accounts')->where('id', $s['reset_account_2'])->value('assigned_school_year'));

        // This year, last year and the other school are untouched.
        $this->assertSame(count($s['keep_rows']), StudentHealthRecord::whereIn('id', $s['keep_rows'])->count());
        $this->assertNotNull(ParentalConsentForm::find($s['keep_consent']->id));
        Storage::disk('local')->assertExists('parental-consents/'.$s['keep_rows'][0].'/now.jpg');
        $this->assertTrue(DB::table('learner_medicine_allergies')->where('id', $s['keep_allergy'])->exists());
        $this->assertTrue(DB::table('section_closures')->where('id', $s['keep_closure'])->exists());
        $this->assertSame('2025-2026', DB::table('accounts')->where('id', $s['keep_account'])->value('assigned_school_year'));
        $this->assertSame(['2026-2027', '2025-2026'], StudentHealthRecord::where('institution_id', $this->school->id)
            ->distinct()->orderByDesc('school_year')->pluck('school_year')->all());

        // Run again: nothing left to do.
        $this->artisan('school-year:remove-future', ['--institution' => $this->school->id])
            ->expectsOutputToContain('Nothing is filed under a school year that has not begun.')
            ->assertSuccessful();
    }

    /** One entry, with the years and the counts — no name, no LRN. */
    #[Test]
    public function the_audit_entry_keeps_no_personal_details(): void
    {
        $this->scenario();
        $before = DB::table('audit_logs')->max('id') ?? 0;

        $this->artisan('school-year:remove-future', ['--institution' => $this->school->id, '--force' => true])->assertSuccessful();

        $entries = AuditLog::where('id', '>', $before)->where('subject_type', 'SchoolYearData')->get();
        $this->assertCount(1, $entries);

        $plaintext = $entries->map(fn (AuditLog $log) => $log->description.'|'.json_encode($log->details))->implode("\n");
        $this->assertStringNotContainsString('Magbanua', $plaintext);
        $this->assertStringNotContainsString('500000000001', $plaintext);
        $this->assertStringNotContainsString('500000000002', $plaintext);

        $details = $entries[0]->details;
        $this->assertSame(['2027-2028', '2028-2029'], $details['school_years']);
        $this->assertSame(2, $details['items_removed']['student_health_records']);
        $this->assertSame(1, $details['items_removed']['parental_consent_forms']);
        $this->assertSame(1, $details['learners_only_in_future_years']);
        $this->assertSame(2, $details['teaching_assignments_reset']);
        $this->assertSame(1, $details['files']);
    }
}
