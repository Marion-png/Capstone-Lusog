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

        return $memo->values[$key] ??= $resolve();
    }

    public static function flush(): void
    {
        app(self::class)->values = [];
    }
}
