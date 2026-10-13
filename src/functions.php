<?php

declare(strict_types=1);

namespace Sylius\Starter\Import;

use Sylius\Starter\Core\App;
use Sylius\Starter\Core\Service\SyliusService;
use Sylius\Starter\Core\Task\TaskProviderRegistry;
use Sylius\Starter\Core\Util\Docker;
use Sylius\Starter\Core\Util\Fixtures;
use Sylius\Starter\Import\Tasks\ImportTasks;

TaskProviderRegistry::register(
    'import',
    static fn(SyliusService $service): iterable => (new ImportTasks($service->getName(), $service->getDirectory(), $service->getDomains()[0] ?? null))(),
);

Fixtures::beforeLoad(static function (App $app, array $suites): void {
    if (!\in_array('app', $suites, true)) {
        return;
    }

    $wasDeployed = is_import_scaffold_deployed($app);
    ensure_import_scaffold($app, $app->name());

    if (!$wasDeployed) {
        Docker::run($app, 'php bin/console cache:clear --no-warmup -n');
    }
});
