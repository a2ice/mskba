<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EventGamePlanningService
{
    /** The new event-first contract is independent of the legacy single-game payload. */
    public static function rules(): array
    {
        return [
            'games' => ['sometimes', 'array', 'list', 'max:50'],
            'games.*' => ['array:title,format,recruitment_mode,team_a_id,team_b_id,scoring_type,timing_mode,periods_count'],
            'games.*.title' => ['required', 'string', 'max:150'],
            'games.*.format' => ['required', Rule::in([
                GameFormatEnum::STREETBALL_1X1->value,
                GameFormatEnum::STREETBALL_3X3->value,
                GameFormatEnum::BASKETBALL_5X5->value,
            ])],
            'games.*.recruitment_mode' => ['nullable', Rule::enum(GameRecruitmentModeEnum::class)],
            'games.*.team_a_id' => ['nullable', 'integer', 'min:1'],
            'games.*.team_b_id' => ['nullable', 'integer', 'min:1'],
            'games.*.scoring_type' => ['nullable', Rule::enum(GameScoringTypeEnum::class)],
            'games.*.timing_mode' => ['nullable', Rule::enum(GameTimingModeEnum::class)],
            'games.*.periods_count' => ['nullable', 'integer', Rule::in([2, 4])],
        ];
    }

    /**
     * Create planned games without admissions, confirmed sides or participant
     * snapshots. The selected permanent teams are only proposed opponents.
     *
     * @param list<array<string,mixed>> $games
     */
    public function initialize(Event $event, Actor $actor, array $games): void
    {
        $validated = Validator::make(['games' => $games], self::rules())->validate();
        DB::transaction(function () use ($event, $actor, $validated): void {
            $parent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $rows = $validated['games'];
            $teamIds = collect($rows)->flatMap(fn (array $row): array => [
                $row['team_a_id'] ?? null, $row['team_b_id'] ?? null,
            ])->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values();
            $teams = Team::query()->competitionEligible()->whereIn('id', $teamIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($rows as $index => $row) {
                $game = $this->create($parent, $actor, $row, $index, $teams);
                if ($parent->primary_game_id === null) {
                    $parent->forceFill(['primary_game_id' => $game->id])->save();
                }
            }
            $event->setAttribute('primary_game_id', $parent->primary_game_id);
        });
    }

    private function create(Event $event, Actor $actor, array $row, int $index, \Illuminate\Support\Collection $teams): Game
    {
        $prefix = 'games.'.$index.'.';
        $format = GameFormatEnum::from($row['format']);
        $size = $format->sideSize();
        $mode = GameRecruitmentModeEnum::from($row['recruitment_mode'] ?? GameRecruitmentModeEnum::INDIVIDUAL_DRAFT->value);
        $a = isset($row['team_a_id']) ? (int) $row['team_a_id'] : null;
        $b = isset($row['team_b_id']) ? (int) $row['team_b_id'] : null;
        $errors = [];
        if (trim($row['title']) === '') {
            $errors[$prefix.'title'] = 'Укажите название игры.';
        }
        if ($size === 1 && $mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT) {
            $errors[$prefix.'recruitment_mode'] = 'Для игры 1×1 доступны только отдельные игроки.';
        }
        if ($mode === GameRecruitmentModeEnum::PREFORMED_TEAMS) {
            foreach (['team_a_id' => $a, 'team_b_id' => $b] as $field => $id) {
                if ($id === null || ! $teams->has($id)) {
                    $errors[$prefix.$field] = 'Выберите активную команду.';
                }
            }
            if ($a !== null && $a === $b) {
                $errors[$prefix.'team_b_id'] = 'Команды должны быть разными.';
            }
        } elseif ($a !== null || $b !== null) {
            $errors[$prefix.'team_a_id'] = 'Для игры из участников команды не указываются.';
        }

        $scoring = GameScoringTypeEnum::from($row['scoring_type'] ?? $format->scoringType()->value);
        $timing = GameTimingModeEnum::from($row['timing_mode'] ?? (
            $format === GameFormatEnum::BASKETBALL_5X5 ? GameTimingModeEnum::PERIODS->value : GameTimingModeEnum::WHOLE_GAME->value
        ));
        $periods = $timing === GameTimingModeEnum::PERIODS ? (int) ($row['periods_count'] ?? 4) : null;
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $game = $event->games()->create([
            'created_by_actor_id' => $actor->id,
            'title' => trim($row['title']),
            'status' => GameStatusEnum::SCHEDULED,
            'format' => $format,
            'recruitment_mode' => $mode,
            'accepts_applications' => true,
            'side_a_size' => $size,
            'side_b_size' => $size,
            'scoring_type' => $scoring,
            'timing_mode' => $timing,
            'periods_count' => $periods,
            'planned_team_a_id' => $a,
            'planned_team_b_id' => $b,
        ]);
        if ($periods !== null) {
            foreach (range(1, $periods) as $number) {
                $game->periods()->create(['number' => $number]);
            }
        }

        return $game;
    }
}
