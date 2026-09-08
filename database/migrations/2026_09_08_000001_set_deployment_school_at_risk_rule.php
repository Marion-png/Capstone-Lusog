<?php

use App\Models\Institution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The school this system is deployed for runs a week of unexcused absence, not
 * a percentage of the cycle.
 *
 * `institutions.feeding_at_risk_mode` has existed since the rule settings
 * migration, but Sta. Ana's row was left NULL and so fell through to the app
 * default — the 80% rate. That made every screen name a threshold the school
 * does not judge anybody against: a learner who attended every session for two
 * months and then vanished for a week is above 90% attended and needs following
 * up today, which is exactly the case a rate cannot see.
 *
 * Deliberately narrow:
 *
 * - It touches **one school**, the deployment's own, and never the 59 catalogue
 *   rows. Which rule a school runs is that school's policy, so the app default
 *   stays the rate for anybody else.
 * - It only fills a **NULL**. A school that has chosen a mode through the System
 *   Admin form has answered this question already, and a migration must not
 *   overrule them.
 * - The flag and removal windows are left NULL on purpose: the defaults are
 *   already one and two feeding weeks, which is the policy.
 */
return new class extends Migration
{
    private const MODE = 'unexcused_absence_days';

    public function up(): void
    {
        if (! Schema::hasTable('institutions') || ! Schema::hasColumn('institutions', 'feeding_at_risk_mode')) {
            return;
        }

        DB::table('institutions')
            ->where('name', Institution::REGISTRATION_SCHOOL)
            ->whereNull('feeding_at_risk_mode')
            ->update(['feeding_at_risk_mode' => self::MODE]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('institutions') || ! Schema::hasColumn('institutions', 'feeding_at_risk_mode')) {
            return;
        }

        DB::table('institutions')
            ->where('name', Institution::REGISTRATION_SCHOOL)
            ->where('feeding_at_risk_mode', self::MODE)
            ->update(['feeding_at_risk_mode' => null]);
    }
};
