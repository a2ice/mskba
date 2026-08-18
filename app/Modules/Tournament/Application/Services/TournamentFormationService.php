<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAssessmentSourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

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

    public function __construct(private readonly TournamentAccess $access, private readonly WebpImageNormalizer $normalizer) {}

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
        $teams = collect(range(1, $teamCount))->map(fn (int $number): array => ['number' => $number, 'name' => 'Команда '.$number, 'logo_preset' => sprintf('crest-%02d', ($number - 1) % 15), 'players' => [], 'score' => 0.0, 'unknown' => 0, 'positions' => []])->all();
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
                'name' => $team['name'],
                'logo_preset' => $team['logo_preset'],
                'score' => round($team['score'] / max(1, count($team['players'])), 4),
                'coverage' => round(collect($team['players'])->avg('coverage') ?? 0, 4),
                'players' => collect($team['players'])->map(fn (array $player): array => collect($player)->only(['id', 'name', 'username', 'score', 'coverage', 'primary_position', 'features'])->all())->all(),
            ])->all(),
        ];
    }

    /** @param array<int, array{number:int, name:string, logo_preset:string, logo_contents?:string, user_ids:list<int>}> $teams */
    public function apply(Tournament $tournament, Actor $actor, string $fingerprint, array $teams): void
    {
        $storedPaths = [];
        try {
            DB::transaction(function () use ($tournament, $actor, $fingerprint, $teams, &$storedPaths): void {
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
                $locked->matches()->whereNull('game_id')->lockForUpdate()->get()->each->forceDelete();
                $obsoleteEntries = $locked->entries()
                    ->where('source', TournamentEntrySourceEnum::ASSEMBLED->value)
                    ->with('media')->get();
                foreach ($obsoleteEntries as $obsoleteEntry) {
                    foreach ($obsoleteEntry->media as $media) {
                        $media->delete();
                        DB::afterCommit(fn () => Storage::disk($media->disk)->delete($media->path));
                    }
                    $obsoleteEntry->delete();
                }
                foreach (array_values($teams) as $index => $team) {
                    $entry = $locked->entries()->create(['source' => TournamentEntrySourceEnum::ASSEMBLED, 'name' => trim($team['name']), 'logo_preset' => $team['logo_preset'], 'status' => TournamentEntryStatusEnum::ACTIVE, 'position' => $index + 1]);
                    $entry->members()->createMany(collect($team['user_ids'])->values()->map(fn ($userId, int $position): array => ['user_id' => (int) $userId, 'position' => $position])->all());
                    if (isset($team['logo_contents'])) {
                        $image = $this->normalizer->normalize($team['logo_contents'], 500);
                        $path = sprintf('tournaments/%d/entries/%d/%s.webp', $locked->id, $entry->id, Str::uuid());
                        if (! Storage::disk('public')->put($path, $image['contents'])) {
                            throw new InvalidArgumentException('Не удалось сохранить логотип команды.');
                        }
                        $storedPaths[] = $path;
                        $entry->media()->create(['collection' => 'tournament_entry_logo', 'source' => 'upload', 'disk' => 'public', 'path' => $path, 'mime' => $image['mime'], 'size' => strlen($image['contents']), 'is_featured' => true]);
                    }
                }
                $locked->forceFill(['participant_pool_locked_at' => now()])->save();
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }
    }

    public function disband(Tournament $tournament, Actor $actor): void
    {
        DB::transaction(function () use ($tournament, $actor): void {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            $this->assertFormationAvailable($locked);
            $locked->matches()->whereNull('game_id')->lockForUpdate()->get()->each->forceDelete();
            $entries = $locked->entries()->where('source', TournamentEntrySourceEnum::ASSEMBLED->value)->with('media')->lockForUpdate()->get();
            foreach ($entries as $entry) {
                foreach ($entry->media as $media) {
                    $media->delete();
                    DB::afterCommit(fn () => Storage::disk($media->disk)->delete($media->path));
                }
                $entry->delete();
            }
            $locked->forceFill(['participant_pool_locked_at' => null])->save();
        });
    }

    private function assertFormationAvailable(Tournament $tournament): void
    {
        if ($tournament->recruitment_mode !== TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT || $tournament->format?->sideSize() === 1) {
            throw new InvalidArgumentException('Balanced-формирование доступно только для individual draft 3×3 или 5×5.');
        }
        if ($tournament->matches()->whereNotNull('game_id')->exists()) {
            throw new InvalidArgumentException('После назначения хотя бы одного матча переформировывать команды нельзя.');
        }
    }

    /** @return Collection<int, User> */
    private function pool(Tournament $tournament): Collection
    {
        $ids = $tournament->admissions()->where('status', TournamentAdmissionStatusEnum::ACCEPTED->value)->whereNotNull('user_id')->pluck('user_id');
        $canonicalIds = User::query()->whereKey($ids)->get()
            ->map(fn (User $user): int => (int) $user->canonical()->id)
            ->unique()
            ->values();

        return User::query()->whereKey($canonicalIds)->with(['profile', 'playerProfile.positions', 'playerProfile.selfAssessment', 'playerObjectiveAssessment'])->get();
    }

    private function fingerprint(Tournament $tournament): string
    {
        $values = $tournament->admissions()->where('status', TournamentAdmissionStatusEnum::ACCEPTED->value)->whereNotNull('user_id')->with('user')->orderBy('id')->get()
            ->map(fn ($item): string => $item->id.':'.$item->user?->canonical()->id.':'.$item->updated_at?->format('U.u'))->join('|');

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
