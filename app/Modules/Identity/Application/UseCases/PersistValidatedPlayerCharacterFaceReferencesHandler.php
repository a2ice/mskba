<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PersistValidatedPlayerCharacterFaceReferencesHandler
{
    /**
     * @param array<string, array{contents: string, mime: string, width: int, height: int}> $images
     * @return array<string, array{media: Media, width: int, height: int}>
     */
    public function handle(Profile $profile, array $images): array
    {
        if ($images === []) {
            return [];
        }

        $prepared = [];

        foreach ($images as $slot => $image) {
            PlayerCharacterFaceReferenceOptions::collectionForSlot($slot);

            if (($image['contents'] ?? '') === '' || ($image['mime'] ?? '') !== 'image/webp') {
                throw new InvalidArgumentException('Некорректный подтверждённый reference лица.');
            }

            $prepared[$slot] = [
                ...$image,
                'disk' => 'local',
                'path' => sprintf(
                    'player-character-faces/%d/%s/%s.webp',
                    $profile->id,
                    $slot,
                    Str::uuid(),
                ),
            ];
        }

        $written = [];

        try {
            foreach ($prepared as $slot => $image) {
                if (! Storage::disk($image['disk'])->put($image['path'], $image['contents'])) {
                    throw new RuntimeException('Не удалось сохранить фото лица.');
                }

                $written[$slot] = $image;
            }
        } catch (Throwable $exception) {
            foreach ($written as $image) {
                Storage::disk($image['disk'])->delete($image['path']);
            }

            throw $exception;
        }

        /** @var Collection<int, Media> $obsolete */
        $obsolete = collect();

        try {
            $created = DB::transaction(function () use ($profile, $prepared, &$obsolete): array {
                $lockedProfile = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                $created = [];

                foreach ($prepared as $slot => $image) {
                    $collection = PlayerCharacterFaceReferenceOptions::collectionForSlot($slot);

                    $old = $lockedProfile->media()
                        ->where('collection', $collection)
                        ->lockForUpdate()
                        ->get();

                    $obsolete = $obsolete->concat($old);

                    $media = $lockedProfile->media()->create([
                        'collection' => $collection,
                        'source' => 'upload',
                        'source_reference' => PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE,
                        'disk' => $image['disk'],
                        'path' => $image['path'],
                        'title' => $slot,
                        'mime' => $image['mime'],
                        'size' => strlen($image['contents']),
                        'is_featured' => true,
                    ]);

                    foreach ($old as $oldReference) {
                        $oldReference->forceDelete();
                    }

                    $created[$slot] = [
                        'media' => $media,
                        'width' => $image['width'],
                        'height' => $image['height'],
                    ];
                }

                return $created;
            });
        } catch (Throwable $exception) {
            foreach ($written as $image) {
                Storage::disk($image['disk'])->delete($image['path']);
            }

            throw $exception;
        }

        foreach ($obsolete as $oldReference) {
            Storage::disk($oldReference->disk)->delete($oldReference->path);
        }

        return $created;
    }
}
