<?php

namespace App\Support\Basketball;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BalancedTeamFormationEngine
{
    public const FORMULA_VERSION = 1;

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

    /**
     * @param Collection<int, User> $users
     * @return array{formula_version:int, assessment_source:string, seed:int, teams:list<array<string,mixed>>}
     */
    public function build(Collection $users, string $assessmentSource, int $teamCount, int $seed): array
    {
        if (! in_array($assessmentSource, ['self_assessment', 'objective_assessment'], true)) {
            throw new InvalidArgumentException('Неизвестный источник оценки для balanced-формирования.');
        }
        if ($teamCount < 2) {
            throw new InvalidArgumentException('Для balanced-формирования нужно не меньше двух команд.');
        }
        if ($users->count() < $teamCount) {
            throw new InvalidArgumentException('Недостаточно игроков для указанного числа команд.');
        }

        $players = $this->scorePlayers($users, $assessmentSource, $seed);
        $teams = collect(range(1, $teamCount))->map(fn (int $number): array => [
            'number' => $number,
            'name' => 'Команда '.$number,
            'logo_preset' => sprintf('crest-%02d', ($number - 1) % 15),
            'players' => [],
            'score' => 0.0,
            'unknown' => 0,
            'positions' => [],
        ])->all();
        $maxSize = (int) ceil($players->count() / $teamCount);

        foreach ($players as $player) {
            $eligible = collect($teams)->filter(fn (array $team): bool => count($team['players']) < $maxSize);
            $target = $eligible->keys()->sortBy(function (int $index) use ($teams, $player): string {
                $positionPenalty = isset($teams[$index]['positions'][$player['primary_position']]) ? 1 : 0;

                if ($player['coverage'] < 0.35) {
                    return sprintf(
                        '%03d-%03d-%015.6f-%03d',
                        $teams[$index]['unknown'],
                        $positionPenalty,
                        $teams[$index]['score'],
                        $index,
                    );
                }

                return sprintf(
                    '%03d-%015.6f-%03d-%03d',
                    $positionPenalty,
                    $teams[$index]['score'],
                    $teams[$index]['unknown'],
                    $index,
                );
            })->first();
            $teams[$target]['players'][] = $player;
            $teams[$target]['score'] += $player['score'];
            $teams[$target]['unknown'] += $player['coverage'] < 0.35 ? 1 : 0;
            $teams[$target]['positions'][$player['primary_position']] = ($teams[$target]['positions'][$player['primary_position']] ?? 0) + 1;
        }

        return [
            'formula_version' => self::FORMULA_VERSION,
            'assessment_source' => $assessmentSource,
            'seed' => $seed,
            'teams' => collect($teams)->map(fn (array $team): array => [
                'number' => $team['number'],
                'name' => $team['name'],
                'logo_preset' => $team['logo_preset'],
                'score' => round($team['score'] / max(1, count($team['players'])), 4),
                'coverage' => round(collect($team['players'])->avg('coverage') ?? 0, 4),
                'players' => collect($team['players'])->map(fn (array $player): array => collect($player)
                    ->only(['id', 'name', 'username', 'score', 'coverage', 'primary_position', 'features'])
                    ->all())->all(),
            ])->values()->all(),
        ];
    }

    /** @param Collection<int, User> $users @return Collection<int, array<string,mixed>> */
    private function scorePlayers(Collection $users, string $assessmentSource, int $seed): Collection
    {
        $rows = $users->map(function (User $user) use ($assessmentSource): array {
            $profile = $user->playerProfile;
            $assessment = $assessmentSource === 'self_assessment'
                ? $profile?->selfAssessment
                : $user->playerObjectiveAssessment;
            $values = [];
            foreach (self::WEIGHTS as $feature => $_weight) {
                $values[$feature] = match ($feature) {
                    'height_cm' => $profile?->height_cm,
                    'weight_kg' => $profile?->weight_kg !== null ? (float) $profile->weight_kg : null,
                    'experience_years' => $profile?->experience_years,
                    'body_type' => $profile?->body_type !== null
                        ? array_search($profile->body_type, $profile->body_type::cases(), true)
                        : null,
                    default => $assessment?->{$feature},
                };
            }
            $positions = $profile?->positions->pluck('position.value')->all() ?? [];

            return [
                'user' => $user,
                'values' => $values,
                'display_values' => collect($values)->mapWithKeys(fn ($value, string $feature): array => [
                    $feature => $this->displayFeatureValue($feature, $value, $profile?->body_type),
                ])->all(),
                'primary_position' => $positions[0] ?? 'unknown',
            ];
        });

        $medians = collect(array_keys(self::WEIGHTS))->mapWithKeys(function (string $feature) use ($rows): array {
            $known = $rows->pluck('values.'.$feature)
                ->filter(fn ($value) => $value !== null)
                ->sort()
                ->values();

            return [$feature => $known->isEmpty()
                ? 0.5
                : (float) $known->get((int) floor(($known->count() - 1) / 2))];
        });
        $ranges = collect(array_keys(self::WEIGHTS))->mapWithKeys(function (string $feature) use ($rows, $medians): array {
            $known = $rows->pluck('values.'.$feature)
                ->filter(fn ($value) => $value !== null)
                ->map(fn ($value): float => (float) $value);

            return [$feature => [
                'min' => $known->min() ?? $medians[$feature],
                'max' => $known->max() ?? $medians[$feature],
            ]];
        });

        return $rows->map(function (array $row) use ($medians, $ranges, $assessmentSource, $seed): array {
            $known = collect($row['values'])->filter(fn ($value) => $value !== null)->count();
            $weighted = 0.0;
            $weightSum = 0;

            foreach (self::WEIGHTS as $feature => $weight) {
                $value = (float) ($row['values'][$feature] ?? $medians[$feature]);
                $range = $ranges[$feature];
                $normalized = $range['max'] > $range['min']
                    ? ($value - $range['min']) / ($range['max'] - $range['min'])
                    : 0.5;
                if ($assessmentSource === 'objective_assessment'
                    && ! in_array($feature, ['height_cm', 'weight_kg', 'experience_years', 'body_type'], true)) {
                    $confidence = (float) ($row['user']->playerObjectiveAssessment?->confidence ?? 0);
                    $normalized = $confidence * $normalized + (1 - $confidence) * 0.5;
                }
                $weighted += $normalized * $weight;
                $weightSum += $weight;
            }

            return [
                'id' => $row['user']->id,
                'name' => trim(($row['user']->profile?->first_name ?? '').' '.($row['user']->profile?->last_name ?? ''))
                    ?: $row['user']->username,
                'username' => $row['user']->username,
                'score' => round($weighted / $weightSum, 4),
                'coverage' => round($known / count(self::WEIGHTS), 4),
                'primary_position' => $row['primary_position'],
                'features' => collect($row['values'])
                    ->map(fn ($value, string $feature): array => [
                        'key' => $feature,
                        'label' => self::FEATURE_LABELS[$feature],
                        'value' => $row['display_values'][$feature],
                        'filled' => $value !== null,
                    ])
                    ->values()
                    ->all(),
                'tie' => hash('sha256', $seed.':'.$row['user']->id),
            ];
        })->sortBy(fn (array $player): string => sprintf('%015.6f-%s', -$player['score'], $player['tie']))->values();
    }

    private function displayFeatureValue(string $feature, mixed $value, mixed $bodyType): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($feature) {
            'height_cm' => (int) $value.' см',
            'weight_kg' => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.').' кг',
            'experience_years' => (int) $value.' лет',
            'body_type' => $bodyType?->label() ?? (string) $value,
            default => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'),
        };
    }
}
