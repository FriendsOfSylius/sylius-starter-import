<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use function Castor\io;

/**
 * @return array{
 *     slug: string,
 *     productsData: array<string, mixed>,
 *     collectionsData: array<string, mixed>,
 *     products: array<int, array<string, mixed>>,
 *     collections: array<int, array<string, mixed>>,
 * }|null
 */
function prepare_import_fixture_generation(?string $projectSlug, string $emptyDataHint): ?array
{
    try {
        ensure_import_ai_ready();
    } catch (\RuntimeException $exception) {
        io()->error($exception->getMessage());

        return null;
    }

    ensure_import_scaffold();

    $slugs = discover_import_hosts();

    if ([] === $slugs) {
        io()->error($emptyDataHint);

        return null;
    }

    import_log(\sprintf('Discovered %d import project(s): %s.', \count($slugs), implode(', ', $slugs)));

    if (null === $projectSlug || '' === trim($projectSlug)) {
        io()->error('Project slug is required. Pass --project.');

        return null;
    }

    $resolvedSlug = resolve_import_project_slug($projectSlug, $slugs);

    if (null === $resolvedSlug) {
        io()->error(\sprintf('No import data found for project "%s".', $projectSlug));

        return null;
    }

    $projectSlug = $resolvedSlug;
    import_log(\sprintf('Using import project slug: %s', $projectSlug));

    ensure_castor_var_dir();

    io()->section('Shop images');
    write_shop_images_config($projectSlug);

    try {
        $productsData = load_import_yaml($projectSlug);
        $collectionsData = load_collections_yaml($projectSlug);
    } catch (\RuntimeException $exception) {
        io()->error($exception->getMessage());

        return null;
    }

    /** @var array<int, array<string, mixed>> $products */
    $products = $productsData['products'] ?? [];

    /** @var array<int, array<string, mixed>> $collections */
    $collections = $collectionsData['collections'] ?? [];

    return [
        'slug' => $projectSlug,
        'productsData' => $productsData,
        'collectionsData' => $collectionsData,
        'products' => $products,
        'collections' => $collections,
    ];
}

function warn_if_fixture_slug_mismatch(string $resolvedSlug): void
{
    $lastGenerated = read_last_generated_import();

    if (null === $lastGenerated) {
        io()->comment('No fixture generation marker found. Run sylius:import:fixtures:generate before loading if fixtures are missing.');

        return;
    }

    if ($lastGenerated['slug'] === $resolvedSlug) {
        return;
    }

    io()->warning(\sprintf(
        'Generated PHP fixtures are for "%s" (at %s) but you are loading "%s". Re-run sylius:import:fixtures:generate for the correct project first.',
        $lastGenerated['slug'],
        $lastGenerated['generated_at'] ?: 'unknown time',
        $resolvedSlug,
    ));
}
