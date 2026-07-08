<?php

namespace App\Http\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * Record an audit entry. Actor is resolved from the active guard when not given.
     */
    public static function log(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null): void
    {
        [$actorType, $actorId] = self::resolveActor();

        AuditLog::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255),
        ]);
    }

    private static function resolveActor(): array
    {
        if (auth('vendor')->check()) {
            return ['vendor', auth('vendor')->id()];
        }

        if (auth('web')->check()) {
            return ['user', auth('web')->id()];
        }

        return ['system', null];
    }
}
