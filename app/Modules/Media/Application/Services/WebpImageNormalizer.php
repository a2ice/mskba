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

        $source = $this->applyExifOrientation($source, $contents, $info['mime']);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $scale = min(1, self::MAX_OUTPUT_DIMENSION / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $target = imagecreatetruecolor($width, $height);

        if ($target === false) {
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

    private function applyExifOrientation(\GdImage $source, string $contents, string $mime): \GdImage
    {
        $orientation = $this->readExifOrientation($contents, $mime);

        if (in_array($orientation, [5, 6], true)) {
            $source = $this->rotate($source, -90);
        } elseif (in_array($orientation, [7, 8], true)) {
            $source = $this->rotate($source, 90);
        } elseif ($orientation === 3) {
            $source = $this->rotate($source, 180);
        }

        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($source, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($source, IMG_FLIP_VERTICAL);
        }

        return $source;
    }

    private function rotate(\GdImage $source, int $degrees): \GdImage
    {
        $transparent = imagecolorallocatealpha($source, 0, 0, 0, 127);
        $rotated = imagerotate($source, $degrees, $transparent);

        if ($rotated === false) {
            throw new RuntimeException('Не удалось повернуть изображение.');
        }

        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function readExifOrientation(string $contents, string $mime): int
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }

        $temporaryFile = tmpfile();

        if ($temporaryFile === false) {
            return 1;
        }

        try {
            if (fwrite($temporaryFile, $contents) === false) {
                return 1;
            }

            $metadata = stream_get_meta_data($temporaryFile);
            $exif = @exif_read_data($metadata['uri'], 'IFD0');
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

            return in_array($orientation, range(1, 8), true) ? $orientation : 1;
        } finally {
            fclose($temporaryFile);
        }
    }
}
