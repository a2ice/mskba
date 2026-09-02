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
        bool $showCancelled = false,
        ?int $venueId = null,
        bool $hasMiniGames = false,
        string $search = '',
    ): LengthAwarePaginator {
        $timezone = (string) config('app.timezone', 'Europe/Moscow');
        $startsFrom = $dateFrom === null
            ? null
            : CarbonImmutable::parse($dateFrom, $timezone)->startOfDay()->utc();
        $startsTo = $dateTo === null
            ? null
            : CarbonImmutable::parse($dateTo, $timezone)->endOfDay()->utc();
        $identityIds = $actor?->user?->canonical()->identityIds() ?? [];
        $publicStatuses = $period === 'past'
            ? [EventStatusEnum::PUBLISHED->value, EventStatusEnum::COMPLETED->value]
            : [EventStatusEnum::PUBLISHED->value];
        if ($showCancelled) {
            $publicStatuses[] = EventStatusEnum::CANCELLED->value;
        }

        return Event::query()
            ->with([
                'venue.schedule',
                'venue.location.address',
                'venue.media' => fn ($query) => $query
                    ->where('collection', 'gallery')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->limit(1),
                'booking',
                'games' => fn ($query) => $query
                    ->with('sides')
                    ->orderByRaw('scheduled_starts_at nulls last')
                    ->orderBy('id'),
            ])
            ->withCount(['participants as participants_count' => fn ($query) => $query->where('status', 'confirmed')])
            ->when(
                $period === 'past',
                fn ($query) => $query->where('ends_at', '<=', now())->orderByDesc('ends_at'),
                fn ($query) => $query->where('ends_at', '>', now())->orderBy('starts_at'),
            )
            ->where(function ($query) use ($identityIds, $publicStatuses): void {
                $query->where(function ($public) use ($publicStatuses): void {
                    $public
                        ->whereIn('status', $publicStatuses)
                        ->where('visibility', EventVisibilityEnum::PUBLIC->value);
                });

                if ($identityIds !== []) {
                    $query->orWhere(function ($own) use ($identityIds): void {
                        $own->whereHas('organizerActor', fn ($organizer) => $organizer->whereIn('user_id', $identityIds));
                    });
                }
            })
            ->when(! $showCancelled, fn ($query) => $query->where('status', '!=', EventStatusEnum::CANCELLED->value))
            ->when($types !== [], fn ($query) => $query->whereIn(
                'type',
                array_map(
                    static fn (EventTypeEnum $type): string => $type->value,
                    $types,
                ),
            ))
            ->when($startsFrom, fn ($query) => $query->where('starts_at', '>=', $startsFrom))
            ->when($startsTo, fn ($query) => $query->where('starts_at', '<=', $startsTo))
            ->when($venueId !== null, fn ($query) => $query->where('venue_id', $venueId))
            ->when($hasMiniGames, fn ($query) => $query
                ->where('type', '!=', EventTypeEnum::GAME->value)
                ->whereHas('games'))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->whereLike('title', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%")
                    ->orWhereHas('venue', fn ($venue) => $venue
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('raw_address', "%{$search}%")
                        ->orWhereHas('location.address', fn ($address) => $address
                            ->whereLike('full_address', "%{$search}%")));
            }))
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
