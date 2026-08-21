<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use function Castor\context;
use function Castor\http_client;
use function Castor\io;
use function Castor\run;
use function Castor\variable;
use function Castor\Sylius\Import\add_yaml_import;
use function Castor\Sylius\Import\add_yaml_import_with_options;
use function Castor\Sylius\Import\app_dir;
use function Castor\Sylius\Import\export_array;

function normalize_image_url(string $url): string
{
    $url = trim($url);

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    return $url;
}

function download_import_image(string $url, string $host, string $code, bool $quiet = false): ?string
{
    $url = normalize_image_url($url);

    if ('' === $url || !filter_var($url, \FILTER_VALIDATE_URL)) {
        return null;
    }

    $directory = castor_import_media_dir($host);
    $webpFilename = $code . '.webp';
    $webpPath = $directory . '/' . $webpFilename;

    if (!is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    if (file_exists($webpPath) && filesize($webpPath) > 0) {
        return import_media_fixture_path($host, $webpFilename);
    }

    $sourceExtension = image_extension_from_url($url);
    $sourceFilename = $code . '.' . $sourceExtension;
    $sourcePath = $directory . '/' . $sourceFilename;

    if (!file_exists($sourcePath) || 0 === filesize($sourcePath)) {
        try {
            $client = \Castor\http_client()->withOptions([
                'timeout' => 30,
                'headers' => ['User-Agent' => 'sylius-starter-import/1.0'],
            ]);
            $response = $client->request('GET', $url);

            $detectedExtension = image_extension_from_content_type($response->getHeaders()['content-type'][0] ?? null);

            if (null !== $detectedExtension && $detectedExtension !== $sourceExtension) {
                $sourceExtension = $detectedExtension;
                $sourceFilename = $code . '.' . $sourceExtension;
                $sourcePath = $directory . '/' . $sourceFilename;
            }

            if (!stream_http_response_to_file($response, $client, $sourcePath)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
    }

    if (!file_exists($sourcePath) || 0 === filesize($sourcePath)) {
        return null;
    }

    if ('webp' === $sourceExtension) {
        if ($sourcePath !== $webpPath) {
            rename($sourcePath, $webpPath);
        }

        return import_media_fixture_path($host, $webpFilename);
    }

    if (!convert_image_to_webp($sourcePath, $webpPath, $quiet)) {
        if (!$quiet && \function_exists('imagewebp')) {
            io()->warning(\sprintf(
                'Could not convert image "%s" to WebP for "%s". The image will be skipped.',
                $url,
                $code,
            ));
        }
        cleanup_import_source_image($sourcePath, $webpPath);

        return null;
    }

    cleanup_import_source_image($sourcePath, $webpPath);

    return import_media_fixture_path($host, $webpFilename);
}

function cleanup_import_source_image(string $sourcePath, string $webpPath): void
{
    if ($sourcePath !== $webpPath && file_exists($sourcePath)) {
        unlink($sourcePath);
    }
}

/**
 * @return array<string, string>|null
 */
function build_shop_channel_images(string $host): ?array
{
    $roles = [
        'imageHeader' => 'image_header',
        'imageInterstice' => 'image_interstice',
        'imageLogo' => 'image_logo',
    ];

    $logoPath = shop_image_storage_path($host, 'image_logo');

    if (null === $logoPath) {
        return null;
    }

    $images = [];

    foreach ($roles as $globalName => $role) {
        $path = shop_image_storage_path($host, $role);

        if (null === $path) {
            continue;
        }

        $images[$globalName] = 'data:' . mime_type_from_image_path($path) . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    return $images;
}

/**
 * @return array{parameters: array{app.shop_images_by_channel: array<string, array<string, string>>}}|null
 */
function build_all_shop_images_config(): ?array
{
    $byChannel = [];

    foreach (discover_import_hosts() as $slug) {
        $channelImages = build_shop_channel_images($slug);

        if (null === $channelImages) {
            continue;
        }

        $byChannel[channel_code_from_slug($slug)] = $channelImages;
    }

    if ([] === $byChannel) {
        return null;
    }

    return [
        'parameters' => [
            'app.shop_images_by_channel' => $byChannel,
        ],
    ];
}

function write_shop_images_config(string $host): void
{
    regenerate_shop_images_config();
}

function regenerate_shop_images_config(): void
{
    $config = build_all_shop_images_config();

    if (null === $config) {
        write_empty_shop_images_config();
        import_log('Shop images not found in var — skipping shop_images.php generation.');

        return;
    }

    persist_shop_images_config($config);
}

function write_empty_shop_images_config(): void
{
    persist_shop_images_config([
        'parameters' => [
            'app.shop_images_by_channel' => [],
        ],
    ]);
}

/**
 * @param array{parameters: array{app.shop_images_by_channel: array<string, array<string, string>>}} $config
 */
function persist_shop_images_config(array $config): void
{
    $exported = export_array($config);
    $configDir = app_dir() . '/config/import';
    $path = $configDir . '/shop_images.php';

    if (!is_dir($configDir)) {
        mkdir($configDir, 0o775, true);
    }

    $body = <<<PHP
        <?php

        declare(strict_types=1);

        namespace Symfony\\Component\\DependencyInjection\\Loader\\Configurator;

        return App::config({$exported});

        PHP;

    if (false === file_put_contents($path, $body)) {
        throw new \RuntimeException('Failed to write shop images config.');
    }

    import_log('  → config/import/shop_images.php');
}

function convert_image_to_webp(string $sourcePath, string $destinationPath, bool $quiet = false): bool
{
    if (!\function_exists('imagewebp')) {
        warn_webp_unavailable_once($quiet);

        return false;
    }

    $extension = strtolower(pathinfo($sourcePath, \PATHINFO_EXTENSION));

    $image = match ($extension) {
        'webp' => @imagecreatefromwebp($sourcePath),
        'png' => @imagecreatefrompng($sourcePath),
        'gif' => @imagecreatefromgif($sourcePath),
        'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
        'avif' => \function_exists('imagecreatefromavif') ? @imagecreatefromavif($sourcePath) : false,
        default => false,
    };

    if (!\is_object($image)) {
        return false;
    }

    if (\in_array($extension, ['png', 'gif', 'avif'], true)) {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
    }

    $image = downscale_image_resource($image);

    $converted = imagewebp($image, $destinationPath, 80);
    $success = $converted && file_exists($destinationPath) && filesize($destinationPath) > 0;
    imagedestroy($image);

    return $success;
}

/**
 * @param \Symfony\Contracts\HttpClient\ResponseInterface        $response
 * @param \Symfony\Contracts\HttpClient\HttpClientInterface      $client
 */
function stream_http_response_to_file(object $response, object $client, string $destinationPath): bool
{
    $fileHandle = fopen($destinationPath, 'w');

    if (false === $fileHandle) {
        return false;
    }

    try {
        foreach ($client->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }

            $written = fwrite($fileHandle, $chunk->getContent());

            if (false === $written) {
                fclose($fileHandle);
                unlink($destinationPath);

                return false;
            }
        }
    } catch (\Throwable) {
        fclose($fileHandle);

        if (file_exists($destinationPath)) {
            unlink($destinationPath);
        }

        return false;
    }

    fclose($fileHandle);

    return file_exists($destinationPath) && filesize($destinationPath) > 0;
}

/**
 * @param \GdImage $image
 */
function downscale_image_resource(\GdImage $image, int $maxDimension = IMPORT_IMAGE_MAX_DIMENSION): \GdImage
{
    $width = imagesx($image);
    $height = imagesy($image);

    if (false === $width || false === $height || ($width <= $maxDimension && $height <= $maxDimension)) {
        return $image;
    }

    $ratio = min($maxDimension / $width, $maxDimension / $height);
    $newWidth = max(1, (int) round($width * $ratio));
    $newHeight = max(1, (int) round($height * $ratio));

    $scaled = imagescale($image, $newWidth, $newHeight, \IMG_BILINEAR_FIXED);

    if (!\is_object($scaled)) {
        return $image;
    }

    imagedestroy($image);

    return $scaled;
}

function warn_webp_unavailable_once(bool $quiet): void
{
    static $warned = false;

    if ($quiet || $warned) {
        return;
    }

    $warned = true;
    io()->warning('PHP GD extension does not support WebP (imagewebp unavailable). Import images will be skipped.');
}

function image_extension_from_url(string $url): string
{
    $path = parse_url($url, \PHP_URL_PATH);

    if (!\is_string($path)) {
        return 'jpg';
    }

    $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

    if ('' !== $extension && preg_match('/^(jpg|jpeg|png|gif|webp|avif)$/', $extension)) {
        return 'jpg' === $extension ? 'jpg' : $extension;
    }

    return 'jpg';
}

function image_extension_from_content_type(?string $contentType): ?string
{
    if (null === $contentType) {
        return null;
    }

    return match (true) {
        str_contains($contentType, 'image/png') => 'png',
        str_contains($contentType, 'image/webp') => 'webp',
        str_contains($contentType, 'image/gif') => 'gif',
        str_contains($contentType, 'image/avif') => 'avif',
        str_contains($contentType, 'image/jpeg') => 'jpg',
        default => null,
    };
}

/**
 * @param array<string, mixed> $stats
 *
 * @return array<string, mixed>
 */
