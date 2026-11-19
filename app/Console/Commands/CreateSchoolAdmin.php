<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Support\AccountName;
use App\Support\AdminScope;
use App\Support\AuditTrail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates one school's School Admin account (AdminScope).
 *
 *   php artisan accounts:create-school-admin "Davao City National High School" dcnhs.admin --name="DCNHS Admin"
 *
 * The password is generated and printed once; the admin changes it in
 * Settings after the first sign-in. The account signs in at /admin-login (or
 * the school sign-in) and administers that one school only. A username that
 * already has an account at that school is refused, never overwritten.
 */
class CreateSchoolAdmin extends Command
{
    protected $signature = 'accounts:create-school-admin
        {school : The school, by institution id or exact name}
        {username : The sign-in username}
        {--name=School Admin : The name shown in the app}';

    protected $description = 'Create a School Admin account that administers one school';

    public function handle(): int
    {
        $school = (string) $this->argument('school');
        $institution = ctype_digit($school)
            ? Institution::query()->find((int) $school)
            : Institution::query()->where('name', $school)->first();

        if ($institution === null) {
            $this->error("No school '{$school}' is on file.");

            return self::FAILURE;
        }

        $username = strtolower(trim((string) $this->argument('username')));
        if ($username === '' || $username === 'prototype') {
            $this->error('Give a username.');

            return self::FAILURE;
        }

        $taken = DB::table('accounts')
            ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
            ->where('institution_id', $institution->id)
            ->exists();

        if ($taken) {
            $this->error("'{$username}' already has an account at {$institution->name}. Nothing was changed.");

            return self::FAILURE;
        }

        // Letters and digits, so it always passes AccountPassword's rule.
        $password = Str::password(12, symbols: false).random_int(0, 9).Str::random(1);

        DB::table('accounts')->insert([
            'name' => AccountName::seal(trim((string) $this->option('name')) ?: 'School Admin'),
            'username' => $username,
            'password_hash' => Hash::make($password),
            'role' => AdminScope::SCHOOL_ADMIN,
            'institution_id' => $institution->id,
            'school_name' => $institution->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AuditTrail::record('created', 'Account', null, "Console created account '{$username}' (school_admin) for {$institution->name}");

        $this->info("School Admin created for {$institution->name} (institution #{$institution->id}).");
        $this->line("  Username: {$username}");
        $this->line("  Password: {$password}");
        $this->line('Sign in at /admin-login, then change the password in Settings.');

        return self::SUCCESS;
    }
}
