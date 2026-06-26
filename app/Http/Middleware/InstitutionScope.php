<?php

namespace App\Http\Middleware;

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
