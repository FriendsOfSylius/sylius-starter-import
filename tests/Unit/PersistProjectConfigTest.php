<?php

declare(strict_types=1);

namespace Unit\Import;

use Castor\Sylius\App;
use Castor\Sylius\Import\ImportContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function Castor\Sylius\Import\persist_project_config;
use function Castor\Sylius\Import\write_project_config;

final class PersistProjectConfigTest extends TestCase
{
    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/castor-persist-config-' . uniqid('', true);
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

    public function testPersistWritesShopImagesFlagsFromFilesOnDisk(): void
    {
        $media = $this->root . '/.castor/import/var/cocorico/media';
        static::assertTrue(mkdir($media, 0o775, true));
        static::assertNotFalse(file_put_contents($media . '/image_logo.png', 'logo'));
        static::assertNotFalse(file_put_contents($media . '/image_header.webp', 'hero'));

        persist_project_config('cocorico', [
            'name' => 'Cocorico',
            'description' => 'Fashion store',
            'url' => 'https://www.cocorico.store',
            'mode' => 'existing',
        ]);

        $config = Yaml::parseFile($this->root . '/.castor/import/var/cocorico/project.yaml');

        static::assertIsArray($config);
        static::assertSame('cocorico', $config['slug']);
        static::assertSame('existing', $config['mode']);
        static::assertSame([
            'logo' => true,
            'header' => true,
            'interstice' => false,
        ], $config['shop_images']);
        static::assertSame(12, \strlen((string) $config['admin_password']));
        static::assertSame(12, \strlen((string) $config['shop_password']));
    }

    public function testPersistKeepsExistingPasswords(): void
    {
        $media = $this->root . '/.castor/import/var/cocorico/media';
        static::assertTrue(mkdir($media, 0o775, true));
        static::assertNotFalse(file_put_contents($media . '/image_logo.png', 'logo'));
        write_project_config('cocorico', [
            'slug' => 'cocorico',
            'name' => 'Cocorico',
            'description' => 'Fashion store',
            'url' => 'https://www.cocorico.store',
            'mode' => 'existing',
            'admin_password' => 'admin-pass-12',
            'shop_password' => 'shop-pass-12',
            'shop_images' => ['logo' => true, 'header' => false, 'interstice' => false],
        ]);

        persist_project_config('cocorico', [
            'name' => 'Cocorico Updated',
            'description' => 'Fashion store',
            'url' => 'https://www.cocorico.store',
            'mode' => 'existing',
        ]);

        $config = Yaml::parseFile($this->root . '/.castor/import/var/cocorico/project.yaml');
        static::assertIsArray($config);
        static::assertSame('admin-pass-12', $config['admin_password']);
        static::assertSame('shop-pass-12', $config['shop_password']);
        static::assertSame('Cocorico Updated', $config['name']);
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
