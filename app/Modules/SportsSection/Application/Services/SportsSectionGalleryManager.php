<?php

namespace App\Modules\SportsSection\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class SportsSectionGalleryManager
{
    public const MAX_PHOTOS = 10;

    public const MAX_OUTPUT_DIMENSION = 1200;

    public function __construct(private WebpImageNormalizer $normalizer, private SportsSectionAccess $access) {}

    public function store(SportsSection $section, User $issuer, string $contents): Media
    {
        $this->authorize($section, $issuer);
        $image = $this->normalizer->normalize($contents, self::MAX_OUTPUT_DIMENSION);
        $disk = 'public';
        $path = sprintf('sports-sections/%d/%s.webp', $section->id, Str::uuid());
        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить фотографию секции.');
        }
        try {
            return DB::transaction(function () use ($section, $issuer, $image, $disk, $path): Media {
                $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
                $this->authorize($section, $issuer);
                $photos = $section->media()->where('collection', 'sports_section_gallery')->lockForUpdate()->get();
                if ($photos->count() >= self::MAX_PHOTOS) {
                    throw new SportsSectionException('В галерее секции может быть не более 10 фотографий.');
                }

                return $section->media()->create([
                    'collection' => 'sports_section_gallery',
                    'source' => 'upload',
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => $image['mime'],
                    'size' => strlen($image['contents']),
                    'is_featured' => $photos->isEmpty(),
                    'sort_order' => $photos->count(),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function feature(SportsSection $section, User $issuer, int $mediaId): void
    {
        DB::transaction(function () use ($section, $issuer, $mediaId): void {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $this->authorize($section, $issuer);
            $photos = $section->media()->where('collection', 'sports_section_gallery')->lockForUpdate()->get();
            $selected = $photos->firstWhere('id', $mediaId);
            if (! $selected instanceof Media) {
                throw (new ModelNotFoundException)->setModel(Media::class, [$mediaId]);
            }
            foreach ($photos as $photo) {
                $photo->forceFill(['is_featured' => $photo->is($selected)])->save();
            }
        });
    }

    public function delete(SportsSection $section, User $issuer, int $mediaId): void
    {
        DB::transaction(function () use ($section, $issuer, $mediaId): void {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $this->authorize($section, $issuer);
            $photos = $section->media()->where('collection', 'sports_section_gallery')->lockForUpdate()->orderBy('id')->get();
            $photo = $photos->firstWhere('id', $mediaId);
            if (! $photo instanceof Media) {
                throw (new ModelNotFoundException)->setModel(Media::class, [$mediaId]);
            }
            $disk = $photo->disk;
            $path = $photo->path;
            $wasFeatured = $photo->is_featured;
            $photo->delete();
            if ($wasFeatured) {
                $photos->first(fn (Media $candidate): bool => ! $candidate->is($photo))?->update(['is_featured' => true]);
            }
            DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
        });
    }

    private function authorize(SportsSection $section, User $issuer): void
    {
        if (! $this->access->allows($issuer->canonical(), $section, SportsSectionPermissionEnum::MANAGE)) {
            throw new SportsSectionException('Недостаточно прав для управления фотографиями секции.');
        }
    }
}
