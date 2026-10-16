<?php

declare(strict_types=1);

namespace App\Import\Storefront;

use App\Storefront\Brand\BrandContent;
use App\Storefront\Brand\BrandContributorInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Feeds the storefront brand with the logo imported for the current channel.
 * Channels without an imported logo keep the theme artwork.
 */
#[AsTaggedItem(priority: 100)]
final class ImportBrandContributor implements BrandContributorInterface
{
    /**
     * @param array<string, array<string, string>> $shopImagesByChannel
     */
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        #[Autowire(param: 'app.shop_images_by_channel')]
        private readonly array $shopImagesByChannel = [],
    ) {}

    public function contribute(BrandContent $brand): BrandContent
    {
        try {
            /** @var ChannelInterface $channel */
            $channel = $this->channelContext->getChannel();
        } catch (\Throwable) {
            return $brand;
        }

        $logo = $this->shopImagesByChannel[$channel->getCode()]['imageLogo'] ?? null;

        if (null === $logo) {
            return $brand;
        }

        return $brand->merge(new BrandContent(
            name: $channel->getName(),
            logo: $logo,
            logoAlt: $channel->getName(),
        ));
    }
}
