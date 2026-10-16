<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use SyliusStarter\Core\Util\Fixtures as CoreFixtures;

use function Castor\io;

/**
 * @param array<int, array<string, mixed>> $collections
 *
 * @return array<int, array{code: string, name: string, slug: string, image: ?string}>
 */
function build_taxon_index(array $collections, string $projectSlug = ''): array
{
    /** @var array<string, array{code: string, name: string, slug: string, image: ?string}> $indexed */
    $indexed = [];
    /** @var array<string, true> $usedCodes */
    $usedCodes = [];

    foreach ($collections as $collection) {
        $name = trim((string) ($collection['name'] ?? ''));

        if ('' === $name) {
            continue;
        }

        $image = null;

        if (isset($collection['image']) && \is_string($collection['image'])) {
            $candidate = trim($collection['image']);

            if ('' !== $candidate && 'null' !== strtolower($candidate)) {
                $image = $candidate;
            }
        }

        $normalized = normalize_label_for_matching($name);

        if (isset($indexed[$normalized])) {
            if (null !== $image) {
                if (null === $indexed[$normalized]['image']) {
                    $indexed[$normalized]['name'] = $name;
                }

                $indexed[$normalized]['image'] = $image;
            }

            continue;
        }

        $code = taxon_code_from_name($name);

        if ('' !== $projectSlug) {
            $code = prefixed_import_code($projectSlug, $code);
        }

        $baseCode = $code;
        $suffix = 2;

        while (isset($usedCodes[$code])) {
            $code = \sprintf('%s_%d', $baseCode, $suffix);
            ++$suffix;
        }

        $usedCodes[$code] = true;

        $indexed[$normalized] = [
            'code' => $code,
            'name' => $name,
            'slug' => $code,
            'image' => $image,
        ];
    }

    return array_values($indexed);
}

/**
 * Keeps the taxons holding the most selected products, in their catalog order, so the shop menu stays readable.
 * Products of a dropped taxon lose their assignment and fall back to the root taxon.
 *
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 * @param array<int, string>                                                         $taxonAssignments
 *
 * @return array{0: array<int, array{code: string, name: string, slug: string, image: ?string}>, 1: array<int, string>}
 */
function limit_menu_taxons(array $taxonIndex, array $taxonAssignments, int $limit = IMPORT_MENU_TAXON_LIMIT): array
{
    $productCounts = array_count_values($taxonAssignments);
    $positions = [];

    foreach ($taxonIndex as $position => $taxon) {
        if (($productCounts[$taxon['code']] ?? 0) > 0) {
            $positions[$taxon['code']] = $position;
        }
    }

    uksort(
        $positions,
        static fn(int|string $a, int|string $b): int => [$productCounts[$b], $positions[$a]] <=> [$productCounts[$a], $positions[$b]],
    );
    $kept = array_flip(\array_slice(array_keys($positions), 0, $limit));
    $dropped = array_diff_key($positions, $kept);

    return [
        array_values(array_filter($taxonIndex, static fn(array $taxon): bool => isset($kept[$taxon['code']]))),
        // Codes outside the index (e.g. the "category" fallback) are left alone.
        array_filter($taxonAssignments, static fn(string $code): bool => !isset($dropped[$code])),
    ];
}

/**
 * @param array<int, array{code: string, name: string, slug: string, image: ?string, parent?: string}> $taxonIndex
 *
 * @return array<string, mixed>
 */
