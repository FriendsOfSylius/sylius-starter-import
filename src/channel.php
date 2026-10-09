<?php

declare(strict_types=1);

namespace SyliusStarter\Import;

function shop_hostname(?string $domain = null, ?string $subdomain = null): string
{
    $domain ??= 'app.test';

    return $subdomain ? $subdomain . '.' . $domain : $domain;
}

function import_list_shop_hostname(string $slug): string
{
    $domain = ImportContext::tryCurrent()?->app()->domain();
    $domain = null !== $domain && '' !== trim($domain) ? trim($domain) : 'app.test';

    return shop_hostname($domain, $slug);
}

/**
 * When --subdomain is omitted (API / non-interactive), use the project slug as shop hostname prefix.
 * Pass an empty string explicitly to use the apex domain (no subdomain).
 */
function resolve_import_shop_subdomain(?string $subdomain, string $projectSlug): ?string
{
    if (null === $subdomain) {
        return $projectSlug;
    }

    $trimmed = trim($subdomain);

    return '' === $trimmed ? null : $trimmed;
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
