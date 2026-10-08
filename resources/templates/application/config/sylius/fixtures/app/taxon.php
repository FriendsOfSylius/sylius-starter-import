<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'menu_taxon' => [
                        'name' => 'taxon',
                        'options' => [
                            'custom' => [
                                'category' => [
                                    'code' => 'category',
                                    'name' => 'Category',
                                    'translations' => [
                                        'en_US' => ['name' => 'Category'],
                                        'fr_FR' => ['name' => 'Catégorie'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
