<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ReceiptInboxRereadService - the pure rules: what a read says (fieldsFromRead) and what is safe to write
 * (mergeBlankOnly). The reader and the gate are not exercised here.
 */
class ReceiptInboxRereadServiceTest extends TestCase
{
    private function parsed(array $over = []): array
    {
        return array_merge(['total' => 157.5, 'gst' => 7.5, 'pst' => 0.0, 'subtotal' => 150.0, 'date' => '2026-10-17',
                            'vendor_hint' => 'Anthropic', 'payment_method' => 'credit_card'], $over);
    }

    private function sugg(array $over = []): array
    {
        return array_merge(['vendor_id' => 12, 'vendor_name' => 'Anthropic', 'accounting_category' => 'Office/Admin', 'gbp_category' => ''], $over);
    }

    private function unreadRow(array $over = []): array
    {
        return array_merge(['vendor_id' => null, 'vendor_name_raw' => '', 'description' => '', 'total' => '0.00',
                            'accounting_category' => '', 'gbp_category' => '', 'payment_method' => ''], $over);
    }

    // ── fieldsFromRead ────────────────────────────────────────────────────────────────────

    public function testFieldsFromReadCarriesTheParsedFacts(): void
    {
        $r = ReceiptInboxRereadService::fieldsFromRead($this->parsed(), $this->sugg());
        $this->assertSame(157.5, $r['total']);
        $this->assertSame(7.5, $r['gst_amount']);
        $this->assertSame(150.0, $r['amount']);
        $this->assertSame('2026-10-17', $r['expense_date']);
        $this->assertSame(12, $r['vendor_id']);
        $this->assertSame('Office/Admin', $r['accounting_category']);
    }

    public function testEstimatedGstIsNotClaimed(): void
    {
        $r = ReceiptInboxRereadService::fieldsFromRead($this->parsed(['gst_estimated' => true, 'subtotal' => null]), $this->sugg());
        $this->assertSame(0.0, $r['gst_amount']);                  // no ITC on tax that was never printed
        $this->assertSame(157.5, $r['amount']);                    // amount = total - 0 gst - 0 pst
        $this->assertNotNull($r['gst_note']);
    }

    public function testGstExemptVendorClaimsNoGst(): void
    {
        $r = ReceiptInboxRereadService::fieldsFromRead($this->parsed(), $this->sugg(['vendor_gst_exempt' => true]));
        $this->assertSame(0.0, $r['gst_amount']);
    }

    public function testAnImpossibleDateIsDropped(): void
    {
        $this->assertSame('', ReceiptInboxRereadService::fieldsFromRead($this->parsed(['date' => '2026-13-45']), $this->sugg())['expense_date']);
        $this->assertSame('', ReceiptInboxRereadService::fieldsFromRead($this->parsed(['date' => 'Oct 17']), $this->sugg())['expense_date']);
    }

    public function testVendorDefaultCategoryIsTheFallback(): void
    {
        $r = ReceiptInboxRereadService::fieldsFromRead($this->parsed(), $this->sugg(['accounting_category' => null]), 'Materials');
        $this->assertSame('Materials', $r['accounting_category']);
    }

    // ── mergeBlankOnly ────────────────────────────────────────────────────────────────────

    public function testUnreadRowGetsEveryBlankField(): void
    {
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(), $this->sugg());
        $c = ReceiptInboxRereadService::mergeBlankOnly($this->unreadRow(), $read);
        $this->assertSame(157.5, $c['total']);
        $this->assertSame('2026-10-17', $c['expense_date']);
        $this->assertSame(12, $c['vendor_id']);
        $this->assertSame('Office/Admin', $c['accounting_category']);
        $this->assertSame('Anthropic', $c['vendor_name_raw']);
    }

    public function testASavedTotalAndEveryAmountAreNeverOverwritten(): void
    {
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(), $this->sugg());
        $c = ReceiptInboxRereadService::mergeBlankOnly($this->unreadRow(['total' => '99.00']), $read);
        foreach (['total', 'amount', 'gst_amount', 'pst_amount', 'expense_date'] as $k) {
            $this->assertArrayNotHasKey($k, $c, $k);
        }
        $this->assertSame(12, $c['vendor_id']);                    // a blank vendor is still filled
    }

    public function testAVendorOrCategoryAPersonChoseIsKept(): void
    {
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(), $this->sugg());
        $row = $this->unreadRow(['vendor_id' => 7, 'vendor_name_raw' => 'Lowes', 'accounting_category' => 'Fuel']);
        $c = ReceiptInboxRereadService::mergeBlankOnly($row, $read);
        $this->assertArrayNotHasKey('vendor_id', $c);
        $this->assertArrayNotHasKey('vendor_name_raw', $c);
        $this->assertArrayNotHasKey('accounting_category', $c);
        $this->assertSame(157.5, $c['total']);                     // the unread amounts are still filled
    }

    public function testAReadWithNoTotalFillsNothing(): void
    {
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(['total' => null, 'subtotal' => null, 'gst' => null]), $this->sugg());
        $this->assertSame([], ReceiptInboxRereadService::mergeBlankOnly($this->unreadRow(), $read));   // still unread: say so, don't half-fill
    }

    public function testMergeIsIdempotent(): void
    {
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(), $this->sugg());
        $first = ReceiptInboxRereadService::mergeBlankOnly($this->unreadRow(), $read);
        $second = ReceiptInboxRereadService::mergeBlankOnly($this->unreadRow($first), $read);   // the row now holds what was written
        $this->assertSame([], array_diff_key($second, ['gbp_category' => 1, 'payment_method' => 1, 'description' => 1]));
    }

    // ── suspectReason: our own documents and form labels are never filled in ──────────────

    public function testOurOwnNameAsVendorIsFlaggedNotFilled(): void
    {
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(['vendor_hint' => 'MOWOLOGY LAWNS AND LANDSCAPES LTD']), $this->sugg(['vendor_name' => 'MOWOLOGY']));
        $this->assertNotNull(ReceiptInboxRereadService::suspectReason($read));
    }

    public function testAFormLabelAsVendorIsFlagged(): void
    {
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(['vendor_hint' => 'PREPARED FOR']), $this->sugg(['vendor_name' => 'PREPARED FOR']));
        $this->assertNotNull(ReceiptInboxRereadService::suspectReason($read));
    }

    public function testARealVendorPasses(): void
    {
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(), $this->sugg());
        $this->assertNull(ReceiptInboxRereadService::suspectReason($read));
    }

    public function testOnlyTheVendorIsJudgedNotTheBodyOfTheBill(): void
    {
        // A genuine bill addressed to us names Mowology in its body; the vendor is who sent it.
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(['vendor_hint' => 'Canadian Electric Vehicles Ltd.']), $this->sugg(['vendor_name' => 'Canadian Electric Vehicles']));
        $this->assertNull(ReceiptInboxRereadService::suspectReason($read));
    }

    public function testAnUnknownVendorIsNotSuspect(): void
    {
        $read = ReceiptInboxRereadService::fieldsFromRead($this->parsed(['vendor_hint' => '']), $this->sugg(['vendor_id' => null, 'vendor_name' => '']));
        $this->assertNull(ReceiptInboxRereadService::suspectReason($read));   // blank vendor: amounts can still be filled, vendor left for the owner
    }
}
