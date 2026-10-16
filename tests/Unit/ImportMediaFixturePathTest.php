<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SyliusStarter\Core\App;
use SyliusStarter\Import\ImportContext;

use function SyliusStarter\Import\import_media_fixture_path;
use function SyliusStarter\Import\import_var_dir_from_app;

final class ImportMediaFixturePathTest extends TestCase
{
    public function testFixturePathIsResolvedFromTheSyliusKernel(): void
    {
        ImportContext::setCurrent(new ImportContext(new App('app', 'app'), 'app'));

        static::assertSame(
            '%kernel.project_dir%/../.castor/import/var/tracteurs-and-co/media/tracteur_mas_850.webp',
            import_media_fixture_path('tracteurs-and-co', 'tracteur_mas_850.webp'),
        );
        static::assertSame(
            '%kernel.project_dir%/../.castor/import/var/cocorico/media/taxon_boxers.webp',
            import_media_fixture_path('cocorico', 'taxon_boxers.webp'),
        );
    }

    public function testImportVarDirIsRelativeToTheApplicationDirectory(): void
    {
        static::assertSame('../.castor/import/var', import_var_dir_from_app('app'));
        static::assertSame('../../.castor/import/var', import_var_dir_from_app('apps/shop'));
        static::assertSame('.castor/import/var', import_var_dir_from_app('.'));
        static::assertSame('../.castor/import/var', import_var_dir_from_app('/srv/project/app', '/srv/project'));
    }
}
