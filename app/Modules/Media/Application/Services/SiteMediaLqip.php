<?php

namespace App\Modules\Media\Application\Services;

use App\Modules\Media\Domain\Models\Media;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Disposable, version-scoped low-quality previews for Site Media Library.
 * Never inserts a Media version or changes current/previous pointers.
 */
final class SiteMediaLqip
{
    public const MAX_BYTES = 10 * 1024;
    public const DIMENSIONS = [96, 80, 64, 48, 32];

    public function __construct(private readonly WebpImageNormalizer $normalizer) {}

    public static function path(Media $media): string
    {
        $dirname = dirname($media->path);
        $stem = pathinfo($media->path, PATHINFO_FILENAME);

        return $dirname.'/'.$stem.'.lqip.webp';
    }

    public function exists(Media $media): bool
    {
        return $media->disk === 'public' && Storage::disk('public')->exists(self::path($media));
    }

    /**
     * Generate a transparent WebP derivative with the same orientation/ratio.
     * Returns false for unsupported or missing files. Throw on storage/write
     * failures so manual processing can surface them.
     */
    public function generate(Media $media, bool $force = false): bool
    {
        if ($media->disk !== 'public' || ! in_array($media->mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return false;
        }

        $disk = Storage::disk('public');
        $path = self::path($media);
        if (! $force && $disk->exists($path)) {
            return true;
        }
        if (! $disk->exists($media->path)) {
            return false;
        }

        $bytes = $disk->get($media->path);
        if (! is_string($bytes) || $bytes === '') {
            return false;
        }

        // WebpImageNormalizer handles EXIF orientation and transparent alpha,
        // avoiding a different silhouette from the full-size image.
        $preview = null;
        foreach (self::DIMENSIONS as $dimension) {
            $encoded = $this->normalizer->normalize(
                $bytes, $dimension, SiteMediaManager::MAX_BYTES, 32,
            );
            $preview = $encoded['contents'];
            if (strlen($preview) <= self::MAX_BYTES) {
                break;
            }
        }

        if (! is_string($preview) || $preview === '' || strlen($preview) > self::MAX_BYTES) {
            throw new RuntimeException('Не удалось создать LQIP меньше 10 КБ.');
        }
        if (! $disk->put($path, $preview)) {
            throw new RuntimeException('Не удалось сохранить LQIP.');
        }

        return true;
    }

    public function delete(Media $media): void
    {
        if ($media->disk === 'public') {
            Storage::disk('public')->delete(self::path($media));
        }
    }
}
