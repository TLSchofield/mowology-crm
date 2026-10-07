<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's plain-English reasons for a receipt's risk score (bookkeeper-mobile.php ?mode=risk).
 */
class RiskExplainerTest extends TestCase
{
    private function esso(array $over = []): array
    {
        return array_merge([
            'id' => 5, 'status' => 'approved', 'total' => '200.00', 'amount' => '190.48', 'gst_amount' => '9.52',
            'expense_date' => '2026-10-04', 'accounting_category' => 'Fuel', 'vendor_name' => 'Esso',
            'vendor_name_raw' => 'ESSO 1234', 'anomaly_flags' => 'ROUND_NUMBER', 'anomaly_score' => '20',
        ], $over);
    }

    public function test_tier_bands_match_the_app_ring(): void
    {
        $this->assertSame('none', RiskExplainer::tier(0));
        $this->assertSame('low', RiskExplainer::tier(15));
        $this->assertSame('medium', RiskExplainer::tier(16));
        $this->assertSame('medium', RiskExplainer::tier(30));
        $this->assertSame('high', RiskExplainer::tier(31));
    }

    public function test_round_number_on_fuel_is_a_fill_up(): void
    {
        $this->assertSame('A round $200 is normal for a fill-up you asked for by amount; nothing to worry about.',
            RiskExplainer::say('ROUND_NUMBER', $this->esso()));
        // a gas-station name is enough, even when the category is something else
        $this->assertStringContainsString('fill-up', RiskExplainer::say('ROUND_NUMBER', $this->esso(['accounting_category' => 'Vehicle'])));
        $this->assertStringContainsString('set price', RiskExplainer::say('ROUND_NUMBER',
            $this->esso(['accounting_category' => 'Materials', 'vendor_name' => 'Home Depot'])));
    }

    public function test_gst_mismatch_names_both_amounts(): void
    {
        $line = RiskExplainer::say('GST_MISMATCH', $this->esso(['amount' => '150.00', 'gst_amount' => '9.52']));
        $this->assertSame('GST is $9.52, but 5% of the subtotal would be $7.50. Worth a look.', $line);
        $this->assertStringContainsString('GST-exempt', RiskExplainer::say('GST_MISMATCH', $this->esso(), 'GST charged at a GST-exempt vendor'));
    }

    public function test_duplicate_day_says_same_amount_only_when_it_is(): void
    {
        $this->assertSame("There's another Esso receipt that day for the same amount.",
            RiskExplainer::say('DUPLICATE_DAY', $this->esso(), null, ['same_amount' => true]));
        $this->assertStringContainsString("isn't the same one twice", RiskExplainer::say('DUPLICATE_DAY', $this->esso()));
    }

    public function test_weekend_names_the_day(): void
    {
        $this->assertSame('Bought on a Sunday.', RiskExplainer::say('WEEKEND_EXPENSE', $this->esso(['expense_date' => '2026-10-04'])));
    }

    public function test_every_code_has_its_own_line(): void
    {
        $lines = array_map(fn($c) => RiskExplainer::say($c, $this->esso(), null), RiskExplainer::CODES);
        $this->assertCount(count(RiskExplainer::CODES), array_unique($lines));
        foreach ($lines as $l) $this->assertStringNotContainsString('Flagged:', $l);
    }

    public function test_high_amount_and_category_read_the_detail(): void
    {
        $this->assertStringContainsString('about $312.40', RiskExplainer::say('HIGH_AMOUNT', $this->esso(),
            '$700.00 is 124% above the Fuel monthly average of $312.40'));
        $this->assertSame("It's filed under Fuel, but Esso is usually Vehicle.", RiskExplainer::say('CATEGORY_MISMATCH', $this->esso(),
            "Selected 'Fuel' but vendor's default is 'Vehicle'"));
    }

    public function test_summary_on_an_approved_receipt_says_so(): void
    {
        $this->assertSame("You approved this; here's what I'd flagged.", RiskExplainer::summary(20, 'approved', 1));
        $this->assertSame("You approved this; here's what I'd flagged.", RiskExplainer::summary(20, 'forwarded', 1));
        $this->assertSame('Worth a quick look before you approve.', RiskExplainer::summary(20, 'pending_approval', 1));
        $this->assertSame("I'd check this one before approving.", RiskExplainer::summary(35, 'draft', 2));
    }

    public function test_explain_uses_stored_codes_and_fresh_details(): void
    {
        $fresh = [['code' => 'WEEKEND_EXPENSE', 'score' => 15, 'detail' => 'Expense dated on Sunday (2026-10-04)'],
                  ['code' => 'ROUND_NUMBER', 'score' => 20, 'detail' => 'Total is exactly $200.00 (round number)']];
        $r = RiskExplainer::explain($this->esso(), $fresh);
        $this->assertSame(20, $r['score']);
        $this->assertSame('medium', $r['tier']);
        $this->assertSame(['ROUND_NUMBER'], array_column($r['flags'], 'code'));    // stored set the score
        $this->assertSame('Total is exactly $200.00 (round number)', $r['flags'][0]['detail']);

        $r2 = RiskExplainer::explain($this->esso(['anomaly_flags' => '', 'anomaly_score' => 0]), $fresh);
        $this->assertSame(['WEEKEND_EXPENSE', 'ROUND_NUMBER'], array_column($r2['flags'], 'code'));
        $this->assertSame(20, $r2['score']);
    }
}
