<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SyliusStarter\Import\Tests\CastorContainerTrait;

use function SyliusStarter\Import\apply_taxon_grouping;
use function SyliusStarter\Import\build_product_fixtures;
use function SyliusStarter\Import\build_taxon_fixture;
use function SyliusStarter\Import\organize_menu_taxons;
use function SyliusStarter\Import\parse_taxon_grouping;

final class TaxonGroupingTest extends TestCase
{
    use CastorContainerTrait;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
    }

    public function testGroupsCollectionsIntoATwoLevelTree(): void
    {
        $taxonIndex = [
            $this->taxon('shop_sweats_homme', 'Sweats Homme', 'https://example.test/sweats.jpg'),
            $this->taxon('shop_sweat_capuche_homme', 'Sweat à Capuche Homme'),
            $this->taxon('shop_t_shirt_homme_noel', 'T-shirt Homme pour Noël'),
            $this->taxon('shop_t_shirts_femme', 'T-shirts Femme'),
            $this->taxon('shop_empty', 'Empty'),
        ];

        [$index, $assignments] = apply_taxon_grouping(
            $taxonIndex,
            [1 => 'shop_sweats_homme', 2 => 'shop_sweat_capuche_homme', 3 => 'shop_sweat_capuche_homme', 4 => 'shop_t_shirt_homme_noel', 5 => 'shop_t_shirts_femme', 6 => 'category'],
            [
                ['name' => 'Homme', 'children' => [
                    // The AI also put "T-shirts Femme" here: the Femme group wins as the name mentions it.
                    ['name' => 'T-shirts', 'collections' => ['T-shirts Femme', 'T-shirt Homme pour Noël']],
                    ['name' => 'Sweats', 'collections' => ['Sweat à Capuche Homme', 'sweats homme', 'Unknown']],
                ]],
                ['name' => 'Femme', 'children' => [
                    ['name' => 'T-shirts', 'collections' => ['T-shirts Femme', 'Sweats Homme']],
                ]],
            ],
            'shop',
        );

        static::assertSame([
            ['code' => 'shop_homme', 'name' => 'Homme', 'slug' => 'shop_homme', 'image' => 'https://example.test/sweats.jpg'],
            ['code' => 'shop_homme_sweats', 'name' => 'Sweats', 'slug' => 'shop_homme_sweats', 'image' => 'https://example.test/sweats.jpg', 'parent' => 'shop_homme'],
            ['code' => 'shop_homme_t_shirts', 'name' => 'T-shirts', 'slug' => 'shop_homme_t_shirts', 'image' => null, 'parent' => 'shop_homme'],
            ['code' => 'shop_femme', 'name' => 'Femme', 'slug' => 'shop_femme', 'image' => null],
            ['code' => 'shop_femme_t_shirts', 'name' => 'T-shirts', 'slug' => 'shop_femme_t_shirts', 'image' => null, 'parent' => 'shop_femme'],
        ], $index);
        static::assertSame([
            1 => 'shop_homme_sweats',
            2 => 'shop_homme_sweats',
            3 => 'shop_homme_sweats',
            4 => 'shop_homme_t_shirts',
            5 => 'shop_femme_t_shirts',
            6 => 'category',
        ], $assignments);
    }

    public function testCollectionsLeftOutOfTheTreeFallBackToTheRoot(): void
    {
        [$index, $assignments] = apply_taxon_grouping(
            [$this->taxon('shop_polos', 'Polos'), $this->taxon('shop_soldes', 'Soldes')],
            [1 => 'shop_polos', 2 => 'shop_soldes'],
            [
                ['name' => 'Homme', 'children' => [['name' => 'Polos', 'collections' => ['Polos']]]],
                ['name' => 'Vide', 'children' => [['name' => 'Rien', 'collections' => ['Unknown']]]],
            ],
            'shop',
        );

        static::assertSame(['shop_homme', 'shop_homme_polos'], array_column($index, 'code'));
        static::assertSame([1 => 'shop_homme_polos'], $assignments);
    }

    public function testParsesTheAiTreeAndIgnoresMalformedEntries(): void
    {
        static::assertSame(
            [['name' => 'Homme', 'children' => [['name' => 'Sweats', 'collections' => ['Sweats Homme']]]]],
            parse_taxon_grouping(['groups' => [
                ['name' => ' Homme ', 'children' => [
                    ['name' => 'Sweats', 'collections' => ['Sweats Homme', '', 42]],
                    ['name' => 'Broken'],
                ]],
                'not a group',
            ]]),
        );
    }

    public function testSmallCatalogsKeepAFlatMenuWithoutCallingTheAi(): void
    {
        [$index, $assignments] = organize_menu_taxons(
            [$this->taxon('shop_polos', 'Polos'), $this->taxon('shop_empty', 'Empty')],
            [1 => 'shop_polos'],
            [['id' => 1, 'title' => 'Polo bleu']],
            'shop',
        );

        static::assertSame(['shop_polos'], array_column($index, 'code'));
        static::assertSame([1 => 'shop_polos'], $assignments);
    }

    public function testFixturesNestChildrenAndListProductsInTheirParentTaxons(): void
    {
        $taxonIndex = [
            $this->taxon('shop_homme', 'Homme'),
            ['code' => 'shop_homme_sweats', 'name' => 'Sweats', 'slug' => 'shop_homme_sweats', 'image' => null, 'parent' => 'shop_homme'],
        ];

        $root = build_taxon_fixture($taxonIndex, 'shop')['sylius_fixtures']['suites']['import']['fixtures']['import_taxons']['options']['custom']['shop_category'];

        static::assertSame(['shop_homme'], array_column($root['children'], 'code'));
        static::assertSame(['shop_homme_sweats'], array_column($root['children'][0]['children'], 'code'));

        [$productFixture] = build_product_fixtures(
            [['id' => 1, 'title' => 'Sweat marine']],
            'shop',
            [1 => 'shop_homme_sweats'],
            [1 => 4900],
            [],
            array_column($taxonIndex, 'parent', 'code'),
        );
        $product = $productFixture['sylius_fixtures']['suites']['import']['fixtures']['import_products']['options']['custom'][0];

        static::assertSame('shop_homme_sweats', $product['main_taxon']);
        static::assertSame(['shop_homme_sweats', 'shop_homme', 'shop_category'], $product['taxons']);
    }

    /**
     * @return array{code: string, name: string, slug: string, image: ?string}
     */
    private function taxon(string $code, string $name, ?string $image = null): array
    {
        return ['code' => $code, 'name' => $name, 'slug' => $code, 'image' => $image];
    }
}
