<?php

declare(strict_types=1);

namespace Sylius\Starter\Import;

use function Castor\io;

require_once __DIR__ . '/writer.php';

/**
 * Generates the same fixture files whatever the catalog source: only the product selection, taxon assignment and
 * pricing depend on whether the YAML comes from an existing site or from the AI build.
 */
function generate_import_fixtures(?string $projectSlug, int $limit, ?string $domain, ?string $subdomain): void
{
    $context = ImportContext::current();

    if ($limit <= 0) {
        io()->error('Limit must be greater than 0.');

        return;
    }

    $prepared = prepare_import_fixture_generation(
        $projectSlug,
        \sprintf('No import data found in %s/{project-slug}/. Run sylius:import:existing:fetch or sylius:import:ai:build first.', $context->importDir()),
    );

    if (null === $prepared) {
        return;
    }

    $projectSlug = $prepared['slug'];
    $collections = $prepared['collections'];
    $products = $prepared['products'];
    $mode = 'ai' === ($prepared['productsData']['mode'] ?? '') ? 'ai' : 'existing';

    io()->title(\sprintf('Generating import fixtures for %s', $projectSlug));
    import_log(\sprintf(
        'YAML loaded (%s) — %d product(s), %d collection(s).',
        $mode,
        \count($products),
        \count($collections),
    ));

    if ([] === $collections) {
        io()->warning('No collections found in import YAML. Taxons will not be generated.');
    }

    if ([] === $products) {
        io()->error(\sprintf('No products in import YAML for project "%s". Re-run the import first.', $projectSlug));

        return;
    }

    $target = persist_import_shop_target($projectSlug, $subdomain);
    import_log(\sprintf(
        $target['shared'] ? 'No subdomain: importing into the default channel %s.' : 'Dedicated channel %s.',
        $target['channel'],
    ));

    $taxonIndex = build_taxon_index($collections, $projectSlug);
    import_log(\sprintf('Taxon index: %d taxon(s).', \count($taxonIndex)));

    io()->section('Step 1/3 — Product selection');
    $selection = 'ai' === $mode
        ? select_ai_import_products($products, $taxonIndex, $limit)
        : select_existing_import_products($products, $collections, $taxonIndex, $limit);

    if (null === $selection) {
        return;
    }

    [$taxonAssignments, $priceAssignments, $promoProductIds, $selectedProducts] = $selection;

    $taxonCount = \count($taxonIndex);
    [$taxonIndex, $taxonAssignments] = limit_menu_taxons($taxonIndex, $taxonAssignments);
    import_log(\sprintf(
        'Menu taxons: %d/%d kept (those holding selected products, %d at most).',
        \count($taxonIndex),
        $taxonCount,
        IMPORT_MENU_TAXON_LIMIT,
    ));

    io()->section('Step 2/3 — Fixture generation');
    $taxonFixture = build_taxon_fixture($taxonIndex, $projectSlug);
    $taxonImageFixture = build_taxon_image_fixture($taxonIndex, $projectSlug, find_first_image_prompt($products));
    [$productFixture, $productPriceFixture] = build_product_fixtures(
        $selectedProducts,
        $projectSlug,
        $taxonAssignments,
        $priceAssignments,
        $promoProductIds,
    );

    io()->section('Step 3/3 — Write fixture files');
    write_import_fixture_files(
        $projectSlug,
        $taxonIndex,
        $selectedProducts,
        $taxonFixture,
        $taxonImageFixture,
        $productFixture,
        $productPriceFixture,
        $mode,
        $domain,
        $subdomain,
    );

    io()->success(\sprintf('Import fixture files generated for %s.', $projectSlug));
}

/**
 * The AI build already assigned a collection and a price to each product.
 *
 * @param array<int, array<string, mixed>>                                           $products
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 *
 * @return array{0: array<int, string>, 1: array<int, int>, 2: int[], 3: array<int, array<string, mixed>>}
 */
function select_ai_import_products(array $products, array $taxonIndex, int $limit): array
{
    return build_ai_product_assignments(build_product_map(\array_slice($products, 0, $limit)), $taxonIndex);
}

/**
 * Lets the AI pick the products to import from the fetched catalog, then assign their taxon and price.
 *
 * @param array<int, array<string, mixed>>                                           $products
 * @param array<int, array<string, mixed>>                                           $collections
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 *
 * @return array{0: array<int, string>, 1: array<int, int>, 2: int[], 3: array<int, array<string, mixed>>}|null
 */
function select_existing_import_products(array $products, array $collections, array $taxonIndex, int $limit): ?array
{
    try {
        import_log(\sprintf('AI provider: %s, model: %s.', ai_provider(), ai_model()));
        if ('ollama' === ai_provider()) {
            import_log(\sprintf('Ollama base URL: %s', ai_base_url()));
        }
        $platform = create_ai_platform();
    } catch (\RuntimeException $exception) {
        io()->error($exception->getMessage());

        return null;
    }

    $catalog = build_product_catalog($products);
    $productMap = build_product_map($products);
    $collectionNames = build_collection_names($collections);

    if ([] === $catalog) {
        io()->error('No products with titles found in import YAML.');

        return null;
    }

    if ($limit > \count($catalog)) {
        io()->warning(\sprintf('Requested limit %d exceeds catalog size %d, using %d.', $limit, \count($catalog), \count($catalog)));
        $limit = \count($catalog);
    }

    io()->info(\sprintf('Selecting %d products from %d via AI...', $limit, \count($catalog)));

    try {
        $selectedIds = select_products_via_ai($platform, $catalog, $collectionNames, $limit);
    } catch (\Throwable $exception) {
        io()->error(\sprintf('AI product selection failed: %s', $exception->getMessage()));

        return null;
    }

    $promoProductIds = pick_promo_product_ids($catalog);
    $selectedBeforePromo = \count($selectedIds);
    $selectedIds = merge_promo_products_into_selection($selectedIds, $promoProductIds, $limit);
    import_log(\sprintf(
        'Promo products merged: %d always-on-promo slot(s), selection %d → %d product(s).',
        \count($promoProductIds),
        $selectedBeforePromo,
        \count($selectedIds),
    ));

    io()->info(\sprintf('Assigning taxons and prices for %d selected products via AI...', \count($selectedIds)));

    try {
        $enrichment = assign_taxons_via_ai($platform, $catalog, $selectedIds, $collectionNames);
    } catch (\Throwable $exception) {
        io()->error(\sprintf('AI product enrichment failed: %s', $exception->getMessage()));

        return null;
    }

    $selectedProducts = deduplicate_products_for_fixtures(filter_products_by_ids($productMap, $selectedIds));
    import_log(\sprintf('Products after slug dedup: %d.', \count($selectedProducts)));

    return [
        resolve_taxon_assignments($enrichment, $taxonIndex),
        build_price_assignments($enrichment),
        $promoProductIds,
        $selectedProducts,
    ];
}
