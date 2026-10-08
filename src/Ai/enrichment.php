<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use Castor\Sylius\Import\Dto\CollectionEntry;
use Castor\Sylius\Import\Dto\CollectionExtraction;
use Castor\Sylius\Import\Dto\ProductSelectionEntry;
use Castor\Sylius\Import\Dto\ProductSelectionExtraction;
use Castor\Sylius\Import\Dto\ProductTaxonAssignmentEntry;
use Castor\Sylius\Import\Dto\ProductTaxonAssignmentExtraction;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;

use function Castor\io;

function invoke_ai_structured(
    object $platform,
    MessageBag $messages,
    string $dtoClass,
    string $label = 'AI request',
    ?array $responseFormat = null,
    array $extraOptions = [],
): string {
    $responseFormatFactory = new ResponseFormatFactory();
    $options = array_merge([
        'response_format' => $responseFormat ?? $responseFormatFactory->create($dtoClass),
        'temperature' => 0.2,
    ], $extraOptions);

    $dtoName = (new \ReflectionClass($dtoClass))->getShortName();
    import_log(\sprintf(
        '→ %s: calling %s via %s (%s)...',
        $label,
        ai_model(),
        ai_provider(),
        $dtoName,
    ));

    $lastException = null;
    $startedAt = microtime(true);

    for ($attempt = 1; $attempt <= 2; ++$attempt) {
        if ($attempt > 1) {
            import_log(\sprintf('→ %s: retry attempt %d/2...', $label, $attempt));
        }

        try {
            $result = $platform->invoke(ai_model(), $messages, $options);
            $text = extract_ai_response_text($result);

            if ('' !== trim($text)) {
                import_log(\sprintf(
                    '← %s: done in %.1fs (%d bytes).',
                    $label,
                    microtime(true) - $startedAt,
                    \strlen($text),
                ));

                return $text;
            }

            $lastException = new \RuntimeException('AI returned empty content.');
        } catch (\Throwable $exception) {
            $lastException = $exception;
            import_log(\sprintf('← %s: attempt %d failed — %s', $label, $attempt, $exception->getMessage()));
        }

        if ($attempt < 2) {
            io()->warning('AI request returned no content, retrying once...');
        }
    }

    throw new \RuntimeException(
        \sprintf('AI request failed: %s', $lastException?->getMessage() ?? 'unknown error'),
        0,
        $lastException,
    );
}

function extract_ai_response_text(object $result): string
{
    if (method_exists($result, 'getContent')) {
        $content = $result->getContent();

        if (\is_string($content)) {
            return $content;
        }
    }

    return $result->asText();
}

/**
 * @param array<int, string> $catalog
 *
 * @return int[]
 */
