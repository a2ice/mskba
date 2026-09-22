<?php

namespace App\Modules\Ai\Infrastructure\Images;

use GdImage;

final class YandexGreenBackgroundRemover
{
    public function remove(
        string $contents,
        string $targetHex = '#00FF00',
        int $threshold = 110,
        int $greenDominance = 35,
    ): ?string {
        $image = @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            return null;
        }

        $mask = null;

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width < 1 || $height < 1 || ($width * $height) > 8_000_000) {
                return null;
            }

            imagealphablending($image, false);
            imagesavealpha($image, true);

            $mask = imagecreatetruecolor($width, $height);
            if (! $mask instanceof GdImage) {
                return null;
            }

            $foreground = imagecolorallocate($mask, 0, 0, 0);
            $candidate = imagecolorallocate($mask, 255, 255, 255);
            $connectedBackground = imagecolorallocate($mask, 255, 0, 0);

            $target = $this->parseHexColor($targetHex);
            $thresholdSquared = max(0, $threshold) ** 2;
            $dominance = max(0, $greenDominance);

            imagefill($mask, 0, 0, $foreground);

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $rgba = imagecolorat($image, $x, $y);
                    $red = ($rgba >> 16) & 0xFF;
                    $green = ($rgba >> 8) & 0xFF;
                    $blue = $rgba & 0xFF;

                    if ($this->isBackgroundCandidate(
                        $red,
                        $green,
                        $blue,
                        $target,
                        $thresholdSquared,
                        $dominance,
                    )) {
                        imagesetpixel($mask, $x, $y, $candidate);
                    }
                }
            }

            // Only candidate pixels connected to an image edge are background.
            // A green detail fully enclosed by the player remains untouched.
            for ($x = 0; $x < $width; $x++) {
                $this->fillCandidateRegion($mask, $x, 0, $candidate, $connectedBackground);
                $this->fillCandidateRegion($mask, $x, $height - 1, $candidate, $connectedBackground);
            }

            for ($y = 0; $y < $height; $y++) {
                $this->fillCandidateRegion($mask, 0, $y, $candidate, $connectedBackground);
                $this->fillCandidateRegion($mask, $width - 1, $y, $candidate, $connectedBackground);
            }

            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            $removedPixels = 0;

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    if (imagecolorat($mask, $x, $y) !== $connectedBackground) {
                        continue;
                    }

                    imagesetpixel($image, $x, $y, $transparent);
                    $removedPixels++;
                }
            }

            if ($removedPixels === 0) {
                return null;
            }

            ob_start();
            $encoded = imagepng($image, null, 6);
            $png = ob_get_clean();

            return $encoded && is_string($png) && $png !== ''
                ? $png
                : null;
        } finally {
            if ($mask instanceof GdImage) {
                imagedestroy($mask);
            }

            imagedestroy($image);
        }
    }

    /**
     * @param array{0:int,1:int,2:int} $target
     */
    private function isBackgroundCandidate(
        int $red,
        int $green,
        int $blue,
        array $target,
        int $thresholdSquared,
        int $greenDominance,
    ): bool {
        $distanceSquared =
            (($red - $target[0]) ** 2)
            + (($green - $target[1]) ** 2)
            + (($blue - $target[2]) ** 2);

        if ($distanceSquared <= $thresholdSquared) {
            return true;
        }

        return $green >= 120
            && ($green - $red) >= $greenDominance
            && ($green - $blue) >= $greenDominance;
    }

    private function fillCandidateRegion(
        GdImage $mask,
        int $x,
        int $y,
        int $candidate,
        int $connectedBackground,
    ): void {
        if (imagecolorat($mask, $x, $y) === $candidate) {
            imagefill($mask, $x, $y, $connectedBackground);
        }
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function parseHexColor(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (! preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            return [0, 255, 0];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
