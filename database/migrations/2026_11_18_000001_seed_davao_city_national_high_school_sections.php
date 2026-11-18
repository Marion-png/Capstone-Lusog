<?php

use App\Models\Institution;
use App\Models\InstitutionSection;
use Database\Seeders\InstitutionSectionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Publish Davao City National High School's section catalogue, so its class
 * advisers pick a Grade 7–10 section at registration instead of typing one.
 *
 * The names are placeholders (InstitutionSectionSeeder::DAVAO_CITY_SECTIONS)
 * until the school sends its own section report. Section names are school
 * structure, not personal data, so nothing here is encrypted. Safe to re-run:
 * a row already present is left alone.
 *
 * The school must already be in `institutions` (Institution::seedDefaults puts
 * it there). A database without it, such as a fresh test database, is left
 * untouched rather than given a school nobody seeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        $institutionId = $this->davaoCityId();

        if ($institutionId === null) {
            return;
        }

        InstitutionSectionSeeder::seedSections($institutionId, InstitutionSectionSeeder::DAVAO_CITY_SECTIONS);
    }

    public function down(): void
    {
        $institutionId = $this->davaoCityId();

        if ($institutionId === null) {
            return;
        }

        foreach (InstitutionSectionSeeder::DAVAO_CITY_SECTIONS as $gradeLevel => $names) {
            InstitutionSection::query()
                ->where('institution_id', $institutionId)
                ->where('grade_level', $gradeLevel)
                ->whereIn('name', $names)
                ->delete();
        }
    }

    private function davaoCityId(): ?int
    {
        if (! Schema::hasTable('institutions') || ! Schema::hasTable('institution_sections')) {
            return null;
        }

        $id = Institution::query()
            ->where('name', InstitutionSectionSeeder::DAVAO_CITY)
            ->value('id');

        return $id ? (int) $id : null;
    }
};
