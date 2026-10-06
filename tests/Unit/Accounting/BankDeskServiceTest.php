<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's suggestion for an imported bank line — from code alone.
 */
class BankDeskServiceTest extends TestCase
{
    private function ctx(): array
    {
        $acct = fn($id, $code, $name) => ['id' => $id, 'code' => $code, 'name' => $name];
        $fuel = $acct(31, '6100', 'Fuel');
        $mat = $acct(22, '5200', 'Materials & Supplies');
        return [
            'byCode'  => ['6120' => $acct(61, '6120', 'Vehicle Maintenance'), '6200' => $acct(62, '6200', 'Equipment Maintenance'), '6100' => $fuel, '5200' => $mat, '6800' => $acct(68, '6800', 'Bank Charges & Fees'), '2400' => $acct(24, '2400', 'Credit Card Payable'), '6900' => $acct(69, '6900', 'Miscellaneous Expenses')],
            'byAlias' => ['repairs/maintenance' => $acct(62, '6200', 'Equipment Maintenance'), 'vehicle' => $acct(61, '6120', 'Vehicle Maintenance'), 'fuel' => $fuel, 'materials' => $mat, 'meals' => $acct(85, '6850', 'Meals & Entertainment')],
            'vendors' => [['name' => 'Lawnboy', 'aliases' => 'SPAS LAWNBOY', 'default_accounting_category' => 'Materials']],
            'expenses' => [500 => ['accounting_category' => 'Fuel', 'vendor' => 'Chevron']],
            'rules'   => [],
        ];
    }

    private function line(string $desc, array $extra = []): array
    {
        return $extra + ['id' => 1, 'description' => $desc, 'account_id' => 69, 'matched_expense_id' => null, 'type' => 'expense'];
    }

    public function test_the_matched_receipt_wins(): void
    {
        $s = BankDeskService::advise($this->line('POS PURCHASE 4471', ['matched_expense_id' => 500]), $this->ctx());
        $this->assertSame('6100', $s['code']);
        $this->assertSame('receipt', $s['source']);
    }

    public function test_a_vendor_named_in_the_description(): void
    {
        $s = BankDeskService::advise($this->line('POINT OF SALE - SPAS LAWNBOY VANCOUVER'), $this->ctx());
        $this->assertSame('5200', $s['code']);
        $this->assertStringContainsString('Lawnboy', $s['reason']);
    }

    public function test_plain_facts_fuel_fees_and_card_payments(): void
    {
        $this->assertSame('6100', BankDeskService::advise($this->line('POINT SALE SHELL C12345'), $this->ctx())['code']);
        $this->assertSame('6800', BankDeskService::advise($this->line('MONTHLY ACCOUNT FEE'), $this->ctx())['code']);
        $this->assertSame('2400', BankDeskService::advise($this->line('PAYMENT - THANK YOU'), $this->ctx())['code']);
    }

    public function test_no_guess_when_nothing_fits_and_never_a_default_account(): void
    {
        $this->assertNull(BankDeskService::advise($this->line('POINT SALE YELLOW CAB'), $this->ctx()));
        $this->assertNull(BankDeskService::advise($this->line('POINT SALE SHELL', ['account_id' => 31]), $this->ctx()), 'already there');
    }

    public function test_contract_invoice_deposits_are_contract_income(): void
    {
        $c = $this->ctx();
        $c['byCode']['4050'] = ['id' => 45, 'code' => '4050', 'name' => 'Contract Income'];
        $c['contractInvoices'] = [77 => 'INV-2026-0375'];
        $s = BankDeskService::advise($this->line('e-Transfer credit KAM SINGH', ['type' => 'income', 'account_id' => 49, 'matched_invoice_id' => 77]), $c);
        $this->assertSame('4050', $s['code']);
    }

    public function test_stripe_payouts_get_a_note_not_a_guess(): void
    {
        $this->assertStringContainsString('Stripe payout', BankDeskService::note(['type' => 'income', 'description' => 'Preauthorized credit STRIPE STRIPE']));
        $this->assertNull(BankDeskService::note(['type' => 'expense', 'description' => 'POINT SALE SHELL']));
    }

    public function test_places_to_eat_are_meals(): void
    {
        $this->assertSame('6850', BankDeskService::advise($this->line('Point of sale AH LONG SUSHI'), $this->ctx())['code']);
        $this->assertSame('6850', BankDeskService::advise($this->line("Point of sale DUFFIN'S DONUTS"), $this->ctx())['code']);
    }

    public function test_a_found_receipt_says_what_it_was_for_and_links(): void
    {
        $c = $this->ctx();
        $c['found'] = [1 => ['expense_id' => 384, 'vendor' => 'Vital Auto Repair', 'date' => '2026-09-15', 'amount' => 1711.70,
                             'category' => 'Vehicle', 'items' => ['Brake pads', 'Rotors']]];
        $s = BankDeskService::advise($this->line('Point of sale VITAL AUTO REPAIR DET'), $c);
        $this->assertSame('found_receipt', $s['source']);
        $this->assertSame(384, $s['expense_id']);
        $this->assertSame('6120', $s['code']);
        $this->assertStringContainsString('Vehicle (Brake pads, Rotors)', $s['reason']);
    }

    public function test_repairs_at_an_auto_shop_are_vehicle_maintenance(): void
    {
        $c = $this->ctx();
        $c['found'] = [1 => ['expense_id' => 384, 'vendor' => 'Vital Auto Repair & Detail', 'date' => '2026-09-02', 'amount' => 1711.70,
                             'category' => 'Repairs/Maintenance', 'items' => ['Mount and balance tire'], 'asset_tag' => null]];
        $this->assertSame('6120', BankDeskService::advise($this->line('Point of sale VITAL AUTO REPAIR'), $c)['code']);
        $this->assertSame('6200', LedgerAccountMap::refineExpenseCode('6200', 'Repairs/Maintenance', 'equipment', 'Rona'));
        $this->assertSame('6120', LedgerAccountMap::refineExpenseCode('6200', 'Repairs/Maintenance', 'truck', 'Rona'));
    }

    public function test_price_fragments_are_not_item_names(): void
    {
        $this->assertSame(['Mount and balance tire'], BankDeskService::itemNames(['$ 172.30 2.00', 'Amount', 'Mount and balance tire']));
    }
}
