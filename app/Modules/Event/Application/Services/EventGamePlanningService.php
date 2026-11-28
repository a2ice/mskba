<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Events\EventChanged;
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
    public function __construct(private readonly EventManagementAccess $access, private readonly GamePlanningCancellation $cancellation) {}

    /** The new event-first contract is independent of the legacy single-game payload. */
    public static function rules(): array
    {
        return [
            'games' => ['sometimes', 'array', 'list', 'max:50'],
            'games.*' => ['array:title,format,recruitment_mode,team_a_id,team_b_id,scoring_type,timing_mode,periods_count'],
            'games.*.title' => ['required', 'string', 'max:150'],
            'games.*.format' => ['present', 'nullable', Rule::in([
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

    /** Create a single planned game on an existing event. */
    public function add(Event $event, Actor $actor, array $row): Game
    {
        $row = $this->validatedRow($row);
        $game = DB::transaction(function () use ($event, $actor, $row): Game {
            $parent = $this->lockManageable($event, $actor, EventResponsibilityPermissionEnum::CREATE_MINI_GAME);
            if ($parent->games()->where('uses_event_planning', true)->count() >= 50) {
                throw ValidationException::withMessages(['games' => 'Можно добавить не более 50 игр.']);
            }
            $game = $this->create($parent, $actor, $row, 0, $this->lockTeamsForRow($row));
            if ($parent->primary_game_id === null) {
                $parent->forceFill(['primary_game_id' => $game->id])->save();
            }

            return $game;
        }, 3);
        event(new EventChanged($event->id));

        return $game;
    }

    /** Edit a planned game while its sporting configuration is still unused. */
    public function edit(Event $event, Game $game, Actor $actor, array $row): Game
    {
        $row = $this->validatedRow($row);
        $updated = DB::transaction(function () use ($event, $game, $actor, $row): Game {
            $parent = $this->lockManageable($event, $actor, EventResponsibilityPermissionEnum::UPDATE_MINI_GAME);
            $locked = $this->lockPlanned($parent, $game);
            $attributes = $this->attributes($row, 0, $this->lockTeamsForRow($row));
            $locked->fill($attributes);
            // Once admissions or sides exist, only the display name can change
            // until the dedicated invitation / formation lifecycle handles transitions.
            if ($locked->isDirty(array_values(array_diff(array_keys($attributes), ['title'])))) {
                $this->assertUnused($locked);
                $locked->periods()->delete();
                $this->createPeriods($locked, $attributes['periods_count']);
            }
            $locked->save();

            return $locked->fresh();
        }, 3);
        event(new EventChanged($event->id));

        return $updated;
    }

    /** Remove a scheduled game without touching event participants or erasing history. */
    public function remove(Event $event, Game $game, Actor $actor, ?bool $notify = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($event, $game, $actor, $notify, $reason): void {
            $parent = $this->lockManageable($event, $actor, EventResponsibilityPermissionEnum::DELETE_MINI_GAME);
            $locked = $this->lockPlanned($parent, $game);
            if ($locked->actions()->exists() || $locked->playerStatistics()->exists()
                || $locked->sides()->where('score', '>', 0)->exists()
                || $locked->periods()->whereNotNull('actual_started_at')->exists()) {
                throw ValidationException::withMessages(['game' => 'У игры уже есть спортивные данные. Удаление невозможно.']);
            }
            $hasHistory = $locked->admissions()->exists() || $locked->invitationThreads()->exists()
                || $locked->sides()->exists() || $locked->rosterEntries()->exists() || $locked->sides_confirmed_at !== null;
            if ($hasHistory) {
                if ($notify === null) {
                    throw ValidationException::withMessages(['notify' => 'У игры уже есть история участия. Обновите страницу и подтвердите отмену.']);
                }
                $this->cancellation->cancel($parent, $locked, $actor, $notify, $reason);
            } else {
                $locked->delete();
            }
            if ((int) $parent->primary_game_id === (int) $locked->id) {
                $parent->forceFill(['primary_game_id' => $parent->games()
                    ->where('status', '!=', GameStatusEnum::CANCELLED->value)->orderBy('id')->value('id')])->save();
            }
        }, 3);
        event(new EventChanged($event->id));
    }

    private function validatedRow(array $row): array
    {
        return Validator::make(['games' => [$row]], self::rules())->validate()['games'][0];
    }

    private function lockManageable(Event $event, Actor $actor, EventResponsibilityPermissionEnum $permission): Event
    {
        $parent = Event::query()->lockForUpdate()->findOrFail($event->id);
        abort_unless($this->access->allows($parent, $actor, $permission), 403);
        if (! in_array($parent->status, [EventStatusEnum::DRAFT, EventStatusEnum::PUBLISHED], true)) {
            throw ValidationException::withMessages(['game' => 'Мероприятие уже закрыто.']);
        }
        // Tournament scheduling belongs to its own aggregate and must not be changed here.
        abort_if($parent->games()->whereHas('tournamentMatch')->exists(), 422, 'Матч турнира управляется отдельно.');

        return $parent;
    }

    private function lockPlanned(Event $event, Game $game): Game
    {
        $locked = $event->games()->whereKey($game->id)->where('uses_event_planning', true)->lockForUpdate()->firstOrFail();
        if ($locked->status !== GameStatusEnum::SCHEDULED || $locked->actual_started_at !== null
            || $locked->statistics_status !== GameStatisticsStatusEnum::NOT_STARTED) {
            throw ValidationException::withMessages(['game' => 'Начатую, завершённую или отменённую игру изменять нельзя.']);
        }

        return $locked;
    }

    private function assertUnused(Game $game): void
    {
        if ($game->admissions()->exists() || $game->sides()->exists() || $game->rosterEntries()->exists()
            || $game->actions()->exists() || $game->playerStatistics()->exists() || $game->sides_confirmed_at !== null
            || $game->periods()->whereNotNull('actual_started_at')->exists()) {
            throw ValidationException::withMessages(['game' => 'У игры уже есть история участия или спортивных действий.']);
        }
    }

    private function lockTeamsForRow(array $row): \Illuminate\Support\Collection
    {
        return Team::query()->competitionEligible()
            ->whereIn('id', array_filter([$row['team_a_id'] ?? null, $row['team_b_id'] ?? null]))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function create(Event $event, Actor $actor, array $row, int $index, \Illuminate\Support\Collection $teams): Game
    {
        $attributes = $this->attributes($row, $index, $teams);
        $game = $event->games()->create([
            ...$attributes,
            'created_by_actor_id' => $actor->id,
            'status' => GameStatusEnum::SCHEDULED,
            'uses_event_planning' => true,
        ]);
        $this->createPeriods($game, $attributes['periods_count']);

        return $game;
    }

    private function createPeriods(Game $game, ?int $periods): void
    {
        if ($periods !== null) {
            foreach (range(1, $periods) as $number) {
                $game->periods()->create(['number' => $number]);
            }
        }
    }

    private function attributes(array $row, int $index, \Illuminate\Support\Collection $teams): array
    {
        $prefix = 'games.'.$index.'.';
        $format = isset($row['format']) ? GameFormatEnum::from($row['format']) : null;
        if ($format === null) {
            $errors = [];
            if (trim($row['title']) === '') {
                $errors[$prefix.'title'] = 'Укажите название игры.';
            }
            foreach (['recruitment_mode', 'team_a_id', 'team_b_id', 'scoring_type', 'timing_mode', 'periods_count'] as $field) {
                if (isset($row[$field])) {
                    $errors[$prefix.$field] = 'Сначала выберите формат игры.';
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return [
                'title' => trim($row['title']),
                'format' => null,
                'recruitment_mode' => null,
                'accepts_applications' => false,
                'side_a_size' => null,
                'side_b_size' => null,
                'scoring_type' => null,
                'timing_mode' => null,
                'periods_count' => null,
                'planned_team_a_id' => null,
                'planned_team_b_id' => null,
            ];
        }
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

        return [
            'title' => trim($row['title']),
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
        ];
    }
}
