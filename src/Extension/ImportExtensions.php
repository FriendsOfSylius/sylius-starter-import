<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Extension;

final class ImportExtensions
{
    /** @var array<string, ImportExtensionInterface> */
    private static array $extensions = [];

    public static function register(string $name, ImportExtensionInterface $extension): void
    {
        self::$extensions[$name] = $extension;
    }

    public static function unregister(string $name): void
    {
        unset(self::$extensions[$name]);
    }

    /**
     * @return array<string, ImportExtensionInterface>
     */
    public static function all(): array
    {
        return self::$extensions;
    }
}
