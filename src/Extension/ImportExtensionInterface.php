<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Extension;

use SyliusStarter\Core\App;

/**
 * Lets a project hook into the shop import (castor sylius:import:*) without the starter shipping its data:
 * e.g. a sales kit creating one admin per imported channel.
 */
interface ImportExtensionInterface
{
    /**
     * Called each time the import scaffold is ensured in the Sylius app (first deploy and every later run).
     */
    public function scaffold(App $app): void;

    /**
     * Fixture files added to the project's import suite, loaded after the imported catalog.
     *
     * @param array{channel: string, subdomain: ?string, shared: bool} $target
     *
     * @return array<string, array<string, mixed>> file name (without extension) => fixture configuration
     */
    public function buildFixtures(string $projectSlug, array $target): array;

    /**
     * Extra fields merged into the project's entry of sylius:import:list.
     *
     * @param array<string, mixed>|null $config project.yaml content
     *
     * @return array<string, mixed>
     */
    public function describeShop(string $projectSlug, ?array $config): array;
}
