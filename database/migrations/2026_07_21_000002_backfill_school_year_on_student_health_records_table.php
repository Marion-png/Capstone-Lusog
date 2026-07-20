<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfills school_year for rows that predate the column. Derived from
     * baseline_recorded_at (falling back to created_at) run through the same
     * DepEd June-May formula as ParentalConsentForm::currentSchoolYear(),
     * rather than stamping every legacy row with "today" — some baseline
     * rows may already be a year old relative to when this migration runs.
     */
    public function up(): void
    {
        if (! Schema::hasTable('student_health_records') || ! Schema::hasColumn('student_health_records', 'school_year')) {
            return;
        }

        DB::table('student_health_records')
            ->select('id', 'baseline_recorded_at', 'created_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $date = $row->baseline_recorded_at ?? $row->created_at;
                    $carbon = $date ? Carbon::parse($date) : now();
                    $month = (int) $carbon->format('n');
                    $year = (int) $carbon->format('Y');
                    $schoolYear = $month >= 6 ? "{$year}-".($year + 1) : ($year - 1)."-{$year}";

                    DB::table('student_health_records')->where('id', $row->id)->update(['school_year' => $schoolYear]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('student_health_records') && Schema::hasColumn('student_health_records', 'school_year')) {
            DB::table('student_health_records')->update(['school_year' => null]);
        }
    }
};
