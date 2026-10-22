<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SyliusStarter\Core\App;
use SyliusStarter\Import\ImportContext;
use SyliusStarter\Import\Tests\CastorContainerTrait;

use function SyliusStarter\Import\import_media_fixture_path;
use function SyliusStarter\Import\sync_import_media_to_app;

final class ImportMediaFixturePathTest extends TestCase
{
    use CastorContainerTrait;

    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
        $this->root = sys_get_temp_dir() . '/castor-import-media-' . uniqid('', true);
        static::assertTrue(mkdir($this->root . '/.castor', 0o775, true));
        static::assertTrue(mkdir($this->root . '/app', 0o775, true));
        $this->previousCwd = getcwd() ?: $this->root;
        chdir($this->root);
        ImportContext::setCurrent(new ImportContext(new App('app', 'app'), 'app'));
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->removeDirectory($this->root);
    }

    public function testFixturePathPointsInsideTheSyliusApplication(): void
    {
        static::assertSame(
            '%kernel.project_dir%/var/import/tracteurs-and-co/media/tracteur_mas_850.webp',
            import_media_fixture_path('tracteurs-and-co', 'tracteur_mas_850.webp'),
        );
        static::assertSame(
            '%kernel.project_dir%/var/import/cocorico/media/taxon_boxers.webp',
            import_media_fixture_path('cocorico', 'taxon_boxers.webp'),
        );
    }

    public function testSyncMirrorsTheProjectMediaIntoTheApplication(): void
    {
        $media = $this->root . '/.castor/import/var/cocorico/media';
        $appMedia = $this->root . '/app/var/import/cocorico/media';

        static::assertTrue(mkdir($media, 0o775, true));
        static::assertNotFalse(file_put_contents($media . '/coco_shirt.webp', 'shirt'));
        static::assertTrue(mkdir($appMedia, 0o775, true));
        static::assertNotFalse(file_put_contents($appMedia . '/coco_removed.webp', 'removed'));

        sync_import_media_to_app('cocorico');

        static::assertSame('shirt', file_get_contents($appMedia . '/coco_shirt.webp'));
        static::assertFileDoesNotExist($appMedia . '/coco_removed.webp');
        static::assertSame(
            $appMedia . '/coco_shirt.webp',
            str_replace('%kernel.project_dir%', $this->root . '/app', import_media_fixture_path('cocorico', 'coco_shirt.webp')),
        );
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
