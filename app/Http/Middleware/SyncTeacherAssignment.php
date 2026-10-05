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

        if (in_array($role, AccountSettings::TEACHER_ROLES, true)) {
            $account = AccountSettings::accountFor($request);

            if ($account !== null && ($account['role'] ?? null) === $role) {
                AccountSettings::syncTeacherAssignment($request, $account);
            }
        }

        return $next($request);
    }
}
