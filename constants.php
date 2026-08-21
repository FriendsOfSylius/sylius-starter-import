<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

const AI_CATALOG_SAMPLE_SIZE = 300;
const HTML_MAX_LENGTH = 120_000;
const IMPORT_PRODUCT_LIMIT = 100;
const IMPORT_AI_PRODUCT_LIMIT = 20;
const IMPORT_PRICE_EUR_MIN = 3.0;
const IMPORT_PRICE_EUR_FALLBACK = 19.99;
const IMPORT_PROMO_PRODUCT_COUNT = 5;
const IMPORT_PROMO_ORIGINAL_PRICE_RATIO = 1.33;
const IMPORT_PLATFORM_SHOPIFY_THRESHOLD = 3;
const IMPORT_COLLECTION_AI_THRESHOLD = 3;
const IMPORT_LOCALE_PREFERENCE = ['fr', 'en', 'es', 'de', 'it', 'nl'];
const IMPORT_IMAGE_MAX_DIMENSION = 2048;

/** Host path of import payloads, relative to the Castor project root. */
const IMPORT_VAR_HOST_DIR = ImportPaths::VAR_HOST_DIR;

/** Where Sylius containers see that directory (see SyliusService::updateCompose). */
const IMPORT_VAR_CONTAINER_DIR = ImportPaths::VAR_CONTAINER_DIR;
