<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Personal button (2026-10-19): a personal receipt goes WHOLE onto a shareholder's account and
 * claims no GST back — not in the books (LedgerService), not on the GST return (GstReportService).
 * Tim on 1300 by default, Katrenna on 1302 by choice. Fake amounts.
 */
class ExpensePersonalTest extends TestCase
{
    private function ledger(): LedgerService
    {
        return new LedgerService($this->createMock(PDO::class));
    }

    private static function lines(array $entry): array
    {
        $out = [];
        foreach ($entry['lines'] as $l) $out[$l['account']] = [round((float)$l['debit'], 2), round((float)$l['credit'], 2)];
        return $out;
    }

    public function testShareholderAccounts(): void
    {
        $this->assertTrue(LedgerService::isShareholderAccount('1300'));
        $this->assertTrue(LedgerService::isShareholderAccount('1302'));
        $this->assertFalse(LedgerService::isShareholderAccount('1310'));
        $this->assertFalse(LedgerService::isShareholderAccount('5200'));
        $this->assertFalse(LedgerService::isShareholderAccount(''));
    }

    public function testAPersonalReceiptClaimsNoGst(): void
    {
        $e = $this->ledger()->buildExpenseEntry(['id' => 1, 'date' => '2026-10-19', 'net' => 20.00, 'gst' => 1.00, 'pst' => 0,
                                                 'expense_account' => '1300', 'funding' => '2400']);
        $this->assertSame(['1300' => [21.0, 0.0], '2400' => [0.0, 21.0]], self::lines($e), 'the whole $21 on the shareholder, no 2210');
        $biz = $this->ledger()->buildExpenseEntry(['id' => 2, 'date' => '2026-10-19', 'net' => 20.00, 'gst' => 1.00, 'pst' => 0,
                                                   'expense_account' => '5200', 'funding' => '2400']);
        $this->assertSame([1.0, 0.0], self::lines($biz)['2210'], 'a business receipt still claims its GST');
    }

    public function testAPersonalShareOfASplitClaimsNoGst(): void
    {
        $e = $this->ledger()->buildSplitExpenseEntry(['id' => 3, 'date' => '2026-10-19', 'funding' => '2400', 'allocations' => [
            ['account' => '5300', 'net' => 50.00, 'gst' => 2.50, 'pst' => 0],
            ['account' => '1302', 'net' => 10.00, 'gst' => 0.50, 'pst' => 0],
        ]]);
        $l = self::lines($e);
        $this->assertSame([10.5, 0.0], $l['1302']);
        $this->assertSame([2.5, 0.0], $l['2210'], 'only the business share is claimed');
        $this->assertSame([0.0, 63.0], $l['2400']);
    }

    public function testThePostingAccountFollowsTheChosenShareholder(): void
    {
        $this->assertSame('1302', LedgerSyncService::personalAccount(['shareholder_account' => '1302'], 'personal'));
        $this->assertSame('1300', LedgerSyncService::personalAccount(['shareholder_account' => null], 'personal'), 'none marked: 1300, never the category map');
        $this->assertNull(LedgerSyncService::personalAccount(['shareholder_account' => '1302'], 'fuel'), 'no longer Personal: ignored');
        $this->assertSame('1300', LedgerSyncService::personalAccount(['shareholder_account' => '5200'], 'personal'), 'never a non-shareholder account');
        $sync = new LedgerSyncService($this->createMock(PDO::class), $this->ledger());
        $args = $sync->mapExpenseToExpenseArgs(['id' => 9, 'expense_date' => '2026-10-19', 'total' => 21, 'gst_amount' => 1, 'pst_amount' => 0,
                                                'accounting_category' => 'Personal', 'payment_method' => 'Visa'], ['personal' => '6900'], []);
        $this->assertSame('1300', $args['expense_account'], 'a wrong category map cannot make it a cost');
    }

    public function testChoicesAndChanges(): void
    {
        $c = ExpensePersonalService::choices(['Katrenna' => '1302', 'Tim' => '1300', 'Payroll' => '5100']);
        $this->assertSame([['name' => 'Tim', 'account' => '1300'], ['name' => 'Katrenna', 'account' => '1302']], $c);
        $this->assertSame('1300', ExpensePersonalService::choices([])[0]['account'], 'no list: the Personal account');

        $tim = ExpensePersonalService::changesFor(null, $c);
        $this->assertSame(['accounting_category' => 'Personal', 'shareholder_account' => null, 'allocations' => []], $tim);
        $this->assertNull(ExpensePersonalService::changesFor('1300', $c)['shareholder_account']);
        $this->assertSame('1302', ExpensePersonalService::changesFor('1302', $c)['shareholder_account']);
        $this->expectException(InvalidArgumentException::class);
        ExpensePersonalService::changesFor('1301', $c);
    }

    public function testTheGstReturnSkipsAPersonalShare(): void
    {
        $rows = [['id' => 7, 'description' => 'Chevron', 'amount' => 60, 'gst_amount' => 3, 'total' => 63, 'category' => 'Fuel']];
        $out = GstReportService::expandSplits($rows, [7 => [
            ['label' => 'Diesel', 'net_amount' => 50, 'gst_amount' => 2.5, 'pst_amount' => 0, 'accounting_category' => 'Fuel'],
            ['label' => 'Snacks', 'net_amount' => 10, 'gst_amount' => 0.5, 'pst_amount' => 0, 'accounting_category' => 'Personal'],
        ]]);
        $this->assertCount(1, $out);
        $this->assertSame('Diesel', $out[0]['description']);

        $unsplit = [['id' => 8, 'category' => 'Personal', 'gst_amount' => 1], ['id' => 9, 'category' => 'Fuel', 'gst_amount' => 2]];
        $this->assertSame([9], array_column(GstReportService::withoutPersonal($unsplit), 'id'), 'an unsplit Personal receipt claims nothing');
        $share = [['id' => 8, 'category' => 'Personal', 'gst_amount' => 1, 'split_share' => true]];
        $this->assertCount(1, GstReportService::withoutPersonal($share), 'a split receipt counts by its shares, as the ledger does');
    }
}
