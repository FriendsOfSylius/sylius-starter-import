<?php

declare(strict_types=1);

namespace Unit\Import;

use Castor\Sylius\App;
use Castor\Sylius\Import\ImportContext;
use PHPUnit\Framework\TestCase;

use function Castor\Sylius\Import\build_all_shop_images_config;
use function Castor\Sylius\Import\build_shop_channel_images;
use function Castor\Sylius\Import\regenerate_shop_images_config;

final class ShopImagesConfigTest extends TestCase
{
    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/castor-shop-images-' . uniqid('', true);
        static::assertTrue(mkdir($this->root . '/.castor', 0o775, true));
        $this->previousCwd = getcwd() ?: $this->root;
        chdir($this->root);
        ImportContext::setCurrent(new ImportContext(new App('app', $this->root), 'app'));
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->removeDirectory($this->root);
    }

    public function testBuildAllShopImagesConfigIndexesImagesByChannelCode(): void
    {
        $this->createShopMedia('cocorico', [
            'image_logo.png' => 'logo-a',
            'image_header.webp' => 'hero-a',
            'image_interstice.webp' => 'inter-a',
        ]);
        $this->createShopMedia('doudou', [
            'image_logo.png' => 'logo-b',
            'image_header.webp' => 'hero-b',
        ]);

        $config = build_all_shop_images_config();

        static::assertIsArray($config);
        static::assertArrayHasKey('parameters', $config);
        static::assertArrayHasKey('app.shop_images_by_channel', $config['parameters']);

        $byChannel = $config['parameters']['app.shop_images_by_channel'];

        static::assertArrayHasKey('COCORICO', $byChannel);
        static::assertArrayHasKey('DOUDOU', $byChannel);
        static::assertStringContainsString(base64_encode('hero-a'), $byChannel['COCORICO']['imageHeader']);
        static::assertStringContainsString(base64_encode('inter-a'), $byChannel['COCORICO']['imageInterstice']);
        static::assertStringContainsString(base64_encode('logo-a'), $byChannel['COCORICO']['imageLogo']);
        static::assertStringContainsString(base64_encode('hero-b'), $byChannel['DOUDOU']['imageHeader']);
        static::assertArrayNotHasKey('imageInterstice', $byChannel['DOUDOU']);
        static::assertStringContainsString(base64_encode('logo-b'), $byChannel['DOUDOU']['imageLogo']);
    }

    public function testBuildShopChannelImagesRequiresLogo(): void
    {
        $media = $this->root . '/.castor/import/var/cocorico/media';
        static::assertTrue(mkdir($media, 0o775, true));
        static::assertNotFalse(file_put_contents($this->root . '/.castor/import/var/cocorico/products.yaml', 'products: []'));
        static::assertNotFalse(file_put_contents($media . '/image_header.webp', 'hero-only'));

        static::assertNull(build_shop_channel_images('cocorico'));
    }

    public function testRegenerateShopImagesConfigWritesMultiChannelFile(): void
    {
        $this->createShopMedia('cocorico', [
            'image_logo.png' => 'logo-a',
            'image_header.webp' => 'hero-a',
        ]);
        $this->createShopMedia('doudou', [
            'image_logo.png' => 'logo-b',
            'image_header.webp' => 'hero-b',
        ]);

        regenerate_shop_images_config();

        $contents = (string) file_get_contents($this->root . '/config/import/shop_images.php');

        static::assertStringContainsString("'app.shop_images_by_channel'", $contents);
        static::assertStringContainsString("'COCORICO'", $contents);
        static::assertStringContainsString("'DOUDOU'", $contents);
        static::assertStringContainsString(base64_encode('hero-a'), $contents);
        static::assertStringContainsString(base64_encode('hero-b'), $contents);
        static::assertStringNotContainsString("'twig'", $contents);
    }

    /**
     * @param array<string, string> $files
     */
    private function createShopMedia(string $slug, array $files): void
    {
        $hostDir = $this->root . '/.castor/import/var/' . $slug;
        $media = $hostDir . '/media';
        static::assertTrue(mkdir($media, 0o775, true));
        static::assertNotFalse(file_put_contents($hostDir . '/products.yaml', 'products: []'));

        foreach ($files as $filename => $contents) {
            static::assertNotFalse(file_put_contents($media . '/' . $filename, $contents));
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
