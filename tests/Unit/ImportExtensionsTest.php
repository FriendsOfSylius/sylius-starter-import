<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SyliusStarter\Core\App;
use SyliusStarter\Import\Extension\ImportExtensionInterface;
use SyliusStarter\Import\Extension\ImportExtensions;
use SyliusStarter\Import\ImportContext;
use SyliusStarter\Import\Tests\CastorContainerTrait;

use function SyliusStarter\Import\ensure_import_scaffold;
use function SyliusStarter\Import\import_scaffold_marker_path;
use function SyliusStarter\Import\list_import_shops;
use function SyliusStarter\Import\write_import_extension_fixture_files;
use function SyliusStarter\Import\write_import_suite_loader;
use function SyliusStarter\Import\write_project_config;

final class ImportExtensionsTest extends TestCase
{
    use CastorContainerTrait;

    private string $root;
    private string $previousCwd;
    private ImportExtensionInterface $extension;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
        $this->root = sys_get_temp_dir() . '/castor-import-extensions-' . uniqid('', true);
        static::assertTrue(mkdir($this->root . '/.castor', 0o775, true));
        $this->previousCwd = getcwd() ?: $this->root;
        chdir($this->root);
        ImportContext::setCurrent(new ImportContext(new App('app', $this->root), 'app'));

        $this->extension = new class implements ImportExtensionInterface {
            /** @var list<string> */
            public array $scaffolded = [];

            public function scaffold(App $app): void
            {
                $this->scaffolded[] = $app->directory();
            }

            public function buildFixtures(string $projectSlug, array $target): array
            {
                return ['demo_admin' => ['channel' => $target['channel'], 'username' => $projectSlug]];
            }

            public function describeShop(string $projectSlug, ?array $config): array
            {
                return ['adminPassword' => $config['admin_password'] ?? null, 'slug' => 'overridden'];
            }
        };
        ImportExtensions::register('demo', $this->extension);
    }

    protected function tearDown(): void
    {
        ImportExtensions::unregister('demo');
        chdir($this->previousCwd);
        $this->removeDirectory($this->root);
    }

    public function testExtensionFixturesAreWrittenAndLoadedByTheImportSuite(): void
    {
        $dir = $this->root . '/config/sylius/fixtures/import/cocorico/extensions';
        static::assertTrue(mkdir($dir, 0o775, true));
        static::assertNotFalse(file_put_contents($dir . '/stale.php', '<?php return [];'));

        write_import_extension_fixture_files('cocorico', ['channel' => 'COCORICO', 'subdomain' => 'cocorico', 'shared' => false]);
        write_import_suite_loader('cocorico');

        static::assertFileDoesNotExist($dir . '/stale.php');
        static::assertStringContainsString("'channel' => 'COCORICO'", (string) file_get_contents($dir . '/demo_admin.php'));

        $loader = (string) file_get_contents($this->root . '/config/sylius/fixtures/import.php');
        static::assertStringContainsString("'resource' => 'import/cocorico/extensions/demo_admin.php'", $loader);
        static::assertStringNotContainsString('admin_user.php', $loader);
    }

    public function testExtensionsAreScaffoldedOnEveryRun(): void
    {
        $app = new App('app', $this->root);
        $marker = import_scaffold_marker_path($app);
        static::assertTrue(mkdir(\dirname($marker), 0o775, true));
        static::assertNotFalse(file_put_contents($marker, "<?php\n\nreturn [];\n"));

        ensure_import_scaffold($app, 'app');

        static::assertSame([$this->root], $this->extension->scaffolded);
    }

    public function testExtensionsAddFieldsToTheShopListWithoutOverridingThem(): void
    {
        $hostDir = $this->root . '/.castor/import/var/cocorico';
        static::assertTrue(mkdir($hostDir, 0o775, true));
        static::assertNotFalse(file_put_contents($hostDir . '/products.yaml', 'products: []'));
        write_project_config('cocorico', ['slug' => 'cocorico', 'mode' => 'existing', 'admin_password' => 'admin-pass-12']);

        $shops = list_import_shops();

        static::assertCount(1, $shops);
        static::assertSame('cocorico', $shops[0]['slug']);
        static::assertSame('admin-pass-12', $shops[0]['adminPassword']);
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
