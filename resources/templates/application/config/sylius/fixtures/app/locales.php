<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'locale' => [
                        'options' => [
                            'locales' => ['en_US', 'fr_FR'],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
