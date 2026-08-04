<?php

namespace Database\Seeders;

use App\Models\Institution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Development login accounts, one per role.
 *
 * Row data never travels through git — each teammate's PostgreSQL server is
 * their own machine's. This seeder is how the whole team ends up with the
 * same set of working logins without sharing a database: commit the file,
 * everyone runs `php artisan db:seed` and gets identical accounts locally.
 *
 * Re-running is safe: accounts are matched on (username, institution_id),
 * which is the table's real unique key since usernames are unique per school.
 *
 * Not for production — the passwords here are public knowledge.
 */
class AccountSeeder extends Seeder
{
    /** Shared dev password for every seeded account. */
    private const PASSWORD = 'password123';

    /** The capstone's partner school; created if the default list lacks it. */
    private const SCHOOL = 'Sta. Ana National High School';

    public function run(): void
    {
        $institution = Institution::firstOrCreate(
            ['name' => self::SCHOOL],
            ['status' => 'active']
        );

        $hash = Hash::make(self::PASSWORD);
        $now = now();

        // system_admin is deliberately absent: that role signs in at
        // /admin-login against SYSTEM_ADMIN_USERNAME / SYSTEM_ADMIN_PASSWORD,
        // never against this table.
        $accounts = [
            ['Maria Santos', 'adviser1', 'class_adviser', 'Grade 10', 'Dalton'],
            ['Jose Reyes', 'adviser2', 'class_adviser', 'Grade 7', 'Rizal'],
            ['Nurse Cruz', 'nurse1', 'school_nurse', null, null],
            ['Clinic Staff', 'clinic1', 'clinic_staff', null, null],
            ['Principal Lim', 'head1', 'school_head', null, null],
            ['Feeding Coordinator', 'feeding1', 'feeding_coor', null, null],
            ['Nutrition Coordinator', 'nutri1', 'nutricor', null, null],
        ];

        foreach ($accounts as [$name, $username, $role, $grade, $section]) {
            DB::table('accounts')->updateOrInsert(
                ['username' => $username, 'institution_id' => $institution->id],
                [
                    'name' => $name,
                    'password_hash' => $hash,
                    'role' => $role,
                    'school_name' => $institution->name,
                    'assigned_grade_level' => $grade,
                    'assigned_section' => $section,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $this->command?->info(
            'Seeded '.count($accounts).' accounts at "'.$institution->name.'" (password: '.self::PASSWORD.')'
        );
    }
}
