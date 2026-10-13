<?php

declare(strict_types=1);

namespace Sylius\Starter\Import\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sylius\Starter\Core\App;
use Sylius\Starter\Import\ImportContext;

use function Sylius\Starter\Import\import_list_shop_hostname;
use function Sylius\Starter\Import\resolve_import_shop_subdomain;
use function Sylius\Starter\Import\shop_hostname;

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
