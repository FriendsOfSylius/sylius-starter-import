<?php

declare(strict_types=1);

namespace Unit\Import;

use PHPUnit\Framework\TestCase;

use function Castor\Sylius\Import\convert_image_to_webp;

require_once \dirname(__DIR__, 3) . '/src/Import/constants.php';
require_once \dirname(__DIR__, 3) . '/src/Import/Images/download.php';

final class ImageConversionTest extends TestCase
{
    public function testConvertsJpegToWebp(): void
    {
        if (!\function_exists('imagewebp') || !\function_exists('imagejpeg')) {
            static::markTestSkipped('GD with JPEG and WebP support is required.');
        }

        $directory = sys_get_temp_dir() . '/castor-sylius-webp-' . uniqid('', true);
        static::assertTrue(mkdir($directory, 0o775, true));

        $source = $directory . '/source.jpg';
        $destination = $directory . '/out.webp';

        try {
            $image = imagecreatetruecolor(4, 4);
            static::assertNotFalse($image);
            imagefilledrectangle($image, 0, 0, 3, 3, imagecolorallocate($image, 200, 40, 40));
            static::assertTrue(imagejpeg($image, $source, 80));
            imagedestroy($image);

            static::assertTrue(convert_image_to_webp($source, $destination));
            static::assertFileExists($destination);
            static::assertGreaterThan(0, filesize($destination));
        } finally {
            foreach ([$source, $destination] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testDownscalesLargeJpegBeforeWebpConversion(): void
    {
        if (!\function_exists('imagewebp') || !\function_exists('imagejpeg') || !\function_exists('imagecreatefromwebp')) {
            static::markTestSkipped('GD with JPEG and WebP support is required.');
        }

        $directory = sys_get_temp_dir() . '/castor-sylius-webp-' . uniqid('', true);
        static::assertTrue(mkdir($directory, 0o775, true));

        $source = $directory . '/large.jpg';
        $destination = $directory . '/large.webp';

        try {
            $image = imagecreatetruecolor(3000, 2000);
            static::assertNotFalse($image);
            imagefilledrectangle($image, 0, 0, 2999, 1999, imagecolorallocate($image, 20, 120, 200));
            static::assertTrue(imagejpeg($image, $source, 85));
            imagedestroy($image);

            static::assertTrue(convert_image_to_webp($source, $destination));

            $webp = imagecreatefromwebp($destination);
            static::assertNotFalse($webp);

            $width = imagesx($webp);
            $height = imagesy($webp);
            imagedestroy($webp);

            static::assertNotFalse($width);
            static::assertNotFalse($height);
            static::assertLessThanOrEqual(2048, $width);
            static::assertLessThanOrEqual(2048, $height);
        } finally {
            foreach ([$source, $destination] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
