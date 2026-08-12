<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\GameActionTypeEnum;
use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAction;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;

final class GameLiveSnapshotBuilder
{
    public function __construct(
        private readonly GameStatisticsFields $statisticsFields,
        private readonly GamePeriodStatisticsBuilder $periodStatistics,
    ) {}

    public function load(int $eventId, int $gameId): Game
    {
        return Game::query()
            ->whereKey($gameId)
            ->where('event_id', $eventId)
            ->with([
                'sides.team.logo',
                'rosterEntries.gameSide',
                'rosterEntries.user.profile.activeAvatar',
                'playerStatistics',
                'latestAction.gameSide.team.logo',
                'latestAction.user.profile',
                'latestTeamAction.gameSide',
                'periods.actions',
            ])
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function build(Game $game): array
    {
        $sides = $game->sides->keyBy('slot');
        $roster = $game->rosterEntries->groupBy('game_side_id');
        $statistics = $game->playerStatistics->keyBy('user_id');
        $definitions = $this->statisticsFields->all();
        $activePeriod = $game->periods->first(fn ($period) => $period->status === GamePeriodStatusEnum::IN_PROGRESS);
        $latestAction = $game->latestAction;

        $snapshot = [
            'schema' => 1,
            'game_id' => $game->id,
            'status' => [
                'value' => $game->status->value,
                'is_live' => $game->actual_started_at !== null && $game->actual_ended_at === null,
                'is_finished' => $game->status === GameStatusEnum::COMPLETED
                    || $game->statistics_status === GameStatisticsStatusEnum::CONFIRMED,
                'is_terminal' => $game->actual_ended_at !== null || $game->status->isTerminal(),
                'ended_early' => (bool) $game->ended_early,
            ],
            'scores' => [
                'A' => (int) ($sides->get('A')?->score ?? 0),
                'B' => (int) ($sides->get('B')?->score ?? 0),
            ],
            'active_side' => $game->latestTeamAction?->gameSide?->slot,
            'timing' => [
                'mode' => $game->timing_mode->value,
                'periods_count' => (int) $game->periods_count,
                'active_period' => $activePeriod?->number,
                'periods' => $this->periodStatistics->build($game)->values()->all(),
            ],
            'teams' => [],
            'latest_action' => $latestAction === null ? null : [
                'sequence' => (int) $latestAction->sequence,
                'slot' => $latestAction->gameSide?->slot,
                'label' => $this->liveActionLabel($latestAction),
                'player_name' => $latestAction->user === null ? null : $this->userName($latestAction->user),
                'team_name' => $latestAction->gameSide?->display_name,
                'team_logo' => $latestAction->gameSide?->logoUrl(),
            ],
            'statistics_labels' => collect($definitions)
                ->mapWithKeys(fn (array $definition, string $field): array => [$field => $definition['label']])
                ->put('points', 'Очки')
                ->all(),
        ];

        foreach (['A', 'B'] as $slot) {
            $side = $sides->get($slot);
            $players = [];

            foreach ($roster->get($side?->id, collect()) as $entry) {
                $stat = $statistics->get($entry->user_id);
                $values = [];

                foreach (GamePlayerStatistic::COUNTING_FIELDS as $field) {
                    $value = (int) ($stat?->{$field} ?? 0);
                    if ($value > 0) {
                        $values[] = [
                            'field' => $field,
                            'label' => $definitions[$field]['label'],
                            'value' => $value,
                        ];
                    }
                }

                $players[] = [
                    'user_id' => (int) $entry->user_id,
                    'name' => $this->userName($entry->user),
                    'points' => $stat?->points($game->scoring_type) ?? 0,
                    'statistics' => $values,
                ];
            }

            $snapshot['teams'][$slot] = [
                'id' => $side?->team_id,
                'name' => $side?->display_name ?: 'Команда '.$slot,
                'logo' => $side?->logoUrl(),
                'players' => $players,
            ];
        }

        $snapshot['revision'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $snapshot['generated_at'] = now()->toISOString();

        return $snapshot;
    }

    private function userName($user): string
    {
        $name = trim(implode(' ', array_filter([
            $user->profile?->first_name,
            $user->profile?->last_name,
        ])));

        return $name ?: $user->username ?: 'Пользователь #'.$user->id;
    }

    private function liveActionLabel(GameAction $action): string
    {
        if ($action->type === GameActionTypeEnum::SHOT_MISSED) {
            return 'Мимо';
        }

        if ($action->type !== GameActionTypeEnum::SHOT_MADE) {
            return $action->type->label();
        }

        if (($action->payload['range'] ?? null) === 'free_throw') {
            return 'Штрафной';
        }

        return match ($action->points) {
            1 => '1 point',
            2 => '2 points',
            3 => '3 points',
            default => $action->type->label(),
        };
    }
}
