<?php

declare(strict_types=1);

namespace App\Import\Storefront;

use App\Storefront\Hero\HeroContent;
use App\Storefront\Hero\HeroContributorInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Feeds the homepage hero with the header image imported for the current channel.
 * Text stays with the theme unless the import provides some.
 */
#[AsTaggedItem(priority: 100)]
final class ImportHeroContributor implements HeroContributorInterface
{
    /**
     * @param array<string, array<string, string>> $shopImagesByChannel
     */
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        #[Autowire(param: 'app.shop_images_by_channel')]
        private readonly array $shopImagesByChannel = [],
    ) {}

    public function contribute(HeroContent $hero): HeroContent
    {
        try {
            /** @var ChannelInterface $channel */
            $channel = $this->channelContext->getChannel();
        } catch (\Throwable) {
            return $hero;
        }

        $image = $this->shopImagesByChannel[$channel->getCode()]['imageHeader'] ?? null;

        if (null === $image) {
            return $hero;
        }

        return $hero->merge(new HeroContent(
            image: $image,
            imageAlt: $channel->getName(),
        ));
    }
}
