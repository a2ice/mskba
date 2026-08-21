<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GameAdmissionService
{
    public function __construct(
        private readonly EventManagementAccess $eventAccess,
        private readonly TeamManagementAccess $teamAccess,
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    public function apply(Game $game, Actor $actor, Team|User $candidate): GameAdmission
    {
        $admission = $this->createPending($game, $actor, $candidate, GameAdmissionDirectionEnum::APPLICATION);
        $ownerUserId = $game->event()->first()?->organizerActor()->value('user_id');
        if ($ownerUserId !== null) {
            $this->notify(
                (int) $ownerUserId,
                $game,
                'Новая заявка на игру',
                $candidate instanceof Team
                    ? 'Команда «'.$candidate->name.'» подала заявку на участие.'
                    : 'Игрок подал заявку на участие в игре.',
            );
        }

        return $admission;
    }

    public function invite(Game $game, Actor $actor, Team|User $candidate): GameAdmission
    {
        $admission = $this->createPending($game, $actor, $candidate, GameAdmissionDirectionEnum::INVITATION);
        if ($candidate instanceof User) {
            $this->notify($candidate->canonical()->id, $game, 'Приглашение на игру', 'Вас пригласили принять участие в игре.');
        } else {
            foreach ($this->teamRepresentativeUserIds($candidate) as $userId) {
                $this->notify(
                    $userId,
                    $game,
                    'Приглашение команды на игру',
                    'Команду «'.$candidate->name.'» пригласили принять участие в игре.',
                );
            }
        }

        return $admission;
    }

    public function respond(
        Game $game,
        GameAdmission $admission,
        Actor $actor,
        GameAdmissionStatusEnum $decision,
        ?string $responseComment = null,
    ): GameAdmission {
        if (! in_array($decision, [GameAdmissionStatusEnum::ACCEPTED, GameAdmissionStatusEnum::DECLINED], true)) {
            throw new InvalidArgumentException('Недопустимый ответ на заявку.');
        }

        $updated = DB::transaction(function () use ($game, $admission, $actor, $decision, $responseComment): GameAdmission {
            $event = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->assertStandaloneOpen($event, $lockedGame);

            $lockedAdmission = GameAdmission::query()
                ->whereKey($admission->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertBelongsToGame($lockedAdmission, $lockedGame);

            if ($lockedAdmission->status !== GameAdmissionStatusEnum::PENDING) {
                throw new InvalidArgumentException('На эту заявку уже ответили.');
            }
            if ($lockedAdmission->direction === GameAdmissionDirectionEnum::SELECTION) {
                throw new InvalidArgumentException('Предварительный выбор организатора не требует ответа.');
            }

            if ($lockedAdmission->direction === GameAdmissionDirectionEnum::APPLICATION) {
                $this->eventAccess->assertAllows(
                    $event,
                    $actor,
                    EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS,
                );
            } else {
                $this->assertCandidateMayAct($this->lockAdmissionCandidate($lockedAdmission), $actor);
            }

            $lockedAdmission->forceFill([
                'status' => $decision,
                'responded_by_actor_id' => $actor->id,
                'responded_at' => now(),
                'response_comment' => $decision === GameAdmissionStatusEnum::DECLINED
                    ? $this->normalizeComment($responseComment)
                    : null,
            ])->save();

            return $lockedAdmission->refresh();
        }, 3);

        if ($updated->direction === GameAdmissionDirectionEnum::APPLICATION) {
            $candidate = $updated->team ?? $updated->user;
            if ($candidate instanceof User) {
                $this->notify(
                    $candidate->canonical()->id,
                    $game,
                    $decision === GameAdmissionStatusEnum::ACCEPTED ? 'Заявка на игру принята' : 'Заявка на игру отклонена',
                    $decision === GameAdmissionStatusEnum::ACCEPTED
                        ? 'Организатор принял вашу заявку на участие.'
                        : 'Организатор отклонил вашу заявку на участие.',
                );
            } elseif ($candidate instanceof Team) {
                foreach ($this->teamRepresentativeUserIds($candidate) as $userId) {
                    $this->notify(
                        $userId,
                        $game,
                        $decision === GameAdmissionStatusEnum::ACCEPTED ? 'Заявка команды принята' : 'Заявка команды отклонена',
                        $decision === GameAdmissionStatusEnum::ACCEPTED
                            ? 'Заявка команды «'.$candidate->name.'» на игру принята.'
                            : 'Заявка команды «'.$candidate->name.'» на игру отклонена.',
                    );
                }
            }
        }

        return $updated;
    }

    public function revoke(Game $game, GameAdmission $admission, Actor $actor): void
    {
        DB::transaction(function () use ($game, $admission, $actor): void {
            $event = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->assertStandaloneOpen($event, $lockedGame);

            $lockedAdmission = GameAdmission::query()
                ->whereKey($admission->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertBelongsToGame($lockedAdmission, $lockedGame);

            if (! $lockedAdmission->status->isActive()) {
                throw new InvalidArgumentException('Эта заявка уже не активна.');
            }

            $organizerMayAct = $this->eventAccess->allows(
                $event,
                $actor,
                EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS,
            );
            $candidateMayAct = $this->candidateMayAct(
                $this->lockAdmissionCandidate($lockedAdmission),
                $actor,
            );
            if (! $organizerMayAct && ! $candidateMayAct) {
                throw new InvalidArgumentException('Недостаточно прав для отзыва этой заявки.');
            }

            $lockedAdmission->forceFill([
                'status' => GameAdmissionStatusEnum::REVOKED,
                'responded_by_actor_id' => $actor->id,
                'responded_at' => now(),
            ])->save();
        }, 3);
    }

    private function createPending(
        Game $game,
        Actor $actor,
        Team|User $candidate,
        GameAdmissionDirectionEnum $direction,
    ): GameAdmission {
        return DB::transaction(function () use ($game, $actor, $candidate, $direction): GameAdmission {
            $event = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->assertStandaloneOpen($event, $lockedGame);

            if ($direction === GameAdmissionDirectionEnum::INVITATION) {
                $this->eventAccess->assertAllows(
                    $event,
                    $actor,
                    EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS,
                );
            } else {
                if ($event->status !== EventStatusEnum::PUBLISHED
                    || $event->visibility !== EventVisibilityEnum::PUBLIC
                    || $event->ends_at?->isPast()) {
                    throw new InvalidArgumentException('Заявки принимаются только на опубликованную публичную игру до её окончания.');
                }
                if (! $lockedGame->acceptsAdmissions()) {
                    throw new InvalidArgumentException('Приём новых заявок на эту игру выключен.');
                }
            }

            $candidate = $this->lockCandidate($candidate);
            if ($direction === GameAdmissionDirectionEnum::APPLICATION) {
                // Authorization is checked after locking the candidate so a concurrent
                // membership/permission change cannot turn a stale pre-check into an admission.
                $this->assertCandidateMayAct($candidate, $actor);
            }
            $this->assertCandidateMatchesMode($lockedGame, $candidate);
            $this->assertNoActiveDuplicate($lockedGame, $candidate);

            return $lockedGame->admissions()->create([
                'candidate_type' => $candidate instanceof Team
                    ? GameAdmissionCandidateTypeEnum::TEAM
                    : GameAdmissionCandidateTypeEnum::USER,
                'team_id' => $candidate instanceof Team ? $candidate->id : null,
                'user_id' => $candidate instanceof User ? $candidate->id : null,
                'direction' => $direction,
                'status' => GameAdmissionStatusEnum::PENDING,
                'requested_by_actor_id' => $actor->id,
            ]);
        }, 3);
    }

    private function lockCandidate(Team|User $candidate): Team|User
    {
        if ($candidate instanceof Team) {
            return Team::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        }

        $canonical = $candidate->canonical();

        return User::query()->whereKey($canonical->id)->lockForUpdate()->firstOrFail();
    }

    private function lockAdmissionCandidate(GameAdmission $admission): Team|User
    {
        if ($admission->candidate_type === GameAdmissionCandidateTypeEnum::TEAM && $admission->team_id !== null) {
            return Team::query()->whereKey($admission->team_id)->lockForUpdate()->firstOrFail();
        }
        if ($admission->candidate_type === GameAdmissionCandidateTypeEnum::USER && $admission->user_id !== null) {
            $user = User::query()->whereKey($admission->user_id)->firstOrFail()->canonical();

            return User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        }

        throw new InvalidArgumentException('У заявки отсутствует кандидат.');
    }

    private function assertCandidateMatchesMode(Game $game, Team|User $candidate): void
    {
        $expectsTeam = $game->recruitment_mode === GameRecruitmentModeEnum::PREFORMED_TEAMS;
        if ($expectsTeam !== ($candidate instanceof Team)) {
            throw new InvalidArgumentException('Тип кандидата не соответствует режиму набора игры.');
        }

        if ($candidate instanceof Team) {
            if ($candidate->status !== TeamStatusEnum::ACTIVE || $candidate->isTemporary()) {
                throw new InvalidArgumentException('Участвовать может только активная постоянная команда.');
            }

            return;
        }

        if ($candidate->status === UserStatusEnum::BLOCKED || $candidate->trashed()) {
            throw new InvalidArgumentException('Этот пользователь не может участвовать в игре.');
        }
    }

    private function assertNoActiveDuplicate(Game $game, Team|User $candidate): void
    {
        $query = $game->admissions()
            ->whereIn('status', [
                GameAdmissionStatusEnum::PENDING->value,
                GameAdmissionStatusEnum::ACCEPTED->value,
            ]);

        if ($candidate instanceof Team) {
            $query->where('team_id', $candidate->id);
        } else {
            $query->whereIn('user_id', $candidate->identityIds());
        }

        if ($query->exists()) {
            throw new InvalidArgumentException('У этого кандидата уже есть активная заявка или приглашение.');
        }
    }

    private function assertCandidateMayAct(Team|User|null $candidate, Actor $actor): void
    {
        if (! $this->candidateMayAct($candidate, $actor)) {
            throw new InvalidArgumentException('Недостаточно прав, чтобы представлять этого участника.');
        }
    }

    private function candidateMayAct(Team|User|null $candidate, Actor $actor): bool
    {
        if ($candidate instanceof Team) {
            return $this->teamAccess->allows(
                $candidate,
                $actor,
                TeamPermissionEnum::MANAGE_GAME_PARTICIPATION,
            );
        }

        if ($candidate instanceof User) {
            $canonical = $candidate->canonical();
            $actorUser = $actor->user?->canonical();

            return $actorUser !== null
                && $actorUser->id === $canonical->id
                && $canonical->status !== UserStatusEnum::BLOCKED
                && ! $canonical->trashed();
        }

        return false;
    }

    private function assertStandaloneOpen(Event $event, Game $game): void
    {
        if ($event->type !== EventTypeEnum::GAME || (int) $event->primary_game_id !== (int) $game->id) {
            throw new InvalidArgumentException('Заявки доступны только для основной самостоятельной игры.');
        }
        if ($game->status !== GameStatusEnum::SCHEDULED || $game->actual_started_at !== null) {
            throw new InvalidArgumentException('После начала игры набор участников закрыт.');
        }
        if ($game->sides_confirmed_at !== null) {
            throw new InvalidArgumentException('Стороны уже утверждены. Сначала снимите утверждение.');
        }
        if ($game->recruitment_mode === null) {
            throw new InvalidArgumentException('Для этой игры режим набора не настроен.');
        }
    }

    private function assertBelongsToGame(GameAdmission $admission, Game $game): void
    {
        if ((int) $admission->game_id !== (int) $game->id) {
            throw new InvalidArgumentException('Заявка не относится к этой игре.');
        }
    }

    /** @return Collection<int, int> */
    private function teamRepresentativeUserIds(Team $team): Collection
    {
        $creatorUserId = $team->createdByActor()->value('user_id');
        $delegates = ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
            ->where('scope_id', $team->id)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permission', TeamPermissionEnum::MANAGE_GAME_PARTICIPATION->value)))
            ->pluck('user_id');

        return $delegates
            ->when($creatorUserId !== null, fn (Collection $ids) => $ids->push((int) $creatorUserId))
            ->map(function ($id): int {
                $user = User::query()->find((int) $id);

                return $user === null ? (int) $id : (int) $user->canonical()->id;
            })
            ->unique()
            ->values();
    }

    private function notify(int $userId, Game $game, string $title, string $body): void
    {
        $event = $game->event()->first();
        if ($event === null) {
            return;
        }
        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $userId,
            type: UserNotificationTypeEnum::SYSTEM,
            title: $title,
            body: $body,
            actionUrl: route('events.show', $event->routeIdentifier()),
            actionText: 'Открыть игру',
            payload: ['game_id' => $game->id, 'event_id' => $event->id, 'source' => 'game.recruitment'],
        ));
    }

    private function normalizeComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : mb_substr($comment, 0, 2000);
    }
}
