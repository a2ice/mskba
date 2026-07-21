<?php

namespace App\Modules\Media\Application\Services;

use InvalidArgumentException;
use RuntimeException;

final class WebpImageNormalizer
{
    public const MAX_INPUT_BYTES = 5 * 1024 * 1024;

    public const MAX_INPUT_DIMENSION = 6000;

    public const MAX_OUTPUT_DIMENSION = 200;

    /**
     * @return array{contents: string, mime: string, width: int, height: int}
     */
    public function normalize(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('Изображение должно быть не больше 5 МБ.');
        }

        $info = @getimagesizefromstring($contents);

        if ($info === false || ! in_array($info['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException('Поддерживаются только изображения JPEG, PNG и WebP.');
        }

        [$sourceWidth, $sourceHeight] = $info;

        if ($sourceWidth < 1 || $sourceHeight < 1 || $sourceWidth > self::MAX_INPUT_DIMENSION || $sourceHeight > self::MAX_INPUT_DIMENSION) {
            throw new InvalidArgumentException('Размер изображения не должен превышать 6000×6000 пикселей.');
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw new InvalidArgumentException('Не удалось прочитать изображение.');
        }

        $scale = min(1, self::MAX_OUTPUT_DIMENSION / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $target = imagecreatetruecolor($width, $height);

        if ($target === false) {
            imagedestroy($source);
            throw new RuntimeException('Не удалось подготовить изображение.');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        ob_start();
        $encoded = imagewebp($target, null, 82);
        $normalized = ob_get_clean();

        imagedestroy($target);
        imagedestroy($source);

        if (! $encoded || ! is_string($normalized) || $normalized === '') {
            throw new RuntimeException('Не удалось сохранить изображение в WebP.');
        }

        return [
            'contents' => $normalized,
            'mime' => 'image/webp',
            'width' => $width,
            'height' => $height,
        ];
    }
}
