<?php

declare(strict_types=1);

namespace Unit\Import;

use Castor\Sylius\App;
use Castor\Sylius\Import\ImportContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function Castor\Sylius\Import\count_import_products;
use function Castor\Sylius\Import\import_yaml_status;
use function Castor\Sylius\Import\resolve_import_project;
use function Castor\Sylius\Import\update_project_config;
use function Castor\Sylius\Import\write_project_config;

final class ResolveImportProjectTest extends TestCase
{
    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/castor-resolve-project-' . uniqid('', true);
        static::assertTrue(mkdir($this->root . '/.castor/import/var/create', 0o775, true));
        $this->previousCwd = getcwd() ?: $this->root;
        chdir($this->root);
        ImportContext::setCurrent(new ImportContext(new App('app', $this->root), 'app'));

        write_project_config('create', [
            'slug' => 'create',
            'name' => 'create',
            'description' => 'Demo store',
            'url' => 'https://www.create-store.com',
            'mode' => 'existing',
            'admin_password' => 'admin-pass-12',
            'shop_password' => 'shop-pass-12',
            'shop_images' => ['logo' => false, 'header' => false, 'interstice' => false],
        ]);

        file_put_contents(
            $this->root . '/.castor/import/var/create/products.yaml',
            Yaml::dump([
                'source' => 'https://www.create-store.com',
                'mode' => 'existing',
                'name' => 'create',
                'imported_at' => '2026-08-25T09:35:23+00:00',
                'products' => [],
            ]),
        );
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->removeDirectory($this->root);
    }

    public function testResolveImportProjectLoadsExistingConfigBySlug(): void
    {
        $resolved = resolve_import_project('existing', 'create', null, null, null);

        static::assertSame('create', $resolved['slug']);
        static::assertSame('create', $resolved['name']);
        static::assertSame('Demo store', $resolved['description']);
        static::assertSame('https://www.create-store.com', $resolved['url']);
    }

    public function testResolveImportProjectAllowsUrlOverride(): void
    {
        $resolved = resolve_import_project(
            'existing',
            'create',
            null,
            null,
            'https://www.create-store.com/fr',
        );

        static::assertSame('https://www.create-store.com/fr', $resolved['url']);
    }

    public function testUpdateProjectConfigPreservesPasswords(): void
    {
        update_project_config('create', [
            'name' => 'Create Store',
            'description' => 'Updated',
            'url' => 'https://example.test',
        ]);

        $config = Yaml::parseFile($this->root . '/.castor/import/var/create/project.yaml');
        static::assertIsArray($config);
        static::assertSame('Create Store', $config['name']);
        static::assertSame('Updated', $config['description']);
        static::assertSame('https://example.test', $config['url']);
        static::assertSame('admin-pass-12', $config['admin_password']);
        static::assertSame('shop-pass-12', $config['shop_password']);
    }

    public function testImportYamlStatus(): void
    {
        static::assertSame('no', import_yaml_status(0, false));
        static::assertSame('empty', import_yaml_status(0, true));
        static::assertSame('yes (12)', import_yaml_status(12, true));
    }

    public function testCountImportProducts(): void
    {
        static::assertSame(0, count_import_products('create'));
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
