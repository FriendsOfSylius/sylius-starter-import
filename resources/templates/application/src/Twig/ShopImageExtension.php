<?php

declare(strict_types=1);

namespace App\Twig;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ShopImageExtension extends AbstractExtension
{
    /**
     * @param array<string, array<string, string>> $shopImagesByChannel
     */
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        #[Autowire(param: 'app.shop_images_by_channel')]
        private readonly array $shopImagesByChannel = [],
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shop_image', $this->getShopImage(...)),
        ];
    }

    public function getShopImage(string $role): ?string
    {
        try {
            /** @var ChannelInterface $channel */
            $channel = $this->channelContext->getChannel();
        } catch (\Throwable) {
            return null;
        }

        return $this->shopImagesByChannel[$channel->getCode()][$role] ?? null;
    }
}
