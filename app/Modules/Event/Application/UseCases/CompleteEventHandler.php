<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CompleteEventHandler
{
    public function __construct(private readonly EventManagementAccess $access) {}

    public function handle(string $identifier, Actor $actor, ?string $description): Event
    {
        $event = DB::transaction(function () use ($identifier, $actor, $description): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $permission = $event->completed_at === null
                ? EventResponsibilityPermissionEnum::COMPLETE_EVENT
                : EventResponsibilityPermissionEnum::MANAGE_RESULT;
            $this->access->assertAllows($event, $actor, $permission);

            if ($event->status === EventStatusEnum::CANCELLED || $event->status === EventStatusEnum::DRAFT) {
                throw new InvalidArgumentException('Это мероприятие нельзя отметить состоявшимся.');
            }

            if ($event->ends_at->isFuture()) {
                throw new InvalidArgumentException('Подвести итог можно после окончания мероприятия.');
            }

            $hasUnfinishedGames = $event->games()
                ->whereNotIn('status', [GameStatusEnum::COMPLETED->value, GameStatusEnum::CANCELLED->value])
                ->lockForUpdate()
                ->exists();
            if ($hasUnfinishedGames) {
                throw new InvalidArgumentException('Сначала завершите или отмените все игры мероприятия.');
            }

            $event->forceFill([
                'status' => EventStatusEnum::COMPLETED,
                'completed_at' => $event->completed_at ?? now(),
                'completed_by_actor_id' => $event->completed_by_actor_id ?? $actor->id,
                'result_description' => $description ?: null,
            ])->save();

            return $event->refresh();
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
