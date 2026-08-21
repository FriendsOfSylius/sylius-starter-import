<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use function Castor\io;

function delete_import_shop(string $projectSlug): void
{
    if (!import_shop_exists($projectSlug)) {
        throw new \RuntimeException(\sprintf('Unknown shop "%s".', $projectSlug));
    }

    ensure_import_scaffold();

    io()->title(\sprintf('Deleting import shop %s', $projectSlug));
    import_log(\sprintf(
        'Resetting channel %s (prefix %s).',
        channel_code_from_slug($projectSlug),
        import_code_prefix($projectSlug),
    ));

    reset_import_shop_channel($projectSlug);

    import_log('Removing import files...');
    purge_import_shop_artifacts($projectSlug);

    io()->success(\sprintf('Import shop %s deleted.', $projectSlug));
}

function reset_import_shop_channel(string $projectSlug): void
{
    ensure_docker_ready();
    import_docker_compose_run(import_channel_reset_cli($projectSlug));
}

function import_shop_exists(string $projectSlug): bool
{
    foreach (import_shop_artifact_directories($projectSlug) as $directory) {
        if (is_dir($directory)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{hostDir: string, fixturesDir: string}
 */
function import_shop_artifact_directories(string $projectSlug): array
{
    return [
        'hostDir' => castor_host_dir($projectSlug),
        'fixturesDir' => app_dir() . '/config/sylius/fixtures/' . import_fixture_slug_dir($projectSlug),
    ];
}

function purge_import_shop_artifacts(string $projectSlug): void
{
    $lastGenerated = read_last_generated_import();
    $wasLastGenerated = null !== $lastGenerated && $lastGenerated['slug'] === $projectSlug;
    $suiteTargetsSlug = import_suite_loader_targets_slug($projectSlug);

    foreach (import_shop_artifact_directories($projectSlug) as $directory) {
        remove_import_directory($directory);
    }

    regenerate_shop_images_config();

    if ($wasLastGenerated) {
        $marker = last_generated_import_path();

        if (is_file($marker)) {
            unlink($marker);
        }
    }

    if ($wasLastGenerated || $suiteTargetsSlug) {
        write_empty_import_suite_loader();
    }
}

function import_suite_loader_targets_slug(string $projectSlug): bool
{
    $path = app_dir() . '/config/sylius/fixtures/import.php';

    if (!is_file($path)) {
        return false;
    }

    return str_contains((string) file_get_contents($path), 'import/' . $projectSlug . '/');
}

function remove_import_directory(string $directory): void
{
    if ('' === $directory || !is_dir($directory)) {
        return;
    }

    $real = realpath($directory);

    if (false === $real) {
        return;
    }

    assert_safe_import_delete_path($real);

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
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

    rmdir($real);
}

function assert_safe_import_delete_path(string $realDirectory): void
{
    $allowedRoots = [];
    $varDir = realpath(castor_var_dir());

    if (false !== $varDir) {
        $allowedRoots[] = $varDir;
    }

    $fixturesRoot = app_dir() . '/config/sylius/fixtures/import';

    if (is_dir($fixturesRoot)) {
        $resolved = realpath($fixturesRoot);

        if (false !== $resolved) {
            $allowedRoots[] = $resolved;
        }
    }

    foreach ($allowedRoots as $root) {
        if ($realDirectory === $root) {
            throw new \RuntimeException('Refusing to delete the import root directory.');
        }

        if (str_starts_with($realDirectory, $root . \DIRECTORY_SEPARATOR)) {
            return;
        }
    }

    throw new \RuntimeException(\sprintf('Refusing to delete unexpected path "%s".', $realDirectory));
}
