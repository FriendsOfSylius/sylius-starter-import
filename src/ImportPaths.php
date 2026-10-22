<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

/**
 * Import path constants usable from PSR-4 classes without relying on constants.php autoload order.
 */
final class ImportPaths
{
    /** Host path of import payloads, relative to the Castor project root. */
    public const string VAR_HOST_DIR = '.castor/import/var';

    /** Copy of the project media read by the generated fixtures, relative to the Sylius application directory. */
    public const string APP_MEDIA_DIR = 'var/import';
}
