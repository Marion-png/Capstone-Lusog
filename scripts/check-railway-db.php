<?php
/**
 * Check that this machine is wired to the deployed Railway PostgreSQL and that
 * the accounts on it can actually be signed in with.
 *
 *   php scripts/check-railway-db.php
 *
 * Read-only: it opens a connection, counts rows and tries one decryption. It
 * writes nothing, so it is safe to run against the shared database.
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$fail = 0;
$ok = static function (string $m) { echo "  OK    $m\n"; };
$bad = static function (string $m) use (&$fail) { $fail++; echo "  FAIL  $m\n"; };
$warn = static function (string $m) { echo "  WARN  $m\n"; };

echo "\n=== 1. Where is this app pointed? ===\n";

$conn = config('database.default');
$cfg = app('db')->connection()->getConfig();
$host = (string) ($cfg['host'] ?? '');
$port = (string) ($cfg['port'] ?? '');
$db = (string) ($cfg['database'] ?? '');

echo "  connection: $conn\n  host:       $host\n  port:       $port\n  database:   $db\n";

if ($conn !== 'pgsql') {
    $bad("DB_CONNECTION is '$conn', expected 'pgsql'.");
} elseif (str_contains($host, 'PASTE-RAILWAY-HOST-HERE')) {
    $bad('.env still holds the placeholder host - paste DATABASE_PUBLIC_URL into DB_URL.');
} elseif (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    $bad("Pointed at a LOCAL database ($host) - this project must use the deployed Railway one.");
} elseif (str_contains($host, 'railway.internal')) {
    $bad('That is the private DATABASE_URL; it only resolves inside Railway. Use DATABASE_PUBLIC_URL.');
} else {
    $ok('Pointed at a remote host, not a local database.');
}

echo "\n=== 2. Can it connect? ===\n";

try {
    $version = DB::selectOne('select version() as v')->v;
    $ok('Connected. '.substr($version, 0, 40).'...');
} catch (Throwable $e) {
    $bad('Cannot connect: '.$e->getMessage());
    echo "\nStopping here - nothing below can be checked without a connection.\n";
    exit(1);
}

echo "\n=== 3. Is the schema there and fully migrated? ===\n";

$tables = DB::table('information_schema.tables')
    ->where('table_schema', 'public')
    ->where('table_type', 'BASE TABLE')
    ->count();
echo "  tables in public schema: $tables\n";

if (! Schema::hasTable('migrations')) {
    $bad('No migrations table - this database has never been migrated.');
} else {
    $ran = DB::table('migrations')->count();
    $onDisk = count(glob(__DIR__.'/../database/migrations/*.php'));
    echo "  migrations ran: $ran   files on disk: $onDisk\n";

    if ($ran >= $onDisk) {
        $ok('Every migration on disk has run.');
    } else {
        $warn(($onDisk - $ran).' migration(s) not yet run - run `php artisan migrate` once the team agrees.');
    }
}

echo "\n=== 4. The collaborator's accounts ===\n";

if (! Schema::hasTable('accounts')) {
    $bad('No accounts table - you cannot sign in against this database.');
} else {
    $accounts = DB::table('accounts')->get();
    echo '  accounts on this database: '.$accounts->count()."\n";

    if ($accounts->isEmpty()) {
        $bad('The accounts table is empty - there is nothing to sign in as.');
    } else {
        $ok($accounts->count().' account(s) present.');

        $withHash = $accounts->filter(function ($a) { return filled($a->password_hash ?? null); });
        $bcrypt = $withHash->filter(function ($a) { return str_starts_with((string) $a->password_hash, '$2y$'); });

        if ($withHash->isNotEmpty() && $bcrypt->count() === $withHash->count()) {
            $ok('Every password is a bcrypt hash Hash::check() can verify.');
        } else {
            $warn(($withHash->count() - $bcrypt->count()).' account(s) have a password that is not a bcrypt hash.');
        }

        // accounts is a plaintext table by design, so a collaborator's account
        // works regardless of which APP_KEY wrote the encrypted student data.
        $garbled = $accounts->filter(function ($a) { return str_starts_with((string) $a->username, 'eyJpdiI6'); });

        if ($garbled->isEmpty()) {
            $ok('Usernames are readable (the accounts table is plaintext by design).');
        } else {
            $bad('Usernames look encrypted - unexpected; login matches on a plain username.');
        }

        echo "\n  role                 school                                   username\n";
        echo '  '.str_repeat('-', 74)."\n";
        foreach ($accounts->sortBy('role') as $a) {
            printf(
                "  %-20s %-40s %s\n",
                substr((string) $a->role, 0, 20),
                substr((string) ($a->school_name ?? '-'), 0, 40),
                (string) $a->username
            );
        }
        echo "\n";

        $known = ['school_nurse', 'clinic_staff', 'class_adviser', 'school_head', 'feeding_coor', 'nutricor', 'system_admin'];
        $unknown = $accounts->pluck('role')->unique()->diff($known);

        if ($unknown->isEmpty()) {
            $ok('Every role maps to a dashboard the login route can redirect to.');
        } else {
            $warn('Unrecognised role(s): '.$unknown->implode(', '));
        }

        $noInstitution = $accounts->filter(function ($a) {
            return blank($a->institution_id ?? null) && $a->role !== 'system_admin';
        });

        if ($noInstitution->isEmpty()) {
            $ok('Every non-admin account carries an institution_id (InstitutionScope needs it).');
        } else {
            $warn($noInstitution->count().' non-admin account(s) have no institution_id - InstitutionScope will eject them.');
        }
    }
}

echo "\n=== 5. Does this machine's APP_KEY read the student data? ===\n";

if (! Schema::hasTable('student_health_records')) {
    $warn('No student_health_records table to test against.');
} else {
    $row = DB::table('student_health_records')->whereNotNull('student_name')->first();

    if (! $row) {
        $warn('No student records on this database yet - nothing to decrypt.');
    } elseif (! str_starts_with((string) $row->student_name, 'eyJpdiI6')) {
        $warn('student_name is stored as plaintext (a pre-encryption row); nothing to verify.');
    } else {
        try {
            $name = Crypt::decryptString($row->student_name);
            $ok('APP_KEY decrypts the student data. Sample: '.mb_substr($name, 0, 2).'***');
        } catch (Throwable $e) {
            $bad('APP_KEY does NOT match the key this data was encrypted with. Get the right APP_KEY from whoever seeded this database - never run key:generate against it.');
        }
    }
}

echo "\n=== 6. Reference data the dashboards need ===\n";

$reference = [
    'institutions' => 'schools',
    'conditions' => 'health conditions',
    'institution_sections' => 'section catalogue',
];

foreach ($reference as $table => $label) {
    if (! Schema::hasTable($table)) {
        $bad("Missing table: $table");
        continue;
    }

    $n = DB::table($table)->count();
    $n > 0 ? $ok("$label: $n row(s)") : $warn("$label ($table) is empty - seed it if a screen needs it.");
}

echo "\n".str_repeat('=', 62)."\n";
echo $fail === 0
    ? "ALL CHECKS PASSED - sign in at /login with a collaborator's account.\n"
    : "$fail CHECK(S) FAILED - see the FAIL lines above.\n";
echo str_repeat('=', 62)."\n\n";

exit($fail === 0 ? 0 : 1);
