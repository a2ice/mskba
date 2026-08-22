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
use App\Support\Basketball\BalancedTeamFormationEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class TournamentFormationService
{
    public function __construct(
        private readonly TournamentAccess $access,
        private readonly WebpImageNormalizer $normalizer,
        private readonly BalancedTeamFormationEngine $balanced,
    ) {}

    /** @return array<string, mixed> */
    public function preview(
        Tournament $tournament,
        Actor $actor,
        TournamentAssessmentSourceEnum $source,
        int $teamCount,
        int $seed,
    ): array {
        $this->access->assertAllows($tournament, $actor, TournamentPermissionEnum::MANAGE_GAMES);
        $this->assertFormationAvailable($tournament);
        $users = $this->pool($tournament);
        $sideSize = $tournament->format?->sideSize()
            ?? throw new InvalidArgumentException('Для формирования команд нужно указать формат турнира.');
        if ($teamCount < 2 || $users->count() < $teamCount * $sideSize) {
            throw new InvalidArgumentException(
                "Для {$teamCount} команд нужно не меньше ".($teamCount * $sideSize).' подтверждённых игроков.',
            );
        }

        return [
            ...$this->balanced->build($users, $source->value, $teamCount, $seed),
            'pool_fingerprint' => $this->fingerprint($tournament),
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
                $assigned = collect($teams)
                    ->flatMap(fn (array $team) => $team['user_ids'])
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                if (count($assigned) !== count(array_unique($assigned))
                    || collect($assigned)->sort()->values()->all() !== $poolIds) {
                    throw new InvalidArgumentException('Каждый подтверждённый игрок должен входить ровно в одну команду.');
                }
                $minimum = $locked->format?->sideSize() ?? 1;
                if (count($teams) < 2
                    || collect($teams)->contains(fn (array $team): bool => count($team['user_ids']) < $minimum)) {
                    throw new InvalidArgumentException('В каждой команде должно быть не меньше игроков, чем требует формат.');
                }

                $locked->matches()->whereNull('game_id')->lockForUpdate()->get()->each->forceDelete();
                $obsoleteEntries = $locked->entries()
                    ->where('source', TournamentEntrySourceEnum::ASSEMBLED->value)
                    ->with('media')
                    ->get();
                foreach ($obsoleteEntries as $obsoleteEntry) {
                    foreach ($obsoleteEntry->media as $media) {
                        $media->delete();
                        DB::afterCommit(fn () => Storage::disk($media->disk)->delete($media->path));
                    }
                    $obsoleteEntry->delete();
                }

                foreach (array_values($teams) as $index => $team) {
                    $entry = $locked->entries()->create([
                        'source' => TournamentEntrySourceEnum::ASSEMBLED,
                        'name' => trim($team['name']),
                        'logo_preset' => $team['logo_preset'],
                        'status' => TournamentEntryStatusEnum::ACTIVE,
                        'position' => $index + 1,
                    ]);
                    $entry->members()->createMany(
                        collect($team['user_ids'])->values()->map(fn ($userId, int $position): array => [
                            'user_id' => (int) $userId,
                            'position' => $position,
                        ])->all(),
                    );
                    if (isset($team['logo_contents'])) {
                        $image = $this->normalizer->normalize($team['logo_contents'], 500);
                        $path = sprintf('tournaments/%d/entries/%d/%s.webp', $locked->id, $entry->id, Str::uuid());
                        if (! Storage::disk('public')->put($path, $image['contents'])) {
                            throw new InvalidArgumentException('Не удалось сохранить логотип команды.');
                        }
                        $storedPaths[] = $path;
                        $entry->media()->create([
                            'collection' => 'tournament_entry_logo',
                            'source' => 'upload',
                            'disk' => 'public',
                            'path' => $path,
                            'mime' => $image['mime'],
                            'size' => strlen($image['contents']),
                            'is_featured' => true,
                        ]);
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
            $entries = $locked->entries()
                ->where('source', TournamentEntrySourceEnum::ASSEMBLED->value)
                ->with('media')
                ->lockForUpdate()
                ->get();
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
        if ($tournament->recruitment_mode !== TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT
            || $tournament->format?->sideSize() === 1) {
            throw new InvalidArgumentException(
                'Balanced-формирование доступно только для individual draft 3×3 или 5×5.',
            );
        }
        if ($tournament->matches()->whereNotNull('game_id')->exists()) {
            throw new InvalidArgumentException('После назначения хотя бы одного матча переформировывать команды нельзя.');
        }
    }

    /** @return Collection<int, User> */
    private function pool(Tournament $tournament): Collection
    {
        $ids = $tournament->admissions()
            ->where('status', TournamentAdmissionStatusEnum::ACCEPTED->value)
            ->whereNotNull('user_id')
            ->pluck('user_id');
        $canonicalIds = User::query()->whereKey($ids)->get()
            ->map(fn (User $user): int => (int) $user->canonical()->id)
            ->unique()
            ->values();

        return User::query()->whereKey($canonicalIds)
            ->with(['profile', 'playerProfile.positions', 'playerProfile.selfAssessment', 'playerObjectiveAssessment'])
            ->get();
    }

    private function fingerprint(Tournament $tournament): string
    {
        $values = $tournament->admissions()
            ->where('status', TournamentAdmissionStatusEnum::ACCEPTED->value)
            ->whereNotNull('user_id')
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn ($item): string => $item->id.':'.$item->user?->canonical()->id.':'.$item->updated_at?->format('U.u'))
            ->join('|');

        return hash(
            'sha256',
            BalancedTeamFormationEngine::FORMULA_VERSION.'|'.$tournament->id.'|'.$values,
        );
    }
}
