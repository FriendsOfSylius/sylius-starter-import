<?php

declare(strict_types=1);

namespace App\Repository\Product;

use App\Import\ImportChannelAdminContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductVariantRepository as BaseProductVariantRepository;
use Sylius\Component\Core\Model\ChannelInterface;

class ProductVariantRepository extends BaseProductVariantRepository
{
    public function __construct(
        EntityManagerInterface $entityManager,
        ClassMetadata $class,
        private readonly ImportChannelAdminContext $importChannelAdminContext,
    ) {
        parent::__construct($entityManager, $class);
    }

    public function createInventoryListQueryBuilder(string $locale): QueryBuilder
    {
        $queryBuilder = parent::createInventoryListQueryBuilder($locale);
        $this->applyImportChannelScope($queryBuilder);

        return $queryBuilder;
    }

    private function applyImportChannelScope(QueryBuilder $queryBuilder): void
    {
        if (!$this->importChannelAdminContext->isChannelAdmin()) {
            return;
        }

        $channel = $this->importChannelAdminContext->getChannel();

        if (!$channel instanceof ChannelInterface) {
            return;
        }

        $queryBuilder
            ->andWhere(':importAdminChannel MEMBER OF product.channels')
            ->setParameter('importAdminChannel', $channel)
        ;
    }
}
