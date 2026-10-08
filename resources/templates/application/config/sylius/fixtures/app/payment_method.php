<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'payment_method' => [
                        'options' => [
                            'custom' => [
                                'cash_on_delivery' => [
                                    'code' => 'cash_on_delivery',
                                    'name' => 'Cash on delivery',
                                    'channels' => ['WEB_STORE'],
                                ],
                                'bank_transfer' => [
                                    'code' => 'bank_transfer',
                                    'name' => 'Bank transfer',
                                    'channels' => ['WEB_STORE'],
                                    'enabled' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
