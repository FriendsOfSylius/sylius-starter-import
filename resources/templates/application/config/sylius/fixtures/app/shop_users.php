<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'shop_user' => [
                        'options' => [
                            'custom' => [
                                'default' => [
                                    'email' => 'shop@example.com',
                                    'password' => 'sylius',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
