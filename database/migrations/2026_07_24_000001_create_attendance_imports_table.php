<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_imports')) {
            Schema::create('attendance_imports', function (Blueprint $table) {
                $table->id();
                // Lookup keys stay plain (scoping / period gate).
                $table->unsignedBigInteger('institution_id')->nullable()->index();
                $table->string('school_year')->index();
                // Personal / sensitive columns are encrypted at rest via casts.
                $table->text('uploaded_by_name')->nullable();
                $table->string('original_filename')->nullable();
                $table->string('stored_path')->nullable();
                $table->unsignedInteger('sessions_count')->default(0);
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('unmatched_count')->default(0);
                $table->text('row_errors')->nullable();
                $table->timestamps();

                $table->index(['institution_id', 'school_year'], 'attendance_imports_scope_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_imports');
    }
};
