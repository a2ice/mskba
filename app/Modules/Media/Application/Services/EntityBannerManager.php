<?php

namespace App\Modules\Media\Application\Services;

use App\Modules\Media\Domain\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * One independently managed banner per entity. Originals are private and immutable;
 * the public file is an optimized WebP rendering of a normalized selection.
 */
final class EntityBannerManager
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const OUTPUT_WIDTH = 1800;

    public function __construct(private readonly WebpImageNormalizer $images) {}

    /**
     * @param array{x: float, y: float, width: float, height: float} $crop
     */
    public function replace(Model $entity, string $original, array $crop): Media
    {
        $info = @getimagesizefromstring($original);
        $mime = is_array($info) ? ($info['mime'] ?? '') : '';
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException('Баннер должен быть JPEG, PNG или WebP.'),
        };

        if ($original === '' || strlen($original) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Размер изображения не должен превышать 10 МБ.');
        }

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! isset($crop[$key]) || ! is_numeric($crop[$key])) {
                throw new InvalidArgumentException('Некорректная область кадрирования.');
            }
            $crop[$key] = (float) $crop[$key];
        }
        if ($crop['x'] < 0 || $crop['y'] < 0 || $crop['width'] <= 0.015 || $crop['height'] <= 0.015
            || $crop['x'] + $crop['width'] > 1.00001
            || $crop['y'] + $crop['height'] > 1.00001) {
            throw new InvalidArgumentException('Область кадрирования выходит за пределы изображения.');
        }

        $rendered = $this->images->crop($original, $crop, self::OUTPUT_WIDTH);
        $type = $entity->getMorphClass();
        $uuid = (string) Str::uuid();
        $source = "entity-banner-originals/{$type}/{$entity->getKey()}/{$uuid}.{$extension}";
        $published = "entity-banners/{$type}/{$entity->getKey()}/{$uuid}.webp";

        if (! Storage::disk('local')->put($source, $original)) {
            throw new RuntimeException('Не удалось сохранить оригинал баннера.');
        }

        if (! Storage::disk('public')->put($published, $rendered['contents'])) {
            Storage::disk('local')->delete($source);
            throw new RuntimeException('Не удалось сохранить баннер.');
        }

        try {
            return DB::transaction(function () use ($entity, $source, $published, $rendered, $crop): Media {
                $current = $entity->banner()->lockForUpdate()->first();
                if ($current instanceof Media) {
                    $current->delete();
                    $this->deleteFilesAfterCommit($current);
                }

                return $entity->banner()->create([
                    'collection' => 'banner',
                    'source' => 'upload',
                    'disk' => 'public',
                    'path' => $published,
                    'original_disk' => 'local',
                    'original_path' => $source,
                    'crop_applied' => $crop,
                    'mime' => $rendered['mime'],
                    'size' => strlen($rendered['contents']),
                    'width' => $rendered['width'],
                    'height' => $rendered['height'],
                    'is_featured' => true,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($published);
            Storage::disk('local')->delete($source);
            throw $exception;
        }
    }

    /** @param array{x: float, y: float, width: float, height: float} $crop */
    public function recrop(Model $entity, array $crop): Media
    {
        $banner = $entity->banner()->firstOrFail();
        if ($banner->original_disk !== 'local' || ! $banner->original_path) {
            throw new InvalidArgumentException('У этого баннера нет сохранённого оригинала.');
        }

        $original = Storage::disk('local')->get($banner->original_path);
        if (! is_string($original) || $original === '') {
            throw new RuntimeException('Исходное изображение недоступно.');
        }

        return $this->replace($entity, $original, $crop);
    }

    public function remove(Model $entity): void
    {
        DB::transaction(function () use ($entity): void {
            $banner = $entity->banner()->lockForUpdate()->first();
            if ($banner === null) {
                return;
            }
            $banner->delete();
            $this->deleteFilesAfterCommit($banner);
        });
    }

    private function deleteFilesAfterCommit(Media $media): void
    {
        DB::afterCommit(static function () use ($media): void {
            Storage::disk($media->disk)->delete($media->path);
            if ($media->original_path && $media->original_disk) {
                Storage::disk($media->original_disk)->delete($media->original_path);
            }
        });
    }
}
