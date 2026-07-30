<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AddEventParticipantHandler
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly UserPrivacyAccessService $privacy,
    ) {}

    public function handle(string $identifier, Actor $actor, int $userId): Event
    {
        $event = DB::transaction(function () use ($identifier, $actor, $userId): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $this->access->assertCanManage($event, $actor);
            $this->assertCanChangeParticipants($event);

            $viewer = $actor->user;
            $user = User::query()->whereKey($userId)->firstOrFail();

            if (! $viewer instanceof User
                || $user->status === UserStatusEnum::BLOCKED
                || ! $this->privacy->allows($user, $viewer, UserPrivacySettingTypeEnum::DISCOVERABILITY)) {
                throw new InvalidArgumentException('Этот пользователь недоступен для добавления.');
            }

            $existing = $event->participants()->where('user_id', $user->id)->first();
            $alreadyConfirmed = $existing?->status === EventParticipantStatusEnum::CONFIRMED
                && $existing->confirmation_version === $event->participation_confirmation_version;

            if ($alreadyConfirmed) {
                throw new InvalidArgumentException('Пользователь уже участвует в мероприятии.');
            }

            $confirmedCount = $event->participants()
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->where('confirmation_version', $event->participation_confirmation_version)
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
                    'confirmation_version' => $event->participation_confirmation_version,
                    'responsibility_status' => null,
                    'responsibility_requested_by_user_id' => null,
                    'responsibility_requested_at' => null,
                    'responsibility_responded_at' => null,
                ],
            );

            return $event;
        });

        event(new EventChanged($event->id));

        return $event;
    }

    private function assertCanChangeParticipants(Event $event): void
    {
        if (in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
            || $event->starts_at->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Состав завершённого или отменённого мероприятия менять нельзя.');
        }
    }
}
