<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'tax_category' => [
                        'options' => [
                            'custom' => [
                                'other' => [
                                    'code' => 'other',
                                    'name' => 'Other',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
