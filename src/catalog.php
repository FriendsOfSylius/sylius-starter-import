<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

function build_product_catalog(array $products): array
{
    $catalog = [];
    $id = 0;

    foreach ($products as $product) {
        $title = trim((string) ($product['title'] ?? ''));

        if ('' === $title) {
            continue;
        }

        $catalog[$id] = $title;
        ++$id;
    }

    return $catalog;
}

/**
 * @param array<int, array<string, mixed>> $products
 *
 * @return array<int, array<string, mixed>>
 */
function build_product_map(array $products): array
{
    $map = [];
    $id = 0;

    foreach ($products as $product) {
        $title = trim((string) ($product['title'] ?? ''));

        if ('' === $title) {
            continue;
        }

        $map[$id] = array_merge($product, ['id' => $id]);
        ++$id;
    }

    return $map;
}

/**
 * @param array<int, string> $catalog
 *
 * @return array<int, string>
 */
function sample_catalog_for_ai(array $catalog, int $maxSize): array
{
    if (\count($catalog) <= $maxSize) {
        return $catalog;
    }

    $keys = array_keys($catalog);
    $total = \count($keys);
    $sampled = [];

    for ($i = 0; $i < $maxSize; ++$i) {
        $index = (int) round($i * ($total - 1) / max($maxSize - 1, 1));
        $key = $keys[$index];
        $sampled[$key] = $catalog[$key];
    }

    return $sampled;
}

/**
 * @param array<int, string> $catalog
 *
 * @return int[]
 */
function fallback_select_product_ids(array $catalog, int $limit): array
{
    $sampled = sample_catalog_for_ai($catalog, $limit);

    return array_map('intval', array_keys($sampled));
}

/**
 * @param array<int, string> $catalog
 * @param string[]           $collectionNames
 */
function format_catalog_for_ai(array $catalog, array $collectionNames, int $maxProducts = AI_CATALOG_SAMPLE_SIZE): string
{
    $products = sample_catalog_for_ai($catalog, $maxProducts);
    $payload = [];

    foreach ($products as $id => $title) {
        $payload[(string) $id] = $title;
    }

    return json_encode(
        [
            'products' => $payload,
            'collections' => $collectionNames,
        ],
        \JSON_UNESCAPED_UNICODE,
    ) ?: '{}';
}

/**
 * Build a compact AI payload for product selection with sequential sample indices (0..N-1).
 *
 * @param array<int, string> $catalog
 *
 * @return array{payload: string, index_map: array<int, int>, sample_size: int}
 */
function build_catalog_selection_payload(array $catalog, int $maxProducts): array
{
    $sampled = sample_catalog_for_ai($catalog, $maxProducts);
    $indexMap = [];
    $products = [];
    $index = 0;

    foreach ($sampled as $catalogId => $title) {
        $indexMap[$index] = (int) $catalogId;
        $products[(string) $index] = $title;
        ++$index;
    }

    return [
        'payload' => json_encode(['products' => $products], \JSON_UNESCAPED_UNICODE) ?: '{"products":{}}',
        'index_map' => $indexMap,
        'sample_size' => $index,
    ];
}

/**
 * @param array<int, string> $catalog
 * @param int[]              $selectedIds
 * @param string[]           $collectionNames
 */
function format_selected_for_ai(array $catalog, array $selectedIds, array $collectionNames): string
{
    $selected = [];

    foreach ($selectedIds as $id) {
        if (isset($catalog[$id])) {
            $selected[(string) $id] = $catalog[$id];
        }
    }

    return json_encode(
        [
            'products' => $selected,
            'collections' => $collectionNames,
        ],
        \JSON_UNESCAPED_UNICODE,
    ) ?: '{}';
}

/**
 * @param array<int, array<string, mixed>> $collections
 *
 * @return string[]
 */
