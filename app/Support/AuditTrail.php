<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Schema;

/**
 * Records who accessed, created, modified, or deleted personal and
 * sensitive personal information, and when. Every write path is
 * fail-safe: an audit failure must never break the action being audited,
 * so errors are swallowed after being reported to the log file.
 */
class AuditTrail
{
    public static function record(
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $description = null,
        ?array $details = null,
    ): void {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }

            $request = request();
            $session = $request?->hasSession() ? $request->session() : null;

            AuditLog::create([
                'actor_name' => $session?->get('active_name'),
                'actor_username' => $session?->get('active_username'),
                'actor_role' => $session?->get('active_role', 'guest'),
                'institution_id' => $session?->get('active_institution_id'),
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'description' => $description,
                'details' => $details ?: null,
                'http_method' => $request?->method(),
                'url' => $request ? substr($request->fullUrl(), 0, 2048) : null,
                'route_name' => $request?->route()?->getName(),
                'ip_address' => $request?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
