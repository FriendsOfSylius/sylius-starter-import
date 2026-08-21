<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

/**
 * Import path constants usable from PSR-4 classes without relying on constants.php autoload order.
 */
final class ImportPaths
{
    /** Host path of import payloads, relative to the Castor project root. */
    public const string VAR_HOST_DIR = '.castor/import/var';

    /** Where Sylius containers see that directory (see SyliusService::updateCompose). */
    public const string VAR_CONTAINER_DIR = '/import-var';
}
