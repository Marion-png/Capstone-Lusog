<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each school sets its own at-risk attendance threshold.
 *
 * The programme default is 80% cumulative attendance, but the SRS requires the
 * figure to be school-configurable — a feeding programme running four days a
 * week cannot be judged on the same line as one running five. NULL means "use
 * the app default" (config/feeding.php), so a school that never sets one keeps
 * moving with the default rather than being pinned to whatever it was the day
 * this column shipped.
 *
 * Plain integer, not encrypted: it is a policy setting, not personal data, and
 * the at-risk queries need to read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('institutions') && ! Schema::hasColumn('institutions', 'feeding_at_risk_threshold')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->unsignedTinyInteger('feeding_at_risk_threshold')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('institutions') && Schema::hasColumn('institutions', 'feeding_at_risk_threshold')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->dropColumn('feeding_at_risk_threshold');
            });
        }
    }
};
