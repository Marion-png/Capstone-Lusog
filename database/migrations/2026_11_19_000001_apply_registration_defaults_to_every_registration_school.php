<?php

use App\Models\Institution;
use App\Models\ParentalConsentForm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every registration school runs the programme the way Sta. Ana does.
 *
 * Two of Sta. Ana's defaults were written for it alone
 * (2026_09_08_000001 and 2026_11_09_000001), so Davao City National High
 * School fell through to the app's attendance-rate rule and offered three
 * services that are not given in Davao City. This gives every registration
 * school the same starting point (Institution::applyRegistrationDefaults()):
 *
 *  - For Follow-up rule: a run of unexcused absences, where none was chosen;
 *  - Health Services Offered: Schistosomiasis, Filariasis and TAS off, for a
 *    school with no row of its own.
 *
 * It never overrules a choice somebody made, and it touches no record: each
 * school's learners, accounts and forms stay its own.
 */
return new class extends Migration
{
    private const MODE = 'unexcused_absence_days';

    public function up(): void
    {
        if (! Schema::hasTable('institutions')) {
            return;
        }

        $schools = DB::table('institutions')->whereIn('name', Institution::REGISTRATION_SCHOOLS)->pluck('id');

        if (Schema::hasColumn('institutions', 'feeding_at_risk_mode')) {
            DB::table('institutions')
                ->whereIn('id', $schools)
                ->whereNull('feeding_at_risk_mode')
                ->update(['feeding_at_risk_mode' => self::MODE, 'updated_at' => now()]);
        }

        if (! Schema::hasTable('health_services_offered')) {
            return;
        }

        foreach ($schools as $school) {
            if (DB::table('health_services_offered')->where('institution_id', $school)->exists()) {
                continue;
            }

            DB::table('health_services_offered')->insert([
                'institution_id' => $school,
                'school_year' => ParentalConsentForm::calendarSchoolYear(),
                'not_offered' => json_encode(Institution::DAVAO_CITY_NOT_OFFERED),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Defaults only: a school may have kept them, so nothing is undone.
    }
};