function build_taxon_fixture(array $taxonIndex, string $projectSlug = ''): array
{
    $rootCode = '' !== $projectSlug ? shop_menu_taxon_code($projectSlug) : 'category';
    $rootSlug = str_replace('_', '-', $rootCode);
    $children = build_taxon_fixture_children($taxonIndex, null);

    return [
        'sylius_fixtures' => [
            'suites' => [
                'import' => [
                    'fixtures' => [
                        'import_taxons' => [
                            'name' => 'taxon',
                            'options' => [
                                'custom' => [
                                    $rootCode => [
                                        'code' => $rootCode,
                                        'name' => 'Category',
                                        'slug' => $rootSlug,
                                        'children' => $children,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @param array<int, array{code: string, name: string, slug: string, image: ?string, parent?: string}> $taxonIndex
 *
 * @return array<int, array<string, mixed>>
 */
function build_taxon_fixture_children(array $taxonIndex, ?string $parentCode): array
{
    $children = [];

    foreach ($taxonIndex as $taxon) {
        if (($taxon['parent'] ?? null) !== $parentCode) {
            continue;
        }

        $child = [
            'code' => $taxon['code'],
            'name' => $taxon['name'],
            'slug' => $taxon['slug'],
            'translations' => [
                'en_US' => [
                    'name' => $taxon['name'],
                    'slug' => $taxon['slug'],
                ],
                'fr_FR' => [
                    'name' => $taxon['name'],
                    'slug' => $taxon['slug'],
                ],
            ],
        ];

        $grandChildren = build_taxon_fixture_children($taxonIndex, $taxon['code']);

        if ([] !== $grandChildren) {
            $child['children'] = $grandChildren;
        }

        $children[] = $child;
    }

    return $children;
}

/**
 * @return array<string, mixed>
 */
function build_channel_fixture(string $projectSlug, ?string $domain, ?string $subdomain = null): array
{
    $code = channel_code_from_slug($projectSlug);
    $name = import_project_display_name($projectSlug);
    $hostname = shop_hostname($domain, $subdomain);
    $menuTaxon = shop_menu_taxon_code($projectSlug);

    return [
        'sylius_fixtures' => [
            'suites' => [
                'import' => [
                    'fixtures' => [
                        'import_channel' => [
                            'name' => 'channel',
                            'options' => [
                                'custom' => [
                                    $code => [
                                        'name' => $name,
                                        'code' => $code,
                                        'locales' => CoreFixtures::defaultChannelLocaleCodes(),
                                        'currencies' => ['EUR'],
                                        'hostname' => $hostname,
                                        'enabled' => true,
                                        'menu_taxon' => $menuTaxon,
                                        'shop_billing_data' => [
                                            'company' => $name,
                                            'country_code' => 'FR',
                                            'street' => '1 rue Example',
                                            'city' => 'Paris',
                                            'postcode' => '75001',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function build_channel_access_fixture(string $projectSlug, ?string $channelCode = null, bool $setMenuTaxon = false): array
{
    $item = ['channel' => $channelCode ?? channel_code_from_slug($projectSlug)];

    if ($setMenuTaxon) {
        // Existing channel: point its shop menu at the imported catalog.
        $item['menu_taxon'] = shop_menu_taxon_code($projectSlug);
    }

    return [
        'sylius_fixtures' => [
            'suites' => [
                'import' => [
                    'fixtures' => [
                        'import_channel_access' => [
                            'name' => 'import_channel_access',
                            'options' => [
                                'custom' => [$item],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function build_shop_user_fixture(string $projectSlug): array
{
    return [
        'sylius_fixtures' => [
            'suites' => [
                'import' => [
                    'fixtures' => [
                        'import_shop_user' => [
                            'name' => 'import_shop_user',
                            'options' => [
                                'custom' => [
                                    [
                                        'email' => import_shop_user_email($projectSlug),
                                        'password' => import_shop_user_password($projectSlug),
                                        'first_name' => import_project_display_name($projectSlug),
                                        'last_name' => 'Customer',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Downloads the collection image when there is one, otherwise generates it in the visual style of the product images
 * when the products are described by image prompts (AI-built catalog).
 *
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 *
 * @return array<string, mixed>
 */
function build_taxon_image_fixture(array $taxonIndex, string $projectSlug, ?string $styleReferencePrompt = null): array
{
    $custom = [];

    if (stream_isatty(\STDOUT)) {
        io()->progressStart(\count($taxonIndex));
    }

    foreach ($taxonIndex as $taxon) {
        $code = 'taxon_' . $taxon['code'];
        $imagePath = null;

        if (null !== $taxon['image'] && '' !== $taxon['image']) {
            $imagePath = download_import_image($taxon['image'], $projectSlug, $code, quiet: true);
        } elseif (null !== $styleReferencePrompt) {
            $imagePath = generate_import_image_from_prompt(
                \sprintf('Category thumbnail for "%s", same visual style as: %s', $taxon['name'], $styleReferencePrompt),
                $projectSlug,
                $code,
                quiet: true,
            );
        }

        if (null !== $imagePath) {
            $custom[] = [
                'taxon' => $taxon['code'],
                'path' => $imagePath,
                'type' => 'main',
            ];
        }

        if (stream_isatty(\STDOUT)) {
            io()->progressAdvance();
        }
    }

    if (stream_isatty(\STDOUT)) {
        io()->progressFinish();
    }

    import_log(\sprintf('Taxon images: %d/%d taxon(s) with an image.', \count($custom), \count($taxonIndex)));

    return [
        'sylius_fixtures' => [
            'suites' => [
                'import' => [
                    'fixtures' => [
                        'import_taxon_images' => [
                            'name' => 'import_taxon_image',
                            'options' => [
                                'custom' => $custom,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @param array<int, array<string, mixed>> $products
 * @param array<int, string>               $taxonAssignments
 * @param array<int, int>                  $priceAssignments
 * @param int[]                              $promoProductIds
 * @param array<string, string>            $taxonParents     taxon code => parent taxon code
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function build_product_fixtures(
    array $products,
    string $projectSlug,
    array $taxonAssignments,
    array $priceAssignments,
    array $promoProductIds = [],
    array $taxonParents = [],
): array {
    $custom = [];
    $priceCustom = [];
    /** @var array<string, true> $usedCodes */
    $usedCodes = [];
    /** @var array<string, true> $usedSlugs */
    $usedSlugs = [];
    $promoSet = array_fill_keys($promoProductIds, true);
    $promoCount = 0;
    $imageCount = 0;
    $imageFailures = 0;

    if (stream_isatty(\STDOUT)) {
        io()->progressStart(\count($products));
    }

    foreach ($products as $product) {
        $title = trim((string) ($product['title'] ?? ''));
        $productId = (int) ($product['id'] ?? -1);

        if ('' === $title) {
            if (stream_isatty(\STDOUT)) {
                io()->progressAdvance();
            }

            continue;
        }

        $code = resolve_product_code($product, $usedCodes);
        if ('' !== $projectSlug) {
            $code = prefixed_import_code($projectSlug, $code);
        }
        $slug = resolve_product_slug($code, $usedSlugs);
        $rootTaxon = '' !== $projectSlug ? shop_menu_taxon_code($projectSlug) : 'category';
        $taxonCode = $taxonAssignments[$productId] ?? $rootTaxon;
        $channelCode = '' !== $projectSlug ? import_channel_code($projectSlug) : IMPORT_DEFAULT_CHANNEL_CODE;
        $description = resolve_product_description($product);

        $entry = [
            'name' => $title,
            'code' => $code,
            'slug' => $slug,
            'short_description' => $description,
            'description' => $description,
            'main_taxon' => $taxonCode,
            // Parent taxons list the product too, so a group page (e.g. "Men") shows its children's products.
            'taxons' => array_values(array_unique([$taxonCode, ...taxon_ancestor_codes($taxonCode, $taxonParents), $rootTaxon])),
            'channels' => [$channelCode],
            'tax_category' => 'other',
        ];

        $imagePath = resolve_product_image($product, $projectSlug, $code);

        if (null !== $imagePath) {
            $entry['images'] = [
                ['path' => $imagePath, 'type' => 'main'],
            ];
            ++$imageCount;
        } else {
            $hasImageInput = '' !== trim((string) ($product['image'] ?? ''))
                || '' !== trim((string) ($product['image_prompt'] ?? ''));

            if ($hasImageInput) {
                ++$imageFailures;
            }
        }

        $custom[] = $entry;

        $priceCents = $priceAssignments[$productId] ?? 1999;
        $priceEntry = [
            'code' => $code,
            'price' => $priceCents,
            'channel' => $channelCode,
        ];

        if (isset($promoSet[$productId])) {
            $priceEntry['original_price'] = compute_promo_original_price_cents($priceCents);
            ++$promoCount;
        }

        $priceCustom[] = $priceEntry;
        if (stream_isatty(\STDOUT)) {
            io()->progressAdvance();
        }
    }

    if (stream_isatty(\STDOUT)) {
        io()->progressFinish();
    }
    import_log(\sprintf(
        'Product fixtures: %d product(s), %d with image(s), %d on promo (original price +33%%).',
        \count($custom),
        $imageCount,
        $promoCount,
    ));

    if ($imageFailures > 0) {
        io()->warning(\sprintf('%d product image(s) could not be downloaded or generated.', $imageFailures));
    }

    return wrap_product_fixture_config($custom, $priceCustom);
}

/**
 * @param array<string, string> $taxonParents
 *
 * @return string[]
 */
function taxon_ancestor_codes(string $taxonCode, array $taxonParents): array
{
    $ancestors = [];

    while (isset($taxonParents[$taxonCode]) && !\in_array($taxonParents[$taxonCode], $ancestors, true)) {
        $taxonCode = $taxonParents[$taxonCode];
        $ancestors[] = $taxonCode;
    }

    return $ancestors;
}

/**
 * @param array<int, array<string, mixed>> $custom
 * @param array<int, array<string, mixed>> $priceCustom
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function wrap_product_fixture_config(array $custom, array $priceCustom): array
{
    return [
        [
            'sylius_fixtures' => [
                'suites' => [
                    'import' => [
                        'fixtures' => [
                            'import_products' => [
                                'name' => 'import_product',
                                'options' => [
                                    'custom' => $custom,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        [
            'sylius_fixtures' => [
                'suites' => [
                    'import' => [
                        'fixtures' => [
                            'import_product_prices' => [
                                'name' => 'import_product_price',
                                'options' => [
                                    'custom' => $priceCustom,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * A product imported from an existing site carries an image URL, an AI-built one carries an image prompt.
 *
 * @param array<string, mixed> $product
 */
function resolve_product_image(array $product, string $projectSlug, string $code): ?string
{
    $imageUrl = trim((string) ($product['image'] ?? ''));

    if ('' !== $imageUrl) {
        return download_import_image($imageUrl, $projectSlug, $code, quiet: true);
    }

    $imagePrompt = trim((string) ($product['image_prompt'] ?? ''));

    if ('' !== $imagePrompt) {
        return generate_import_image_from_prompt($imagePrompt, $projectSlug, $code, quiet: true);
    }

    return null;
}

/**
 * @param array<string, mixed> $product
 */
function resolve_product_description(array $product): string
{
    $description = trim((string) ($product['description'] ?? ''));

    return '' !== $description ? $description : trim((string) ($product['title'] ?? ''));
}
