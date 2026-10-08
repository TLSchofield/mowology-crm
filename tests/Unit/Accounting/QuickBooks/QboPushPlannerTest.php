<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CRM → QuickBooks payloads (phase 2, flag OFF) and the guards that hold a record back.
 */
class QboPushPlannerTest extends TestCase
{
    private function ctx(array $over = []): array
    {
        return $over + [
            'book_close_date' => '2025-12-31', 'paid_from_qbo_id' => '42', 'vendor_qbo_id' => '77',
            'account_map' => [20 => '60', 21 => '63', 22 => '64'], 'category_accounts' => ['Materials' => 20, 'Meals' => 21, 'Fuel' => 22],
            'tax_gst' => '2', 'tax_gst_pst' => '5', 'tax_exempt' => '3', 'multicurrency' => false, 'currency' => 'CAD',
        ];
    }

    private function expense(array $over = []): array
    {
        return $over + ['id' => 812, 'expense_date' => '2026-05-14', 'status' => 'approved', 'payment_method' => 'credit_card', 'vendor_name' => 'Home Depot',
                        'description' => 'Mulch + edging', 'amount' => 150.00, 'tax_amount' => 7.50, 'pst_amount' => 7.00, 'total' => 164.50, 'job_id' => 301, 'forwarded_to_accounting' => 0];
    }

    private function lines(): array
    {
        return [
            ['label' => 'Bark mulch 3 yd', 'accounting_category' => 'Materials', 'net_amount' => 100.00, 'gst_amount' => 5.00, 'pst_amount' => 7.00, 'pst_taxable' => 1, 'is_stock' => 0],
            ['label' => 'Edging (exempt)', 'accounting_category' => 'Materials', 'net_amount' => 50.00, 'gst_amount' => 2.50, 'pst_amount' => 0.00, 'pst_taxable' => 0, 'is_stock' => 0],
        ];
    }

    public function test_an_approved_split_expense_becomes_a_credit_card_purchase_with_one_line_per_split(): void
    {
        $r = QboPushPlanner::purchaseFromExpense($this->expense(), $this->lines(), $this->ctx());
        $this->assertTrue($r['ok'], $r['blocked'] ?? '');
        $p = $r['payload'];
        $this->assertSame('CreditCard', $p['PaymentType']);
        $this->assertSame(['value' => '42'], $p['AccountRef']);
        $this->assertSame(['value' => '77', 'type' => 'Vendor'], $p['EntityRef']);
        $this->assertSame('2026-05-14', $p['TxnDate']);
        $this->assertSame('EXP-812', $p['DocNumber']);
        $this->assertSame('TaxExcluded', $p['GlobalTaxCalculation']);
        $this->assertCount(2, $p['Line']);
        $this->assertSame('AccountBasedExpenseLineDetail', $p['Line'][0]['DetailType']);
        $this->assertSame(100.0, $p['Line'][0]['Amount']);
        $this->assertSame('60', $p['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef']['value']);
        $this->assertSame('5', $p['Line'][0]['AccountBasedExpenseLineDetail']['TaxCodeRef']['value'], 'PST-taxable line → GST/PST code');
        $this->assertSame('2', $p['Line'][1]['AccountBasedExpenseLineDetail']['TaxCodeRef']['value'], 'GST-only line → GST code');
        $this->assertSame('NotBillable', $p['Line'][1]['AccountBasedExpenseLineDetail']['BillableStatus']);
        $this->assertStringContainsString('CRM expense #812', $p['PrivateNote']);
        $this->assertSame([], $r['warnings']);
        $this->assertArrayNotHasKey('CurrencyRef', $p);
    }

    public function test_payment_method_maps_to_intuits_three_payment_types(): void
    {
        $this->assertSame('CreditCard', QboPushPlanner::paymentType('Visa'));
        $this->assertSame('CreditCard', QboPushPlanner::paymentType('credit_card'));
        $this->assertSame('Check', QboPushPlanner::paymentType('cheque'));
        $this->assertSame('Cash', QboPushPlanner::paymentType('debit'));
        $this->assertSame('Cash', QboPushPlanner::paymentType('e-transfer'));
        $this->assertSame('Cash', QboPushPlanner::paymentType(null));
    }

