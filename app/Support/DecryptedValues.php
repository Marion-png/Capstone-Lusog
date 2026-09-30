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
     * The plaintext for a stored value.
     *
     * Two kinds of value cannot be decrypted, and they are not the same thing:
     *
     *  - **Legacy plaintext** — written before encryption at rest, or an
     *    empty-string default the data migration skipped. It is returned as it
     *    stands, because it is already readable and a page must not break over
     *    a row that predates the feature.
     *  - **Ciphertext this key cannot open** — written under a different
     *    APP_KEY. Returning it printed a wall of base64 into a learner's name
     *    column. That is not a value: nobody can read it, it is not what the
     *    parent or the adviser typed, and rendering it makes a screen unusable
     *    while telling the reader nothing. So it comes back **empty**, and the
     *    row shows a blank where a name should be — which is the truth, and
     *    which the plain columns beside it (the LRN) still identify.
     *
     * Losing APP_KEY loses the data; this only stops the loss being rendered
     * as gibberish. Nothing here invents a value to put in its place.
     */
    public static function plaintext(string $stored): string
    {
        $hit = self::$values[$stored] ?? null;

        if ($hit !== null) {
            // Same rule on the cached path as on the fresh one: a remembered
            // failure must not hand back base64 just because it was asked twice.
            return $hit === false
                ? (self::looksEncrypted($stored) ? '' : $stored)
                : $hit;
        }

        if (count(self::$values) >= self::MAX_ENTRIES) {
            self::$values = [];
        }

        try {
            $plain = Crypt::decryptString($stored);
        } catch (DecryptException) {
            self::$values[$stored] = false;

            // Ciphertext written under another key is unreadable, not legacy
            // plaintext. An empty string says so; the base64 said nothing.
            return self::looksEncrypted($stored) ? '' : $stored;
        }

        self::$values[$stored] = $plain;

        return $plain;
    }

    /**
     * Whether a stored value is Laravel ciphertext by shape — base64 of a JSON
     * object carrying iv, value and mac — whatever key it was written with.
     *
     * Shape alone, deliberately: this is asked precisely when decryption has
     * already failed, so the question is no longer "can this be opened" but
     * "was this ever meant to be readable as it stands".
     */
    public static function looksEncrypted(string $stored): bool
    {
        $decoded = base64_decode($stored, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
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
