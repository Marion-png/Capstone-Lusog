<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Text messages to parents (App\Support\ParentNotifier, sent through the
 * Semaphore gateway).
 *
 * `sms_notifications` is the log of every message the school tried to send:
 * one row per attempt to reach one parent about one event, carrying what the
 * gateway answered. The number, the message and any gateway error are
 * personal information and encrypted through the model's casts; the columns
 * a query filters on — school, LRN, event, status, the gateway's message id
 * and the de-duplication key — stay plain. `recipient_last4` is what the
 * screens print, so the full number never has to be decrypted to show a row.
 *
 * The parent's opt-in lives on their Sulat-Pahibalo (`health_consent_forms`):
 * the yes/no and the language are flags, plain; the number they gave is
 * encrypted like every other answer on that form.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sms_notifications')) {
            Schema::create('sms_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('institution_id')->nullable()->index();
                $table->string('student_lrn', 20)->nullable()->index();
                $table->string('event', 40)->index();
                // Unique: the same notice for the same run of absences is one
                // message, however many times the flags are recomputed.
                $table->string('dedupe_key', 120)->nullable()->unique();
                $table->text('recipient');
                $table->string('recipient_last4', 4)->nullable();
                $table->text('message');
                $table->string('language', 5)->default('en');
                $table->string('status', 20)->index();
                $table->string('provider_message_id', 40)->nullable()->index();
                $table->string('provider_status', 30)->nullable();
                $table->string('network', 30)->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->text('error')->nullable();
                $table->text('requested_by_name')->nullable();
                $table->string('requested_by_role', 40)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('status_checked_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('health_consent_forms') && ! Schema::hasColumn('health_consent_forms', 'sms_opt_in')) {
            Schema::table('health_consent_forms', function (Blueprint $table) {
                $table->boolean('sms_opt_in')->default(false);
                $table->text('sms_number')->nullable();
                $table->string('sms_language', 5)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_notifications');

        if (Schema::hasTable('health_consent_forms') && Schema::hasColumn('health_consent_forms', 'sms_opt_in')) {
            Schema::table('health_consent_forms', function (Blueprint $table) {
                $table->dropColumn(['sms_opt_in', 'sms_number', 'sms_language']);
            });
        }
    }
};
