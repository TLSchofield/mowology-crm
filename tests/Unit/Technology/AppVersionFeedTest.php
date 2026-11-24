<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AppVersionFeedTest extends TestCase
{
    private array $base = [
        'android' => ['version' => '1.8.0', 'native_signup' => false, 'native_receipts' => true],
        'ios' => ['version' => '1.0.0'],
    ];

    public function testOriginalBusinessNeverReadsAnOverride(): void
    {
        $this->assertNull(AppVersionFeed::overrideFile('/x', 'mowology', true));
    }

    public function testOverrideFileIsNamedAfterTheBusinessAndRejectsUnsafeKeys(): void
    {
        $this->assertSame('/x/app-version.testcompany.json', AppVersionFeed::overrideFile('/x/', 'testcompany', false));
        $this->assertNull(AppVersionFeed::overrideFile('/x', '../etc', false));
        $this->assertNull(AppVersionFeed::overrideFile('/x', 'Up', false));
    }

    public function testMergeReplacesOnlyTheKeysTheOverrideNames(): void
    {
        $out = AppVersionFeed::merge($this->base, ['android' => ['native_signup' => true, 'native_auth' => true], 'web' => ['x' => 1]]);
        $this->assertTrue($out['android']['native_signup']);
        $this->assertTrue($out['android']['native_auth']);
        $this->assertTrue($out['android']['native_receipts']);
        $this->assertSame('1.8.0', $out['android']['version']);
        $this->assertSame(['version' => '1.0.0'], $out['ios']);
        $this->assertArrayNotHasKey('web', $out);
    }

    public function testForTenantReadsTheFileAndFallsBackToTheBase(): void
    {
        $dir = sys_get_temp_dir() . '/avf-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->assertSame($this->base, AppVersionFeed::forTenant($this->base, $dir, 'testcompany', false));
            file_put_contents($dir . '/app-version.testcompany.json', '{"android":{"native_signup":true}}');
            $this->assertTrue(AppVersionFeed::forTenant($this->base, $dir, 'testcompany', false)['android']['native_signup']);
            $this->assertFalse(AppVersionFeed::forTenant($this->base, $dir, 'testcompany', true)['android']['native_signup']);
            file_put_contents($dir . '/app-version.testcompany.json', '{not json');
            $this->assertSame($this->base, AppVersionFeed::forTenant($this->base, $dir, 'testcompany', false));
        } finally {
            @unlink($dir . '/app-version.testcompany.json');
            @rmdir($dir);
        }
    }
}