    public function test_a_2025_expense_is_never_pushed_even_if_the_books_are_open(): void
    {
        $r = QboPushPlanner::purchaseFromExpense($this->expense(['expense_date' => '2025-11-02']), $this->lines(), $this->ctx(['book_close_date' => null]));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('FY2025 is filed', $r['blocked']);
    }

    public function test_a_date_inside_the_closed_period_is_blocked(): void
    {
        $r = QboPushPlanner::purchaseFromExpense($this->expense(['expense_date' => '2026-02-10']), $this->lines(), $this->ctx(['book_close_date' => '2026-03-31']));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('closed through 2026-03-31', $r['blocked']);
    }

    public function test_unapproved_unmapped_and_unchosen_each_block_with_a_plain_reason(): void
    {
        $this->assertStringContainsString('Not approved', QboPushPlanner::purchaseFromExpense($this->expense(['status' => 'draft']), $this->lines(), $this->ctx())['blocked']);
        $this->assertStringContainsString('bank / card', QboPushPlanner::purchaseFromExpense($this->expense(), $this->lines(), $this->ctx(['paid_from_qbo_id' => null]))['blocked']);
        $this->assertStringContainsString('Vendor "Home Depot"', QboPushPlanner::purchaseFromExpense($this->expense(), $this->lines(), $this->ctx(['vendor_qbo_id' => null]))['blocked']);
        $lines = $this->lines(); $lines[0]['accounting_category'] = 'Disposal';
        $this->assertStringContainsString('no confirmed QuickBooks account', QboPushPlanner::purchaseFromExpense($this->expense(), $lines, $this->ctx())['blocked']);
        $this->assertStringContainsString('No QuickBooks tax code', QboPushPlanner::purchaseFromExpense($this->expense(), $this->lines(), $this->ctx(['tax_gst_pst' => null]))['blocked']);
    }

    public function test_a_meals_line_is_flagged_for_the_50_percent_itc_and_multicurrency_adds_the_currency(): void
    {
        $lines = [['label' => 'Crew lunch', 'accounting_category' => 'Meals', 'net_amount' => 40.00, 'gst_amount' => 2.00, 'pst_amount' => 0, 'pst_taxable' => 0]];
        $r = QboPushPlanner::purchaseFromExpense($this->expense(['amount' => 40.00, 'tax_amount' => 2.00, 'pst_amount' => 0, 'total' => 42.00]), $lines, $this->ctx(['multicurrency' => true]));
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('50 % input tax credit', $r['warnings'][0]);
        $this->assertStringContainsString('[meals — 50% ITC]', $r['payload']['Line'][0]['Description']);
        $this->assertSame(['value' => 'CAD'], $r['payload']['CurrencyRef']);
    }

