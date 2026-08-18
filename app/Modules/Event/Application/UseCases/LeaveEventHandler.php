<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventIdentityParticipationService;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LeaveEventHandler
{
    public function __construct(
        private readonly EventIdentityParticipationService $identityParticipation,
    ) {}

    public function handle(string $identifier, User $user): Event
    {
        $user = $user->canonical();

        $event = DB::transaction(function () use ($identifier, $user): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $participant = $this->identityParticipation->effectiveParticipant($event, $user);

            if ($participant === null || $participant->status !== EventParticipantStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Вы не участвуете в этом мероприятии.');
            }

            if ($participant->role === EventParticipantRoleEnum::ORGANIZER) {
                throw new InvalidArgumentException('Организатор не может покинуть собственное мероприятие.');
            }

            if ($event->ends_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('После завершения мероприятия изменить участие нельзя.');
            }

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
            $participant->responsibilityPermissions()->delete();

            return $event;
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
