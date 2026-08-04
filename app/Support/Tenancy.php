<?php

namespace App\Support;

use App\Models\Institution;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Points the `tenant` database connection at one institution's private database.
 *
 * Every school owns a physically separate PostgreSQL database, so cross-school
 * data mixing is impossible by construction rather than by remembering to add a
 * WHERE clause: a model bound to the tenant connection can only ever see rows in
 * the database this class last bound.
 *
 * The row-level `institution_id` scoping stays in place on top of this as a
 * second layer — see the Multi-School Data Separation invariant in CLAUDE.md.
 */
class Tenancy
{
    /**
     * Institution id whose database is currently bound, if any.
     */
    private static ?int $current = null;

    /**
     * Database name for an institution.
     */
    public static function databaseFor(int $institutionId): string
    {
        return Config::get('tenancy.database_prefix').$institutionId;
    }

    /**
     * The connection name school-owned data lives on.
     *
     * Single source of truth for both the TenantConnection trait and raw query
     * builders — under shared-database mode this collapses to the default
     * connection so tests run on one database.
     */
    public static function connectionName(): string
    {
        return Config::get('tenancy.shared_database')
            ? Config::get('database.default')
            : 'tenant';
    }

    /**
     * Query builder for a school-owned table.
     *
     * Use this instead of `DB::table()` for anything school-owned. A bare
     * `DB::table()` runs on the default connection, which is the central
     * database — it would silently read nothing and write to the wrong place.
     */
    public static function table(string $table): Builder
    {
        return DB::connection(self::connectionName())->table($table);
    }

    /**
     * Schema builder for the bound institution's database, for `hasTable()` and
     * `hasColumn()` checks against school-owned tables.
     */
    public static function schema(): SchemaBuilder
    {
        return Schema::connection(self::connectionName());
    }

    /**
     * The institution whose database is bound right now.
     */
    public static function current(): ?int
    {
        return self::$current;
    }

    /**
     * Point the tenant connection at this institution's database.
     *
     * Re-binding the institution that is already bound is a no-op, so calling
     * this on every request is cheap.
     */
    public static function bind(int $institutionId): void
    {
        if (self::$current === $institutionId) {
            return;
        }

        $database = Config::get('tenancy.shared_database')
            ? Config::get('database.connections.'.Config::get('database.default').'.database')
            : self::databaseFor($institutionId);

        Config::set('database.connections.tenant.database', $database);

        // Drop the pooled PDO handle so the next query dials the new database
        // instead of reusing the previous school's open connection.
        DB::purge('tenant');

        self::$current = $institutionId;
    }

    /**
     * Unbind the current tenant. A tenant query after this fails loudly.
     */
    public static function forget(): void
    {
        Config::set('database.connections.tenant.database', null);
        DB::purge('tenant');

        self::$current = null;
    }

    /**
     * Run a callback against one institution's database, restoring whatever was
     * bound before. Use this for the System Admin's cross-school reads, which
     * have to visit each tenant database in turn.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runFor(int $institutionId, callable $callback): mixed
    {
        $previous = self::$current;

        self::bind($institutionId);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                self::forget();
            } else {
                self::bind($previous);
            }
        }
    }

    /**
     * Bind the tenant connection or fail. Guards code paths that must never run
     * unscoped, so a missing institution surfaces as an error instead of a
     * query against whatever database happened to be bound last.
     */
    public static function bindOrFail(?int $institutionId): void
    {
        if (! $institutionId) {
            throw new RuntimeException('No institution bound: refusing to run a tenant query without a school.');
        }

        self::bind($institutionId);
    }

    /**
     * Does this institution's database exist on the server yet?
     */
    public static function isProvisioned(Institution $institution): bool
    {
        if (Config::get('tenancy.shared_database')) {
            return true;
        }

        return (bool) DB::connection(Config::get('database.default'))
            ->selectOne('select 1 from pg_database where datname = ?', [self::databaseFor($institution->id)]);
    }
}
