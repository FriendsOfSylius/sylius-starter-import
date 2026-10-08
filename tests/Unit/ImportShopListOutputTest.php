<?php

declare(strict_types=1);

namespace Unit\Import;

use PHPUnit\Framework\TestCase;

use function Castor\Sylius\Import\import_shop_list_table;
use function Castor\Sylius\Import\mode_from_import_sources;

require_once \dirname(__DIR__, 3) . '/src/Import/project.php';

final class ImportShopListOutputTest extends TestCase
{
    /**
     * @return list<array{
     *     slug: string,
     *     name: string,
     *     mode: string|null,
     *     hasYaml: bool,
     *     hasProducts: bool,
     *     productCount: int,
     *     collectionCount: int,
     *     hasFixtures: bool,
     *     channelCode: string,
     *     shopUrl: string,
     *     adminUrl: string,
     *     adminPassword: string|null,
     *     shopPassword: string|null,
     *     shopUserEmail: string,
     *     url: string|null,
     *     description: string|null,
     *     importedAt: string|null,
     *     shopImages: array{logo: bool, header: bool, interstice: bool}|null,
     * }>
     */
    private function shops(): array
    {
        return [
            [
                'slug' => 'cocorico',
                'name' => 'Cocorico',
                'mode' => 'existing',
                'hasYaml' => true,
                'hasProducts' => true,
                'productCount' => 42,
                'collectionCount' => 6,
                'hasFixtures' => false,
                'channelCode' => 'COCORICO',
                'shopUrl' => 'https://cocorico.prevente.test',
                'adminUrl' => 'https://cocorico.prevente.test/admin',
                'adminPassword' => 'admin-pass-12',
                'shopPassword' => 'shop-pass-12',
                'shopUserEmail' => 'cocorico@shop.local',
                'url' => 'https://www.cocorico.store',
                'description' => null,
                'importedAt' => null,
                'shopImages' => ['logo' => true, 'header' => false, 'interstice' => false],
            ],
            [
                'slug' => 'tracteurs-and-co',
                'name' => 'Tracteurs and co',
                'mode' => null,
                'hasYaml' => false,
                'hasProducts' => false,
                'productCount' => 0,
                'collectionCount' => 0,
                'hasFixtures' => true,
                'channelCode' => 'TRACTEURS_AND_CO',
                'shopUrl' => 'https://tracteurs-and-co.prevente.test',
                'adminUrl' => 'https://tracteurs-and-co.prevente.test/admin',
                'adminPassword' => null,
                'shopPassword' => null,
                'shopUserEmail' => 'tracteurs-and-co@shop.local',
                'url' => null,
                'description' => null,
                'importedAt' => null,
                'shopImages' => null,
            ],
        ];
    }

    public function testTableRowsMapShopFieldsForTheCli(): void
    {
        $table = import_shop_list_table($this->shops());

        static::assertSame(
            ['Name', 'Slug', 'Mode', 'YAML', 'Products', 'Fixtures', 'Channel'],
            $table['headers'],
        );
        static::assertSame(
            [
                ['Cocorico', 'cocorico', 'existing', 'yes (42)', '42', 'no', 'COCORICO'],
                ['Tracteurs and co', 'tracteurs-and-co', '—', 'no', '0', 'yes', 'TRACTEURS_AND_CO'],
            ],
            $table['rows'],
        );
    }

    public function testTableIsEmptyWhenThereAreNoShops(): void
    {
        $table = import_shop_list_table([]);

        static::assertSame([], $table['rows']);
    }

    public function testModePrefersProjectConfigOverProductsYaml(): void
    {
        static::assertSame('ai', mode_from_import_sources(
            ['mode' => 'ai'],
            ['mode' => 'existing'],
        ));
    }

    public function testModeFallsBackToProductsYaml(): void
    {
        static::assertSame('existing', mode_from_import_sources(
            null,
            ['mode' => 'existing', 'name' => 'Cocorico'],
        ));
    }

    public function testBlankModeIsIgnored(): void
    {
        static::assertSame('ai', mode_from_import_sources(
            ['mode' => '  '],
            ['mode' => 'ai'],
        ));
        static::assertNull(mode_from_import_sources(['mode' => ''], null));
        static::assertNull(mode_from_import_sources(null, null));
    }
}
