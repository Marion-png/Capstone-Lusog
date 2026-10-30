<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->longText('scan_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->dropColumn('scan_result');
        });
    }
};
