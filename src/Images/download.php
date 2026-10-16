<?php

declare(strict_types=1);

namespace Sylius\Starter\Import;

use function Castor\io;

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
 * Shop images are served as static files from the Sylius public directory, one directory per channel
 * (`public/media/shops/{CHANNEL}/{role}.{hash}.{ext}`). The content hash in the file name busts browser
 * caches when an image changes; `app.shop_images_by_channel` only keeps the public URLs.
 */
const SHOP_IMAGES_PUBLIC_PATH = 'media/shops';

/**
 * Container parameter key => import storage role.
 */
const SHOP_IMAGE_PARAMETER_ROLES = [
    'imageHeader' => 'image_header',
    'imageInterstice' => 'image_interstice',
    'imageLogo' => 'image_logo',
];

function shop_images_public_dir(): string
{
    return app_dir() . '/public/' . SHOP_IMAGES_PUBLIC_PATH;
}

/**
 * Copies the images of a shop into the public directory of its channel and returns their URLs.
 * Each image is optional: a shop without a logo still gets its header and interstice.
 *
 * @return array<string, string>
 */
function build_shop_channel_images(string $host): array
{
    $channelCode = import_channel_code($host);
    $directory = shop_channel_images_public_dir($channelCode);
    $images = [];

    foreach (SHOP_IMAGE_PARAMETER_ROLES as $parameterName => $role) {
        $path = shop_image_storage_path($host, $role);

        if (null === $path) {
            continue;
        }

        $hash = hash_file('xxh128', $path);

        if (false === $hash) {
            continue;
        }

        $filename = \sprintf(
            '%s.%s.%s',
            substr($role, \strlen('image_')),
            substr($hash, 0, 16),
            strtolower(pathinfo($path, \PATHINFO_EXTENSION)),
        );
        $target = $directory . '/' . $filename;

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Failed to create the shop images directory "%s".', $directory));
        }

        if (!is_file($target) && !copy($path, $target)) {
            throw new \RuntimeException(\sprintf('Failed to copy the shop image "%s" to "%s".', $path, $target));
        }

        $images[$parameterName] = '/' . SHOP_IMAGES_PUBLIC_PATH . '/' . $channelCode . '/' . $filename;
    }

    return $images;
}

function shop_channel_images_public_dir(string $channelCode): string
{
    if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $channelCode)) {
        throw new \RuntimeException(\sprintf('Invalid channel code "%s" for shop images.', $channelCode));
    }

    return shop_images_public_dir() . '/' . $channelCode;
}

/**
 * Removes the shop image files that $byChannel no longer references: older versions of an image,
 * removed images and the directories of deleted shops.
 *
 * @param array<string, array<string, string>> $byChannel
 */
function prune_shop_images(array $byChannel): void
{
    $root = shop_images_public_dir();

    if (!is_dir($root)) {
        return;
    }

    foreach (scandir($root) ?: [] as $channelCode) {
        $directory = $root . '/' . $channelCode;

        if ('.' === $channelCode || '..' === $channelCode || !is_dir($directory)) {
            continue;
        }

        $keep = array_map(basename(...), array_values($byChannel[$channelCode] ?? []));

        foreach (scandir($directory) ?: [] as $filename) {
            if (is_file($directory . '/' . $filename) && !\in_array($filename, $keep, true)) {
                unlink($directory . '/' . $filename);
            }
        }

        if ([] === array_diff(scandir($directory) ?: [], ['.', '..'])) {
            rmdir($directory);
        }
    }
}

/**
 * @return array{parameters: array{app.shop_images_by_channel: array<string, array<string, string>>}}|null
 */
function build_all_shop_images_config(): ?array
{
    $byChannel = [];

    foreach (discover_import_hosts() as $slug) {
        $channelImages = build_shop_channel_images($slug);

        if ([] === $channelImages) {
            continue;
        }

        $byChannel[import_channel_code($slug)] = $channelImages;
    }

    prune_shop_images($byChannel);

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
