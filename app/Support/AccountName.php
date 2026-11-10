<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * A staff member's full name on `accounts` / `account_requests`.
 *
 * A name is personal information, so it is encrypted at rest like every other
 * staff name in the app (`recorded_by_name`, `uploaded_by_name`, …). Both
 * tables are read and written through `DB::table`, which has no casts, so
 * every write seals the name here and every read opens it here.
 *
 * Only the name. `username` and `password_hash` stay as they are: sign-in
 * matches on them in SQL, and a collaborator must be able to sign in whichever
 * APP_KEY wrote the student data (see scripts/check-railway-db.php). A name
 * this key cannot open reads as empty, and callers fall back to the username.
 */
final class AccountName
{
    /** The value to store for a typed name. */
    public static function seal(?string $name): string
    {
        return Crypt::encryptString(trim((string) $name));
    }

    /** The readable name for a stored value — legacy plaintext included. */
    public static function read(mixed $stored): string
    {
        $stored = (string) ($stored ?? '');

        return $stored === '' ? '' : trim(DecryptedValues::plaintext($stored));
    }

    /**
     * A row read with `(array)` or as an object, with its name opened.
     *
     * @template T of array|object
     *
     * @param  T  $row
     * @return T
     */
    public static function open(array|object $row): array|object
    {
        if (is_array($row)) {
            if (array_key_exists('name', $row)) {
                $row['name'] = self::read($row['name']);
            }

            return $row;
        }

        if (property_exists($row, 'name')) {
            $row->name = self::read($row->name);
        }

        return $row;
    }
}
