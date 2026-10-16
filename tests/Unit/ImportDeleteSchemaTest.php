<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function SyliusStarter\Import\import_failure_is_missing_sylius_schema;

final class ImportDeleteSchemaTest extends TestCase
{
    public function testDetectsMissingSyliusChannelTable(): void
    {
        $exception = new \RuntimeException(
            'SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "sylius_channel" does not exist',
        );

        static::assertTrue(import_failure_is_missing_sylius_schema($exception));
    }

    public function testIgnoresUnrelatedFailures(): void
    {
        $exception = new \RuntimeException('Connection refused');

        static::assertFalse(import_failure_is_missing_sylius_schema($exception));
    }
}
