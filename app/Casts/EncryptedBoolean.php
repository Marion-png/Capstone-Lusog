<?php

namespace App\Casts;

use App\Support\DecryptedValues;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Boolean flag stored encrypted at rest. Health flags (e.g. "has asthma")
 * are sensitive personal information even though they are only true/false,
 * so they must not be readable from a stolen database file.
 */
class EncryptedBoolean implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?bool
    {
        if ($value === null) {
            return null;
        }

        $stored = (string) $value;

        // Plain 0/1 written by a column default or before encryption at rest
        // was introduced reads as itself; anything decryptable reads as '1'.
        return DecryptedValues::isEncrypted($stored)
            ? DecryptedValues::plaintext($stored) === '1'
            : (bool) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($value ? '1' : '0');
    }
}
