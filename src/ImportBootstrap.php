<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run;

final class ImportBootstrap
{
    public static function ensureReady(): void
    {
        self::ensureCastorFiles();
        ensure_import_vendor();
        ensure_import_env();
        ensure_import_autoload();
    }

    public static function ensureCastorFiles(): void
    {
        $castorDir = ImportContext::current()->castorDir();

        if (!is_dir($castorDir)) {
            fs()->mkdir($castorDir);
        }

        $composerJson = $castorDir . '/composer.json';

        if (!is_file($composerJson)) {
            $source = \dirname(__DIR__) . '/resources/castor/composer.json';

            if (!is_file($source)) {
                throw new \RuntimeException(\sprintf('Missing Castor import composer template at "%s".', $source));
            }

            fs()->copy($source, $composerJson);
        }

        $envExample = $castorDir . '/.env.example';

        if (!is_file($envExample)) {
            $source = \dirname(__DIR__) . '/resources/castor/.env.example';

            if (!is_file($source)) {
                throw new \RuntimeException(\sprintf('Missing Castor import env template at "%s".', $source));
            }

            fs()->copy($source, $envExample);
        }
    }

    public static function ensureAiReady(): void
    {
        self::ensureReady();
        load_castor_env();
    }
}

function ensure_import_vendor(): void
{
    $castorDir = ImportContext::current()->castorDir();
    $autoload = $castorDir . '/vendor/autoload.php';

    if (!is_file($autoload)) {
        ImportBootstrap::ensureCastorFiles();

        if (!is_file($castorDir . '/composer.json')) {
            throw new \RuntimeException(\sprintf('Missing "%s/composer.json".', $castorDir));
        }

        io()->section('Installing Castor import dependencies');
        import_log('Running composer install in .castor/...');

        $composerContext = context()->withWorkingDirectory($castorDir)->withTimeout(600);

        run(
            ['composer', 'install', '--no-interaction', '--prefer-dist'],
            context: $composerContext->withAllowFailure(),
        );

        if (!is_file($autoload)) {
            import_log('composer install incomplete — running composer update...');
            run(['composer', 'update', '--no-interaction', '--prefer-dist'], context: $composerContext);
        }

        if (!is_file($autoload)) {
            throw new \RuntimeException(\sprintf(
                'Failed to install Castor dependencies in "%s".',
                $castorDir,
            ));
        }

        import_log('Castor import dependencies installed.');
    }

    require_once $autoload;

    if (!import_ai_platform_classes_available()) {
        throw new \RuntimeException(missing_import_ai_packages_message());
    }
}

function ensure_import_env(): void
{
    $projectRoot = ImportContext::current()->projectRoot();
    $envFile = $projectRoot . '/.env';
    $distFile = $projectRoot . '/.env.dist';

    if (is_file($envFile)) {
        return;
    }

    if (is_file($distFile)) {
        io()->section('Creating project env file');
        fs()->copy($distFile, $envFile);
        import_log('Created .env from .env.dist — add secrets and AI settings to .env.local.');

        return;
    }

    $legacyEnv = ImportContext::current()->castorDir() . '/.env';

    if (is_file($legacyEnv)) {
        import_log('Using legacy .castor/.env — prefer project .env and .env.local at the repository root.');

        return;
    }

    throw new \RuntimeException(\sprintf(
        'Missing project env file. Create "%s" (copy from .env.dist) or "%s".',
        $envFile,
        $legacyEnv,
    ));
}

function ensure_import_autoload(): void
{
    require_once ImportContext::current()->castorDir() . '/vendor/autoload.php';
}

function ensure_import_ai_ready(): void
{
    ImportBootstrap::ensureAiReady();
}

function load_castor_env(): void
{
    $projectRoot = ImportContext::current()->projectRoot();
    $dotenv = new \Symfony\Component\Dotenv\Dotenv();
    $envFile = $projectRoot . '/.env';
    $distFile = $projectRoot . '/.env.dist';
    $localFile = $projectRoot . '/.env.local';

    if (is_file($envFile)) {
        $dotenv->load($envFile);
    } elseif (is_file($distFile)) {
        $dotenv->load($distFile);
    }

    if (is_file($localFile)) {
        $dotenv->overload($localFile);

        return;
    }

    if (is_file($envFile) || is_file($distFile)) {
        return;
    }

    $legacyEnv = ImportContext::current()->castorDir() . '/.env';

    if (!is_file($legacyEnv)) {
        throw new \RuntimeException(\sprintf('Missing "%s" (or legacy "%s").', $envFile, $legacyEnv));
    }

    import_log('Loading AI config from legacy .castor/.env — move settings to .env.local at the project root.');
    $dotenv->overload($legacyEnv);
}
