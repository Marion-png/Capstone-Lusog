<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The days a school does not hold class, beyond Saturdays and Sundays.
 *
 * Two kinds of row share the table:
 *
 *   - Public holidays read from the Calendarific API (`source` = calendarific)
 *     carry no institution: a national holiday closes every school. A sync
 *     replaces a year's rows wholesale, because a proclamation can move or
 *     cancel a holiday and a merge would keep the date it replaced.
 *   - Class suspensions and school breaks entered by the System Admin
 *     (`source` = manual) belong to one school, since a typhoon suspension in
 *     Davao closes no school in Manila.
 *
 * A row covers `starts_on` to `ends_on` inclusive, so a two-week Christmas
 * break is one row rather than ten. Nothing here is a fact about a person, so
 * every column is plain and may be used in SQL, except the name of whoever
 * entered a row, which is a staff name and is encrypted by the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_calendar_days')) {
            return;
        }

        Schema::create('school_calendar_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('name', 160);
            $table->string('kind', 20);
            $table->string('source', 20);
            // The provider's own id for the holiday (Calendarific's `urlid`),
            // kept so a synced row can be traced back to what the API said.
            $table->string('external_id', 160)->nullable();
            $table->text('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
            $table->index(['source', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_calendar_days');
    }
};
