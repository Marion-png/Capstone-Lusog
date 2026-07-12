<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A teacher who works in multiple schools must hold a separate account per
     * school, so usernames are unique per institution instead of globally.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique('accounts_username_unique');
            $table->unique(['username', 'institution_id'], 'accounts_username_institution_unique');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique('accounts_username_institution_unique');
            $table->unique('username');
        });
    }
};
