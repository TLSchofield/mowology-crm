<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ClientCreditServiceTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeStmt(
        mixed $fetchReturn    = false,
        mixed $fetchAllReturn = [],
        mixed $fetchColReturn = 0
    ): PDOStatement {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetch')->willReturn($fetchReturn);
        $s->method('fetchAll')->willReturn($fetchAllReturn);
        $s->method('fetchColumn')->willReturn($fetchColReturn);
        return $s;
    }

    private function baseInvoice(array $overrides = []): array
    {
        return array_merge([
            'id'          => 236,
            'client_id'   => 55,
            'contact_id'  => 12,
            'company_id'  => null,
            'status'      => 'sent',
            'amount_paid' => '0.00',
            'balance_due' => '75.00',
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getBalance
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function get_balance_sums_the_ledger(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false, [], 425.00));

        $svc = (new ClientCreditService($db))->withoutLedger();
        $this->assertSame(425.00, $svc->getBalance(55));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // addDeposit
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function add_deposit_saves_nothing_when_the_named_bank_line_cannot_move(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE client_credits (id INTEGER PRIMARY KEY, client_id INT, type TEXT, amount REAL, source_note TEXT, created_by INT, bank_transaction_id INT)");
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT)");
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, type TEXT, reference_type TEXT, account_id INT, amount REAL)");
        $db->exec("INSERT INTO chart_of_accounts VALUES (7, '2160')");
        $db->exec("INSERT INTO accounting_transactions VALUES (40, 'transfer', 'bank_import', 7, 500)");   // already moved

        $svc = (new ClientCreditService($db))->withoutLedger();
        try {
            $svc->addDeposit(55, 500.0, 'Advance cheque', 1, 40);
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing was recorded', $e->getMessage());
        }
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM client_credits")->fetchColumn());

        $db->exec("INSERT INTO accounting_transactions VALUES (41, 'income', 'bank_import', 99, 500)");
        $id = $svc->addDeposit(55, 500.0, 'Advance cheque', 1, 41);
        $this->assertSame(41, (int)$db->query("SELECT bank_transaction_id FROM client_credits WHERE id = $id")->fetchColumn());
        $this->assertSame('transfer', $db->query("SELECT type FROM accounting_transactions WHERE id = 41")->fetchColumn());
    }

    /** @test */
    public function add_deposit_rejects_non_positive_amount(): void
    {
        $db  = $this->createMock(PDO::class);
        $svc = (new ClientCreditService($db))->withoutLedger();
        $this->expectException(InvalidArgumentException::class);
        $svc->addDeposit(55, 0, 'Advance payment', 1);
    }

    /** @test */
    public function add_deposit_inserts_and_returns_new_id(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt());
        $db->method('lastInsertId')->willReturn('9001');

        $svc = (new ClientCreditService($db))->withoutLedger();
        $this->assertSame(9001, $svc->addDeposit(55, 500.00, 'Advance payment recorded', 1));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // applyToInvoice
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function apply_to_invoice_throws_when_invoice_not_found(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc = (new ClientCreditService($db))->withoutLedger();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $svc->applyToInvoice(999, 1);
    }

    /** @test */
    public function apply_to_invoice_throws_when_already_paid(): void
    {
        $invoice = $this->baseInvoice(['status' => 'paid']);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($invoice));

        $svc = (new ClientCreditService($db))->withoutLedger();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already paid/i');
        $svc->applyToInvoice(236, 1);
    }

    /** @test */
    public function apply_to_invoice_throws_when_client_unresolvable(): void
    {
        $invoice = $this->baseInvoice(['client_id' => null, 'contact_id' => null, 'company_id' => null]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($invoice));

        $svc = (new ClientCreditService($db))->withoutLedger();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not resolve/i');
        $svc->applyToInvoice(236, 1);
    }

    /** @test */
    public function apply_to_invoice_caps_at_invoice_balance_due(): void
    {
        // Plenty of credit available ($500), invoice only owes $75 — apply just enough
        // to zero it out and leave the rest of the credit for future invoices.
        $invoice = $this->baseInvoice(['balance_due' => '75.00']);

        $invoiceStmt  = $this->makeStmt($invoice);
        $balanceStmt1 = $this->makeStmt(false, [], 500.00);
        $insertStmt   = $this->makeStmt();
        $updateStmt   = $this->makeStmt();
        $balanceStmt2 = $this->makeStmt(false, [], 425.00);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $invoiceStmt, $balanceStmt1, $insertStmt, $updateStmt, $balanceStmt2
        );

        $svc    = (new ClientCreditService($db))->withoutLedger();
        $result = $svc->applyToInvoice(236, 1);

        $this->assertSame(75.0, $result['applied']);
        $this->assertSame('paid', $result['invoice_status']);
        $this->assertSame(0.0, $result['invoice_balance_due']);
        $this->assertSame(425.00, $result['remaining_credit']);
        $this->assertSame(55, $result['client_id']);
    }

    /** @test */
    public function apply_to_invoice_caps_at_available_balance_when_lower_than_balance_due(): void
    {
        // Only $40 of credit left, but the invoice owes $75 — apply what's available,
        // leave the rest of the invoice outstanding (status → partial, not paid).
        $invoice = $this->baseInvoice(['balance_due' => '75.00']);

        $invoiceStmt  = $this->makeStmt($invoice);
        $balanceStmt1 = $this->makeStmt(false, [], 40.00);
        $insertStmt   = $this->makeStmt();
        $updateStmt   = $this->makeStmt();
        $balanceStmt2 = $this->makeStmt(false, [], 0.00);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $invoiceStmt, $balanceStmt1, $insertStmt, $updateStmt, $balanceStmt2
        );

        $svc    = (new ClientCreditService($db))->withoutLedger();
        $result = $svc->applyToInvoice(236, 1);

        $this->assertSame(40.0, $result['applied']);
        $this->assertSame('partial', $result['invoice_status']);
        $this->assertSame(35.00, $result['invoice_balance_due']);
    }

    /** @test */
    public function apply_to_invoice_throws_when_no_credit_available(): void
    {
        $invoice = $this->baseInvoice();

        $invoiceStmt  = $this->makeStmt($invoice);
        $balanceStmt1 = $this->makeStmt(false, [], 0.00);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($invoiceStmt, $balanceStmt1);

        $svc = (new ClientCreditService($db))->withoutLedger();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no credit available/i');
        $svc->applyToInvoice(236, 1);
    }

    /** @test */
    public function a_prepayment_is_tied_to_a_bank_line_only_when_the_match_is_unique(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false, [18388]));
        $this->assertSame(18388, (new ClientCreditService($db))->findDepositLine(529.20));

        $db2 = $this->createMock(PDO::class);
        $db2->method('prepare')->willReturn($this->makeStmt(false, [18388, 18400]));
        $this->assertNull((new ClientCreditService($db2))->findDepositLine(529.20));
    }

    /** @test */
    public function credit_is_used_on_send_only_when_there_is_both_a_balance_and_credit(): void
    {
        $this->assertTrue(ClientCreditService::shouldApplyOnSend('draft', 63.00, 186.49));
        $this->assertTrue(ClientCreditService::shouldApplyOnSend('sent', 63.00, 10.00), 'part of it');
        $this->assertFalse(ClientCreditService::shouldApplyOnSend('draft', 63.00, 0.0));
        $this->assertFalse(ClientCreditService::shouldApplyOnSend('paid', 0.0, 186.49));
        $this->assertFalse(ClientCreditService::shouldApplyOnSend('cancelled', 63.00, 186.49));
    }
}
