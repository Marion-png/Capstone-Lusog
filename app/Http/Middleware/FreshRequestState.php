<?php

namespace App\Http\Middleware;

use App\Support\RequestMemo;
use App\Support\SchemaCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Empties the per-request caches at the start of every request.
 *
 * `scoped()` container bindings are only discarded automatically by Octane and
 * the queue worker. Under PHP-FPM each request is a fresh process, so they
 * behave as per-request already — but inside a test process one application
 * instance serves many requests, and a cache that was meant to live for one
 * request would go on answering with data the next request has already
 * changed. Clearing them here makes the two environments behave the same, and
 * makes that equivalence something the test suite can actually check.
 */
class FreshRequestState
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestMemo::flush();
        SchemaCache::flush();

        return $next($request);
    }
}
