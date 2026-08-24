<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;

final class GameAdmissionNotificationStateService
{
    public function __construct(
        private readonly UserNotificationCounterStore $counters,
    ) {}

    public function attachApplicationNotification(GameAdmission $admission): void
    {
        if ($admission->direction !== GameAdmissionDirectionEnum::APPLICATION) {
            return;
        }

        $game = $admission->game()->with('event')->first();
        $event = $game?->event;
        $organizerUserId = $event?->organizerActor()->value('user_id');
        if ($game === null || $event === null || $organizerUserId === null) {
            return;
        }

        $notification = UserNotification::query()
            ->where('user_id', (int) $organizerUserId)
            ->where('status', UserNotificationStatusEnum::NEW->value)
            ->where('title', 'Новая заявка на игру')
            ->where('payload->source', 'game.recruitment')
            ->where('payload->game_id', $game->id)
            ->whereNull('payload->game_admission_id')
            ->latest('id')
            ->first();

        if ($notification === null) {
            return;
        }

        $payload = $notification->payload ?? [];
        $payload['game_admission_id'] = $admission->id;
        $notification->forceFill(['payload' => $payload])->save();
    }

    public function resolve(GameAdmission $admission): void
    {
        $notifications = UserNotification::query()
            ->where('status', UserNotificationStatusEnum::NEW->value)
            ->where('payload->source', 'game.recruitment')
            ->where('payload->game_admission_id', $admission->id)
            ->get(['id', 'user_id']);

        if ($notifications->isEmpty()) {
            return;
        }

        UserNotification::query()
            ->whereKey($notifications->pluck('id'))
            ->where('status', UserNotificationStatusEnum::NEW->value)
            ->update([
                'status' => UserNotificationStatusEnum::READ->value,
                'read_at' => now(),
            ]);

        $notifications->pluck('user_id')->unique()
            ->each(fn ($userId) => $this->counters->forget((int) $userId));
    }
}
