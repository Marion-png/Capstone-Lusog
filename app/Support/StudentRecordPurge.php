<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\StudentHealthRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Permanently deletes one school's archived learners whose `delete_after`
 * has passed, together with everything attached to them and every file they
 * uploaded (SIGLA School Year Transition spec, "Archive and scheduled
 * deletion").
 *
 * This is irreversible and there is no undo, so these hold:
 *
 *  - **Who to delete is never decided here.** `StudentRetention` decides, and
 *    it is asked again for each learner immediately before anything of theirs
 *    is touched — a learner restored since the list was drawn up has no
 *    `delete_after` any more and is skipped.
 *  - **One institution at a time, and the scope is required.** Every statement
 *    carries `institution_id`, and with no institution the purge reads and
 *    deletes nothing rather than falling through to every school's children.
 *  - **Files first, then rows, and the whole learner is retried on failure.**
 *    A learner's files are removed before the rows that point at them, so a
 *    file is never left on disk with no row pointing to it; the rows then go
 *    in one transaction. If anything fails the learner is attempted again,
 *    and a file already gone is simply not there to remove the second time.
 *  - **Deletion is by query, not by model.** `Auditable` logs a snapshot of
 *    `attributesToArray()` on every Eloquent delete, and `audit_logs` is
 *    append-only evidence that is never purged — deleting a learner through
 *    the model would copy their name, address, guardian and every health
 *    reading into the one table this policy cannot reach. This class writes
 *    the audit entry itself: **one entry per deletion, with the date and a
 *    count of what was removed, and no name, LRN or health data.**
 *
 * What goes: every `student_health_records` row of the learner (their profile
 * and every placement), the rows keyed to those records or to the LRN —
 * assessments, attendance, follow-ups, Sheet 1 conditions, clinic notes,
 * incident reports, both kinds of consent form with their exclusions,
 * medical certificates, photos, stored FHIR bundles — and the learner's
 * consultation logs with their photographs.
 *
 * A consultation carries no LRN, only the name the clinic typed, so it is
 * matched the way the learner's own profile matches it — by surname and
 * given name — and deleted **only when no other learner at the school could
 * also be the one named**. A visit two learners could share is left rather
 * than guessed at: deleting another child's clinic history would be the one
 * outcome worse than keeping a record a little longer.
 *
 * `medicine_dispenses` is deliberately left behind. A dispense is a movement
 * of the school's stock, not a fact about one child, and deleting it would
 * retroactively change the inventory's history and every MedicineUsage
 * figure read off it.
 */
final class StudentRecordPurge
{
    /** How many times one learner is attempted before the job moves on. */
    private const ATTEMPTS = 3;

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
     * Some do carry a cascading foreign key, but they are deleted explicitly
     * all the same: `feeding_follow_ups` has only an index and would survive,
     * and relying on the database to cascade makes the behaviour depend on
     * whether foreign keys are enforced by the engine the code happens to be
     * running against.
     *
     * table => list of columns holding a file path on the local disk
     *
     * @var array<string, list<string>>
     */
    private const RECORD_KEYED = [
        'health_assessments' => [],
        'feeding_attendances' => [],
        'feeding_follow_ups' => [],
        'feeding_attendance_confirmations' => [],
        'parental_consent_forms' => ['file_path', 'med_cert_path'],
    ];

    /**
     * Delete the given learners at one school — each one only if it is still
     * due when its turn comes.
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
        $summary = ['learners' => 0, 'rows' => 0, 'children' => 0, 'files' => 0, 'deleted' => []];

        $lrns = array_values(array_unique(array_filter(array_map(fn ($l) => trim((string) $l), $lrns), fn (string $l) => $l !== '')));

        if (! $institutionId || $lrns === [] || ! SchemaCache::hasTable('student_health_records')) {
            return $summary;
        }

        $asOf = $asOf ?? CarbonImmutable::now();

        foreach ($lrns as $lrn) {
            $filesRemoved = 0;

            try {
                // A closure, not an arrow function: the count of files removed
                // has to survive every attempt, so it is held by reference.
                $result = retry(
                    self::ATTEMPTS,
                    function () use ($institutionId, $lrn, $trigger, $asOf, &$filesRemoved) {
                        return self::purgeLearner($institutionId, $lrn, $trigger, $asOf, $filesRemoved);
                    },
                    200
                );
            } catch (Throwable $e) {
                // Logged, never shown: the message may carry a path. The next
                // run attempts this learner again; the rest go ahead now.
                report($e);

                continue;
            }

            if ($result === null) {
                continue;
            }

            $summary['learners']++;
            $summary['rows'] += $result['rows'];
            $summary['children'] += $result['children'];
            $summary['files'] += $filesRemoved;
            $summary['deleted'][] = ['lrn' => $lrn, 'last_school_year' => $result['last_school_year'], 'rows' => $result['rows']];
        }

        LearnerPlacement::forget();

        return $summary;
    }

    /**
     * One learner: files, then rows, then the audit entry — or nothing, when
     * the learner is no longer due.
     *
     * @return array{rows: int, children: int, last_school_year: string}|null
     */
    private static function purgeLearner(int $institutionId, string $lrn, string $trigger, CarbonImmutable $asOf, int &$filesRemoved): ?array
    {
        if (! StudentRetention::isDue($institutionId, $lrn, $asOf)) {
            return null;
        }

        $rows = DB::table('student_health_records')
            ->where('institution_id', $institutionId)
            ->where('student_id', $lrn)
            ->get(['id', 'school_year']);

        if ($rows->isEmpty()) {
            return null;
        }

        $recordIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $years = $rows->pluck('school_year')->map(fn ($y) => (string) $y)->filter()->values()->all();
        rsort($years);

        $consultationIds = self::consultationsOf($institutionId, $lrn);

        // Files first: once they are gone, no file can outlive the row that
        // named it. A retry finds them already removed and moves on.
        $paths = array_values(array_unique(array_merge(
            self::collectPaths(self::LRN_KEYED, 'student_lrn', [$lrn], $institutionId),
            self::collectPaths(self::RECORD_KEYED, 'student_health_record_id', $recordIds, null),
            self::consultationPhotoPaths($consultationIds, $institutionId),
        )));

        foreach ($paths as $path) {
            if (EncryptedFileStorage::delete($path)) {
                $filesRemoved++;
            }
        }

        return DB::transaction(function () use ($institutionId, $lrn, $recordIds, $years, $consultationIds, $trigger, $asOf, $filesRemoved): array {
            $children = 0;

            foreach (array_keys(self::RECORD_KEYED) as $table) {
                if (SchemaCache::hasTable($table)) {
                    $children += DB::table($table)->whereIn('student_health_record_id', $recordIds)->delete();
                }
            }

            foreach (array_keys(self::LRN_KEYED) as $table) {
                if (SchemaCache::hasTable($table)) {
                    $children += DB::table($table)
                        ->where('institution_id', $institutionId)
                        ->where('student_lrn', $lrn)
                        ->delete();
                }
            }

            $consultations = 0;
            if ($consultationIds !== []) {
                if (SchemaCache::hasTable('consultation_photos')) {
                    $children += DB::table('consultation_photos')
                        ->where('institution_id', $institutionId)
                        ->whereIn('consultation_id', $consultationIds)
                        ->delete();
                }

                $consultations = DB::table('consultations')
                    ->where('institution_id', $institutionId)
                    ->whereIn('id', $consultationIds)
                    ->delete();
                $children += $consultations;
            }

            $deletedRows = DB::table('student_health_records')
                ->where('institution_id', $institutionId)
                ->whereIn('id', $recordIds)
                ->delete();

            // The date and the counts — never the learner's name, LRN or any
            // of their health data. The trail records that a deletion
            // happened under the policy, not who it was about.
            AuditTrail::record(
                'deleted',
                'StudentHealthRecord',
                null,
                'Deleted an archived learner record under the '.StudentRetention::years().'-year retention policy',
                [
                    'deleted_on' => $asOf->toDateString(),
                    'items_removed' => [
                        'learner_records' => (int) $deletedRows,
                        'attached_rows' => $children - $consultations,
                        'consultations' => $consultations,
                        'files' => $filesRemoved,
                    ],
                    'institution_id' => $institutionId,
                    'retention_years' => StudentRetention::years(),
                    'triggered_by' => $trigger,
                ],
            );

            return ['rows' => (int) $deletedRows, 'children' => $children, 'last_school_year' => $years[0] ?? ''];
        });
    }

    /**
     * The consultation logs that belong to this learner and to no one else.
     *
     * Matched as the learner's profile matches them — the clinic's typed name
     * containing the learner's surname and given name — and kept back when
     * any other learner at the school would match the same visit.
     *
     * @return list<int>
     */
    private static function consultationsOf(int $institutionId, string $lrn): array
    {
        if (! SchemaCache::hasTable('consultations')) {
            return [];
        }

        $names = self::namesOf(
            StudentHealthRecord::query()
                ->where('institution_id', $institutionId)
                ->where('student_id', $lrn)
                ->get(['id', 'student_id', 'student_name', 'student_details'])
        );

        if ($names === []) {
            return [];
        }

        $matches = Consultation::query()
            ->where('institution_id', $institutionId)
            ->get(['id', 'student_name'])
            ->filter(fn (Consultation $visit): bool => self::names($visit->student_name, $names))
            ->values();

        if ($matches->isEmpty()) {
            return [];
        }

        $others = self::namesOf(
            StudentHealthRecord::query()
                ->where('institution_id', $institutionId)
                ->where('student_id', '!=', $lrn)
                ->get(['id', 'student_id', 'student_name'])
        );

        return $matches
            ->reject(fn (Consultation $visit): bool => self::names($visit->student_name, $others))
            ->map(fn (Consultation $visit): int => (int) $visit->id)
            ->values()
            ->all();
    }

    /**
     * Every (surname, given name) pair the rows spell the learner by.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function namesOf($records): array
    {
        $names = [];

        foreach ($records as $record) {
            $details = is_array($record->student_details ?? null) ? $record->student_details : [];
            $last = trim((string) ($details['last_name'] ?? ''));
            $first = trim((string) ($details['first_name'] ?? ''));

            if ($last === '' || $first === '') {
                [$last, $first] = StudentRosterSync::splitStudentName((string) $record->student_name);
            }

            $last = self::normalise($last);
            $first = self::normalise($first);

            if ($last !== '' && $first !== '') {
                $names[$last.'|'.$first] = [$last, $first];
            }
        }

        return array_values($names);
    }

    /** @param  list<array{0: string, 1: string}>  $names */
    private static function names(?string $typed, array $names): bool
    {
        $typed = self::normalise((string) $typed);

        if ($typed === '') {
            return false;
        }

        foreach ($names as [$last, $first]) {
            if (str_contains($typed, $last) && str_contains($typed, $first)) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(trim($name))) ?? '';

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    /** @param  list<int>  $consultationIds */
    private static function consultationPhotoPaths(array $consultationIds, int $institutionId): array
    {
        if ($consultationIds === [] || ! SchemaCache::hasTable('consultation_photos')) {
            return [];
        }

        return DB::table('consultation_photos')
            ->where('institution_id', $institutionId)
            ->whereIn('consultation_id', $consultationIds)
            ->pluck('file_path')
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn (string $path) => $path !== '')
            ->values()
            ->all();
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

        $lrns = array_map('strval', array_keys($due));

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

        $consultationIds = [];
        foreach ($lrns as $lrn) {
            $consultationIds = array_merge($consultationIds, self::consultationsOf($institutionId, $lrn));
        }
        $children += count(array_unique($consultationIds));

        $files = array_merge(
            self::collectPaths(self::LRN_KEYED, 'student_lrn', $lrns, $institutionId),
            self::collectPaths(self::RECORD_KEYED, 'student_health_record_id', $recordIds, null),
            self::consultationPhotoPaths(array_values(array_unique($consultationIds)), $institutionId),
        );

        $candidates = [];
        foreach ($due as $lrn => $standing) {
            $candidates[] = [
                'lrn' => (string) $lrn,
                'last_school_year' => $standing['last_school_year'],
                'deletion_date' => $standing['deletion_date']?->toDateString(),
                'rows' => $rows->where('student_id', (string) $lrn)->count(),
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
     * Archived learners at one school whose records are nearing deletion, as
     * LRN => standing — what the student list warns about.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function nearingAt(?int $institutionId, ?CarbonImmutable $asOf = null): array
    {
        return StudentRetention::nearingAt($institutionId, $asOf);
    }
}
