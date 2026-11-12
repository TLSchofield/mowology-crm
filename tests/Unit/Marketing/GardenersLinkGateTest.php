<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/public/ittai.ca/g/gate.php';

/** ittai.ca/g/<code>: a private page reached only by its link. Codes here are invented. */
class GardenersLinkGateTest extends TestCase
{
    private const CODE = 'abcdef0123456789abcdef0123456789abcdef0123456789';   // 48 hex
    private int $now;
    private array $links;

    protected function setUp(): void
    {
        $this->now = strtotime('2026-11-20 12:00:00 UTC');
        $this->links = [hash('sha256', self::CODE) => ['label' => 'test', 'expires' => '2027-01-31']];
    }

    public function testTheRightCodeOpensTheLink(): void
    {
        $this->assertSame('test', ittaiLinkLookup(self::CODE, $this->links, $this->now)['label']);
    }

    public function testAWrongOrMalformedCodeOpensNothing(): void
    {
        $this->assertNull(ittaiLinkLookup(str_repeat('0', 48), $this->links, $this->now));
        $this->assertNull(ittaiLinkLookup(substr(self::CODE, 0, 47), $this->links, $this->now));
        $this->assertNull(ittaiLinkLookup(strtoupper(self::CODE), $this->links, $this->now), 'case matters');
        $this->assertNull(ittaiLinkLookup('../links', $this->links, $this->now));
        $this->assertNull(ittaiLinkLookup('', $this->links, $this->now));
    }

    public function testAnExpiredLinkOpensNothingButItsLastDayStillWorks(): void
    {
        $this->assertNotNull(ittaiLinkLookup(self::CODE, $this->links, strtotime('2027-01-31 23:00:00 UTC')));
        $this->assertNull(ittaiLinkLookup(self::CODE, $this->links, strtotime('2027-02-01 00:00:01 UTC')));
    }

    public function testARevokedLinkIsSimplyAbsentAndALinkWithNoExpiryNeverExpires(): void
    {
        $this->assertNull(ittaiLinkLookup(self::CODE, [], $this->now));
        $open = [hash('sha256', self::CODE) => ['label' => 'open']];
        $this->assertNotNull(ittaiLinkLookup(self::CODE, $open, strtotime('2040-01-01 UTC')));
    }

    public function testTheRegisterNeverHoldsTheCodeItself(): void
    {
        $live = require dirname(__DIR__, 3) . '/public/ittai.ca/g/links.php';
        foreach (array_keys($live) as $key) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$key, 'a SHA-256, not a code');
        }
    }

    public function testRepeatedMissesThrottleOneVisitorOnly(): void
    {
        $dir = sys_get_temp_dir() . '/ittai-g-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        for ($i = 0; $i < 14; $i++) $this->assertFalse(ittaiThrottled($dir, '203.0.113.9', $this->now, true));
        $this->assertTrue(ittaiThrottled($dir, '203.0.113.9', $this->now, true), 'the 15th miss throttles');
        $this->assertTrue(ittaiThrottled($dir, '203.0.113.9', $this->now + 60));
        $this->assertFalse(ittaiThrottled($dir, '203.0.113.10', $this->now), 'someone else is not affected');
        $this->assertFalse(ittaiThrottled($dir, '203.0.113.9', $this->now + 601), 'misses age out after 10 minutes');
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testOnlyTheNamedPhotosCanBeServed(): void
    {
        $this->assertContains('hedge-after.jpg', ittaiPageImages());
        $this->assertNotContains('links.php', ittaiPageImages());
        foreach (ittaiPageImages() as $f) {
            $this->assertFileExists(dirname(__DIR__, 3) . '/public/ittai.ca/g/_img/' . $f);
        }
    }
}
