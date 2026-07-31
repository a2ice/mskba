<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\EventResponsibilityPermissionManager;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateEventResponsibilityPermissionsHandler
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly EventResponsibilityPermissionManager $permissions,
    ) {}

    /** @param list<string> $permissionValues */
    public function handle(string $identifier, int $participantId, Actor $actor, array $permissionValues): Event
    {
        $event = DB::transaction(function () use ($identifier, $participantId, $actor, $permissionValues): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $this->access->assertOwnsManagementScope($event);
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_RESPONSIBILITIES);
            $participant = $event->participants()->whereKey($participantId)->lockForUpdate()->firstOrFail();

            if (! in_array($participant->responsibility_status, [
                EventResponsibilityStatusEnum::PENDING,
                EventResponsibilityStatusEnum::ACCEPTED,
            ], true)) {
                throw new InvalidArgumentException('Участник не имеет действующего назначения.');
            }

            $this->permissions->replace($event, $participant, $actor, $permissionValues);

            return $event;
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
