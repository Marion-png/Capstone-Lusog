<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
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

        try {
            return Crypt::decryptString($value) === '1';
        } catch (DecryptException) {
            // Plain 0/1 written by a column default or before encryption
            // at rest was introduced.
            return (bool) $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($value ? '1' : '0');
    }
}
