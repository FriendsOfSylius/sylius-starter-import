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

    // Stored target (dedicated subdomain or default channel on the apex); legacy projects fall back to the slug.
    return shop_hostname($domain, import_shop_target($slug)['subdomain']);
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

/**
 * Where a project's catalog lives.
 *
 * - With a subdomain (sales kit / demo environments): a dedicated channel named after the
 *   project, served on "{subdomain}.{domain}". Loading resets and recreates that channel.
 * - Without a subdomain: the default Sylius channel (WEB_STORE) on the main domain. Nothing
 *   is created; loading only replaces the project's prefixed products and taxons.
 *
 * @return array{channel: string, subdomain: ?string, shared: bool}
 */
function resolve_import_shop_target(string $slug, ?string $subdomain): array
{
    $subdomain = null !== $subdomain && '' !== trim($subdomain) ? trim($subdomain) : null;
    $channel = null === $subdomain ? IMPORT_DEFAULT_CHANNEL_CODE : channel_code_from_slug($slug);

    return ['channel' => $channel, 'subdomain' => $subdomain, 'shared' => IMPORT_DEFAULT_CHANNEL_CODE === $channel];
}

/**
 * Target stored by sylius:import:fixtures:generate in project.yaml ("shop" key).
 * Projects generated before it was stored keep their historical dedicated channel.
 *
 * @return array{channel: string, subdomain: ?string, shared: bool}
 */
function import_shop_target(string $slug): array
{
    try {
        $config = load_project_config($slug);
    } catch (\Throwable) {
        $config = null;
    }

    $shop = \is_array($config['shop'] ?? null) ? $config['shop'] : [];
    $channel = trim((string) ($shop['channel'] ?? ''));

    if ('' === $channel) {
        return ['channel' => channel_code_from_slug($slug), 'subdomain' => $slug, 'shared' => false];
    }

    $subdomain = trim((string) ($shop['subdomain'] ?? ''));

    return [
        'channel' => $channel,
        'subdomain' => '' !== $subdomain ? $subdomain : null,
        'shared' => IMPORT_DEFAULT_CHANNEL_CODE === $channel,
    ];
}

/**
 * @return array{channel: string, subdomain: ?string, shared: bool}
 */
function persist_import_shop_target(string $slug, ?string $subdomain): array
{
    $target = resolve_import_shop_target($slug, $subdomain);
    $config = load_project_config($slug) ?? ['slug' => $slug];
    $config['shop'] = ['channel' => $target['channel'], 'subdomain' => $target['subdomain']];
    write_project_config($slug, $config);

    return $target;
}

function import_channel_code(string $slug): string
{
    return import_shop_target($slug)['channel'];
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

function import_channel_reset_cli(string $projectSlug, ?string $channelCode = null, bool $keepChannel = false): string
{
    return \sprintf(
        'php bin/console sylius:import:channel:reset %s --prefix=%s --shop-email=%s%s -n',
        $channelCode ?? channel_code_from_slug($projectSlug),
        import_code_prefix($projectSlug),
        import_shop_user_email($projectSlug),
        $keepChannel ? ' --keep-channel' : '',
    );
}

function import_shop_reset_cli(string $projectSlug): string
{
    $target = import_shop_target($projectSlug);

    return import_channel_reset_cli($projectSlug, $target['channel'], $target['shared']);
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
