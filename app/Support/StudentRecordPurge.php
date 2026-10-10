<?php

namespace App\Support;

use App\Models\StudentHealthRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one school's expired learner records, and everything attached to
 * them.
 *
 * This is irreversible and there is no undo, so four things hold:
 *
 *  - **Who to delete is never decided here.** `StudentRetention::dueAt()`
 *    decides, and it refuses to consider a learner enrolled in the current
 *    school year at all. This class is handed a list of LRNs and deletes
 *    exactly those.
 *  - **One institution at a time, and the scope is required.** Every statement
 *    carries `institution_id`, and with no institution the purge reads and
 *    deletes nothing rather than falling through to every school's children.
 *  - **Rows go in one transaction; files go after it commits.** A file delete
 *    cannot be rolled back, so destroying files inside the transaction would
 *    mean a later failure left rows pointing at files that no longer exist —
 *    the one outcome worse than either half failing cleanly. Paths are
 *    collected before the rows are gone and the files are removed only once
 *    the database has committed.
 *  - **Deletion is by query, not by model.** `Auditable` logs
 *    `['snapshot' => $model->attributesToArray()]` on every Eloquent delete,
 *    and `audit_logs` is append-only evidence that is never purged — so
 *    deleting a learner through the model would copy their name, address,
 *    guardian and every health reading into the one table this policy cannot
 *    reach. A mass delete fires no model events, so no snapshot is written;
 *    and because that bypasses Eloquent, this class records the audit entry
 *    itself, carrying only the LRN, the last school year, the date and what
 *    triggered it.
 *
 * Two kinds of data are deliberately left behind, and neither is an oversight:
 *
 *  - **`medicine_dispenses`.** A dispense is a movement of the school's stock,
 *    not a fact about one child; deleting it would retroactively change the
 *    inventory's history and every `MedicineUsage` consumption figure read off
 *    it. The learner's name on the row is encrypted and is not the record's
 *    purpose.
 *  - **`consultations`.** They carry no LRN at all — only an encrypted
 *    `student_name` — so they cannot be matched in SQL, and the app's own name
 *    matcher is documented as best-effort and never a key anything is written
 *    against. Deleting clinic visits on a fuzzy name match could remove
 *    another learner's.
 */
final class StudentRecordPurge
{
    /**
     * Child tables keyed by the learner's LRN + institution.
     *
     * These carry no foreign key to `student_health_records`, so nothing
     * cascades and each must be named here. A table missing from the schema is
     * skipped.
     *
     * table => list of columns holding a file path on the local disk
     *
     * @var array<string, list<string>>
     */
    private const LRN_KEYED = [
        'student_health_conditions' => [],
        'clinic_notes' => [],
        'student_incident_reports' => [],
        'medical_certificates' => ['file_path'],
        'health_consent_forms' => ['paper_form_path'],
        'student_photos' => ['file_path'],
        // The stored copy of every FHIR bundle sent about the learner. The
        // fact that a disclosure happened stays on the audit trail (host,
        // digest, date — nothing personal); the copy of what was disclosed
        // goes with the record it was copied from.
        'fhir_transmissions' => [],
    ];

    /**
     * Child tables keyed by `student_health_record_id`.
     *
     * Three of these do carry a cascading foreign key, but they are deleted
     * explicitly all the same: `feeding_follow_ups` has only an index and
     * would survive, and relying on the database to cascade makes the
     * behaviour depend on whether foreign keys are enforced by the engine the
     * code happens to be running against.
     *
     * table => list of columns holding a file path on the local disk
     *
     * @var array<string, list<string>>
     */
    private const RECORD_KEYED = [
        'health_assessments' => [],
        'feeding_attendances' => [],
        'feeding_follow_ups' => [],
        'parental_consent_forms' => ['file_path', 'med_cert_path'],
    ];

