<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ContentCoverManager
{
    public const COLLECTION = 'content_cover';

    public const MAX_OUTPUT_DIMENSION = 1200;

    public function __construct(private readonly WebpImageNormalizer $normalizer) {}

    public function replace(ContentItem $content, string $contents): void
    {
        $image = $this->normalizer->normalize($contents, self::MAX_OUTPUT_DIMENSION);
        $disk = 'public';
        $path = sprintf('content/%d/%s.webp', $content->id, Str::uuid());

        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить обложку материала.');
        }

        try {
            DB::transaction(function () use ($content, $disk, $path, $image): void {
                $oldCovers = $content->media()->where('collection', self::COLLECTION)->lockForUpdate()->get();

                $content->media()->create([
                    'collection' => self::COLLECTION,
                    'source' => 'upload',
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => $image['mime'],
                    'size' => strlen($image['contents']),
                    'is_featured' => true,
                    'sort_order' => 0,
                ]);

                foreach ($oldCovers as $oldCover) {
                    $oldDisk = $oldCover->disk;
                    $oldPath = $oldCover->path;
                    $oldCover->delete();
                    DB::afterCommit(fn () => Storage::disk($oldDisk)->delete($oldPath));
                }
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }
}
