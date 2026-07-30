<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RemoveEventResponsibilityHandler
{
    public function __construct(private readonly EventManagementAccess $access) {}

    public function handle(string $identifier, int $participantId, Actor $actor): Event
    {
        $event = DB::transaction(function () use ($identifier, $participantId, $actor): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $this->access->assertCanManage($event, $actor);
            $this->access->assertOwnsManagementScope($event);
            $participant = $event->participants()->whereKey($participantId)->lockForUpdate()->firstOrFail();

            if ($participant->role === EventParticipantRoleEnum::ORGANIZER) {
                throw new InvalidArgumentException('Статус организатора этим действием не изменяется.');
            }

            $participant->forceFill([
                'responsibility_status' => null,
                'responsibility_requested_by_user_id' => null,
                'responsibility_requested_at' => null,
                'responsibility_responded_at' => null,
            ])->save();

            return $event;
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
