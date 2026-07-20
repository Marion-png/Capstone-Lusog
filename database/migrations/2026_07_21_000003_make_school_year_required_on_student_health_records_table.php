<?php

use App\Models\ParentalConsentForm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_health_records') || ! Schema::hasColumn('student_health_records', 'school_year')) {
            return;
        }

        // Safety net: any straggler with no school_year (shouldn't happen —
        // the previous migration backfills every row) falls back to the
        // current school year so the NOT NULL alter below never fails.
        DB::table('student_health_records')
            ->whereNull('school_year')
            ->update(['school_year' => ParentalConsentForm::currentSchoolYear()]);

        Schema::table('student_health_records', function (Blueprint $table) {
            $table->string('school_year')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('student_health_records') && Schema::hasColumn('student_health_records', 'school_year')) {
            Schema::table('student_health_records', function (Blueprint $table) {
                $table->string('school_year')->nullable()->change();
            });
        }
    }
};
