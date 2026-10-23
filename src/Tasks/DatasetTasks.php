<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tasks;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Api\Attribute\AsApi;
use Castor\Exception\ProblemException;
use SyliusStarter\Core\App;
use SyliusStarter\Import\Dataset\DatasetBuilders;
use SyliusStarter\Import\ImportContext;

use function Castor\io;
use function SyliusStarter\Import\build_import_dataset;
use function SyliusStarter\Import\create_import_shop_from_dataset;
use function SyliusStarter\Import\delete_import_dataset;
use function SyliusStarter\Import\export_import_dataset;
use function SyliusStarter\Import\list_import_datasets;
use function SyliusStarter\Import\normalize_import_name;
use function SyliusStarter\Import\store_import_dataset_images;
use function SyliusStarter\Import\write_import_dataset_list;

/**
 * Datasets (catalog + images) and the shops created from them. See docs/import/datasets.md.
 */
final class DatasetTasks
{
    public function __construct(
        private readonly string $name,
        private readonly string $directory,
        private readonly ?string $domain = null,
    ) {}

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    public function __invoke(): iterable
    {
        yield from $this->listTask();
        yield from $this->buildTask();
        yield from $this->imagesTask();
        yield from $this->exportTask();
        yield from $this->deleteTask();
        yield from $this->shopCreateTask();
    }

    /**
     * Errors end the task with a non-zero exit code, so castor-api reports the run as failed.
     */
    private function withContext(callable $callback): void
    {
        ImportContext::setCurrent(new ImportContext(new App($this->name, $this->directory, $this->domain), $this->name));

        try {
            $callback();
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new ProblemException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function listTask(): iterable
    {
        yield [
            'task' => new AsTask('list', 'sylius:import:dataset', 'List the datasets shops can be created from'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi] function (): void {
                $this->withContext(static function (): void {
                    write_import_dataset_list(list_import_datasets());
                });
            },
        ];
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function buildTask(): iterable
    {
        yield [
            'task' => new AsTask('build', 'sylius:import:dataset', 'Build a dataset (catalog and images) from an AI description or an existing site'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true, lock: 'dataset:{dataset}')] function (
                #[AsOption(description: 'Where the catalog comes from: "ai", or "existing" when a project registers it')]
                string $source = 'ai',
                #[AsOption(description: 'Dataset slug (defaults to the slug of the name)')]
                ?string $dataset = null,
                #[AsOption]
                ?string $name = null,
                #[AsOption]
                ?string $description = null,
                #[AsOption(description: 'Site to import ("existing" source), or the site the AI catalog is for')]
                ?string $url = null,
                #[AsOption(description: 'Number of products of an AI catalog (max 20)')]
                int $limit = 20,
            ): void {
                $this->withContext(static function () use ($source, $dataset, $name, $description, $url, $limit): void {
                    $name = trim((string) $name);

                    if ('' === $name) {
                        throw new \InvalidArgumentException('Dataset name is required.');
                    }

                    if ('ai' === $source && '' === trim((string) $description)) {
                        throw new \InvalidArgumentException('Dataset description is required.');
                    }

                    if ('ai' !== $source && '' === trim((string) $url)) {
                        throw new \InvalidArgumentException('Site URL is required.');
                    }

                    DatasetBuilders::get($source);
                    $slug = normalize_import_name('' !== trim((string) $dataset) ? (string) $dataset : $name);

                    build_import_dataset($source, $slug, $name, trim((string) $description), $url, $limit);
                });
            },
        ];
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function imagesTask(): iterable
    {
        yield [
            'task' => new AsTask('images', 'sylius:import:dataset', 'Download or generate the missing images of a dataset into its media/ directory'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true, lock: 'dataset:{dataset}')] function (
                #[AsArgument]
                string $dataset,
            ): void {
                $this->withContext(static function () use ($dataset): void {
                    $result = store_import_dataset_images(trim($dataset));
                    io()->success(\sprintf('%d image(s) stored for dataset %s.', $result['images'], trim($dataset)));
                });
            },
        ];
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function exportTask(): iterable
    {
        yield [
            'task' => new AsTask('export', 'sylius:import:dataset', 'Zip a dataset (YAML and images), by default into .castor/import/exports/<dataset>.zip'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(lock: 'dataset:{dataset}')] function (
                #[AsArgument]
                string $dataset,
                #[AsOption(description: 'Path of the zip file')]
                ?string $output = null,
            ): void {
                $this->withContext(static function () use ($dataset, $output): void {
                    io()->success(\sprintf('Dataset exported to %s.', export_import_dataset(trim($dataset), $output)));
                });
            },
        ];
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function deleteTask(): iterable
    {
        yield [
            'task' => new AsTask('delete', 'sylius:import:dataset', 'Delete a dataset built by sylius:import:dataset:build (shops created from it are kept)'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(lock: 'dataset:{dataset}')] function (
                #[AsArgument]
                string $dataset,
            ): void {
                $this->withContext(static function () use ($dataset): void {
                    if (stream_isatty(\STDIN) && !io()->confirm(\sprintf('Delete dataset "%s"?', $dataset), false)) {
                        io()->comment('Aborted.');

                        return;
                    }

                    delete_import_dataset(trim($dataset));
                });
            },
        ];
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function shopCreateTask(): iterable
    {
        yield [
            'task' => new AsTask('create', 'sylius:import:shop', 'Create a shop from a dataset, ready for sylius:import:fixtures:generate'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(lock: 'shop:{project}')] function (
                #[AsArgument]
                string $dataset,
                #[AsOption(description: 'Shop slug (defaults to the slug of the name, or the dataset slug)')]
                ?string $project = null,
                #[AsOption(description: 'Shop name (defaults to the dataset name)')]
                ?string $name = null,
                #[AsOption(description: 'Shop description (defaults to the dataset description)')]
                ?string $description = null,
            ): void {
                $this->withContext(static function () use ($dataset, $project, $name, $description): void {
                    $slug = create_import_shop_from_dataset(trim($dataset), $project, $name, $description);

                    io()->writeln(\sprintf(
                        'Next: castor sylius:import:fixtures:generate --project=%1$s, then castor sylius:import:fixtures:load --project=%1$s',
                        $slug,
                    ));
                });
            },
        ];
    }
}
