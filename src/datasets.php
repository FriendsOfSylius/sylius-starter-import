<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use SyliusStarter\Core\Dataset\DatasetDirectories;
use SyliusStarter\Import\Dataset\DatasetBuilders;
use Symfony\Component\Yaml\Yaml;

use function Castor\fs;
use function Castor\io;

/**
 * A dataset is a catalog (products.yaml, collections.yaml) with its images (media/) and a dataset.yaml (name,
 * description, …), from which shops are created. Built-in datasets come from packages (sylius-starter/datasets,
 * see DatasetDirectories), the others are built by sylius:import:dataset:build into .castor/import/datasets/.
 *
 * In a dataset, an image is a path relative to the dataset directory ("media/<code>.webp"): creating a shop copies
 * the dataset into the shop directory, where the fixtures generation copies each image instead of downloading it.
 */
const IMPORT_DATASET_CONFIG_FILE = 'dataset.yaml';

/** Files of a dataset copied into a shop, besides media/. */
const IMPORT_DATASET_CATALOG_FILES = ['products.yaml', 'collections.yaml'];

function import_datasets_dir(): string
{
    return ImportContext::current()->datasetsDir();
}

function import_dataset_exports_dir(): string
{
    return ImportContext::current()->castorDir() . '/import/exports';
}

/**
 * Built-in datasets first: a dataset built from the import tasks cannot take the slug of a built-in one.
 *
 * @return array<string, array{path: string, builtIn: bool}>
 */
function discover_import_datasets(): array
{
    $datasets = [];
    $sources = [];

    foreach (DatasetDirectories::all() as $directory) {
        $sources[] = [$directory, true];
    }

    $sources[] = [import_datasets_dir(), false];

    foreach ($sources as [$directory, $builtIn]) {
        foreach (glob($directory . '/*', \GLOB_ONLYDIR) ?: [] as $path) {
            if (is_file($path . '/products.yaml')) {
                $datasets[basename($path)] ??= ['path' => $path, 'builtIn' => $builtIn];
            }
        }
    }

    ksort($datasets);

    return $datasets;
}

/**
 * @return array{path: string, builtIn: bool}|null
 */
function find_import_dataset(string $slug): ?array
{
    return discover_import_datasets()[$slug] ?? null;
}

/**
 * @return array{path: string, builtIn: bool}
 */
function get_import_dataset(string $slug): array
{
    return find_import_dataset($slug) ?? throw new \RuntimeException(\sprintf(
        'Unknown dataset "%s". Run "castor sylius:import:dataset:list" to list the datasets.',
        $slug,
    ));
}

/**
 * @return array<string, mixed>
 */
function load_import_dataset_config(string $path): array
{
    $file = $path . '/' . IMPORT_DATASET_CONFIG_FILE;

    if (!is_file($file)) {
        return [];
    }

    ensure_castor_yaml_autoload();
    $config = Yaml::parseFile($file);

    return \is_array($config) ? $config : [];
}

/**
 * @return array{
 *     slug: string,
 *     name: string,
 *     description: string|null,
 *     url: string|null,
 *     mode: string,
 *     source: string|null,
 *     theme: string|null,
 *     builtIn: bool,
 *     productCount: int,
 *     collectionCount: int,
 *     imageCount: int,
 *     createdAt: string|null,
 * }
 */
