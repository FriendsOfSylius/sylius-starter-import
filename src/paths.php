<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use SyliusStarter\Core\Util\Runner;
use Symfony\Component\Filesystem\Path;

function last_generated_import_path(): string
{
    return castor_var_dir() . '/.last-generated';
}

function castor_project_dir(): string
{
    return ImportContext::current()->castorDir();
}

function castor_var_dir(): string
{
    return ImportContext::current()->varDir();
}

function ensure_castor_var_dir(): void
{
    $dir = castor_var_dir();

    if (!is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }
}

function castor_host_dir(string $host): string
{
    return castor_var_dir() . '/' . $host;
}

function ensure_castor_host_dir(string $host): void
{
    ensure_castor_var_dir();

    $dir = castor_host_dir($host);

    if (!is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }
}

function castor_var_path(string $host, string $suffix = ''): string
{
    ensure_castor_host_dir($host);

    if ('collections' === $suffix) {
        return castor_host_dir($host) . '/collections.yaml';
    }

    return castor_host_dir($host) . '/products.yaml';
}

function castor_import_media_dir(string $host): string
{
    $directory = castor_host_dir($host) . '/media';

    if (!is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    return $directory;
}

/**
 * @return list<string>
 */
function discover_var_slugs(callable $predicate): array
{
    $varDir = castor_var_dir();
    $slugs = [];

    foreach (glob($varDir . '/*', \GLOB_ONLYDIR) ?: [] as $path) {
        $slug = basename($path);

        if ('server' === $slug) {
            continue;
        }

        if ($predicate($path, $slug)) {
            $slugs[] = $slug;
        }
    }

    sort($slugs);

    return $slugs;
}

/**
 * @return list<string>
 */
function discover_import_hosts(): array
{
    return discover_var_slugs(static fn(string $path, string $slug): bool => is_file($path . '/products.yaml'));
}

/**
 * @param array{slug: string, generated_at: string, mode: string} $data
 */
function write_last_generated_import(array $data): void
{
    ensure_castor_var_dir();
    ensure_castor_yaml_autoload();

    $content = \Symfony\Component\Yaml\Yaml::dump($data, 2, 2);

    if (false === file_put_contents(last_generated_import_path(), $content)) {
        throw new \RuntimeException('Failed to write last generated import marker.');
    }
}

/**
 * @return array{slug: string, generated_at: string, mode: string}|null
 */
function read_last_generated_import(): ?array
{
    $path = last_generated_import_path();

    if (!is_file($path)) {
        return null;
    }

    ensure_castor_yaml_autoload();

    /** @var array<string, mixed>|null $data */
    $data = \Symfony\Component\Yaml\Yaml::parseFile($path);

    if (!\is_array($data) || !isset($data['slug'])) {
        return null;
    }

    return [
        'slug' => (string) $data['slug'],
        'generated_at' => (string) ($data['generated_at'] ?? ''),
        'mode' => (string) ($data['mode'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function load_yaml_file(string $path): array
{
    if (!file_exists($path)) {
        throw new \RuntimeException(\sprintf('Import file "%s" not found.', $path));
    }

    ensure_castor_yaml_autoload();

    /** @var array<string, mixed>|null $data */
    $data = \Symfony\Component\Yaml\Yaml::parseFile($path);

    if (!\is_array($data)) {
        throw new \RuntimeException(\sprintf('Import file "%s" is empty or invalid.', $path));
    }

    return $data;
}

/**
 * @return array<string, mixed>
 */
function load_import_yaml(string $host): array
{
    return load_yaml_file(castor_var_path($host));
}

/**
 * @return array<string, mixed>
 */
function load_collections_yaml(string $host): array
{
    return load_yaml_file(castor_var_path($host, 'collections'));
}

/**
 * Image path written into generated fixtures. It is resolved by Symfony when the
 * fixture suite is compiled, relative to the Sylius kernel, so it is valid wherever
 * the fixtures are loaded (Docker builder, castor-api container or bare host).
 */
function import_media_fixture_path(string $host, string $filename): string
{
    return '%kernel.project_dir%/' . import_var_dir_from_app() . '/' . $host . '/media/' . $filename;
}

/**
 * The import var directory relative to the Sylius application directory
 * (".castor/import/var" sits at the Castor project root, "app/" next to it).
 */
function import_var_dir_from_app(?string $appDirectory = null, ?string $projectRoot = null): string
{
    $appDirectory ??= ImportContext::current()->appDir();

    if (Path::isAbsolute($appDirectory)) {
        $projectRoot ??= ImportContext::current()->projectRoot();

        return Path::makeRelative(Path::join($projectRoot, IMPORT_VAR_HOST_DIR), $appDirectory);
    }

    // Both relative to the Castor project root.
    return Path::makeRelative(IMPORT_VAR_HOST_DIR, $appDirectory);
}

/**
 * Runs a command against the Sylius application (Docker builder or local, see Runner).
 */
function import_docker_compose_run(string $command): void
{
    $context = ImportContext::current();

    Runner::run($context->app(), $command);
}
