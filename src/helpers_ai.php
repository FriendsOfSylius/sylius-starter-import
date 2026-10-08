<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use Castor\Sylius\Import\Dto\AiCatalogExtraction;
use Castor\Sylius\Import\Dto\AiProductEntry;
use Castor\Sylius\Import\Dto\CollectionEntry;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Yaml\Yaml;

use function Castor\fs;
use function Castor\http_client;
use function Castor\io;

function ai_image_model(): string
{
    $model = $_ENV['AI_IMAGE_MODEL'] ?? $_SERVER['AI_IMAGE_MODEL'] ?? getenv('AI_IMAGE_MODEL');

    if (\is_string($model) && '' !== trim($model)) {
        return trim($model);
    }

    return match (ai_provider()) {
        'ollama' => 'x/flux2-klein',
        default => 'black-forest-labs/flux.2-klein-4b',
    };
}

function build_ai_product_system_prompt(int $limit): string
{
    return <<<PROMPT
        Give me exactly {$limit} image creation prompts for an e-commerce site about "{USER_DESCRIPTION}".

        In these {$limit} prompts, include several different objects, or several variations on a single object.
        For example, if the site is about agricultural tools, imagine tractors, shovels, harvesters.
        If the site is about something more specific, like shoe sales, prefer variants — colors, shapes, sizes.

        Each image_prompt must describe a realistic photo or image. Choose ONE consistent visual style for ALL {$limit} products — do not mix styles across the catalog. Pick the style that best fits the store concept:
        - e-commerce photoshoot style (all products: isolated on white background or styled surface, e.g. a perfume bottle on a marble table)
        - in-situ lifestyle style (all products: shown in context, e.g. a t-shirt worn by a man)

        Apply the chosen style uniformly to every image_prompt in the response.

        Each image_prompt stays simple and must not exceed one line.

        For each product, also return:
        - title: a short, marketable product name for the e-commerce listing
        - description: a short product description (1–2 sentences)
        - collection_name: must match one of the generated categories exactly
        - price_eur: a realistic retail price in euros (float, 2 decimals)

        Also generate appropriate product categories/collections for this store (minimum 3, maximum {$limit}/5).
        Return exactly {$limit} products.
        PROMPT;
}

function build_ai_import_data(string $name, string $description, ?string $url, int $limit, ?string $projectSlug = null): void
{
    $name = trim($name);
    $description = trim($description);

    if ('' === $name) {
        io()->error('Project name is required.');

        return;
    }

    if ('' === $description) {
        io()->error('Project description is required.');

        return;
    }

    if ($limit <= 0) {
        io()->error('Limit must be greater than 0.');

        return;
    }

    try {
        ensure_import_ai_ready();
    } catch (\RuntimeException $exception) {
        io()->error($exception->getMessage());

        return;
    }

    try {
        if (null !== $projectSlug && '' !== trim($projectSlug)) {
            $projectSlug = trim($projectSlug);
        } else {
            $projectSlug = normalize_import_name($name);
        }
    } catch (\InvalidArgumentException $exception) {
        io()->error($exception->getMessage());

        return;
    }

    $sourceUrl = trim((string) $url);

    if ('' === $sourceUrl) {
        $sourceUrl = 'https://' . $projectSlug . '.local';
    } else {
        try {
            $sourceUrl = parse_import_site_input($sourceUrl)['base_url'];
        } catch (\InvalidArgumentException $exception) {
            io()->error($exception->getMessage());

            return;
        }
    }

    ensure_castor_var_dir();

    io()->title(\sprintf('Building AI import catalog for "%s"', $name));
    import_log(\sprintf(
        'Environment loaded — AI provider: %s, model: %s.',
        ai_provider(),
        ai_model(),
    ));

    $systemPrompt = str_replace('{USER_DESCRIPTION}', $description, build_ai_product_system_prompt($limit));

    $userMessage = json_encode([
        'project_name' => $name,
        'project_description' => $description,
        'source_url' => $sourceUrl,
        'product_limit' => $limit,
    ], \JSON_UNESCAPED_UNICODE) ?: '{}';

    $messages = new MessageBag(
        Message::forSystem($systemPrompt),
        Message::ofUser($userMessage),
    );

    try {
        $platform = create_ai_platform();
        $text = invoke_ai_structured(
            $platform,
            $messages,
            AiCatalogExtraction::class,
            'AI catalog generation',
        );
        $extraction = deserialize_ai_catalog_extraction($text);
    } catch (\Throwable $exception) {
        io()->error(\sprintf('AI catalog generation failed: %s', $exception->getMessage()));

        return;
    }

    $collections = normalize_ai_collections($extraction->collections);
    $products = normalize_ai_products($extraction->products, $collections, $limit);

    if ([] === $collections) {
        io()->error('AI returned no collections.');

        return;
    }

    if ([] === $products) {
        io()->error('AI returned no products.');

        return;
    }

    $importedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    $metadata = [
        'source' => $sourceUrl,
        'platform' => 'ai',
        'mode' => 'ai',
        'name' => $name,
        'description' => $description,
        'imported_at' => $importedAt,
    ];

    $productsPath = castor_var_path($projectSlug);
    $collectionsPath = castor_var_path($projectSlug, 'collections');

    fs()->dumpFile(
        $productsPath,
        Yaml::dump(
            $metadata + ['products' => $products],
            4,
            4,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        ),
    );

    persist_project_config($projectSlug, $metadata);

    fs()->dumpFile(
        $collectionsPath,
        Yaml::dump(
            $metadata + ['collections' => $collections],
            4,
            4,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        ),
    );

    io()->success(\sprintf(
        'Generated %d products and %d collections for "%s" (%s).',
        \count($products),
        \count($collections),
        $name,
        $projectSlug,
    ));
}

