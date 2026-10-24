<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventIdentityParticipationService;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AddEventParticipantHandler
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly UserPrivacyAccessService $privacy,
        private readonly EventIdentityParticipationService $identityParticipation,
    ) {}

    public function handle(string $identifier, Actor $actor, int $userId, bool $asBackoffice = false, ?array $participationRoles = null): Event
    {
        // Keep existing web invitations as player by default. Backoffice may
        // explicitly select multiple event-specific functions.
        $roles = $participationRoles ?? [UserParticipationRoleEnum::PLAYER->value];
        if ($roles === [] || count($roles) !== count(array_unique($roles))
            || collect($roles)->contains(fn ($role): bool => ! is_string($role)
                || ($case = UserParticipationRoleEnum::tryFrom($role)) === null
                || $case === UserParticipationRoleEnum::ORGANIZER)) {
            throw new InvalidArgumentException('Выберите хотя бы одну допустимую роль участия.');
        }

        $event = DB::transaction(function () use ($identifier, $actor, $userId, $asBackoffice, $roles): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            if (! $asBackoffice) {
                $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
            } else {
                $user = $actor->user?->canonical();
                $permissions = app(BackofficePermissionManager::class);
                abort_unless($user !== null
                    && $permissions->allows($user, Permission::BACKOFFICE_ACCESS)
                    && $permissions->allows($user, Permission::BACKOFFICE_EVENT_MANAGE), 403);
            }
            $this->assertCanChangeParticipants($event);

            $viewer = $actor->user?->canonical();
            $user = User::query()->whereKey($userId)->firstOrFail()->canonical();

            if (! $viewer instanceof User
                || $user->status === UserStatusEnum::BLOCKED
                || ! $this->privacy->allows($user, $viewer, UserPrivacySettingTypeEnum::DISCOVERABILITY)) {
                throw new InvalidArgumentException('Этот пользователь недоступен для добавления.');
            }

            $existing = $this->identityParticipation->effectiveParticipant($event, $user);
            $alreadyConfirmed = $existing?->status === EventParticipantStatusEnum::CONFIRMED
                && $existing->confirmation_version === $event->participation_confirmation_version;

            if ($alreadyConfirmed) {
                throw new InvalidArgumentException('Пользователь уже участвует в мероприятии.');
            }

            // Organizer membership is not interchangeable with an invitation.
            if ($existing?->role === EventParticipantRoleEnum::ORGANIZER) {
                throw new InvalidArgumentException('Организатора нельзя пригласить как обычного участника.');
            }

            $existing?->responsibilityPermissions()->delete();
            $attributes = [
                'role' => EventParticipantRoleEnum::PARTICIPANT,
                'participation_roles' => $roles,
                'status' => EventParticipantStatusEnum::TENTATIVE,
                'joined_at' => null,
                'left_at' => null,
                'confirmation_version' => $event->participation_confirmation_version,
                'responsibility_status' => null,
                'responsibility_requested_by_user_id' => null,
                'responsibility_requested_at' => null,
                'responsibility_responded_at' => null,
                'status_changed_by_actor_id' => $actor->id,
                'status_changed_at' => now(),
            ];

            if ($existing !== null) {
                $existing->update($attributes);
            } else {
                $event->participants()->create([
                    'user_id' => $user->id,
                    ...$attributes,
                ]);
            }

            return $event;
        });

        event(new EventChanged($event->id));

        return $event;
    }

    private function assertCanChangeParticipants(Event $event): void
    {
        if (in_array($event->status, [EventStatusEnum::DRAFT, EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
            || $event->ends_at->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Состав этого мероприятия уже нельзя изменять.');
        }
    }
}
