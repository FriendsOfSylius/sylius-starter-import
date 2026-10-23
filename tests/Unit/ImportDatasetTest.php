<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SyliusStarter\Core\App;
use SyliusStarter\Core\Dataset\DatasetDirectories;
use SyliusStarter\Import\Dataset\DatasetBuilders;
use SyliusStarter\Import\ImportContext;
use SyliusStarter\Import\Tests\CastorContainerTrait;
use Symfony\Component\Yaml\Yaml;

use function SyliusStarter\Import\build_import_dataset;
use function SyliusStarter\Import\castor_host_dir;
use function SyliusStarter\Import\create_import_shop_from_dataset;
use function SyliusStarter\Import\delete_import_dataset;
use function SyliusStarter\Import\download_import_image;
use function SyliusStarter\Import\export_import_dataset;
use function SyliusStarter\Import\import_media_fixture_path;
use function SyliusStarter\Import\is_local_import_image;
use function SyliusStarter\Import\list_import_datasets;
use function SyliusStarter\Import\load_project_config;

final class ImportDatasetTest extends TestCase
{
    use CastorContainerTrait;

    private string $root;
    private string $previousCwd;

    /** @var array<string, string> */
    private array $previousDirectories;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
        $this->root = sys_get_temp_dir() . '/castor-import-dataset-' . uniqid('', true);
        static::assertTrue(mkdir($this->root . '/.castor', 0o775, true));
        static::assertTrue(mkdir($this->root . '/app', 0o775, true));
        $this->previousCwd = getcwd() ?: $this->root;
        chdir($this->root);
        ImportContext::setCurrent(new ImportContext(new App('app', 'app'), 'app'));

        $this->previousDirectories = DatasetDirectories::all();

        foreach (array_keys($this->previousDirectories) as $name) {
            DatasetDirectories::unregister($name);
        }

