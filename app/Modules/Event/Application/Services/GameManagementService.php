<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Events\GameStatisticsConfirmed;
use App\Modules\Event\Domain\Models\Event;
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
    public function initializeStandalone(
        Event $event,
        int $teamAId,
        int $teamBId,
        int $sideASize = 5,
        int $sideBSize = 5,
    ): void {
        if ($event->type !== EventTypeEnum::GAME || $event->parent_event_id !== null) {
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

        $event->gameDetail()->create([
            'side_a_size' => $sideASize,
            'side_b_size' => $sideBSize,
        ]);

        $sideA = $event->gameSides()->create([
            'team_id' => $teamAId,
            'slot' => 'A',
            'display_name' => $teams[$teamAId]->name,
        ]);
        $sideB = $event->gameSides()->create([
            'team_id' => $teamBId,
            'slot' => 'B',
            'display_name' => $teams[$teamBId]->name,
        ]);

        $sideAUsers = $this->snapshotTeamRoster($event, $sideA, $teams[$teamAId]);
        $this->snapshotTeamRoster($event, $sideB, $teams[$teamBId], $sideAUsers);
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
    ): Event {
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
        ): Event {
            $lockedParent = Event::query()->lockForUpdate()->findOrFail($parent->id);
            [$start, $end, $isTimeScheduled] = $this->resolveMiniGamePeriod($lockedParent, $startsAt, $endsAt);
            [$sideAName, $sideBName] = $this->normalizeSideNames($sideAName, $sideBName);

            if ($isTimeScheduled && ($start->lessThan($lockedParent->starts_at)
                || $end->greaterThan($lockedParent->ends_at))) {
                throw new InvalidArgumentException('Мини-игра должна целиком входить во время тренировки.');
            }

            if ($isTimeScheduled) {
                $overlap = Event::query()
                    ->where('parent_event_id', $lockedParent->id)
                    ->whereNotIn('status', [EventStatusEnum::CANCELLED->value])
                    ->whereHas('gameDetail', fn ($query) => $query->where('is_time_scheduled', true))
                    ->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start)
                    ->lockForUpdate()
                    ->exists();
                if ($overlap) {
                    throw new InvalidArgumentException('В выбранное время уже запланирована другая мини-игра.');
                }
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

            $game = Event::query()->create([
                'parent_event_id' => $lockedParent->id,
                'venue_id' => $lockedParent->venue_id,
                'organizer_actor_id' => $actor->id,
                'title' => $title,
                'alias' => Str::slug($title).'-'.Str::lower(Str::random(6)),
                'type' => EventTypeEnum::GAME,
                'status' => EventStatusEnum::PUBLISHED,
                'visibility' => $lockedParent->visibility,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);

            $teamA = $this->createTemporaryTeam($game, $actor, $sideAName);
            $teamB = $this->createTemporaryTeam($game, $actor, $sideBName);
            $game->gameDetail()->create([
                'side_a_size' => $sideASize,
                'side_b_size' => $sideBSize,
                'is_time_scheduled' => $isTimeScheduled,
            ]);
            $sideA = $game->gameSides()->create([
                'team_id' => $teamA->id,
                'slot' => 'A',
                'display_name' => $teamA->name,
            ]);
            $sideB = $game->gameSides()->create([
                'team_id' => $teamB->id,
                'slot' => 'B',
                'display_name' => $teamB->name,
            ]);

            $this->snapshotParticipantRoster($game, $sideA, $sideAUserIds, $confirmedParticipants);
            $this->snapshotParticipantRoster($game, $sideB, $sideBUserIds, $confirmedParticipants);

            return $game->load(['gameDetail', 'gameSides', 'gameRosterEntries.user.profile']);
        });

        event(new EventChanged($parent->id));

        return $game;
    }

    public function updateMiniGame(
        Event $game,
        string $title,
        ?string $startsAt,
        ?string $endsAt,
        string $sideAName,
        string $sideBName,
        int $sideASize,
        int $sideBSize,
    ): void {
        DB::transaction(function () use (
            $game,
            $title,
            $startsAt,
            $endsAt,
            $sideAName,
            $sideBName,
            $sideASize,
            $sideBSize,
        ): void {
            if ($game->parent_event_id === null) {
                throw new InvalidArgumentException('Этот сценарий доступен только для мини-игры.');
            }

            // Во всех сценариях с двумя мероприятиями сохраняем единый порядок:
            // родитель → дочерняя игра. Это снижает риск взаимной блокировки.
            $lockedParent = Event::query()->lockForUpdate()->findOrFail($game->parent_event_id);
            $lockedGame = Event::query()->lockForUpdate()->findOrFail($game->id);
            if ($lockedGame->parent_event_id !== $lockedParent->id) {
                throw new InvalidArgumentException('Связь мини-игры с тренировкой была изменена.');
            }
            $detail = $lockedGame->gameDetail()->lockForUpdate()->firstOrFail();
            if ($detail->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
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

            if ($isTimeScheduled) {
                $overlap = Event::query()
                    ->where('parent_event_id', $lockedParent->id)
                    ->where('id', '!=', $lockedGame->id)
                    ->whereNotIn('status', [EventStatusEnum::CANCELLED->value])
                    ->whereHas('gameDetail', fn ($query) => $query->where('is_time_scheduled', true))
                    ->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start)
                    ->lockForUpdate()
                    ->exists();
                if ($overlap) {
                    throw new InvalidArgumentException('В выбранное время уже запланирована другая мини-игра.');
                }
            }

            $selectedBySide = $lockedGame->gameRosterEntries()
                ->selectRaw('game_side_id, count(*) as aggregate')
                ->where('status', GameRosterStatusEnum::SELECTED->value)
                ->groupBy('game_side_id')
                ->pluck('aggregate', 'game_side_id');
            $sides = $lockedGame->gameSides()->orderBy('slot')->lockForUpdate()->get()->keyBy('slot');
            if ((int) ($selectedBySide[$sides['A']->id] ?? 0) > $sideASize
                || (int) ($selectedBySide[$sides['B']->id] ?? 0) > $sideBSize) {
                throw new InvalidArgumentException('Новый лимит меньше уже выбранного состава.');
            }

            $lockedGame->update([
                'title' => $title,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
            $detail->update([
                'side_a_size' => $sideASize,
                'side_b_size' => $sideBSize,
                'is_time_scheduled' => $isTimeScheduled,
            ]);
            foreach (['A' => $sideAName, 'B' => $sideBName] as $slot => $name) {
                $side = $sides[$slot];
                $side->update(['display_name' => $name]);
                $side->team()->update(['name' => $name]);
            }
        });

        event(new EventChanged((int) $game->parent_event_id));
    }

    public function deleteMiniGame(Event $game): void
    {
        $parentEventId = (int) $game->parent_event_id;

        DB::transaction(function () use ($game): void {
            if ($game->parent_event_id === null) {
                throw new InvalidArgumentException('Удалить здесь можно только мини-игру.');
            }

            $lockedParent = Event::query()->whereKey($game->parent_event_id)->lockForUpdate()->firstOrFail();
            $lockedGame = Event::query()->lockForUpdate()->findOrFail($game->id);
            if ($lockedGame->parent_event_id !== $lockedParent->id) {
                throw new InvalidArgumentException('Связь мини-игры с тренировкой была изменена.');
            }
            $detail = $lockedGame->gameDetail()->lockForUpdate()->firstOrFail();
            if ($detail->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Мини-игру с подтверждённой статистикой удалить нельзя.');
            }

            $lockedGame->delete();
        });

        event(new EventChanged($parentEventId));
    }

    /**
     * @param  array<int, int>  $sideAUserIds
     * @param  array<int, int>  $sideBUserIds
     */
    public function replaceRoster(Event $game, array $sideAUserIds, array $sideBUserIds): void
    {
        DB::transaction(function () use ($game, $sideAUserIds, $sideBUserIds): void {
            $lockedGame = Event::query()->lockForUpdate()->findOrFail($game->id);
            $detail = $lockedGame->gameDetail()->lockForUpdate()->firstOrFail();
            if ($detail->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Состав игры с подтверждённой статистикой изменять нельзя.');
            }
            $sides = $lockedGame->gameSides()->orderBy('slot')->lockForUpdate()->get()->keyBy('slot');
            if ($sides->count() !== 2) {
                throw new InvalidArgumentException('Для игры не настроены две стороны.');
            }
            $this->assertRosterSizes(
                $sideAUserIds,
                $sideBUserIds,
                (int) $detail->side_a_size,
                (int) $detail->side_b_size,
            );

            $allIds = collect([...$sideAUserIds, ...$sideBUserIds])->map(fn ($id) => (int) $id);
            if ($allIds->duplicates()->isNotEmpty()) {
                throw new InvalidArgumentException('Игрок не может входить сразу в обе стороны.');
            }

            $candidates = $this->rosterCandidates($lockedGame);
            if ($allIds->diff($candidates->keys())->isNotEmpty()) {
                throw new InvalidArgumentException('Один или несколько игроков недоступны для этой игры.');
            }
            if ($lockedGame->parent_event_id === null) {
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
            $lockedGame->gamePlayerStatistics()->delete();
            $lockedGame->gameRosterEntries()->delete();
            $detail->update([
                'statistics_status' => GameStatisticsStatusEnum::NOT_STARTED,
                'statistics_version' => $detail->statistics_version + 1,
            ]);
            foreach (['A' => $sideAUserIds, 'B' => $sideBUserIds] as $slot => $userIds) {
                foreach ($userIds as $userId) {
                    $source = $candidates[(int) $userId];
                    $lockedGame->gameRosterEntries()->create([
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
    public function saveStatistics(Event $game, Actor $actor, array $statistics): void
    {
        DB::transaction(function () use ($game, $statistics): void {
            $lockedGame = Event::query()->lockForUpdate()->findOrFail($game->id);
            $detail = $lockedGame->gameDetail()->lockForUpdate()->firstOrFail();
            if ($detail->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Подтверждённую статистику изменять нельзя.');
            }

            $sides = $lockedGame->gameSides()->lockForUpdate()->get()->keyBy('slot');
            if (! $sides->has('A') || ! $sides->has('B')) {
                throw new InvalidArgumentException('Для игры не настроены две стороны.');
            }
            foreach (['A', 'B'] as $slot) {
                $score = $statistics['scores'][$slot] ?? null;
                $sides[$slot]->update(['score' => $score === null ? null : (int) $score]);
            }

            $roster = $lockedGame->gameRosterEntries()->get()->keyBy('user_id');
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
                $lockedGame->gamePlayerStatistics()->updateOrCreate(
                    ['user_id' => (int) $userId],
                    [...$normalized, 'game_side_id' => $entry->game_side_id],
                );
            }

            $detail->update([
                'statistics_status' => GameStatisticsStatusEnum::READY,
                'statistics_version' => $detail->statistics_version + 1,
            ]);
        });

        event(new EventChanged($this->aggregateEventId($game)));
    }

    public function confirmStatistics(Event $game, Actor $actor): void
    {
        DB::transaction(function () use ($game, $actor): void {
            $lockedGame = Event::query()->lockForUpdate()->findOrFail($game->id);
            $detail = $lockedGame->gameDetail()->lockForUpdate()->firstOrFail();
            if ($detail->statistics_status !== GameStatisticsStatusEnum::READY) {
                throw new InvalidArgumentException('Сначала сохраните готовую статистику.');
            }
            if ($lockedGame->gameSides()->whereNull('score')->exists()) {
                throw new InvalidArgumentException('Укажите итоговый счёт обеих сторон.');
            }
            if ($lockedGame->ends_at->isFuture()) {
                throw new InvalidArgumentException('Подтвердить итоговую статистику можно после окончания игры.');
            }

            $selectedPlayers = $lockedGame->gameRosterEntries()
                ->where('status', GameRosterStatusEnum::SELECTED->value)
                ->count();
            $statisticPlayers = $lockedGame->gamePlayerStatistics()->count();
            if ($selectedPlayers === 0 || $statisticPlayers !== $selectedPlayers) {
                throw new InvalidArgumentException('Сохраните статистику для каждого игрока выбранного состава.');
            }
            $selectedBySide = $lockedGame->gameRosterEntries()
                ->selectRaw('game_side_id, count(*) as aggregate')
                ->where('status', GameRosterStatusEnum::SELECTED->value)
                ->groupBy('game_side_id')
                ->pluck('aggregate', 'game_side_id');
            $sides = $lockedGame->gameSides()->get()->keyBy('slot');
            if ($sides->count() !== 2
                || (int) ($selectedBySide[$sides['A']->id] ?? 0) < 1
                || (int) ($selectedBySide[$sides['B']->id] ?? 0) < 1
                || (int) ($selectedBySide[$sides['A']->id] ?? 0) > $detail->side_a_size
                || (int) ($selectedBySide[$sides['B']->id] ?? 0) > $detail->side_b_size) {
                throw new InvalidArgumentException('Проверьте составы: на каждой стороне должен быть хотя бы один игрок и не больше указанного формата.');
            }

            $detail->update([
                'statistics_status' => GameStatisticsStatusEnum::CONFIRMED,
                'statistics_confirmed_at' => now(),
                'statistics_confirmed_by_actor_id' => $actor->id,
            ]);
            $lockedGame->gameRosterEntries()
                ->where('status', GameRosterStatusEnum::SELECTED->value)
                ->update(['status' => GameRosterStatusEnum::PLAYED->value]);

            DB::afterCommit(fn () => event(new GameStatisticsConfirmed($lockedGame->id)));
        });

        event(new EventChanged($this->aggregateEventId($game)));
    }

    /**
     * @param  Collection<int, int>|null  $excludedUserIds
     * @return Collection<int, int>
     */
    private function snapshotTeamRoster(
        Event $event,
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
            $event->gameRosterEntries()->create([
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
    private function snapshotParticipantRoster(Event $event, GameSide $side, array $userIds, Collection $participants): void
    {
        foreach ($userIds as $userId) {
            $participant = $participants[(int) $userId];
            $event->gameRosterEntries()->create([
                'game_side_id' => $side->id,
                'user_id' => (int) $userId,
                'source_event_participant_id' => $participant->id,
                'status' => GameRosterStatusEnum::SELECTED,
            ]);
        }
    }

    private function createTemporaryTeam(Event $game, Actor $actor, string $name): Team
    {
        return Team::query()->create([
            'temporary_for_event_id' => $game->id,
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
        $sideAName = trim($sideAName);
        $sideBName = trim($sideBName);
        if ($sideAName === '' || $sideBName === '') {
            throw new InvalidArgumentException('Укажите названия обеих команд.');
        }
        if (mb_strtolower($sideAName) === mb_strtolower($sideBName)) {
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

    private function aggregateEventId(Event $game): int
    {
        return (int) ($game->parent_event_id ?: $game->id);
    }

    /** @return Collection<int, array{membership_id?: int, participant_id?: int}> */
    private function rosterCandidates(Event $game): Collection
    {
        if ($game->parent_event_id !== null) {
            return $game->parentEvent->participants()
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->get()
                ->mapWithKeys(fn ($participant) => [
                    $participant->user_id => ['participant_id' => $participant->id],
                ]);
        }

        return $game->gameSides()
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