function deserialize_ai_catalog_extraction(string $content): AiCatalogExtraction
{
    $content = normalize_ai_json($content);

    $serializer = new \Symfony\AI\Platform\StructuredOutput\Serializer();

    try {
        $extraction = $serializer->deserialize($content, AiCatalogExtraction::class, 'json');

        if ($extraction instanceof AiCatalogExtraction) {
            return $extraction;
        }
    } catch (\Throwable) {
    }

    /** @var array<string, mixed> $data */
    $data = decode_ai_json($content);
    $extraction = new AiCatalogExtraction();

    foreach ($data['collections'] ?? [] as $entry) {
        if (!\is_array($entry)) {
            continue;
        }

        $collection = new CollectionEntry();
        $collection->name = trim((string) ($entry['name'] ?? ''));
        $collection->image = isset($entry['image']) ? (string) $entry['image'] : null;
        $extraction->collections[] = $collection;
    }

    foreach ($data['products'] ?? [] as $entry) {
        if (!\is_array($entry)) {
            continue;
        }

        $product = new AiProductEntry();
        $product->title = trim((string) ($entry['title'] ?? ''));
        $product->image_prompt = trim((string) ($entry['image_prompt'] ?? ''));
        $product->description = trim((string) ($entry['description'] ?? ''));
        $product->collection_name = trim((string) ($entry['collection_name'] ?? ''));
        $product->price_eur = (float) ($entry['price_eur'] ?? 0.0);
        $extraction->products[] = $product;
    }

    return $extraction;
}

/**
 * @param CollectionEntry[] $collections
 *
 * @return array<int, array{name: string}>
 */
function normalize_ai_collections(array $collections): array
{
    /** @var array<string, array{name: string}> $byKey */
    $byKey = [];

    foreach ($collections as $collection) {
        $name = trim($collection->name);

        if ('' === $name) {
            continue;
        }

        $byKey[normalize_label_for_matching($name)] = ['name' => $name];
    }

    if ([] === $byKey) {
        $byKey['category'] = ['name' => 'Category'];
    }

    return array_values($byKey);
}

/**
 * @param AiProductEntry[]          $products
 * @param array<int, array{name: string}> $collections
 *
 * @return array<int, array<string, mixed>>
 */
