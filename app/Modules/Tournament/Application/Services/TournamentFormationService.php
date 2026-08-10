<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAssessmentSourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TournamentFormationService
{
    private const FORMULA_VERSION = 1;

    private const WEIGHTS = [
        'stamina' => 6, 'speed' => 5, 'ball_handling' => 7, 'passing' => 7,
        'close_range_shooting' => 5, 'mid_range_shooting' => 5, 'long_range_shooting' => 5,
        'defense' => 8, 'rebounding' => 7, 'basketball_iq' => 8,
        'height_cm' => 5, 'weight_kg' => 2, 'experience_years' => 6, 'body_type' => 2,
    ];

    private const FEATURE_LABELS = [
        'stamina' => 'выносливость', 'speed' => 'скорость', 'ball_handling' => 'ведение', 'passing' => 'передачи',
        'close_range_shooting' => 'ближний бросок', 'mid_range_shooting' => 'средний бросок', 'long_range_shooting' => 'дальний бросок',
        'defense' => 'защита', 'rebounding' => 'подборы', 'basketball_iq' => 'игровой интеллект',
        'height_cm' => 'рост', 'weight_kg' => 'вес', 'experience_years' => 'опыт', 'body_type' => 'телосложение',
    ];

    public function __construct(private readonly TournamentAccess $access) {}

    /** @return array<string, mixed> */
    public function preview(Tournament $tournament, Actor $actor, TournamentAssessmentSourceEnum $source, int $teamCount, int $seed): array
    {
        $this->access->assertAllows($tournament, $actor, TournamentPermissionEnum::MANAGE_GAMES);
        $this->assertFormationAvailable($tournament);
        $users = $this->pool($tournament);
        $sideSize = $tournament->format?->sideSize() ?? throw new InvalidArgumentException('Для формирования команд нужно указать формат турнира.');
        if ($teamCount < 2 || $users->count() < $teamCount * $sideSize) {
            throw new InvalidArgumentException("Для {$teamCount} команд нужно не меньше ".($teamCount * $sideSize).' подтверждённых игроков.');
        }

        $players = $this->scorePlayers($users, $source, $seed);
        $teams = collect(range(1, $teamCount))->map(fn (int $number): array => ['number' => $number, 'players' => [], 'score' => 0.0, 'unknown' => 0, 'positions' => []])->all();
        $maxSize = (int) ceil($players->count() / $teamCount);
        foreach ($players as $player) {
            $eligible = collect($teams)->filter(fn (array $team): bool => count($team['players']) < $maxSize);
            $target = $eligible->keys()->sortBy(function (int $index) use ($teams, $player): string {
                $positionPenalty = isset($teams[$index]['positions'][$player['primary_position']]) ? 1 : 0;

                if ($player['coverage'] < 0.35) {
                    return sprintf('%03d-%03d-%015.6f-%03d', $teams[$index]['unknown'], $positionPenalty, $teams[$index]['score'], $index);
                }

                return sprintf('%03d-%015.6f-%03d-%03d', $positionPenalty, $teams[$index]['score'], $teams[$index]['unknown'], $index);
            })->first();
            $teams[$target]['players'][] = $player;
            $teams[$target]['score'] += $player['score'];
            $teams[$target]['unknown'] += $player['coverage'] < 0.35 ? 1 : 0;
            $teams[$target]['positions'][$player['primary_position']] = ($teams[$target]['positions'][$player['primary_position']] ?? 0) + 1;
        }

        return [
            'formula_version' => self::FORMULA_VERSION,
            'assessment_source' => $source->value,
            'seed' => $seed,
            'pool_fingerprint' => $this->fingerprint($tournament),
            'teams' => collect($teams)->map(fn (array $team): array => [
                'number' => $team['number'],
                'score' => round($team['score'] / max(1, count($team['players'])), 4),
                'coverage' => round(collect($team['players'])->avg('coverage') ?? 0, 4),
                'players' => collect($team['players'])->map(fn (array $player): array => collect($player)->only(['id', 'name', 'username', 'score', 'coverage', 'primary_position', 'missing_features'])->all())->all(),
            ])->all(),
        ];
    }

    /** @param array<int, array{number:int, user_ids:list<int>}> $teams */
    public function apply(Tournament $tournament, Actor $actor, string $fingerprint, array $teams): void
    {
        DB::transaction(function () use ($tournament, $actor, $fingerprint, $teams): void {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            $this->assertFormationAvailable($locked);
            if (! hash_equals($this->fingerprint($locked), $fingerprint)) {
                throw new InvalidArgumentException('Пул участников изменился. Сформируйте preview заново.');
            }
            $poolIds = $this->pool($locked)->pluck('id')->sort()->values()->all();
            $assigned = collect($teams)->flatMap(fn (array $team) => $team['user_ids'])->map(fn ($id): int => (int) $id)->all();
            if (count($assigned) !== count(array_unique($assigned)) || collect($assigned)->sort()->values()->all() !== $poolIds) {
                throw new InvalidArgumentException('Каждый подтверждённый игрок должен входить ровно в одну команду.');
            }
            $minimum = $locked->format?->sideSize() ?? 1;
            if (count($teams) < 2 || collect($teams)->contains(fn (array $team): bool => count($team['user_ids']) < $minimum)) {
                throw new InvalidArgumentException('В каждой команде должно быть не меньше игроков, чем требует формат.');
            }
            $locked->entries()
                ->where('source', TournamentEntrySourceEnum::ASSEMBLED->value)
                ->get()
                ->each
                ->delete();
            foreach (array_values($teams) as $index => $team) {
                $entry = $locked->entries()->create(['source' => TournamentEntrySourceEnum::ASSEMBLED, 'name' => 'Команда '.($index + 1), 'status' => TournamentEntryStatusEnum::ACTIVE, 'position' => $index + 1]);
                $entry->members()->createMany(collect($team['user_ids'])->values()->map(fn ($userId, int $position): array => ['user_id' => (int) $userId, 'position' => $position])->all());
            }
        });
    }

    private function assertFormationAvailable(Tournament $tournament): void
    {
        if ($tournament->recruitment_mode !== TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT || $tournament->format?->sideSize() === 1) {
            throw new InvalidArgumentException('Balanced-формирование доступно только для individual draft 3×3 или 5×5.');
        }
        if ($tournament->matches()->exists()) {
            throw new InvalidArgumentException('После создания матчей переформировывать составы нельзя.');
        }
    }

    /** @return Collection<int, User> */
    private function pool(Tournament $tournament): Collection
    {
        $ids = $tournament->admissions()->where('status', TournamentAdmissionStatusEnum::ACCEPTED->value)->whereNotNull('user_id')->pluck('user_id');

        return User::query()->whereKey($ids)->with(['profile', 'playerProfile.positions', 'playerProfile.selfAssessment', 'playerObjectiveAssessment'])->get();
    }

    private function fingerprint(Tournament $tournament): string
    {
        $values = $tournament->admissions()->where('status', TournamentAdmissionStatusEnum::ACCEPTED->value)->whereNotNull('user_id')->orderBy('id')->get(['id', 'user_id', 'updated_at'])
            ->map(fn ($item): string => $item->id.':'.$item->user_id.':'.$item->updated_at?->format('U.u'))->join('|');

        return hash('sha256', self::FORMULA_VERSION.'|'.$tournament->id.'|'.$values);
    }

    private function scorePlayers(Collection $users, TournamentAssessmentSourceEnum $source, int $seed): Collection
    {
        $rows = $users->map(function (User $user) use ($source): array {
            $profile = $user->playerProfile;
            $assessment = $source === TournamentAssessmentSourceEnum::SELF_ASSESSMENT ? $profile?->selfAssessment : $user->playerObjectiveAssessment;
            $values = [];
            foreach (self::WEIGHTS as $feature => $_weight) {
                $values[$feature] = match ($feature) {
                    'height_cm' => $profile?->height_cm,
                    'weight_kg' => $profile?->weight_kg !== null ? (float) $profile->weight_kg : null,
                    'experience_years' => $profile?->experience_years,
                    'body_type' => $profile?->body_type !== null ? array_search($profile->body_type, $profile->body_type::cases(), true) : null,
                    default => $assessment?->{$feature},
                };
            }
            $positions = $profile?->positions->pluck('position.value')->all() ?? [];

            return ['user' => $user, 'values' => $values, 'primary_position' => $positions[0] ?? 'unknown'];
        });
        $medians = collect(array_keys(self::WEIGHTS))->mapWithKeys(function (string $feature) use ($rows): array {
            $known = $rows->pluck('values.'.$feature)->filter(fn ($value) => $value !== null)->sort()->values();

            return [$feature => $known->isEmpty() ? 0.5 : (float) $known->get((int) floor(($known->count() - 1) / 2))];
        });
        $ranges = collect(array_keys(self::WEIGHTS))->mapWithKeys(function (string $feature) use ($rows, $medians): array {
            $known = $rows->pluck('values.'.$feature)->filter(fn ($value) => $value !== null)->map(fn ($value): float => (float) $value);

            return [$feature => ['min' => $known->min() ?? $medians[$feature], 'max' => $known->max() ?? $medians[$feature]]];
        });

        return $rows->map(function (array $row) use ($medians, $ranges, $source, $seed): array {
            $known = collect($row['values'])->filter(fn ($value) => $value !== null)->count();
            $weighted = 0.0;
            $weightSum = 0;
            foreach (self::WEIGHTS as $feature => $weight) {
                $value = (float) ($row['values'][$feature] ?? $medians[$feature]);
                $range = $ranges[$feature];
                $normalized = $range['max'] > $range['min'] ? ($value - $range['min']) / ($range['max'] - $range['min']) : 0.5;
                if ($source === TournamentAssessmentSourceEnum::OBJECTIVE_ASSESSMENT && ! in_array($feature, ['height_cm', 'weight_kg', 'experience_years', 'body_type'], true)) {
                    $confidence = (float) ($row['user']->playerObjectiveAssessment?->confidence ?? 0);
                    $normalized = $confidence * $normalized + (1 - $confidence) * 0.5;
                }
                $weighted += $normalized * $weight;
                $weightSum += $weight;
            }

            return [
                'id' => $row['user']->id,
                'name' => trim(($row['user']->profile?->first_name ?? '').' '.($row['user']->profile?->last_name ?? '')) ?: $row['user']->username,
                'username' => $row['user']->username,
                'score' => round($weighted / $weightSum, 4),
                'coverage' => round($known / count(self::WEIGHTS), 4),
                'primary_position' => $row['primary_position'],
                'missing_features' => collect($row['values'])
                    ->filter(fn ($value) => $value === null)
                    ->keys()
                    ->map(fn (string $feature): string => self::FEATURE_LABELS[$feature])
                    ->values()
                    ->all(),
                'tie' => hash('sha256', $seed.':'.$row['user']->id),
            ];
        })->sortBy(fn (array $player): string => sprintf('%015.6f-%s', -$player['score'], $player['tie']))->values();
    }
}