    public function test_an_expense_without_split_lines_falls_back_to_its_header(): void
    {
        $r = QboPushPlanner::purchaseFromExpense($this->expense(['accounting_category' => 'Fuel', 'pst_amount' => 0]), [], $this->ctx());
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['payload']['Line']);
        $this->assertSame(150.0, $r['payload']['Line'][0]['Amount']);
        $this->assertSame('64', $r['payload']['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef']['value']);
        $this->assertSame('2', $r['payload']['Line'][0]['AccountBasedExpenseLineDetail']['TaxCodeRef']['value']);
    }

    public function test_lines_that_do_not_add_up_are_warned_not_silently_sent(): void
    {
        $lines = $this->lines(); $lines[1]['net_amount'] = 10.00;
        $r = QboPushPlanner::purchaseFromExpense($this->expense(), $lines, $this->ctx());
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('Lines add to 110.00 but the expense net is 150.00', $r['warnings'][0]);
    }

    public function test_an_invoice_becomes_sales_item_lines_with_the_crm_number_in_the_note(): void
    {
        $inv = ['id' => 55, 'invoice_number' => 'INV-2026-0055', 'issue_date' => '2026-04-01', 'due_date' => '2026-04-30', 'status' => 'sent', 'subtotal' => 200.00, 'tax_rate' => 0.05, 'tax_amount' => 10.00, 'total' => 210.00];
        $items = [['description' => 'Spring clean-up', 'quantity' => 2, 'unit_price' => 100.00, 'amount' => 200.00, 'service_date' => '2026-03-28']];
        $r = QboPushPlanner::invoiceFromCrm($inv, $items, ['book_close_date' => '2025-12-31', 'customer_qbo_id' => '9', 'item_qbo_id' => '11', 'tax_gst' => '2', 'tax_gst_pst' => '5', 'tax_exempt' => '3', 'custom_txn_numbers' => false]);
        $this->assertTrue($r['ok']);
        $p = $r['payload'];
        $this->assertSame(['value' => '9'], $p['CustomerRef']);
        $this->assertArrayNotHasKey('DocNumber', $p, 'QBO numbers it when CustomTxnNumbers is off');
        $this->assertSame('CRM INV-2026-0055', $p['PrivateNote']);
        $this->assertSame('2026-04-30', $p['DueDate']);
        $l = $p['Line'][0];
        $this->assertSame('SalesItemLineDetail', $l['DetailType']);
        $this->assertSame(200.0, $l['Amount']);
        $this->assertSame(2.0, $l['SalesItemLineDetail']['Qty']);
        $this->assertSame(100.0, $l['SalesItemLineDetail']['UnitPrice']);
        $this->assertSame('2', $l['SalesItemLineDetail']['TaxCodeRef']['value']);
        $this->assertSame('2026-03-28', $l['SalesItemLineDetail']['ServiceDate']);
        $this->assertStringContainsString('numbers the invoice itself', $r['warnings'][0]);

        $r2 = QboPushPlanner::invoiceFromCrm($inv + ['tax_rate' => 0.12], $items, ['book_close_date' => null, 'customer_qbo_id' => '9', 'item_qbo_id' => '11', 'tax_gst' => '2', 'tax_gst_pst' => '5', 'tax_exempt' => '3', 'custom_txn_numbers' => true]);
        $this->assertSame('INV-2026-0055', $r2['payload']['DocNumber']);
        $this->assertFalse(QboPushPlanner::invoiceFromCrm(['issue_date' => '2026-04-01', 'status' => 'draft'], [], ['customer_qbo_id' => '9', 'item_qbo_id' => '11'])['ok']);
    }

    public function test_a_payment_links_to_its_invoice_and_lands_in_the_chosen_bank(): void
    {
        $alloc = ['id' => 900, 'amount' => 210.00, 'payment_date' => '2026-04-20', 'method' => 'etransfer', 'reference' => 'ET-9981'];
        $r = QboPushPlanner::paymentFromAllocation($alloc, ['book_close_date' => '2025-12-31', 'customer_qbo_id' => '9', 'invoice_qbo_id' => '345', 'deposit_to_qbo_id' => '35']);
        $this->assertTrue($r['ok']);
        $p = $r['payload'];
        $this->assertSame(210.0, $p['TotalAmt']);
        $this->assertSame([['TxnId' => '345', 'TxnType' => 'Invoice']], $p['Line'][0]['LinkedTxn']);
        $this->assertSame(['value' => '35'], $p['DepositToAccountRef']);
        $this->assertSame('ET-9981', $p['PaymentRefNum']);
        $noInv = QboPushPlanner::paymentFromAllocation($alloc, ['customer_qbo_id' => '9', 'invoice_qbo_id' => null]);
        $this->assertStringContainsString('invoice is not in QuickBooks yet', $noInv['blocked']);
        $undeposited = QboPushPlanner::paymentFromAllocation($alloc, ['customer_qbo_id' => '9', 'invoice_qbo_id' => '345']);
        $this->assertArrayNotHasKey('DepositToAccountRef', $undeposited['payload'], 'omitted → Undeposited Funds, grouped by a Deposit later');
    }

    public function test_the_content_hash_ignores_key_order_and_the_requestid_fits_intuits_50_chars(): void
    {
        $a = ['PaymentType' => 'Cash', 'Line' => [['Amount' => 1, 'DetailType' => 'X']]];
        $b = ['Line' => [['DetailType' => 'X', 'Amount' => 1]], 'PaymentType' => 'Cash'];
        $this->assertSame(QboPushPlanner::hash($a), QboPushPlanner::hash($b));
        $this->assertNotSame(QboPushPlanner::hash($a), QboPushPlanner::hash($a + ['TxnDate' => '2026-01-01']));
        $id = QboPushPlanner::requestId('expense', 123456789, sha1('x'));
        $this->assertLessThanOrEqual(50, strlen($id));
        $this->assertStringStartsWith('expense-123456789-', $id);
    }
}
