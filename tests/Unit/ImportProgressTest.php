<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SyliusStarter\Core\App;
use SyliusStarter\Import\ImportContext;
use SyliusStarter\Import\Tests\CastorContainerTrait;

use function SyliusStarter\Import\import_progress_advance;
use function SyliusStarter\Import\import_progress_current;
use function SyliusStarter\Import\import_progress_finish;
use function SyliusStarter\Import\import_progress_path;
use function SyliusStarter\Import\import_progress_start;

final class ImportProgressTest extends TestCase
{
    use CastorContainerTrait;

    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
        $this->root = sys_get_temp_dir() . '/castor-import-progress-' . uniqid('', true);
        static::assertTrue(mkdir($this->root . '/.castor', 0o775, true));
        $this->previousCwd = getcwd() ?: $this->root;
        chdir($this->root);
        ImportContext::setCurrent(new ImportContext(new App('app', 'app'), 'app'));
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testWritesTheProgressOfAStepUntilItEnds(): void
    {
        $path = import_progress_path('dataset-tea');
        static::assertStringEndsWith('/.castor/import/var/.progress/dataset-tea.json', $path);

        import_progress_start('dataset-tea', 'Product images', 2);
        static::assertSame(['label' => 'Product images', 'done' => 0, 'total' => 2, 'current' => null], $this->read($path));

        import_progress_current('dataset-tea', 'Green Tea');
        import_progress_advance('dataset-tea');
        import_progress_current('dataset-tea', 'Black Tea');
        static::assertSame(['label' => 'Product images', 'done' => 1, 'total' => 2, 'current' => 'Black Tea'], $this->read($path));

        import_progress_finish('dataset-tea');
        static::assertFileDoesNotExist($path);

        // A step that was not started writes nothing.
        import_progress_advance('dataset-tea');
        static::assertFileDoesNotExist($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        static::assertIsArray($data);
        static::assertIsInt($data['updatedAt'] ?? null);
        unset($data['updatedAt']);

        return $data;
    }
}