function describe_import_dataset(string $slug, string $path, bool $builtIn): array
{
    $config = load_import_dataset_config($path);
    $products = import_dataset_items($path, 'products.yaml', 'products');
    $imageCount = 0;

    foreach ($products as $product) {
        $image = trim((string) ($product['image'] ?? ''));

        if (is_local_import_image($image) && is_file($path . '/' . $image)) {
            ++$imageCount;
        }
    }

    $productsData = is_file($path . '/products.yaml') ? load_yaml_file($path . '/products.yaml') : [];
    $optional = static fn(string $key): ?string => '' !== trim((string) ($config[$key] ?? '')) ? trim((string) $config[$key]) : null;

    return [
        'slug' => $slug,
        'name' => $optional('name') ?? humanize_slug($slug),
        'description' => $optional('description'),
        'url' => $optional('url'),
        'mode' => 'ai' === ($productsData['mode'] ?? '') ? 'ai' : 'existing',
        'source' => $optional('source'),
        'theme' => $optional('theme'),
        'builtIn' => $builtIn,
        'productCount' => \count($products),
        'collectionCount' => \count(import_dataset_items($path, 'collections.yaml', 'collections')),
        'imageCount' => $imageCount,
        'createdAt' => $optional('created_at'),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function import_dataset_items(string $path, string $file, string $key): array
{
    if (!is_file($path . '/' . $file)) {
        return [];
    }

    try {
        $items = load_yaml_file($path . '/' . $file)[$key] ?? [];
    } catch (\Throwable) {
        return [];
    }

    return \is_array($items) ? array_values(array_filter($items, \is_array(...))) : [];
}

/**
 * @return list<array<string, mixed>>
 */
function list_import_datasets(): array
{
    $datasets = [];

    foreach (discover_import_datasets() as $slug => $dataset) {
        $datasets[] = describe_import_dataset($slug, $dataset['path'], $dataset['builtIn']);
    }

    return $datasets;
}

/**
 * JSON on non-TTY (API, pipes). A table when a human is at a terminal.
 *
 * @param list<array<string, mixed>> $datasets
 */
function write_import_dataset_list(array $datasets, ?bool $tty = null): void
{
    $tty ??= stream_isatty(\STDOUT);

    if (!$tty) {
        io()->writeln(json_encode($datasets, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return;
    }

    if ([] === $datasets) {
        io()->writeln('No datasets found.');

        return;
    }

    $rows = [];

    foreach ($datasets as $dataset) {
        $rows[] = [
            $dataset['name'],
            $dataset['slug'],
            $dataset['builtIn'] ? 'built-in' : (string) ($dataset['source'] ?? '—'),
            (string) $dataset['productCount'],
            \sprintf('%d/%d', $dataset['imageCount'], $dataset['productCount']),
            (string) $dataset['collectionCount'],
        ];
    }

    io()->table(['Name', 'Slug', 'Source', 'Products', 'Images', 'Collections'], $rows);
}

/**
 * Runs the callback in an import context whose var dir is the datasets one: the shop import functions (AI build,
 * fetch, image download) then read and write the dataset directories.
 *
 * @template T
 *
 * @param callable(): T $callback
 *
 * @return T
 */
function with_import_datasets_context(callable $callback): mixed
{
    $previous = ImportContext::current();
    ImportContext::setCurrent($previous->withVarDir($previous->datasetsDir()));

    try {
        return $callback();
    } finally {
        ImportContext::setCurrent($previous);
    }
}

/**
 * Builds (or rebuilds) a dataset from a source, then stores its images in media/.
 */
function build_import_dataset(string $source, string $slug, string $name, string $description, ?string $url, int $limit): void
{
    $builder = DatasetBuilders::get($source);
    $existing = find_import_dataset($slug);

    if (null !== $existing && $existing['builtIn']) {
        throw new \RuntimeException(\sprintf('"%s" is a built-in dataset: choose another name.', $slug));
    }

    with_import_datasets_context(static function () use ($builder, $source, $slug, $name, $description, $url, $limit): void {
        $previousConfig = load_import_dataset_config(castor_host_dir($slug));
        $productsPath = castor_var_path($slug);
        $backupPath = $productsPath . '.previous';

        if (is_file($productsPath)) {
            fs()->rename($productsPath, $backupPath, true);
        }

        // A single AI or fetch call: the step has no intermediate progress.
        import_progress_start(import_dataset_progress_key($slug), 'ai' === $source ? 'Writing the catalog with AI' : 'Fetching the catalog', 1);

        try {
            $builder($slug, $name, $description, $url, $limit);
        } finally {
            import_progress_finish(import_dataset_progress_key($slug));
        }

        // The shop builders report their errors and return: keep the previous catalog.
        if (!is_file($productsPath)) {
            if (is_file($backupPath)) {
                fs()->rename($backupPath, $productsPath);
            }

            throw new \RuntimeException(\sprintf('The %s build of dataset "%s" did not write any product.', $source, $slug));
        }

        fs()->remove($backupPath);

        // The builders keep their metadata in project.yaml, like a shop: a dataset keeps it in dataset.yaml.
        $projectConfig = load_project_config($slug) ?? [];
        fs()->remove(project_yaml_path($slug));

        write_import_dataset_config($slug, array_filter([
            'name' => trim((string) ($projectConfig['name'] ?? $name)),
            'description' => trim((string) ($projectConfig['description'] ?? $description)),
            'url' => trim((string) ($projectConfig['url'] ?? $url ?? '')),
            'source' => $source,
            'theme' => $previousConfig['theme'] ?? null,
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], static fn(mixed $value): bool => null !== $value && '' !== $value));

        store_import_dataset_images($slug);
    });

    io()->success(\sprintf('Dataset %s built.', $slug));
}

/**
 * Must run in the datasets context (see with_import_datasets_context()).
 *
 * @param array<string, mixed> $config
 */
function write_import_dataset_config(string $slug, array $config): void
{
    fs()->dumpFile(
        castor_host_dir($slug) . '/' . IMPORT_DATASET_CONFIG_FILE,
        Yaml::dump($config, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
    );
}

/**
 * Downloads (image URL) or generates (image prompt) the image of every product and collection of a dataset into its
 * media/ directory, and points the YAML at it, so the dataset is complete without the source site or the AI. Images
 * that are already there are kept; media files no longer used are removed.
 *
 * @return array{images: int, failures: int}
 */
function store_import_dataset_images(string $slug): array
{
    $dataset = get_import_dataset($slug);

    if ($dataset['builtIn']) {
        throw new \RuntimeException(\sprintf('"%s" is a built-in dataset: its images are already stored.', $slug));
    }

    return with_import_datasets_context(static function () use ($slug): array {
        $productsData = load_import_yaml($slug);
        $products = \is_array($productsData['products'] ?? null) ? $productsData['products'] : [];
        $collectionsData = is_file(castor_var_path($slug, 'collections')) ? load_collections_yaml($slug) : [];
        $collections = \is_array($collectionsData['collections'] ?? null) ? $collectionsData['collections'] : [];
        $styleReferencePrompt = find_first_image_prompt($products);

        // Downloads and image generation read the AI and HTTP settings of the project.
        foreach ([...$products, ...$collections] as $item) {
            $image = \is_array($item) ? trim((string) ($item['image'] ?? '')) : '';

            if (null !== $styleReferencePrompt || ('' !== $image && !is_local_import_image($image))) {
                ensure_import_ai_ready();

                break;
            }
        }
        $used = [];
        $images = 0;
        $failures = 0;
        /** @var array<string, true> $usedCodes */
        $usedCodes = [];

        io()->section(\sprintf('Storing the images of dataset %s', $slug));

        $progressKey = import_dataset_progress_key($slug);
        $isNamed = static fn(mixed $item, string $key): bool => \is_array($item) && '' !== trim((string) ($item[$key] ?? ''));
        import_progress_start($progressKey, 'Product images', \count(array_filter($products, static fn(mixed $product): bool => $isNamed($product, 'title'))));

        foreach ($products as $index => $product) {
            if (!$isNamed($product, 'title')) {
                continue;
            }

            import_progress_current($progressKey, trim((string) $product['title']));
            $image = store_import_dataset_image(
                $slug,
                resolve_product_code($product, $usedCodes),
                trim((string) ($product['image'] ?? '')),
                trim((string) ($product['image_prompt'] ?? '')),
            );

            import_progress_advance($progressKey);

            if (null === $image) {
                $failures += '' !== trim((string) ($product['image'] ?? '')) || '' !== trim((string) ($product['image_prompt'] ?? '')) ? 1 : 0;

                continue;
            }

            $products[$index]['image'] = $image;
            $used[$image] = true;
            ++$images;
        }

        import_progress_start($progressKey, 'Collection images', \count(array_filter($collections, static fn(mixed $collection): bool => $isNamed($collection, 'name'))));

        /** @var array<string, true> $usedTaxonCodes */
        $usedTaxonCodes = [];

        foreach ($collections as $index => $collection) {
            $name = \is_array($collection) ? trim((string) ($collection['name'] ?? '')) : '';

            if ('' === $name) {
                continue;
            }

            import_progress_current($progressKey, $name);
            $image = store_import_dataset_image(
                $slug,
                'collection_' . unique_taxon_code($name, $usedTaxonCodes),
                \is_string($collection['image'] ?? null) && 'null' !== strtolower(trim($collection['image'])) ? trim($collection['image']) : '',
                null !== $styleReferencePrompt ? \sprintf('Category thumbnail for "%s", same visual style as: %s', $name, $styleReferencePrompt) : '',
            );

            import_progress_advance($progressKey);

            if (null !== $image) {
                $collections[$index]['image'] = $image;
                $used[$image] = true;
            }
        }

        import_progress_finish($progressKey);

        $productsData['products'] = $products;
        fs()->dumpFile(castor_var_path($slug), Yaml::dump($productsData, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        if ([] !== $collectionsData) {
            $collectionsData['collections'] = $collections;
            fs()->dumpFile(castor_var_path($slug, 'collections'), Yaml::dump($collectionsData, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }

        foreach (glob(castor_import_media_dir($slug) . '/*') ?: [] as $file) {
            if (is_file($file) && !isset($used['media/' . basename($file)])) {
                fs()->remove($file);
            }
        }

        import_log(\sprintf('Dataset images: %d/%d product(s) with an image.', $images, \count($products)));

        if ($failures > 0) {
            io()->warning(\sprintf('%d product image(s) could not be downloaded or generated.', $failures));
        }

        return ['images' => $images, 'failures' => $failures];
    });
}

/**
 * Must run in the datasets context. Returns the image path relative to the dataset directory, or null.
 */
function store_import_dataset_image(string $slug, string $code, string $image, string $prompt): ?string
{
    $filename = $code . '.webp';

    // A stored image that went missing is generated again from its prompt.
    if ('' !== $prompt && is_local_import_image($image) && null === resolve_local_import_image_path($image, $slug)) {
        $image = '';
    }

    if ('' !== $image) {
        $stored = download_import_image($image, $slug, $code, quiet: true);
    } elseif ('' !== $prompt) {
        $stored = generate_import_image_from_prompt($prompt, $slug, $code, quiet: true);
    } else {
        return null;
    }

    return null !== $stored ? 'media/' . $filename : null;
}

/**
 * Copies a dataset into a new shop (catalog and images), ready for sylius:import:fixtures:generate.
 *
 * @return string the shop (project) slug
 */
function create_import_shop_from_dataset(string $datasetSlug, ?string $projectSlug = null, ?string $name = null, ?string $description = null): string
{
    $dataset = get_import_dataset($datasetSlug);
    $config = load_import_dataset_config($dataset['path']);
    $name = trim((string) $name);
    $projectSlug = trim((string) $projectSlug);

    // The shop slug comes from its name, or is the dataset one.
    if ('' === $projectSlug) {
        $projectSlug = '' !== $name ? $name : $datasetSlug;
    }

    $projectSlug = normalize_import_name($projectSlug);
    $name = '' !== $name ? $name : trim((string) ($config['name'] ?? humanize_slug($datasetSlug)));

    if (is_file(castor_host_dir($projectSlug) . '/products.yaml')) {
        throw new \RuntimeException(\sprintf('Shop "%s" already exists: delete it first, or choose another name.', $projectSlug));
    }

    ensure_castor_host_dir($projectSlug);

    foreach (IMPORT_DATASET_CATALOG_FILES as $file) {
        if (is_file($dataset['path'] . '/' . $file)) {
            fs()->copy($dataset['path'] . '/' . $file, castor_host_dir($projectSlug) . '/' . $file, true);
        }
    }

    if (is_dir($dataset['path'] . '/media')) {
        fs()->mirror($dataset['path'] . '/media', castor_host_dir($projectSlug) . '/media', options: ['override' => true]);
    }

    $description = trim((string) $description);
    $productsData = load_import_yaml($projectSlug);

    persist_project_config($projectSlug, [
        'name' => $name,
        'description' => '' !== $description ? $description : trim((string) ($config['description'] ?? '')),
        'url' => trim((string) ($config['url'] ?? '')),
        'mode' => 'ai' === ($productsData['mode'] ?? '') ? 'ai' : 'existing',
    ]);
    write_project_config($projectSlug, (load_project_config($projectSlug) ?? []) + ['dataset' => $datasetSlug]);

    io()->success(\sprintf('Shop %s created from dataset %s.', $projectSlug, $datasetSlug));

    return $projectSlug;
}

/**
 * Zips a dataset into a "<slug>/" directory (dataset.yaml, products.yaml, collections.yaml, media/), the layout of
 * a built-in dataset.
 */
function export_import_dataset(string $slug, ?string $output = null): string
{
    $dataset = get_import_dataset($slug);
    $output = null !== $output && '' !== trim($output) ? trim($output) : import_dataset_exports_dir() . '/' . $slug . '.zip';

    if (!class_exists(\ZipArchive::class)) {
        throw new \RuntimeException('The zip PHP extension is required to export a dataset.');
    }

    fs()->mkdir(\dirname($output));
    $temporary = $output . '.tmp';
    $zip = new \ZipArchive();

    if (true !== $zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
        throw new \RuntimeException(\sprintf('Could not create "%s".', $output));
    }

    foreach ([IMPORT_DATASET_CONFIG_FILE, ...IMPORT_DATASET_CATALOG_FILES] as $file) {
        if (is_file($dataset['path'] . '/' . $file)) {
            $zip->addFile($dataset['path'] . '/' . $file, $slug . '/' . $file);
        }
    }

    $media = glob($dataset['path'] . '/media/*') ?: [];
    sort($media);

    foreach ($media as $file) {
        if (is_file($file)) {
            $zip->addFile($file, $slug . '/media/' . basename($file));
            // Images are already compressed.
            $zip->setCompressionName($slug . '/media/' . basename($file), \ZipArchive::CM_STORE);
        }
    }

    if (!$zip->close()) {
        throw new \RuntimeException(\sprintf('Could not write "%s".', $output));
    }

    fs()->rename($temporary, $output, true);

    return $output;
}

function delete_import_dataset(string $slug): void
{
    $dataset = get_import_dataset($slug);

    if ($dataset['builtIn']) {
        throw new \RuntimeException(\sprintf('"%s" is a built-in dataset: it cannot be deleted.', $slug));
    }

    $root = realpath(import_datasets_dir());
    $path = realpath($dataset['path']);

    if (false === $root || false === $path || !str_starts_with($path, $root . \DIRECTORY_SEPARATOR)) {
        throw new \RuntimeException(\sprintf('Refusing to delete "%s" outside of the datasets directory.', $dataset['path']));
    }

    fs()->remove($path);
    fs()->remove(import_dataset_exports_dir() . '/' . $slug . '.zip');

    io()->success(\sprintf('Dataset %s deleted.', $slug));
}