function select_products_via_ai(object $platform, array $catalog, array $collectionNames, int $limit): array
{
    $catalogSize = \count($catalog);
    $sampleSize = min(AI_CATALOG_SAMPLE_SIZE, $catalogSize);
    $selectionContext = build_catalog_selection_payload($catalog, $sampleSize);
    $indexMap = $selectionContext['index_map'];
    $sampleSize = $selectionContext['sample_size'];

    import_log(\sprintf(
        'Building AI catalog sample: %d product(s) from %d (target selection: %d).',
        $sampleSize,
        $catalogSize,
        $limit,
    ));

    import_log(\sprintf(
        'Catalog payload ready: %d bytes.',
        \strlen($selectionContext['payload']),
    ));

    $messages = new MessageBag(
        Message::forSystem(
            <<<PROMPT
                You receive a JSON object with:
                - "products": map of product_id (integer keys from 0 to {$sampleSize} - 1) to product title

                Select exactly {$limit} products that are the most interesting and representative for a demo e-commerce store.
                Prefer variety across product types; avoid near-duplicate titles.
                Return exactly {$limit} entries in the products array.
                Return each selected product_id as an integer key from the products map.
                PROMPT
        ),
        Message::ofUser($selectionContext['payload']),
    );

    $aiOptions = [];

    if ('ollama' === ai_provider()) {
        $aiOptions['num_predict'] = max(8192, $limit * 64);
    }

    try {
        $selectedIds = [];
        $lastException = null;

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            if ($attempt > 1) {
                io()->warning('AI product selection returned insufficient results, retrying once...');
                import_log('Retrying AI product selection...');
            }

            try {
                $text = invoke_ai_structured(
                    $platform,
                    $messages,
                    ProductSelectionExtraction::class,
                    'Product selection',
                    extraOptions: $aiOptions,
                );
                import_log('Parsing AI product selection response...');
                $extraction = deserialize_product_selection($text);
                $sampleIndices = validate_selected_sample_indices($extraction, $sampleSize, $limit);
                $selectedIds = map_sample_indices_to_catalog_ids($sampleIndices, $indexMap);

                if (\count($selectedIds) > 0 || 2 === $attempt) {
                    break;
                }
            } catch (\Throwable $exception) {
                $lastException = $exception;
                import_log(\sprintf('Product selection attempt %d failed: %s', $attempt, $exception->getMessage()));
            }
        }

        if ([] === $selectedIds && null !== $lastException) {
            throw $lastException;
        }

        $selectedIds = fill_selected_product_ids($catalog, $selectedIds, $limit);
        import_log(\sprintf('Product selection validated: %d product(s) selected.', \count($selectedIds)));

        return $selectedIds;
    } catch (\Throwable $exception) {
        io()->warning(\sprintf(
            'AI product selection failed (%s), using deterministic fallback.',
            $exception->getMessage(),
        ));
        import_log(\sprintf('Fallback selection: picking %d product(s) evenly from catalog.', $limit));

        return fallback_select_product_ids($catalog, $limit);
    }
}

/**
 * @return int[]
 */
function validate_selected_sample_indices(ProductSelectionExtraction $extraction, int $sampleSize, int $limit): array
{
    $selected = [];

    foreach ($extraction->products as $entry) {
        $id = $entry->product_id;

        if ($id < 0 || $id >= $sampleSize || isset($selected[$id])) {
            continue;
        }

        $selected[$id] = $id;
    }

    $selectedIds = array_values($selected);

    if (\count($selectedIds) > $limit) {
        io()->warning(\sprintf(
            'AI selected %d products, truncating to %d.',
            \count($selectedIds),
            $limit,
        ));

        return \array_slice($selectedIds, 0, $limit);
    }

    return $selectedIds;
}

/**
 * @param int[] $sampleIndices
 * @param array<int, int> $indexMap
 *
 * @return int[]
 */
function map_sample_indices_to_catalog_ids(array $sampleIndices, array $indexMap): array
{
    $catalogIds = [];

    foreach ($sampleIndices as $index) {
        if (!isset($indexMap[$index])) {
            continue;
        }

        $catalogId = $indexMap[$index];
        $catalogIds[$catalogId] = $catalogId;
    }

    return array_values($catalogIds);
}

/**
 * @param int[] $selectedIds
 *
 * @return int[]
 */
function fill_selected_product_ids(array $catalog, array $selectedIds, int $limit): array
{
    $selected = [];

    foreach ($selectedIds as $id) {
        if ($id >= 0 && isset($catalog[$id])) {
            $selected[$id] = $id;
        }
    }

    $selectedIds = array_values($selected);

    if (\count($selectedIds) >= $limit) {
        return \array_slice($selectedIds, 0, $limit);
    }

    if (\count($selectedIds) < $limit && \count($catalog) > 0) {
        io()->warning(\sprintf(
            'AI selected only %d products, filling up to %d from the catalog.',
            \count($selectedIds),
            $limit,
        ));

        foreach (fallback_select_product_ids($catalog, $limit) as $id) {
            if (!isset($selected[$id])) {
                $selectedIds[] = $id;
                $selected[$id] = $id;
            }

            if (\count($selectedIds) >= $limit) {
                break;
            }
        }
    }

    return \array_slice($selectedIds, 0, $limit);
}

/**
 * @param array<int, string> $catalog
 *
 * @return int[]
 */
