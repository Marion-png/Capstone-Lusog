<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side protections on every response the application sends.
 *
 * Global rather than in the `web` group, so a 404, a refused write and a JSON
 * error carry them too. The web server adds the same nosniff header to the
 * static files it serves itself (see the Caddyfile); everything here is what
 * only the application can decide.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A file is only ever read as the type it was sent as, so an uploaded
        // document cannot be made to run as a script.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // No other site may frame a page here and dress it up as its own.
        // Same-origin framing (a document preview, a print frame) still works.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        // URLs here carry record ids. They are sent in full within the app
        // and not at all to another site (the font host included).
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), payment=(), usb=()');
        // Learners' health records are not for search engines, login page
        // included. robots.txt says the same to the crawlers that read it.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        // Only over HTTPS: sent over plain HTTP the header is ignored anyway,
        // and a local http:// server must stay reachable.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // The exact PHP version is of use to nobody but an attacker. The web
        // server turns expose_php off; this covers any other server.
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
