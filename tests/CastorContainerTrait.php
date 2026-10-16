<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests;

use Castor\Container;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Installs a minimal Castor container (io + filesystem) so that tests do not
 * depend on the container left behind by a previously executed test.
 */
trait CastorContainerTrait
{
    private function setUpCastorContainer(): void
    {
        $container = (new \ReflectionClass(Container::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(Container::class, 'symfonyStyle'))->setValue($container, new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
        (new \ReflectionProperty(Container::class, 'fs'))->setValue($container, new Filesystem());

        Container::set($container);
    }
}
