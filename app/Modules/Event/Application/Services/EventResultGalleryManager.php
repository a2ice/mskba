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

    public function __construct(
        private readonly WebpImageNormalizer $normalizer,
        private readonly EventManagementAccess $access,
    ) {}

    public function store(Event $event, Actor $actor, string $contents): Media
    {
        $image = $this->normalizer->normalize($contents, self::MAX_OUTPUT_DIMENSION);
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
            $photo->delete();
            DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
        });
    }
}
