<?php

declare(strict_types=1);

namespace Sylius\Starter\Import;

use function Castor\io;

function import_log(string $message): void
{
    try {
        io()->comment($message);
    } catch (\LogicException) {
        // Castor is not bootstrapped (e.g. unit tests).
    }
}

/**
 * @deprecated use ensure_app_ready()
 */
function ensure_docker_ready(): void
{
    ensure_app_ready();
}

function ensure_app_ready(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    if (!is_dir(app_dir())) {
        throw new \RuntimeException(
            'Sylius app directory not found. Run: composer create-project sylius/sylius-standard app && castor build && castor up',
        );
    }

    $loaded = true;
}

function ensure_castor_yaml_autoload(): void
{
    $autoload = ImportContext::current()->castorDir() . '/vendor/autoload.php';

    if (is_file($autoload)) {
        require_once $autoload;
    }
}

function import_ai_platform_classes_available(): bool
{
    return class_exists(\Symfony\AI\Platform\Bridge\Ollama\Factory::class)
        && class_exists(\Symfony\AI\Platform\Bridge\OpenRouter\Factory::class);
}

function missing_import_ai_packages_message(): string
{
    return 'Symfony AI packages are missing from .castor/vendor. Run: castor composer update';
}
