<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'tax_rate' => [
                        'options' => [
                            'custom' => [
                                'fr_vat' => [
                                    'code' => 'fr_vat_20',
                                    'name' => 'TVA 20%',
                                    'zone' => 'FR',
                                    'category' => 'other',
                                    'amount' => 0.2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
