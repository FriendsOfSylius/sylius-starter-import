<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use function Castor\Docker\docker_compose_run;

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

function import_media_fixture_path(string $host, string $filename): string
{
    return IMPORT_VAR_CONTAINER_DIR . '/' . $host . '/media/' . $filename;
}

function import_docker_compose_run(string $command): void
{
    docker_compose_run($command, ImportContext::current()->serviceName() . '-builder');
}
