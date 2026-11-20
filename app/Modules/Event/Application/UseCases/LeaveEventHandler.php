<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventIdentityParticipationService;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Social\Application\Services\SocialActionLimiter;
use App\Modules\Social\Application\Services\SocialWallService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LeaveEventHandler
{
    public function __construct(
        private readonly EventIdentityParticipationService $identityParticipation,
        private readonly SocialActionLimiter $socialActions,
        private readonly SocialWallService $wall,
    ) {}

    public function handle(string $identifier, User $user): Event
    {
        $user = $user->canonical();

        $eventId = Event::query()->whereRouteIdentifier($identifier)->firstOrFail()->id;
        $changed = false;
        $event = $this->socialActions->run($user, 'event', $eventId, 'rsvp', function () use (
            $identifier, $user, &$changed
        ): array {
            $event = DB::transaction(function () use ($identifier, $user, &$changed): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $participant = $this->identityParticipation->effectiveParticipant($event, $user);

            if ($participant === null || $participant->status !== EventParticipantStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Вы не участвуете в этом мероприятии.');
            }

            $isOrganizer = $participant->role === EventParticipantRoleEnum::ORGANIZER;
            if ($isOrganizer && ! $event->organizerActor()->whereIn('user_id', $user->identityIds())->exists()) {
                throw new InvalidArgumentException('Запись организатора не соответствует владельцу мероприятия.');
            }

            if ($event->ends_at === null || $event->ends_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('После завершения мероприятия изменить участие нельзя.');
            }

            $previousStatus = $participant->status;
            $participant->update([
                'status' => EventParticipantStatusEnum::LEFT,
                'left_at' => now(),
                'responsibility_status' => null,
                'responsibility_requested_by_user_id' => null,
                'responsibility_requested_at' => null,
                'responsibility_responded_at' => null,
                'status_changed_by_actor_id' => null,
                'status_changed_at' => null,
            ]);
            if (! $isOrganizer) {
                $participant->responsibilityPermissions()->delete();
            }

            $this->wall->appendSystem('event', $event->id, $user, 'event_rsvp_changed', [
                'from' => $previousStatus->value,
                'to' => EventParticipantStatusEnum::LEFT->value,
                'confirmation_version' => $event->participation_confirmation_version,
            ]);
            $changed = true;
            return $event;
            });

            return ['changed' => $changed, 'value' => $event];
        });

        if ($changed) {
            event(new EventChanged($event->id));
        }

        return $event;
    }
}