        $this->writeDataset($this->root . '/builtin/wine', ['name' => 'Wine Merchant', 'theme' => 'cellar']);
        DatasetDirectories::register('test', $this->root . '/builtin');
    }

    protected function tearDown(): void
    {
        DatasetDirectories::unregister('test');
        DatasetBuilders::unregister('test');

        foreach ($this->previousDirectories as $name => $directory) {
            DatasetDirectories::register($name, $directory);
        }

        chdir($this->previousCwd);
        $this->removeDirectory($this->root);
    }

    public function testListsBuiltInAndBuiltDatasets(): void
    {
        $this->writeDataset($this->root . '/.castor/import/datasets/tea', ['name' => 'Tea House', 'source' => 'ai']);
        // A built dataset cannot shadow a built-in one.
        $this->writeDataset($this->root . '/.castor/import/datasets/wine', ['name' => 'Other wine']);

        $datasets = list_import_datasets();

        static::assertSame(['tea', 'wine'], array_column($datasets, 'slug'));
        static::assertSame('Tea House', $datasets[0]['name']);
        static::assertFalse($datasets[0]['builtIn']);
        static::assertSame('ai', $datasets[0]['source']);
        static::assertSame('Wine Merchant', $datasets[1]['name']);
        static::assertTrue($datasets[1]['builtIn']);
        static::assertSame('cellar', $datasets[1]['theme']);
        static::assertSame(2, $datasets[1]['productCount']);
        static::assertSame(1, $datasets[1]['imageCount']);
        static::assertSame(1, $datasets[1]['collectionCount']);
    }

    public function testCreatesAShopFromADataset(): void
    {
        static::assertSame('my-cellar', create_import_shop_from_dataset('wine', name: 'My Cellar'));

        $shopDir = $this->root . '/.castor/import/var/my-cellar';
        static::assertFileExists($shopDir . '/products.yaml');
        static::assertFileExists($shopDir . '/collections.yaml');
        static::assertSame('bottle', file_get_contents($shopDir . '/media/margaux.webp'));
        static::assertFileDoesNotExist($shopDir . '/dataset.yaml');

        $config = load_project_config('my-cellar');
        static::assertSame('wine', $config['dataset'] ?? null);
        static::assertSame('My Cellar', $config['name'] ?? null);
        static::assertSame('ai', $config['mode'] ?? null);
        static::assertNotSame('', $config['shop_password'] ?? '');

        static::assertSame('wine', create_import_shop_from_dataset('wine'));
        static::assertSame('Wine Merchant', load_project_config('wine')['name'] ?? null);

        $this->expectExceptionMessage('Shop "my-cellar" already exists');
        create_import_shop_from_dataset('wine', name: 'My Cellar');
    }

    public function testCopiesTheLocalImagesOfTheProjectInsteadOfDownloadingThem(): void
    {
        create_import_shop_from_dataset('wine');

        static::assertTrue(is_local_import_image('media/margaux.webp'));
        static::assertFalse(is_local_import_image('https://example.com/margaux.webp'));
        static::assertFalse(is_local_import_image('//cdn.example.com/margaux.webp'));

        static::assertSame(
            import_media_fixture_path('wine', 'wine_margaux.webp'),
            download_import_image('media/margaux.webp', 'wine', 'wine_margaux', quiet: true),
        );
        static::assertSame('bottle', file_get_contents(castor_host_dir('wine') . '/media/wine_margaux.webp'));
        static::assertNull(download_import_image('media/missing.webp', 'wine', 'wine_missing', quiet: true));
        static::assertNull(download_import_image('../../builtin/wine/media/margaux.webp', 'wine', 'wine_escape', quiet: true));
    }

    public function testBuildsADatasetAndStoresItsImages(): void
    {
        $source = $this->root . '/source.webp';
        static::assertNotFalse(file_put_contents($source, 'downloaded'));

        DatasetBuilders::register('test', static function (string $slug, string $name, string $description) use ($source): void {
            $dir = castor_host_dir($slug);
            static::assertSame(ImportContext::current()->datasetsDir() . '/' . $slug, $dir);
            static::assertTrue(is_dir($dir) || mkdir($dir, 0o775, true));
            file_put_contents($dir . '/products.yaml', Yaml::dump(['mode' => 'ai', 'products' => [
                // The fake source lives in the test root: copied as a local image relative to the dataset directory.
                ['title' => 'Green Tea', 'image' => '../../../../source.webp'],
                ['title' => 'Black Tea', 'image' => 'media/stale.webp'],
            ]]));
            file_put_contents($dir . '/project.yaml', Yaml::dump(['name' => $name, 'description' => $description, 'mode' => 'ai']));
            mkdir($dir . '/media');
            file_put_contents($dir . '/media/stale.webp', 'stale');
            file_put_contents($dir . '/media/unused.webp', 'unused');
        });

        build_import_dataset('test', 'tea', 'Tea House', 'Loose leaf teas', null, 20);

        $dir = $this->root . '/.castor/import/datasets/tea';
        static::assertFileDoesNotExist($dir . '/project.yaml');
        $config = Yaml::parseFile($dir . '/dataset.yaml');
        static::assertSame('Tea House', $config['name']);
        static::assertSame('test', $config['source']);

        $products = Yaml::parseFile($dir . '/products.yaml')['products'];
        // ".." never leaves the dataset directory: the first image is dropped, the second one is kept.
        static::assertSame('../../../../source.webp', $products[0]['image']);
        static::assertSame('media/black_tea.webp', $products[1]['image']);
        static::assertSame('stale', file_get_contents($dir . '/media/black_tea.webp'));
        static::assertFileDoesNotExist($dir . '/media/unused.webp');
        static::assertFileDoesNotExist($dir . '/media/stale.webp');
        static::assertSame(ImportContext::current()->castorDir() . '/import/var', ImportContext::current()->varDir());

        $this->expectExceptionMessage('"wine" is a built-in dataset');
        build_import_dataset('test', 'wine', 'Wine', 'Wine', null, 20);
    }

    public function testExportsADatasetAsAZip(): void
    {
        $zipPath = export_import_dataset('wine');

        static::assertStringEndsWith('/.castor/import/exports/wine.zip', $zipPath);

        $zip = new \ZipArchive();
        static::assertTrue($zip->open($zipPath));
        $names = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }

        static::assertSame(['wine/dataset.yaml', 'wine/products.yaml', 'wine/collections.yaml', 'wine/media/margaux.webp'], $names);
        static::assertSame('bottle', $zip->getFromName('wine/media/margaux.webp'));
        $zip->close();
    }

    public function testDeletesOnlyBuiltDatasets(): void
    {
        $this->writeDataset($this->root . '/.castor/import/datasets/tea', ['name' => 'Tea House']);

        delete_import_dataset('tea');
        static::assertDirectoryDoesNotExist($this->root . '/.castor/import/datasets/tea');

        $this->expectExceptionMessage('"wine" is a built-in dataset: it cannot be deleted.');
        delete_import_dataset('wine');
    }

    /**
     * @param array<string, string> $config
     */
    private function writeDataset(string $dir, array $config): void
    {
        static::assertTrue(mkdir($dir . '/media', 0o775, true));
        file_put_contents($dir . '/dataset.yaml', Yaml::dump($config));
        file_put_contents($dir . '/collections.yaml', Yaml::dump(['collections' => [['name' => 'Bordeaux']]]));
        file_put_contents($dir . '/products.yaml', Yaml::dump(['mode' => 'ai', 'products' => [
            ['title' => 'Margaux', 'collection_name' => 'Bordeaux', 'image' => 'media/margaux.webp'],
            ['title' => 'Médoc', 'collection_name' => 'Bordeaux'],
        ]]));
        file_put_contents($dir . '/media/margaux.webp', 'bottle');
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

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
