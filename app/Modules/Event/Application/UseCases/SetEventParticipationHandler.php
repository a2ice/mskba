<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventIdentityParticipationService;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Social\Application\Services\SocialActionLimiter;
use App\Modules\Social\Application\Services\SocialWallService;
use InvalidArgumentException;

final class SetEventParticipationHandler
{
    public function __construct(
        private readonly EventIdentityParticipationService $identityParticipation,
        private readonly SocialActionLimiter $socialActions,
        private readonly SocialWallService $wall,
    ) {}

    public function handle(
        string $identifier,
        User $user,
        EventParticipantStatusEnum $status,
    ): Event {
        $user = $user->canonical();

        $eventId = Event::query()->whereRouteIdentifier($identifier)->firstOrFail()->id;
        $changed = false;
        $event = $this->socialActions->run($user, 'event', $eventId, 'rsvp', function () use (
            $identifier, $user, $status, &$changed
        ): array {
            $event = Event::query()
                ->whereRouteIdentifier($identifier)
                ->lockForUpdate()
                ->firstOrFail();

            $participant = $this->identityParticipation->effectiveParticipant($event, $user);
            $isOrganizer = $participant?->role === EventParticipantRoleEnum::ORGANIZER;
            $native = $event->proposed_time_options !== null;
            $isPublished = $event->status === EventStatusEnum::PUBLISHED
                && ($event->visibility === EventVisibilityEnum::PUBLIC || ($native && $participant !== null));
            $ownDraft = $native && $isOrganizer && $event->status === EventStatusEnum::DRAFT && $event->starts_at === null;
            if (! $isPublished && ! $ownDraft) {
                throw new InvalidArgumentException('Для этого мероприятия сейчас нельзя изменить участие.');
            }

            if ($event->ends_at?->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('После завершения мероприятия изменить участие нельзя.');
            }

            if ($isOrganizer && ! $native) {
                throw new InvalidArgumentException('Организатор не может изменить участие в собственном мероприятии.');
            }

            if ($participant?->status === $status
                && $participant->confirmation_version === $event->participation_confirmation_version) {
                return ['changed' => false, 'value' => $event];
            }

            $previousStatus = $participant?->status;
            $roles = $participant?->participation_roles ?: ['player'];
            if ($status === EventParticipantStatusEnum::CONFIRMED
                && ($participant?->status !== EventParticipantStatusEnum::CONFIRMED || $participant->confirmation_version !== $event->participation_confirmation_version)
                && in_array('player', $roles, true)
                && $event->max_participants !== null
                && $this->identityParticipation->confirmedIdentityCount($event) >= $event->max_participants) {
                throw new InvalidArgumentException('Все места на мероприятии уже заняты.');
            }

            if ($status !== EventParticipantStatusEnum::CONFIRMED) {
                $participant?->responsibilityPermissions()->delete();
            }

            $attributes = [
                'role' => $isOrganizer ? EventParticipantRoleEnum::ORGANIZER : EventParticipantRoleEnum::PARTICIPANT,
                'participation_roles' => $roles,
                'status' => $status,
                'joined_at' => $status === EventParticipantStatusEnum::CONFIRMED ? now() : null,
                'left_at' => $status === EventParticipantStatusEnum::LEFT ? now() : null,
                'confirmation_version' => $event->participation_confirmation_version,
                'status_changed_by_actor_id' => null,
                'status_changed_at' => null,
                ...($status === EventParticipantStatusEnum::CONFIRMED ? [] : [
                    'responsibility_status' => null,
                    'responsibility_requested_by_user_id' => null,
                    'responsibility_requested_at' => null,
                    'responsibility_responded_at' => null,
                ]),
            ];

            if ($participant !== null) {
                $participant->update($attributes);
            } else {
                $event->participants()->create([
                    'user_id' => $user->id,
                    ...$attributes,
                ]);
            }

            $this->wall->appendSystem('event', $event->id, $user, 'event_rsvp_changed', [
                'from' => $previousStatus?->value,
                'to' => $status->value,
                'confirmation_version' => $event->participation_confirmation_version,
            ]);
            $changed = true;
            return ['changed' => true, 'value' => $event];
        });

        if ($changed) {
            event(new EventChanged($event->id));
        }

        return $event->load('participants.user');
    }
}
