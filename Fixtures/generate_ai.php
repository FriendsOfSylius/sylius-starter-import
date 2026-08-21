<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use function Castor\io;

require_once __DIR__ . '/writer.php';

function generate_ai_import_fixtures(?string $projectSlug): void
{
    $prepared = prepare_import_fixture_generation(
        $projectSlug,
        'No import data found in .castor/import/var/{project-slug}/. Run sylius:import:ai:build first.',
    );

    if (null === $prepared) {
        return;
    }

    $projectSlug = $prepared['slug'];
    $productsData = $prepared['productsData'];
    $collections = $prepared['collections'];
    $products = $prepared['products'];

    io()->title(\sprintf('Generating AI import fixtures for %s', $projectSlug));

    if ('ai' !== ($productsData['mode'] ?? '')) {
        io()->error(\sprintf('Project "%s" is not an AI import (mode=%s).', $projectSlug, $productsData['mode'] ?? 'unknown'));

        return;
    }

    if ([] === $products) {
        io()->error('No products found in import YAML.');

        return;
    }

    import_log(\sprintf(
        'YAML loaded — %d product(s), %d collection(s).',
        \count($products),
        \count($collections),
    ));

    $productMap = build_product_map($products);
    $taxonIndex = build_taxon_index($collections, $projectSlug);
    $styleReferencePrompt = find_first_image_prompt($products);

    io()->section('Step 1/3 — AI image generation');
    $taxonImageFixture = build_ai_taxon_image_fixture($taxonIndex, $projectSlug, $styleReferencePrompt);

    io()->section('Step 2/3 — Fixture generation');
    [$taxonAssignments, $priceAssignments, $promoProductIds, $selectedProducts] = build_ai_product_assignments($productMap, $taxonIndex);
    $taxonFixture = build_taxon_fixture($taxonIndex, $projectSlug);
    [$productFixture, $productPriceFixture] = build_ai_product_fixtures(
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
        'ai',
    );

    io()->success(\sprintf('AI import fixture files generated for %s.', $projectSlug));
}
