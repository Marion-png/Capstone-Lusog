<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One outbound HL7 FHIR disclosure: what was sent, where, by whom, and
     * what the receiving server answered.
     *
     * The payload is a copy of a learner's personal and health information,
     * so it, the server's reply, the error text and the sender's name are
     * encrypted at rest (App\Models\FhirTransmission's casts). Everything a
     * query filters on — the school, the record, the LRN, the status — stays
     * plain, as everywhere else in the schema. The endpoint is stored without
     * its credentials or query string.
     */
    public function up(): void
    {
        Schema::create('fhir_transmissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('institution_id')->nullable()->index();
            $table->unsignedBigInteger('student_health_record_id')->nullable()->index();
            $table->string('student_lrn')->nullable()->index();

            $table->string('endpoint_host')->nullable();
            $table->string('endpoint_url', 2048)->nullable();
            $table->string('trigger', 32)->default('manual');
            $table->boolean('deidentified')->default(true);

            $table->unsignedInteger('resource_count')->default(0);
            $table->longText('payload')->nullable();            // encrypted
            $table->char('payload_sha256', 64)->nullable();

            $table->string('status', 16)->index();              // pending | sent | failed | blocked
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('response_body')->nullable();      // encrypted
            $table->text('error_message')->nullable();          // encrypted

            $table->text('sent_by_name')->nullable();           // encrypted
            $table->string('sent_by_username')->nullable();
            $table->string('sent_by_role')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fhir_transmissions');
    }
};
