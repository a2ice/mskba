<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class VenueCourtGalleryManager
{
    public const MAX_PHOTOS = 3;

    public const MAX_OUTPUT_DIMENSION = 500;

    public function __construct(private readonly WebpImageNormalizer $normalizer) {}

    public function store(VenueCourt $court, string $contents): Media
    {
        $image = $this->normalizer->normalize($contents, self::MAX_OUTPUT_DIMENSION);
        $disk = 'public';
        $path = sprintf('venues/%d/courts/%d/%s.webp', $court->venue_id, $court->id, Str::uuid());

        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить фотографию зала.');
        }

        try {
            return DB::transaction(function () use ($court, $disk, $path, $image): Media {
                $lockedCourt = VenueCourt::query()->with('venue')->whereKey($court->id)->lockForUpdate()->firstOrFail();
                $this->guardMutable($lockedCourt);

                $photos = $lockedCourt->media()
                    ->where('collection', 'gallery')
                    ->lockForUpdate()
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                foreach ($photos as $photo) {
                    if ($photo->is_featured) {
                        $photo->forceFill(['is_featured' => false])->save();
                    }
                }

                $photo = $lockedCourt->media()->create([
                    'collection' => 'gallery',
                    'source' => 'upload',
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => $image['mime'],
                    'size' => strlen($image['contents']),
                    'is_featured' => true,
                    'sort_order' => 0,
                ]);

                foreach ($lockedCourt->media()
                    ->where('collection', 'gallery')
                    ->latest('id')
                    ->get()
                    ->slice(self::MAX_PHOTOS) as $obsolete) {
                    $this->deleteMediaAfterCommit($obsolete);
                }

                return $photo;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function activate(VenueCourt $court, int $mediaId): void
    {
        DB::transaction(function () use ($court, $mediaId): void {
            $lockedCourt = VenueCourt::query()->with('venue')->whereKey($court->id)->lockForUpdate()->firstOrFail();
            $this->guardMutable($lockedCourt);
            $photos = $lockedCourt->media()->where('collection', 'gallery')->lockForUpdate()->get();
            $selected = $photos->firstWhere('id', $mediaId);

            if (! $selected instanceof Media) {
                $this->notFound($mediaId);
            }

            foreach ($photos as $photo) {
                $photo->forceFill(['is_featured' => $photo->is($selected)])->save();
            }
        });
    }

    public function delete(VenueCourt $court, int $mediaId): void
    {
        DB::transaction(function () use ($court, $mediaId): void {
            $lockedCourt = VenueCourt::query()->with('venue')->whereKey($court->id)->lockForUpdate()->firstOrFail();
            $this->guardMutable($lockedCourt);
            $photos = $lockedCourt->media()
                ->where('collection', 'gallery')
                ->lockForUpdate()
                ->orderByDesc('is_featured')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            $photo = $photos->firstWhere('id', $mediaId);

            if (! $photo instanceof Media) {
                $this->notFound($mediaId);
            }

            $wasFeatured = (bool) $photo->is_featured;
            $this->deleteMediaAfterCommit($photo);

            if ($wasFeatured) {
                $photos->first(fn (Media $candidate): bool => ! $candidate->is($photo))
                    ?->forceFill(['is_featured' => true])->save();
            }
        });
    }

    /** @return array<int, array{id: int, url: string, is_featured: bool}> */
    public function gallery(VenueCourt $court): array
    {
        return $court->media()
            ->where('collection', 'gallery')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Media $media): array => [
                'id' => (int) $media->id,
                'url' => $media->publicUrl(),
                'is_featured' => (bool) $media->is_featured,
            ])
            ->all();
    }

    private function guardMutable(VenueCourt $court): void
    {
        if ($court->trashed() || $court->venue === null || $court->venue->trashed() || $court->venue->status === VenueStatusEnum::BLOCKED) {
            throw new InvalidArgumentException('Фотографии этого зала сейчас нельзя изменять.');
        }
    }

    private function deleteMediaAfterCommit(Media $media): void
    {
        $disk = $media->disk;
        $path = $media->path;
        $media->delete();
        DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
    }

    private function notFound(int $id): never
    {
        throw (new ModelNotFoundException)->setModel(Media::class, [$id]);
    }
}
