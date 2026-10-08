<?php

declare(strict_types=1);

namespace Unit\Import;

use Castor\Sylius\App;
use Castor\Sylius\Import\ImportContext;
use PHPUnit\Framework\TestCase;

use function Castor\Sylius\Import\build_admin_user_fixture;
use function Castor\Sylius\Import\build_shop_user_fixture;
use function Castor\Sylius\Import\generate_import_password;
use function Castor\Sylius\Import\import_admin_user_password;
use function Castor\Sylius\Import\write_project_config;

final class ImportProjectPasswordTest extends TestCase
{
    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/castor-import-password-' . uniqid('', true);
        static::assertTrue(mkdir($this->root . '/.castor', 0o775, true));
        $this->previousCwd = getcwd() ?: $this->root;
        chdir($this->root);
        ImportContext::setCurrent(new ImportContext(new App('app', $this->root), 'app'));
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->removeDirectory($this->root);
    }

    public function testGenerateImportPasswordProducesTwelveAlphanumericCharacters(): void
    {
        $password = generate_import_password();

        static::assertSame(12, \strlen($password));
        static::assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $password);
    }

    public function testBuildAdminUserFixtureUsesSlugAndYamlPassword(): void
    {
        $this->bootstrapProject('cocorico', 'admin-pass-1234', 'shop-pass-5678');

        $fixture = build_admin_user_fixture('cocorico');

        $entry = $fixture['sylius_fixtures']['suites']['import']['fixtures']['import_admin_user']['options']['custom'][0];

        static::assertSame('COCORICO', $entry['channel']);
        static::assertSame('cocorico', $entry['username']);
        static::assertSame('admin-pass-1234', $entry['password']);
        static::assertSame('cocorico@import.local', $entry['email']);
    }

    public function testBuildShopUserFixtureUsesYamlPassword(): void
    {
        $this->bootstrapProject('cocorico', 'admin-pass-1234', 'shop-pass-5678');

        $fixture = build_shop_user_fixture('cocorico');
        $entry = $fixture['sylius_fixtures']['suites']['import']['fixtures']['import_shop_user']['options']['custom'][0];

        static::assertSame('cocorico@shop.local', $entry['email']);
        static::assertSame('shop-pass-5678', $entry['password']);
    }

    public function testImportAdminUserPasswordReadsFromProjectYaml(): void
    {
        $this->bootstrapProject('doudou', 'secret-admin!', 'secret-shop!');

        static::assertSame('secret-admin!', import_admin_user_password('doudou'));
    }

    private function bootstrapProject(string $slug, string $adminPassword, string $shopPassword): void
    {
        $hostDir = $this->root . '/.castor/import/var/' . $slug;
        $media = $hostDir . '/media';
        static::assertTrue(mkdir($media, 0o775, true));
        static::assertNotFalse(file_put_contents($hostDir . '/products.yaml', 'products: []'));
        static::assertNotFalse(file_put_contents($media . '/image_logo.png', 'logo'));

        write_project_config($slug, [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'description' => 'Demo shop',
            'url' => 'https://example.com',
            'mode' => 'existing',
            'admin_password' => $adminPassword,
            'shop_password' => $shopPassword,
            'shop_images' => ['logo' => true, 'header' => false, 'interstice' => false],
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
