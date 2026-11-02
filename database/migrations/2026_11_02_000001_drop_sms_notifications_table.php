<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the parent SMS log.
 *
 * The Semaphore integration that wrote `sms_notifications` and the text
 * opt-in on the Sulat-Pahibalo was removed from the application, and nothing
 * reads or writes either any more. Both hold parents' numbers, so they are
 * dropped rather than left behind with nobody responsible for them. This
 * deletes the messages logged while the integration was in use.
 *
 * Rolling back restores the empty structure, not the rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sms_notifications');

        if (Schema::hasTable('health_consent_forms') && Schema::hasColumn('health_consent_forms', 'sms_opt_in')) {
            Schema::table('health_consent_forms', function (Blueprint $table) {
                $table->dropColumn(['sms_opt_in', 'sms_number', 'sms_language']);
            });
        }
    }

    public function down(): void
    {
        (require __DIR__.'/2026_11_01_000001_create_sms_notifications_table.php')->up();
    }
};
