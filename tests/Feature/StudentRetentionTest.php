<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Archive and scheduled deletion (SIGLA School Year Transition spec, rule 5).
 *
 * A learner who leaves is archived and kept five years from the day they were
 * archived, then permanently deleted with every uploaded file. Nobody else is
 * ever deleted — a learner in a section, or waiting for one, is never a
 * candidate at any age — and restoring a learner cancels the deletion.
 *
 * The two tests that matter most are about what must **not** happen:
 * `a_learner_who_is_not_archived_is_never_touched` and
 * `the_audit_entry_keeps_no_personal_details` — `Auditable` snapshots a whole
 * model on delete into a table that is append-only, so the obvious
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

    /**
     * A learner with one row per school year; the last is archived on the
     * given day, with the deletion date the archive sets.
     */
    private function archivedLearner(string $lrn, array $schoolYears, string $archivedOn = '2025-11-03', ?Institution $school = null, string $name = 'Cruz, Juan'): StudentHealthRecord
    {
        $school ??= $this->school;
        $last = null;

        foreach ($schoolYears as $year) {
            $last = StudentHealthRecord::create([
                'student_id' => $lrn,
                'institution_id' => $school->id,
                'school_year' => $year,
                'section' => 'Grade 9 / Dalton',
                'school_name' => $school->name,
                'student_name' => $name,
            ]);
        }

        $on = CarbonImmutable::parse($archivedOn);
        $last->forceFill([
            'left_at' => $on,
            'end_status' => 'transferred_out',
            'archived_at' => $on,
            'archive_reason' => 'Transferred out',
            'delete_after' => StudentRetention::deletionDateFrom($on)->toDateString(),
        ])->save();

        return $last;
    }

    /** A learner with one row per school year, none of them archived. */
    private function learner(string $lrn, array $schoolYears, ?Institution $school = null): StudentHealthRecord
    {
        $school ??= $this->school;
        $last = null;

        foreach ($schoolYears as $year) {
            $last = StudentHealthRecord::create([
                'student_id' => $lrn,
                'institution_id' => $school->id,
                'school_year' => $year,
                'section' => 'Grade 9 / Dalton',
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

    /** Archived 3 Nov 2025, so deleted from 3 Nov 2030. */
    private function justAfter(string $archivedOn = '2025-11-03'): CarbonImmutable
    {
        return StudentRetention::deletionDateFrom(CarbonImmutable::parse($archivedOn));
    }

    private function justBefore(string $archivedOn = '2025-11-03'): CarbonImmutable
    {
        return StudentRetention::deletionDateFrom(CarbonImmutable::parse($archivedOn))->subDay();
    }

    private function purgeAt(CarbonImmutable $asOf, ?Institution $school = null): array
    {
        $school ??= $this->school;
        $due = StudentRetention::dueAt($school->id, $asOf);

        return StudentRecordPurge::purge($school->id, array_keys($due), 'test', $asOf);
    }

    // ── The window ──────────────────────────────────────────────────────

    /** Five years from the day the learner was archived — and that is configuration. */
    #[Test]
    public function the_deletion_date_is_five_years_after_archiving(): void
    {
        $this->assertSame('2030-11-03', StudentRetention::deletionDateFrom(CarbonImmutable::parse('2025-11-03 14:20'))->toDateString());

        config(['retention.years' => 3]);
        $this->assertSame('2028-11-03', StudentRetention::deletionDateFrom(CarbonImmutable::parse('2025-11-03'))->toDateString());
    }

    /** Inside the window, the archived record stays. */
    #[Test]
    public function an_archived_learner_within_five_years_is_kept(): void
    {
        $this->archivedLearner('100000000001', ['2024-2025', '2025-2026']);

        $result = $this->purgeAt($this->justBefore());

        $this->assertSame(0, $result['learners']);
        $this->assertSame(2, $this->recordCount('100000000001'));
    }

    /** On the deletion date, every one of the learner's rows goes. */
    #[Test]
    public function an_archived_learner_past_the_deletion_date_is_deleted(): void
    {
        $this->archivedLearner('100000000002', ['2024-2025', '2025-2026']);

        $result = $this->purgeAt($this->justAfter());

        $this->assertSame(1, $result['learners']);
        $this->assertSame(2, $result['rows'], 'Both of the learner\'s years go, not just the last.');
        $this->assertSame(0, $this->recordCount('100000000002'));
    }

    /** Restoring a learner clears the deletion date, so the job skips them. */
    #[Test]
    public function a_restored_learner_is_kept(): void
    {
        $archived = $this->archivedLearner('100000000003', ['2025-2026']);

        // They come back the next year: a new placement, the deletion cancelled.
        $archived->forceFill(['archived_at' => null, 'archived_by' => null, 'delete_after' => null])->save();
        $this->learner('100000000003', ['2026-2027']);

        $result = $this->purgeAt($this->justAfter()->addYears(10));

        $this->assertSame(0, $result['learners']);
        $this->assertSame(2, $this->recordCount('100000000003'), 'The old year survives with the new one.');
    }

    /**
     * A learner who is not archived is never a candidate, however old their
     * rows are — in a section this year, waiting for one, or left over from
     * before the handover existed.
     */
    #[Test]
    public function a_learner_who_is_not_archived_is_never_touched(): void
    {
        $current = StudentHealthRecord::currentSchoolYear();
        $this->learner('100000000004', ['2015-2016', $current]);

        $waiting = $this->learner('100000000014', ['2016-2017']);
        $waiting->forceFill(['left_at' => '2017-03-20', 'end_status' => 'promoted', 'wait_grade' => 'Grade 10'])->save();

        $this->learner('100000000024', ['2012-2013']);

        $result = $this->purgeAt(CarbonImmutable::parse('2099-01-01'));

        $this->assertSame(0, $result['learners']);
        $this->assertSame(2, $this->recordCount('100000000004'));
        $this->assertSame(1, $this->recordCount('100000000014'));
        $this->assertSame(1, $this->recordCount('100000000024'));

        $standing = StudentRetention::standingFor(
            StudentHealthRecord::where('student_id', '100000000004')->get(),
            CarbonImmutable::parse('2099-01-01')
        );
        $this->assertSame(StudentRetention::ENROLLED, $standing['standing']);
        $this->assertNull($standing['deletion_date'], 'A learner who is not archived has no deletion date at all.');
    }

    // ── What goes with the record ───────────────────────────────────────

    /**
     * Everything attached to the learner goes — consent forms with their
     * exclusions, documents, incidents, assessments, the consultation logs
     * that are theirs — and every file on disk.
     */
    #[Test]
    public function the_related_records_and_files_are_removed(): void
    {
        Storage::fake('local');

        $record = $this->archivedLearner('100000000005', ['2024-2025'], name: 'Magbanua, Esperanza');
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
            'student_name' => 'Magbanua, Esperanza',
            'consent_exceptions' => 'Dili ang bakuna',
            'paper_form_path' => 'consents/paper.jpg',
        ]);

        // Her clinic visit goes; a visit that names somebody else stays.
        $hers = Consultation::create([
            'institution_id' => $this->school->id,
            'consulted_at' => now()->subYears(2),
            'student_name' => 'Esperanza Magbanua',
            'grade_section' => 'Grade 9 / Dalton',
            'condition' => 'Headache',
            'status' => 'treated',
        ]);
        $theirs = Consultation::create([
            'institution_id' => $this->school->id,
            'consulted_at' => now()->subYears(2),
            'student_name' => 'Reyes, Ana',
            'grade_section' => 'Grade 9 / Dalton',
            'condition' => 'Cough',
            'status' => 'treated',
        ]);

        $files = ['consents/cert.pdf', 'consents/signed.png', 'documents/medcert.pdf', 'consents/paper.jpg'];

        foreach ($files as $path) {
            Storage::disk('local')->put($path, 'encrypted-bytes');
        }

        $result = $this->purgeAt($this->justAfter());

        $this->assertSame(1, $result['learners']);

        // Every table, whether it cascades or not.
        $this->assertSame(0, DB::table('health_assessments')->count(), 'Sheet 1/2 assessment rows remain.');
        $this->assertSame(0, DB::table('parental_consent_forms')->count(), 'Consent forms remain.');
        $this->assertSame(0, DB::table('student_health_conditions')->count(), 'Declared conditions remain.');
        $this->assertSame(0, DB::table('medical_certificates')->count(), 'Medical documents remain.');
        $this->assertSame(0, DB::table('health_consent_forms')->count(), 'Health consent forms and their exclusions remain.');
        $this->assertSame(0, $this->recordCount($lrn));

        $this->assertNull(Consultation::find($hers->id), 'The learner\'s consultation log remains.');
        $this->assertNotNull(Consultation::find($theirs->id), 'Another learner\'s consultation was deleted.');

        // And the actual files, not just the rows that pointed at them.
        $this->assertSame(count($files), $result['files']);
        foreach ($files as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    /** A clinic visit two learners could share is kept rather than guessed at. */
    #[Test]
    public function a_consultation_that_could_name_another_learner_is_kept(): void
    {
        $this->archivedLearner('100000000015', ['2024-2025'], name: 'Santos, Juan');
        // Another learner at the school with the same surname and given name.
        StudentHealthRecord::create([
            'student_id' => '100000000016',
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'section' => 'Grade 8 / Rizal',
            'school_name' => $this->school->name,
            'student_name' => 'Santos, Juan',
        ]);

        $visit = Consultation::create([
            'institution_id' => $this->school->id,
            'consulted_at' => now()->subYears(2),
            'student_name' => 'Juan Santos',
            'grade_section' => 'Grade 9',
            'condition' => 'Fever',
            'status' => 'treated',
        ]);

        $this->assertSame(1, $this->purgeAt($this->justAfter())['learners']);
        $this->assertNotNull(Consultation::find($visit->id));
    }

    /** A file already gone — a retry after a failure, say — does not stop the rest. */
    #[Test]
    public function a_learner_whose_files_are_already_gone_is_still_deleted(): void
    {
        Storage::fake('local');

        $this->archivedLearner('100000000017', ['2024-2025']);
        MedicalCertificate::create([
            'student_lrn' => '100000000017',
            'institution_id' => $this->school->id,
            'file_path' => 'documents/missing.pdf',
            'file_original_name' => 'missing.pdf',
            'uploaded_by_name' => 'Nurse Cruz',
        ]);

        $result = $this->purgeAt($this->justAfter());

        $this->assertSame(1, $result['learners']);
        $this->assertSame(0, $result['files']);
        $this->assertSame(0, $this->recordCount('100000000017'));
        $this->assertSame(0, MedicalCertificate::count());
    }

    /**
     * The trail records that a deletion happened — the date and a count of
     * what was removed — and nothing about the child: no name, no LRN, no
     * health data.
     */
    #[Test]
    public function the_audit_entry_keeps_no_personal_details(): void
    {
        $record = $this->archivedLearner('100000000006', ['2024-2025'], name: 'Magbanua, Esperanza');
        $record->update(['nutritional_status' => 'Severely Wasted']);

        $before = DB::table('audit_logs')->max('id') ?? 0;

        $asOf = $this->justAfter();
        $this->purgeAt($asOf);

        $entries = DB::table('audit_logs')->where('id', '>', $before)->get();
        $this->assertTrue($entries->isNotEmpty(), 'The deletion was not audited at all.');

        $deletions = $entries->where('action', 'deleted')->values();
        $this->assertCount(1, $deletions, 'One audit entry per deletion.');
        $this->assertSame('StudentHealthRecord', $deletions[0]->subject_type);

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
        $this->assertStringNotContainsString('100000000006', $plaintext, "The learner's LRN is in the audit trail.");
        $this->assertStringNotContainsString('Severely Wasted', $plaintext, 'Health data is in the audit trail.');
        $this->assertStringNotContainsString('snapshot', $plaintext, 'A full model snapshot was written.');

        // And it still says when, and how much was removed.
        $details = AuditLog::find($deletions[0]->id)->details;
        $this->assertSame($asOf->toDateString(), $details['deleted_on']);
        $this->assertSame(1, $details['items_removed']['learner_records']);
        $this->assertArrayHasKey('files', $details['items_removed']);
    }

    // ── Safety ──────────────────────────────────────────────────────────

    /** A dry run reports the same set and deletes none of it. */
    #[Test]
    public function the_dry_run_deletes_nothing(): void
    {
        $this->archivedLearner('100000000007', ['2024-2025']);
        $asOf = $this->justAfter();

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
        $this->archivedLearner('100000000008', ['2024-2025']);
        $asOf = $this->justAfter();

        $this->artisan('students:purge-expired', ['--as-of' => $asOf->toDateString()])
            ->assertExitCode(0);

        $this->assertSame(0, $this->recordCount('100000000008'));
    }

    /** The deletion job is scheduled daily. */
    #[Test]
    public function the_deletion_job_runs_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'students:purge-expired'));

        $this->assertNotNull($event, 'The deletion job is not scheduled.');
        $this->assertSame('30 2 * * *', $event->expression);
    }

    /** One school's purge never reaches another's learners. */
    #[Test]
    public function the_purge_is_scoped_to_one_institution(): void
    {
        $this->archivedLearner('100000000009', ['2024-2025']);
        $this->archivedLearner('100000000009', ['2024-2025'], school: $this->otherSchool);

        $asOf = $this->justAfter();

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
        $this->archivedLearner('100000000010', ['2024-2025']);
        $asOf = $this->justAfter();

        $this->assertSame([], StudentRetention::dueAt(null, $asOf));
        $this->assertSame(0, StudentRecordPurge::purge(null, ['100000000010'], 'test', $asOf)['learners']);
        $this->assertSame(1, $this->recordCount('100000000010'));
    }

    /** A learner named to the purge who is not due is not deleted. */
    #[Test]
    public function the_purge_deletes_nobody_who_is_not_due(): void
    {
        $this->archivedLearner('100000000018', ['2024-2025']);
        $this->learner('100000000019', [StudentHealthRecord::currentSchoolYear()]);

        $result = StudentRecordPurge::purge($this->school->id, ['100000000018', '100000000019'], 'test', $this->justBefore());

        $this->assertSame(0, $result['learners']);
        $this->assertSame(1, $this->recordCount('100000000018'));
        $this->assertSame(1, $this->recordCount('100000000019'));
    }

    /** A misconfigured window of zero is refused rather than obeyed. */
    #[Test]
    public function a_zero_year_window_refuses_to_run(): void
    {
        config(['retention.years' => 0]);
        $this->archivedLearner('100000000011', ['2024-2025']);

        $this->artisan('students:purge-expired')->assertExitCode(1);

        $this->assertSame(1, $this->recordCount('100000000011'));
    }

    // ── The warning ─────────────────────────────────────────────────────

    /** A record close to its date is flagged, with the date in the sentence. */
    #[Test]
    public function a_record_nearing_deletion_is_flagged_to_authorised_roles(): void
    {
        $this->archivedLearner('100000000012', ['2024-2025']);

        $standing = StudentRetention::standingFor(
            StudentHealthRecord::where('student_id', '100000000012')->get(),
            $this->justAfter()->subDays(30)
        );

        $this->assertTrue($standing['is_nearing']);
        $this->assertFalse($standing['is_due']);
        $this->assertStringContainsString('3 November 2030', StudentRetention::warningFor($standing));
        $this->assertStringContainsString('if the learner is not enrolled again', StudentRetention::warningFor($standing));

        $this->assertArrayHasKey('100000000012', StudentRecordPurge::nearingAt($this->school->id, $this->justAfter()->subDays(30)));

        // A learner who is not archived is never warned about.
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
