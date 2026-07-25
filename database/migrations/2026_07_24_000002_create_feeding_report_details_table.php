<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feeding_report_details')) {
            Schema::create('feeding_report_details', function (Blueprint $table) {
                $table->id();
                // Lookup keys stay plain (scoping / period).
                $table->unsignedBigInteger('institution_id')->nullable();
                $table->string('school_year');
                // Manual admin fields (report metadata) — encrypted at rest via
                // casts because they carry names / contact details.
                $table->text('narrative')->nullable();
                $table->text('consignees')->nullable();
                $table->text('signatories')->nullable();
                $table->timestamps();

                $table->unique(['institution_id', 'school_year'], 'feeding_report_details_scope_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feeding_report_details');
    }
};
