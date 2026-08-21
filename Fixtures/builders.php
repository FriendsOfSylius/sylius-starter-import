<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

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
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 *
 * @return array<string, mixed>
 */
function build_taxon_fixture(array $taxonIndex, string $projectSlug = ''): array
{
    $children = [];
    $rootCode = '' !== $projectSlug ? shop_menu_taxon_code($projectSlug) : 'category';
    $rootSlug = str_replace('_', '-', $rootCode);

    foreach ($taxonIndex as $taxon) {
        $children[] = [
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
    }

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
 * @return array<string, mixed>
 */
function build_channel_fixture(string $projectSlug): array
{
    $code = channel_code_from_slug($projectSlug);
    $name = import_project_display_name($projectSlug);
    $hostname = shop_hostname($projectSlug);
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
                                        'locales' => ['%locale%'],
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
function build_channel_access_fixture(string $projectSlug): array
{
    return [
        'sylius_fixtures' => [
            'suites' => [
                'import' => [
                    'fixtures' => [
                        'import_channel_access' => [
                            'name' => 'import_channel_access',
                            'options' => [
                                'custom' => [
                                    ['channel' => channel_code_from_slug($projectSlug)],
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
function build_admin_user_fixture(string $projectSlug): array
{
    return [
        'sylius_fixtures' => [
            'suites' => [
                'import' => [
                    'fixtures' => [
                        'import_admin_user' => [
                            'name' => 'import_admin_user',
                            'options' => [
                                'custom' => [
                                    [
                                        'channel' => channel_code_from_slug($projectSlug),
                                        'username' => $projectSlug,
                                        'password' => import_admin_user_password($projectSlug),
                                        'first_name' => import_project_display_name($projectSlug),
                                        'email' => import_admin_user_email($projectSlug),
                                        'import_code_prefix' => import_code_prefix($projectSlug),
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
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 *
 * @return array<string, mixed>
 */
function build_taxon_image_fixture(array $taxonIndex, string $host): array
{
    $custom = [];
    $withImage = 0;

    foreach ($taxonIndex as $taxon) {
        if (null === $taxon['image'] || '' === $taxon['image']) {
            continue;
        }

        ++$withImage;
        $imagePath = download_import_image($taxon['image'], $host, 'taxon_' . $taxon['code']);

        if (null === $imagePath) {
            continue;
        }

        $custom[] = [
            'taxon' => $taxon['code'],
            'path' => $imagePath,
            'type' => 'main',
        ];
    }

    import_log(\sprintf(
        'Taxon images: %d/%d downloaded and converted to WebP.',
        \count($custom),
        $withImage,
    ));

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
 * @param callable(array<string, mixed>): string $resolveDescription
 * @param callable(array<string, mixed>, string, string): ?string $resolveImagePath
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function build_product_fixture_entries(
    array $products,
    array $taxonAssignments,
    array $priceAssignments,
    array $promoProductIds,
    callable $resolveDescription,
    callable $resolveImagePath,
    string $imageFailureMessage,
    string $successLogSuffix = '',
    string $projectSlug = '',
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
        $channelCode = '' !== $projectSlug ? channel_code_from_slug($projectSlug) : 'WEB_STORE';
        $description = $resolveDescription($product);

        $entry = [
            'name' => $title,
            'code' => $code,
            'slug' => $slug,
            'short_description' => $description,
            'description' => $description,
            'main_taxon' => $taxonCode,
            'taxons' => array_values(array_unique([$taxonCode, $rootTaxon])),
            'channels' => [$channelCode],
            'tax_category' => 'other',
        ];

        $imagePath = $resolveImagePath($product, $code, $slug);

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
        'Product fixtures: %d product(s), %d with image(s), %d on promo%s.',
        \count($custom),
        $imageCount,
        $promoCount,
        $successLogSuffix,
    ));

    if ($imageFailures > 0) {
        io()->warning(\sprintf('%d product image(s) could not be %s.', $imageFailures, $imageFailureMessage));
    }

    return wrap_product_fixture_config($custom, $priceCustom);
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
 * @param array<int, array<string, mixed>> $products
 * @param array<int, string>               $taxonAssignments
 * @param array<int, int>                  $priceAssignments
 * @param int[]                              $promoProductIds
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function build_product_fixtures(
    array $products,
    string $host,
    array $taxonAssignments,
    array $priceAssignments,
    array $promoProductIds = [],
): array {
    return build_product_fixture_entries(
        $products,
        $taxonAssignments,
        $priceAssignments,
        $promoProductIds,
        static fn(array $product): string => trim((string) ($product['title'] ?? '')),
        static function (array $product, string $code) use ($host): ?string {
            $imageUrl = trim((string) ($product['image'] ?? ''));

            if ('' === $imageUrl) {
                return null;
            }

            return download_import_image($imageUrl, $host, $code, quiet: true);
        },
        'downloaded or converted',
        ' (original price +33%)',
        $host,
    );
}

/**
 * @param array<int, array<string, mixed>> $products
 * @param array<int, string>               $taxonAssignments
 * @param array<int, int>                  $priceAssignments
 * @param int[]                              $promoProductIds
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function build_ai_product_fixtures(
    array $products,
    string $projectSlug,
    array $taxonAssignments,
    array $priceAssignments,
    array $promoProductIds = [],
): array {
    return build_product_fixture_entries(
        $products,
        $taxonAssignments,
        $priceAssignments,
        $promoProductIds,
        static fn(array $product): string => trim((string) ($product['description'] ?? $product['title'] ?? '')),
        static function (array $product, string $code) use ($projectSlug): ?string {
            $imagePrompt = trim((string) ($product['image_prompt'] ?? ''));

            if ('' === $imagePrompt) {
                return null;
            }

            return generate_import_image_from_prompt($imagePrompt, $projectSlug, $code, quiet: true);
        },
        'generated',
        '',
        $projectSlug,
    );
}
