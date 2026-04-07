<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            if (!Schema::hasColumn('student_health_records', 'school_name')) {
                $table->string('school_name')->nullable()->after('student_id');
                $table->index('school_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            if (Schema::hasColumn('student_health_records', 'school_name')) {
                $table->dropIndex(['school_name']);
                $table->dropColumn('school_name');
            }
        });
    }
};
