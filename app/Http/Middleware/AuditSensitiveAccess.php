<?php

namespace App\Http\Middleware;

use App\Support\AuditTrail;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every request that touches personal or sensitive personal
 * information: dashboards and pages that display student data, API
 * endpoints that return it, document downloads, and the parent consent
 * link. Together with the Auditable model trait (which records the exact
 * field-level changes) this answers "who accessed or changed what, when,
 * and from where" during a forensic investigation.
 */
class AuditSensitiveAccess
{
    /** URL patterns that expose personal / sensitive personal information. */
    private const SENSITIVE_PATTERNS = [
        'dashboard', 'dashboard/*',
        'adviser', 'adviser/*',
        'nurse', 'nurse/*',
        'health-records', 'health-records/*',
        'medical-certificate/*',
        'parental-consent/*',
        'consent/*',
        'api/student-conditions',
        'api/student-consent-status',
        'api/student-health-assessment',
        'api/student-health-history',
        'api/student-clinic-notes',
        'api/student-consultations',
    ];

    /**
     * Change-detection endpoints that return no personal information — only a
     * hashed row-count/timestamp fingerprint. Dashboard panels poll these on a
     * timer, so auditing them would bury real access records in noise without
     * recording any access to personal data. Never exempt a path that returns
     * personal information.
     */
    private const NON_SENSITIVE_PATTERNS = [
        'dashboard/class-adviser/activity/pulse',
        'dashboard/school-head/metrics/pulse',
        'dashboard/feedingcor-dashboard/metrics/pulse',
        'health-records/students/*/documents/pulse',
    ];

    /**
     * Request attribute carrying what this request should record.
     *
     * It is held on the request, not on the middleware: Laravel resolves a
     * fresh middleware instance to call terminate(), so anything stored on
     * `$this` in handle() is gone by then. The request object is the same one.
     */
    private const PENDING = 'audit.pending';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldAudit($request)) {
            $action = match (true) {
                $request->isMethod('GET') || $request->isMethod('HEAD') => str_contains($request->path(), 'download') ? 'downloaded' : 'viewed',
                $request->isMethod('DELETE') => 'delete_requested',
                default => 'submitted',
            };

            $parameters = collect($request->route()?->parameters() ?? [])
                ->map(fn ($value) => $value instanceof Model
                    ? class_basename($value).'#'.$value->getKey()
                    : (string) $value)
                ->all();

            // Decided here, written in terminate(): the row itself is an
            // INSERT, and against a hosted database that is a round trip the
            // reader was waiting on before their page had even started
            // rendering. What gets recorded is fixed at this point, so moving
            // the write later changes when the entry lands, never whether it
            // does or what it says.
            $request->attributes->set(self::PENDING, [
                'action' => $action,
                'description' => ucfirst($action).' '.$request->path(),
                'details' => $parameters !== [] ? ['route_parameters' => $parameters] : null,
            ]);
        }

        return $next($request);
    }

    /**
     * Writes the entry after the response has been sent to the client.
     *
     * Every access is still recorded, including one that was refused: the
     * decision was taken in handle(), before the action ran, so a School Head
     * write that RestrictSchoolHeadWrites turned away is logged exactly as it
     * was before. Failures are swallowed by AuditTrail, as they always were.
     */
    public function terminate(Request $request, Response $response): void
    {
        $pending = $request->attributes->get(self::PENDING);

        if (! is_array($pending)) {
            return;
        }

        $request->attributes->remove(self::PENDING);

        AuditTrail::record(
            $pending['action'],
            null,
            null,
            $pending['description'],
            $pending['details'],
        );
    }

    private function shouldAudit(Request $request): bool
    {
        if ($request->is(...self::NON_SENSITIVE_PATTERNS)) {
            return false;
        }

        if (! $request->is(...self::SENSITIVE_PATTERNS)) {
            return false;
        }

        // Authenticated staff, or a parent opening their consent link.
        return $request->session()->has('active_role') || $request->is('consent/*');
    }
}
