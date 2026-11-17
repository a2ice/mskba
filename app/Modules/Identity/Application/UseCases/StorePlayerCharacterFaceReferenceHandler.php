<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use InvalidArgumentException;

final class StorePlayerCharacterFaceReferenceHandler
{
    public function __construct(
        private readonly WebpImageNormalizer $normalizer,
        private readonly PersistValidatedPlayerCharacterFaceReferencesHandler $persister,
    ) {}

    /**
     * @return array{media: \App\Modules\Media\Domain\Models\Media, width: int, height: int}
     */
    public function handle(Profile $profile, string $slot, string $contents): array
    {
        $image = $this->normalizer->normalize(
            $contents,
            PlayerCharacterFaceReferenceOptions::MAX_OUTPUT_DIMENSION,
        );

        // Uploading a reference is free, local profile storage and must never
        // depend on the configured generation AI gateway or consume credits.
        // The normalizer already validates the image data and MIME type.
        // The angle is NOT verified here: that requires a dedicated vision
        // check, not the GitHub generation provider's size-only validator.
        if ($image['width'] < 128 || $image['height'] < 128) {
            throw new InvalidArgumentException(
                'Фотография слишком маленькая. Загрузите изображение не менее 128×128 пикселей.',
            );
        }

        return $this->persister->handle($profile, [$slot => $image])[$slot];
    }
}
