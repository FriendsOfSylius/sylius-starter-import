<?php

declare(strict_types=1);

namespace SyliusStarter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SyliusStarter\Core\App;
use SyliusStarter\Import\ImportContext;

use function SyliusStarter\Import\import_list_shop_hostname;
use function SyliusStarter\Import\resolve_import_shop_subdomain;
use function SyliusStarter\Import\shop_hostname;

final class ImportShopSubdomainTest extends TestCase
{
    public function testDefaultsToProjectSlugWhenSubdomainOptionIsOmitted(): void
    {
        static::assertSame('cocorico', resolve_import_shop_subdomain(null, 'cocorico'));
    }

    public function testEmptyStringUsesApexDomain(): void
    {
        static::assertNull(resolve_import_shop_subdomain('', 'cocorico'));
        static::assertSame('sylius-starter-sk.test', shop_hostname('sylius-starter-sk.test', null));
    }

    public function testExplicitSubdomainIsTrimmed(): void
    {
        static::assertSame('demo', resolve_import_shop_subdomain('  demo  ', 'cocorico'));
    }

    public function testImportListShopHostnameUsesAppDomainAndSlug(): void
    {
        $previous = ImportContext::tryCurrent();
        ImportContext::setCurrent(new ImportContext(new App('app', 'app', 'sylius-starter-sk.test'), 'app'));

        try {
            static::assertSame(
                'cocorico.sylius-starter-sk.test',
                import_list_shop_hostname('cocorico'),
            );
        } finally {
            if (null !== $previous) {
                ImportContext::setCurrent($previous);
            }
        }
    }
}
