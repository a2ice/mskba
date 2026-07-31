<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LeaveEventHandler
{
    public function handle(string $identifier, User $user): Event
    {
        $event = DB::transaction(function () use ($identifier, $user): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $participant = $event->participants()->where('user_id', $user->id)->first();

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
