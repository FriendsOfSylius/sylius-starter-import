<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'channel' => [
                        'options' => [
                            'custom' => [
                                'web_store' => [
                                    'name' => 'Web store',
                                    'code' => 'WEB_STORE',
                                    'locales' => ['%locale%'],
                                    'currencies' => ['EUR'],
                                    'hostname' => 'app.test',
                                    'enabled' => true,
                                    'menu_taxon' => 'category',
                                    'shop_billing_data' => [
                                        'company' => 'App',
                                        'country_code' => 'FR',
                                        'street' => '1 rue Example',
                                        'city' => 'Paris',
                                        'postcode' => '75001',
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
