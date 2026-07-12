<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail of every access and action performed on
     * personal and sensitive personal information, for forensic
     * investigation of breaches or unexplained record changes.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Who
            $table->string('actor_name')->nullable();
            $table->string('actor_username')->nullable();
            $table->string('actor_role')->nullable()->index();
            $table->unsignedBigInteger('institution_id')->nullable()->index();

            // What
            $table->string('action')->index();               // e.g. viewed, created, updated, deleted, downloaded, login, login_failed, logout
            $table->string('subject_type')->nullable();      // model class or resource name
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description')->nullable();       // short human-readable, no sensitive payloads
            $table->text('details')->nullable();             // encrypted JSON: changed fields, old/new values, route params

            // Where / how
            $table->string('http_method', 10)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('route_name')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->nullable()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
