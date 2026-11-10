<?php

use App\Support\AccountName;
use App\Support\AuditTrail;
use App\Support\DecryptedValues;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt staff full names on `accounts` and `account_requests` at rest.
 *
 * Every other staff name in the app was already encrypted; these two tables
 * were not. The column is widened to `text` first (ciphertext is ~200+
 * characters and outgrows a varchar(255) for a long name), then each
 * plaintext name is sealed in place. A value that already decrypts is left
 * alone, so the migration is safe to re-run. `username` stays plaintext:
 * sign-in matches on it in SQL.
 */
return new class extends Migration
{
    private const TABLES = ['accounts', 'account_requests'];

    public function up(): void
    {
        $sealed = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'name')) {
                continue;
            }

            if (Schema::getColumnType($table, 'name') !== 'text') {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->text('name')->change();
                });
            }

            $sealed[$table] = 0;

            DB::table($table)->select(['id', 'name'])->orderBy('id')->chunk(200, function ($rows) use ($table, &$sealed) {
                foreach ($rows as $row) {
                    $stored = (string) ($row->name ?? '');

                    // Already ciphertext — under this key or another one.
                    if ($stored === '' || DecryptedValues::isEncrypted($stored) || DecryptedValues::looksEncrypted($stored)) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update(['name' => AccountName::seal($stored)]);
                    $sealed[$table]++;
                }
            });
        }

        if (array_sum($sealed) > 0) {
            AuditTrail::record(
                'updated',
                'Account',
                null,
                'Staff account names encrypted at rest',
                $sealed
            );
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: writing the names back as plaintext would
        // undo the protection this migration exists for, and narrowing the
        // column would truncate the ciphertext.
    }
};
