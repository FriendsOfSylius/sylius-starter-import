<?php

declare(strict_types=1);

namespace App\Fixture;

use App\Entity\Product\Product;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ImportProductPriceFixture extends AbstractFixture
{
    private OptionsResolver $optionsResolver;

    /**
     * @param ProductRepositoryInterface<ProductInterface> $productRepository
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     * @param FactoryInterface<ChannelPricingInterface>    $channelPricingFactory
     */
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly FactoryInterface $channelPricingFactory,
    ) {
        $this->optionsResolver = (new OptionsResolver())
            ->setDefault('custom', [])
            ->setAllowedTypes('custom', 'array');
    }

    public function getName(): string
    {
        return 'import_product_price';
    }

    public function load(array $options): void
    {
        $options = $this->optionsResolver->resolve($options);

        foreach ($options['custom'] as $item) {
            $this->loadProductPrice($item);
        }

        $this->getObjectManager()->flush();
        $this->getObjectManager()->clear();
    }

    private function getObjectManager(): ObjectManager
    {
        $manager = $this->managerRegistry->getManagerForClass(Product::class);

        if (!$manager instanceof ObjectManager) {
            throw new \RuntimeException('Could not resolve Doctrine manager for Product.');
        }

        return $manager;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function loadProductPrice(array $item): void
    {
        $code = trim((string) ($item['code'] ?? ''));
        $channelCode = trim((string) ($item['channel'] ?? ''));
        $price = (int) ($item['price'] ?? 0);
        $originalPrice = isset($item['original_price']) ? (int) $item['original_price'] : null;

        if ('' === $code || $price <= 0) {
            return;
        }

        /** @var ProductInterface|null $product */
        $product = $this->productRepository->findOneBy(['code' => $code]);

        if (null === $product) {
            return;
        }

        /** @var ProductVariantInterface|false $variant */
        $variant = $product->getVariants()->first();

        if (false === $variant) {
            return;
        }

        /** @var ChannelInterface|null $channel */
        $channel = $this->channelRepository->findOneBy(['code' => $channelCode]);

        if (null === $channel) {
            return;
        }

        $channelPricing = $variant->getChannelPricingForChannel($channel);

        if (null === $channelPricing) {
            /** @var ChannelPricingInterface $channelPricing */
            $channelPricing = $this->channelPricingFactory->createNew();
            $channelPricing->setChannelCode($channelCode);
            $variant->addChannelPricing($channelPricing);
        }

        $channelPricing->setPrice($price);

        if (null !== $originalPrice && $originalPrice > $price) {
            $channelPricing->setOriginalPrice($originalPrice);
        }

        $this->getObjectManager()->persist($variant);

        if (!$this->getObjectManager()->contains($channelPricing)) {
            $this->getObjectManager()->persist($channelPricing);
        }
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->arrayNode('custom')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('code')->isRequired()->cannotBeEmpty()->end()
                            ->integerNode('price')->isRequired()->min(1)->end()
                            ->integerNode('original_price')->min(1)->end()
                            ->scalarNode('channel')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
