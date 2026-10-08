<?php

declare(strict_types=1);

namespace Unit\Import;

use PHPUnit\Framework\TestCase;

use function Castor\Sylius\Import\import_media_fixture_path;

final class ImportMediaFixturePathTest extends TestCase
{
    public function testFixturePathIsTheContainerImportVarMount(): void
    {
        static::assertSame(
            '/import-var/tracteurs-and-co/media/tracteur_mas_850.webp',
            import_media_fixture_path('tracteurs-and-co', 'tracteur_mas_850.webp'),
        );
        static::assertSame(
            '/import-var/cocorico/media/taxon_boxers.webp',
            import_media_fixture_path('cocorico', 'taxon_boxers.webp'),
        );
    }
}
