<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who recorded each clinic visit, kept on the visit itself — as a dispense
 * keeps who handed the medicine over (`medicine_dispenses.dispensed_by_name`).
 *
 * The name is personal information, so it is encrypted like the rest of the
 * visit; the role is plain. Visits already on file are filled in from the
 * audit trail, which recorded who created each one: the entry is read, never
 * changed, and a visit the trail has no entry for stays blank rather than
 * being given a guessed name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consultations') || Schema::hasColumn('consultations', 'recorded_by_name')) {
            return;
        }

        Schema::table('consultations', function (Blueprint $table) {
            $table->text('recorded_by_name')->nullable();
            $table->string('recorded_by_role', 40)->nullable();
        });

        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')
            ->where('subject_type', 'Consultation')
            ->where('action', 'created')
            ->whereNotNull('subject_id')
            ->whereNotNull('actor_name')
            ->orderBy('id')
            ->get(['subject_id', 'actor_name', 'actor_role'])
            ->unique('subject_id')
            ->each(function ($entry): void {
                $name = trim((string) $entry->actor_name);

                if ($name === '') {
                    return;
                }

                DB::table('consultations')
                    ->where('id', $entry->subject_id)
                    ->whereNull('recorded_by_name')
                    ->update([
                        'recorded_by_name' => Crypt::encryptString($name),
                        'recorded_by_role' => $entry->actor_role,
                    ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('consultations') && Schema::hasColumn('consultations', 'recorded_by_name')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->dropColumn(['recorded_by_name', 'recorded_by_role']);
            });
        }
    }
};
