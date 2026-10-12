<?php

use PHPUnit\Framework\TestCase;

/** When to trust the phone's time for a clock-in/out sent after the fact (2026-10-12). */
class OfflineTimeTest extends TestCase
{
    private string $tz;

    protected function setUp(): void { $this->tz = date_default_timezone_get(); date_default_timezone_set('America/Vancouver'); }
    protected function tearDown(): void { date_default_timezone_set($this->tz); }

    public function test_no_phone_time_uses_the_server_time(): void
    {
        $r = OfflineTime::accept(null, '2026-10-12 07:46:00');
        $this->assertSame(['2026-10-12 07:46:00', false, null], [$r['at'], $r['from_phone'], $r['flag']]);
    }

    public function test_a_clock_in_tapped_offline_is_paid_from_when_it_was_tapped(): void
    {
        $r = OfflineTime::accept('2026-10-12T07:30:05-07:00', '2026-10-12 07:46:00');
        $this->assertSame('2026-10-12 07:30:05', $r['at']);
        $this->assertTrue($r['from_phone']);
        $this->assertSame('sent late', $r['flag']);
        $this->assertStringContainsString('15 min later', $r['note']);
    }

    public function test_a_normal_online_tap_is_not_flagged(): void
    {
        $r = OfflineTime::accept('2026-10-12T07:45:58-07:00', '2026-10-12 07:46:00');
        $this->assertTrue($r['from_phone']);
        $this->assertNull($r['flag']);
        $this->assertNull($r['note']);
    }

    public function test_a_phone_clock_set_ahead_is_not_trusted(): void
    {
        $r = OfflineTime::accept('2026-10-12T09:00:00-07:00', '2026-10-12 07:46:00');
        $this->assertSame('2026-10-12 07:46:00', $r['at']);
        $this->assertSame('phone clock ahead', $r['flag']);
    }

    public function test_a_time_from_days_ago_is_not_trusted(): void
    {
        $r = OfflineTime::accept('2026-10-10T07:30:00-07:00', '2026-10-12 07:46:00');
        $this->assertFalse($r['from_phone']);
        $this->assertSame('too old to trust', $r['flag']);
    }

    public function test_a_clock_out_before_its_clock_in_is_not_trusted(): void
    {
        $r = OfflineTime::accept('2026-10-12T06:00:00-07:00', '2026-10-12 15:00:00', '2026-10-12 07:30:00');
        $this->assertFalse($r['from_phone']);
        $this->assertSame('earlier than clock-in', $r['flag']);
    }

    public function test_another_zone_is_converted(): void
    {
        $r = OfflineTime::accept('2026-10-12T14:30:00Z', '2026-10-12 07:46:00');   // 07:30 Pacific
        $this->assertSame('2026-10-12 07:30:00', $r['at']);
    }
}
