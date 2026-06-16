<?php

namespace App\Modules\Audit\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ListAuditLogsHandler
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = AuditLog::query()
            ->with(['actor.user', 'actor.fingerprint'])
            ->latest('id');

        $entity = trim((string) ($filters['entity'] ?? ''));
        if ($entity !== '') {
            $query->where('auditable_type', $entity);
        }

        $event = trim((string) ($filters['event'] ?? ''));
        if ($event !== '') {
            $query->where('event', $event);
        }

        $actorSearch = trim((string) ($filters['actor'] ?? ''));
        if ($actorSearch !== '') {
            $query->whereHas('actor', function (Builder $query) use ($actorSearch): void {
                $query
                    ->where('actor_key', 'like', '%'.$actorSearch.'%')
                    ->orWhereHas('user', fn (Builder $query) => $query->where('username', 'like', '%'.$actorSearch.'%'));
            });
        }

        return $query
            ->paginate($this->perPage($filters))
            ->withQueryString();
    }

    /**
     * @return array<int, string>
     */
    public function entities(): array
    {
        return AuditLog::query()
            ->select('auditable_type')
            ->distinct()
            ->orderBy('auditable_type')
            ->pluck('auditable_type')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function events(): array
    {
        return AuditLog::query()
            ->select('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return min(max($perPage, 5), 50);
    }
}
