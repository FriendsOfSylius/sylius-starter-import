<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use SyliusStarter\Core\App;
use SyliusStarter\Core\PhpFile;
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
        setup_import_admin_user_entity($app);
        sync_import_owned_files($app);

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

    setup_import_admin_user_entity(ImportContext::current()->app());

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

/**
 * sylius-standard always ships src/Entity/User/AdminUser.php (and other packages,
 * e.g. Mollie, patch it), so the non-overriding mirror never writes ours. Wire the
 * import channel scoping onto the existing entity instead. Idempotent.
 */
function setup_import_admin_user_entity(App $app): void
{
    $path = $app->directory() . '/src/Entity/User/AdminUser.php';

    if (!is_file($path)) {
        return;
    }

    (new PhpFile($path))
        ->addInterface('App\\Entity\\User\\ImportChannelAdminAwareInterface')
        ->addTrait('App\\Entity\\User\\ImportChannelAdminAwareTrait')
        ->save();
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
