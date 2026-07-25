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

final class DeclineEventHandler
{
    public function handle(string $identifier, User $user): Event
    {
        $event = DB::transaction(function () use ($identifier, $user): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();

            if ($event->status !== EventStatusEnum::PUBLISHED || $event->visibility !== EventVisibilityEnum::PUBLIC) {
                throw new InvalidArgumentException('Для этого мероприятия сейчас нельзя изменить участие.');
            }

            if ($event->starts_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('Мероприятие уже началось.');
            }

            $participant = $event->participants()->where('user_id', $user->id)->first();

            if ($participant?->role === EventParticipantRoleEnum::ORGANIZER) {
                throw new InvalidArgumentException('Организатор не может отказаться от собственного мероприятия.');
            }

            $event->participants()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => EventParticipantRoleEnum::PARTICIPANT,
                    'status' => EventParticipantStatusEnum::LEFT,
                    'joined_at' => null,
                    'left_at' => now(),
                    'confirmation_version' => $event->participation_confirmation_version,
                ],
            );

            return $event;
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
