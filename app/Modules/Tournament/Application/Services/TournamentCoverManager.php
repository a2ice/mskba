<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TournamentCoverManager
{
    public function __construct(
        private readonly WebpImageNormalizer $normalizer,
        private readonly TournamentAccess $access,
    ) {}

    public function replace(Tournament $tournament, Actor $actor, string $contents): void
    {
        $image = $this->normalizer->normalize($contents, 1600);
        $disk = 'public';
        $path = sprintf('tournaments/%d/%s.webp', $tournament->id, Str::uuid());
        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить обложку турнира.');
        }

        try {
            DB::transaction(function () use ($tournament, $actor, $disk, $path, $image): void {
                $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
                $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_DESCRIPTION);
                $previous = $locked->media()->where('collection', 'tournament_cover')->lockForUpdate()->get();
                $locked->media()->create([
                    'collection' => 'tournament_cover',
                    'source' => 'upload',
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => $image['mime'],
                    'size' => strlen($image['contents']),
                    'is_featured' => true,
                ]);
                foreach ($previous as $cover) {
                    $oldDisk = $cover->disk;
                    $oldPath = $cover->path;
                    $cover->delete();
                    DB::afterCommit(fn () => Storage::disk($oldDisk)->delete($oldPath));
                }
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }
}
