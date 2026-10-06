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
            'byCode'  => ['6100' => $fuel, '5200' => $mat, '6800' => $acct(68, '6800', 'Bank Charges & Fees'), '2400' => $acct(24, '2400', 'Credit Card Payable'), '6900' => $acct(69, '6900', 'Miscellaneous Expenses')],
            'byAlias' => ['fuel' => $fuel, 'materials' => $mat],
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
}
