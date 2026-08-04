<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending school sign-up requests, awaiting System Admin review.
 *
 * The table exists in the deployed databases but had no migration behind it,
 * so a fresh `migrate` produced a schema that did not match production. This
 * backfills the definition. Nothing in app/ reads the table yet — it is
 * carried purely to keep fresh installs and production identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('institution_requests')) {
            return;
        }

        Schema::create('institution_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('division')->nullable();
            $table->string('contact_person');
            $table->string('contact_email');
            $table->string('contact_number')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('decline_reason')->nullable();
            $table->unsignedBigInteger('institution_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_requests');
    }
};
