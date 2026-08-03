<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A medical document belongs to the learner, not necessarily to one
     * diagnosed condition: the class adviser uploads certificates, clearances,
     * and lab results straight from the student profile. Keying the row by
     * (student_lrn, institution_id) — both plaintext lookup columns — lets that
     * list be read without walking the condition table, and makes the condition
     * link optional for documents that are not tied to a diagnosis.
     */
    public function up(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table) {
            if (! Schema::hasColumn('medical_certificates', 'student_lrn')) {
                $table->string('student_lrn', 50)->nullable()->after('id');
            }
            if (! Schema::hasColumn('medical_certificates', 'institution_id')) {
                $table->unsignedBigInteger('institution_id')->nullable()->after('student_lrn');
            }
            // Byte size of the original upload, captured before encryption —
            // the file on disk is ciphertext and is larger than what the user
            // picked, so its on-disk size must never be shown as the file size.
            if (! Schema::hasColumn('medical_certificates', 'file_size')) {
                $table->unsignedBigInteger('file_size')->nullable()->after('file_original_name');
            }
        });

        Schema::table('medical_certificates', function (Blueprint $table) {
            $table->index(['student_lrn', 'institution_id'], 'mc_student_institution_idx');
        });

        Schema::table('medical_certificates', function (Blueprint $table) {
            $table->foreignId('student_health_condition_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table) {
            $table->dropIndex('mc_student_institution_idx');
            $table->dropColumn(['student_lrn', 'institution_id', 'file_size']);
        });

        // student_health_condition_id is deliberately left nullable: rows
        // uploaded straight from the student profile have no condition, and
        // restoring the NOT NULL constraint would drop them.
    }
};
