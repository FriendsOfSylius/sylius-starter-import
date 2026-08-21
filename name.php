<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

require_once __DIR__ . '/url.php';
require_once __DIR__ . '/paths.php';

function normalize_import_name(string $name): string
{
    $name = trim($name);

    if ('' === $name) {
        throw new \InvalidArgumentException('Project name is required.');
    }

    $normalized = mb_strtolower($name);

    if (\function_exists('transliterator_transliterate')) {
        $normalized = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $normalized) ?: $normalized;
    } elseif (\function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        $normalized = false !== $converted ? strtolower($converted) : $normalized;
    }

    $normalized = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
    $slug = trim($normalized, '-');

    if ('' === $slug) {
        throw new \InvalidArgumentException('Project name must contain at least one alphanumeric character.');
    }

    return $slug;
}

/**
 * @param list<string> $availableSlugs
 */
function resolve_import_project_slug(?string $input, array $availableSlugs): ?string
{
    if (null === $input || '' === trim($input)) {
        return null;
    }

    $input = trim($input);

    try {
        $slug = normalize_import_name($input);

        if (\in_array($slug, $availableSlugs, true)) {
            return $slug;
        }
    } catch (\InvalidArgumentException) {
    }

    if (\in_array($input, $availableSlugs, true)) {
        return $input;
    }

    try {
        $hostSlug = normalize_import_host($input);

        if (\in_array($hostSlug, $availableSlugs, true)) {
            return $hostSlug;
        }
    } catch (\InvalidArgumentException) {
    }

    foreach ($availableSlugs as $slug) {
        if (0 === strcasecmp($slug, $input)) {
            return $slug;
        }
    }

    return null;
}

/**
 * Resolve a CLI-provided project identifier against available import data.
 *
 * @throws \InvalidArgumentException
 */
function resolve_cli_project_slug(string $input): string
{
    $input = trim($input);
    $slugs = discover_import_hosts();
    $normalizedSlug = normalize_import_name($input);

    return resolve_import_project_slug($input, $slugs) ?? $normalizedSlug;
}
