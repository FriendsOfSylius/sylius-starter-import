<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use SyliusStarter\Core\App;
use SyliusStarter\Core\Storefront\Brand;
use SyliusStarter\Core\Storefront\Hero;
use SyliusStarter\Core\Util\Database;
use SyliusStarter\Import\Extension\ImportExtensions;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function Castor\fs;
use function Castor\io;

function import_scaffold_marker_path(App $app): string
{
    return $app->directory() . '/config/sylius/fixtures/app.php';
}

function is_import_scaffold_deployed(App $app): bool
{
    return is_file(import_scaffold_marker_path($app));
}

function ensure_import_scaffold(?App $app = null, ?string $serviceName = null): void
{
    if (null === $app || null === $serviceName) {
        $context = ImportContext::tryCurrent();

        if (null === $context) {
            throw new \RuntimeException('Import context is not initialized.');
        }

        $app = $context->app();
        $serviceName = $context->serviceName();
    }

    if (is_import_scaffold_deployed($app)) {
        upgrade_import_storefront($app);
        upgrade_import_brand($app);
        remove_import_header_overrides($app);
        sync_import_owned_files($app);
        scaffold_import_extensions($app);

        return;
    }

    ImportContext::setCurrent(new ImportContext($app, $serviceName));
    deploy_import_scaffold();
    scaffold_import_extensions($app);
    maybe_refresh_composer_autoload();
    Database::migrate($app);
}

function ensure_sylius_application(): void
{
    $targetDir = app_dir();

    if (is_dir($targetDir)) {
        return;
    }

    throw new \RuntimeException(
        'Sylius app directory not found at "' . $targetDir . '". '
        . 'Run: composer create-project sylius/sylius-standard app && castor build && castor up',
    );
}

function deploy_import_scaffold(): void
{
    $templateDir = ImportContext::packageResourcesDir() . '/templates/application';
    $targetDir = app_dir();

    if (!is_dir($templateDir)) {
        throw new \RuntimeException('Import templates not found.');
    }

    ensure_sylius_application();

    io()->section('Deploying import application scaffold');

    fs()->mirror($templateDir, $targetDir, options: ['override' => false]);

    Hero::install(ImportContext::current()->app());
    Brand::install(ImportContext::current()->app());

    add_yaml_import('config/packages/_sylius.yaml', '../sylius/fixtures/app.php');
    add_yaml_import('config/packages/_sylius.yaml', '../sylius/fixtures/import.php');
    add_yaml_import('config/packages/_sylius.yaml', '../sylius/twig_hooks/**/**');
    add_yaml_import('config/packages/_sylius.yaml', '../sylius/shop_images.yaml');
    add_yaml_import_with_options(
        'config/packages/_sylius.yaml',
        '../import/shop_images.php',
        ignoreErrors: true,
    );

    import_log('Import application scaffold deployed from templates.');
}

/**
 * Files owned by the import package (not meant to be customized): existing copies are
 * refreshed on every run so already scaffolded apps get fixes, unlike the non-overriding
 * initial mirror. Missing files are not recreated (a deployed scaffold is never redeployed).
 */
const IMPORT_OWNED_APPLICATION_FILES = [
    'src/Command/ResetImportChannelCommand.php',
    'src/Fixture/ImportChannelAccessFixture.php',
];

function sync_import_owned_files(App $app): void
{
    $templateDir = ImportContext::packageResourcesDir() . '/templates/application';

    foreach (IMPORT_OWNED_APPLICATION_FILES as $file) {
        $source = $templateDir . '/' . $file;
        $target = $app->directory() . '/' . $file;

        if (is_file($source) && is_file($target) && file_get_contents($source) !== file_get_contents($target)) {
            if (!copy($source, $target)) {
                throw new \RuntimeException(\sprintf('Failed to update "%s".', $target));
            }

            import_log(\sprintf('Updated %s.', $file));
        }
    }
}

function scaffold_import_extensions(App $app): void
{
    foreach (ImportExtensions::all() as $extension) {
        $extension->scaffold($app);
    }
}

/**
 * Projects scaffolded before the shared hero slot rendered the hero from
 * templates/shop/homepage/banner.html.twig, a path themes also write to.
 * Move them to the core hero slot once.
 */
