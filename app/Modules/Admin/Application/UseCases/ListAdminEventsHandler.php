<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminEventsHandler
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Event>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        return Event::query()
            ->with(['venue', 'booking', 'organizerActor.user'])
            ->withCount(['participants as participants_count' => fn ($query) => $query->where('status', 'confirmed')])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('title', 'like', '%'.trim($search).'%'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    /** @return list<EventStatusEnum> */
    public function statuses(): array
    {
        return EventStatusEnum::cases();
    }

    /** @return list<EventTypeEnum> */
    public function types(): array
    {
        return EventTypeEnum::cases();
    }
}