function build_collection_names(array $collections): array
{
    $names = ['category'];

    foreach ($collections as $collection) {
        $name = trim((string) ($collection['name'] ?? ''));

        if ('' !== $name) {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

function resolve_product_code(array $product, array &$usedCodes): string
{
    $title = trim((string) ($product['title'] ?? ''));
    $url = trim((string) ($product['url'] ?? ''));
    $code = product_code_from_url('' !== $url ? $url : $title);

    if (isset($usedCodes[$code])) {
        $suffix = 2;

        while (isset($usedCodes[\sprintf('%s_%d', $code, $suffix)])) {
            ++$suffix;
        }

        $code = \sprintf('%s_%d', $code, $suffix);
    }

    $usedCodes[$code] = true;

    return $code;
}

function product_slug_from_code(string $code): string
{
    return str_replace('_', '-', $code);
}

/**
 * @param array<string, true> $usedSlugs
 */
function resolve_product_slug(string $code, array &$usedSlugs): string
{
    $slug = product_slug_from_code($code);

    if (!isset($usedSlugs[$slug])) {
        $usedSlugs[$slug] = true;

        return $slug;
    }

    $suffix = 2;

    while (isset($usedSlugs[\sprintf('%s-%d', $slug, $suffix)])) {
        ++$suffix;
    }

    $uniqueSlug = \sprintf('%s-%d', $slug, $suffix);
    $usedSlugs[$uniqueSlug] = true;

    return $uniqueSlug;
}

/**
 * @param array<int, array<string, mixed>> $products
 *
 * @return array<int, array<string, mixed>>
 */
function deduplicate_products_for_fixtures(array $products): array
{
    /** @var array<string, array<string, mixed>> $byKey */
    $byKey = [];

    foreach ($products as $product) {
        $url = trim((string) ($product['url'] ?? ''));

        if ('' === $url) {
            continue;
        }

        $key = product_dedup_key_from_url($url);

        if ('' === $key || !isset($byKey[$key])) {
            $byKey[$key] = $product;
        }
    }

    return array_values($byKey);
}

function normalize_label_for_matching(string $label): string
{
    $label = mb_strtolower(trim($label));

    if (\function_exists('transliterator_transliterate')) {
        $label = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $label) ?: $label;
    } elseif (\function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
        $label = false !== $converted ? strtolower($converted) : $label;
    }

    $label = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $label) ?? $label;
    $label = preg_replace('/\s*[-–—]\s*\d+\s*%/', '', $label) ?? $label;
    $label = preg_replace('/[^a-z0-9\s]+/', ' ', $label) ?? $label;
    $label = preg_replace('/\s+/', ' ', $label) ?? $label;

    return trim($label);
}

function taxon_code_from_name(string $name): string
{
    $normalized = normalize_label_for_matching($name);
    $code = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
    $code = trim($code, '_');

    if ('' === $code) {
        return 'taxon';
    }

    return $code;
}

/**
 * @param array<string, true> $usedCodes
 */
function unique_taxon_code(string $name, array &$usedCodes): string
{
    $baseCode = taxon_code_from_name($name);
    $code = $baseCode;
    $suffix = 2;

    while (isset($usedCodes[$code])) {
        $code = \sprintf('%s_%d', $baseCode, $suffix);
        ++$suffix;
    }

    $usedCodes[$code] = true;

    return $code;
}

function humanize_slug(string $slug): string
{
    $slug = urldecode($slug);
    $slug = str_replace(['-', '_'], ' ', $slug);
    $slug = trim(preg_replace('/\s+/', ' ', $slug) ?? '');

    if ('' === $slug) {
        return '';
    }

    return mb_convert_case($slug, \MB_CASE_TITLE, 'UTF-8');
}

function title_from_url_slug(string $url): string
{
    $path = parse_url($url, \PHP_URL_PATH);

    if (!\is_string($path) || '' === $path) {
        return '';
    }

    $segments = array_values(array_filter(explode('/', trim($path, '/'))));

    if ([] === $segments) {
        return '';
    }

    return humanize_slug((string) end($segments));
}

function slug_from_product_url(string $url): string
{
    $path = parse_url($url, \PHP_URL_PATH);

    if (!\is_string($path)) {
        return '';
    }

    if (preg_match('#/products/(?:.+/)?([^/]+)/?$#', $path, $matches)) {
        return urldecode($matches[1]);
    }

    foreach (product_url_patterns() as $pattern) {
        if (preg_match($pattern, $path, $matches)) {
            return urldecode($matches[1]);
        }
    }

    $normalizedPath = strip_import_url_locale_prefix($path);
    $segments = array_values(array_filter(
        explode('/', trim($normalizedPath, '/')),
        static fn(string $segment): bool => '' !== $segment,
    ));

    if (\count($segments) >= 4) {
        return urldecode((string) end($segments));
    }

    return '';
}

function product_dedup_key_from_url(string $url): string
{
    $slug = slug_from_product_url($url);

    if ('' !== $slug) {
        return strtolower($slug);
    }

    return normalize_product_url_for_dedup($url);
}

/**
 * @return string[]
 */
function product_url_patterns(): array
{
    return [
        '#/products/([^/]+)/?$#',
        '#/(?:produit|product)/([^/]+)/?$#',
        '#/(?:auto-all|auto-promo-\d+)/([^/]+)/?$#',
        '#/p/([^/]+)/?$#',
        '#/item/([^/]+)/?$#',
    ];
}

function is_product_url(string $url): bool
{
    return '' !== slug_from_product_url($url);
}

function is_category_url(string $url): bool
{
    $path = parse_url($url, \PHP_URL_PATH);

    if (!\is_string($path)) {
        return false;
    }

    foreach (category_path_patterns() as $pattern) {
        if (preg_match($pattern, $path)) {
            return true;
        }
    }

    return (bool) preg_match('#/(?:pages|blogs|blog|news)(?:/|$)#i', $path);
}

/**
 * @return string[]
 */
function category_path_patterns(): array
{
    return [
        '#/collections/([^/]+)#',
        '#/catalogue/([^/]+)#',
        '#/categorie/([^/]+)#',
        '#/category/([^/]+)#',
        '#/shop/([^/]+)#',
    ];
}

function product_code_from_url(string $url): string
{
    $slug = slug_from_product_url($url);

    if ('' !== $slug) {
        $code = preg_replace('/[^a-z0-9]+/', '_', strtolower($slug)) ?? '';
        $code = trim($code, '_');

        if ('' !== $code) {
            return $code;
        }
    }

    return taxon_code_from_name($url);
}
