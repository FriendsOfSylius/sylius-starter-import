<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Dataset;

/**
 * Sources a dataset can be built from (sylius:import:dataset:build --source=…). The import package registers "ai";
 * a project registers the others, e.g. "existing" with the fetch of an existing site.
 *
 * A builder writes products.yaml and collections.yaml of the project slug it receives, like the shop import tasks:
 * it runs in an import context whose var dir is the datasets one.
 */
final class DatasetBuilders
{
    /** @var array<string, \Closure(string, string, string, ?string, int): void> */
    private static array $builders = [];

    /**
     * @param callable(string $slug, string $name, string $description, ?string $url, int $limit): void $builder
     */
    public static function register(string $source, callable $builder): void
    {
        self::$builders[$source] = $builder(...);
    }

    public static function unregister(string $source): void
    {
        unset(self::$builders[$source]);
    }

    /**
     * @return \Closure(string, string, string, ?string, int): void
     */
    public static function get(string $source): \Closure
    {
        return self::$builders[$source] ?? throw new \InvalidArgumentException(\sprintf(
            'Unknown dataset source "%s". Available sources: %s.',
            $source,
            implode(', ', self::sources()) ?: 'none',
        ));
    }

    /**
     * @return list<string>
     */
    public static function sources(): array
    {
        $sources = array_keys(self::$builders);
        sort($sources);

        return $sources;
    }
}
