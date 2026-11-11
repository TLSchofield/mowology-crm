<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Sales-plugin rules ported for Sam, 2026-11-03: signals digest, renewal radar, tiering, objection replies. */
final class SamPluginRulesTest extends TestCase
{
    private const TODAY = '2026-11-03';

    public function testDigestFiresOnlyOnCrossingsAndEmptyIsSuccess(): void
    {
        $d = SamSignals::digest([['kind' => 'nudge', 'name' => 'A', 'label' => 'q']], [['name' => 'Bo', 'days' => 2]], [['name' => 'Cy', 'bucket' => 'qualified', 'age_days' => 3]], [], [['quote_number' => 'Q1', 'name' => 'Di', 'amount' => 500, 'days_left' => 20]]);
        $this->assertSame([[], []], [$d['today'], $d['week']]);
        $this->assertSame('Nothing crossed a threshold today — 4 things swept, all inside their normal wait.', $d['line']);
        $d = SamSignals::digest([['kind' => 'replied', 'name' => 'Ann', 'label' => 'QUO-1']], [['name' => 'Bo', 'days' => 3]], [['name' => 'Cy', 'bucket' => 'hot', 'next' => 'call'], ['name' => 'Ed', 'bucket' => 'qualified', 'age_days' => 8]],
            [['name' => 'Fay', 'state' => 'gone', 'spend_12m' => 5000, 'reason' => 'r'], ['name' => 'Gus', 'state' => 'quiet', 'spend_12m' => 300, 'reason' => 'r']], [['quote_number' => 'Q2', 'name' => 'Hal', 'amount' => 900, 'days_left' => 5]]);
        $this->assertCount(4, $d['today']);   // replied, 3-day wait, hot lead, gone & worth it
        $this->assertCount(3, $d['week']);    // old qualified lead, quiet client, expiring quote
        $this->assertStringContainsString('Q2 for Hal ($900) expires in 5 days', $d['week'][2]);
    }

    public function testRenewalRadarWindowsRisksAndTimeline(): void
    {
        $rows = RenewalRadar::scan([
            ['id' => 1, 'contract_number' => 'CTR-1', 'name' => 'Big strata', 'renewal_date' => '2027-02-15', 'annual_amount' => 9000, 'last_activity' => '2026-10-30'],              // 104 d, big → in window, on track
            ['id' => 2, 'contract_number' => 'CTR-2', 'name' => 'Small lot', 'renewal_date' => '2027-02-15', 'annual_amount' => 900, 'last_activity' => '2026-10-30'],                // small: 90-day window → out
            ['id' => 3, 'contract_number' => 'CTR-3', 'name' => 'Quiet PM', 'renewal_date' => '2026-12-20', 'annual_amount' => 4000, 'last_activity' => '2026-09-01', 'open_complaint' => true],   // silent 63 d + complaint → at risk
            ['id' => 4, 'contract_number' => 'CTR-4', 'name' => 'Auto', 'renewal_date' => '2026-12-01', 'annual_amount' => 3000, 'last_activity' => null, 'auto_renew' => true],       // no contact but auto-renews → needs attention? up1 down1 → on track
        ], self::TODAY);
        $this->assertSame(['CTR-4', 'CTR-3', 'CTR-1'], array_column($rows, 'contract_number'));
        $by = array_column($rows, 'verdict', 'contract_number');
        $this->assertSame(['CTR-4' => 'on_track', 'CTR-3' => 'at_risk', 'CTR-1' => 'on_track'], $by);
        $this->assertSame(['talk' => '2026-11-17', 'terms' => '2027-01-01', 'signed' => '2027-02-01'], $rows[2]['timeline']);
        $this->assertStringContainsString('no contact for 63 days, an open complaint', $rows[1]['text']);
    }

    public function testTieringGridAndMotions(): void
    {
        $a = ClientTier::score(['recurring' => true, 'on_route' => true, 'priced_service' => true, 'open_work' => true, 'last_activity' => '2026-10-20', 'inbound_90d' => true, 'contacts' => 2], self::TODAY);
        $this->assertSame(['A', 10, 10], [$a['tier'], $a['fit'], $a['engagement']]);
        $b = ClientTier::score(['recurring' => true, 'on_route' => true, 'last_activity' => '2026-05-01'], self::TODAY);   // fit 8, engagement 1
        $this->assertSame('B', $b['tier']);
        $this->assertTrue($b['gap']);
        $this->assertStringContainsString('reactivate with a trigger', $b['motion']);
        $c = ClientTier::score(['pays_late' => true, 'open_work' => true, 'last_activity' => '2026-11-01', 'inbound_90d' => true], self::TODAY);   // fit 0, engagement 8
        $this->assertSame('C', $c['tier']);
        $this->assertSame('D', ClientTier::score([], self::TODAY)['tier']);
    }

    public function testObjectionsAreClassifiedAndRepliesKeepTheShape(): void
    {
        $this->assertSame('negotiation', ObjectionReply::classify("Can you do any better on the price? What's your best price")['type']);
        $this->assertSame('price', ObjectionReply::classify('Honestly that is too expensive for us')['type']);
        $this->assertSame('authority', ObjectionReply::classify('I need to check with my wife first')['type']);
        $this->assertSame('timing', ObjectionReply::classify('Lets hold off until next spring')['type']);
        $this->assertSame('competitor', ObjectionReply::classify('We got another quote that was lower')['type']);
        $this->assertSame('unclear', ObjectionReply::classify('ok')['type']);
        $this->assertSame('unclear', ObjectionReply::classify('Could you send a cost breakdown? After reviewing it we will decide')['type']);   // ordinary words are not objections
        $r = ObjectionReply::draft('price', ['first_name' => 'Ann', 'goal' => 'a tidy front yard before you list the house', 'proof' => 'The last three listings we prepared sold within two weeks.', 'signoff' => 'Tim']);
        $this->assertStringStartsWith("Hi Ann,\n\nThanks for being straight about the price.", $r);
        $this->assertStringContainsString('a tidy front yard before you list the house', $r);
        $this->assertStringEndsWith('?' . "\n\nTim", $r);                      // ends with a forward question
        $this->assertStringNotContainsString('%', $r);                          // no discount offered
        $this->assertLessThanOrEqual(5, preg_match_all('/[.?!](\s|$)/', explode("\n\n", $r)[1]));   // 3–5 sentences
    }
}
