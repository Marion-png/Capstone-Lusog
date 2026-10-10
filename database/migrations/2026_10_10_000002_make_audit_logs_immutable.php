<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit trail was append-only by convention: nothing in the
     * application updated or deleted a row, but nothing stopped a query from
     * doing it. This makes the database refuse it.
     *
     *  - Triggers reject UPDATE and DELETE on every row, and TRUNCATE on the
     *    table (Postgres), whoever issues them — a mass query that bypasses
     *    the model's events is refused all the same.
     *  - `row_hash` holds each entry's HMAC seal (App\Support\AuditSeal),
     *    written by the model as the row is created, so a row altered by
     *    someone who first dropped the triggers is still detectable.
     *
     * Rows already in the table are left unsealed rather than sealed now: a
     * seal vouches for the moment it was taken, and sealing old rows today
     * would vouch for whatever they happen to say today.
     *
     * The column is added before the triggers exist, since adding it is
     * itself an ALTER the triggers do not cover but a backfill would be an
     * UPDATE they would refuse.
     */
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        if (! Schema::hasColumn('audit_logs', 'row_hash')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->char('row_hash', 64)->nullable();
            });
        }

        match (DB::getDriverName()) {
            'pgsql' => $this->postgres(),
            'sqlite' => $this->sqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        match (DB::getDriverName()) {
            'pgsql' => DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS audit_logs_append_only_rows ON audit_logs;
                DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs;
                DROP FUNCTION IF EXISTS audit_logs_reject_change();
                SQL),
            'sqlite' => DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS audit_logs_no_update;
                DROP TRIGGER IF EXISTS audit_logs_no_delete;
                SQL),
            default => null,
        };

        if (Schema::hasColumn('audit_logs', 'row_hash')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropColumn('row_hash');
            });
        }
    }

    private function postgres(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_reject_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            DROP TRIGGER IF EXISTS audit_logs_append_only_rows ON audit_logs;
            CREATE TRIGGER audit_logs_append_only_rows
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_change();

            DROP TRIGGER IF EXISTS audit_logs_append_only_truncate ON audit_logs;
            CREATE TRIGGER audit_logs_append_only_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_reject_change();
            SQL);
    }

    private function sqlite(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS audit_logs_no_update
            BEFORE UPDATE ON audit_logs
            BEGIN
                SELECT RAISE(ABORT, 'audit_logs is append-only: UPDATE is not permitted');
            END;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS audit_logs_no_delete
            BEFORE DELETE ON audit_logs
            BEGIN
                SELECT RAISE(ABORT, 'audit_logs is append-only: DELETE is not permitted');
            END;
            SQL);
    }
};
