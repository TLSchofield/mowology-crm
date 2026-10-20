<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The standard capital / small-tools split (CapitalItemRule, 2026-10-19). The mower receipt is Canadian Tire
 * #149: $1,800.95 = EGO mower $1,599.99 + five small items, GST $80.40, PST $112.56.
 */
class CapitalItemRuleTest extends TestCase
{
    private static function mower(): array
    {
        return ['id' => 149, 'net' => 1607.99, 'gst' => 80.40, 'pst' => 112.56, 'expense_account' => '6210'];
    }

    public function testAMixedReceiptSplitsTheMachineFromTheRest(): void
    {
        $lines = [['name' => 'EG056V22" SPMW A', 'quantity' => 1, 'unit_price' => 1599.99, 'line_total' => 1599.99],
                  ['name' => 'EHF', 'quantity' => 1, 'unit_price' => null, 'line_total' => 8.00]];
        $r = CapitalItemRule::apply(self::mower(), $lines);
        $this->assertSame(['1500', '6210'], array_column($r['allocations'], 'account'));
        $sum = 0.0;
        foreach ($r['allocations'] as $a) $sum += $a['net'] + $a['gst'] + $a['pst'];
        $this->assertEqualsWithDelta(1800.95, $sum, 0.001, 'to the cent');
        $this->assertStringContainsString('EG056V22', $r['allocations'][0]['label']);
        $this->assertStringContainsString('#149', $r['memo']);
        $entry = (new LedgerService($this->createMock(PDO::class)))->buildExpenseEntry(self::mower() + ['date' => '2026-03-05', 'funding' => '1010'] + $r);
        $dr = 0.0; $cr = 0.0;
        foreach ($entry['lines'] as $l) { $dr += $l['debit']; $cr += $l['credit']; }
        $this->assertEqualsWithDelta($dr, $cr, 0.001, 'balanced');
    }

    public function testSmallToolsStayAndAllCapitalMoves(): void
    {
        $rake = [['name' => 'Corona Leaf Rake', 'quantity' => 2, 'unit_price' => 27.99, 'line_total' => 55.98]];
        $this->assertNull(CapitalItemRule::apply(['net' => 55.98, 'gst' => 2.80, 'pst' => 3.92, 'expense_account' => '6210', 'id' => 1], $rake));
        $two = [['name' => 'Blower', 'quantity' => 2, 'unit_price' => null, 'line_total' => 1100.0]];
        $this->assertSame('1500', CapitalItemRule::apply(['net' => 1100, 'gst' => 55, 'pst' => 77, 'expense_account' => '6210', 'id' => 2], $two)['expense_account'], '$550 each');
        $pair = [['name' => 'Gloves', 'quantity' => 100, 'unit_price' => null, 'line_total' => 600.0]];
        $this->assertNull(CapitalItemRule::apply(['net' => 600, 'gst' => 30, 'pst' => 42, 'expense_account' => '6210', 'id' => 3], $pair), '$6 each is not equipment');
        $this->assertSame('1500', CapitalItemRule::apply(['net' => 899, 'gst' => 44.95, 'pst' => 62.93, 'expense_account' => '6210', 'id' => 4], [])['expense_account'], 'no lines: the amount decides');
        $this->assertNull(CapitalItemRule::apply(['net' => 120, 'gst' => 6, 'pst' => 8.4, 'expense_account' => '6210', 'id' => 5], []));
    }

    public function testToolsReceiptsCarryTheRuleAndASplitByAPersonWins(): void
    {
        $sync = new LedgerSyncService($this->createMock(PDO::class), new LedgerService($this->createMock(PDO::class)));
        $args = $sync->mapExpenseToExpenseArgs(['id' => 9, 'expense_date' => '2026-03-05', 'total' => 100, 'gst_amount' => 0, 'pst_amount' => 0,
                                                'accounting_category' => 'Tools/Equipment', 'payment_method' => 'debit'], ['tools/equipment' => '6210'], []);
        $this->assertArrayHasKey('capital_rule', $args);
        $meals = $sync->mapExpenseToExpenseArgs(['id' => 9, 'expense_date' => '2026-03-05', 'total' => 100, 'gst_amount' => 0, 'pst_amount' => 0,
                                                 'accounting_category' => 'Meals', 'payment_method' => 'debit'], ['meals' => '6850'], []);
        $this->assertArrayNotHasKey('capital_rule', $meals);
        $this->assertSame(['allocations' => []], array_intersect_key((new LedgerService($this->createMock(PDO::class)))->withCapitalItems(['allocations' => []] + $args), ['allocations' => 1]),
                          'given allocations are left alone');
    }
}
