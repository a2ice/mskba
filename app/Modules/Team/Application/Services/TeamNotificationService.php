<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use Illuminate\Support\Collection;

final class TeamNotificationService
{
    public function __construct(private readonly CreateUserNotificationHandler $notifications) {}

    public function invitationSent(Team $team, ContractMembership $membership, User $inviter): void
    {
        $role = collect($membership->sportRoleValues())->map(fn (string $value): string => match ($value) {
            'player' => 'игрока',
            'coach' => 'тренера',
            'manager' => 'менеджера',
            default => $value,
        })->join(', ');

        $this->create(
            $membership->user_id,
            'Приглашение в команду',
            $this->userName($inviter).' приглашает вас вступить в команду «'.$team->name.'»'.($role !== '' ? ' в роли '.$role : '').'.',
            route('teams.show', $team->routeIdentifier()),
            'Просмотреть приглашение',
            'team.invitation.created',
            ['team_id' => $team->id, 'membership_id' => $membership->id],
        );
    }

    public function invitationResponded(Team $team, ContractMembership $membership, bool $accepted): void
    {
        $inviterId = $membership->contract?->assigned_by;
        if (! is_int($inviterId) && ! is_numeric($inviterId)) {
            return;
        }

        $member = User::query()->with('profile')->find($membership->user_id);
        $this->create(
            (int) $inviterId,
            $accepted ? 'Приглашение принято' : 'Приглашение отклонено',
            ($member ? $this->userName($member) : 'Пользователь')
                .($accepted ? ' вступил' : ' отклонил приглашение').' в команду «'.$team->name.'».',
            route('teams.management', $team->routeIdentifier()),
            'Открыть команду',
            $accepted ? 'team.invitation.accepted' : 'team.invitation.declined',
            ['team_id' => $team->id, 'membership_id' => $membership->id],
        );
    }

    public function joinRequestSubmitted(Team $team, TeamJoinRequest $request): void
    {
        $applicant = $request->user()->with('profile')->first();
        foreach ($this->joinRequestManagers($team) as $userId) {
            $this->create(
                $userId,
                'Новая заявка в команду',
                ($applicant ? $this->userName($applicant) : 'Пользователь').' хочет вступить в команду «'.$team->name.'».',
                route('teams.join-requests.index', $team->routeIdentifier()).'?request='.$request->id,
                'Просмотреть заявку',
                'team.join_request.submitted',
                ['team_id' => $team->id, 'join_request_id' => $request->id],
            );
        }
    }

    public function joinRequestReviewed(Team $team, TeamJoinRequest $request, string $action): void
    {
        [$title, $body, $source] = match ($action) {
            'accept' => ['Заявка принята', 'Вас приняли в команду «'.$team->name.'».', 'team.join_request.accepted'],
            'reject' => ['Заявка отклонена', 'Команда «'.$team->name.'» отклонила вашу заявку.', 'team.join_request.rejected'],
            'block' => [
                'Отправка заявок заблокирована',
                'Вы больше не можете отправлять заявки в команду «'.$team->name.'».',
                'team.join_request.blocked',
            ],
            'unblock' => [
                'Отправка заявок снова доступна',
                'Вы снова можете подать заявку в команду «'.$team->name.'».',
                'team.join_request.unblocked',
            ],
            default => ['Статус заявки изменён', 'Статус вашей заявки в команду «'.$team->name.'» изменён.', 'team.join_request.changed'],
        };
        if (filled($request->review_reason)) {
            $body .= ' Причина: '.$request->review_reason;
        }

        $this->create(
            $request->user_id,
            $title,
            $body,
            route('teams.show', $team->routeIdentifier()),
            'Открыть команду',
            $source,
            ['team_id' => $team->id, 'join_request_id' => $request->id],
        );
    }

    /** @return Collection<int, int> */
    private function joinRequestManagers(Team $team): Collection
    {
        $creatorId = $team->createdByActor()->value('user_id');
        $memberIds = $team->memberships()
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permission', TeamPermissionEnum::MANAGE_JOIN_REQUESTS->value)))
            ->pluck('user_id');

        return $memberIds
            ->when($creatorId !== null, fn (Collection $ids) => $ids->push((int) $creatorId))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /** @param array<string, mixed> $context */
    private function create(
        int $userId,
        string $title,
        string $body,
        string $actionUrl,
        string $actionText,
        string $source,
        array $context,
    ): void {
        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $userId,
            type: UserNotificationTypeEnum::SYSTEM,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            actionText: $actionText,
            payload: array_merge($context, [
                'source' => $source,
                'delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value,
            ]),
        ));
    }

    private function userName(User $user): string
    {
        $user->loadMissing('profile');

        return trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? ''))
            ?: ($user->username ? '@'.$user->username : 'Пользователь #'.$user->id);
    }
}
