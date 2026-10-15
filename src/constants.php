<?php

declare(strict_types=1);

namespace Sylius\Starter\Import;

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
// Imported taxons are all menu children: beyond this, theme menus wrap over many lines.
const IMPORT_MENU_TAXON_LIMIT = 12;
// Above this many collections holding products, AI groups them into a two-level menu (e.g. Men > T-shirts).
const IMPORT_TAXON_GROUPING_THRESHOLD = 8;
const IMPORT_TAXON_GROUP_LIMIT = 6;
const IMPORT_TAXON_GROUP_CHILD_LIMIT = 8;
const IMPORT_LOCALE_PREFERENCE = ['fr', 'en', 'es', 'de', 'it', 'nl'];
const IMPORT_IMAGE_MAX_DIMENSION = 2048;

/** Sub-directory of a project's fixtures holding those contributed by import extensions. */
const IMPORT_EXTENSION_FIXTURES_DIR = 'extensions';

/** Channel created by the Sylius default fixtures, served on the app main domain. */
const IMPORT_DEFAULT_CHANNEL_CODE = 'WEB_STORE';

/** Host path of import payloads, relative to the Castor project root. */
const IMPORT_VAR_HOST_DIR = ImportPaths::VAR_HOST_DIR;
