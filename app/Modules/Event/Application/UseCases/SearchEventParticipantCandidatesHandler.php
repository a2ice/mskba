<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventIdentityParticipationService;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
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
        private readonly EventIdentityParticipationService $identityParticipation,
    ) {}

    /** @return Collection<int, User> */
    public function handle(string $identifier, Actor $actor, string $query): Collection
    {
        $event = Event::query()->whereRouteIdentifier($identifier)->firstOrFail();
        $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
        if (in_array($event->status, [EventStatusEnum::DRAFT, EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
            || $event->ends_at->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Состав этого мероприятия уже нельзя изменять.');
        }
        $viewer = $actor->user?->canonical();

        if (! $viewer instanceof User) {
            throw new InvalidArgumentException('Для выбора участника требуется аккаунт пользователя.');
        }

        $excludedUserIds = $this->identityParticipation->confirmedIdentityIds($event);

        return $this->users->handle($viewer, $query, $excludedUserIds);
    }
}
