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
                'court.media' => fn ($query) => $query->where('collection', 'gallery')->orderByDesc('is_featured')->orderBy('sort_order'),
                'booking.court.media',
                'sourceBooking.court.media',
                'venueInterests',
                'venueRevisions.previousVenue',
                'venueRevisions.nextVenue',
                'venueRevisions.previousCourt',
                'venueRevisions.nextCourt',
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
                    ->withExists(['admissions', 'actions'])
                    ->with([
                        'plannedTeamA.logo',
                        'plannedTeamB.logo',
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

        $isPublic = in_array($event->status, [
            EventStatusEnum::PUBLISHED,
            EventStatusEnum::COMPLETED,
            EventStatusEnum::CANCELLED,
        ], true) && $event->visibility === EventVisibilityEnum::PUBLIC;
        $canManage = $actor !== null && $this->access->canManage($event, $actor);

        $isInvited = $event->proposed_time_options !== null
            && $event->status === EventStatusEnum::PUBLISHED
            && $actor?->user !== null
            && ! $actor->user->canonical()->trashed()
            && ! $actor->user->canonical()->isBlocked()
            && $event->participants->contains(fn ($participant) => in_array((int) $participant->user_id, $actor->user->canonical()->identityIds(), true));

        if (! $isPublic && ! $canManage && ! $isInvited) {
            throw (new ModelNotFoundException)->setModel(Event::class, [$identifier]);
        }

        return $event;
    }
}
