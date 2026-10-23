<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

/**
 * Progress of the long import steps (image generation and downloads), for the tools that run the tasks in the
 * background: castor-api only returns the output of a task once it finishes. Each step writes a small JSON file:
 *
 *     {"label": "Product images", "done": 3, "total": 18, "current": "Baguette fraîche", "updatedAt": 1792744000}
 *
 * "current" is the item being processed, announced before the slow call: import_progress_current(), then
 * import_progress_advance() once it is done.
 *
 * in .castor/import/var/.progress/<key>.json, where the key is "shop-<project slug>" or "dataset-<dataset slug>".
 * The file is removed when the step ends. A task locks its shop or dataset, so a key has one writer at a time.
 */
function import_progress_path(string $key): string
{
    return ImportContext::current()->castorDir() . '/import/var/.progress/' . $key . '.json';
}

function import_shop_progress_key(string $projectSlug): string
{
    return 'shop-' . $projectSlug;
}

function import_dataset_progress_key(string $datasetSlug): string
{
    return 'dataset-' . $datasetSlug;
}

/**
 * The steps in progress, by key.
 *
 * @return array<string, array{label: string, done: int, total: int, current: string|null}>
 */
function &import_progress_steps(): array
{
    static $steps = [];

    return $steps;
}

function import_progress_start(string $key, string $label, int $total): void
{
    $steps = &import_progress_steps();
    $steps[$key] = ['label' => $label, 'done' => 0, 'total' => max(0, $total), 'current' => null];
    write_import_progress($key);
}

function import_progress_advance(string $key): void
{
    $steps = &import_progress_steps();

    if (!isset($steps[$key])) {
        return;
    }

    ++$steps[$key]['done'];
    write_import_progress($key);
}

function import_progress_current(string $key, string $current): void
{
    $steps = &import_progress_steps();

    if (!isset($steps[$key])) {
        return;
    }

    $steps[$key]['current'] = $current;
    write_import_progress($key);
}

function import_progress_finish(string $key): void
{
    $steps = &import_progress_steps();
    unset($steps[$key]);
    $path = import_progress_path($key);

    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Best effort: the progress never makes a task fail.
 */
function write_import_progress(string $key): void
{
    $path = import_progress_path($key);
    $directory = \dirname($path);

    if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
        return;
    }

    $steps = &import_progress_steps();
    $temporary = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($steps[$key] + ['updatedAt' => time()], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

    if (false !== $json && false !== @file_put_contents($temporary, $json)) {
        @rename($temporary, $path);
    }
}
