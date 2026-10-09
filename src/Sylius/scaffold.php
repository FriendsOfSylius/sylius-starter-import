<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use SyliusStarter\Core\App;
use SyliusStarter\Core\Storefront\Hero;
use SyliusStarter\Core\Util\Database;

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

        return;
    }

    ImportContext::setCurrent(new ImportContext($app, $serviceName));
    deploy_import_scaffold();
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

    add_yaml_import('config/packages/_sylius.yaml', '../sylius/fixtures/app.php');
    add_yaml_import('config/packages/_sylius.yaml', '../sylius/fixtures/import.php');
    add_yaml_import('config/packages/_sylius.yaml', '../sylius/twig_hooks/**/**');
    add_yaml_import('config/packages/_sylius.yaml', '../sylius/shop_images.yaml');
    add_yaml_import('config/packages/_sylius.yaml', '../sylius/import_channel_admin.yaml');
    add_yaml_import_with_options(
        'config/packages/_sylius.yaml',
        '../import/shop_images.php',
        ignoreErrors: true,
    );

    import_log('Import application scaffold deployed from templates.');
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

function maybe_refresh_composer_autoload(): void
{
    try {
        ensure_docker_ready();
        import_docker_compose_run('composer dump-autoload');
    } catch (\Throwable) {
        import_log('composer dump-autoload skipped (stack may not be running yet).');
    }
}
