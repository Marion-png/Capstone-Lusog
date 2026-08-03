<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Class advisers, school nurses, and clinic staff all file documents
     * against the same learner and all see each other's uploads, so a row has
     * to say which desk it came from — the uploader's name alone does not.
     * The role is a plain enum-ish string, never a filter on personal data.
     */
    public function up(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table) {
            if (! Schema::hasColumn('medical_certificates', 'uploaded_by_role')) {
                $table->string('uploaded_by_role', 40)->nullable();
            }
        });

        // Everything on file before this column existed was uploaded through
        // the adviser's certificate form — the only writer that existed.
        DB::table('medical_certificates')
            ->whereNull('uploaded_by_role')
            ->update(['uploaded_by_role' => 'class_adviser']);
    }

    public function down(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table) {
            $table->dropColumn('uploaded_by_role');
        });
    }
};
