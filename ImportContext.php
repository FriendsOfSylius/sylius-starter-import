<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use Castor\Sylius\App;
use Castor\Sylius\Util\Filesystem;
use Castor\Sylius\Util\Yaml;

use function Castor\variable;

final class ImportContext
{
    private static ?self $current = null;

    private ?string $resolvedProjectRoot = null;

    public function __construct(
        private readonly App $app,
        private readonly string $serviceName,
    ) {}

    public static function setCurrent(self $context): void
    {
        self::$current = $context;
    }

    public static function current(): self
    {
        if (null === self::$current) {
            throw new \RuntimeException('Import context is not initialized.');
        }

        return self::$current;
    }

    public static function tryCurrent(): ?self
    {
        return self::$current;
    }

    public function app(): App
    {
        return $this->app;
    }

    public function serviceName(): string
    {
        return $this->serviceName;
    }

    public function appDir(): string
    {
        return $this->app->directory();
    }

    public function projectRoot(): string
    {
        if (null !== $this->resolvedProjectRoot) {
            return $this->resolvedProjectRoot;
        }

        try {
            $candidate = rtrim((string) variable('root_dir'), '/\\');

            if ('' !== $candidate && is_dir($candidate . '/.castor')) {
                return $this->resolvedProjectRoot = $candidate;
            }
        } catch (\Throwable) {
        }

        $cwd = getcwd();

        if (false !== $cwd && is_dir($cwd . '/.castor')) {
            return $this->resolvedProjectRoot = rtrim($cwd, '/\\');
        }

        return $this->resolvedProjectRoot = rtrim($this->app->directory(), '/\\');
    }

    public function castorDir(): string
    {
        return $this->projectRoot() . '/.castor';
    }

    public function varDir(): string
    {
        return $this->castorDir() . '/import/var';
    }

    public static function packageResourcesDir(): string
    {
        return \dirname(__DIR__, 2) . '/resources/import';
    }

    public function addYamlImport(string $file, string $resource): void
    {
        Yaml::addImport($this->app, $file, $resource);
    }

    public function addYamlImportWithOptions(string $file, string $resource, bool $ignoreErrors = false): void
    {
        Yaml::addImportWithOptions($this->app, $file, $resource, $ignoreErrors);
    }

    public function createFile(string $file, string $body, bool $override = false): void
    {
        Filesystem::createFile($this->app, $file, $body, $override);
    }

    public function hasFile(string $file): bool
    {
        return Filesystem::hasFile($this->app, $file);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function exportArray(array $data): string
    {
        return Filesystem::exportArray($data);
    }
}

function project_root_dir(): string
{
    return ImportContext::current()->projectRoot();
}

function app_dir(): string
{
    return ImportContext::current()->appDir();
}

function add_yaml_import(string $file, string $resource): void
{
    ImportContext::current()->addYamlImport($file, $resource);
}

function add_yaml_import_with_options(string $file, string $resource, bool $ignoreErrors = false): void
{
    ImportContext::current()->addYamlImportWithOptions($file, $resource, $ignoreErrors);
}

function create_file(string $file, string $body, bool $override = false): void
{
    ImportContext::current()->createFile($file, $body, $override);
}

function has_file(string $file): bool
{
    return ImportContext::current()->hasFile($file);
}

/**
 * @param array<string, mixed> $data
 */
function export_array(array $data): string
{
    return ImportContext::current()->exportArray($data);
}
