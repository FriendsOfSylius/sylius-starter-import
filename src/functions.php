<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

use SyliusStarter\Core\Service\SyliusService;
use SyliusStarter\Core\Task\TaskProviderRegistry;
use SyliusStarter\Import\Tasks\ImportTasks;

TaskProviderRegistry::register(
    'import',
    static fn(SyliusService $service): iterable => (new ImportTasks($service->getName(), $service->getDirectory(), $service->getDomains()[0] ?? null))(),
);
