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
        'health-records/students/*/documents/pulse',
    ];

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

            AuditTrail::record(
                $action,
                null,
                null,
                ucfirst($action).' '.$request->path(),
                $parameters !== [] ? ['route_parameters' => $parameters] : null,
            );
        }

        return $next($request);
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
