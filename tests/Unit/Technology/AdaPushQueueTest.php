<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Technology/Services/AdaRules.php';

/**
 * Security audit 2026-10-12 #23: Ada showed Apple push green while the send queue was dead,
 * because pending rows were ignored and a 'quiet' push check promoted push_drain to green.
 */
final class AdaPushQueueTest extends TestCase
{
    private const NOW = '2026-10-12 15:00:00';

    public function test_pending_rows_older_than_30_minutes_are_red(): void
    {
        $c = AdaRules::pushCheck(0, 0, '2026-10-10 09:00:00', null, self::NOW, 4, '2026-10-12 13:00:00');
        $this->assertSame('red', $c['state']);
        $this->assertSame('stuck', $c['problem']);
        $this->assertStringContainsString('4 notifications waiting', $c['evidence']);
        $this->assertSame('developer', $c['handoff_to']);
    }

    public function test_stuck_queue_is_red_even_when_recent_sends_look_fine(): void
    {
        $this->assertSame('red', AdaRules::pushCheck(50, 0, '2026-10-12 14:00:00', null, self::NOW, 1, '2026-10-12 14:00:00')['state']);
    }

    public function test_no_stuck_rows_keeps_the_old_verdicts(): void
    {
        $this->assertSame('quiet', AdaRules::pushCheck(0, 0, null, null, self::NOW)['problem']);
        $this->assertSame('ok', AdaRules::pushCheck(12, 0, '2026-10-12 14:00:00', null, self::NOW, 0)['problem']);
    }

    private static function drainUnknown(): array
    {
        return ['system' => 'cron:push_drain', 'group' => 'Scheduled jobs', 'name' => 'Push drain',
                'state' => 'unknown', 'problem' => 'no-record', 'last_worked' => null, 'evidence' => 'x',
                'handoff' => null, 'handoff_to' => null];
    }

    public function test_a_quiet_proxy_does_not_promote_the_drain_to_green(): void
    {
        $out = AdaRules::applyProxies([self::drainUnknown(), AdaRules::pushCheck(0, 0, null, null, self::NOW)]);
        $this->assertSame('unknown', $out[0]['state']);
    }

    public function test_a_proxy_that_did_real_work_still_promotes(): void
    {
        $out = AdaRules::applyProxies([self::drainUnknown(), AdaRules::pushCheck(12, 0, '2026-10-12 14:00:00', null, self::NOW)]);
        $this->assertSame('green', $out[0]['state']);
        $this->assertSame('ok-by-proxy', $out[0]['problem']);
    }
}
