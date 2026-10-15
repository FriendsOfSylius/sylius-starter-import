<?php

declare(strict_types=1);

namespace Sylius\Starter\Import\Dto;

final class TaxonGroupChildEntry
{
    public string $name = '';

    /** @var string[] */
    public array $collections = [];
}
