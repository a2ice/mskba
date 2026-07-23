<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListEventsHandler
{
    /** @return LengthAwarePaginator<Event> */
    public function handle(?Actor $actor, ?EventTypeEnum $type = null, string $period = 'upcoming'): LengthAwarePaginator
    {
        return Event::query()
            ->with(['venue.schedule', 'booking'])
            ->withCount(['participants as participants_count' => fn ($query) => $query->where('status', 'confirmed')])
            ->when(
                $period === 'past',
                fn ($query) => $query->where('ends_at', '<=', now())->orderByDesc('ends_at'),
                fn ($query) => $query->where('ends_at', '>', now())->orderBy('starts_at'),
            )
            ->where(function ($query) use ($actor, $period): void {
                $query->where(function ($public) use ($period): void {
                    $public
                        ->whereIn('status', $period === 'past'
                            ? [
                                EventStatusEnum::PUBLISHED->value,
                                EventStatusEnum::COMPLETED->value,
                            ]
                            : [EventStatusEnum::PUBLISHED->value])
                        ->where('visibility', EventVisibilityEnum::PUBLIC->value);
                });

                if ($actor?->user_id !== null) {
                    $query->orWhere(function ($own) use ($actor): void {
                        $own->whereHas('organizerActor', fn ($organizer) => $organizer->where('user_id', $actor->user_id));
                    });
                }
            })
            ->when($type, fn ($query) => $query->where('type', $type->value))
            ->paginate(12)
            ->withQueryString();
    }
}