function pick_promo_product_ids(array $catalog, int $count = IMPORT_PROMO_PRODUCT_COUNT): array
{
    $catalogSize = \count($catalog);

    if (0 === $catalogSize || $count <= 0) {
        return [];
    }

    $count = min($count, $catalogSize);
    $ids = [];

    for ($i = 0; $i < $count; ++$i) {
        $ids[] = (int) floor($i * $catalogSize / $count);
    }

    return array_values(array_unique($ids));
}

/**
 * @param int[] $selectedIds
 * @param int[] $promoIds
 *
 * @return int[]
 */
function merge_promo_products_into_selection(array $selectedIds, array $promoIds, int $limit): array
{
    if ([] === $promoIds) {
        return \array_slice($selectedIds, 0, $limit);
    }

    $promoIds = array_values(array_unique($promoIds));
    $promoSet = array_fill_keys($promoIds, true);
    $othersOrdered = [];

    foreach ($selectedIds as $id) {
        if (!isset($promoSet[$id])) {
            $othersOrdered[] = $id;
        }
    }

    $effectiveLimit = max($limit, \count($promoIds));
    $othersAllowed = max(0, $effectiveLimit - \count($promoIds));

    return array_merge($promoIds, \array_slice($othersOrdered, 0, $othersAllowed));
}

function compute_promo_original_price_cents(int $priceCents): int
{
    return (int) round($priceCents * IMPORT_PROMO_ORIGINAL_PRICE_RATIO);
}



/**
 * @param string[] $collectionNames
 */
function build_taxon_assignment_response_format(array $collectionNames): array
{
    $responseFormat = (new ResponseFormatFactory())->create(ProductTaxonAssignmentExtraction::class);
    $responseFormat['json_schema']['schema']['properties']['assignments']['items']['properties']['collection_name']['enum'] = array_values($collectionNames);

    return $responseFormat;
}

/**
 * @param int[] $selectedIds
 *
 * @return array<int, array{collection_name: string, price_eur: float}>
 */
function assign_taxons_via_ai(object $platform, array $catalog, array $selectedIds, array $collectionNames): array
{
    import_log(\sprintf(
        'Building enrichment payload for %d selected product(s)...',
        \count($selectedIds),
    ));

    $payload = format_selected_for_ai($catalog, $selectedIds, $collectionNames);
    import_log(\sprintf('Enrichment payload ready: %d bytes.', \strlen($payload)));

    $collectionsJson = json_encode($collectionNames, \JSON_UNESCAPED_UNICODE) ?: '[]';

    $messages = new MessageBag(
        Message::forSystem(
            <<<PROMPT
                You receive a JSON object with:
                - "products": map of product_id (string key) to product title
                - "collections": closed list of allowed category names — the ONLY valid values for collection_name

                For each product_id in "products", return one assignment with:
                - collection_name: MUST be copied exactly from the "collections" array (character-for-character)
                - price_eur: a realistic retail price in euros (float, 2 decimals), consistent with the title and collection (e.g. multi-packs cost more than single items)

                STRICT RULES for collection_name:
                - Pick exactly one value from "collections". No other value is accepted.
                - Do NOT invent, translate, paraphrase, or combine category names.
                - Do NOT derive category names from product titles.
                - If no collection fits well, use "category".

                Allowed collections (same as JSON "collections" field): {$collectionsJson}
                Typical price range: 5–150 EUR for underwear and accessories.
                PROMPT
        ),
        Message::ofUser($payload),
    );

    $text = invoke_ai_structured(
        $platform,
        $messages,
        ProductTaxonAssignmentExtraction::class,
        'Taxon & price assignment',
        build_taxon_assignment_response_format($collectionNames),
    );
    import_log('Parsing AI taxon/price assignment response...');
    $extraction = deserialize_product_taxon_assignment($text);
    $enrichment = validate_product_enrichment($extraction, $selectedIds, $collectionNames);
    import_log(\sprintf('Enrichment validated: %d product(s) with taxon and price.', \count($enrichment)));

    return $enrichment;
}

