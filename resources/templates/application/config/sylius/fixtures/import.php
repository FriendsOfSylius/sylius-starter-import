<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'imports' => [],
    'sylius_fixtures' => [
        'suites' => [
            'import' => [
                'listeners' => [
                    'logger' => null,
                ],
            ],
        ],
    ],
]);
