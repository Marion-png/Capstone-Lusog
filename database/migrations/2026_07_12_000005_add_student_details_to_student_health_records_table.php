<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Full adviser-entered student profile (names, birth date, birthplace,
     * guardian, address, contact number, gender, ...) stored as one
     * encrypted JSON payload, so the roster survives session loss and
     * server restarts instead of living only in the session.
     */
    public function up(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            $table->text('student_details')->nullable()->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            $table->dropColumn('student_details');
        });
    }
};
