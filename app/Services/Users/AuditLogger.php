<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\AuditAction;
use App\Models\AdminAuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the admin action journal (RB-12). The journal holds identifiers and codes, never personal data:
 * pass ids, role codes and permission keys in `$data`, not names, emails or phone numbers.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(AuditAction|string $action, ?User $actor = null, ?Model $subject = null, array $data = []): AdminAuditEntry
    {
        $actorId = $actor !== null ? $actor->getKey() : Auth::id();

        return AdminAuditEntry::query()->create([
            'user_id' => $actorId !== null ? (int) $actorId : null,
            'action' => $action instanceof AuditAction ? $action->value : $action,
            'subject_type' => $subject !== null ? $subject->getTable() : null,
            'subject_id' => $subject !== null ? (int) $subject->getKey() : null,
            'data' => $data === [] ? null : $data,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Delete entries older than the retention period (365 days by default, `shop:cleanup`).
     */
    public function purgeOlderThan(?int $days = null): int
    {
        $days ??= (int) config('shop.audit_retention_days');

        return AdminAuditEntry::query()->where('created_at', '<', now()->subDays($days))->delete();
    }
}
