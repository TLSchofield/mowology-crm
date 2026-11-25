<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tests expiry behavior for calculator endpoint throttles. */
final class ToolThrottleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/tool-throttle-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** Expired stamps no longer limit a key and its old file is replaced cleanly. */
    public function testExpiredStampsAreRemoved(): void
    {
        $throttle = new ToolThrottle($this->directory);
        $this->assertFalse($throttle->limited('key', 1, 60, 100));
        $this->assertTrue($throttle->limited('key', 1, 60, 100));
        $this->assertFalse($throttle->limited('key', 1, 60, 161));
        $file = $this->directory . '/' . hash('sha256', 'key');
        $this->assertSame('161', file_get_contents($file));
    }
}
