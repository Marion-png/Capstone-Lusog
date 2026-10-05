<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\HealthAssessment;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\MedicalCertificate;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthCondition;
use App\Models\StudentHealthRecord;
use App\Support\StudentRecordPurge;
use App\Support\StudentRetention;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The 5-year retention policy — docs/open-decisions.md entry 2, settled.
 *
 * Until now nothing in this application ever deleted a `StudentHealthRecord`,
 * so every test here guards a brand-new destructive path. The two that matter
 * most are the ones about what must **not** happen:
 * `a_learner_on_this_years_roll_is_never_touched` (an enrolled child's history
 * is not deletable by this rule at any age) and
 * `the_audit_entry_keeps_no_personal_details` — because `Auditable` snapshots
 * a whole model on delete into a table that is append-only, so the obvious
 * implementation would have relocated the data rather than deleted it.
 */
class StudentRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private Institution $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Another NHS', 'status' => 'active']);

        config(['retention.years' => 5]);
    }

    /** A learner enrolled in the given school years, one row each. */
    private function learner(string $lrn, array $schoolYears, ?Institution $school = null): StudentHealthRecord
    {
        $school ??= $this->school;
        $last = null;

        foreach ($schoolYears as $year) {
            $last = StudentHealthRecord::create([
                'student_id' => $lrn,
                'institution_id' => $school->id,
                'school_year' => $year,
                'section' => 'Grade 10 - Dalton',
                'school_name' => $school->name,
                'student_name' => 'Cruz, Juan',
            ]);
        }

        return $last;
    }

    private function recordCount(string $lrn, ?Institution $school = null): int
    {
        return StudentHealthRecord::where('student_id', $lrn)
            ->where('institution_id', ($school ?? $this->school)->id)
            ->count();
    }

    /** "2026-2027" closes 31 May 2027, so +5 years is 31 May 2032. */
    private function justAfter(string $schoolYear): CarbonImmutable
    {
        return StudentRetention::deletionDateFor($schoolYear)->addDay();
    }

    private function justBefore(string $schoolYear): CarbonImmutable
    {
        return StudentRetention::deletionDateFor($schoolYear)->subDay();
    }

    private function purgeAt(CarbonImmutable $asOf, ?Institution $school = null): array
    {
        $school ??= $this->school;
        $due = StudentRetention::dueAt($school->id, $asOf);

        return StudentRecordPurge::purge($school->id, array_keys($due), 'test', $asOf);
    }

    // ── The window ──────────────────────────────────────────────────────

    /** The countdown runs from the end of the last enrolled year, not its start. */
    #[Test]
    public function the_deletion_date_is_five_years_after_the_school_year_closes(): void
    {
        $this->assertSame('2032-05-31', StudentRetention::deletionDateFor('2026-2027')->toDateString());
        $this->assertSame('2030-05-31', StudentRetention::deletionDateFor('2024-2025')->toDateString());

        // The window is configuration, not a constant in the code.
        config(['retention.years' => 3]);
        $this->assertSame('2030-05-31', StudentRetention::deletionDateFor('2026-2027')->toDateString());
    }

    /** Inside the window, the record stays. */
    #[Test]
    public function a_learner_not_enrolled_but_within_five_years_is_kept(): void
    {
        $this->learner('100000000001', ['2024-2025']);

        $result = $this->purgeAt($this->justBefore('2024-2025'));

        $this->assertSame(0, $result['learners']);
        $this->assertSame(1, $this->recordCount('100000000001'));
    }

    /** Past the window, with no enrolment since, it goes. */
    #[Test]
    public function a_learner_not_enrolled_past_five_years_is_deleted(): void
    {
        $this->learner('100000000002', ['2023-2024', '2024-2025']);

        $result = $this->purgeAt($this->justAfter('2024-2025'));

        $this->assertSame(1, $result['learners']);
        $this->assertSame(2, $result['rows'], 'Both of the learner\'s years go, not just the last.');
        $this->assertSame(0, $this->recordCount('100000000002'));
    }

    /**
     * Re-enrolment clears the countdown — and does so by construction, since
     * the new row raises the MAX(school_year) the date is derived from. There
     * is no flag to forget to clear.
     */
    #[Test]
    public function a_learner_re_enrolled_before_the_deadline_is_kept(): void
    {
        $this->learner('100000000003', ['2024-2025']);

        // Still inside the window, so nothing happens yet.
        $this->assertSame(0, $this->purgeAt($this->justBefore('2024-2025'))['learners']);

        // They come back.
        $this->learner('100000000003', ['2028-2029']);

        // The date that would have ended the 2024-2025 record passes, and the
        // learner is untouched: their window now runs from 2028-2029.
        $result = $this->purgeAt($this->justAfter('2024-2025'));

        $this->assertSame(0, $result['learners']);
        $this->assertSame(2, $this->recordCount('100000000003'), 'The old year survives with the new one.');

        $standing = StudentRetention::standingFor(
            StudentHealthRecord::where('student_id', '100000000003')->get(),
            $this->justAfter('2024-2025')
        );
        $this->assertSame('2028-2029', $standing['last_school_year']);
        $this->assertFalse($standing['is_due']);
    }

    /**
     * A learner on this year's roll is never a candidate, however old their
     * earliest row is. This is the invariant the whole feature is built
     * around: promotion keeps history, and nothing here may undo that.
     */
    #[Test]
    public function a_learner_on_this_years_roll_is_never_touched(): void
    {
        $current = StudentHealthRecord::currentSchoolYear();
        $this->learner('100000000004', ['2015-2016', $current]);

        // Judged a decade past the first year's deadline.
        $result = $this->purgeAt(CarbonImmutable::parse('2099-01-01'));

        $this->assertSame(0, $result['learners']);
        $this->assertSame(2, $this->recordCount('100000000004'));

        $standing = StudentRetention::standingFor(
            StudentHealthRecord::where('student_id', '100000000004')->get(),
            CarbonImmutable::parse('2099-01-01')
        );
        $this->assertSame(StudentRetention::ENROLLED, $standing['standing']);
        $this->assertNull($standing['deletion_date'], 'An enrolled learner has no deletion date at all.');
    }

    // ── What goes with the record ───────────────────────────────────────

    /** Everything attached to the learner goes, including the files on disk. */
    #[Test]
    public function the_related_records_and_files_are_removed(): void
    {
        Storage::fake('local');

        $record = $this->learner('100000000005', ['2024-2025']);
        $lrn = '100000000005';

        HealthAssessment::create([
            'student_health_record_id' => $record->id,
            'school_year' => '2024-2025',
        ]);

        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'school_year' => '2024-2025',
            'file_path' => 'consents/signed.png',
            'file_original_name' => 'signed.png',
            'uploaded_by_name' => 'Maria Santos',
            'med_cert_path' => 'consents/cert.pdf',
        ]);

        StudentHealthCondition::create([
            'student_lrn' => $lrn,
            'institution_id' => $this->school->id,
            'condition_name' => 'Asthma',
        ]);

        MedicalCertificate::create([
            'student_lrn' => $lrn,
            'institution_id' => $this->school->id,
            'file_path' => 'documents/medcert.pdf',
            'file_original_name' => 'medcert.pdf',
            'uploaded_by_name' => 'Nurse Cruz',
        ]);

        HealthConsentForm::create([
            'student_lrn' => $lrn,
            'institution_id' => $this->school->id,
            'school_year' => '2024-2025',
            'division' => 'Davao City',
            'school_name' => $this->school->name,
            'school_address' => 'Sta. Ana, Davao City',
            'student_name' => 'Cruz, Juan',
            'paper_form_path' => 'consents/paper.jpg',
        ]);

        $files = ['consents/cert.pdf', 'consents/signed.png', 'documents/medcert.pdf', 'consents/paper.jpg'];

        foreach ($files as $path) {
            Storage::disk('local')->put($path, 'encrypted-bytes');
        }

        $result = $this->purgeAt($this->justAfter('2024-2025'));

        $this->assertSame(1, $result['learners']);

        // Every table, whether it cascades or not.
        $this->assertSame(0, DB::table('health_assessments')->count(), 'Sheet 1/2 assessment rows remain.');
        $this->assertSame(0, DB::table('parental_consent_forms')->count(), 'Consent forms remain.');
        $this->assertSame(0, DB::table('student_health_conditions')->count(), 'Declared conditions remain.');
        $this->assertSame(0, DB::table('medical_certificates')->count(), 'Medical documents remain.');
        $this->assertSame(0, DB::table('health_consent_forms')->count(), 'Health consent forms remain.');
        $this->assertSame(0, $this->recordCount($lrn));

        // And the actual files, not just the rows that pointed at them.
        $this->assertSame(count($files), $result['files']);
        foreach ($files as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    /**
     * The trail records that a deletion happened, and nothing about the child.
     *
     * `Auditable` snapshots `attributesToArray()` on every Eloquent delete and
     * `audit_logs` is never purged, so the obvious implementation would have
     * copied the learner's name and health data into the one table this policy
     * cannot reach.
     */
    #[Test]
    public function the_audit_entry_keeps_no_personal_details(): void
    {
        $record = $this->learner('100000000006', ['2024-2025']);
        $record->update(['student_name' => 'Magbanua, Esperanza']);

        $before = DB::table('audit_logs')->max('id') ?? 0;

        $this->purgeAt($this->justAfter('2024-2025'));

        $entries = DB::table('audit_logs')->where('id', '>', $before)->get();
        $this->assertTrue($entries->isNotEmpty(), 'The deletion was not audited at all.');

        $deletion = $entries->firstWhere('action', 'deleted');
        $this->assertNotNull($deletion, 'No deletion entry was recorded.');
        $this->assertSame('StudentHealthRecord', $deletion->subject_type);

        // `details` is cast EncryptedArray, so the raw column is ciphertext.
        // Asserting the name is absent from *that* would pass whatever it held,
        // which is the one way this test could lie — so every entry is read
        // back through the model and compared as plaintext.
        $plaintext = AuditLog::whereIn('id', $entries->pluck('id'))->get()
            ->map(fn (AuditLog $log) => $log->action.'|'.$log->description.'|'.json_encode($log->details))
            ->implode("\n");

        $this->assertNotSame('', $plaintext, 'Nothing could be read back from the trail.');
        $this->assertStringNotContainsString('Magbanua', $plaintext, "The learner's name is in the audit trail.");
        $this->assertStringNotContainsString('Esperanza', $plaintext);
        $this->assertStringNotContainsString('snapshot', $plaintext, 'A full model snapshot was written.');

        // And it still says which learner, when, and what took it.
        $this->assertStringContainsString('100000000006', $plaintext);
        $this->assertStringContainsString('2024-2025', $plaintext);
        $this->assertStringContainsString('retention', $plaintext);
    }

    // ── Safety ──────────────────────────────────────────────────────────

    /** A dry run reports the same set and deletes none of it. */
    #[Test]
    public function the_dry_run_deletes_nothing(): void
    {
        $this->learner('100000000007', ['2024-2025']);
        $asOf = $this->justAfter('2024-2025');

        $preview = StudentRecordPurge::preview($this->school->id, $asOf);

        $this->assertSame(1, $preview['learners']);
        $this->assertSame('100000000007', $preview['candidates'][0]['lrn']);
        $this->assertSame(1, $this->recordCount('100000000007'), 'A preview deleted a record.');

        $this->artisan('students:purge-expired', ['--dry-run' => true, '--as-of' => $asOf->toDateString()])
            ->assertExitCode(0);

        $this->assertSame(1, $this->recordCount('100000000007'), 'The dry run deleted a record.');
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'deleted')->count(), 'A dry run wrote a deletion entry.');
    }

    /** The command deletes for real without --dry-run. */
    #[Test]
    public function the_command_deletes_when_not_a_dry_run(): void
    {
        $this->learner('100000000008', ['2024-2025']);
        $asOf = $this->justAfter('2024-2025');

        $this->artisan('students:purge-expired', ['--as-of' => $asOf->toDateString()])
            ->assertExitCode(0);

        $this->assertSame(0, $this->recordCount('100000000008'));
    }

    /** One school's purge never reaches another's learners. */
    #[Test]
    public function the_purge_is_scoped_to_one_institution(): void
    {
        $this->learner('100000000009', ['2024-2025']);
        $this->learner('100000000009', ['2024-2025'], $this->otherSchool);

        $asOf = $this->justAfter('2024-2025');

        $result = $this->purgeAt($asOf, $this->school);

        $this->assertSame(1, $result['learners']);
        $this->assertSame(0, $this->recordCount('100000000009', $this->school));
        $this->assertSame(1, $this->recordCount('100000000009', $this->otherSchool), "Another school's learner was deleted.");

        // Named explicitly, the other school's run is also limited to itself.
        $this->artisan('students:purge-expired', [
            '--institution' => $this->otherSchool->id,
            '--as-of' => $asOf->toDateString(),
        ])->assertExitCode(0);

        $this->assertSame(0, $this->recordCount('100000000009', $this->otherSchool));
    }

    /** Without an institution the rule reads nothing, rather than everything. */
    #[Test]
    public function an_unscoped_call_reads_and_deletes_nothing(): void
    {
        $this->learner('100000000010', ['2024-2025']);
        $asOf = $this->justAfter('2024-2025');

        $this->assertSame([], StudentRetention::dueAt(null, $asOf));
        $this->assertSame(0, StudentRecordPurge::purge(null, ['100000000010'], 'test', $asOf)['learners']);
        $this->assertSame(1, $this->recordCount('100000000010'));
    }

    /** A misconfigured window of zero is refused rather than obeyed. */
    #[Test]
    public function a_zero_year_window_refuses_to_run(): void
    {
        config(['retention.years' => 0]);
        $this->learner('100000000011', ['2024-2025']);

        $this->artisan('students:purge-expired')->assertExitCode(1);

        $this->assertSame(1, $this->recordCount('100000000011'));
    }

    // ── The warning ─────────────────────────────────────────────────────

    /** A record close to its date is flagged, with the date in the sentence. */
    #[Test]
    public function a_record_nearing_deletion_is_flagged_to_authorised_roles(): void
    {
        $this->learner('100000000012', ['2024-2025']);

        $standing = StudentRetention::standingFor(
            StudentHealthRecord::where('student_id', '100000000012')->get(),
            StudentRetention::deletionDateFor('2024-2025')->subDays(30)
        );

        $this->assertTrue($standing['is_nearing']);
        $this->assertFalse($standing['is_due']);
        $this->assertStringContainsString('31 May 2030', StudentRetention::warningFor($standing));
        $this->assertStringContainsString('if the learner is not enrolled again', StudentRetention::warningFor($standing));

        // An enrolled learner is never warned about.
        $this->learner('100000000013', [StudentHealthRecord::currentSchoolYear()]);
        $enrolled = StudentRetention::standingFor(
            StudentHealthRecord::where('student_id', '100000000013')->get()
        );
        $this->assertSame('', StudentRetention::warningFor($enrolled));

        // And the notice is role-gated.
        $this->assertTrue(StudentRetention::maySeeNotice('school_nurse'));
        $this->assertTrue(StudentRetention::maySeeNotice('school_head'));
        $this->assertFalse(StudentRetention::maySeeNotice('feeding_coor'));
        $this->assertFalse(StudentRetention::maySeeNotice(null));
    }
}
