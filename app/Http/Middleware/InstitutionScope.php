<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InstitutionScope
{
    // These roles must have an institution_id in their session; they can only see their school's data.
    const SCOPED_ROLES = [
        'school_nurse',
        'clinic_staff',
        'class_adviser',
        'school_head',
        'feeding_coor',
        'nutricor',
    ];

    // These roles see all schools — no institution filter applied.
    const ADMIN_ROLES = ['system_admin'];

    public function handle(Request $request, Closure $next): Response
    {
        // A tenant connection left bound from a previous request in the same
        // worker would silently serve the wrong school, so start every request
        // with nothing bound and re-derive it from this session.
        Tenancy::forget();

        if (! $request->session()->has('active_role')) {
            return $next($request);
        }

        $role = (string) $request->session()->get('active_role');

        if (in_array($role, self::SCOPED_ROLES, true)) {
            $institutionId = $request->session()->get('active_institution_id');

            if (! $institutionId && $this->requiresActiveSession($request)) {
                $request->session()->forget([
                    'active_role', 'active_name', 'active_username',
                    'active_school_name', 'active_institution_id',
                ]);

                return redirect()->route('login')
                    ->with('error', 'Your account has no school assigned. Contact the System Admin.');
            }

            if ($institutionId) {
                Tenancy::bind((int) $institutionId);
            }
        }

        return $next($request);
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
            'health-records/*',
        );
    }
}
