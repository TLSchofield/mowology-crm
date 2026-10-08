<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2026-10-07: Charlie said "186 unbilled visits — $28,920.40" and Otto counted Alexandra's
 * MONTHLY UPFRONT contract visits as "not invoiced yet". Only per-visit work with no invoice
 * is unbilled.
 */
class UnbilledVisitServiceTest extends TestCase
{
    private const TODAY = '2026-10-07';

    private static function visit(array $o = []): array
    {
        return $o + [
            'id' => 1, 'plan_id' => 10, 'scheduled_date' => '2026-09-15',
            'is_invoiced' => 0, 'invoice_id' => null, 'invoice_status' => null,
            'actual_amount' => null, 'extras_amount' => null,
            'pricing_model' => 'per_visit', 'is_recurring' => 1, 'price_per_visit' => '55.00', 'estimated_amount' => null,
            'plan_title' => 'Weekly mowing',
            'contract_id' => null, 'billing_cycle' => null, 'billing_amount' => null, 'contract_status' => null, 'contract_number' => null,
            'test_text' => 'Weekly mowing 2505 W 8th Ave',
            'line_linked' => false, 'plan_invoiced' => false, 'contract_invoice_dates' => [], 'plan_lines_total' => null,
        ];
    }

    private static function monthly(array $o = []): array
    {
        return self::visit($o + ['contract_id' => 77, 'billing_cycle' => 'monthly', 'billing_amount' => '420.00',
            'contract_status' => 'active', 'contract_number' => 'CTR-2026-0007', 'pricing_model' => 'monthly_flat', 'price_per_visit' => null]);
    }

    private static function bucket(array $v): string
    {
        return UnbilledVisitService::classify($v, self::TODAY);
    }

    public function test_per_visit_plan_with_no_invoice_is_unbilled(): void
    {
        $this->assertSame('unbilled', self::bucket(self::visit()));
    }

    public function test_one_off_job_is_unbilled_and_valued_like_invoicing(): void
    {
        $v = self::visit(['is_recurring' => 0, 'price_per_visit' => null, 'estimated_amount' => '640.00', 'extras_amount' => '35.00']);
        $this->assertSame('unbilled', self::bucket($v));
        $this->assertSame(675.0, UnbilledVisitService::value($v));
    }

    public function test_per_visit_contract_still_counts(): void
    {
        $v = self::visit(['contract_id' => 5, 'billing_cycle' => 'per_visit', 'contract_status' => 'active']);
        $this->assertSame('unbilled', self::bucket($v));
    }

    // ── Monthly contract (Alexandra) ─────────────────────────────────────

    public function test_monthly_contract_with_that_months_invoice_is_covered(): void
    {
        $v = self::monthly(['contract_invoice_dates' => ['2026-09-01', '2026-10-01']]);
        $this->assertSame('contract', self::bucket($v));
    }

    public function test_monthly_contract_current_month_is_covered_while_active(): void
    {
        $this->assertSame('contract', self::bucket(self::monthly(['scheduled_date' => '2026-10-03'])));
    }

    public function test_monthly_contract_past_month_with_no_invoice_is_a_gap_not_unbilled(): void
    {
        $v = self::monthly(['contract_invoice_dates' => ['2026-10-01']]);
        $this->assertSame('contract_gap', self::bucket($v));
        $this->assertSame('contract_gap', self::bucket(self::monthly(['scheduled_date' => '2026-10-03', 'contract_status' => 'expired'])));
    }

    public function test_seasonal_contract_covered_by_season_invoice_or_while_active(): void
    {
        $s = ['billing_cycle' => 'seasonal', 'pricing_model' => 'seasonal'];
        $this->assertSame('contract', self::bucket(self::monthly($s + ['contract_invoice_dates' => ['2026-04-01']])));
        $this->assertSame('contract', self::bucket(self::monthly($s)));
        $this->assertSame('contract_gap', self::bucket(self::monthly($s + ['contract_status' => 'cancelled'])));
    }

    // ── Already invoiced ─────────────────────────────────────────────────

    public function test_linked_visits_are_invoiced(): void
    {
        $this->assertSame('invoiced', self::bucket(self::visit(['line_linked' => true])));
        $this->assertSame('invoiced', self::bucket(self::visit(['is_invoiced' => 1])));        // flagged by hand, no id
        $this->assertSame('invoiced', self::bucket(self::visit(['invoice_id' => 9, 'invoice_status' => 'draft'])));
        $this->assertSame('invoiced', self::bucket(self::visit(['is_recurring' => 0, 'plan_invoiced' => true])));
    }

    public function test_void_invoice_or_recurring_plan_invoice_does_not_cover(): void
    {
        $this->assertSame('unbilled', self::bucket(self::visit(['is_invoiced' => 1, 'invoice_id' => 9, 'invoice_status' => 'void'])));
        $this->assertSame('unbilled', self::bucket(self::visit(['plan_invoiced' => true])));   // weekly plan: one invoice ≠ every visit
    }

    // ── No charge, flat price, before the CRM, test ──────────────────────

    public function test_other_buckets(): void
    {
        $this->assertSame('no_charge', self::bucket(self::visit(['actual_amount' => '0.00'])));
        $this->assertSame('fixed_price', self::bucket(self::visit(['pricing_model' => 'monthly_flat'])));
        $this->assertSame('before_crm', self::bucket(self::visit(['scheduled_date' => '2026-02-10'])));
        $this->assertSame('test', self::bucket(self::visit(['test_text' => 'ZZTEST mowing'])));
    }

    // ── Summary ──────────────────────────────────────────────────────────

    public function test_summary_counts_only_truly_unbilled_and_explains_the_rest(): void
    {
        $visits = [
            self::visit(['id' => 1]),                                                         // unbilled $55
            self::visit(['id' => 2, 'actual_amount' => '80.00']),                            // unbilled $80
            self::monthly(['id' => 3, 'contract_invoice_dates' => ['2026-09-01']]),          // contract
            self::monthly(['id' => 4, 'contract_invoice_dates' => ['2026-09-01']]),          // contract
            self::visit(['id' => 5, 'is_invoiced' => 1]),                                    // invoiced
            self::visit(['id' => 6, 'actual_amount' => '0.00']),                             // no charge
            self::monthly(['id' => 7, 'scheduled_date' => '2026-08-10']),                    // gap Aug
            self::monthly(['id' => 8, 'scheduled_date' => '2026-08-24']),                    // same gap month
        ];
        $s = UnbilledVisitService::summarise($visits, self::TODAY);
        $this->assertSame(2, $s['count']);
        $this->assertSame(135.0, $s['amount']);
        $this->assertSame(8, $s['was']['count']);       // the old query counted all eight (none has an invoice id)
        $this->assertSame(2, $s['buckets']['contract']['count']);
        $this->assertSame(1, $s['buckets']['invoiced']['count']);
        $this->assertSame(1, $s['buckets']['no_charge']['count']);
        $this->assertSame(2, $s['buckets']['contract_gap']['count']);
        $this->assertSame(420.0, $s['buckets']['contract_gap']['amount']);   // one missing month, not two visits
        $this->assertCount(1, $s['gaps']);
        $this->assertSame([1, 2], array_column($s['visits'], 'visit_id'));
        $this->assertStringStartsWith('8 → 2 truly unbilled ($135.00); 2 covered by contract billing', $s['text']);
        $this->assertStringContainsString('1 already invoiced', $s['text']);
        $this->assertStringContainsString('1 no charge', $s['text']);
    }
}
