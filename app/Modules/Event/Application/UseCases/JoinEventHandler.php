<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class JoinEventHandler
{
    public function handle(string $identifier, User $user): Event
    {
        $event = DB::transaction(function () use ($identifier, $user): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();

            if ($event->status !== EventStatusEnum::PUBLISHED || $event->visibility !== EventVisibilityEnum::PUBLIC) {
                throw new InvalidArgumentException('К этому мероприятию сейчас нельзя присоединиться.');
            }

            if ($event->starts_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('Мероприятие уже началось.');
            }

            $participant = $event->participants()->where('user_id', $user->id)->first();

            if ($participant?->status === EventParticipantStatusEnum::CONFIRMED) {
                return $event;
            }

            $confirmedCount = $event->participants()
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->count();

            if ($event->max_participants !== null && $confirmedCount >= $event->max_participants) {
                throw new InvalidArgumentException('Все места на мероприятии уже заняты.');
            }

            $event->participants()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => EventParticipantRoleEnum::PARTICIPANT,
                    'status' => EventParticipantStatusEnum::CONFIRMED,
                    'joined_at' => now(),
                    'left_at' => null,
                ],
            );

            return $event->load('participants.user');
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
