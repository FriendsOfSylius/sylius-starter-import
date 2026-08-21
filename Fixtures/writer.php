<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use function Castor\io;

/**
 * @param array<string, mixed> $config
 */
function write_fixture_file(string $relativePath, array $config): void
{
    $exported = export_array($config);

    create_file(
        'config/sylius/fixtures/' . $relativePath,
        <<<PHP
            <?php

            declare(strict_types=1);

            namespace Symfony\\Component\\DependencyInjection\\Loader\\Configurator;

            return App::config({$exported});

            PHP,
        override: true,
    );
}

function import_fixture_slug_dir(string $projectSlug): string
{
    return 'import/' . $projectSlug;
}

/**
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 * @param array<int, array<string, mixed>>                                           $selectedProducts
 * @param array<string, mixed>                                                       $taxonFixture
 * @param array<string, mixed>                                                       $taxonImageFixture
 * @param array<string, mixed>                                                       $productFixture
 * @param array<string, mixed>                                                       $productPriceFixture
 */
function write_import_fixture_files(
    string $projectSlug,
    array $taxonIndex,
    array $selectedProducts,
    array $taxonFixture,
    array $taxonImageFixture,
    array $productFixture,
    array $productPriceFixture,
    string $mode,
): void {
    $relativeDir = import_fixture_slug_dir($projectSlug);
    $fixturesDir = app_dir() . '/config/sylius/fixtures/' . $relativeDir;

    if (!is_dir($fixturesDir)) {
        mkdir($fixturesDir, 0o775, true);
        import_log(\sprintf('Created fixtures directory: %s', $fixturesDir));
    }

    import_log(\sprintf('Writing fixture files for %s...', $projectSlug));
    write_fixture_file($relativeDir . '/taxons.php', $taxonFixture);
    write_fixture_file($relativeDir . '/taxon_images.php', $taxonImageFixture);
    write_fixture_file($relativeDir . '/products.php', $productFixture);
    write_fixture_file($relativeDir . '/product_prices.php', $productPriceFixture);
    write_fixture_file($relativeDir . '/channel.php', build_channel_fixture($projectSlug));
    write_fixture_file($relativeDir . '/channel_access.php', build_channel_access_fixture($projectSlug));
    write_fixture_file($relativeDir . '/admin_user.php', build_admin_user_fixture($projectSlug));
    write_fixture_file($relativeDir . '/shop_user.php', build_shop_user_fixture($projectSlug));
    write_import_suite_loader($projectSlug);

    io()->info(\sprintf(
        'Generated %d taxons and %d products for %s (channel %s, hostname %s).',
        \count($taxonIndex),
        \count($selectedProducts),
        $projectSlug,
        channel_code_from_slug($projectSlug),
        shop_hostname($projectSlug),
    ));

    write_last_generated_import([
        'slug' => $projectSlug,
        'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        'mode' => $mode,
    ]);
}

function write_import_suite_loader(string $projectSlug): void
{
    $relativeDir = import_fixture_slug_dir($projectSlug);
    $resources = var_export([
        ['resource' => $relativeDir . '/taxons.php'],
        ['resource' => $relativeDir . '/taxon_images.php'],
        ['resource' => $relativeDir . '/channel.php'],
        ['resource' => $relativeDir . '/channel_access.php'],
        ['resource' => $relativeDir . '/admin_user.php'],
        ['resource' => $relativeDir . '/shop_user.php'],
        ['resource' => $relativeDir . '/products.php'],
        ['resource' => $relativeDir . '/product_prices.php'],
    ], true);

    create_file(
        'config/sylius/fixtures/import.php',
        <<<PHP
            <?php

            declare(strict_types=1);

            namespace Symfony\\Component\\DependencyInjection\\Loader\\Configurator;

            return App::config([
                'imports' => {$resources},
                'sylius_fixtures' => [
                    'suites' => [
                        'import' => [
                            'listeners' => [
                                'logger' => null,
                            ],
                        ],
                    ],
                ],
            ]);

            PHP,
        override: true,
    );
}

function write_empty_import_suite_loader(): void
{
    $path = app_dir() . '/config/sylius/fixtures/import.php';
    $directory = \dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
        throw new \RuntimeException(\sprintf('Failed to create directory "%s".', $directory));
    }

    $body = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Symfony\Component\DependencyInjection\Loader\Configurator;

        return App::config([
            'imports' => [],
            'sylius_fixtures' => [
                'suites' => [
                    'import' => [
                        'listeners' => [
                            'logger' => null,
                        ],
                    ],
                ],
            ],
        ]);

        PHP;

    if (false === file_put_contents($path, $body)) {
        throw new \RuntimeException('Failed to write empty import suite loader.');
    }
}

function project_has_generated_fixtures(string $projectSlug): bool
{
    return is_file(app_dir() . '/config/sylius/fixtures/' . import_fixture_slug_dir($projectSlug) . '/products.php');
}