function upgrade_import_storefront(App $app): void
{
    if (Hero::isInstalled($app) || !is_file($app->directory() . '/config/packages/_sylius.yaml')) {
        return;
    }

    $templateDir = ImportContext::packageResourcesDir() . '/templates/application';

    Hero::install($app);

    foreach ([
        'src/Import/Storefront/ImportHeroContributor.php',
        'config/sylius/twig_hooks/shop/homepage.yaml',
    ] as $file) {
        fs()->copy($templateDir . '/' . $file, $app->directory() . '/' . $file, true);
    }

    // Only remove the legacy banner if it is the one import generated, not a theme's.
    $legacyBanner = $app->directory() . '/templates/shop/homepage/banner.html.twig';

    if (is_file($legacyBanner) && str_contains((string) file_get_contents($legacyBanner), "shop_image('imageHeader')")) {
        fs()->remove($legacyBanner);
    }

    import_log('Import storefront upgraded to the shared hero slot.');
}

/**
 * Projects scaffolded before the shared brand slot rendered the shop logo from
 * templates/shop/shared/layout/header/logo.html.twig, a path themes also write
 * to (and delete when they are removed). Move them to the core brand slot once.
 */
function upgrade_import_brand(App $app): void
{
    $contributor = 'src/Import/Storefront/ImportBrandContributor.php';

    if (is_file($app->directory() . '/' . $contributor) || !is_file($app->directory() . '/config/packages/_sylius.yaml')) {
        return;
    }

    $templateDir = ImportContext::packageResourcesDir() . '/templates/application';

    Brand::install($app);

    fs()->copy($templateDir . '/' . $contributor, $app->directory() . '/' . $contributor, true);

    // Only remove the legacy templates import generated, not a theme's.
    foreach ([
        'templates/shop/shared/layout/header/logo.html.twig',
        'templates/shop/shared/layout/footer/copy.html.twig',
    ] as $legacyTemplate) {
        $path = $app->directory() . '/' . $legacyTemplate;

        if (is_file($path) && str_contains((string) file_get_contents($path), "shop_image('imageLogo')")) {
            fs()->remove($path);
        }
    }

    // The "logo" placeholder import used to render in the checkout and error pages.
    $legacyLogo = $app->directory() . '/templates/bundles/SyliusShopBundle/shared/logo.html.twig';

    if (is_file($legacyLogo) && '<span class="fw-bold text-uppercase">logo</span>' === trim((string) file_get_contents($legacyLogo))) {
        fs()->remove($legacyLogo);
    }

    import_log('Import storefront upgraded to the shared brand slot.');
}

/**
 * Projects scaffolded before the import left the header layout to Sylius and the
 * themes got config/sylius/twig_hooks/shop/header.yaml, which disabled the navbar
 * menu and the mobile category toggle (taxon_hamburger) of every theme: YAML hook
 * configs load after the themes' PHP ones. Remove it if it is one import generated.
 */
function remove_import_header_overrides(App $app): void
{
    $path = $app->directory() . '/config/sylius/twig_hooks/shop/header.yaml';

    if (!is_file($path)) {
        return;
    }

    try {
        $config = Yaml::parseFile($path);
    } catch (ParseException) {
        return;
    }

    $hooks = [
        'sylius_shop.base.header.navbar' => ['menu' => ['enabled' => false]],
        'sylius_shop.base.header.content' => ['taxon_hamburger' => ['enabled' => false]],
    ];
    $legacyLogoHook = [
        'sylius_shop.base.header.content.logo' => ['content' => ['template' => 'shop/shared/layout/header/logo.html.twig']],
    ];

    if (!\in_array($config, [
        ['sylius_twig_hooks' => ['hooks' => $hooks]],
        ['sylius_twig_hooks' => ['hooks' => $legacyLogoHook + $hooks]],
    ], true)) {
        return;
    }

    if (!unlink($path)) {
        throw new \RuntimeException(\sprintf('Failed to remove "%s".', $path));
    }

    import_log('Removed the import header overrides, the header layout belongs to Sylius and the themes.');
}

function maybe_refresh_composer_autoload(): void
{
    try {
        ensure_app_ready();
        import_docker_compose_run('composer dump-autoload');
    } catch (\Throwable) {
        import_log('composer dump-autoload skipped (stack may not be running yet).');
    }
}
