<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionCandidateTypeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionDirectionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionRoleEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionSourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentAdmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TournamentAdmissionService
{
    public function __construct(
        private readonly TournamentAccess $tournamentAccess,
        private readonly TeamManagementAccess $teamAccess,
        private readonly CreateUserNotificationHandler $notifications,
        private readonly UserNotificationCounterStore $notificationCounters,
    ) {}

    public function invite(Tournament $tournament, Actor $actor, Team|User $candidate): TournamentAdmission
    {
        $admission = $this->createPending($tournament, $actor, $candidate, TournamentAdmissionDirectionEnum::INVITATION);

        if ($candidate instanceof User) {
            $this->notify(
                $candidate->id,
                $tournament,
                'Приглашение на турнир',
                'Вас пригласили участвовать в турнире.',
                $admission,
                source: 'tournament.invitation.created',
            );
        } else {
            foreach ($this->teamRepresentativeUserIds($candidate) as $recipientId) {
                $this->notify(
                    $recipientId,
                    $tournament,
                    'Приглашение команды на турнир',
                    'Команду «'.$candidate->name.'» пригласили участвовать в турнире.',
                    $admission,
                    $candidate,
                    'tournament.invitation.created',
                );
            }
        }

        return $admission;
    }

    /** @param Collection<int, TournamentAdmissionRoleEnum>|null $roles */
    public function apply(Tournament $tournament, Actor $actor, Team|User $candidate, ?Collection $roles = null): TournamentAdmission
    {
        $this->assertCandidateMayAct($candidate, $actor);

        $admission = $this->createPending($tournament, $actor, $candidate, TournamentAdmissionDirectionEnum::APPLICATION, $roles, TournamentAdmissionSourceEnum::STANDARD);
        $ownerUserId = $tournament->createdByActor()->value('user_id');
        if ($ownerUserId !== null) {
            $this->notify((int) $ownerUserId, $tournament, 'Новая заявка на турнир', 'Поступила новая заявка на участие.', $admission, source: 'tournament.application.submitted');
        }

        return $admission;
    }

    public function respond(Tournament $tournament, TournamentAdmission $admission, Actor $actor, TournamentAdmissionStatusEnum $decision): TournamentAdmission
    {
        if (! in_array($decision, [TournamentAdmissionStatusEnum::ACCEPTED, TournamentAdmissionStatusEnum::DECLINED], true)) {
            throw new InvalidArgumentException('Недопустимый ответ на заявку.');
        }

        $updated = DB::transaction(function () use ($tournament, $admission, $actor, $decision): TournamentAdmission {
            $lockedTournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $locked = TournamentAdmission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToTournament($locked, $lockedTournament);
            if ($locked->status !== TournamentAdmissionStatusEnum::PENDING) {
                throw new InvalidArgumentException('На эту заявку уже ответили.');
            }

            if ($decision === TournamentAdmissionStatusEnum::ACCEPTED) {
                $this->assertTournamentAcceptsCandidates($lockedTournament);
            }

            if ($locked->direction === TournamentAdmissionDirectionEnum::APPLICATION) {
                $this->tournamentAccess->assertAllows($lockedTournament, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            } else {
                $this->assertCandidateMayAct($locked->team ?? $locked->user, $actor);
            }

            $locked->forceFill([
                'status' => $decision,
                'responded_by_actor_id' => $actor->id,
                'responded_at' => now(),
            ])->save();

            if ($decision === TournamentAdmissionStatusEnum::ACCEPTED) {
                $this->materializeEntryWhenReady($lockedTournament, $locked);
            }

            return $locked->refresh();
        });

        if ($updated->direction === TournamentAdmissionDirectionEnum::APPLICATION) {
            $this->markAdmissionNotificationsAsRead($updated, 'tournament.application.submitted');
            $this->notifyApplicationDecision($updated, $decision);
        } else {
            $this->markAdmissionNotificationsAsRead($updated, 'tournament.invitation.created');
        }

        return $updated;
    }

    public function revoke(Tournament $tournament, TournamentAdmission $admission, Actor $actor): void
    {
        DB::transaction(function () use ($tournament, $admission, $actor): void {
            $lockedTournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->tournamentAccess->assertAllows($lockedTournament, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            $locked = TournamentAdmission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToTournament($locked, $lockedTournament);
            if ($locked->status === TournamentAdmissionStatusEnum::ACCEPTED && $lockedTournament->participant_pool_locked_at !== null) {
                throw new InvalidArgumentException('Сначала возобновите набор или расформируйте команды, чтобы отозвать принятого участника.');
            }
            $entry = $locked->entry()->first();
            if ($entry !== null && ($entry->matchesAsA()->exists() || $entry->matchesAsB()->exists())) {
                throw new InvalidArgumentException('Нельзя отозвать участника, уже включённого в матчи.');
            }
            $entry?->delete();
            $locked->forceFill(['status' => TournamentAdmissionStatusEnum::REVOKED])->save();
        });
    }

    /** @param Collection<int, TournamentAdmissionRoleEnum>|null $roles */
    private function createPending(Tournament $tournament, Actor $actor, Team|User $candidate, TournamentAdmissionDirectionEnum $direction, ?Collection $roles = null, TournamentAdmissionSourceEnum $source = TournamentAdmissionSourceEnum::STANDARD): TournamentAdmission
    {
        return DB::transaction(function () use ($tournament, $actor, $candidate, $direction, $roles, $source): TournamentAdmission {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->assertTournamentAcceptsCandidates($locked);
            if ($direction === TournamentAdmissionDirectionEnum::INVITATION) {
                $this->tournamentAccess->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            }
            $this->assertCandidateMatchesMode($locked, $candidate);
            if ($direction === TournamentAdmissionDirectionEnum::APPLICATION
                && $candidate instanceof User
                && ($roles === null || $roles->isEmpty())) {
                throw new InvalidArgumentException('Выберите хотя бы одну роль для участия в турнире.');
            }
            if ($direction === TournamentAdmissionDirectionEnum::APPLICATION
                && $candidate instanceof User
                && $candidate->status !== UserStatusEnum::CONFIRMED
                && ! $locked->accepts_unconfirmed_participants) {
                throw new InvalidArgumentException('По условиям этого турнира для подачи заявки необходимо подтвердить аккаунт');
            }
            $candidateColumn = $candidate instanceof Team ? 'team_id' : 'user_id';
            $duplicate = $locked->admissions()->where($candidateColumn, $candidate->id)
                ->whereIn('status', [TournamentAdmissionStatusEnum::PENDING->value, TournamentAdmissionStatusEnum::ACCEPTED->value])
                ->exists();
            if ($duplicate) {
                throw new InvalidArgumentException('У этого кандидата уже есть активная заявка или допуск.');
            }

            return $locked->admissions()->create([
                'candidate_type' => $candidate instanceof Team ? TournamentAdmissionCandidateTypeEnum::TEAM : TournamentAdmissionCandidateTypeEnum::USER,
                'team_id' => $candidate instanceof Team ? $candidate->id : null,
                'user_id' => $candidate instanceof User ? $candidate->id : null,
                'direction' => $direction,
                'source' => $source,
                'roles' => $candidate instanceof User ? $roles : null,
                'status' => TournamentAdmissionStatusEnum::PENDING,
                'requested_by_actor_id' => $actor->id,
            ]);
        });
    }

    private function materializeEntryWhenReady(Tournament $tournament, TournamentAdmission $admission): void
    {
        if ($tournament->recruitment_mode === TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT
            && $tournament->format !== GameFormatEnum::STREETBALL_1X1) {
            return;
        }

        if ($admission->user_id !== null) {
            $entry = $tournament->entries()->create([
                'admission_id' => $admission->id,
                'source' => TournamentEntrySourceEnum::INDIVIDUAL,
                'name' => $admission->user->profile?->first_name ?: $admission->user->username ?: 'Участник #'.$admission->user_id,
                'status' => TournamentEntryStatusEnum::ACTIVE,
            ]);
            $entry->members()->create(['user_id' => $admission->user_id, 'position' => 0]);

            return;
        }

        $memberships = ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
            ->where('scope_id', $admission->team_id)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereJsonContains('sport_roles', TeamMemberTypeEnum::PLAYER->value)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->orderBy('id')->get();
        $minimum = $tournament->format?->sideSize();
        if ($minimum === null) {
            throw new InvalidArgumentException('Перед допуском команды укажите формат турнира.');
        }
        if ($memberships->count() < $minimum) {
            throw new InvalidArgumentException("В команде должно быть не меньше {$minimum} подтверждённых игроков.");
        }
        $tournament->entries()->create([
            'admission_id' => $admission->id,
            'source' => TournamentEntrySourceEnum::TEAM,
            'team_id' => $admission->team_id,
            'name' => $admission->team->name,
            'status' => TournamentEntryStatusEnum::ACTIVE,
        ]);
    }

    private function assertCandidateMayAct(Team|User $candidate, Actor $actor): void
    {
        if ($candidate instanceof User) {
            if ($actor->user_id !== $candidate->id || $candidate->status === UserStatusEnum::BLOCKED) {
                throw new InvalidArgumentException('Ответить может только сам приглашённый игрок.');
            }
        } elseif (! $this->teamAccess->allows($candidate, $actor, TeamPermissionEnum::MANAGE_TOURNAMENT_PARTICIPATION)) {
            throw new InvalidArgumentException('Нужно право представлять эту команду.');
        }
    }

    private function assertCandidateMatchesMode(Tournament $tournament, Team|User $candidate): void
    {
        $expectsTeam = $tournament->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS;
        if ($expectsTeam !== ($candidate instanceof Team)) {
            throw new InvalidArgumentException('Тип кандидата не соответствует режиму набора турнира.');
        }
        if ($candidate instanceof Team && ($candidate->status !== TeamStatusEnum::ACTIVE || $candidate->isTemporary())) {
            throw new InvalidArgumentException('Пригласить можно только активную постоянную команду.');
        }
    }

    private function assertTournamentAcceptsCandidates(Tournament $tournament): void
    {
        if (! $tournament->acceptsAdmissions()) {
            throw new InvalidArgumentException('Приём заявок и приглашений на этот турнир уже закрыт.');
        }
    }

    private function assertBelongsToTournament(TournamentAdmission $admission, Tournament $tournament): void
    {
        if ((int) $admission->tournament_id !== (int) $tournament->id) {
            throw new InvalidArgumentException('Заявка не относится к этому турниру.');
        }
    }

    /** @return Collection<int, int> */
    private function teamRepresentativeUserIds(Team $team): Collection
    {
        $creatorUserId = $team->createdByActor()->value('user_id');
        $contractRepresentatives = ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
            ->where('scope_id', $team->id)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permission', TeamPermissionEnum::MANAGE_TOURNAMENT_PARTICIPATION->value)))
            ->pluck('user_id');

        return $contractRepresentatives
            ->when($creatorUserId !== null, fn (Collection $ids) => $ids->push((int) $creatorUserId))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function notify(
        int $userId,
        Tournament $tournament,
        string $title,
        string $body,
        TournamentAdmission $admission,
        ?Team $team = null,
        ?string $source = null,
        ?TournamentAdmissionStatusEnum $admissionStatus = null,
    ): void {
        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $userId,
            type: UserNotificationTypeEnum::REMINDER,
            title: $title,
            body: $body.' «'.$tournament->title.'»',
            actionUrl: route('tournaments.show', $tournament->routeIdentifier(), false),
            actionText: $admission->direction === TournamentAdmissionDirectionEnum::INVITATION
                ? 'Ответить на приглашение'
                : 'Открыть заявку',
            payload: array_filter([
                'delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value,
                'source' => $source,
                'tournament_id' => $tournament->id,
                'tournament_admission_id' => $admission->id,
                'tournament_admission_status' => $admissionStatus?->value,
                'team_id' => $team?->id,
            ], static fn ($value): bool => $value !== null),
        ));
    }

    private function markAdmissionNotificationsAsRead(TournamentAdmission $admission, string $source): void
    {
        $notifications = UserNotification::query()
            ->where('status', UserNotificationStatusEnum::NEW)
            ->where('payload->source', $source)
            ->where('payload->tournament_admission_id', $admission->id)
            ->get(['id', 'user_id']);

        if ($notifications->isEmpty()) {
            return;
        }

        UserNotification::query()
            ->whereKey($notifications->pluck('id'))
            ->where('status', UserNotificationStatusEnum::NEW)
            ->update([
                'status' => UserNotificationStatusEnum::READ,
                'read_at' => now(),
            ]);

        $notifications->pluck('user_id')->unique()
            ->each(fn ($userId) => $this->notificationCounters->forget((int) $userId));
    }

    private function notifyApplicationDecision(TournamentAdmission $admission, TournamentAdmissionStatusEnum $decision): void
    {
        if ($admission->user_id === null) {
            return;
        }

        [$title, $body, $source] = $decision === TournamentAdmissionStatusEnum::ACCEPTED
            ? ['Заявка на турнир принята', 'Ваша заявка на участие принята.', 'tournament.application.accepted']
            : ['Заявка на турнир отклонена', 'Ваша заявка на участие отклонена.', 'tournament.application.declined'];

        $this->notify(
            $admission->user_id,
            $admission->tournament,
            $title,
            $body,
            $admission,
            source: $source,
            admissionStatus: $decision,
        );
    }
}
