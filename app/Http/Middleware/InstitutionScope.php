<?php

namespace App\Http\Middleware;

use App\Support\AccountSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InstitutionScope
{
    // These roles must have an institution_id in their session; they can only see their school's data.
    const SCOPED_ROLES = [
        'school_nurse',
        'clinic_staff',
        'clinic_teacher',
        'class_adviser',
        'school_head',
        'feeding_coor',
        // One school's admin (AdminScope): without its school it covers nothing.
        'school_admin',
    ];

    // These roles see all schools — no institution filter applied.
    const ADMIN_ROLES = ['system_admin'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('active_role')) {
            return $next($request);
        }

        $role = (string) $request->session()->get('active_role');

        // A session still holding a role SIGLA no longer has (signed in before
        // the Nutrition Coordinator was removed) is signed out on every path.
        if (in_array($role, AccountSettings::RETIRED_ROLES, true) && ! $this->isSignInPage($request)) {
            $request->session()->invalidate();

            return redirect()->route('login');
        }

        if (in_array($role, self::SCOPED_ROLES, true)) {
            $institutionId = $request->session()->get('active_institution_id');

            // Every path, not only the signed-in prefixes: the JSON endpoints
            // under /api, the file downloads and the announcement and event
            // writes are school data too, and several of them filter by school
            // only `when` there is one. The sign-in pages are left alone, so
            // a stale session can always sign in again.
            if (! $institutionId && ! $this->isSignInPage($request)) {
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

    private function isSignInPage(Request $request): bool
    {
        return $request->is('/', 'login', 'admin-login', 'logout', 'account-request');
    }
}
