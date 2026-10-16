<?php

declare(strict_types=1);

namespace Sylius\Starter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sylius\Starter\Core\App;
use Sylius\Starter\Import\ImportContext;
use Sylius\Starter\Import\Tests\CastorContainerTrait;

use function Sylius\Starter\Import\build_all_shop_images_config;
use function Sylius\Starter\Import\build_shop_channel_images;
use function Sylius\Starter\Import\regenerate_shop_images_config;

final class ShopImagesConfigTest extends TestCase
{
    use CastorContainerTrait;

    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
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
        $this->assertPublishedImage('hero-a', '/media/shops/COCORICO/header.', '.webp', $byChannel['COCORICO']['imageHeader']);
        $this->assertPublishedImage('inter-a', '/media/shops/COCORICO/interstice.', '.webp', $byChannel['COCORICO']['imageInterstice']);
        $this->assertPublishedImage('logo-a', '/media/shops/COCORICO/logo.', '.png', $byChannel['COCORICO']['imageLogo']);
        $this->assertPublishedImage('hero-b', '/media/shops/DOUDOU/header.', '.webp', $byChannel['DOUDOU']['imageHeader']);
        static::assertArrayNotHasKey('imageInterstice', $byChannel['DOUDOU']);
        $this->assertPublishedImage('logo-b', '/media/shops/DOUDOU/logo.', '.png', $byChannel['DOUDOU']['imageLogo']);
    }

    public function testBuildShopChannelImagesKeepsImagesOfShopWithoutLogo(): void
    {
        $this->createShopMedia('cocorico', [
            'image_header.webp' => 'hero-only',
        ]);

        $images = build_shop_channel_images('cocorico');

        static::assertSame(['imageHeader'], array_keys($images));
        $this->assertPublishedImage('hero-only', '/media/shops/COCORICO/header.', '.webp', $images['imageHeader']);
    }

    public function testBuildShopChannelImagesReturnsNothingWithoutImages(): void
    {
        $this->createShopMedia('cocorico', []);

        static::assertSame([], build_shop_channel_images('cocorico'));
        static::assertNull(build_all_shop_images_config());
        static::assertDirectoryDoesNotExist($this->root . '/public/media/shops/COCORICO');
    }

    public function testChangedImageGetsANewUrlAndTheOldFileIsRemoved(): void
    {
        $this->createShopMedia('cocorico', ['image_logo.png' => 'logo-v1']);
        $before = build_all_shop_images_config()['parameters']['app.shop_images_by_channel']['COCORICO']['imageLogo'] ?? null;

        static::assertIsString($before);
        static::assertNotFalse(file_put_contents($this->root . '/.castor/import/var/cocorico/media/image_logo.png', 'logo-v2'));

        $after = build_all_shop_images_config()['parameters']['app.shop_images_by_channel']['COCORICO']['imageLogo'] ?? null;

        static::assertIsString($after);
        static::assertNotSame($before, $after);
        $this->assertPublishedImage('logo-v2', '/media/shops/COCORICO/logo.', '.png', $after);
        static::assertFileDoesNotExist($this->root . '/public' . $before);
    }

    public function testBuildAllShopImagesConfigRemovesImagesOfDeletedShops(): void
    {
        $orphan = $this->root . '/public/media/shops/GONE';
        static::assertTrue(mkdir($orphan, 0o775, true));
        static::assertNotFalse(file_put_contents($orphan . '/logo.0123456789abcdef.png', 'old'));
        $this->createShopMedia('cocorico', ['image_logo.png' => 'logo-a']);

        build_all_shop_images_config();

        static::assertDirectoryDoesNotExist($orphan);
        static::assertDirectoryExists($this->root . '/public/media/shops/COCORICO');
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
        static::assertStringContainsString("'/media/shops/COCORICO/header.", $contents);
        static::assertStringContainsString("'/media/shops/DOUDOU/header.", $contents);
        static::assertStringNotContainsString('base64', $contents);
        static::assertStringNotContainsString("'twig'", $contents);
    }

    private function assertPublishedImage(string $contents, string $prefix, string $suffix, string $url): void
    {
        static::assertStringStartsWith($prefix, $url);
        static::assertStringEndsWith($suffix, $url);
        static::assertMatchesRegularExpression('/\.[0-9a-f]{16}\./', $url);
        static::assertFileExists($this->root . '/public' . $url);
        static::assertSame($contents, file_get_contents($this->root . '/public' . $url));
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
