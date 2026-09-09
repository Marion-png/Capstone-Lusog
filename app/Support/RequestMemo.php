<?php

namespace App\Support;

use Closure;

/**
 * Remembers the result of an expensive read for the length of one request.
 *
 * Deliberately not a property on the controller. Laravel caches the resolved
 * controller instance on the Route object, and the router survives from one
 * request to the next inside a test process — so an instance property looks
 * per-request in production and quietly persists across requests under test,
 * which is how a memoized value ends up being served after the data behind it
 * has changed. A scoped container binding is discarded when the framework
 * terminates the request (and between queue jobs), in tests and in production
 * alike.
 *
 * Keys must describe the query's own inputs, so a reuse is only ever a reuse
 * of the identical read.
 */
class RequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $resolve
     * @return TValue
     */
    public static function remember(string $key, Closure $resolve): mixed
    {
        $memo = app(self::class);

        // array_key_exists, not ??=: a resolver that legitimately returns null
        // (no cycle start date yet, no configured cycle length) would otherwise
        // be re-run on every call and the memo would cache nothing at all.
        if (! array_key_exists($key, $memo->values)) {
            $memo->values[$key] = $resolve();
        }

        return $memo->values[$key];
    }

    public static function flush(): void
    {
        app(self::class)->values = [];
    }

    /**
     * Drop every memo whose key starts with $prefix.
     *
     * A memo is only ever safe while the data behind it has not moved. A
     * request that writes and then reads the same thing has moved it, so the
     * write path says so explicitly rather than relying on nobody ever adding a
     * read after it.
     */
    public static function forgetPrefix(string $prefix): void
    {
        $memo = app(self::class);

        foreach (array_keys($memo->values) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($memo->values[$key]);
            }
        }
    }
}
