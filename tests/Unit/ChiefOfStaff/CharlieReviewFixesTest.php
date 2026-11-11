<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: brief taps open the card that holds the item; urgent resends are capped. */
class CharlieReviewFixesTest extends TestCase
{
    public function test_route_is_the_card_that_holds_the_item(): void
    {
        $this->assertSame('charlie', HeadBriefReadyService::routeOf(['head' => 'house', 'kind' => 'house:overdue_invoice']));
        $this->assertSame('charlie', HeadBriefReadyService::routeOf(['head' => 'charlie', 'kind' => 'charlie:deadline_tax']));
        $this->assertSame('sam', HeadBriefReadyService::routeOf(['head' => 'sam']));
        $this->assertSame('charlie', HeadBriefReadyService::routeOf([]));
        // The label still names the owner of the work.
        $this->assertSame('penny', TeamCardService::owner(['head' => 'house', 'kind' => 'house:overdue_invoice']));
    }

    public function test_failed_alerts_retry_hourly_at_most_three_times(): void
    {
        $now = 1_760_300_000;
        $this->assertTrue(CharlieUrgentService::retryDue(CharlieUrgentService::failures(''), $now));
        $f1 = CharlieUrgentService::failures(CharlieUrgentService::failureNote(1, $now - 600, 'SMTP down'));
        $this->assertSame(['tries' => 1, 'at' => $now - 600], $f1);
        $this->assertFalse(CharlieUrgentService::retryDue($f1, $now));
        $this->assertTrue(CharlieUrgentService::retryDue(['tries' => 2, 'at' => $now - 3600], $now));
        $this->assertFalse(CharlieUrgentService::retryDue(['tries' => 3, 'at' => 0], $now));
        // An error written before this change counts as one try, long ago.
        $this->assertSame(['tries' => 1, 'at' => 0], CharlieUrgentService::failures('send failed'));
    }
}
