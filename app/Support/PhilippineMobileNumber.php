<?php

namespace App\Support;

/**
 * Reads a Philippine mobile number however it was typed.
 *
 * Advisers and parents write the same number as "0917 123 4567",
 * "+63-917-123-4567", "639171234567" or "9171234567". The gateway wants one
 * spelling, and a landline or a half-typed number has to be caught here rather
 * than spend a credit on a message no phone will receive. Only a mobile
 * number (9 followed by nine digits) is accepted, because only a mobile can
 * receive a text.
 */
class PhilippineMobileNumber
{
    /** The number as the gateway takes it (09XXXXXXXXX), or null if it is not a PH mobile. */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        $national = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '639') => substr($digits, 2),
            strlen($digits) === 11 && str_starts_with($digits, '09') => substr($digits, 1),
            strlen($digits) === 10 && str_starts_with($digits, '9') => $digits,
            default => null,
        };

        return $national === null ? null : '0'.$national;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /** The last four digits, which is all a screen ever prints of a parent's number. */
    public static function lastFour(?string $raw): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        return substr($digits, -4);
    }

    /** "•••• 4567" — how a number is shown to staff. */
    public static function masked(?string $raw): string
    {
        $last = self::lastFour($raw);

        return $last === '' ? '—' : '•••• '.$last;
    }
}
