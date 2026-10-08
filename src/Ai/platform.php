<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use Castor\Sylius\Import\Dto\CollectionEntry;
use Castor\Sylius\Import\Dto\CollectionExtraction;
use Castor\Sylius\Import\Dto\ProductSelectionEntry;
use Castor\Sylius\Import\Dto\ProductSelectionExtraction;
use Castor\Sylius\Import\Dto\ProductTaxonAssignmentEntry;
use Castor\Sylius\Import\Dto\ProductTaxonAssignmentExtraction;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenRouter\Factory as OpenRouterFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\Component\Yaml\Yaml;

function ai_api_key(): string
{
    $apiKey = $_ENV['AI_API_KEY'] ?? $_SERVER['AI_API_KEY'] ?? getenv('AI_API_KEY');

    if (!\is_string($apiKey) || '' === trim($apiKey)) {
        throw new \RuntimeException('AI_API_KEY is not set in .castor/.env.');
    }

    return trim($apiKey);
}

function ai_provider(): string
{
    $provider = $_ENV['AI_PROVIDER'] ?? $_SERVER['AI_PROVIDER'] ?? getenv('AI_PROVIDER');

    if (!\is_string($provider) || '' === trim($provider)) {
        return 'openrouter';
    }

    $provider = strtolower(trim($provider));

    if (!\in_array($provider, ['ollama', 'openrouter'], true)) {
        throw new \RuntimeException(\sprintf('Invalid AI_PROVIDER "%s". Expected ollama or openrouter.', $provider));
    }

    return $provider;
}

function ai_base_url(): string
{
    $url = $_ENV['AI_BASE_URL'] ?? $_SERVER['AI_BASE_URL'] ?? getenv('AI_BASE_URL');

    if (\is_string($url) && '' !== trim($url)) {
        return rtrim(trim($url), '/');
    }

    return 'http://127.0.0.1:11434';
}

function validate_ai_config(): void
{
    if ('openrouter' === ai_provider()) {
        ai_api_key();
    }
}

function ai_model(): string
{
    $model = $_ENV['AI_MODEL'] ?? $_SERVER['AI_MODEL'] ?? getenv('AI_MODEL');

    if (\is_string($model) && '' !== trim($model)) {
        return trim($model);
    }

    return match (ai_provider()) {
        'ollama' => 'qwen2.5:latest',
        default => 'openai/gpt-oss-20b:free',
    };
}

function clean_html_for_ai(string $html): string
{
    $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
    $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
    $html = preg_replace('/\b(?:src|href|srcset|content)\s*=\s*["\']data:[^"\']*["\']/i', '', $html) ?? $html;
    $html = preg_replace('/url\(\s*["\']?data:[^"\')\s]*["\']?\s*\)/i', '', $html) ?? $html;

    return $html;
}

function normalize_ai_json(string $content): string
{
    $content = trim($content);

    if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $content, $matches)) {
        return trim($matches[1]);
    }

    if (str_starts_with($content, '{') || str_starts_with($content, '[')) {
        return $content;
    }

    if (preg_match('/(\{.*\})/s', $content, $matches)) {
        return trim($matches[1]);
    }

    return $content;
}

function deserialize_collection_extraction(string $content): CollectionExtraction
{
    $content = normalize_ai_json($content);

    $serializer = new \Symfony\AI\Platform\StructuredOutput\Serializer();

    try {
        $extraction = $serializer->deserialize($content, CollectionExtraction::class, 'json');

        if ($extraction instanceof CollectionExtraction && [] !== $extraction->collections) {
            return $extraction;
        }
    } catch (\Throwable) {
    }

    return build_collection_extraction_from_array(decode_ai_json($content));
}

/**
 * @return array<string, mixed>
 */
function decode_ai_json(string $content): array
{
    try {
        /** @var array<string, mixed> $data */
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
        throw new \RuntimeException('AI response is not valid JSON.', previous: $exception);
    }

    return $data;
}

/**
 * @param array<string, mixed> $data
 */
/**
 * @param array<string, mixed> $data
 *
 * @return array<int, mixed>|null
 */
