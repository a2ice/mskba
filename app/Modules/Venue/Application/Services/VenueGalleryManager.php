<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class VenueGalleryManager
{
    public const MAX_PHOTOS = 3;

    public const MAX_OUTPUT_DIMENSION = 500;

    public function __construct(
        private readonly WebpImageNormalizer $normalizer,
        private readonly VenueRevisionManager $revisions,
    ) {}

    public function store(Venue $venue, string $contents, ?Actor $actor = null, bool $forcePublished = false): Media
    {
        $image = $this->normalizer->normalize($contents, self::MAX_OUTPUT_DIMENSION);
        $disk = 'public';
        $path = sprintf('venues/%d/%s.webp', $venue->id, Str::uuid());

        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить фотографию площадки.');
        }

        try {
            return DB::transaction(function () use ($venue, $actor, $forcePublished, $disk, $path, $image): Media {
                $lockedVenue = Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();

                if ($lockedVenue->trashed() || ($lockedVenue->status === VenueStatusEnum::BLOCKED && ! $forcePublished)) {
                    throw new InvalidArgumentException('Фотографии этой площадки сейчас нельзя изменять.');
                }

                if ($lockedVenue->hasPendingModerationRequest() && ! $forcePublished) {
                    throw new InvalidArgumentException('Площадка находится на модерации и недоступна для редактирования.');
                }

                if ($lockedVenue->status === VenueStatusEnum::CONFIRMED && ! $forcePublished) {
                    return $this->storeInRevision($lockedVenue, $actor, $disk, $path, $image);
                }

                return $this->storePublished($lockedVenue, $disk, $path, $image);
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function activate(Venue $venue, int $mediaId, ?Actor $actor = null, bool $forcePublished = false): void
    {
        DB::transaction(function () use ($venue, $mediaId, $actor, $forcePublished): void {
            $lockedVenue = Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $this->guardMutable($lockedVenue, $forcePublished);

            if ($lockedVenue->status === VenueStatusEnum::CONFIRMED && ! $forcePublished) {
                $revision = $this->revisions->getOrCreateDraft($lockedVenue, $actor);
                $this->revisions->assertCurrent($revision);
                $payload = $revision->payload;
                $gallery = $this->galleryPayload($payload);
                $position = collect($gallery)->search(fn (array $item): bool => (int) $item['id'] === $mediaId);

                if ($position === false) {
                    $this->notFound($mediaId);
                }

                $selected = $gallery[$position];
                array_splice($gallery, $position, 1);
                array_unshift($gallery, $selected);
                $payload['gallery'] = $this->normalizeGallery($gallery);
                $revision->forceFill(['payload' => $payload])->save();

                return;
            }

            $photos = $lockedVenue->media()->where('collection', 'gallery')->lockForUpdate()->get();
            $selected = $photos->firstWhere('id', $mediaId);
            if (! $selected instanceof Media) {
                $this->notFound($mediaId);
            }
            foreach ($photos as $photo) {
                $photo->forceFill(['is_featured' => $photo->is($selected)])->save();
            }
            $lockedVenue->increment('content_version');
        });
    }

    public function delete(Venue $venue, int $mediaId, ?Actor $actor = null, bool $forcePublished = false): void
    {
        DB::transaction(function () use ($venue, $mediaId, $actor, $forcePublished): void {
            $lockedVenue = Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $this->guardMutable($lockedVenue, $forcePublished);

            if ($lockedVenue->status === VenueStatusEnum::CONFIRMED && ! $forcePublished) {
                $revision = $this->revisions->getOrCreateDraft($lockedVenue, $actor);
                $this->revisions->assertCurrent($revision);
                $payload = $revision->payload;
                $gallery = $this->galleryPayload($payload);
                $position = collect($gallery)->search(fn (array $item): bool => (int) $item['id'] === $mediaId);

                if ($position === false) {
                    $this->notFound($mediaId);
                }

                $removed = $gallery[$position];
                array_splice($gallery, $position, 1);
                $payload['gallery'] = $this->normalizeGallery($gallery);
                $revision->forceFill(['payload' => $payload])->save();

                if (($removed['kind'] ?? null) === 'draft') {
                    $photo = $revision->media()->where('collection', 'gallery')->lockForUpdate()->find($mediaId);
                    if ($photo instanceof Media) {
                        $this->deleteMediaAfterCommit($photo);
                    }
                }

                return;
            }

            $photos = $lockedVenue->media()->where('collection', 'gallery')->lockForUpdate()->orderBy('id')->get();
            $photo = $photos->firstWhere('id', $mediaId);
            if (! $photo instanceof Media) {
                $this->notFound($mediaId);
            }
            $wasFeatured = $photo->is_featured;
            $this->deleteMediaAfterCommit($photo);
            if ($wasFeatured) {
                $photos->first(fn (Media $candidate): bool => ! $candidate->is($photo))
                    ?->forceFill(['is_featured' => true])->save();
            }
            $lockedVenue->increment('content_version');
        });
    }

    /** @return array<int, array{id: int, url: string, is_featured: bool, is_draft: bool}> */
    public function editableGallery(Venue $venue, bool $forcePublished = false): array
    {
        if ($venue->status !== VenueStatusEnum::CONFIRMED || $forcePublished) {
            return $venue->media()
                ->where('collection', 'gallery')
                ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (Media $media): array => $this->mediaPayload($media, false))
                ->all();
        }

        $revision = $this->revisions->draftFor($venue);
        if ($revision === null) {
            return $venue->media()
                ->where('collection', 'gallery')
                ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (Media $media): array => $this->mediaPayload($media, false))
                ->all();
        }

        $published = $venue->media()->where('collection', 'gallery')->get()->keyBy('id');
        $draft = $revision->media()->where('collection', 'gallery')->get()->keyBy('id');

        return collect($this->galleryPayload($revision->payload))
            ->map(function (array $item, int $index) use ($published, $draft): ?array {
                $isDraft = ($item['kind'] ?? null) === 'draft';
                $media = ($isDraft ? $draft : $published)->get((int) $item['id']);

                if (! $media instanceof Media) {
                    return null;
                }

                return array_replace($this->mediaPayload($media, $isDraft), ['is_featured' => $index === 0]);
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $image */
    private function storeInRevision(Venue $venue, ?Actor $actor, string $disk, string $path, array $image): Media
    {
        $revision = $this->revisions->getOrCreateDraft($venue, $actor);
        $this->revisions->assertCurrent($revision);
        $photo = $revision->media()->create($this->mediaAttributes($disk, $path, $image));
        $payload = $revision->payload;
        $gallery = $this->galleryPayload($payload);
        array_unshift($gallery, ['kind' => 'draft', 'id' => $photo->id]);
        $discarded = array_slice($gallery, self::MAX_PHOTOS);
        $payload['gallery'] = $this->normalizeGallery(array_slice($gallery, 0, self::MAX_PHOTOS));
        $revision->forceFill(['payload' => $payload])->save();

        foreach ($discarded as $item) {
            if (($item['kind'] ?? null) === 'draft') {
                $old = $revision->media()->where('collection', 'gallery')->find((int) $item['id']);
                if ($old instanceof Media) {
                    $this->deleteMediaAfterCommit($old);
                }
            }
        }

        return $photo;
    }

    /** @param array<string, mixed> $image */
    private function storePublished(Venue $venue, string $disk, string $path, array $image): Media
    {
        $photos = $venue->media()->where('collection', 'gallery')->lockForUpdate()->latest('id')->get();
        foreach ($photos as $photo) {
            if ($photo->is_featured) {
                $photo->forceFill(['is_featured' => false])->save();
            }
        }
        $photo = $venue->media()->create($this->mediaAttributes($disk, $path, $image) + ['is_featured' => true]);
        foreach ($venue->media()->where('collection', 'gallery')->latest('id')->get()->slice(self::MAX_PHOTOS) as $obsolete) {
            $this->deleteMediaAfterCommit($obsolete);
        }
        $venue->increment('content_version');

        return $photo;
    }

    private function guardMutable(Venue $venue, bool $forcePublished): void
    {
        if ($venue->trashed() || ($venue->status === VenueStatusEnum::BLOCKED && ! $forcePublished)) {
            throw new InvalidArgumentException('Фотографии этой площадки сейчас нельзя изменять.');
        }
        if ($venue->hasPendingModerationRequest() && ! $forcePublished) {
            throw new InvalidArgumentException('Площадка находится на модерации и недоступна для редактирования.');
        }
    }

    /** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
    private function galleryPayload(array $payload): array
    {
        return array_values(array_filter($payload['gallery'] ?? [], fn ($item): bool => is_array($item) && isset($item['id'])));
    }

    /** @param array<int, array<string, mixed>> $gallery @return array<int, array<string, mixed>> */
    private function normalizeGallery(array $gallery): array
    {
        return collect($gallery)->values()->map(fn (array $item, int $index): array => [
            'kind' => ($item['kind'] ?? null) === 'draft' ? 'draft' : 'published',
            'id' => (int) $item['id'],
            'is_featured' => $index === 0,
            'sort_order' => $index,
        ])->all();
    }

    /** @param array<string, mixed> $image @return array<string, mixed> */
    private function mediaAttributes(string $disk, string $path, array $image): array
    {
        return [
            'collection' => 'gallery', 'source' => 'upload', 'disk' => $disk, 'path' => $path,
            'mime' => $image['mime'], 'size' => strlen($image['contents']), 'sort_order' => 0,
        ];
    }

    /** @return array{id: int, url: string, is_featured: bool, is_draft: bool} */
    private function mediaPayload(Media $media, bool $isDraft): array
    {
        return ['id' => (int) $media->id, 'url' => $media->publicUrl(), 'is_featured' => (bool) $media->is_featured, 'is_draft' => $isDraft];
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
