<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListEventsHandler
{
    /**
     * @param  list<EventTypeEnum>  $types
     * @return LengthAwarePaginator<Event>
     */
    public function handle(
        ?Actor $actor,
        array $types = [],
        string $period = 'upcoming',
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $outcome = null,
    ): LengthAwarePaginator {
        $timezone = (string) config('app.timezone', 'Europe/Moscow');
        $startsFrom = $dateFrom === null
            ? null
            : CarbonImmutable::parse($dateFrom, $timezone)->startOfDay()->utc();
        $startsTo = $dateTo === null
            ? null
            : CarbonImmutable::parse($dateTo, $timezone)->endOfDay()->utc();

        return Event::query()
            ->whereNull('parent_event_id')
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
            ->when($types !== [], fn ($query) => $query->whereIn(
                'type',
                array_map(
                    static fn (EventTypeEnum $type): string => $type->value,
                    $types,
                ),
            ))
            ->when($startsFrom, fn ($query) => $query->where('starts_at', '>=', $startsFrom))
            ->when($startsTo, fn ($query) => $query->where('starts_at', '<=', $startsTo))
            ->when($period === 'past' && $outcome !== null, function ($query) use ($outcome): void {
                match ($outcome) {
                    'completed' => $query->where('status', EventStatusEnum::COMPLETED->value),
                    'cancelled' => $query->where('status', EventStatusEnum::CANCELLED->value),
                    'unmarked' => $query->whereNotIn('status', [
                        EventStatusEnum::COMPLETED->value,
                        EventStatusEnum::CANCELLED->value,
                    ]),
                };
            })
            ->paginate(12)
            ->withQueryString();
    }
}
