<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Remembers what a given ciphertext decrypts to, for the length of one request.
 *
 * Laravel only caches the result of a custom cast when that cast returns an
 * *object* (see HasAttributes::getClassCastableAttributeValue). The casts in
 * App\Casts return strings, arrays and booleans, so nothing is cached and every
 * single read of an encrypted attribute runs a fresh AES decrypt plus an HMAC
 * check — around 26 microseconds each. The screens in this app read the same
 * attribute over and over: one page loads a roster, then hands it to a summary
 * class, an at-risk rule, a filter and a Blade template, each of which reads
 * `student_name` and `nutritional_status` again. Tens of thousands of decrypts
 * per page turned into whole seconds of CPU before a byte was sent.
 *
 * A ciphertext always decrypts to the same plaintext, so the mapping is safe to
 * reuse. Keying on the stored value rather than on the model and column means a
 * write invalidates itself: the new ciphertext is a different key, and two
 * models holding the same value share one entry.
 *
 * The legacy-plaintext path is cached too, and that matters more than it looks:
 * a value written before encryption (or an empty-string default) makes
 * `decryptString` *throw*, and a thrown exception is far more expensive than
 * the decrypt it replaces.
 *
 * Scope and lifetime: plaintext personal data lives here only as long as the
 * request that already had it in memory in model attributes, and
 * FreshRequestState empties it at the start of every request. The entry count
 * is capped so a job walking a large table cannot grow it without bound.
 */
final class DecryptedValues
{
    /**
     * Enough for a large roster read many times over, small enough that the
     * plaintext held is bounded. On overflow the cache is emptied rather than
     * evicted one by one — the next reads simply repopulate it.
     */
    private const MAX_ENTRIES = 20000;

    /** @var array<string, string|false> ciphertext => plaintext, or false when it is not decryptable */
    private static array $values = [];

    /**
     * The plaintext for a stored value, or the value itself when it was written
     * before encryption at rest and cannot be decrypted.
     */
    public static function plaintext(string $stored): string
    {
        $hit = self::$values[$stored] ?? null;

        if ($hit !== null) {
            return $hit === false ? $stored : $hit;
        }

        if (count(self::$values) >= self::MAX_ENTRIES) {
            self::$values = [];
        }

        try {
            $plain = Crypt::decryptString($stored);
        } catch (DecryptException) {
            self::$values[$stored] = false;

            return $stored;
        }

        self::$values[$stored] = $plain;

        return $plain;
    }

    /** Whether the stored value could be decrypted at all. */
    public static function isEncrypted(string $stored): bool
    {
        self::plaintext($stored);

        return self::$values[$stored] !== false;
    }

    public static function flush(): void
    {
        self::$values = [];
    }
}
