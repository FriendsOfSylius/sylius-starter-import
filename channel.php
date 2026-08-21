<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use function Castor\variable;

function shop_root_domain(): string
{
    $domain = variable('root_domain', 'app.test');

    return \is_string($domain) && '' !== trim($domain) ? trim($domain) : 'app.test';
}

function shop_hostname(string $slug): string
{
    return $slug . '.' . shop_root_domain();
}

function channel_code_from_slug(string $slug): string
{
    $code = strtoupper(str_replace('-', '_', $slug));
    $code = preg_replace('/[^A-Z0-9_]/', '', $code) ?? '';

    return '' !== $code ? $code : 'SHOP';
}

function import_code_prefix(string $slug): string
{
    $prefix = strtolower(str_replace('-', '_', $slug));
    $prefix = preg_replace('/[^a-z0-9_]/', '', $prefix) ?? '';

    return '' !== $prefix ? $prefix : 'shop';
}

function prefixed_import_code(string $slug, string $code): string
{
    $prefix = import_code_prefix($slug);

    if (str_starts_with($code, $prefix . '_')) {
        return $code;
    }

    return $prefix . '_' . $code;
}

function shop_menu_taxon_code(string $slug): string
{
    return prefixed_import_code($slug, 'category');
}

function import_channel_reset_cli(string $projectSlug): string
{
    return \sprintf(
        'php bin/console sylius:import:channel:reset %s --prefix=%s --shop-email=%s -n',
        channel_code_from_slug($projectSlug),
        import_code_prefix($projectSlug),
        import_shop_user_email($projectSlug),
    );
}

function generate_import_password(): string
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $password = '';

    for ($index = 0; $index < 12; ++$index) {
        $password .= $characters[random_int(0, \strlen($characters) - 1)];
    }

    return $password;
}

function import_admin_user_email(string $slug): string
{
    return $slug . '@import.local';
}

function import_shop_user_email(string $slug): string
{
    return $slug . '@shop.local';
}
