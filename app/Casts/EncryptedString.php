<?php

namespace App\Casts;

use App\Support\DecryptedValues;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * String attribute encrypted at rest. Unlike Laravel's built-in `encrypted`
 * cast, reading a value written before encryption was introduced (or an
 * empty string skipped by the data migration) returns the raw value instead
 * of throwing, so legacy rows never break a page. Writes always encrypt.
 *
 * Reads go through DecryptedValues, which remembers the result for the length
 * of the request: Laravel does not cache a cast that returns a string, so the
 * same attribute was decrypted afresh on every single access.
 */
class EncryptedString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return DecryptedValues::plaintext((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }
}
