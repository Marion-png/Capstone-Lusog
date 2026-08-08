<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Remembers, for the length of one request, which tables and columns exist.
 *
 * Every `Schema::hasTable()` / `hasColumn()` / `getColumnListing()` call is a
 * round trip to the database. That is nearly free against a local SQLite file
 * and anything but free against a hosted Postgres, where a single round trip
 * costs the better part of a second. The controllers here ask the same
 * questions repeatedly inside one request — "does student_health_records
 * exist?" is asked by the middleware, the roster sync, the controller, and the
 * audit trail — and the class adviser dashboard was spending more than twenty
 * seconds on schema introspection alone, enough to blow PHP's execution limit
 * and fail the page outright.
 *
 * The schema cannot change while a request is being served, so caching the
 * answers for that long is safe. The cache is registered as a *scoped*
 * container binding (see AppServiceProvider), which Laravel discards between
 * requests and between queue jobs — so a migration run elsewhere is picked up
 * by the next request, not held stale. `flush()` exists for tests that change
 * the schema mid-process.
 */
class SchemaCache
{
    /** @var array<string, true>|null lowercased table names, loaded in one query */
    private ?array $tables = null;

    /** @var array<string, list<string>> lowercased column names, keyed by table */
    private array $columns = [];

    /** @var array<string, list<string>>|null the whole schema, read in one query */
    private ?array $allColumns = null;

    public static function hasTable(string $table): bool
    {
        return self::instance()->tableExists($table);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        // Schema::hasColumn is case-insensitive; stay compatible with it.
        return in_array(strtolower($column), self::columns($table), true);
    }

    /** @return list<string> lowercased column names */
    public static function columns(string $table): array
    {
        return self::instance()->columnsFor($table);
    }

    /** Forget everything learned so far. For tests that alter the schema. */
    public static function flush(): void
    {
        $cache = self::instance();
        $cache->tables = null;
        $cache->columns = [];
        $cache->allColumns = null;
    }

    private static function instance(): self
    {
        return app(self::class);
    }

    /**
     * The whole table list is read in one query the first time anything asks,
     * rather than one "does this table exist?" round trip per table.
     */
    private function tableExists(string $table): bool
    {
        if ($this->tables === null) {
            $names = [];

            foreach (Schema::getTableListing() as $name) {
                $name = strtolower($name);
                $names[$name] = true;
                // Postgres qualifies names as "public.students"; index the bare
                // name too, since that is what callers pass.
                if (($dot = strrpos($name, '.')) !== false) {
                    $names[substr($name, $dot + 1)] = true;
                }
            }

            $this->tables = $names;
        }

        return isset($this->tables[strtolower($table)]);
    }

    /** @return list<string> */
    private function columnsFor(string $table): array
    {
        $table = strtolower($table);

        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }

        if (! $this->tableExists($table)) {
            return $this->columns[$table] = [];
        }

        // One query for the whole schema where the database offers it, rather
        // than one per table. Falls back to asking table by table.
        $this->allColumns ??= $this->loadAllColumns();

        $columns = $this->allColumns !== null
            ? ($this->allColumns[$table] ?? [])
            : array_map('strtolower', Schema::getColumnListing($table));

        return $this->columns[$table] = array_values($columns);
    }

    /**
     * Every column of every table, in one round trip, via the SQL-standard
     * information_schema. SQLite has no such view — it returns null there and
     * the per-table path takes over, which costs nothing against a local file.
     *
     * @return array<string, list<string>>|null table => lowercased column names
     */
    private function loadAllColumns(): ?array
    {
        $connection = Schema::getConnection();

        $scope = match ($connection->getDriverName()) {
            'pgsql' => 'current_schema()',
            'mysql', 'mariadb' => 'database()',
            default => null,
        };

        if ($scope === null) {
            return null;
        }

        try {
            $rows = $connection->select(
                'select table_name, column_name from information_schema.columns where table_schema = '.$scope
            );
        } catch (Throwable) {
            return null;
        }

        $columns = [];
        foreach ($rows as $row) {
            $columns[strtolower((string) $row->table_name)][] = strtolower((string) $row->column_name);
        }

        return $columns;
    }
}
