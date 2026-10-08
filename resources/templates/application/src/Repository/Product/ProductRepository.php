<?php

declare(strict_types=1);

namespace App\Repository\Product;

use App\Import\ImportChannelAdminContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository as BaseProductRepository;
use Sylius\Component\Core\Model\ChannelInterface;

class ProductRepository extends BaseProductRepository
{
    public function __construct(
        EntityManagerInterface $entityManager,
        ClassMetadata $class,
        private readonly ImportChannelAdminContext $importChannelAdminContext,
    ) {
        parent::__construct($entityManager, $class);
    }

    public function createListQueryBuilder(string $locale, mixed $taxonId = null): QueryBuilder
    {
        $queryBuilder = parent::createListQueryBuilder($locale, $taxonId);
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
            ->andWhere(':importAdminChannel MEMBER OF o.channels')
            ->setParameter('importAdminChannel', $channel)
        ;
    }
}
