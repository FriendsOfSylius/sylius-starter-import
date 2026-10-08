<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Dto;

final class ProductTaxonAssignmentEntry
{
    public int $product_id = 0;

    public string $collection_name = '';

    public float $price_eur = 0.0;
}
