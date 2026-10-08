<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'sylius_fixtures' => [
        'suites' => [
            'app' => [
                'fixtures' => [
                    'admin_user' => [
                        'options' => [
                            'custom' => [
                                'admin' => [
                                    'email' => 'sylius@example.com',
                                    'username' => 'sylius',
                                    'password' => '%env(default:sylius:SYLIUS_ADMIN_PASSWORD)',
                                    'locale_code' => 'en_US',
                                    'avatar' => '@SyliusCoreBundle/Resources/fixtures/adminAvatars/luke.webp',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
