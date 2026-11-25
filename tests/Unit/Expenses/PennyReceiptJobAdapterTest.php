<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PennyReceiptJobAdapter — a pending emailed receipt becomes the strip's "Approve this receipt?" job, carrying the exact
 * fields the Expenses card's Approve button posts to receipt-inbox-confirm.php; only receipts the endpoint would take.
 */
class PennyReceiptJobAdapterTest extends TestCase
{
    private function row(array $over = []): array
    {
        return $over + [
            'id' => 301, 'expense_date' => '2026-11-06', 'vendor_id' => 12, 'vendor_name' => 'Home Depot', 'vendor_name_raw' => 'HOME DEPOT #7054',
            'amount' => '100.00', 'gst_amount' => '5.00', 'pst_amount' => '7.00', 'total' => '112.00', 'accounting_category' => 'Materials',
            'created_at' => '2026-11-07 09:10:00', 'receipt_media_id' => 88, 'sender_email' => 'orders@homedepot.ca',
        ];
    }

    public function testApproveCarriesTheCardsOwnFields(): void
    {
        $j = PennyReceiptJobAdapter::fromReceipt($this->row(), ['default' => 'Materials', 'seen' => 6, 'run' => 5, 'twin' => false], 1, 3);
        $this->assertSame('receipt', $j['kind']);
        $this->assertSame('rc:301', $j['key']);
        $this->assertSame('/crm/api/receipt-inbox-confirm.php', $j['api']);
        $this->assertSame('form', $j['transport']);
        $this->assertSame(['action' => 'approve', 'expense_id' => 301, 'vendor_id' => 12, 'expense_date' => '2026-11-06', 'total' => '112.00',
            'gst_amount' => '5.00', 'pst_amount' => '7.00', 'accounting_category' => 'Materials'], $j['primary']['request']);
        $this->assertSame(['mode' => 'edit', 'expense_id' => 301], $j['secondary']['request']);
        $this->assertSame('/crm/api/serve-receipt.php?id=88', $j['left']['href']);
        $this->assertSame('mastered', $j['sure']);
    }

    public function testBlankCategoryTakesTheVendorsDefault(): void
    {
        $j = PennyReceiptJobAdapter::fromReceipt($this->row(['accounting_category' => '']), ['default' => 'Fuel', 'seen' => 0, 'run' => 0], 0, 0);
        $this->assertSame('Fuel', $j['primary']['request']['accounting_category']);
        $this->assertSame('info', $j['columns'][3]['state']);
    }

    public function testProposesOnlyWhatTheEndpointWouldTake(): void
    {
        $this->assertTrue(PennyReceiptJobAdapter::proposes($this->row()));
        $this->assertFalse(PennyReceiptJobAdapter::proposes($this->row(['vendor_id' => null])), 'no vendor');
        $this->assertFalse(PennyReceiptJobAdapter::proposes($this->row(['total' => '0.00'])), 'unread total');
        $this->assertFalse(PennyReceiptJobAdapter::proposes($this->row(['gst_amount' => '90.00', 'pst_amount' => '40.00'])), 'tax larger than the total: the endpoint refuses');
        $this->assertFalse(PennyReceiptJobAdapter::proposes($this->row(['receipt_media_id' => null])), 'nothing to look at');
        $this->assertFalse(PennyReceiptJobAdapter::proposes($this->row(['expense_date' => '2025-12-30'])), 'a filed year is traced, not approved');
        $this->assertFalse(PennyReceiptJobAdapter::proposes($this->row(['expense_date' => ''])), 'no date');
        $this->assertFalse(PennyReceiptJobAdapter::proposes($this->row(['accounting_category' => '']), ''), 'no category anywhere');
        $this->assertTrue(PennyReceiptJobAdapter::proposes($this->row(['accounting_category' => '']), 'Fuel'));
    }

    public function testAReadThatDoesNotAddUpShowsAsADifferingDisc(): void
    {
        $j = PennyReceiptJobAdapter::fromReceipt($this->row(['amount' => '90.00']), ['default' => 'Materials', 'seen' => 9, 'run' => 5], 0, 0);
        $this->assertSame('differs', $j['columns'][0]['state']);
        $this->assertSame('held', $j['sure']);
    }

    public function testTheLadder(): void
    {
        $s = fn(int $seen, int $run, bool $d = false) => PennyReceiptJobAdapter::sureOfReceipt($seen, $run, $d);
        $this->assertSame('forming', $s(0, 0));
        $this->assertSame('held', $s(1, 1));
        $this->assertSame('held', $s(8, 2));
        $this->assertSame('known', $s(8, 3));
        $this->assertSame('known', $s(8, 4));
        $this->assertSame('mastered', $s(8, 5));
        $this->assertSame('held', $s(8, 5, true), 'a differs disc strikes gold back');
        $this->assertSame('forming', $s(0, 0, true));
    }

    public function testDiscsCompareAndAnyDifferenceDropsTheTriangle(): void
    {
        $ctx = ['default' => 'Materials', 'seen' => 9, 'run' => 5, 'twin' => true];
        $j = PennyReceiptJobAdapter::fromReceipt($this->row(), $ctx, 0, 0);
        $this->assertSame(['Amounts', 'Vendor', 'Date', 'Category', 'Already filed'], array_column($j['columns'], 'label'));
        $this->assertSame('differs', $j['columns'][4]['state']);
        $this->assertSame('held', $j['sure']);
        $odd = PennyReceiptJobAdapter::fromReceipt($this->row(['expense_date' => '2026-06-01']), ['default' => 'Materials', 'seen' => 9, 'run' => 5], 0, 0);
        $this->assertSame('differs', $odd['columns'][2]['state'], 'a date months before the email was sent');
        $cat = PennyReceiptJobAdapter::fromReceipt($this->row(), ['default' => 'Fuel', 'seen' => 9, 'run' => 5], 0, 0);
        $this->assertSame('differs', $cat['columns'][3]['state']);
    }

    public function testBestKnownVendorFirstThenTheCardsOrder(): void
    {
        $c = fn(int $id, int $seen, int $run) => ['row' => ['id' => $id], 'default' => '', 'record' => ['seen' => $seen, 'run' => $run]];
        $out = PennyReceiptJobAdapter::order([$c(1, 0, 0), $c(2, 4, 1), $c(3, 9, 5), $c(4, 0, 0), $c(5, 7, 5)]);
        $this->assertSame([3, 5, 2, 1, 4], array_map(fn($x) => $x['row']['id'], $out));
    }
}