function normalize_ai_products(array $products, array $collections, int $limit): array
{
    $collectionNames = array_map(static fn(array $collection): string => $collection['name'], $collections);
    $collectionLookup = [];

    foreach ($collectionNames as $collectionName) {
        $collectionLookup[normalize_label_for_matching($collectionName)] = $collectionName;
    }

    $normalized = [];

    foreach ($products as $product) {
        $title = trim($product->title);
        $imagePrompt = trim(str_replace(["\r\n", "\n", "\r"], ' ', $product->image_prompt));

        if ('' === $title || '' === $imagePrompt) {
            continue;
        }

        if (\strlen($imagePrompt) > 500) {
            import_log(\sprintf('Truncating long image_prompt for "%s".', $title));
            $imagePrompt = substr($imagePrompt, 0, 500);
        }

        $collectionName = trim($product->collection_name);
        $collectionKey = normalize_label_for_matching($collectionName);
        $resolvedCollection = $collectionLookup[$collectionKey] ?? ($collectionNames[0] ?? 'Category');

        $description = trim($product->description);

        if ('' === $description) {
            $description = $title;
        }

        $priceEur = normalize_price_eur($product->price_eur, \count($normalized));

        $normalized[] = [
            'title' => $title,
            'image_prompt' => $imagePrompt,
            'description' => $description,
            'collection_name' => $resolvedCollection,
            'price_eur' => $priceEur,
        ];

        if (\count($normalized) >= $limit) {
            break;
        }
    }

    return $normalized;
}

function find_first_image_prompt(array $products): string
{
    foreach ($products as $product) {
        $prompt = trim((string) ($product['image_prompt'] ?? ''));

        if ('' !== $prompt) {
            return $prompt;
        }
    }

    return 'e-commerce product photoshoot, white background, soft natural light';
}

/**
 * @param array<int, array<string, mixed>> $productMap
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 *
 * @return array{
 *     0: array<int, string>,
 *     1: array<int, int>,
 *     2: int[],
 *     3: array<int, array<string, mixed>>
 * }
 */
function build_ai_product_assignments(array $productMap, array $taxonIndex): array
{
    $taxonAssignments = [];
    $priceAssignments = [];
    $catalog = [];

    foreach ($productMap as $productId => $product) {
        $catalog[$productId] = trim((string) ($product['title'] ?? ''));
        $collectionName = trim((string) ($product['collection_name'] ?? 'Category'));
        $taxonAssignments[$productId] = resolve_collection_to_taxon_code($collectionName, $taxonIndex);
        $priceEur = (float) ($product['price_eur'] ?? IMPORT_PRICE_EUR_FALLBACK);
        $priceAssignments[$productId] = (int) round(normalize_price_eur($priceEur, $productId) * 100);
    }

    $promoProductIds = pick_promo_product_ids($catalog);

    return [$taxonAssignments, $priceAssignments, $promoProductIds, array_values($productMap)];
}