    /**
     * Delete the given learners at one school.
     *
     * @param  list<string>  $lrns
     * @return array{learners: int, rows: int, children: int, files: int, deleted: list<array{lrn: string, last_school_year: string, rows: int}>}
     */
    public static function purge(
        ?int $institutionId,
        array $lrns,
        string $trigger = 'scheduled retention purge',
        ?CarbonImmutable $asOf = null,
    ): array {
        $empty = ['learners' => 0, 'rows' => 0, 'children' => 0, 'files' => 0, 'deleted' => []];

        $lrns = array_values(array_unique(array_filter(array_map('strval', $lrns), fn (string $l) => trim($l) !== '')));

        if (! $institutionId || $lrns === [] || ! SchemaCache::hasTable('student_health_records')) {
            return $empty;
        }

        $asOf = $asOf ?? CarbonImmutable::now();

        /** @var list<string> $files */
        $files = [];
        $summary = $empty;

        DB::transaction(function () use ($institutionId, $lrns, $trigger, $asOf, &$files, &$summary): void {
            // The rows being removed, read before anything is deleted.
            $rows = DB::table('student_health_records')
                ->where('institution_id', $institutionId)
                ->whereIn('student_id', $lrns)
                ->get(['id', 'student_id', 'school_year']);

            if ($rows->isEmpty()) {
                return;
            }

            $recordIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

            // Paths first: once the rows are gone there is nothing left to
            // read them from.
            $files = array_merge(
                self::collectPaths(self::LRN_KEYED, 'student_lrn', $lrns, $institutionId),
                self::collectPaths(self::RECORD_KEYED, 'student_health_record_id', $recordIds, null),
            );

            $children = 0;

            foreach (array_keys(self::RECORD_KEYED) as $table) {
                if (! SchemaCache::hasTable($table)) {
                    continue;
                }
                $children += DB::table($table)->whereIn('student_health_record_id', $recordIds)->delete();
            }

            foreach (array_keys(self::LRN_KEYED) as $table) {
                if (! SchemaCache::hasTable($table)) {
                    continue;
                }
                $children += DB::table($table)
                    ->where('institution_id', $institutionId)
                    ->whereIn('student_lrn', $lrns)
                    ->delete();
            }

            $deletedRows = DB::table('student_health_records')
                ->where('institution_id', $institutionId)
                ->whereIn('id', $recordIds)
                ->delete();

            // One entry per learner, carrying what the policy has to be able
            // to answer for — which learner, how long their record had been
            // dormant, when it went and what took it — and none of their
            // personal or health details. The LRN is the school's own
            // identifier for the child, not a fact about them, and without it
            // the trail could not show that this particular deletion happened.
            $perLearner = [];

            foreach ($rows->groupBy(fn ($row) => (string) $row->student_id) as $lrn => $learnerRows) {
                $years = $learnerRows->pluck('school_year')->map(fn ($y) => (string) $y)->filter()->values()->all();
                rsort($years);
                $last = $years[0] ?? '';

                AuditTrail::record(
                    'deleted',
                    'StudentHealthRecord',
                    null,
                    'Deleted learner record under the '.StudentRetention::years().'-year retention policy',
                    [
                        'student_lrn' => (string) $lrn,
                        'last_school_year' => $last,
                        'school_years_removed' => $years,
                        'rows_removed' => $learnerRows->count(),
                        'institution_id' => $institutionId,
                        'retention_years' => StudentRetention::years(),
                        'deleted_on' => $asOf->toDateString(),
                        'triggered_by' => $trigger,
                    ],
                );

                $perLearner[] = [
                    'lrn' => (string) $lrn,
                    'last_school_year' => $last,
                    'rows' => $learnerRows->count(),
                ];
            }

            $summary = [
                'learners' => count($perLearner),
                'rows' => (int) $deletedRows,
                'children' => $children,
                'files' => 0,
                'deleted' => $perLearner,
            ];
        });

        // Committed, so the rows are certainly gone and the files they pointed
        // at are certainly unreferenced.
        $removed = 0;
        foreach (array_values(array_unique($files)) as $path) {
            if (EncryptedFileStorage::delete($path)) {
                $removed++;
            }
        }

        $summary['files'] = $removed;

        return $summary;
    }

