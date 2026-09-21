<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Identity\Domain\Exceptions\PlayerCharacterFlowException;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StorePlayerCharacterFaceReferenceHandler
{
    public function __construct(
        private readonly WebpImageNormalizer $normalizer,
        private readonly PlayerCharacterAiGateway $ai,
    ) {}

    /**
     * @return array{media: Media, width: int, height: int}
     */
    public function handle(Profile $profile, string $slot, string $contents): array
    {
        $collection = PlayerCharacterFaceReferenceOptions::collectionForSlot($slot);
        $image = $this->normalizer->normalize(
            $contents,
            PlayerCharacterFaceReferenceOptions::MAX_OUTPUT_DIMENSION,
        );

        // The normalized image exists only in memory until AI confirms that the
        // uploaded face matches the requested slot. Failed validation must not
        // create Media rows or permanent files.
        $validation = $this->ai->validateFaceReference(
            $slot,
            $image['contents'],
            $image['mime'],
        );

        if (! $validation->valid) {
            $expectedLabel = PlayerCharacterFaceReferenceOptions::labels()[$slot] ?? $slot;
            throw new PlayerCharacterFlowException(
                'face_reference_invalid',
                $validation->reason ?: 'Фото не соответствует ракурсу «'.$expectedLabel.'».',
                422,
                [
                    'expected_slot' => $slot,
                    'detected_slot' => $validation->detectedSlot,
                ],
            );
        }

        $disk = 'local';
        $path = sprintf(
            'player-character-faces/%d/%s/%s.webp',
            $profile->id,
            $slot,
            Str::uuid(),
        );

        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить фото лица.');
        }

        $obsolete = collect();

        try {
            $media = DB::transaction(function () use (
                $profile,
                $collection,
                $slot,
                $disk,
                $path,
                $image,
                &$obsolete,
            ): Media {
                $lockedProfile = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

                $obsolete = $lockedProfile->media()
                    ->where('collection', $collection)
                    ->lockForUpdate()
                    ->get();

                $media = $lockedProfile->media()->create([
                    'collection' => $collection,
                    'source' => 'upload',
                    'source_reference' => PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE,
                    'disk' => $disk,
                    'path' => $path,
                    'title' => $slot,
                    'mime' => $image['mime'],
                    'size' => strlen($image['contents']),
                    'is_featured' => true,
                ]);

                foreach ($obsolete as $oldReference) {
                    $oldReference->forceDelete();
                }

                return $media;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        foreach ($obsolete as $oldReference) {
            Storage::disk($oldReference->disk)->delete($oldReference->path);
        }

        return [
            'media' => $media,
            'width' => $image['width'],
            'height' => $image['height'],
        ];
    }
}
