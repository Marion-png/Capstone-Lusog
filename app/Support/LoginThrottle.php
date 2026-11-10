<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Failed sign-ins, counted so a password cannot be guessed by trying many.
 *
 * Two counters, both of failures only (a correct sign-in clears the first):
 *
 *  - one username from one address: a handful of wrong passwords locks that
 *    pair for a few minutes. Keyed on the address too, so a stranger typing a
 *    nurse's username cannot lock the nurse out at the nurse's own computer;
 *  - one address across every username: whoever sprays one password over a
 *    list of usernames is stopped as well. Set well above what a staff room
 *    sharing one connection could reach by mistyping.
 */
final class LoginThrottle
{
    public const ACCOUNT_ATTEMPTS = 5;

    public const ADDRESS_ATTEMPTS = 30;

    public const DECAY_SECONDS = 300;

    /**
     * A bcrypt hash of no password anybody knows. Checked against when the
     * username does not exist, so that answer takes as long as a wrong one.
     */
    public const DUMMY_HASH = '$2y$12$saTKyppbk02K5rz7MlO69es/vLl04lRZ6sLCHBKjL0YVr/FtrAfDe';

    private function __construct(
        private readonly string $accountKey,
        private readonly string $addressKey,
    ) {}

    public static function for(string $form, string $username, string $ip): self
    {
        return new self(
            "{$form}:account:".sha1($username.'|'.$ip),
            "{$form}:address:".sha1($ip),
        );
    }

    public function locked(): bool
    {
        return RateLimiter::tooManyAttempts($this->accountKey, self::ACCOUNT_ATTEMPTS)
            || RateLimiter::tooManyAttempts($this->addressKey, self::ADDRESS_ATTEMPTS);
    }

    public function fail(): void
    {
        RateLimiter::hit($this->accountKey, self::DECAY_SECONDS);
        RateLimiter::hit($this->addressKey, self::DECAY_SECONDS);
    }

    public function clear(): void
    {
        RateLimiter::clear($this->accountKey);
    }

    public function message(): string
    {
        $seconds = max(
            RateLimiter::availableIn($this->accountKey),
            RateLimiter::availableIn($this->addressKey),
            1
        );
        $minutes = (int) ceil($seconds / 60);

        return 'Too many sign-in attempts. Try again in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').'.';
    }
}
