<?php

declare(strict_types=1);

namespace App\Repository\Taxonomy;

use App\Import\ImportChannelAdminContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\TaxonomyBundle\Doctrine\ORM\TaxonRepository as BaseTaxonRepository;

class TaxonRepository extends BaseTaxonRepository
{
    public function __construct(
        EntityManagerInterface $entityManager,
        ClassMetadata $class,
        private readonly ImportChannelAdminContext $importChannelAdminContext,
    ) {
        parent::__construct($entityManager, $class);
    }

    public function createListQueryBuilder(): QueryBuilder
    {
        $queryBuilder = parent::createListQueryBuilder();
        $this->applyImportChannelScope($queryBuilder);

        return $queryBuilder;
    }

    private function applyImportChannelScope(QueryBuilder $queryBuilder): void
    {
        if (!$this->importChannelAdminContext->isChannelAdmin()) {
            return;
        }

        $prefix = $this->importChannelAdminContext->getImportCodePrefix();

        if (null === $prefix) {
            return;
        }

        $queryBuilder
            ->andWhere('o.code LIKE :importTaxonPrefix OR o.code = :importTaxonRoot')
            ->setParameter('importTaxonPrefix', $prefix . '_%')
            ->setParameter('importTaxonRoot', $prefix . '_category')
        ;
    }
}
