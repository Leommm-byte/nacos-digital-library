<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Election;
use App\Support\Departments\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes entries to the audit log. Keep `meta` free of secrets (passwords,
 * tokens, OTP codes).
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(string $action, ?Model $subject = null, array $meta = [], ?int $userId = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            // Always a NACOS user: on a department's election pages the
            // default guard is "voter", whose ids aren't users'.
            'user_id' => $userId ?? auth('web')->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'meta' => $meta ?: null,
        ]);
    }

    /**
     * An election's setup and lifecycle: into NACOS's audit log for NACOS's
     * own elections, into the department's activity log for a
     * department's (each keeps its own).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function election(Election $election, string $action, array $meta = []): void
    {
        if ($election->organization_id !== null) {
            Activity::record((int) $election->organization_id, $action, $election, $meta);

            return;
        }

        self::record($action, $election, $meta);
    }
}
