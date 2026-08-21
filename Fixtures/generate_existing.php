<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use function Castor\io;

require_once __DIR__ . '/writer.php';

function generate_existing_import_fixtures(?string $projectSlug, int $limit): void
{
    if ($limit <= 0) {
        io()->error('Limit must be greater than 0.');

        return;
    }

    $prepared = prepare_import_fixture_generation(
        $projectSlug,
        'No import data found in .castor/import/var/{project-slug}/. Run sylius:import:existing:fetch first.',
    );

    if (null === $prepared) {
        return;
    }

    $projectSlug = $prepared['slug'];
    $productsData = $prepared['productsData'];
    $collections = $prepared['collections'];
    $products = $prepared['products'];

    io()->title(\sprintf('Generating import fixtures for %s', $projectSlug));

    import_log(\sprintf('Loading import YAML for project "%s"...', $projectSlug));

    if ('ai' === ($productsData['mode'] ?? '')) {
        io()->error(\sprintf('Project "%s" is an AI import. Use sylius:import:fixtures:generate ai instead.', $projectSlug));

        return;
    }

    import_log(\sprintf(
        'YAML loaded — %d product(s), %d collection(s).',
        \count($products),
        \count($collections),
    ));

    if ([] === $collections) {
        io()->warning('No collections found in import YAML. Taxons will not be generated.');
    }

    if ([] === $products) {
        io()->error(\sprintf(
            'No products in import YAML. Re-fetch the site:%s  castor sylius:import:existing:fetch --project=%s',
            \PHP_EOL,
            $projectSlug,
        ));

        return;
    }

    io()->section('Step 1/5 — AI setup');

    try {
        import_log(\sprintf(
            'Environment loaded — AI provider: %s, model: %s.',
            ai_provider(),
            ai_model(),
        ));
        if ('ollama' === ai_provider()) {
            import_log(\sprintf('Ollama base URL: %s', ai_base_url()));
        }
        import_log('Creating AI platform...');
        $platform = create_ai_platform();
        import_log('AI platform ready.');
    } catch (\RuntimeException $exception) {
        io()->error($exception->getMessage());

        return;
    }

    io()->section('Step 2/5 — Catalog preparation');
    import_log('Building product catalog and collection names...');

    $catalog = build_product_catalog($products);
    $productMap = build_product_map($products);
    $collectionNames = build_collection_names($collections);

    import_log(\sprintf(
        'Catalog ready — %d product(s) with titles, %d collection name(s).',
        \count($catalog),
        \count($collectionNames),
    ));

    if ([] === $catalog) {
        io()->error('No products with titles found in import YAML.');

        return;
    }

    if ($limit > \count($catalog)) {
        io()->warning(\sprintf(
            'Requested limit %d exceeds catalog size %d, using %d.',
            $limit,
            \count($catalog),
            \count($catalog),
        ));
        $limit = \count($catalog);
    }

    io()->section('Step 3/5 — AI product selection');
    io()->info(\sprintf(
        'Selecting %d products from %d via AI...',
        $limit,
        \count($catalog),
    ));

    try {
        $selectedIds = select_products_via_ai($platform, $catalog, $collectionNames, $limit);
    } catch (\Throwable $exception) {
        io()->error(\sprintf('AI product selection failed: %s', $exception->getMessage()));

        return;
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

    io()->section('Step 4/5 — AI taxon & price assignment');
    io()->info(\sprintf('Assigning taxons and prices for %d selected products via AI...', \count($selectedIds)));

    try {
        $enrichment = assign_taxons_via_ai($platform, $catalog, $selectedIds, $collectionNames);
    } catch (\Throwable $exception) {
        io()->error(\sprintf('AI product enrichment failed: %s', $exception->getMessage()));

        return;
    }

    io()->section('Step 5/5 — Fixture generation');
    import_log('Building taxon index from collections...');
    $taxonIndex = build_taxon_index($collections, $projectSlug);
    import_log(\sprintf('Taxon index: %d taxon(s).', \count($taxonIndex)));

    import_log('Resolving taxon assignments and prices...');
    $taxonAssignments = resolve_taxon_assignments($enrichment, $taxonIndex);
    $priceAssignments = build_price_assignments($enrichment);
    import_log(\sprintf(
        'Resolved %d taxon assignment(s) and %d price(s).',
        \count($taxonAssignments),
        \count($priceAssignments),
    ));

    import_log(\sprintf('Filtering %d selected product(s) from catalog...', \count($selectedIds)));
    $selectedProducts = deduplicate_products_for_fixtures(filter_products_by_ids($productMap, $selectedIds));
    import_log(\sprintf('Products after slug dedup: %d.', \count($selectedProducts)));

    import_log('Building taxon fixture...');
    $taxonFixture = build_taxon_fixture($taxonIndex, $projectSlug);

    import_log('Downloading taxon images and building taxon image fixture...');
    $taxonImageFixture = build_taxon_image_fixture($taxonIndex, $projectSlug);

    import_log('Downloading product images and building product fixtures...');
    [$productFixture, $productPriceFixture] = build_product_fixtures(
        $selectedProducts,
        $projectSlug,
        $taxonAssignments,
        $priceAssignments,
        $promoProductIds,
    );

    write_import_fixture_files(
        $projectSlug,
        $taxonIndex,
        $selectedProducts,
        $taxonFixture,
        $taxonImageFixture,
        $productFixture,
        $productPriceFixture,
        'existing',
    );

    io()->success(\sprintf('Import fixture files generated for %s.', $projectSlug));
}