function unwrap_ai_items_array(array $data): ?array
{
    if (array_is_list($data)) {
        return $data;
    }

    $items = $data['collections'] ?? $data['categories'] ?? $data['items'] ?? null;

    if (\is_array($items)) {
        return $items;
    }

    foreach (['data', 'result', 'output'] as $wrapper) {
        if (!isset($data[$wrapper]) || !\is_array($data[$wrapper])) {
            continue;
        }

        $nested = $data[$wrapper];
        $items = $nested['collections'] ?? $nested['categories'] ?? $nested['items'] ?? null;

        if (\is_array($items)) {
            return $items;
        }

        if (array_is_list($nested)) {
            return $nested;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $data
 */
function build_collection_extraction_from_array(array $data): CollectionExtraction
{
    $items = unwrap_ai_items_array($data);

    if (!\is_array($items)) {
        throw new \RuntimeException('AI response has no collections array.');
    }

    $extraction = new CollectionExtraction();

    foreach ($items as $item) {
        if (!\is_array($item)) {
            continue;
        }

        $name = trim((string) ($item['name'] ?? $item['title'] ?? $item['label'] ?? ''));

        if ('' === $name) {
            continue;
        }

        $entry = new CollectionEntry();
        $entry->name = $name;

        $image = $item['image'] ?? $item['image_url'] ?? $item['img'] ?? null;

        if (\is_array($image)) {
            $image = $image['src'] ?? $image['url'] ?? null;
        }

        if (\is_string($image)) {
            $image = trim($image);

            if ('' !== $image && 'null' !== strtolower($image)) {
                $entry->image = $image;
            }
        }

        $extraction->collections[] = $entry;
    }

    return $extraction;
}

function deserialize_product_selection(string $content): ProductSelectionExtraction
{
    $content = normalize_ai_json($content);

    try {
        $manual = build_product_selection_from_array(decode_ai_json($content));

        if ([] !== $manual->products) {
            return $manual;
        }
    } catch (\Throwable) {
    }

    $serializer = new \Symfony\AI\Platform\StructuredOutput\Serializer();

    try {
        $extraction = $serializer->deserialize($content, ProductSelectionExtraction::class, 'json');

        if (
            $extraction instanceof ProductSelectionExtraction
            && [] !== $extraction->products
            && !is_degenerate_product_selection($extraction)
        ) {
            return $extraction;
        }
    } catch (\Throwable) {
    }

    return build_product_selection_from_array(decode_ai_json($content));
}

function is_degenerate_product_selection(ProductSelectionExtraction $extraction): bool
{
    if ([] === $extraction->products) {
        return true;
    }

    $ids = array_map(
        static fn(ProductSelectionEntry $entry): int => $entry->product_id,
        $extraction->products,
    );

    return \count($ids) > 1 && 1 === \count(array_unique($ids));
}

/**
 * @param array<string, mixed> $data
 */
function build_product_selection_from_array(array $data): ProductSelectionExtraction
{
    $items = $data['products'] ?? $data['selection'] ?? $data['items'] ?? null;

    if (!\is_array($items)) {
        throw new \RuntimeException('AI response has no products array.');
    }

    $extraction = new ProductSelectionExtraction();

    foreach ($items as $item) {
        $entry = new ProductSelectionEntry();

        if (\is_array($item)) {
            $entry->product_id = (int) ($item['product_id'] ?? $item['id'] ?? 0);
        } else {
            $entry->product_id = (int) $item;
        }

        if ($entry->product_id < 0) {
            continue;
        }

        $extraction->products[] = $entry;
    }

    return $extraction;
}

function deserialize_product_taxon_assignment(string $content): ProductTaxonAssignmentExtraction
{
    $content = normalize_ai_json($content);

    $serializer = new \Symfony\AI\Platform\StructuredOutput\Serializer();

    try {
        $extraction = $serializer->deserialize($content, ProductTaxonAssignmentExtraction::class, 'json');

        if ($extraction instanceof ProductTaxonAssignmentExtraction && [] !== $extraction->assignments) {
            return $extraction;
        }
    } catch (\Throwable) {
    }

    return build_product_taxon_assignment_from_array(decode_ai_json($content));
}

/**
 * @param array<string, mixed> $data
 */
function build_product_taxon_assignment_from_array(array $data): ProductTaxonAssignmentExtraction
{
    $items = $data['assignments'] ?? $data['products'] ?? $data['items'] ?? null;

    if (!\is_array($items)) {
        throw new \RuntimeException('AI response has no assignments array.');
    }

    $extraction = new ProductTaxonAssignmentExtraction();

    foreach ($items as $item) {
        if (!\is_array($item)) {
            continue;
        }

        $entry = new ProductTaxonAssignmentEntry();
        $entry->product_id = (int) ($item['product_id'] ?? $item['id'] ?? 0);
        $entry->collection_name = trim((string) ($item['collection_name'] ?? $item['collection'] ?? $item['taxon'] ?? ''));

        $price = $item['price_eur'] ?? $item['price'] ?? 0.0;
        $entry->price_eur = is_numeric($price) ? (float) $price : 0.0;

        if ($entry->product_id < 0 || '' === $entry->collection_name) {
            continue;
        }

        $extraction->assignments[] = $entry;
    }

    return $extraction;
}

/**
 * @param array<int, array<string, mixed>> $products
 *
 * @return array<int, string>
 */
function ai_http_client(): \Symfony\Contracts\HttpClient\HttpClientInterface
{
    return new \Symfony\Component\HttpClient\CurlHttpClient([
        'timeout' => 300,
        'max_duration' => 300,
        'proxy' => null,
        'headers' => ['User-Agent' => 'sylius-starter-import/1.0'],
    ]);
}

function create_ai_platform(): object
{
    validate_ai_config();

    $client = ai_http_client();

    return match (ai_provider()) {
        'ollama' => OllamaFactory::createPlatform(
            endpoint: ai_base_url(),
            httpClient: $client,
        ),
        'openrouter' => OpenRouterFactory::createPlatform(
            apiKey: ai_api_key(),
            httpClient: $client,
        ),
    };
}
