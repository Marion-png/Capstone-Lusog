<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A change-detection fingerprint over several tables, read in **one** round
 * trip.
 *
 * Every dashboard in this app polls a stamp on a timer to notice what another
 * role has written. The stamp itself carries no personal information — it is a
 * row count and a last-touched timestamp per table — so polling it is cheap in
 * principle. It was not cheap in practice: each watched table cost its own
 * `COUNT(*) / MAX(updated_at)` query, so the School Head's stamp alone was ten
 * round trips, fired every twenty seconds by every open tab. Against a hosted
 * Postgres reached through a tunnel, where a round trip costs a large fraction
 * of a second, that is seconds of database time spent on a poll that usually
 * reports "nothing changed" — and while it is in flight the user's next click
 * is queued behind it.
 *
 * So the counts are gathered with a single UNION ALL. One round trip, whatever
 * the number of tables watched, and the stamp it produces is the same shape as
 * before: `count@last_touched` per table in the order given, hashed.
 *
 * The value is memoized for the length of the request, because a page and its
 * JSON endpoint often ask for the same stamp twice.
 */
final class ChangeStamp
{
    /**
     * @param  list<string>  $tables  watched tables, in a fixed order
     * @param  ?int  $institutionId  scope, applied only to tables that carry the column
     * @param  list<string>  $extra  further parts folded into the hash (e.g. today's date)
     */
    public static function forTables(array $tables, ?int $institutionId, array $extra = []): string
    {
        $key = 'stamp:'.implode(',', $tables).':'.($institutionId ?? '-').':'.implode(',', $extra);

        return RequestMemo::remember($key, function () use ($tables, $institutionId, $extra): string {
            $parts = $extra;

            foreach (self::readCounts($tables, $institutionId) as $part) {
                $parts[] = $part;
            }

            return md5(implode('|', $parts));
        });
    }

    /**
     * One `count@last_touched` string per table, in the order asked.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private static function readCounts(array $tables, ?int $institutionId): array
    {
        $present = array_values(array_filter($tables, fn (string $t): bool => SchemaCache::hasTable($t)));

        $rows = $present === [] ? [] : self::readInOneQuery($present, $institutionId);

        // A driver the single-query form does not cover, or a query that failed:
        // fall back to asking table by table, which is slower but always works.
        if ($rows === null) {
            $rows = [];
            foreach ($present as $table) {
                $rows[$table] = self::readOne($table, $institutionId);
            }
        }

        $parts = [];
        foreach ($tables as $table) {
            $parts[] = in_array($table, $present, true) ? ($rows[$table] ?? '0@') : '-';
        }

        return $parts;
    }

    /**
     * Every watched table's count and last-touched timestamp in a single
     * statement. Returns null when this driver is not covered or the statement
     * fails, so the caller can fall back rather than lose the stamp.
     *
     * @param  list<string>  $tables
     * @return array<string, string>|null
     */
    private static function readInOneQuery(array $tables, ?int $institutionId): ?array
    {
        $connection = DB::connection();

        // Both drivers this app runs on spell the cast the same way. Anything
        // else falls back rather than guessing at its dialect.
        if (! in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return null;
        }

        $grammar = $connection->getQueryGrammar();
        $selects = [];
        $bindings = [];

        foreach ($tables as $table) {
            $touched = SchemaCache::hasColumn($table, 'updated_at') ? 'updated_at' : 'created_at';

            // The table name is carried in the projection so a result row can be
            // matched back to the table it counted. Postgres cannot infer the type
            // of a bare placeholder in a select list, so it is cast explicitly.
            $select = 'select cast(? as text) as stamp_table, count(*) as row_count, '
                .'cast(max('.$grammar->wrap($touched).') as text) as last_touched '
                .'from '.$grammar->wrapTable($table);
            $bindings[] = $table;

            if ($institutionId !== null && SchemaCache::hasColumn($table, 'institution_id')) {
                $select .= ' where '.$grammar->wrap('institution_id').' = ?';
                $bindings[] = $institutionId;
            }

            $selects[] = $select;
        }

        try {
            $rows = $connection->select(implode(' union all ', $selects), $bindings);
        } catch (Throwable $e) {
            // Reported, not swallowed: the caller falls back to a query per
            // table so nothing breaks, but a stamp quietly costing ten round
            // trips again is exactly the regression this class exists to
            // prevent, and it would be invisible otherwise.
            report($e);

            return null;
        }

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->stamp_table] = ((int) $row->row_count).'@'.((string) ($row->last_touched ?? ''));
        }

        return $counts;
    }

    private static function readOne(string $table, ?int $institutionId): string
    {
        $query = DB::table($table);

        if ($institutionId !== null && SchemaCache::hasColumn($table, 'institution_id')) {
            $query->where('institution_id', $institutionId);
        }

        $touched = SchemaCache::hasColumn($table, 'updated_at') ? 'updated_at' : 'created_at';
        $row = $query->selectRaw('COUNT(*) as row_count, MAX('.$touched.') as last_touched')->first();

        return ((int) ($row->row_count ?? 0)).'@'.((string) ($row->last_touched ?? ''));
    }
}
