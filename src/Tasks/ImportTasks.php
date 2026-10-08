<?php

declare(strict_types=1);

namespace Castor\Sylius\Tasks;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Sylius\App;
use Castor\Sylius\Import\ImportContext;
use Jolicode\CastorApi\Attribute\AsApi;
use Symfony\Component\Console\Question\Question;

use function Castor\io;
use function Castor\Sylius\Import\build_ai_import_data;
use function Castor\Sylius\Import\delete_import_shop;
use function Castor\Sylius\Import\generate_ai_import_fixtures;
use function Castor\Sylius\Import\generate_existing_import_fixtures;
use function Castor\Sylius\Import\import_log;
use function Castor\Sylius\Import\list_import_shops;
use function Castor\Sylius\Import\load_import_fixture_suite;
use function Castor\Sylius\Import\resolve_cli_project_slug;
use function Castor\Sylius\Import\resolve_import_project;
use function Castor\Sylius\Import\shop_hostname;
use function Castor\Sylius\Import\write_import_shop_list;

final class ImportTasks
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
        yield from $this->deleteTask();
        yield from $this->aiBuildTask();
        yield from $this->fixturesGenerateTask();
        yield from $this->fixturesLoadTask();
    }

    private function withContext(callable $callback): void
    {
        ImportContext::setCurrent(new ImportContext(new App($this->name, $this->directory, $this->domain), $this->name));
        $callback();
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function listTask(): iterable
    {
        yield [
            'task' => new AsTask('list', 'sylius:import', 'List import shops'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi] function (): void {
                $this->withContext(static function (): void {
                    write_import_shop_list(list_import_shops());
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
            'task' => new AsTask('delete', 'sylius:import', 'Delete an imported shop, its files, and the Sylius channel catalog'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true)] function (
                #[AsArgument]
                ?string $project = null,
            ): void {
                $this->withContext(static function () use ($project): void {
                    if (null === $project || '' === trim($project)) {
                        io()->error('Project slug is required.');

                        return;
                    }

                    try {
                        $original = trim($project);
                        $projectSlug = resolve_cli_project_slug($original);

                        if ($original !== $projectSlug) {
                            import_log(\sprintf('Using project slug: %s', $projectSlug));
                        }
                    } catch (\InvalidArgumentException $exception) {
                        io()->error($exception->getMessage());

                        return;
                    }

                    if (stream_isatty(\STDIN) && !io()->confirm(
                        \sprintf('Delete shop "%s" and its Sylius channel/catalog?', $projectSlug),
                        false,
                    )) {
                        io()->comment('Aborted.');

                        return;
                    }

                    try {
                        delete_import_shop($projectSlug);
                    } catch (\Throwable $exception) {
                        io()->error($exception->getMessage());
                    }
                });
            },
        ];
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function aiBuildTask(): iterable
    {
        yield [
            'task' => new AsTask('build', 'sylius:import:ai', 'Generate products and collections YAML from a project description via AI'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true)] function (
                #[AsOption]
                ?string $project = null,
                #[AsOption]
                ?string $name = null,
                #[AsOption]
                ?string $description = null,
                #[AsOption]
                ?string $url = null,
                #[AsOption]
                int $limit = 20,
            ): void {
                if (null === $name) {
                    $name = io()->askQuestion(new Question('Enter the name of your project:', 'App'));
                }

                if (null === $description) {
                    $description = io()->askQuestion(new Question('Enter the description of your project:', 'An online store selling organic clothes for children'));
                }

                $this->withContext(static function () use ($project, $name, $description, $url, $limit): void {
                    try {
                        $resolved = resolve_import_project('ai', $project, $name, $description, $url);
                    } catch (\RuntimeException $exception) {
                        io()->error($exception->getMessage());

                        return;
                    }

                    if ($limit > 20) {
                        io()->warning(\sprintf(
                            'AI build is capped at %d products (requested %d).',
                            20,
                            $limit,
                        ));
                        $limit = 20;
                    }

                    build_ai_import_data(
                        $resolved['name'],
                        $resolved['description'],
                        $resolved['url'],
                        $limit,
                        $resolved['slug'],
                    );
                });
            },
        ];
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function fixturesGenerateTask(): iterable
    {
        yield [
            'task' => new AsTask('generate', 'sylius:import:fixtures', 'Generate Sylius import fixture PHP files from YAML (no database load)'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true)] function (
                #[AsArgument]
                string $mode = 'existing',
                #[AsOption]
                ?string $project = null,
                #[AsOption]
                ?string $subdomain = null,
                #[AsOption]
                int $limit = 100,
            ): void {
                $this->withContext(static function () use ($mode, $project, $limit, $subdomain): void {
                    $context = ImportContext::current();
                    $mode = strtolower(trim($mode));

                    if (!\in_array($mode, ['existing', 'ai'], true)) {
                        io()->error('Mode must be "existing" or "ai".');

                        return;
                    }

                    if (null === $project || '' === trim($project)) {
                        $project = io()->choice('Which fixtures would you like to generate?', $context->importProjectNames());
                    }

                    if (null === $subdomain) {
                        $exampleHostname = shop_hostname($context->app()->domain(), 'example');
                        $subdomain = io()->askQuestion(new Question(\sprintf('Enter the name of your subdomain (optional). The "example" subdomain will generate "%s" as hostname', $exampleHostname), null));
                    }

                    $projectSlug = trim($project);

                    if ('existing' === $mode) {
                        generate_existing_import_fixtures($projectSlug, $limit, $context->app()->domain(), $subdomain);

                        return;
                    }

                    generate_ai_import_fixtures($projectSlug, $context->app()->domain(), $subdomain);
                });
            },
        ];
    }

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    private function fixturesLoadTask(): iterable
    {
        yield [
            'task' => new AsTask('load', 'sylius:import:fixtures', 'Load generated import fixtures into Sylius via Docker'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true)] function (
                #[AsOption]
                ?string $project = null,
            ): void {
                $this->withContext(static function () use ($project): void {
                    if (null === $project || '' === trim($project)) {
                        $context = ImportContext::current();

                        $project = io()->choice('Which fixtures would you like to load?', $context->importProjectNames());
                    }

                    try {
                        $original = trim($project);
                        $projectSlug = resolve_cli_project_slug($original);

                        if ($original !== $projectSlug) {
                            import_log(\sprintf('Using project slug: %s', $projectSlug));
                        }
                    } catch (\InvalidArgumentException $exception) {
                        io()->error($exception->getMessage());

                        return;
                    }

                    try {
                        load_import_fixture_suite($projectSlug);
                    } catch (\Throwable $exception) {
                        io()->error(
                            'Fixture load failed. If the issue persists, reset with "castor app:db:fixtures app" then re-run sylius:import:fixtures:load.',
                        );
                        io()->writeln($exception->getMessage());

                        return;
                    }

                    io()->success(\sprintf('Import fixtures loaded for %s.', $projectSlug));
                });
            },
        ];
    }
}
