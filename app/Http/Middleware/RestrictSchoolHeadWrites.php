<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The School Head reads and decides; other roles write.
 *
 * The role reviews, monitors and exports. It does not encode a learner's weight
 * or height, add or transfer a learner, mark a feeding day, touch the medicine
 * or delivery log, or edit another account. Hiding those buttons is not
 * enforcement — a stale tab, a replayed form or a hand-made request reaches the
 * endpoint all the same — so the boundary is drawn here, server-side, over
 * every state-changing method at once.
 *
 * It is a deny-by-default list, not an allow-by-default one. A write route
 * added anywhere in the app is refused for this role until somebody puts it in
 * ALLOWED_ROUTES on purpose, which is the right way round: the failure mode of
 * forgetting is a School Head who cannot do something, never a School Head who
 * silently overwrote a measurement.
 */
class RestrictSchoolHeadWrites
{
    /**
     * Route names a School Head may still post to.
     *
     * Deliberately short. Signing out is the head's own session, not school
     * data; reviewing a report is the head's own decision, and the only write
     * the role has. Nothing that touches a learner's measurement, enrolment,
     * attendance, inventory or account belongs on this list.
     */
    private const ALLOWED_ROUTES = [
        'login',
        'logout',
        'dashboard.school-head.reports.review',
    ];

    /** Methods that change state. Everything else is a read. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isBlockedWrite($request)) {
            return $next($request);
        }

        $message = 'The School Head account is read-only. Reviewing, monitoring and exporting are available; '
            .'encoding and editing school records are done by the role that owns them.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        // A 403, not a redirect carrying a flash: a refusal that answers 302
        // reads to a browser — and to a script — as though the write went
        // somewhere, and the boundary has to be unambiguous.
        abort(Response::HTTP_FORBIDDEN, $message);
    }

    private function isBlockedWrite(Request $request): bool
    {
        if (! in_array($request->method(), self::WRITE_METHODS, true)) {
            return false;
        }

        if (strtolower(trim((string) $request->session()->get('active_role', ''))) !== 'school_head') {
            return false;
        }

        return ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true);
    }
}
