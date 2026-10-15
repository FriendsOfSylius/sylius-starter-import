<?php

declare(strict_types=1);

namespace Sylius\Starter\Import\Dto;

final class TaxonGroupEntry
{
    public string $name = '';

    /** @var TaxonGroupChildEntry[] */
    public array $children = [];
}
