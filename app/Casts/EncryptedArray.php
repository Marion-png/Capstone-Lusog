<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * JSON array attribute encrypted at rest. Reading a plain-JSON value written
 * before encryption was introduced falls back to decoding it directly instead
 * of throwing, so legacy rows never break a page. Writes always encrypt.
 */
class EncryptedArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true);
        } catch (DecryptException) {
            $decoded = json_decode((string) $value, true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode($value));
    }
}
