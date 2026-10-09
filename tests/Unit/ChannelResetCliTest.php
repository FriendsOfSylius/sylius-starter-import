<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\Import\import_channel_reset_cli;
use function SyliusStarter\Import\resolve_import_shop_target;

final class ChannelResetCliTest extends TestCase
{
    public function testResetCommandTargetsTheShopChannelAndPrefix(): void
    {
        static::assertSame(
            'php bin/console sylius:import:channel:reset COCORICO --prefix=cocorico --shop-email=cocorico@shop.local -n',
            import_channel_reset_cli('cocorico'),
        );
        static::assertSame(
            'php bin/console sylius:import:channel:reset TRACTEURS_AND_CO --prefix=tracteurs_and_co --shop-email=tracteurs-and-co@shop.local -n',
            import_channel_reset_cli('tracteurs-and-co'),
        );
    }

    public function testResetCommandKeepsASharedChannel(): void
    {
        static::assertSame(
            'php bin/console sylius:import:channel:reset WEB_STORE --prefix=cocorico --shop-email=cocorico@shop.local --keep-channel -n',
            import_channel_reset_cli('cocorico', 'WEB_STORE', true),
        );
    }

    public function testASubdomainGetsADedicatedChannel(): void
    {
        static::assertSame(
            ['channel' => 'TRACTEURS_AND_CO', 'subdomain' => 'tracteurs', 'shared' => false],
            resolve_import_shop_target('tracteurs-and-co', ' tracteurs '),
        );
    }

    public function testNoSubdomainTargetsTheDefaultChannel(): void
    {
        foreach ([null, '', '  '] as $subdomain) {
            static::assertSame(
                ['channel' => 'WEB_STORE', 'subdomain' => null, 'shared' => true],
                resolve_import_shop_target('tracteurs-and-co', $subdomain),
            );
        }
    }
}
