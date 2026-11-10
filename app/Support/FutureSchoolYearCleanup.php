<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Removes test data filed under school years that have not begun.
 *
 * The school-year testing aid (WorkingSchoolYear) let a teacher work in any
 * year, and testers used it to carry learners into 2027-2028 and 2028-2029
 * in November 2026. Those rows made screens open on a year that has not
 * started (testers' report #4). This deletes them, and nothing else:
 *
 *  - **Only years after the calendar's** (SchoolYear::isFuture(), on the
 *    school's clock). The current year and every past year are never touched,
 *    and that includes sections closed early in the current year.
 *  - **One institution at a time.** Every read carries the institution; a
 *    table without its own `institution_id` is reached through that
 *    institution's learner rows. With no institution it reads nothing.
 *  - **What goes:** every row filed under a future year in a table that has
 *    a `school_year`, the rows attached to a future learner row, and — for a
 *    learner who exists only in future years — the rows kept by their LRN
 *    (allergies, conditions, photos…), since nothing would be left to own
 *    them. A teacher's Teaching Assignment set to a future year is cleared
 *    back to the calendar's.
 *  - **Listed before it is done.** plan() reads exactly what run() deletes,
 *    so the dry run cannot show one set and the real run take another.
 *  - **Files first, then rows by query, in one transaction.** As
 *    StudentRecordPurge does: deleting through the models would copy every
 *    learner's personal data into the append-only audit log. One audit entry
 *    records the years and the counts — no name, no LRN.
 */
final class FutureSchoolYearCleanup
{
    /** Every table that files a row under a school year. */
    public const YEAR_TABLES = [
        'student_health_records',
        'parental_consent_forms',
        'health_consent_forms',
        'health_assessments',
        'clinic_notes',
        'student_incident_reports',
        'attendance_imports',
        'feeding_follow_ups',
        'feeding_attendance_confirmations',
        'feeding_weigh_ins',
        'report_reviews',
        'section_closures',
        'health_services_offered',
    ];

    /** Columns holding a file on the local disk, by table, beyond StudentRecordPurge's. */
    private const FILE_COLUMNS = [
        'attendance_imports' => ['stored_path'],
    ];

    /**
     * Tables deleted after the others, in this order, because other rows
     * point at theirs. Every table not named goes first.
     *
     * @var array<string, int>
     */
    private const DELETE_LAST = [
        'learner_medicine_allergies' => 1,
        'student_health_records' => 2,
    ];

    /**
     * What would be removed at one institution: the row ids by table, the
     * accounts to reset, and the files, read once and shared by the dry run
     * and the real run.
     *
     * @return array{institution_id: int, current: string, years: list<string>, rows: array<string, list<int>>, learners: int, future_only: int, accounts: list<array{id: int, role: string, school_year: string}>, files: list<string>}
     */
    public static function plan(int $institutionId): array
    {
        $plan = [
            'institution_id' => $institutionId,
            'current' => SchoolYear::calendar(),
            'years' => [],
            'rows' => [],
            'learners' => 0,
            'future_only' => 0,
            'accounts' => [],
            'files' => [],
        ];

        if ($institutionId <= 0 || ! SchemaCache::hasTable('student_health_records')) {
            return $plan;
        }

        $learnerIds = DB::table('student_health_records')
            ->where('institution_id', $institutionId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // The years on record here that have not begun, from every table that
        // files by year, and from the teachers' own Teaching Assignments.
        $years = [];
        foreach (self::YEAR_TABLES as $table) {
            foreach (self::scoped($table, $institutionId, $learnerIds)?->distinct()->pluck('school_year') ?? [] as $year) {
                $years[] = (string) $year;
            }
        }
        if (SchemaCache::hasColumn('accounts', 'assigned_school_year')) {
            foreach (DB::table('accounts')->where('institution_id', $institutionId)->distinct()->pluck('assigned_school_year') as $year) {
                $years[] = (string) $year;
            }
        }
        $years = array_values(array_unique(array_filter($years, fn (string $year): bool => SchoolYear::isFuture($year))));
        sort($years);
        $plan['years'] = $years;

        if ($years === []) {
            return $plan;
        }

        // Rows filed under those years.
        foreach (self::YEAR_TABLES as $table) {
            $query = self::scoped($table, $institutionId, $learnerIds);
            if ($query !== null) {
                self::add($plan['rows'], $table, $query->whereIn('school_year', $years)->pluck('id'));
            }
        }

        $futureLearners = $plan['rows']['student_health_records'] ?? [];

        // Rows attached to a future learner row, whatever year they carry.
        foreach (self::attachedTables() as $table) {
            if ($futureLearners !== [] && SchemaCache::hasColumn($table, 'student_health_record_id')) {
                self::add($plan['rows'], $table, DB::table($table)->whereIn('student_health_record_id', $futureLearners)->pluck('id'));
            }
        }

        // A learner with no row outside the future years leaves nothing to own
        // what is kept by their LRN, so that goes too.
        $lrns = DB::table('student_health_records')->whereIn('id', $futureLearners)->distinct()->pluck('student_id')->map(fn ($lrn): string => (string) $lrn)->all();
        $kept = $lrns === [] ? [] : DB::table('student_health_records')
            ->where('institution_id', $institutionId)
            ->whereIn('student_id', $lrns)
            ->whereNotIn('school_year', $years)
            ->distinct()
            ->pluck('student_id')
            ->map(fn ($lrn): string => (string) $lrn)
            ->all();
        $futureOnly = array_values(array_diff($lrns, $kept));

        $plan['learners'] = count($lrns);
        $plan['future_only'] = count($futureOnly);

        foreach (array_keys(StudentRecordPurge::LRN_KEYED) as $table) {
            if ($futureOnly !== [] && SchemaCache::hasColumn($table, 'student_lrn') && SchemaCache::hasColumn($table, 'institution_id')) {
                self::add($plan['rows'], $table, DB::table($table)->where('institution_id', $institutionId)->whereIn('student_lrn', $futureOnly)->pluck('id'));
            }
        }

        // A Teaching Assignment set to a year that has not begun.
        if (SchemaCache::hasColumn('accounts', 'assigned_school_year')) {
            $plan['accounts'] = DB::table('accounts')
                ->where('institution_id', $institutionId)
                ->whereIn('assigned_school_year', $years)
                ->orderBy('id')
                ->get(['id', 'role', 'assigned_school_year'])
                ->map(fn ($row): array => ['id' => (int) $row->id, 'role' => (string) $row->role, 'school_year' => (string) $row->assigned_school_year])
                ->all();
        }

        // The files those rows name.
        foreach ($plan['rows'] as $table => $ids) {
            $columns = array_values(array_filter(
                self::fileColumns($table),
                fn (string $column): bool => SchemaCache::hasColumn($table, $column),
            ));

            if ($columns === [] || $ids === []) {
                continue;
            }

            foreach (DB::table($table)->whereIn('id', $ids)->get($columns) as $row) {
                foreach ($columns as $column) {
                    $path = trim((string) ($row->{$column} ?? ''));
                    if ($path !== '') {
                        $plan['files'][] = $path;
                    }
                }
            }
        }
        $plan['files'] = array_values(array_unique($plan['files']));

        return $plan;
    }

    /**
     * Remove what plan() lists. Returns the counts it removed.
     *
     * @param  array<string, mixed>  $plan  what plan() returned for this institution
     * @return array{rows: array<string, int>, accounts: int, files: int}
     */
    public static function run(array $plan, string $trigger): array
    {
        $result = ['rows' => [], 'accounts' => 0, 'files' => 0];
        $institutionId = (int) ($plan['institution_id'] ?? 0);

        if ($institutionId <= 0 || ($plan['years'] ?? []) === []) {
            return $result;
        }

        foreach ($plan['files'] as $path) {
            if (EncryptedFileStorage::delete($path)) {
                $result['files']++;
            }
        }

        DB::transaction(function () use ($plan, $institutionId, $trigger, &$result): void {
            // A row before the row it points at: an allergy check before the
            // allergy, everything before the learner rows.
            $order = array_keys($plan['rows']);
            usort($order, fn (string $a, string $b): int => (self::DELETE_LAST[$a] ?? 0) <=> (self::DELETE_LAST[$b] ?? 0));

            foreach ($order as $table) {
                $ids = $plan['rows'][$table];
                $deleted = 0;

                foreach (array_chunk($ids, 500) as $chunk) {
                    $deleted += DB::table($table)->whereIn('id', $chunk)->delete();
                }

                $result['rows'][$table] = $deleted;
            }

            $accountIds = array_column($plan['accounts'], 'id');
            if ($accountIds !== []) {
                $result['accounts'] = DB::table('accounts')
                    ->where('institution_id', $institutionId)
                    ->whereIn('id', $accountIds)
                    ->update(['assigned_school_year' => null]);
            }

            // The years and the counts — never a learner's name or LRN.
            AuditTrail::record(
                'deleted',
                'SchoolYearData',
                null,
                'Removed test data filed under school years that have not begun',
                [
                    'school_years' => $plan['years'],
                    'current_school_year' => $plan['current'],
                    'items_removed' => $result['rows'],
                    'learners' => $plan['learners'],
                    'learners_only_in_future_years' => $plan['future_only'],
                    'teaching_assignments_reset' => $result['accounts'],
                    'files' => $result['files'],
                    'institution_id' => $institutionId,
                    'triggered_by' => $trigger,
                ],
            );
        });

        LearnerPlacement::forget();

        return $result;
    }

    /**
     * One table's rows at one institution, or null when the table cannot be
     * scoped to it (or does not exist): a table without its own institution is
     * reached through the institution's learner rows.
     *
     * @param  list<int>  $learnerIds
     */
    private static function scoped(string $table, int $institutionId, array $learnerIds): ?Builder
    {
        if (! SchemaCache::hasTable($table) || ! SchemaCache::hasColumn($table, 'school_year')) {
            return null;
        }

        if (SchemaCache::hasColumn($table, 'institution_id')) {
            return DB::table($table)->where('institution_id', $institutionId);
        }

        if (SchemaCache::hasColumn($table, 'student_health_record_id')) {
            return DB::table($table)->whereIn('student_health_record_id', $learnerIds === [] ? [0] : $learnerIds);
        }

        return null;
    }

    /** @return list<string> */
    private static function attachedTables(): array
    {
        return array_values(array_unique([
            ...array_keys(StudentRecordPurge::RECORD_KEYED),
            ...array_keys(StudentRecordPurge::LRN_KEYED),
        ]));
    }

    /** @return list<string> */
    private static function fileColumns(string $table): array
    {
        return self::FILE_COLUMNS[$table]
            ?? StudentRecordPurge::RECORD_KEYED[$table]
            ?? StudentRecordPurge::LRN_KEYED[$table]
            ?? [];
    }

    /**
     * @param  array<string, list<int>>  $rows
     * @param  iterable<mixed>  $ids
     */
    private static function add(array &$rows, string $table, iterable $ids): void
    {
        $merged = $rows[$table] ?? [];

        foreach ($ids as $id) {
            $merged[] = (int) $id;
        }

        $merged = array_values(array_unique($merged));
        sort($merged);

        if ($merged !== []) {
            $rows[$table] = $merged;
        }
    }
}
