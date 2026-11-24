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
        $this->assertStringContainsString('<If "%{HTTP_HOST} !~ /^(greenleaf\.ittai\.ca)(:[0-9]+)?$/i">', $h, 'core <If>, no mod_rewrite needed (Codex 2026-10-23)');
        $this->assertStringNotContainsString('IfModule', $h, 'the host rule must not depend on a module being loaded');
        $this->assertStringContainsString('Options -Indexes', $h);
        $this->assertStringContainsString('<FilesMatch "(?i)\\.(ph(p', $h, 'scripts denied');
        $this->assertStringContainsString('Require all denied', $h);
    }

    /** Codex 2026-10-23: a stored path is only trusted when it really resolves inside this business's area. */
    public function test_uploads_inside_checks_the_real_folder_boundary(): void
    {
        $dir = uploadsDir('cms');
        $ok = $dir . '/ut-' . bin2hex(random_bytes(3)) . '.jpg';
        $sib = dirname($dir) . '/cms-x-' . bin2hex(random_bytes(3));
        @mkdir($sib); file_put_contents($ok, 'x'); file_put_contents($sib . '/f.jpg', 'x');
        try {
            $this->assertTrue(uploadsInside($ok, 'cms'));
            $this->assertFalse(uploadsInside($sib . '/f.jpg', 'cms'), 'cms-x/ is not cms/');
            $this->assertFalse(uploadsInside($dir . '/../cms-x-nope/f.jpg', 'cms'));
            $this->assertFalse(uploadsInside('/etc/passwd', 'cms'));
        } finally {
            @unlink($ok); @unlink($sib . '/f.jpg'); @rmdir($sib);
        }
    }

    public function test_a_business_receipts_folder_is_never_served_directly(): void
    {
        $this->assertStringContainsString('Require all denied', Uploads::DENY_ALL);
    }
}

