<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EventResultGalleryManager
{
    public const COLLECTION = 'event_results';

    public const MAX_PHOTOS = 12;

    public const MAX_OUTPUT_DIMENSION = 1200;

    public const MAX_INPUT_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly WebpImageNormalizer $normalizer,
        private readonly EventManagementAccess $access,
    ) {}

    public function store(Event $event, Actor $actor, string $contents): Media
    {
        $image = $this->normalizer->normalize($contents, self::MAX_OUTPUT_DIMENSION, self::MAX_INPUT_BYTES);
        $disk = 'public';
        $path = sprintf('events/%d/%s.webp', $event->id, Str::uuid());

        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить фотографию мероприятия.');
        }

        try {
            return DB::transaction(function () use ($event, $actor, $disk, $path, $image): Media {
                $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $this->access->assertAllows($lockedEvent, $actor, EventResponsibilityPermissionEnum::MANAGE_RESULT);

                if ($lockedEvent->status !== EventStatusEnum::COMPLETED) {
                    throw new InvalidArgumentException('Фотографии результата можно добавить после завершения мероприятия.');
                }

                $photos = $lockedEvent->media()->where('collection', self::COLLECTION)->lockForUpdate()->get();
                if ($photos->count() >= self::MAX_PHOTOS) {
                    throw new InvalidArgumentException('Можно добавить не больше '.self::MAX_PHOTOS.' фотографий.');
                }

                return $lockedEvent->media()->create([
                    'collection' => self::COLLECTION,
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

    public function delete(Event $event, Actor $actor, int $mediaId): void
    {
        DB::transaction(function () use ($event, $actor, $mediaId): void {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($lockedEvent, $actor, EventResponsibilityPermissionEnum::MANAGE_RESULT);
            $photo = $lockedEvent->media()
                ->where('collection', self::COLLECTION)
                ->lockForUpdate()
                ->find($mediaId);

            if (! $photo instanceof Media) {
                throw (new ModelNotFoundException)->setModel(Media::class, [$mediaId]);
            }

            $disk = $photo->disk;
            $path = $photo->path;
            $photo->eventResultPhotoTags()->delete();
            $photo->delete();
            DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
        });
    }

    /**
     * @param  array<int, array{user_id: int, x: float|int|string, y: float|int|string}>  $tags
     */
    public function updateMetadata(Event $event, Actor $actor, int $mediaId, ?string $description, array $tags): Media
    {
        return DB::transaction(function () use ($event, $actor, $mediaId, $description, $tags): Media {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($lockedEvent, $actor, EventResponsibilityPermissionEnum::MANAGE_RESULT);

            if ($lockedEvent->status !== EventStatusEnum::COMPLETED) {
                throw new InvalidArgumentException('Оформить фотографии можно после завершения мероприятия.');
            }

            $photo = $lockedEvent->media()
                ->where('collection', self::COLLECTION)
                ->lockForUpdate()
                ->find($mediaId);

            if (! $photo instanceof Media) {
                throw (new ModelNotFoundException)->setModel(Media::class, [$mediaId]);
            }

            $participantUserIds = $lockedEvent->participants()->pluck('user_id')->map(fn ($id): int => (int) $id);
            $invalidUserId = collect($tags)
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->first(fn (int $userId): bool => ! $participantUserIds->contains($userId));

            if ($invalidUserId !== null) {
                throw new InvalidArgumentException('На фотографии можно отметить только участников мероприятия.');
            }

            $photo->update(['description' => filled($description) ? trim($description) : null]);
            $photo->eventResultPhotoTags()->delete();
            $photo->eventResultPhotoTags()->createMany(collect($tags)->map(fn (array $tag): array => [
                'user_id' => (int) $tag['user_id'],
                'position_x' => round((float) $tag['x'], 2),
                'position_y' => round((float) $tag['y'], 2),
            ])->all());

            return $photo->load('eventResultPhotoTags.user.profile');
        });
    }
}