/**
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 */
function build_ai_taxon_image_fixture(array $taxonIndex, string $projectSlug, string $styleReferencePrompt): array
{
    $custom = [];

    if (stream_isatty(\STDOUT)) {
        io()->progressStart(\count($taxonIndex));
    }

    foreach ($taxonIndex as $taxon) {
        $prompt = \sprintf(
            'Category thumbnail for "%s", same visual style as: %s',
            $taxon['name'],
            $styleReferencePrompt,
        );
        $imagePath = generate_import_image_from_prompt($prompt, $projectSlug, 'taxon_' . $taxon['code']);

        if (null !== $imagePath) {
            $custom[] = [
                'taxon' => $taxon['code'],
                'path' => $imagePath,
                'type' => 'main',
            ];
        }

        if (stream_isatty(\STDOUT)) {
            io()->progressAdvance();
        }
    }

    if (stream_isatty(\STDOUT)) {
        io()->progressFinish();
    }

    import_log(\sprintf('Taxon images: %d/%d generated.', \count($custom), \count($taxonIndex)));

    return [
        'sylius_fixtures' => [
            'suites' => [
                'import' => [
                    'fixtures' => [
                        'import_taxon_images' => [
                            'name' => 'import_taxon_image',
                            'options' => [
                                'custom' => $custom,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function generate_import_image_from_prompt(string $prompt, string $projectSlug, string $code, bool $quiet = false): ?string
{
    $prompt = trim($prompt);

    if ('' === $prompt) {
        return null;
    }

    $directory = castor_import_media_dir($projectSlug);
    $webpFilename = $code . '.webp';
    $webpPath = $directory . '/' . $webpFilename;

    if (file_exists($webpPath) && filesize($webpPath) > 0) {
        return import_media_fixture_path($projectSlug, $webpFilename);
    }

    try {
        $binary = request_ai_image_binary($prompt);
    } catch (\Throwable $exception) {
        if (!$quiet) {
            io()->warning(\sprintf('Image generation failed for "%s": %s', $code, $exception->getMessage()));
        }

        return null;
    }

    if ('' === $binary) {
        return null;
    }

    $sourcePath = $directory . '/' . $code . '.png';

    if (false === file_put_contents($sourcePath, $binary)) {
        return null;
    }

    if (!convert_image_to_webp($sourcePath, $webpPath, $quiet)) {
        if (!$quiet) {
            io()->warning(\sprintf('Could not convert generated image for "%s" to WebP.', $code));
        }
        cleanup_import_source_image($sourcePath, $webpPath);

        return null;
    }

    cleanup_import_source_image($sourcePath, $webpPath);

    return file_exists($webpPath) && filesize($webpPath) > 0
        ? import_media_fixture_path($projectSlug, $webpFilename)
        : null;
}

function request_ai_image_binary(string $prompt): string
{
    return match (ai_provider()) {
        'ollama' => request_ollama_image_binary($prompt),
        default => request_openrouter_image_binary($prompt),
    };
}

function request_ollama_image_binary(string $prompt): string
{
    $client = ai_http_client();

    $response = $client->request('POST', ai_base_url() . '/api/generate', [
        'json' => [
            'model' => ai_image_model(),
            'prompt' => $prompt,
            'stream' => false,
        ],
    ]);

    $body = $response->getContent(false);
    /** @var array<string, mixed>|null $payload */
    $payload = json_decode($body, true);

    if (!\is_array($payload)) {
        throw new \RuntimeException('Ollama image response is not valid JSON.');
    }

    if (isset($payload['image']) && \is_string($payload['image']) && '' !== $payload['image']) {
        $binary = base64_decode($payload['image'], true);

        if (false !== $binary && '' !== $binary) {
            return $binary;
        }
    }

    if (isset($payload['error']) && \is_string($payload['error'])) {
        throw new \RuntimeException($payload['error']);
    }

    throw new \RuntimeException('Ollama image response did not contain image data.');
}

function request_openrouter_image_binary(string $prompt): string
{
    $client = ai_http_client()->withOptions([
        'headers' => [
            'Authorization' => 'Bearer ' . ai_api_key(),
            'HTTP-Referer' => 'https://sylius-starter.local',
            'X-Title' => 'Sylius Starter Import',
        ],
    ]);

    $response = $client->request('POST', 'https://openrouter.ai/api/v1/images', [
        'json' => [
            'model' => ai_image_model(),
            'prompt' => $prompt,
            'n' => 1,
            'output_format' => 'png',
        ],
    ]);

    $body = $response->getContent(false);
    /** @var array<string, mixed>|null $payload */
    $payload = json_decode($body, true);

    if (!\is_array($payload)) {
        throw new \RuntimeException('OpenRouter image response is not valid JSON.');
    }

    $data = $payload['data'] ?? null;

    if (!\is_array($data) || [] === $data) {
        $error = $payload['error']['message'] ?? $payload['error'] ?? 'Unknown OpenRouter image error';

        throw new \RuntimeException(\is_string($error) ? $error : 'OpenRouter image generation failed.');
    }

    $first = $data[0] ?? null;

    if (!\is_array($first)) {
        throw new \RuntimeException('OpenRouter image response is missing image data.');
    }

    if (isset($first['b64_json']) && \is_string($first['b64_json'])) {
        $binary = base64_decode($first['b64_json'], true);

        if (false !== $binary && '' !== $binary) {
            return $binary;
        }
    }

    if (isset($first['url']) && \is_string($first['url']) && '' !== $first['url']) {
        return http_client()->request('GET', $first['url'])->getContent();
    }

    throw new \RuntimeException('OpenRouter image response did not contain usable image bytes.');
}