    /**
     * Every stored file path the given rows hold, across the named tables.
     *
     * @param  array<string, list<string>>  $tables
     * @param  list<string>|list<int>  $keys
     * @return list<string>
     */
    private static function collectPaths(array $tables, string $keyColumn, array $keys, ?int $institutionId): array
    {
        $paths = [];

        foreach ($tables as $table => $columns) {
            if ($columns === [] || ! SchemaCache::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter(
                $columns,
                fn (string $column) => SchemaCache::hasColumn($table, $column)
            ));

            if ($present === []) {
                continue;
            }

            $rows = DB::table($table)
                ->whereIn($keyColumn, $keys)
                ->when($institutionId !== null, fn ($q) => $q->where('institution_id', $institutionId))
                ->get($present);

            foreach ($rows as $row) {
                foreach ($present as $column) {
                    $path = trim((string) ($row->{$column} ?? ''));
                    if ($path !== '') {
                        $paths[] = $path;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * What a purge *would* do at one school, without doing any of it.
     *
     * Reads exactly what `purge()` reads, so a dry run cannot report a
     * different set from the one a real run would take.
     *
     * @return array{learners: int, rows: int, children: int, files: int, candidates: list<array{lrn: string, last_school_year: string, deletion_date: ?string, rows: int}>}
     */
    public static function preview(?int $institutionId, ?CarbonImmutable $asOf = null): array
    {
        $due = StudentRetention::dueAt($institutionId, $asOf);
        $blank = ['learners' => 0, 'rows' => 0, 'children' => 0, 'files' => 0, 'candidates' => []];

        if ($due === [] || ! $institutionId) {
            return $blank;
        }

        $lrns = array_keys($due);

        $rows = DB::table('student_health_records')
            ->where('institution_id', $institutionId)
            ->whereIn('student_id', $lrns)
            ->get(['id', 'student_id']);

        $recordIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

        $children = 0;
        foreach (array_keys(self::RECORD_KEYED) as $table) {
            if (SchemaCache::hasTable($table)) {
                $children += DB::table($table)->whereIn('student_health_record_id', $recordIds)->count();
            }
        }
        foreach (array_keys(self::LRN_KEYED) as $table) {
            if (SchemaCache::hasTable($table)) {
                $children += DB::table($table)
                    ->where('institution_id', $institutionId)
                    ->whereIn('student_lrn', $lrns)
                    ->count();
            }
        }

        $files = array_merge(
            self::collectPaths(self::LRN_KEYED, 'student_lrn', $lrns, $institutionId),
            self::collectPaths(self::RECORD_KEYED, 'student_health_record_id', $recordIds, null),
        );

        $candidates = [];
        foreach ($due as $lrn => $standing) {
            $candidates[] = [
                'lrn' => (string) $lrn,
                'last_school_year' => $standing['last_school_year'],
                'deletion_date' => $standing['deletion_date']?->toDateString(),
                'rows' => $rows->where('student_id', $lrn)->count(),
            ];
        }

        return [
            'learners' => count($candidates),
            'rows' => $rows->count(),
            'children' => $children,
            'files' => count(array_unique($files)),
            'candidates' => $candidates,
        ];
    }

    /**
     * Learners at one school whose records are nearing deletion, as
     * LRN => standing — what the student list warns about.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function nearingAt(?int $institutionId, ?CarbonImmutable $asOf = null): array
    {
        if (! $institutionId || ! SchemaCache::hasTable('student_health_records')) {
            return [];
        }

        $asOf = $asOf ?? CarbonImmutable::now();
        $currentSchoolYear = StudentHealthRecord::currentSchoolYear();

        $rows = StudentHealthRecord::query()
            ->where('institution_id', $institutionId)
            ->get(['id', 'student_id', 'school_year', 'institution_id']);

        $nearing = [];

        foreach ($rows->groupBy(fn (StudentHealthRecord $row) => (string) $row->student_id) as $lrn => $learnerRows) {
            if ((string) $lrn === '') {
                continue;
            }

            $standing = StudentRetention::standingFor($learnerRows, $asOf, $currentSchoolYear);

            if ($standing['is_nearing'] || $standing['is_due']) {
                $nearing[(string) $lrn] = $standing;
            }
        }

        return $nearing;
    }
}
