<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class SearchEventParticipantCandidatesHandler
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly SearchDiscoverableUsers $users,
    ) {}

    /** @return Collection<int, User> */
    public function handle(string $identifier, Actor $actor, string $query): Collection
    {
        $event = Event::query()->whereRouteIdentifier($identifier)->firstOrFail();
        $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
        $viewer = $actor->user;

        if (! $viewer instanceof User) {
            throw new InvalidArgumentException('Для выбора участника требуется аккаунт пользователя.');
        }

        $excludedUserIds = $event->participants()
            ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
            ->where('confirmation_version', $event->participation_confirmation_version)
            ->pluck('user_id')
            ->all();

        return $this->users->handle($viewer, $query, $excludedUserIds);
    }
}
