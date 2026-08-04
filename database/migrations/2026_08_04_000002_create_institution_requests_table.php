<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school's own registration, distinct from a staff member's account request.
 *
 * Approving one of these provisions the institution's private database, so the
 * school exists as an isolated tenant before any of its staff can be approved
 * into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            // Recorded rather than derived so an operator can see, from the
            // central database alone, which schools have a database and when
            // it was built.
            $table->string('database_name')->nullable()->after('status');
            $table->timestamp('provisioned_at')->nullable()->after('database_name');
        });

        Schema::create('institution_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('division')->nullable();
            $table->string('contact_person');
            $table->string('contact_email');
            $table->string('contact_number')->nullable();
            $table->string('status')->default('pending');
            $table->text('decline_reason')->nullable();
            // Set once approved, linking the request to the school it created.
            $table->unsignedBigInteger('institution_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_requests');

        Schema::table('institutions', function (Blueprint $table): void {
            $table->dropColumn(['database_name', 'provisioned_at']);
        });
    }
};
