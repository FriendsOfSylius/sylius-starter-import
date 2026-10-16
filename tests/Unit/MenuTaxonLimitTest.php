<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\Import\limit_menu_taxons;

final class MenuTaxonLimitTest extends TestCase
{
    public function testKeepsTheBusiestTaxonsInCatalogOrder(): void
    {
        $taxonIndex = [
            $this->taxon('shop_empty'),
            $this->taxon('shop_polos'),
            $this->taxon('shop_sweats'),
            $this->taxon('shop_socks'),
        ];

        [$kept, $assignments] = limit_menu_taxons(
            $taxonIndex,
            [1 => 'shop_socks', 2 => 'shop_sweats', 3 => 'shop_sweats', 4 => 'shop_polos', 5 => 'category'],
            2,
        );

        static::assertSame(['shop_polos', 'shop_sweats'], array_column($kept, 'code'));
        static::assertSame([2 => 'shop_sweats', 3 => 'shop_sweats', 4 => 'shop_polos', 5 => 'category'], $assignments);
    }

    /**
     * @return array{code: string, name: string, slug: string, image: ?string}
     */
    private function taxon(string $code): array
    {
        return ['code' => $code, 'name' => $code, 'slug' => $code, 'image' => null];
    }
}
