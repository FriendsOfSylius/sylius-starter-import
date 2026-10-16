<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use SyliusStarter\Core\App;
use SyliusStarter\Core\Service\SyliusService;
use SyliusStarter\Core\Task\TaskProviderRegistry;
use SyliusStarter\Core\Util\Docker;
use SyliusStarter\Core\Util\Fixtures;
use SyliusStarter\Import\Tasks\ImportTasks;

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