function normalize_price_eur(float $priceEur, int $productId): float
{
    if ($priceEur <= 0) {
        io()->warning(\sprintf(
            'Missing or invalid price for product_id %d, falling back to %.2f EUR.',
            $productId,
            IMPORT_PRICE_EUR_FALLBACK,
        ));

        return IMPORT_PRICE_EUR_FALLBACK;
    }

    $normalized = round($priceEur, 2);

    if ($normalized < IMPORT_PRICE_EUR_MIN) {
        io()->warning(\sprintf(
            'Price %.2f EUR for product_id %d is too low, clamping to %.2f EUR.',
            $normalized,
            $productId,
            IMPORT_PRICE_EUR_MIN,
        ));
        $normalized = IMPORT_PRICE_EUR_MIN;
    }

    return $normalized;
}

/**
 * @param int[]    $selectedIds
 * @param string[] $collectionNames
 *
 * @return array<int, array{collection_name: string, price_eur: float}>
 */
function validate_product_enrichment(
    ProductTaxonAssignmentExtraction $extraction,
    array $selectedIds,
    array $collectionNames,
): array {
    $allowedNames = array_fill_keys($collectionNames, true);
    $enrichment = [];

    foreach ($extraction->assignments as $entry) {
        if (!\in_array($entry->product_id, $selectedIds, true)) {
            continue;
        }

        $collectionName = $entry->collection_name;

        if (!isset($allowedNames[$collectionName])) {
            io()->warning(\sprintf(
                'Unknown collection "%s" for product_id %d, falling back to "category".',
                $collectionName,
                $entry->product_id,
            ));
            $collectionName = 'category';
        }

        $enrichment[$entry->product_id] = [
            'collection_name' => $collectionName,
            'price_eur' => normalize_price_eur($entry->price_eur, $entry->product_id),
        ];
    }

    foreach ($selectedIds as $id) {
        if (!isset($enrichment[$id])) {
            io()->warning(\sprintf('No enrichment for product_id %d, falling back to "category" and %.2f EUR.', $id, IMPORT_PRICE_EUR_FALLBACK));
            $enrichment[$id] = [
                'collection_name' => 'category',
                'price_eur' => IMPORT_PRICE_EUR_FALLBACK,
            ];
        }
    }

    return $enrichment;
}

/**
 * @param array<int, array{code: string, name: string, slug: string}> $taxonIndex
 */
function resolve_collection_to_taxon_code(string $name, array $taxonIndex): string
{
    if ('category' === $name) {
        return 'category';
    }

    $normalized = normalize_label_for_matching($name);

    foreach ($taxonIndex as $taxon) {
        if (normalize_label_for_matching($taxon['name']) === $normalized) {
            return $taxon['code'];
        }
    }

    return 'category';
}

/**
 * @param array<int, array<string, mixed>> $productMap
 * @param int[]                            $selectedIds
 *
 * @return array<int, array<string, mixed>>
 */
function filter_products_by_ids(array $productMap, array $selectedIds): array
{
    $filtered = [];

    foreach ($selectedIds as $id) {
        if (isset($productMap[$id])) {
            $filtered[] = $productMap[$id];
        }
    }

    return $filtered;
}

/**
 * @param array<int, array{collection_name: string, price_eur: float}> $enrichment
 * @param array<int, array{code: string, name: string, slug: string}>   $taxonIndex
 *
 * @return array<int, string>
 */
function resolve_taxon_assignments(array $enrichment, array $taxonIndex): array
{
    $resolved = [];

    foreach ($enrichment as $productId => $data) {
        $resolved[$productId] = resolve_collection_to_taxon_code($data['collection_name'], $taxonIndex);
    }

    return $resolved;
}

/**
 * @param array<int, array{collection_name: string, price_eur: float}> $enrichment
 *
 * @return array<int, int>
 */
function build_price_assignments(array $enrichment): array
{
    $prices = [];

    foreach ($enrichment as $productId => $data) {
        $prices[$productId] = (int) round($data['price_eur'] * 100);
    }

    return $prices;
}

/**
 * @param array<string, mixed> $product
 * @param array<string, true>  $usedCodes
 */
