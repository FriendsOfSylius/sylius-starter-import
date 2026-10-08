<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'imports' => [
        ['resource' => 'app/locales.php'],
        ['resource' => 'app/currencies.php'],
        ['resource' => 'app/geographical.php'],
        ['resource' => 'app/taxon.php'],
        ['resource' => 'app/channels.php'],
        ['resource' => 'app/tax_category.php'],
        ['resource' => 'app/tax_rate.php'],
        ['resource' => 'app/payment_method.php'],
        ['resource' => 'app/shipping_method.php'],
        ['resource' => 'app/admin_users.php'],
        ['resource' => 'app/shop_users.php'],
    ],
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'listeners' => [
                    'orm_purger' => null,
                    'images_purger' => null,
                    'logger' => null,
                ],
            ],
        ],
    ],
]);
