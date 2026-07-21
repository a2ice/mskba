<?php

namespace Tests\Unit\Media;

use App\Modules\Media\Application\Services\WebpImageNormalizer;
use PHPUnit\Framework\TestCase;

class WebpImageNormalizerTest extends TestCase
{
    public function test_it_physically_applies_jpeg_exif_orientation_before_encoding(): void
    {
        $source = imagecreatetruecolor(40, 80);
        $red = imagecolorallocate($source, 220, 20, 20);
        $blue = imagecolorallocate($source, 20, 20, 220);
        imagefilledrectangle($source, 0, 0, 39, 39, $red);
        imagefilledrectangle($source, 0, 40, 39, 79, $blue);

        ob_start();
        imagejpeg($source, null, 95);
        $jpeg = ob_get_clean();

        self::assertIsString($jpeg);

        $normalized = (new WebpImageNormalizer)->normalize(
            $this->withExifOrientation($jpeg, 6),
        );

        self::assertSame(80, $normalized['width']);
        self::assertSame(40, $normalized['height']);

        $image = imagecreatefromstring($normalized['contents']);
        self::assertNotFalse($image);
        self::assertGreaterThan($this->blue($image, 70, 20), $this->red($image, 70, 20));
        self::assertGreaterThan($this->red($image, 10, 20), $this->blue($image, 10, 20));
    }

    private function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
            .pack('V', 0);
        $exif = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    private function red(\GdImage $image, int $x, int $y): int
    {
        return (imagecolorat($image, $x, $y) >> 16) & 0xFF;
    }

    private function blue(\GdImage $image, int $x, int $y): int
    {
        return imagecolorat($image, $x, $y) & 0xFF;
    }
}
