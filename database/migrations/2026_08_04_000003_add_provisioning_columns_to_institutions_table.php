<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school database provisioning bookkeeping.
 *
 * Both columns exist in the deployed databases with no migration behind them.
 * Like institution_requests, nothing in app/ reads them yet; they are carried
 * so that a fresh `migrate` reproduces production exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            if (! Schema::hasColumn('institutions', 'database_name')) {
                $table->string('database_name')->nullable();
            }

            if (! Schema::hasColumn('institutions', 'provisioned_at')) {
                $table->timestamp('provisioned_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn(['database_name', 'provisioned_at']);
        });
    }
};
