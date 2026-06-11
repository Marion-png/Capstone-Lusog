<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    /**
     * Require the app's session-based login for private screens and prevent
     * browsers from serving those screens from history after logout.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->requiresActiveSession($request) && !$request->session()->has('active_role')) {
            return $this->withNoCacheHeaders(redirect()->route('login'));
        }

        return $this->withNoCacheHeaders($next($request));
    }

    private function requiresActiveSession(Request $request): bool
    {
        return $request->is(
            'dashboard',
            'dashboard/*',
            'adviser',
            'adviser/*',
            'nurse',
            'nurse/*',
            'health-records',
            'health-records/*'
        );
    }

    private function withNoCacheHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Fri, 01 Jan 1990 00:00:00 GMT');

        return $response;
    }
}
