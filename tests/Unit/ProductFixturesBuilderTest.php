<?php

declare(strict_types=1);

namespace Sylius\Starter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sylius\Starter\Import\Tests\CastorContainerTrait;

use function Sylius\Starter\Import\build_product_fixtures;

final class ProductFixturesBuilderTest extends TestCase
{
    use CastorContainerTrait;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
    }

    public function testAiProductFixturesTargetTheImportShopChannel(): void
    {
        $products = [
            [
                'id' => 0,
                'title' => 'Tracteur Massey Ferguson 8500',
                'description' => 'A versatile tractor.',
                'collection_name' => 'Tracteurs',
            ],
        ];

        [$productFixture] = build_product_fixtures(
            $products,
            'tracteurs',
            [0 => 'tracteurs_tracteurs'],
            [0 => 1299999],
        );

        $custom = $productFixture['sylius_fixtures']['suites']['import']['fixtures']['import_products']['options']['custom'];

        static::assertSame('tracteurs_tracteur_massey_ferguson_8500', $custom[0]['code']);
        static::assertSame(['TRACTEURS'], $custom[0]['channels']);
        static::assertSame('tracteurs_tracteurs', $custom[0]['main_taxon']);
        static::assertContains('tracteurs_category', $custom[0]['taxons']);
        static::assertSame('A versatile tractor.', $custom[0]['description']);
    }

    public function testFetchedAndAiBuiltProductsProduceTheSameFixtures(): void
    {
        $fetched = ['id' => 0, 'title' => 'Tracteur', 'url' => 'https://example.test/products/tracteur', 'image' => ''];
        $aiBuilt = ['id' => 0, 'title' => 'Tracteur', 'description' => '', 'collection_name' => 'Tracteurs', 'price_eur' => 12.99];

        $build = static fn(array $product): array => build_product_fixtures([$product], 'tracteurs', [0 => 'tracteurs_tracteurs'], [0 => 1299], [0]);

        static::assertSame($build($fetched), $build($aiBuilt));
    }
}
