<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Dto;

final class AiCatalogExtraction
{
    /** @var CollectionEntry[] */
    public array $collections = [];

    /** @var AiProductEntry[] */
    public array $products = [];
}
