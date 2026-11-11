<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The client unticks lines before signing — QUO-2026-0073 without the sprinkler line. */
class QuoteLineChoiceServiceTest extends TestCase
{
    private function monica(): array
    {
        return [
            ['id' => 1, 'service_type' => 'Shrub bed renovation', 'line_total' => '600.00'],
            ['id' => 2, 'service_type' => 'Mulch', 'line_total' => '325.00'],
            ['id' => 3, 'service_type' => 'Hedge trim', 'line_total' => '200.00'],
            ['id' => 4, 'service_type' => 'Sprinkler repair', 'line_total' => '550.00'],
        ];
    }

    public function test_declining_the_sprinkler_line_gives_councils_total(): void
    {
        $lines = $this->monica();
        $lines[3]['client_declined'] = 1;
        $t = QuoteLineChoiceService::totals($lines);
        $this->assertSame(1125.0, $t['subtotal']);
        $this->assertSame(56.25, $t['tax_amount']);
        $this->assertSame(1181.25, $t['total']);
    }

    public function test_optional_and_declined_lines_stay_out_of_the_total(): void
    {
        $t = QuoteLineChoiceService::totals([
            ['line_total' => 100], ['line_total' => 50, 'is_optional' => 1], ['line_total' => 30, 'client_declined' => 1],
        ]);
        $this->assertSame(100.0, $t['subtotal']);
    }

    public function test_never_on_contracts_or_signed_quotes(): void
    {
        $this->assertFalse(QuoteLineChoiceService::allowed(['is_contract' => 1, 'status' => 'sent'], '1'));
        $this->assertFalse(QuoteLineChoiceService::allowed(['is_contract' => 0, 'status' => 'accepted'], '1'));
        $this->assertTrue(QuoteLineChoiceService::allowed(['is_contract' => 0, 'status' => 'sent'], '1'));
    }

    public function test_the_quote_setting_beats_the_default(): void
    {
        $this->assertFalse(QuoteLineChoiceService::allowed(['status' => 'sent', 'allow_line_decline' => 0], '1'));
        $this->assertTrue(QuoteLineChoiceService::allowed(['status' => 'sent', 'allow_line_decline' => 1], '0'));
        $this->assertFalse(QuoteLineChoiceService::allowed(['status' => 'sent', 'allow_line_decline' => null], '0'));
    }

    public function test_locked_upsell_and_optional_lines_cannot_be_unticked(): void
    {
        $this->assertFalse(QuoteLineChoiceService::canDecline(['line_total' => 10, 'client_locked' => 1]));
        $this->assertFalse(QuoteLineChoiceService::canDecline(['line_total' => 10, 'is_upsell' => 1]));
        $this->assertFalse(QuoteLineChoiceService::canDecline(['line_total' => 10, 'is_optional' => 1]));
        $this->assertFalse(QuoteLineChoiceService::canDecline(['line_total' => 0]));
        $this->assertTrue(QuoteLineChoiceService::canDecline(['line_total' => 10]));
    }

    public function test_at_least_one_line_stays_ticked(): void
    {
        $lines = $this->monica();
        foreach ([0, 1, 2] as $i) $lines[$i]['client_declined'] = 1;
        $this->assertNotNull(QuoteLineChoiceService::refusal($lines, 4, true));
        $this->assertNull(QuoteLineChoiceService::refusal($lines, 1, false));   // ticking back on is fine
        $this->assertNull(QuoteLineChoiceService::refusal($this->monica(), 4, true));
        $this->assertNotNull(QuoteLineChoiceService::refusal($this->monica(), 99, true));
    }
}
