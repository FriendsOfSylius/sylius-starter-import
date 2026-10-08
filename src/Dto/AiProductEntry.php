<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Dto;

final class AiProductEntry
{
    public string $title = '';

    public string $image_prompt = '';

    public string $description = '';

    public string $collection_name = '';

    public float $price_eur = 0.0;
}
