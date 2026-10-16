<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\Import\import_ai_platform_classes_available;
use function SyliusStarter\Import\missing_import_ai_packages_message;

final class ImportVendorTest extends TestCase
{
    public function testMissingAiPackagesMessagePointsToCastorComposerUpdate(): void
    {
        static::assertStringContainsString(
            'castor composer update',
            missing_import_ai_packages_message(),
        );
    }

    public function testAiPlatformClassesAreDetectedWhenAutoloaded(): void
    {
        $available = import_ai_platform_classes_available();

        if (class_exists(\Symfony\AI\Platform\Bridge\Ollama\Factory::class)) {
            static::assertTrue($available);

            return;
        }

        static::assertFalse($available);
    }
}
