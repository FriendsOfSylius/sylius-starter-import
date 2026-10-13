<?php

declare(strict_types=1);

namespace Sylius\Starter\Import\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Starter\Import\Dto\AiCatalogExtraction;
use Sylius\Starter\Import\Dto\AiProductEntry;
use Sylius\Starter\Import\Dto\CollectionEntry;
use Sylius\Starter\Import\Dto\CollectionExtraction;
use Sylius\Starter\Import\Dto\ProductSelectionEntry;
use Sylius\Starter\Import\Dto\ProductSelectionExtraction;
use Sylius\Starter\Import\Dto\ProductTaxonAssignmentEntry;
use Sylius\Starter\Import\Dto\ProductTaxonAssignmentExtraction;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;

final class AiCatalogDtoTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    public static function dtoClassProvider(): array
    {
        return [
            [CollectionEntry::class],
            [CollectionExtraction::class],
            [AiProductEntry::class],
            [AiCatalogExtraction::class],
            [ProductSelectionEntry::class],
            [ProductSelectionExtraction::class],
            [ProductTaxonAssignmentEntry::class],
            [ProductTaxonAssignmentExtraction::class],
        ];
    }

    /**
     * Nested AI DTOs must live in their own PSR-4 files. Otherwise Symfony
     * type-info cannot resolve phpdoc like CollectionEntry[] when building
     * the structured-output schema from a sibling class.
     *
     * @param class-string $class
     */
    #[DataProvider('dtoClassProvider')]
    public function testDtoClassIsAutoloadableFromItsOwnFile(string $class): void
    {
        $relative = str_replace('Sylius\\Starter\\Import\\', '', $class);
        $expectedFile = \dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';

        static::assertFileExists($expectedFile);
        static::assertTrue(class_exists($class));
    }

    /**
     * @return list<class-string>
     */
    public static function structuredOutputClassProvider(): array
    {
        return [
            [AiCatalogExtraction::class],
            [CollectionExtraction::class],
            [ProductSelectionExtraction::class],
            [ProductTaxonAssignmentExtraction::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('structuredOutputClassProvider')]
    public function testStructuredOutputSchemaCanBeBuilt(string $class): void
    {
        if (!class_exists(ResponseFormatFactory::class)) {
            static::markTestSkipped('symfony/ai-platform is not installed in this vendor.');
        }

        $format = (new ResponseFormatFactory())->create($class);

        static::assertSame('json_schema', $format['type']);
        static::assertIsArray($format['json_schema']['schema']);
    }
}
