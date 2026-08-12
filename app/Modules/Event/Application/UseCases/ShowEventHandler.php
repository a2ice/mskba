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
                'venue.characteristics',
                'venue.location.address',
                'venue.location.metroStations.line',
                'venue.amenities',
                'venue.media' => fn ($query) => $query
                    ->where('collection', 'gallery')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'booking',
                'organizerActor.user.profile.activeAvatar',
                'organizerActor.user.telegramAccount',
                'organizerActor.user.contacts',
                'participants.user.profile.activeAvatar',
                'participants.statusChangedByActor.user.profile',
                'participants.responsibilityPermissions',
                'primaryGame' => fn ($query) => $query->with([
                    'sides.team.logo',
                    'rosterEntries.user.profile.activeAvatar',
                    'playerStatistics',
                    'periods',
                    'tournamentMatch.tournament',
                ]),
                'games' => fn ($query) => $query
                    ->with([
                        'sides.team.logo',
                        'rosterEntries.user.profile.activeAvatar',
                        'playerStatistics',
                        'periods',
                    ])
                    ->orderByRaw('scheduled_starts_at nulls last')
                    ->orderBy('id'),
                'media' => fn ($query) => $query
                    ->where('collection', 'event_results')
                    ->with('eventResultPhotoTags.user.profile')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->firstOrFail();

        $isPublic = in_array($event->status, [EventStatusEnum::PUBLISHED, EventStatusEnum::COMPLETED], true)
            && $event->visibility === EventVisibilityEnum::PUBLIC;
        $canManage = $actor !== null && $this->access->canManage($event, $actor);

        if (! $isPublic && ! $canManage) {
            throw (new ModelNotFoundException)->setModel(Event::class, [$identifier]);
        }

        return $event;
    }
}
