<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The adviser's upload form takes a medical certificate alongside the signed
 * Sulat-Pahibalo, plus free-text remarks. Only the boolean
 * `medical_cert_attached` flag existed before, with nowhere to keep the file
 * itself.
 *
 * Paths stay plain (they are opaque storage keys); the original file name and
 * the notes are personal information and are encrypted by the model casts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->string('med_cert_path')->nullable()->after('medical_cert_attached');
            $table->string('med_cert_original_name')->nullable()->after('med_cert_path');
            $table->text('notes')->nullable()->after('med_cert_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->dropColumn(['med_cert_path', 'med_cert_original_name', 'notes']);
        });
    }
};
