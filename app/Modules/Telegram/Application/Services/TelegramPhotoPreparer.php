<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Media\Domain\Models\Media;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class TelegramPhotoPreparer
{
    /**
     * Telegram Bot API does not accept WebP as a regular photo.
     *
     * @return array{contents: string, filename: string, mime: string}
     */
    public function jpeg(Media $media): array
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            throw new RuntimeException('Подготовка изображения для Telegram временно недоступна.');
        }

        $contents = Storage::disk($media->disk)->get($media->path);
        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw new RuntimeException('Не удалось прочитать обложку материала.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $target = imagecreatetruecolor($width, $height);

        if ($target === false) {
            imagedestroy($source);

            throw new RuntimeException('Не удалось подготовить обложку для Telegram.');
        }

        $background = imagecolorallocate($target, 255, 255, 255);
        imagefilledrectangle($target, 0, 0, $width, $height, $background);
        imagealphablending($target, true);
        imagecopy($target, $source, 0, 0, 0, 0, $width, $height);

        ob_start();
        $encoded = imagejpeg($target, null, 88);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        if (! $encoded || ! is_string($jpeg) || $jpeg === '') {
            throw new RuntimeException('Не удалось преобразовать обложку для Telegram.');
        }

        return [
            'contents' => $jpeg,
            'filename' => sprintf('content-%d.jpg', $media->mediable_id),
            'mime' => 'image/jpeg',
        ];
    }
}
