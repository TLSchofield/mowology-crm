<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Core/Tenant.php';
require_once dirname(__DIR__, 3) . '/app/Core/Uploads.php';

/** Where a business's uploaded files live (Ittai founding businesses, 2026-10-23). */
class UploadsTest extends TestCase
{
    protected function tearDown(): void
    {
        Tenant::forTests(null);
    }

    public function test_default_business_keeps_todays_paths(): void
    {
        Tenant::forTests(null);
        $this->assertSame('/uploads/photos', uploadsUrl('photos'));
        $this->assertSame('/uploads', uploadsUrl(''));
        $this->assertSame('/uploads/receipts/thumbs', uploadsUrl('receipts/thumbs'));

        $root = defined('PUBLIC_ROOT') ? rtrim(PUBLIC_ROOT, '/') : dirname(__DIR__, 3) . '/public';
        $area = 'zz-uploads-test-' . getmypid();
        $dir = uploadsDir($area);                       // created if missing; removed again below
        $this->assertSame($root . '/uploads/' . $area, $dir);
        $this->assertDirectoryExists($dir);
        @rmdir($dir);
    }

    public function test_other_business_gets_its_own_folder(): void
    {
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => ['hosts' => ['greenleaf.ittai.ca'], 'db_name' => 'x']]);
        $this->assertSame('/uploads/t/greenleaf/photos', uploadsUrl('photos'));
        $this->assertSame('/uploads/t/greenleaf', uploadsUrl(''));
        $this->assertSame('uploads/t/greenleaf', uploadsBase());
    }

    public function test_area_cannot_climb_out(): void
    {
        $this->expectException(InvalidArgumentException::class);
        uploadsDir('../x');
    }

    public function test_htaccess_serves_only_own_hosts_and_no_scripts(): void
    {
        $h = Uploads::htaccess(['greenleaf.ittai.ca']);
        $this->assertStringContainsString('RewriteCond %{HTTP_HOST} !^greenleaf\.ittai\.ca(:\d+)?$ [NC]', $h);
        $this->assertStringContainsString('Options -Indexes', $h);
        $this->assertStringContainsString('php', $h);
        $this->assertStringContainsString('Require all denied', $h);
    }
}
