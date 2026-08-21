<?php

declare(strict_types=1);

namespace Castor\Sylius\Import;

use Symfony\Component\Yaml\Yaml;

use function Castor\io;

function project_yaml_path(string $slug): string
{
    ensure_castor_host_dir($slug);

    return castor_host_dir($slug) . '/project.yaml';
}

/**
 * @param array<string, mixed> $config
 */
function write_project_config(string $slug, array $config): void
{
    ensure_castor_host_dir($slug);

    $path = project_yaml_path($slug);
    $content = Yaml::dump($config, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

    if (false === file_put_contents($path, $content)) {
        throw new \RuntimeException(\sprintf('Failed to write project config to "%s".', $path));
    }
}

/**
 * @return array<string, mixed>|null
 */
function load_project_config(string $slug): ?array
{
    $path = project_yaml_path($slug);

    if (!is_file($path)) {
        return null;
    }

    ensure_castor_yaml_autoload();

    /** @var array<string, mixed>|null $data */
    $data = Yaml::parseFile($path);

    return \is_array($data) ? $data : null;
}

/**
 * @return list<string>
 */
function discover_project_configs(?string $modeFilter = null): array
{
    return discover_var_slugs(static function (string $path, string $slug) use ($modeFilter): bool {
        if (!is_file($path . '/project.yaml')) {
            return false;
        }

        if (null === $modeFilter) {
            return true;
        }

        $config = load_project_config($slug);

        return null !== $config && ($config['mode'] ?? '') === $modeFilter;
    });
}

function import_project_display_name(string $slug): string
{
    $config = load_project_config($slug);

    if (null !== $config) {
        $name = trim((string) ($config['name'] ?? ''));

        if ('' !== $name) {
            return $name;
        }
    }

    if (is_file(castor_host_dir($slug) . '/products.yaml')) {
        try {
            $products = load_import_yaml($slug);
            $name = trim((string) ($products['name'] ?? ''));

            if ('' !== $name) {
                return $name;
            }
        } catch (\Throwable) {
        }
    }

    return $slug;
}

/**
 * @param array<string, mixed>|null $projectConfig
 * @param array<string, mixed>|null $productsYaml
 */
function mode_from_import_sources(?array $projectConfig, ?array $productsYaml): ?string
{
    foreach ([$projectConfig, $productsYaml] as $data) {
        if (!\is_array($data)) {
            continue;
        }

        $mode = $data['mode'] ?? null;

        if (\is_string($mode) && '' !== trim($mode)) {
            return trim($mode);
        }
    }

    return null;
}

function import_project_mode(string $slug): ?string
{
    $mode = mode_from_import_sources(load_project_config($slug), null);

    if (null !== $mode) {
        return $mode;
    }

    if (!is_file(castor_host_dir($slug) . '/products.yaml')) {
        return null;
    }

    return mode_from_import_sources(null, load_import_yaml($slug));
}

/**
 * @param array<string, mixed>|null $existing
 *
 * @return array<string, mixed>
 */
function merge_project_passwords(array $config, ?array $existing): array
{
    foreach (['admin_password', 'shop_password'] as $key) {
        $previous = trim((string) ($existing[$key] ?? ''));

        if ('' !== $previous) {
            $config[$key] = $previous;

            continue;
        }

        $config[$key] = generate_import_password();
    }

    return $config;
}

function import_admin_user_password(string $slug): string
{
    $config = load_project_config($slug);
    $password = trim((string) ($config['admin_password'] ?? ''));

    if ('' === $password) {
        throw new \RuntimeException(\sprintf('Missing admin_password in project.yaml for "%s".', $slug));
    }

    return $password;
}

function import_shop_user_password(string $slug): string
{
    $config = load_project_config($slug);
    $password = trim((string) ($config['shop_password'] ?? ''));

    if ('' === $password) {
        throw new \RuntimeException(\sprintf('Missing shop_password in project.yaml for "%s".', $slug));
    }

    return $password;
}

/**
 * @param array{name?: string, description?: string, url?: string, source?: string, mode: string} $metadata
 */
function persist_project_config(string $slug, array $metadata): void
{
    $existing = load_project_config($slug);

    write_project_config($slug, merge_project_passwords([
        'slug' => $slug,
        'name' => trim((string) ($metadata['name'] ?? '')),
        'description' => trim((string) ($metadata['description'] ?? '')),
        'url' => trim((string) ($metadata['url'] ?? $metadata['source'] ?? '')),
        'mode' => (string) $metadata['mode'],
        'shop_images' => shop_images_presence($slug),
    ], $existing));
}

function import_project_choice_label(string $slug): string
{
    $name = import_project_display_name($slug);

    if ($name === $slug) {
        return $slug;
    }

    return \sprintf('%s (%s)', $name, $slug);
}

/**
 * @param list<string> $slugs
 */
function select_import_slug(string $prompt, array $slugs): ?string
{
    if ([] === $slugs) {
        return null;
    }

    if (1 === \count($slugs)) {
        import_log(\sprintf('Using project: %s.', import_project_choice_label($slugs[0])));

        return $slugs[0];
    }

    $choices = [];

    foreach ($slugs as $slug) {
        $choices[import_project_choice_label($slug)] = $slug;
    }

    return io()->choice($prompt, $choices);
}

function select_import_project_slug(string $prompt, ?string $modeFilter = null): ?string
{
    return select_import_slug($prompt, discover_project_configs($modeFilter));
}

/**
 * @return array{name: string, description: string, url: ?string, slug: string}
 */
function resolve_import_project(
    string $expectedMode,
    ?string $project = null,
    ?string $name = null,
    ?string $description = null,
    ?string $url = null,
): array {
    $projectSlug = null !== $project ? trim($project) : '';

    if ('' === $projectSlug) {
        return resolve_project_inputs($expectedMode, $name, $description, $url);
    }

    $config = load_project_config($projectSlug);

    if (null === $config) {
        throw new \RuntimeException(\sprintf('Project "%s" not found.', $projectSlug));
    }

    assert_project_config_mode($config, $expectedMode);

    $resolvedName = trim((string) ($name ?? ''));

    if ('' === $resolvedName) {
        $resolvedName = trim((string) ($config['name'] ?? $projectSlug));
    }

    if ('' === $resolvedName) {
        throw new \RuntimeException('Project name is required.');
    }

    $resolvedDescription = trim((string) ($description ?? ''));

    if ('' === $resolvedDescription) {
        $resolvedDescription = trim((string) ($config['description'] ?? ''));
    }

    $resolvedUrl = null !== $url ? trim($url) : null;

    if ((null === $resolvedUrl || '' === $resolvedUrl)) {
        $configUrl = trim((string) ($config['url'] ?? ''));

        if ('' !== $configUrl) {
            $resolvedUrl = $configUrl;
        }
    }

    if ('existing' === $expectedMode && (null === $resolvedUrl || '' === $resolvedUrl)) {
        throw new \RuntimeException('Site URL is required.');
    }

    if ('ai' === $expectedMode && (null === $resolvedUrl || '' === $resolvedUrl)) {
        $resolvedUrl = null;
    }

    return [
        'name' => $resolvedName,
        'description' => $resolvedDescription,
        'url' => $resolvedUrl,
        'slug' => $projectSlug,
    ];
}

/**
 * @param array{name?: string, description?: string, url?: string} $fields
 */
function update_project_config(string $slug, array $fields): void
{
    $existing = load_project_config($slug);

    if (null === $existing) {
        throw new \RuntimeException(\sprintf('Project "%s" not found.', $slug));
    }

    write_project_config($slug, merge_project_passwords([
        'slug' => $slug,
        'name' => trim((string) ($fields['name'] ?? $existing['name'] ?? '')),
        'description' => trim((string) ($fields['description'] ?? $existing['description'] ?? '')),
        'url' => trim((string) ($fields['url'] ?? $existing['url'] ?? '')),
        'mode' => (string) ($existing['mode'] ?? ''),
        'shop_images' => shop_images_presence($slug),
    ], $existing));
}

function count_import_products(string $slug): int
{
    $path = castor_host_dir($slug) . '/products.yaml';

    if (!is_file($path)) {
        return 0;
    }

    try {
        $data = load_import_yaml($slug);
        $products = $data['products'] ?? [];

        return \is_array($products) ? \count($products) : 0;
    } catch (\Throwable) {
        return 0;
    }
}

function count_import_collections(string $slug): int
{
    $path = castor_host_dir($slug) . '/collections.yaml';

    if (!is_file($path)) {
        return 0;
    }

    try {
        ensure_castor_yaml_autoload();
        /** @var array<string, mixed>|null $data */
        $data = Yaml::parseFile($path);
        $collections = $data['collections'] ?? [];

        return \is_array($collections) ? \count($collections) : 0;
    } catch (\Throwable) {
        return 0;
    }
}

function import_yaml_status(int $productCount, bool $hasYamlFile): string
{
    if (!$hasYamlFile) {
        return 'no';
    }

    if (0 === $productCount) {
        return 'empty';
    }

    return \sprintf('yes (%d)', $productCount);
}

/**
 * @return array{name: string, description: string, url: ?string, slug: string}
 */
function resolve_project_inputs(
    string $expectedMode,
    ?string $name = null,
    ?string $description = null,
    ?string $url = null,
): array {
    $config = try_load_project_config_for_name($name, $expectedMode);

    if (null !== $config) {
        assert_project_config_mode($config, $expectedMode);
    }

    $resolvedName = trim((string) ($name ?? ''));

    if ('' === $resolvedName && null !== $config) {
        $resolvedName = trim((string) ($config['name'] ?? ''));
    }

    if ('' === $resolvedName) {
        throw new \RuntimeException('Project name is required.');
    }

    $resolvedDescription = trim((string) ($description ?? ''));

    if ('' === $resolvedDescription && null !== $config) {
        $resolvedDescription = trim((string) ($config['description'] ?? ''));
    }

    $resolvedUrl = null !== $url ? trim($url) : null;

    if ((null === $resolvedUrl || '' === $resolvedUrl) && null !== $config) {
        $configUrl = trim((string) ($config['url'] ?? ''));

        if ('' !== $configUrl) {
            $resolvedUrl = $configUrl;
        }
    }

    if ('existing' === $expectedMode && (null === $resolvedUrl || '' === $resolvedUrl)) {
        throw new \RuntimeException('Site URL is required.');
    }

    if ('ai' === $expectedMode && (null === $resolvedUrl || '' === $resolvedUrl)) {
        $resolvedUrl = null;
    }

    $slug = normalize_import_name($resolvedName);

    return [
        'name' => $resolvedName,
        'description' => $resolvedDescription,
        'url' => $resolvedUrl,
        'slug' => $slug,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function try_load_project_config_for_name(?string $name, string $expectedMode): ?array
{
    if (null === $name || '' === trim($name)) {
        return null;
    }

    try {
        $slug = normalize_import_name($name);
    } catch (\InvalidArgumentException) {
        return null;
    }

    $config = load_project_config($slug);

    if (null === $config || ($config['mode'] ?? '') !== $expectedMode) {
        return null;
    }

    return $config;
}

/**
 * @param array<string, mixed> $config
 */
function assert_project_config_mode(array $config, string $expectedMode): void
{
    $mode = (string) ($config['mode'] ?? '');
    $label = trim((string) ($config['name'] ?? $config['slug'] ?? 'project'));

    if ($mode === $expectedMode) {
        return;
    }

    if ('existing' === $expectedMode) {
        throw new \RuntimeException(\sprintf(
            'Project "%s" is configured for AI import. Run sylius:import:ai:build instead.',
            $label,
        ));
    }

    throw new \RuntimeException(\sprintf(
        'Project "%s" is configured for existing site import. Run sylius:import:existing:fetch instead.',
        $label,
    ));
}

function select_import_host_slug(string $prompt): ?string
{
    return select_import_slug($prompt, discover_import_hosts());
}

/**
 * @return list<array{
 *     slug: string,
 *     name: string,
 *     mode: string|null,
 *     hasYaml: bool,
 *     hasProducts: bool,
 *     productCount: int,
 *     collectionCount: int,
 *     hasFixtures: bool,
 *     channelCode: string,
 *     shopUrl: string,
 *     adminUrl: string,
 *     adminPassword: string|null,
 *     shopPassword: string|null,
 *     shopUserEmail: string,
 *     url: string|null,
 *     description: string|null,
 *     importedAt: string|null,
 *     shopImages: array{logo: bool, header: bool, interstice: bool}|null,
 * }>
 */
function list_import_shops(): array
{
    $shops = [];

    foreach (discover_import_hosts() as $slug) {
        $config = load_project_config($slug);
        $hostname = shop_hostname($slug);
        $hasYamlFile = is_file(castor_host_dir($slug) . '/products.yaml');
        $productCount = count_import_products($slug);
        $collectionCount = count_import_collections($slug);
        $shopImages = $config['shop_images'] ?? null;

        if (!\is_array($shopImages)) {
            $shopImages = shop_images_presence($slug);
        }

        $shops[] = [
            'slug' => $slug,
            'name' => import_project_display_name($slug),
            'mode' => import_project_mode($slug),
            'hasYaml' => $hasYamlFile,
            'hasProducts' => $productCount > 0,
            'productCount' => $productCount,
            'collectionCount' => $collectionCount,
            'hasFixtures' => project_has_generated_fixtures($slug),
            'channelCode' => channel_code_from_slug($slug),
            'shopUrl' => 'https://' . $hostname,
            'adminUrl' => 'https://' . $hostname . '/admin',
            'adminPassword' => isset($config['admin_password']) ? (string) $config['admin_password'] : null,
            'shopPassword' => isset($config['shop_password']) ? (string) $config['shop_password'] : null,
            'shopUserEmail' => import_shop_user_email($slug),
            'url' => isset($config['url']) ? (string) $config['url'] : null,
            'description' => isset($config['description']) ? (string) $config['description'] : null,
            'importedAt' => import_project_imported_at($slug),
            'shopImages' => [
                'logo' => (bool) ($shopImages['logo'] ?? false),
                'header' => (bool) ($shopImages['header'] ?? false),
                'interstice' => (bool) ($shopImages['interstice'] ?? false),
            ],
        ];
    }

    return $shops;
}

function import_project_imported_at(string $slug): ?string
{
    if (!is_file(castor_host_dir($slug) . '/products.yaml')) {
        return null;
    }

    try {
        $importedAt = trim((string) (load_import_yaml($slug)['imported_at'] ?? ''));

        return '' !== $importedAt ? $importedAt : null;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * JSON on non-TTY (API, pipes). A table when a human is at a terminal.
 *
 * @param list<array{
 *     slug: string,
 *     name: string,
 *     mode: string|null,
 *     hasYaml: bool,
 *     hasProducts: bool,
 *     productCount: int,
 *     collectionCount: int,
 *     hasFixtures: bool,
 *     channelCode: string,
 *     shopUrl: string,
 *     adminUrl: string,
 *     adminPassword: string|null,
 *     shopPassword: string|null,
 *     shopUserEmail: string,
 *     url: string|null,
 *     description: string|null,
 *     importedAt: string|null,
 *     shopImages: array{logo: bool, header: bool, interstice: bool}|null,
 * }> $shops
 */
function write_import_shop_list(array $shops, ?bool $tty = null): void
{
    $tty ??= stream_isatty(\STDOUT);

    if (!$tty) {
        io()->writeln(json_encode($shops, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return;
    }

    if ([] === $shops) {
        io()->writeln('No import shops found.');

        return;
    }

    $table = import_shop_list_table($shops);
    io()->table($table['headers'], $table['rows']);
}

/**
 * @param list<array{
 *     slug: string,
 *     name: string,
 *     mode: string|null,
 *     hasYaml: bool,
 *     hasProducts: bool,
 *     productCount: int,
 *     collectionCount: int,
 *     hasFixtures: bool,
 *     channelCode: string,
 *     shopUrl: string,
 *     adminUrl: string,
 *     adminPassword: string|null,
 *     shopPassword: string|null,
 *     shopUserEmail: string,
 *     url: string|null,
 *     description: string|null,
 *     importedAt: string|null,
 *     shopImages: array{logo: bool, header: bool, interstice: bool}|null,
 * }> $shops
 *
 * @return array{headers: list<string>, rows: list<list<string>>}
 */
function import_shop_list_table(array $shops): array
{
    $rows = [];

    foreach ($shops as $shop) {
        $rows[] = [
            $shop['name'],
            $shop['slug'],
            $shop['mode'] ?? '—',
            import_yaml_status($shop['productCount'] ?? 0, $shop['hasYaml'] ?? false),
            (string) ($shop['productCount'] ?? 0),
            $shop['hasFixtures'] ? 'yes' : 'no',
            $shop['channelCode'],
        ];
    }

    return [
        'headers' => ['Name', 'Slug', 'Mode', 'YAML', 'Products', 'Fixtures', 'Channel'],
        'rows' => $rows,
    ];
}
