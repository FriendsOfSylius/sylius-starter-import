<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Sylius\TwigHooks\Twig\Component\HookableComponentTrait;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

#[AsTwigComponent]
class HomepageCollectionList
{
    use HookableComponentTrait;

    public const DEFAULT_LIMIT = 8;

    public int $limit = self::DEFAULT_LIMIT;

    /**
     * @param TaxonRepositoryInterface<TaxonInterface>   $taxonRepository
     * @param ProductRepositoryInterface<ProductInterface> $productRepository
     */
    public function __construct(
        private readonly TaxonRepositoryInterface $taxonRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly LocaleContextInterface $localeContext,
    ) {}

    /**
     * @return TaxonInterface[]
     */
    #[ExposeInTemplate('collections')]
    public function getCollections(): array
    {
        /** @var ChannelInterface $channel */
        $channel = $this->channelContext->getChannel();
        $localeCode = $this->localeContext->getLocaleCode();

        $taxons = $this->taxonRepository->findChildrenByChannelMenuTaxon(
            $channel->getMenuTaxon(),
            $localeCode,
        );

        $productCounts = $this->countEnabledProductsByTaxon($taxons, $channel);

        usort(
            $taxons,
            static function (TaxonInterface $a, TaxonInterface $b) use ($productCounts): int {
                $aCount = $productCounts[$a->getId()] ?? 0;
                $bCount = $productCounts[$b->getId()] ?? 0;
                $aTier = self::sortTier($a, $aCount);
                $bTier = self::sortTier($b, $bCount);

                if ($aTier !== $bTier) {
                    return $aTier <=> $bTier;
                }

                if (0 === $aTier && $aCount !== $bCount) {
                    return $bCount <=> $aCount;
                }

                return $a->getPosition() <=> $b->getPosition();
            },
        );

        return \array_slice($taxons, 0, $this->limit);
    }

    /**
     * 0 = image + products, 1 = image only, 2 = everything else.
     */
    private static function sortTier(TaxonInterface $taxon, int $productCount): int
    {
        if (!$taxon->getImages()->isEmpty()) {
            return $productCount > 0 ? 0 : 1;
        }

        return 2;
    }

    /**
     * @param TaxonInterface[] $taxons
     *
     * @return array<int, int>
     */
    private function countEnabledProductsByTaxon(array $taxons, ChannelInterface $channel): array
    {
        if ([] === $taxons) {
            return [];
        }

        $rows = $this->productRepository->createQueryBuilder('product')
            ->select('IDENTITY(productTaxon.taxon) AS taxonId, COUNT(DISTINCT product.id) AS productCount')
            ->innerJoin('product.productTaxons', 'productTaxon')
            ->andWhere('productTaxon.taxon IN (:taxons)')
            ->andWhere(':channel MEMBER OF product.channels')
            ->andWhere('product.enabled = true')
            ->groupBy('productTaxon.taxon')
            ->setParameter('taxons', $taxons)
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['taxonId']] = (int) $row['productCount'];
        }

        return $counts;
    }
}
