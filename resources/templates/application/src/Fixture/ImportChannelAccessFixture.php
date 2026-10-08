<?php

declare(strict_types=1);

namespace App\Fixture;

use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ImportChannelAccessFixture extends AbstractFixture
{
    private OptionsResolver $optionsResolver;

    /**
     * @param ChannelRepositoryInterface<ChannelInterface>                      $channelRepository
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface>          $paymentMethodRepository
     * @param ShippingMethodRepositoryInterface<ShippingMethodInterface>        $shippingMethodRepository
     */
    public function __construct(
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly ShippingMethodRepositoryInterface $shippingMethodRepository,
    ) {
        $this->optionsResolver = (new OptionsResolver())
            ->setDefault('custom', [])
            ->setAllowedTypes('custom', 'array');
    }

    public function getName(): string
    {
        return 'import_channel_access';
    }

    public function load(array $options): void
    {
        $options = $this->optionsResolver->resolve($options);

        foreach ($options['custom'] as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $channelCode = trim((string) ($item['channel'] ?? ''));

            if ('' === $channelCode) {
                continue;
            }

            /** @var ChannelInterface|null $channel */
            $channel = $this->channelRepository->findOneBy(['code' => $channelCode]);

            if (null === $channel) {
                continue;
            }

            foreach ($this->paymentMethodRepository->findAll() as $method) {
                if (!$method->hasChannel($channel)) {
                    $method->addChannel($channel);
                }
            }

            foreach ($this->shippingMethodRepository->findAll() as $method) {
                if (!$method->hasChannel($channel)) {
                    $method->addChannel($channel);
                }
            }
        }
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->arrayNode('custom')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('channel')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
