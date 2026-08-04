<?php

namespace App\Support;

use App\Models\Institution;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates and migrates the private database that backs one institution.
 *
 * Provisioning runs when a System Admin approves an institution's registration,
 * and can be replayed for schools that predate this (see the
 * `institutions:provision` command). It is idempotent: a school whose database
 * already exists is brought up to date rather than rebuilt, so re-running it
 * never destroys data.
 */
class InstitutionProvisioner
{
    /**
     * Provision an institution's database and record that it happened.
     *
     * @return bool True if the database was newly created, false if it already existed.
     */
    public static function provision(Institution $institution): bool
    {
        if (Config::get('tenancy.shared_database')) {
            $institution->forceFill([
                'database_name' => Tenancy::databaseFor($institution->id),
                'provisioned_at' => now(),
            ])->save();

            return false;
        }

        $created = self::createDatabase($institution);

        self::migrate($institution);

        $institution->forceFill([
            'database_name' => Tenancy::databaseFor($institution->id),
            'provisioned_at' => now(),
        ])->save();

        return $created;
    }

    /**
     * A throwaway connection used only for CREATE/DROP DATABASE.
     *
     * Postgres refuses those statements inside a transaction block, and the
     * default connection may well be in one — RefreshDatabase wraps every test
     * in a transaction, and an approval flow could be wrapped by a caller. This
     * clones the default connection under its own name so it gets its own PDO
     * handle, outside whatever transaction is open elsewhere.
     */
    private static function maintenance()
    {
        $default = Config::get('database.default');

        Config::set('database.connections.tenancy_maintenance', Config::get("database.connections.{$default}"));

        DB::purge('tenancy_maintenance');

        return DB::connection('tenancy_maintenance');
    }

    /**
     * CREATE DATABASE for this institution if it is not already there.
     */
    public static function createDatabase(Institution $institution): bool
    {
        $database = Tenancy::databaseFor($institution->id);

        $exists = self::maintenance()
            ->selectOne('select 1 from pg_database where datname = ?', [$database]);

        if ($exists) {
            DB::purge('tenancy_maintenance');

            return false;
        }

        // The name is built from the institution's integer id and a config
        // prefix, never from user input, so it cannot carry an injection.
        self::maintenance()->statement('create database "'.$database.'"');

        DB::purge('tenancy_maintenance');

        return true;
    }

    /**
     * Run every migration against this institution's database, then seed the
     * reference data a school needs to function.
     */
    public static function migrate(Institution $institution): void
    {
        Tenancy::runFor($institution->id, function () use ($institution): void {
            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--force' => true,
            ]);

            self::seedOwnInstitutionRow($institution);

            Artisan::call('db:seed', [
                '--class' => 'Database\Seeders\ConditionSeeder',
                '--database' => 'tenant',
                '--force' => true,
            ]);
        });
    }

    /**
     * Copy the institution's own row into its database.
     *
     * Tables like student_health_records carry an institution_id foreign key to
     * `institutions`, and Postgres cannot point a foreign key at another
     * database. Each tenant therefore holds exactly one institution row —
     * itself — which satisfies those keys without any other school's data being
     * present. The central `institutions` table stays the authoritative list.
     */
    private static function seedOwnInstitutionRow(Institution $institution): void
    {
        DB::connection('tenant')->table('institutions')->updateOrInsert(
            ['id' => $institution->id],
            [
                'name' => $institution->name,
                'address' => $institution->address,
                'status' => $institution->status,
                'created_at' => $institution->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Drop an institution's database. Only used to clean up a failed
     * provisioning run and by tests — never wire this to a user-facing action
     * without an explicit confirmation step, as it destroys a school's records.
     */
    public static function drop(Institution $institution): void
    {
        if (Config::get('tenancy.shared_database')) {
            return;
        }

        $database = Tenancy::databaseFor($institution->id);

        if (Tenancy::current() === $institution->id) {
            Tenancy::forget();
        }

        // Release our own pooled handle on the tenant database first, or the
        // drop blocks on a connection we are holding open ourselves.
        DB::purge('tenant');

        try {
            self::maintenance()->statement('drop database if exists "'.$database.'" with (force)');
        } catch (Throwable) {
            // A database still holding open connections cannot be dropped;
            // leave it in place rather than half-tearing it down.
        } finally {
            DB::purge('tenancy_maintenance');
        }

        $institution->forceFill(['provisioned_at' => null])->save();
    }
}
