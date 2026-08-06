<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Events\GameStatisticsConfirmed;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;
use App\Modules\Event\Domain\Models\GameSide;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class GameManagementService
{
    public function __construct(
        private readonly EventManagementAccess $access,
    ) {}

    public function initializeStandalone(
        Event $event,
        int $teamAId,
        int $teamBId,
        int $sideASize = 5,
        int $sideBSize = 5,
        GameScoringTypeEnum $scoringType = GameScoringTypeEnum::STREETBALL,
    ): void {
        if ($event->type !== EventTypeEnum::GAME) {
            throw new InvalidArgumentException('Составы команд доступны только для самостоятельной игры.');
        }
        $this->assertSideSizeLimits($sideASize, $sideBSize);

        if ($teamAId === $teamBId) {
            throw new InvalidArgumentException('Для игры нужны две разные команды.');
        }

        $teams = Team::query()
            ->whereIn('id', [$teamAId, $teamBId])
            ->whereNull('temporary_for_event_id')
            ->where('status', TeamStatusEnum::ACTIVE->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($teams->count() !== 2) {
            throw new InvalidArgumentException('Обе команды должны быть активными постоянными командами.');
        }

        $game = Game::query()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'status' => GameStatusEnum::SCHEDULED,
            'side_a_size' => $sideASize,
            'side_b_size' => $sideBSize,
            'scoring_type' => $scoringType,
            'scheduled_starts_at' => $event->starts_at,
            'scheduled_ends_at' => $event->ends_at,
        ]);

        $sideA = $game->sides()->create([
            'team_id' => $teamAId,
            'slot' => 'A',
            'display_name' => $teams[$teamAId]->name,
        ]);
        $sideB = $game->sides()->create([
            'team_id' => $teamBId,
            'slot' => 'B',
            'display_name' => $teams[$teamBId]->name,
        ]);

        $sideAUsers = $this->snapshotTeamRoster($game, $sideA, $teams[$teamAId]);
        $this->snapshotTeamRoster($game, $sideB, $teams[$teamBId], $sideAUsers);
    }

    /**
     * @param  array<int, int>  $sideAUserIds
     * @param  array<int, int>  $sideBUserIds
     */
    public function createMiniGame(
        Event $parent,
        Actor $actor,
        string $title,
        ?string $startsAt,
        ?string $endsAt,
        string $sideAName,
        string $sideBName,
        array $sideAUserIds,
        array $sideBUserIds,
        int $sideASize = 5,
        int $sideBSize = 5,
        GameScoringTypeEnum $scoringType = GameScoringTypeEnum::STREETBALL,
    ): Game {
        if (! in_array($parent->type, [EventTypeEnum::TRAINING, EventTypeEnum::GAME_TRAINING], true)) {
            throw new InvalidArgumentException('Мини-игры можно создавать только внутри тренировки.');
        }

        $game = DB::transaction(function () use (
            $parent,
            $actor,
            $title,
            $startsAt,
            $endsAt,
            $sideAName,
            $sideBName,
            $sideAUserIds,
            $sideBUserIds,
            $sideASize,
            $sideBSize,
            $scoringType,
        ): Game {
            $lockedParent = Event::query()->lockForUpdate()->findOrFail($parent->id);
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::CREATE_MINI_GAME);
            if ($lockedParent->status !== EventStatusEnum::PUBLISHED
                || $lockedParent->ends_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('После закрытия мероприятия создавать мини-игры нельзя.');
            }
            [$start, $end, $isTimeScheduled] = $this->resolveMiniGamePeriod($lockedParent, $startsAt, $endsAt);
            [$sideAName, $sideBName] = $this->normalizeSideNames($sideAName, $sideBName);

            if ($isTimeScheduled && ($start->lessThan($lockedParent->starts_at)
                || $end->greaterThan($lockedParent->ends_at))) {
                throw new InvalidArgumentException('Мини-игра должна целиком входить во время тренировки.');
            }

            $confirmedParticipants = $lockedParent->participants()
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->where('confirmation_version', $lockedParent->participation_confirmation_version)
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');
            $this->assertMiniGameFormat(
                $sideASize,
                $sideBSize,
                $confirmedParticipants->count(),
            );
            $this->assertRosterSizes($sideAUserIds, $sideBUserIds, $sideASize, $sideBSize);
            $allUserIds = collect([...$sideAUserIds, ...$sideBUserIds])->map(fn ($id) => (int) $id);
            if ($allUserIds->duplicates()->isNotEmpty()
                || $allUserIds->diff($confirmedParticipants->keys())->isNotEmpty()) {
                throw new InvalidArgumentException('В состав можно включить только подтверждённых участников тренировки, по одному разу.');
            }

            $game = Game::query()->create([
                'event_id' => $lockedParent->id,
                'created_by_actor_id' => $actor->id,
                'title' => $title,
                'status' => GameStatusEnum::SCHEDULED,
                'side_a_size' => $sideASize,
                'side_b_size' => $sideBSize,
                'scoring_type' => $scoringType,
                'scheduled_starts_at' => $isTimeScheduled ? $start : null,
                'scheduled_ends_at' => $isTimeScheduled ? $end : null,
            ]);

            $teamA = $this->createTemporaryTeam($lockedParent, $game, $actor, $sideAName);
            $teamB = $this->createTemporaryTeam($lockedParent, $game, $actor, $sideBName);
            $sideA = $game->sides()->create([
                'team_id' => $teamA->id,
                'slot' => 'A',
                'display_name' => $teamA->name,
            ]);
            $sideB = $game->sides()->create([
                'team_id' => $teamB->id,
                'slot' => 'B',
                'display_name' => $teamB->name,
            ]);

            $this->snapshotGameParticipantRoster($game, $sideA, $sideAUserIds, $confirmedParticipants);
            $this->snapshotGameParticipantRoster($game, $sideB, $sideBUserIds, $confirmedParticipants);

            return $game->load(['sides', 'rosterEntries.user.profile']);
        });

        event(new EventChanged($parent->id));

        return $game;
    }

    public function updateMiniGame(
        Game $game,
        Actor $actor,
        string $title,
        ?string $startsAt,
        ?string $endsAt,
        string $sideAName,
        string $sideBName,
        int $sideASize,
        int $sideBSize,
        GameScoringTypeEnum $scoringType,
    ): void {
        DB::transaction(function () use (
            $game,
            $actor,
            $title,
            $startsAt,
            $endsAt,
            $sideAName,
            $sideBName,
            $sideASize,
            $sideBSize,
            $scoringType,
        ): void {
            $lockedParent = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::UPDATE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            if ($lockedGame->event_id !== $lockedParent->id) {
                throw new InvalidArgumentException('Связь мини-игры с тренировкой была изменена.');
            }
            $this->assertGameIsEditable($lockedGame);
            if ($lockedGame->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Мини-игру с подтверждённой статистикой изменять нельзя.');
            }

            [$start, $end, $isTimeScheduled] = $this->resolveMiniGamePeriod($lockedParent, $startsAt, $endsAt);
            [$sideAName, $sideBName] = $this->normalizeSideNames($sideAName, $sideBName);
            $confirmedParticipantsCount = $lockedParent->participants()
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->where('confirmation_version', $lockedParent->participation_confirmation_version)
                ->count();
            $this->assertMiniGameFormat($sideASize, $sideBSize, $confirmedParticipantsCount);
            if ($isTimeScheduled
                && ($start->lessThan($lockedParent->starts_at) || $end->greaterThan($lockedParent->ends_at))) {
                throw new InvalidArgumentException('Мини-игра должна целиком входить во время тренировки.');
            }

            $selectedBySide = $lockedGame->rosterEntries()
                ->selectRaw('game_side_id, count(*) as aggregate')
                ->where('status', GameRosterStatusEnum::SELECTED->value)
                ->groupBy('game_side_id')
                ->pluck('aggregate', 'game_side_id');
            $sides = $lockedGame->sides()->orderBy('slot')->lockForUpdate()->get()->keyBy('slot');
            if ((int) ($selectedBySide[$sides['A']->id] ?? 0) > $sideASize
                || (int) ($selectedBySide[$sides['B']->id] ?? 0) > $sideBSize) {
                throw new InvalidArgumentException('Новый лимит меньше уже выбранного состава.');
            }

            $lockedGame->update([
                'title' => $title,
                'side_a_size' => $sideASize,
                'side_b_size' => $sideBSize,
                'scoring_type' => $scoringType,
                'scheduled_starts_at' => $isTimeScheduled ? $start : null,
                'scheduled_ends_at' => $isTimeScheduled ? $end : null,
            ]);
            foreach (['A' => $sideAName, 'B' => $sideBName] as $slot => $name) {
                $side = $sides[$slot];
                $side->update(['display_name' => $name]);
                $side->team()->update(['name' => $name]);
            }
        });

        event(new EventChanged($game->event_id));
    }

    public function deleteMiniGame(Game $game, Actor $actor): void
    {
        $parentEventId = (int) $game->event_id;

        DB::transaction(function () use ($game, $actor): void {
            $lockedParent = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::DELETE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            if ($lockedGame->event_id !== $lockedParent->id) {
                throw new InvalidArgumentException('Связь мини-игры с тренировкой была изменена.');
            }
            if ($lockedGame->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Мини-игру с подтверждённой статистикой удалить нельзя.');
            }
            $temporaryTeamIds = $lockedGame->sides()
                ->whereHas('team', fn ($query) => $query->where('temporary_for_event_id', $lockedParent->id))
                ->pluck('team_id')
                ->filter();
            $lockedGame->forceDelete();
            Team::query()->whereKey($temporaryTeamIds)->delete();
        });

        event(new EventChanged($parentEventId));
    }

    public function cancelMiniGame(Game $game, Actor $actor): void
    {
        $parentEventId = (int) $game->event_id;

        DB::transaction(function () use ($game, $actor): void {
            $lockedParent = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->status === GameStatusEnum::CANCELLED) {
                return;
            }
            if ($lockedGame->status === GameStatusEnum::COMPLETED
                || $lockedGame->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Завершённую игру отменить нельзя.');
            }
            if ($this->hasRecordedGameData($lockedGame)) {
                throw new InvalidArgumentException('В игре уже есть счёт или статистика. Сначала проверьте данные и завершите игру.');
            }

            $lockedGame->forceFill([
                'status' => GameStatusEnum::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_actor_id' => $actor->id,
                'cancellation_reason' => 'Игра не состоялась.',
            ])->save();
        });

        event(new EventChanged($parentEventId));
    }

    /**
     * @param  array<int, int>  $sideAUserIds
     * @param  array<int, int>  $sideBUserIds
     */
    public function replaceRoster(Game $game, Actor $actor, array $sideAUserIds, array $sideBUserIds): void
    {
        DB::transaction(function () use ($game, $actor, $sideAUserIds, $sideBUserIds): void {
            $lockedParent = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $this->assertGameIsEditable($lockedGame);
            if ($lockedGame->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Состав игры с подтверждённой статистикой изменять нельзя.');
            }
            $sides = $lockedGame->sides()->orderBy('slot')->lockForUpdate()->get()->keyBy('slot');
            if ($sides->count() !== 2) {
                throw new InvalidArgumentException('Для игры не настроены две стороны.');
            }
            $this->assertRosterSizes(
                $sideAUserIds,
                $sideBUserIds,
                (int) $lockedGame->side_a_size,
                (int) $lockedGame->side_b_size,
            );

            $allIds = collect([...$sideAUserIds, ...$sideBUserIds])->map(fn ($id) => (int) $id);
            if ($allIds->duplicates()->isNotEmpty()) {
                throw new InvalidArgumentException('Игрок не может входить сразу в обе стороны.');
            }

            $candidates = $this->rosterCandidates($lockedGame);
            if ($allIds->diff($candidates->keys())->isNotEmpty()) {
                throw new InvalidArgumentException('Один или несколько игроков недоступны для этой игры.');
            }
            if ($lockedParent->type === EventTypeEnum::GAME) {
                foreach (['A' => $sideAUserIds, 'B' => $sideBUserIds] as $slot => $userIds) {
                    $invalid = collect($userIds)->map(fn ($id) => (int) $id)
                        ->filter(fn ($id) => ($candidates[$id]['slot'] ?? null) !== $slot);
                    if ($invalid->isNotEmpty()) {
                        throw new InvalidArgumentException('Игрок постоянной команды не может быть перенесён в состав соперника.');
                    }
                }
            }

            // Статистика относится к снимку состава. При изменении состава черновую
            // статистику нужно очистить, иначе старые строки попадут в подтверждение.
            $lockedGame->playerStatistics()->delete();
            $lockedGame->rosterEntries()->delete();
            $lockedGame->update([
                'statistics_status' => GameStatisticsStatusEnum::NOT_STARTED,
                'statistics_version' => $lockedGame->statistics_version + 1,
            ]);
            foreach (['A' => $sideAUserIds, 'B' => $sideBUserIds] as $slot => $userIds) {
                foreach ($userIds as $userId) {
                    $source = $candidates[(int) $userId];
                    $lockedGame->rosterEntries()->create([
                        'game_side_id' => $sides[$slot]->id,
                        'user_id' => (int) $userId,
                        'source_contract_membership_id' => $source['membership_id'] ?? null,
                        'source_event_participant_id' => $source['participant_id'] ?? null,
                        'status' => GameRosterStatusEnum::SELECTED,
                    ]);
                }
            }
        });

        event(new EventChanged($this->aggregateEventId($game)));
    }

    /**
     * @param  array<string, mixed>  $statistics
     */
    public function saveStatistics(Game $game, Actor $actor, array $statistics): void
    {
        DB::transaction(function () use ($game, $actor, $statistics): void {
            $lockedParent = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $this->assertGameIsLive($lockedGame);
            $this->persistStatistics($lockedGame, $statistics);
        });

        event(new EventChanged($this->aggregateEventId($game)));
    }

    /** @param array{A:int, B:int} $scores */
    public function saveScore(Game $game, Actor $actor, array $scores): void
    {
        DB::transaction(function () use ($game, $actor, $scores): void {
            $lockedParent = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $this->assertGameIsLive($lockedGame);
            $this->assertGameIsEditable($lockedGame);
            if ($lockedGame->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Счёт подтверждённой игры изменять нельзя.');
            }
            $sides = $lockedGame->sides()->lockForUpdate()->get()->keyBy('slot');
            if (! $sides->has('A') || ! $sides->has('B')) {
                throw new InvalidArgumentException('Для игры не настроены две стороны.');
            }
            foreach (['A', 'B'] as $slot) {
                $sides[$slot]->update(['score' => (int) $scores[$slot]]);
            }
        });

        event(new EventChanged($this->aggregateEventId($game)));
    }

    public function confirmStatistics(Game $game, Actor $actor): void
    {
        DB::transaction(function () use ($game, $actor): void {
            $lockedParent = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $this->assertGameHasEnded($lockedGame);

            $this->confirmLockedStatistics($lockedGame, $actor);
        });

        event(new GameStatisticsConfirmed($game->id));
        event(new EventChanged($this->aggregateEventId($game)));
    }

    /**
     * Сохраняет текущие значения формы и завершает игру в одной транзакции.
     *
     * @param  array<string, mixed>  $statistics
     */
    public function saveAndCompleteStatistics(Game $game, Actor $actor, array $statistics): void
    {
        DB::transaction(function () use ($game, $actor, $statistics): void {
            // Для дочерних игр сохраняем единый порядок блокировок: сначала
            // мероприятие-агрегат, затем мини-игра. Это снижает риск deadlock
            // при одновременной синхронизации мероприятия и его игр.
            $lockedParent = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
            $this->access->assertAllows($lockedParent, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $this->assertGameHasEnded($lockedGame);

            if ($lockedGame->scheduled_starts_at?->isFuture()) {
                throw new InvalidArgumentException('Завершить игру можно только после её начала.');
            }

            $this->persistStatistics($lockedGame, $statistics);
            $this->confirmLockedStatistics($lockedGame->fresh(), $actor);
            $lockedGame->update([
                'status' => GameStatusEnum::COMPLETED,
                'completed_at' => now(),
                'completed_by_actor_id' => $actor->id,
            ]);
        });

        event(new GameStatisticsConfirmed($game->id));
        event(new EventChanged($this->aggregateEventId($game)));
    }

    /**
     * @param  array<string, mixed>  $statistics
     */
    private function persistStatistics(Game $game, array $statistics): void
    {
        $this->assertGameIsEditable($game);
        if ($game->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
            throw new InvalidArgumentException('Подтверждённую статистику изменять нельзя.');
        }

        $sides = $game->sides()->lockForUpdate()->get()->keyBy('slot');
        if (! $sides->has('A') || ! $sides->has('B')) {
            throw new InvalidArgumentException('Для игры не настроены две стороны.');
        }
        foreach (['A', 'B'] as $slot) {
            $score = $statistics['scores'][$slot] ?? null;
            $sides[$slot]->update(['score' => $score === null ? null : (int) $score]);
        }

        $roster = $game->rosterEntries()->get()->keyBy('user_id');
        foreach (($statistics['players'] ?? []) as $userId => $values) {
            $entry = $roster->get((int) $userId);
            if ($entry === null) {
                throw new InvalidArgumentException('Статистика передана для игрока вне состава.');
            }
            $normalized = [];
            foreach (GamePlayerStatistic::COUNTING_FIELDS as $field) {
                $normalized[$field] = max(0, (int) ($values[$field] ?? 0));
            }
            foreach (['close', 'mid', 'three', 'free_throw'] as $range) {
                if ($normalized[$range.'_made'] > $normalized[$range.'_attempted']) {
                    throw new InvalidArgumentException('Попаданий не может быть больше попыток.');
                }
            }
            $game->playerStatistics()->updateOrCreate(
                ['user_id' => (int) $userId],
                [
                    ...$normalized,
                    'game_side_id' => $entry->game_side_id,
                ],
            );
        }

        $game->update([
            'statistics_status' => GameStatisticsStatusEnum::READY,
            'statistics_version' => $game->statistics_version + 1,
        ]);
    }

    private function confirmLockedStatistics(Game $game, Actor $actor): void
    {
        if ($game->statistics_status !== GameStatisticsStatusEnum::READY) {
            throw new InvalidArgumentException('Сначала сохраните готовую статистику.');
        }
        if ($game->sides()->whereNull('score')->exists()) {
            throw new InvalidArgumentException('Укажите итоговый счёт обеих сторон.');
        }

        $selectedPlayers = $game->rosterEntries()
            ->where('status', GameRosterStatusEnum::SELECTED->value)
            ->count();
        $statisticPlayers = $game->playerStatistics()->count();
        if ($selectedPlayers === 0 || $statisticPlayers !== $selectedPlayers) {
            throw new InvalidArgumentException('Сохраните статистику для каждого игрока выбранного состава.');
        }
        $selectedBySide = $game->rosterEntries()
            ->selectRaw('game_side_id, count(*) as aggregate')
            ->where('status', GameRosterStatusEnum::SELECTED->value)
            ->groupBy('game_side_id')
            ->pluck('aggregate', 'game_side_id');
        $sides = $game->sides()->get()->keyBy('slot');
        if ($sides->count() !== 2
            || (int) ($selectedBySide[$sides['A']->id] ?? 0) < 1
            || (int) ($selectedBySide[$sides['B']->id] ?? 0) < 1
            || (int) ($selectedBySide[$sides['A']->id] ?? 0) > $game->side_a_size
            || (int) ($selectedBySide[$sides['B']->id] ?? 0) > $game->side_b_size) {
            throw new InvalidArgumentException('Проверьте составы: на каждой стороне должен быть хотя бы один игрок и не больше указанного формата.');
        }

        $game->update([
            'statistics_status' => GameStatisticsStatusEnum::CONFIRMED,
            'statistics_confirmed_at' => now(),
            'statistics_confirmed_by_actor_id' => $actor->id,
        ]);
        $game->rosterEntries()
            ->where('status', GameRosterStatusEnum::SELECTED->value)
            ->update(['status' => GameRosterStatusEnum::PLAYED->value]);

    }

    private function hasRecordedGameData(Game $game): bool
    {
        return $game->statistics_status !== GameStatisticsStatusEnum::NOT_STARTED
            || $game->playerStatistics()->exists()
            || $game->sides()->where('score', '>', 0)->exists();
    }

    private function assertGameIsEditable(Game $game): void
    {
        if ($game->status === GameStatusEnum::CANCELLED) {
            throw new InvalidArgumentException('Отменённую игру изменять нельзя.');
        }
        if ($game->status === GameStatusEnum::COMPLETED
            || $game->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
            throw new InvalidArgumentException('Завершённую игру изменять нельзя.');
        }
    }

    private function assertGameIsLive(Game $game): void
    {
        if ($game->actual_started_at === null) {
            throw new InvalidArgumentException('Сначала необходимо начать игру.');
        }
        if ($game->actual_ended_at !== null) {
            throw new InvalidArgumentException('Игра уже закончена. Оперативный ввод закрыт.');
        }
    }

    private function assertGameHasEnded(Game $game): void
    {
        if ($game->actual_ended_at === null) {
            throw new InvalidArgumentException('Сначала необходимо закончить фактическое проведение игры.');
        }
    }

    /**
     * @param  Collection<int, int>|null  $excludedUserIds
     * @return Collection<int, int>
     */
    private function snapshotTeamRoster(
        Game $game,
        GameSide $side,
        Team $team,
        ?Collection $excludedUserIds = null,
    ): Collection {
        $memberships = $team->memberships()
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->get();
        $selected = collect();
        foreach ($memberships as $membership) {
            if ($excludedUserIds?->contains($membership->user_id)) {
                continue;
            }
            $game->rosterEntries()->create([
                'game_side_id' => $side->id,
                'user_id' => $membership->user_id,
                'source_contract_membership_id' => $membership->id,
                'status' => GameRosterStatusEnum::SELECTED,
            ]);
            $selected->push($membership->user_id);
        }

        return $selected;
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  Collection<int, mixed>  $participants
     */
    private function snapshotGameParticipantRoster(
        Game $game,
        GameSide $side,
        array $userIds,
        Collection $participants,
    ): void {
        foreach ($userIds as $userId) {
            $participant = $participants[(int) $userId];
            $game->rosterEntries()->create([
                'game_side_id' => $side->id,
                'user_id' => (int) $userId,
                'source_event_participant_id' => $participant->id,
                'status' => GameRosterStatusEnum::SELECTED,
            ]);
        }
    }

    private function createTemporaryTeam(Event $event, Game $game, Actor $actor, string $name): Team
    {
        return Team::query()->create([
            'temporary_for_event_id' => $event->id,
            'created_by_actor_id' => $actor->id,
            'name' => $name,
            'alias' => 'game-'.$game->id.'-'.Str::lower(Str::random(6)),
            'status' => TeamStatusEnum::ACTIVE,
        ]);
    }

    /**
     * @param  array<int, int>  $sideAUserIds
     * @param  array<int, int>  $sideBUserIds
     */
    private function assertRosterSizes(
        array $sideAUserIds,
        array $sideBUserIds,
        int $sideASize,
        int $sideBSize,
    ): void {
        $this->assertSideSizeLimits($sideASize, $sideBSize);
        if ($sideAUserIds === [] || $sideBUserIds === []) {
            throw new InvalidArgumentException('На каждой стороне должен быть хотя бы один игрок.');
        }
        if (count($sideAUserIds) > $sideASize || count($sideBUserIds) > $sideBSize) {
            throw new InvalidArgumentException('Количество выбранных игроков превышает формат стороны.');
        }
    }

    private function assertSideSizeLimits(int $sideASize, int $sideBSize): void
    {
        if ($sideASize < 1 || $sideASize > 7 || $sideBSize < 1 || $sideBSize > 7) {
            throw new InvalidArgumentException('Количество игроков на каждой стороне должно быть от 1 до 7.');
        }
    }

    private function assertMiniGameFormat(
        int $sideASize,
        int $sideBSize,
        int $confirmedParticipantsCount,
    ): void {
        $totalPlayers = $sideASize + $sideBSize;

        if ($sideASize < 1
            || $sideASize > 6
            || $sideBSize < 1
            || $sideBSize > 5
            || $sideASize < $sideBSize
            || $sideASize - $sideBSize > 1
            || $totalPlayers > min(11, $confirmedParticipantsCount)) {
            throw new InvalidArgumentException(
                'Формат мини-игры должен соответствовать доступному составу: от 1×1 до 6×5.',
            );
        }
    }

    /** @return array{0: string, 1: string} */
    private function normalizeSideNames(string $sideAName, string $sideBName): array
    {
        $sideAName = trim(preg_replace('/\s+/u', ' ', $sideAName) ?? $sideAName);
        $sideBName = trim(preg_replace('/\s+/u', ' ', $sideBName) ?? $sideBName);
        if ($sideAName === '' || $sideBName === '') {
            throw new InvalidArgumentException('Укажите названия обеих команд.');
        }
        $normalizedA = str_replace('ё', 'е', mb_strtolower($sideAName));
        $normalizedB = str_replace('ё', 'е', mb_strtolower($sideBName));
        if ($normalizedA === $normalizedB) {
            throw new InvalidArgumentException('Названия команд должны отличаться.');
        }

        return [$sideAName, $sideBName];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: bool} */
    private function resolveMiniGamePeriod(Event $parent, ?string $startsAt, ?string $endsAt): array
    {
        if ($startsAt === null && $endsAt === null) {
            return [$parent->starts_at->copy(), $parent->ends_at->copy(), false];
        }
        if ($startsAt === null || $endsAt === null) {
            throw new InvalidArgumentException('Укажите и начало, и окончание либо оставьте оба поля пустыми.');
        }

        $timezone = $parent->venue?->schedule?->timezone
            ?: config('app.timezone', 'Europe/Moscow');
        $localDate = $parent->starts_at->setTimezone($timezone)->toDateString();
        $start = Carbon::parse($localDate.' '.$startsAt, $timezone);
        $end = Carbon::parse($localDate.' '.$endsAt, $timezone);
        if ($end->equalTo($start)) {
            throw new InvalidArgumentException('Начало и окончание мини-игры не могут совпадать.');
        }
        if ($end->lessThan($start)) {
            $end->addDay();
        }

        return [$start, $end, true];
    }

    private function aggregateEventId(Game $game): int
    {
        return (int) $game->event_id;
    }

    /** @return Collection<int, array{membership_id?: int, participant_id?: int}> */
    private function rosterCandidates(Game $game): Collection
    {
        if ($game->event->type !== EventTypeEnum::GAME) {
            return $game->event->participants()
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->get()
                ->mapWithKeys(fn ($participant) => [
                    $participant->user_id => ['participant_id' => $participant->id],
                ]);
        }

        return $game->sides()
            ->with(['team.memberships' => fn ($query) => $query->whereHas(
                'contract',
                fn ($contract) => $contract->where('status', ContractStatusEnum::ACTIVE->value),
            )])
            ->get()
            ->flatMap(fn ($side) => ($side->team?->memberships ?? collect())->map(fn ($membership) => [
                'membership' => $membership,
                'slot' => $side->slot,
            ]))
            ->mapWithKeys(fn ($item) => [
                $item['membership']->user_id => [
                    'membership_id' => $item['membership']->id,
                    'slot' => $item['slot'],
                ],
            ]);
    }
}
