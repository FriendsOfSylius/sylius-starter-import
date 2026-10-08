<?php

declare(strict_types=1);

namespace App\Fixture;

use Sylius\Bundle\CoreBundle\Fixture\ProductFixture;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ImportProductFixture extends AbstractFixture
{
    private OptionsResolver $optionsResolver;

    /**
     * @param ProductRepositoryInterface<ProductInterface> $productRepository
     */
    public function __construct(
        #[Autowire(service: 'sylius.fixture.product')]
        private readonly ProductFixture $productFixture,
        private readonly ProductRepositoryInterface $productRepository,
    ) {
        $this->optionsResolver = (new OptionsResolver())
            ->setDefault('custom', [])
            ->setAllowedTypes('custom', 'array');
    }

    public function getName(): string
    {
        return 'import_product';
    }

    public function load(array $options): void
    {
        $options = $this->optionsResolver->resolve($options);
        $custom = [];
        /** @var array<string, true> $usedSlugs */
        $usedSlugs = [];

        foreach ($options['custom'] as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $code = trim((string) ($item['code'] ?? ''));

            if ('' === $code) {
                continue;
            }

            if (null !== $this->productRepository->findOneBy(['code' => $code])) {
                continue;
            }

            $slug = trim((string) ($item['slug'] ?? ''));

            if ('' === $slug) {
                $slug = str_replace('_', '-', $code);
            }

            if ($this->productSlugExists($slug) || isset($usedSlugs[$slug])) {
                continue;
            }

            $usedSlugs[$slug] = true;
            $item['slug'] = $slug;
            $custom[] = $item;
        }

        if ([] === $custom) {
            return;
        }

        $this->productFixture->load(['custom' => $custom]);
    }

    private function productSlugExists(string $slug): bool
    {
        $existing = $this->productRepository->createQueryBuilder('product')
            ->join('product.translations', 'translation')
            ->andWhere('translation.slug = :slug')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $existing;
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->arrayNode('custom')
                    ->ignoreExtraKeys(false)
                    ->prototype('array')
                        ->ignoreExtraKeys(false)
                    ->end()
                ->end()
            ->end()
        ;
    }
}
