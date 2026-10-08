<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'geographical' => [
                        'options' => [
                            'countries' => ['FR'],
                            'zones' => [
                                'FR' => [
                                    'name' => 'France',
                                    'countries' => ['FR'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
