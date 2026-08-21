<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GameAdmissionService
{
    public function __construct(
        private readonly EventManagementAccess $eventAccess,
        private readonly TeamManagementAccess $teamAccess,
    ) {}

    public function apply(Game $game, Actor $actor, Team|User $candidate): GameAdmission
    {
        return $this->createPending($game, $actor, $candidate, GameAdmissionDirectionEnum::APPLICATION);
    }

    public function invite(Game $game, Actor $actor, Team|User $candidate): GameAdmission
    {
        return $this->createPending($game, $actor, $candidate, GameAdmissionDirectionEnum::INVITATION);
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

        return DB::transaction(function () use ($game, $admission, $actor, $decision, $responseComment): GameAdmission {
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
            }

            $candidate = $this->lockCandidate($candidate);
            if ($direction === GameAdmissionDirectionEnum::APPLICATION) {
                // Authorization is checked again after locking the candidate so a concurrent
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

    private function normalizeComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : mb_substr($comment, 0, 2000);
    }
}
