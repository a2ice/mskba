<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ContentInlineImageManager
{
    public const COLLECTION = 'content_inline';

    public const MAX_OUTPUT_DIMENSION = 1800;

    public function __construct(private readonly WebpImageNormalizer $normalizer) {}

    public function store(ContentItem $content, string $contents, ?string $title = null, ?string $description = null): Media
    {
        $image = $this->normalizer->normalize($contents, self::MAX_OUTPUT_DIMENSION);
        $disk = 'public';
        $path = sprintf('content/%d/inline/%s.webp', $content->id, Str::uuid());

        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить изображение материала.');
        }

        try {
            return DB::transaction(function () use ($content, $disk, $path, $image, $title, $description): Media {
                $sortOrder = ((int) $content->media()
                    ->where('collection', self::COLLECTION)
                    ->max('sort_order')) + 10;

                return $content->media()->create([
                    'collection' => self::COLLECTION,
                    'source' => 'upload',
                    'disk' => $disk,
                    'path' => $path,
                    'title' => filled($title) ? trim((string) $title) : null,
                    'description' => filled($description) ? trim((string) $description) : null,
                    'mime' => $image['mime'],
                    'size' => strlen($image['contents']),
                    'is_featured' => false,
                    'sort_order' => $sortOrder,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function delete(ContentItem $content, Media $media): void
    {
        if ($media->mediable_type !== $content->getMorphClass()
            || (int) $media->mediable_id !== (int) $content->id
            || $media->collection !== self::COLLECTION) {
            abort(404);
        }

        $disk = $media->disk;
        $path = $media->path;

        DB::transaction(function () use ($media, $disk, $path): void {
            $media->delete();
            DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
        });
    }
}
