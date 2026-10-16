<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SyliusStarter\Core\App;
use SyliusStarter\Import\ImportContext;

use function SyliusStarter\Import\ensure_import_scaffold;
use function SyliusStarter\Import\import_scaffold_marker_path;
use function SyliusStarter\Import\is_import_scaffold_deployed;

final class ImportScaffoldTest extends TestCase
{
    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/castor-import-scaffold-' . uniqid('', true);
        $this->previousCwd = getcwd() ?: $this->root;
        chdir(\dirname(__DIR__, 4));
        ImportContext::setCurrent(new ImportContext(new App('app', $this->root), 'app'));
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->removeDirectory($this->root);
    }

    public function testImportScaffoldMarkerPathPointsToAppFixtureSuite(): void
    {
        $app = new App('app', $this->root);

        static::assertSame(
            $this->root . '/config/sylius/fixtures/app.php',
            import_scaffold_marker_path($app),
        );
    }

    public function testIsImportScaffoldDeployedDetectsMarkerFile(): void
    {
        $app = new App('app', $this->root);
        $marker = import_scaffold_marker_path($app);

        static::assertFalse(is_import_scaffold_deployed($app));

        static::assertTrue(mkdir(\dirname($marker), 0o775, true));
        static::assertNotFalse(file_put_contents($marker, "<?php\n\nreturn [];\n"));

        static::assertTrue(is_import_scaffold_deployed($app));
    }

    public function testEnsureImportScaffoldIsNoOpWhenMarkerIsPresent(): void
    {
        $app = new App('app', $this->root);
        $marker = import_scaffold_marker_path($app);

        static::assertTrue(mkdir(\dirname($marker), 0o775, true));
        static::assertNotFalse(file_put_contents($marker, "<?php\n\nreturn [];\n"));
        touch($marker, 1_700_000_000);

        ensure_import_scaffold($app, 'app');

        static::assertSame(1_700_000_000, filemtime($marker));
        static::assertFileDoesNotExist($this->root . '/src/Command/ResetImportChannelCommand.php');
    }

    public function testEnsureImportScaffoldRefreshesExistingOwnedFiles(): void
    {
        $app = new App('app', $this->root);
        $marker = import_scaffold_marker_path($app);
        $command = $this->root . '/src/Command/ResetImportChannelCommand.php';
        $template = \dirname(__DIR__, 2) . '/resources/templates/application/src/Command/ResetImportChannelCommand.php';

        static::assertTrue(mkdir(\dirname($marker), 0o775, true));
        static::assertNotFalse(file_put_contents($marker, "<?php\n\nreturn [];\n"));
        static::assertTrue(mkdir(\dirname($command), 0o775, true));
        static::assertNotFalse(file_put_contents($command, "<?php\n\n// outdated\n"));

        ensure_import_scaffold($app, 'app');

        static::assertFileEquals($template, $command);
        static::assertFileDoesNotExist($this->root . '/src/Fixture/ImportChannelAccessFixture.php');
    }

    public function testImportScaffoldTemplatesIncludeMarkerFile(): void
    {
        $templateDir = \dirname(__DIR__, 2) . '/resources/templates/application';

        static::assertFileExists($templateDir . '/config/sylius/fixtures/app.php');
        static::assertFileExists($templateDir . '/src/Command/ResetImportChannelCommand.php');
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
