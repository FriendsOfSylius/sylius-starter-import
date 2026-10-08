<?php

declare(strict_types=1);

namespace Unit\Import;

use Castor\Sylius\App;
use Castor\Sylius\Import\ImportContext;
use PHPUnit\Framework\TestCase;

use function Castor\Sylius\Import\purge_import_shop_artifacts;
use function Castor\Sylius\Import\write_last_generated_import;

final class PurgeImportShopArtifactsTest extends TestCase
{
    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/castor-purge-shop-' . uniqid('', true);
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

    public function testPurgeRemovesShopFilesAndClearsSharedLoaderWhenLastGenerated(): void
    {
        $hostDir = $this->root . '/.castor/import/var/cocorico';
        $media = $hostDir . '/media';
        $fixturesDir = $this->root . '/config/sylius/fixtures/import/cocorico';
        $shopImages = $this->root . '/config/import/shop_images.php';

        static::assertTrue(mkdir($media, 0o775, true));
        static::assertNotFalse(file_put_contents($hostDir . '/products.yaml', 'products: []'));
        static::assertNotFalse(file_put_contents($media . '/image_logo.png', 'logo'));
        static::assertTrue(mkdir($fixturesDir, 0o775, true));
        static::assertNotFalse(file_put_contents($fixturesDir . '/products.php', '<?php return [];'));
        static::assertTrue(mkdir(\dirname($shopImages), 0o775, true));
        static::assertNotFalse(file_put_contents($shopImages, '<?php return [];'));
        static::assertNotFalse(file_put_contents(
            $this->root . '/config/sylius/fixtures/import.php',
            "<?php\nreturn ['resource' => 'import/cocorico/products.php'];\n",
        ));

        write_last_generated_import([
            'slug' => 'cocorico',
            'generated_at' => '2026-08-14T10:00:00+00:00',
            'mode' => 'existing',
        ]);

        purge_import_shop_artifacts('cocorico');

        static::assertDirectoryDoesNotExist($hostDir);
        static::assertDirectoryDoesNotExist($fixturesDir);
        static::assertFileDoesNotExist($this->root . '/.castor/import/var/.last-generated');
        static::assertFileExists($this->root . '/config/sylius/fixtures/import.php');
        static::assertStringContainsString("'imports' => []", (string) file_get_contents($this->root . '/config/sylius/fixtures/import.php'));
        $shopImagesContents = (string) file_get_contents($shopImages);
        static::assertStringContainsString("'app.shop_images_by_channel'", $shopImagesContents);
        static::assertStringNotContainsString("'COCORICO'", $shopImagesContents);
    }

    public function testPurgeLeavesSharedFilesWhenAnotherShopWasLastGenerated(): void
    {
        $hostDir = $this->root . '/.castor/import/var/doudou';
        $fixturesDir = $this->root . '/config/sylius/fixtures/import/doudou';
        $loader = $this->root . '/config/sylius/fixtures/import.php';
        $shopImages = $this->root . '/config/import/shop_images.php';

        static::assertTrue(mkdir($hostDir, 0o775, true));
        static::assertNotFalse(file_put_contents($hostDir . '/products.yaml', 'products: []'));
        static::assertTrue(mkdir($fixturesDir, 0o775, true));
        static::assertNotFalse(file_put_contents($fixturesDir . '/products.php', '<?php return [];'));
        static::assertNotFalse(file_put_contents(
            $loader,
            "<?php\nreturn ['resource' => 'import/cocorico/products.php'];\n",
        ));
        static::assertTrue(mkdir(\dirname($shopImages), 0o775, true));
        static::assertNotFalse(file_put_contents($shopImages, '<?php return [\'kept\'];'));

        write_last_generated_import([
            'slug' => 'cocorico',
            'generated_at' => '2026-08-14T10:00:00+00:00',
            'mode' => 'existing',
        ]);

        purge_import_shop_artifacts('doudou');

        static::assertDirectoryDoesNotExist($hostDir);
        static::assertDirectoryDoesNotExist($fixturesDir);
        static::assertFileExists($this->root . '/.castor/import/var/.last-generated');
        static::assertStringContainsString('import/cocorico/products.php', (string) file_get_contents($loader));
        $shopImagesContents = (string) file_get_contents($shopImages);
        static::assertStringContainsString("'app.shop_images_by_channel'", $shopImagesContents);
        static::assertStringNotContainsString("'COCORICO'", $shopImagesContents);
    }

    public function testPurgeRegeneratesShopImagesForRemainingShops(): void
    {
        $cocoricoDir = $this->root . '/.castor/import/var/cocorico';
        $cocoricoMedia = $cocoricoDir . '/media';
        $doudouDir = $this->root . '/.castor/import/var/doudou';
        $doudouFixturesDir = $this->root . '/config/sylius/fixtures/import/doudou';
        $shopImages = $this->root . '/config/import/shop_images.php';

        static::assertTrue(mkdir($cocoricoMedia, 0o775, true));
        static::assertNotFalse(file_put_contents($cocoricoDir . '/products.yaml', 'products: []'));
        static::assertNotFalse(file_put_contents($cocoricoMedia . '/image_logo.png', 'logo-a'));
        static::assertNotFalse(file_put_contents($cocoricoMedia . '/image_header.webp', 'hero-a'));

        static::assertTrue(mkdir($doudouDir . '/media', 0o775, true));
        static::assertNotFalse(file_put_contents($doudouDir . '/products.yaml', 'products: []'));
        static::assertNotFalse(file_put_contents($doudouDir . '/media/image_logo.png', 'logo-b'));
        static::assertNotFalse(file_put_contents($doudouDir . '/media/image_header.webp', 'hero-b'));
        static::assertTrue(mkdir($doudouFixturesDir, 0o775, true));
        static::assertNotFalse(file_put_contents($doudouFixturesDir . '/products.php', '<?php return [];'));

        write_last_generated_import([
            'slug' => 'cocorico',
            'generated_at' => '2026-08-14T10:00:00+00:00',
            'mode' => 'existing',
        ]);

        purge_import_shop_artifacts('doudou');

        $contents = (string) file_get_contents($shopImages);

        static::assertStringContainsString("'COCORICO'", $contents);
        static::assertStringNotContainsString("'DOUDOU'", $contents);
        static::assertStringContainsString(base64_encode('hero-a'), $contents);
        static::assertStringNotContainsString(base64_encode('hero-b'), $contents);
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
