<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TeamLogoManager
{
    public const COLLECTION = 'team_logo';

    public const MAX_OUTPUT_DIMENSION = 500;

    public function __construct(
        private readonly WebpImageNormalizer $normalizer,
        private readonly TeamManagementAccess $access,
    ) {}

    public function store(Team $team, Actor $actor, string $contents): Media
    {
        $image = $this->normalizer->normalize($contents, self::MAX_OUTPUT_DIMENSION);
        $disk = 'public';
        $path = sprintf('teams/%d/%s.webp', $team->id, Str::uuid());

        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить логотип команды.');
        }

        try {
            return DB::transaction(function () use ($team, $actor, $disk, $path, $image): Media {
                $lockedTeam = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->access->canManage($lockedTeam, $actor), 403);
                $previous = $lockedTeam->media()
                    ->where('collection', self::COLLECTION)
                    ->lockForUpdate()
                    ->get();

                $logo = $lockedTeam->media()->create([
                    'collection' => self::COLLECTION,
                    'source' => 'upload',
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => $image['mime'],
                    'size' => strlen($image['contents']),
                    'is_featured' => true,
                ]);

                foreach ($previous as $media) {
                    $oldDisk = $media->disk;
                    $oldPath = $media->path;
                    $media->delete();
                    DB::afterCommit(fn () => Storage::disk($oldDisk)->delete($oldPath));
                }

                return $logo;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function delete(Team $team, Actor $actor): void
    {
        DB::transaction(function () use ($team, $actor): void {
            $lockedTeam = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->access->canManage($lockedTeam, $actor), 403);
            $logo = $lockedTeam->media()
                ->where('collection', self::COLLECTION)
                ->where('is_featured', true)
                ->lockForUpdate()
                ->first();

            if (! $logo instanceof Media) {
                throw (new ModelNotFoundException)->setModel(Media::class);
            }

            $disk = $logo->disk;
            $path = $logo->path;
            $logo->delete();
            DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
        });
    }
}
