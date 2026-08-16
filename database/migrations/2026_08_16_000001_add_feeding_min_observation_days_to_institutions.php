<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each school sets its own minimum observation period.
 *
 * The at-risk threshold answers "how much attendance is enough"; this column
 * answers "how much attendance history is enough to judge on at all". A learner
 * one of four recorded sessions has covered is at 25%, but four sessions is not
 * a programme problem — it is too little evidence to classify a child on, and
 * flagging them would put them on a follow-up list on the strength of a single
 * missed morning.
 *
 * The default is 10 recorded feeding days (config/feeding.php). NULL means "use
 * the app default", so a school that never sets one keeps moving with the
 * programme rather than being pinned to whatever this was the day it shipped;
 * 0 or 1 means the threshold applies from the first confirmed session.
 *
 * Plain integer, not encrypted: it is a policy setting, not personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('institutions') && ! Schema::hasColumn('institutions', 'feeding_min_observation_days')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->unsignedTinyInteger('feeding_min_observation_days')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('institutions') && Schema::hasColumn('institutions', 'feeding_min_observation_days')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->dropColumn('feeding_min_observation_days');
            });
        }
    }
};
