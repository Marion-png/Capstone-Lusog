<?php

namespace App\Http\Middleware;

use App\Models\Institution;
use App\Support\SchemaCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    /**
     * Prototype behavior: instead of forcing a login, visiting any protected
     * URL auto-starts a demo session for the role that URL belongs to, so
     * every role's UI can be opened by typing its URL directly. Real logins
     * still work — an account session is never overwritten. Also sets
     * no-cache headers so browsers don't serve stale screens from history.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->requiresActiveSession($request)) {
            [$allowedRoles, $defaultRole] = $this->rolesForPath($request);

            if (! $request->session()->has('active_role')) {
                $this->startPrototypeSession($request, $defaultRole);
            } elseif (
                $this->isPrototypeSession($request)
                && $allowedRoles !== null
                && ! in_array($request->session()->get('active_role'), $allowedRoles, true)
            ) {
                $this->startPrototypeSession($request, $defaultRole);
            }
        }

        return $this->withNoCacheHeaders($next($request));
    }

    /**
     * Seed a fake session for the given role so any screen can be opened
     * directly during prototyping.
     */
    private function startPrototypeSession(Request $request, string $role): void
    {
        $institution = $this->prototypeInstitution();

        $request->session()->put('active_role', $role);
        $request->session()->put('active_name', 'Prototype '.ucwords(str_replace('_', ' ', $role)));
        $request->session()->put('active_username', 'prototype');
        $request->session()->put('active_school_name', $institution?->name);
        $request->session()->put('active_institution_id', $institution?->id);

        if ($role === 'class_adviser') {
            $request->session()->put('assigned_grade_level', 'Grade 4/SPED');
            $request->session()->put('assigned_section', 'SPED-A');
            $request->session()->put('assigned_school_name', $institution?->name);
        } else {
            $request->session()->forget(['assigned_grade_level', 'assigned_section', 'assigned_school_name']);
        }
    }

    private function isPrototypeSession(Request $request): bool
    {
        return $request->session()->get('active_username') === 'prototype';
    }

    /**
     * The roles a URL accepts and the role to seed when the current one does
     * not fit: [allowedRoles|null, defaultRole]. Null allowedRoles means any
     * role may view the page, so an existing session is left untouched.
     * Keeping the current role whenever it is in the allowed list prevents
     * shared pages (health records, consultation log, …) from silently
     * flipping the session to another role — which made a refresh bounce
     * users back to that other role's dashboard.
     */
    private function rolesForPath(Request $request): array
    {
        return match (true) {
            $request->is('dashboard/system-admin*') => [['system_admin'], 'system_admin'],
            $request->is('dashboard/class-adviser*', 'adviser', 'adviser/*') => [['class_adviser'], 'class_adviser'],
            $request->is('dashboard/school-head*') => [['school_head'], 'school_head'],
            $request->is('dashboard/feedingcor*') => [['feeding_coor'], 'feeding_coor'],
            $request->is('dashboard/nutricor*') => [['nutricor'], 'nutricor'],
            $request->is('dashboard/clinic-staff*') => [['clinic_staff'], 'clinic_staff'],
            $request->is('dashboard/consultation-log*', 'dashboard/medicine-inventory*') => [['clinic_staff', 'school_nurse'], 'clinic_staff'],
            $request->is('dashboard/student-health-records*', 'dashboard/data-visualization*') => [['school_nurse', 'clinic_staff'], 'school_nurse'],
            $request->is('dashboard/consent-forms*', 'dashboard/health-assessments*') => [['class_adviser', 'school_nurse', 'clinic_staff'], 'school_nurse'],
            $request->is('dashboard/school-nurse*', 'nurse', 'nurse/*') => [['school_nurse'], 'school_nurse'],
            default => [null, 'school_nurse'],
        };
    }

    private function prototypeInstitution(): ?Institution
    {
        if (! SchemaCache::hasTable('institutions')) {
            return null;
        }

        if (! Institution::active()->exists()) {
            Institution::seedDefaults();
        }

        return Institution::active()->orderBy('name')->first();
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
