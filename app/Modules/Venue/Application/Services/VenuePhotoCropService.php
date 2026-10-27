<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The original never changes. A draft only stores selection coordinates; apply
 * creates a separate WebP rendition from the original (not from an older crop).
 */
final class VenuePhotoCropService
{
    public function __construct(private readonly WebpImageNormalizer $normalizer) {}

    /** @param array<string, mixed> $selection */
    public function saveDraft(Venue $venue, int $photoId, array $selection): void
    {
        $selection = $this->validatedSelection($selection);

        DB::transaction(function () use ($venue, $photoId, $selection): void {
            $this->photoForUpdate($venue, $photoId)
                ->forceFill(['crop_draft' => $selection])
                ->save();
        });
    }

    public function apply(Venue $venue, int $photoId): void
    {
        $newPath = sprintf('venues/%d/%s.webp', $venue->getKey(), Str::uuid());
        $writtenDisk = null;

        try {
            DB::transaction(function () use ($venue, $photoId, $newPath, &$writtenDisk): void {
                $lockedVenue = Venue::query()->whereKey($venue->getKey())->lockForUpdate()->firstOrFail();
                $photo = $this->photoForUpdate($lockedVenue, $photoId);

                if (! is_array($photo->crop_draft)) {
                    throw ValidationException::withMessages([
                        'crop' => 'Сначала выберите область кадрирования.',
                    ]);
                }

                $selection = $this->validatedSelection($photo->crop_draft);
                $sourcePath = $photo->original_path ?: $photo->path;
                $sourceContents = Storage::disk($photo->disk)->get($sourcePath);
                $image = $this->normalizer->crop(
                    $sourceContents,
                    $selection,
                    VenueGalleryManager::MAX_OUTPUT_DIMENSION,
                );
                $bytes = $image['contents'];
                $outputWidth = $image['width'];
                $outputHeight = $image['height'];

                if (! Storage::disk($photo->disk)->put($newPath, $bytes)) {
                    throw new RuntimeException('Не удалось сохранить кадрированное изображение.');
                }

                $writtenDisk = $photo->disk;
                $previousPath = $photo->path;
                $photo->forceFill([
                    'original_path' => $sourcePath,
                    'path' => $newPath,
                    'mime' => 'image/webp',
                    'size' => strlen($bytes),
                    'width' => $outputWidth,
                    'height' => $outputHeight,
                    'crop_applied' => $selection,
                    'crop_draft' => null,
                ])->save();

                $lockedVenue->increment('content_version');

                if ($previousPath !== $sourcePath && $previousPath !== $newPath) {
                    $disk = $photo->disk;
                    DB::afterCommit(fn () => Storage::disk($disk)->delete($previousPath));
                }
            });
        } catch (Throwable $exception) {
            if ($writtenDisk !== null) {
                Storage::disk($writtenDisk)->delete($newPath);
            }

            throw $exception;
        }
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    private function validatedSelection(array $selection): array
    {
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! array_key_exists($key, $selection) || ! is_numeric($selection[$key]) || ! is_finite((float) $selection[$key])) {
                throw ValidationException::withMessages(['crop' => 'Некорректная область кадрирования.']);
            }
        }

        $x = (float) $selection['x'];
        $y = (float) $selection['y'];
        $width = (float) $selection['width'];
        $height = (float) $selection['height'];

        if ($x < 0 || $y < 0 || $width < 0.02 || $height < 0.02 ||
            $x + $width > 1.000001 || $y + $height > 1.000001) {
            throw ValidationException::withMessages(['crop' => 'Область кадрирования должна находиться внутри фотографии.']);
        }

        return [
            'x' => round($x, 6),
            'y' => round($y, 6),
            'width' => round(min($width, 1 - $x), 6),
            'height' => round(min($height, 1 - $y), 6),
        ];
    }

    private function photoForUpdate(Venue $venue, int $photoId): Media
    {
        return $venue->media()
            ->whereKey($photoId)
            ->where('collection', 'gallery')
            ->lockForUpdate()
            ->firstOrFail();
    }
}
