<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'shipping_method' => [
                        'options' => [
                            'custom' => [
                                'standard' => [
                                    'code' => 'standard',
                                    'name' => 'Standard shipping',
                                    'enabled' => true,
                                    'channels' => ['WEB_STORE'],
                                    'zone' => 'FR',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
