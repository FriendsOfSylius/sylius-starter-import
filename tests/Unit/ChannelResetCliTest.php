<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\Import\import_channel_reset_cli;

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
}
