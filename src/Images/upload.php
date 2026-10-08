<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

require_once __DIR__ . '/../paths.php';

const IMPORT_UPLOAD_MAX_BYTES = 5_242_880;

/**
 * @return list<string>
 */
function import_shop_image_roles(): array
{
    return ['image_header', 'image_interstice', 'image_logo'];
}

function save_uploaded_import_image(string $host, string $role, string $binary, string $mimeType): ?string
{
    if ('' === $binary || \strlen($binary) > IMPORT_UPLOAD_MAX_BYTES) {
        return null;
    }

    if (!\in_array($role, import_shop_image_roles(), true)) {
        return null;
    }

    $mimeType = normalize_upload_mime_type($mimeType, $binary);

    if (null === $mimeType) {
        return null;
    }

    $directory = castor_import_media_dir($host);

    if (!is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    $extension = extension_from_mime_type($mimeType);

    foreach (glob($directory . '/' . $role . '.*') ?: [] as $existingPath) {
        if (is_file($existingPath)) {
            unlink($existingPath);
        }
    }

    $path = $directory . '/' . $role . '.' . $extension;

    if (false === file_put_contents($path, $binary)) {
        return null;
    }

    return file_exists($path) && filesize($path) > 0 ? $path : null;
}

function shop_image_storage_path(string $host, string $role): ?string
{
    if (!\in_array($role, import_shop_image_roles(), true)) {
        return null;
    }

    $directory = castor_import_media_dir($host);

    foreach (glob($directory . '/' . $role . '.*') ?: [] as $path) {
        if (is_file($path) && filesize($path) > 0) {
            return $path;
        }
    }

    return null;
}

/**
 * @return array{logo: bool, header: bool, interstice: bool}
 */
function shop_images_presence(string $host): array
{
    return [
        'logo' => null !== shop_image_storage_path($host, 'image_logo'),
        'header' => null !== shop_image_storage_path($host, 'image_header'),
        'interstice' => null !== shop_image_storage_path($host, 'image_interstice'),
    ];
}

function mime_type_from_image_path(string $path): string
{
    return match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
}

function normalize_upload_mime_type(string $mimeType, string $binary): ?string
{
    $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

    if (\in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return $mimeType;
    }

    if (\function_exists('finfo_open')) {
        $finfo = finfo_open(\FILEINFO_MIME_TYPE);

        if (false !== $finfo) {
            $detected = finfo_buffer($finfo, $binary);
            finfo_close($finfo);

            if (\is_string($detected) && \in_array($detected, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                return $detected;
            }
        }
    }

    return null;
}

function extension_from_mime_type(string $mimeType): string
{
    return match ($mimeType) {
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg',
    };
}
