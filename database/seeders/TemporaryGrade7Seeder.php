<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TEMPORARY test data — Grade 7 students "entered by the adviser" so the
 * Feeding Coordinator's SBFP forms (Masterlist / Milk Beneficiaries) can be
 * verified to auto-fill per grade level.
 *
 * Every row is tagged with an LRN starting "TMP7-" so it is easy to find and
 * remove. Re-running the seeder replaces the previous batch (idempotent).
 *
 * Remove with:  php artisan tinker --execute="App\Models\StudentHealthRecord::where('student_id','like','TMP7-%')->delete();"
 */
class TemporaryGrade7Seeder extends Seeder
{
    private const LRN_PREFIX = 'TMP7-';

    public function run(): void
    {
        if (! Schema::hasTable('student_health_records')) {
            $this->command?->warn('student_health_records table missing — nothing seeded.');

            return;
        }

        $schoolYear = StudentHealthRecord::currentSchoolYear();

        // Cover both ways the Feeding Coordinator page is opened:
        // 1) the prototype session (first active institution, by name), and
        // 2) any real account whose role is feeding_coor.
        $institutionIds = collect();

        $prototype = Institution::query()->where('status', 'active')->orderBy('name')->first();
        if ($prototype) {
            $institutionIds->push($prototype->id);
        }

        if (Schema::hasTable('accounts')) {
            $institutionIds = $institutionIds->merge(
                DB::table('accounts')->where('role', 'feeding_coor')->pluck('institution_id')
            );
        }

        $institutionIds = $institutionIds->filter()->unique()->values();

        if ($institutionIds->isEmpty()) {
            $this->command?->warn('No target institutions found — nothing seeded.');

            return;
        }

        // Wipe any prior TMP7 batch so re-runs do not duplicate.
        StudentHealthRecord::query()
            ->where('student_id', 'like', self::LRN_PREFIX.'%')
            ->delete();

        $students = $this->studentTemplates();
        $counter = 1;

        foreach ($institutionIds as $institutionId) {
            $institution = Institution::find($institutionId);
            $schoolName = $institution?->name ?? 'Demo School';

            foreach ($students as $student) {
                StudentHealthRecord::create([
                    'institution_id' => $institutionId,
                    'school_year' => $schoolYear,
                    'student_id' => self::LRN_PREFIX.str_pad((string) $counter++, 4, '0', STR_PAD_LEFT),
                    'student_name' => $student['name'],
                    'school_name' => $schoolName,
                    'section' => 'Grade 7 / '.$student['section'],
                    'student_details' => [
                        'last_name' => $student['last'],
                        'first_name' => $student['first'],
                        'middle_name' => $student['middle'],
                        'grade_level' => 'Grade 7',
                        'section' => $student['section'],
                        'gender' => $student['gender'],
                    ],
                    'weight' => $student['weight'],
                    'bmi_value' => $student['bmi'],
                    'nutritional_status' => $student['status'],
                    'baseline_age' => 12,
                    'baseline_height_cm' => $student['height'],
                    'baseline_weight_kg' => $student['weight'],
                    'baseline_bmi_value' => $student['bmi'],
                    'baseline_nutritional_status' => $student['status'],
                    'baseline_recorded_at' => now()->toDateString(),
                ]);
            }

            $this->command?->info("Seeded {$students->count()} Grade 7 students into institution {$institutionId} ({$schoolName}).");
        }
    }

    private function studentTemplates(): Collection
    {
        return collect([
            ['last' => 'Bautista',    'first' => 'Andrei',  'middle' => 'Morales',   'section' => 'Sampaguita', 'gender' => 'Male',   'status' => 'Severely Wasted', 'bmi' => 13.8, 'weight' => 30.5, 'height' => 148.0],
            ['last' => 'Cruz',        'first' => 'Bianca',  'middle' => 'Lopez',     'section' => 'Sampaguita', 'gender' => 'Female', 'status' => 'Wasted',          'bmi' => 15.1, 'weight' => 33.2, 'height' => 148.5],
            ['last' => 'Delos Reyes', 'first' => 'Carlo',   'middle' => 'Pascual',   'section' => 'Sampaguita', 'gender' => 'Male',   'status' => 'Wasted',          'bmi' => 15.4, 'weight' => 34.0, 'height' => 149.0],
            ['last' => 'Estrada',     'first' => 'Diana',   'middle' => 'Ramos',     'section' => 'Rosal',      'gender' => 'Female', 'status' => 'Underweight',     'bmi' => 16.2, 'weight' => 35.8, 'height' => 149.2],
            ['last' => 'Fernandez',   'first' => 'Elijah',  'middle' => 'Santos',    'section' => 'Rosal',      'gender' => 'Male',   'status' => 'Wasted',          'bmi' => 15.0, 'weight' => 33.0, 'height' => 148.3],
            ['last' => 'Gonzales',    'first' => 'Faith',   'middle' => 'Torres',    'section' => 'Rosal',      'gender' => 'Female', 'status' => 'Normal',          'bmi' => 19.3, 'weight' => 43.0, 'height' => 149.1],
        ])->map(function (array $student): array {
            $middleInitial = $student['middle'] !== '' ? ' '.strtoupper(substr($student['middle'], 0, 1)).'.' : '';
            $student['name'] = $student['last'].', '.$student['first'].$middleInitial;

            return $student;
        });
    }
}
