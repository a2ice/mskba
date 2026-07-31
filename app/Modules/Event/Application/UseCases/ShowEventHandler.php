<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ShowEventHandler
{
    public function __construct(private readonly EventManagementAccess $access) {}

    public function handle(string $identifier, ?Actor $actor): Event
    {
        $event = Event::query()
            ->whereRouteIdentifier($identifier)
            ->with([
                'venue.schedule',
                'venue.location.address',
                'venue.location.metroStations.line',
                'venue.amenities',
                'venue.media' => fn ($query) => $query
                    ->where('collection', 'gallery')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'booking',
                'parentEvent.participants.user.profile.activeAvatar',
                'organizerActor.user.profile.activeAvatar',
                'organizerActor.user.telegramAccount',
                'organizerActor.user.contacts',
                'participants.user.profile.activeAvatar',
                'participants.statusChangedByActor.user.profile',
                'participants.responsibilityPermissions',
                'gameDetail',
                'gameSides.team.memberships.contract',
                'gameSides.team.memberships.user.profile.activeAvatar',
                'gameRosterEntries.gameSide',
                'gameRosterEntries.user.profile.activeAvatar',
                'gamePlayerStatistics',
                'childGames' => fn ($query) => $query
                    ->with(['gameDetail', 'gameSides.team'])
                    ->orderBy('starts_at'),
                'media' => fn ($query) => $query->where('collection', 'event_results')->orderBy('sort_order')->orderBy('id'),
            ])
            ->firstOrFail();

        $visibilitySource = $event->parentEvent ?? $event;
        $isPublic = in_array($visibilitySource->status, [EventStatusEnum::PUBLISHED, EventStatusEnum::COMPLETED], true)
            && $visibilitySource->visibility === EventVisibilityEnum::PUBLIC;
        $canManage = $actor !== null && $this->access->canManage($event, $actor);

        if (! $isPublic && ! $canManage) {
            throw (new ModelNotFoundException)->setModel(Event::class, [$identifier]);
        }

        return $event;
    }
}
