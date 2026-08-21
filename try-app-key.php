<?php
/**
 * Test whether an APP_KEY can decrypt the Railway data — without touching .env.
 *
 *   php try-app-key.php "base64:XXXXXXXX..."
 *
 * Prints MATCH or NO MATCH. Reads one encrypted column and tries to decrypt it
 * with the key you pass, so a candidate key can be checked safely before it is
 * put anywhere. Delete this file when you are done with it.
 */
require __DIR__.'/vendor/autoload.php';

$key = $argv[1] ?? '';
if ($key === '') {
    fwrite(STDERR, "Usage: php try-app-key.php \"base64:...\"\n");
    exit(1);
}

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$row = Illuminate\Support\Facades\DB::table('student_health_records')
    ->whereNotNull('student_name')
    ->first();

if (! $row) {
    echo "No student records to test against.\n";
    exit(1);
}

$raw = base64_decode(substr($key, 7));
$encrypter = new Illuminate\Encryption\Encrypter($raw, config('app.cipher'));

try {
    $name = $encrypter->decryptString($row->student_name);
    echo "MATCH — this key decrypts the data. Sample: ".substr($name, 0, 3)."***\n";
} catch (Throwable $e) {
    echo "NO MATCH — this key cannot decrypt the data.\n";
}
