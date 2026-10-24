<?php

namespace App\Modules\Favorites\Application\Services;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;

final class FavoriteTargetRegistry
{
    public const TYPES = [
        'venue' => 'Площадки',
        'event' => 'Мероприятия',
        'team' => 'Команды',
    ];

    public function visible(string $type, int $id): ?Model
    {
        return match ($type) {
            'venue' => Venue::query()->whereKey($id)->where('status', VenueStatusEnum::CONFIRMED)->first(),
            'event' => Event::query()->whereKey($id)
                ->where('visibility', EventVisibilityEnum::PUBLIC)
                ->whereIn('status', [EventStatusEnum::PUBLISHED->value, EventStatusEnum::COMPLETED->value])->first(),
            'team' => Team::query()->whereKey($id)
                ->where('status', TeamStatusEnum::ACTIVE)->whereNull('temporary_for_event_id')->first(),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function describe(string $type, int $id): array
    {
        $target = $this->visible($type, $id);
        if ($target === null) {
            return [
                'title' => 'Объект недоступен',
                'url' => null,
                'available' => false,
            ];
        }

        return [
            'title' => $type === 'event' ? $target->title : $target->name,
            'url' => match ($type) {
                'venue' => route('venues.show', $target->routeIdentifier()),
                'event' => route('events.show', $target->routeIdentifier()),
                'team' => route('teams.show', $target->routeIdentifier()),
            },
            'available' => true,
        ];
    }
}
