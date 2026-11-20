<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(
        string $action,
        ?Model $auditable = null,
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * Audit entries whose action starts with one of `$prefixes` (e.g. "finance."), newest first, for modules that show
     * their own audit trail. Filters: action (exact), user_id, from, to (dates), auditable_type, auditable_id.
     *
     * @param  list<string>  $prefixes
     * @param  array{action?: string, user_id?: int, from?: string, to?: string, auditable_type?: string, auditable_id?: int}  $filters
     */
    public function search(array $prefixes, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where(fn ($q) => collect($prefixes)->each(fn (string $prefix) => $q->orWhere('action', 'like', $prefix.'%')))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($filters['auditable_type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['auditable_id'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $perPage)));
    }
}
