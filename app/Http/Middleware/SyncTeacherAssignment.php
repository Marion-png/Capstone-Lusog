<?php

namespace App\Http\Middleware;

use App\Support\AccountSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncTeacherAssignment
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = (string) $request->session()->get('active_role', '');

        // The change-detection polls return a hash and nothing scoped to the
        // class, so they skip the account lookup; the next page view syncs.
        if (in_array($role, AccountSettings::TEACHER_ROLES, true)
            && ! $request->is(...AuditSensitiveAccess::NON_SENSITIVE_PATTERNS)) {
            $account = AccountSettings::accountFor($request);

            if ($account !== null && ($account['role'] ?? null) === $role) {
                AccountSettings::syncTeacherAssignment($request, $account);
            }
        }

        return $next($request);
    }
}
