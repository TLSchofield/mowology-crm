<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for InvoiceReconciliationService candidate scoring.
 *
 * Mocks PDO so the two read queries (loadInvoicesForScoring, loadUnmatchedDeposits)
 * return canned rows — exercising the amount + date + description blended scoring
 * without a database. The transactional attach()/detach() path is verified on
 * production (see migration runner + plan verification steps).
 *
 * Covers:
 *  - exact amount + e-Transfer sender name → high confidence, both reasons present
 *  - invoice number appearing in the memo boosts confidence
 *  - unrelated amounts are filtered out (below threshold)
 *  - deposit larger than the balance is flagged covers_more (split scenario)
 *  - candidates returned high→low by confidence
 */
class InvoiceReconciliationServiceTest extends TestCase
{
    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'id'                       => 1,
            'invoice_number'           => 'INV-2026-0036',
            'balance_due'              => 364.65,
            'total'                    => 364.65,
            'amount_paid'              => 0,
            'invoice_date'             => '2026-05-28',
            'due_date'                 => '2026-05-31',
            'status'                   => 'overdue',
            'contact_id'               => 5,
            'plan_id'                  => null,
            'stripe_payment_intent_id' => null,
            'stripe_charge_id'         => null,
            'contact_name'             => 'Alexandra Bee',
            'company_name'             => null,
            'property_name'            => null,
        ], $overrides);
    }

    /** Build a service whose two read queries return the given rows. */
    private function serviceWith(array $invoiceRows, array $depositRows): InvoiceReconciliationService
    {
        $invoiceStmt = $this->createMock(PDOStatement::class);
        $invoiceStmt->method('execute')->willReturn(true);
        $invoiceStmt->method('fetchAll')->willReturn($invoiceRows);

        $depositStmt = $this->createMock(PDOStatement::class);
        $depositStmt->method('bindValue')->willReturn(true);
        $depositStmt->method('execute')->willReturn(true);
        $depositStmt->method('fetchAll')->willReturn($depositRows);

        // Paid e-Transfer lookup (likely_recorded signal) → nothing recorded
        $paidStmt = $this->createMock(PDOStatement::class);
        $paidStmt->method('execute')->willReturn(true);
        $paidStmt->method('fetchAll')->willReturn([]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($invoiceStmt, $depositStmt, $paidStmt) {
                if (strpos($sql, "payment_method = 'e_transfer'") !== false) return $paidStmt;
                return strpos($sql, 'FROM invoices') !== false ? $invoiceStmt : $depositStmt;
            }
        );

        return new InvoiceReconciliationService($pdo);
    }

    public function testExactAmountAndSenderNameScoreHighWithReasons(): void
    {
        $svc = $this->serviceWith(
            [$this->invoice()],
            [[
                'id'               => 101,
                'transaction_date' => '2026-05-30',
                'amount'           => 364.65,
                'description'      => 'INTERAC E-TRF 1234 A BEE',
                'bank_account'     => 'Vancity',
            ]]
        );

        $candidates = $svc->candidatesForInvoice(1);

        $this->assertCount(1, $candidates);
        $this->assertSame(101, $candidates[0]['tx_id']);
        $this->assertGreaterThanOrEqual(90, $candidates[0]['confidence']);
        $this->assertContains('Exact amount', $candidates[0]['reasons']);
        $this->assertTrue(
            (bool)array_filter($candidates[0]['reasons'], fn($r) => str_starts_with($r, 'Memo names')),
            'Expected a "Memo names ..." reason from the sender-name match'
        );
    }

    public function testInvoiceNumberInMemoIsRecognised(): void
    {
        $svc = $this->serviceWith(
            [$this->invoice(['contact_name' => 'Unrelated Person'])],
            [[
                'id'               => 102,
                'transaction_date' => '2026-06-10',
                'amount'           => 364.65,
                'description'      => 'BILL PAYMENT INV-2026-0036',
                'bank_account'     => 'Vancity',
            ]]
        );

        $candidates = $svc->candidatesForInvoice(1);

        $this->assertCount(1, $candidates);
        $this->assertContains('Invoice # in memo', $candidates[0]['reasons']);
    }

    public function testUnrelatedAmountIsNotSurfaced(): void
    {
        $svc = $this->serviceWith(
            [$this->invoice()],
            [[
                'id'               => 103,
                'transaction_date' => '2026-05-30',
                'amount'           => 50.00, // far below balance and < 50% of it
                'description'      => 'INTERAC E-TRF SMITH',
                'bank_account'     => 'Vancity',
            ]]
        );

        $this->assertSame([], $svc->candidatesForInvoice(1));
    }

    public function testLargerDepositIsFlaggedAsCoveringMore(): void
    {
        $svc = $this->serviceWith(
            [$this->invoice()],
            [[
                'id'               => 104,
                'transaction_date' => '2026-05-30',
                'amount'           => 999.00, // covers this invoice and then some
                'description'      => 'INTERAC E-TRF A BEE',
                'bank_account'     => 'Vancity',
            ]]
        );

        $candidates = $svc->candidatesForInvoice(1);

        $this->assertCount(1, $candidates);
        $this->assertTrue($candidates[0]['covers_more']);
        $this->assertEqualsWithDelta(364.65, $candidates[0]['suggested_amount'], 0.001);
        $this->assertContains('Deposit covers this invoice', $candidates[0]['reasons']);
    }

    public function testCandidatesAreSortedByConfidenceDescending(): void
    {
        $svc = $this->serviceWith(
            [$this->invoice()],
            [
                [ // weak: exact amount, no name, distant date
                    'id' => 201, 'transaction_date' => '2026-06-15',
                    'amount' => 364.65, 'description' => 'DEPOSIT', 'bank_account' => 'Vancity',
                ],
                [ // strong: exact amount + name + close date
                    'id' => 202, 'transaction_date' => '2026-05-30',
                    'amount' => 364.65, 'description' => 'INTERAC E-TRF A BEE', 'bank_account' => 'Vancity',
                ],
            ]
        );

        $candidates = $svc->candidatesForInvoice(1);

        $this->assertGreaterThanOrEqual(2, count($candidates));
        $this->assertSame(202, $candidates[0]['tx_id'], 'Strongest match should be first');
        $this->assertGreaterThan($candidates[1]['confidence'], $candidates[0]['confidence'] + 1);
    }

    // ── correctManualPayment() guard clauses ────────────────────────────────
    // These validate before any DB write, so a bare PDO mock (no expectations)
    // is enough — a real write would need the full lock/update transaction,
    // which is verified on production like attach()/detach() are (per this
    // file's own docblock).

    public function testCorrectManualPaymentRejectsEmptyReason(): void
    {
        $svc = new InvoiceReconciliationService($this->createMock(PDO::class));
        $this->expectException(InvalidArgumentException::class);
        $svc->correctManualPayment(28, 378.16, '   ', 1);
    }

    public function testCorrectManualPaymentRejectsNegativeAmount(): void
    {
        $svc = new InvoiceReconciliationService($this->createMock(PDO::class));
        $this->expectException(InvalidArgumentException::class);
        $svc->correctManualPayment(28, -10.00, 'Typo fix', 1);
    }

    public function testCorrectManualPaymentRefusesWhenAllocationsExist(): void
    {
        // Real scenario this guards against: an invoice reconciled via
        // attach()/e-Transfer recording (invoice_payment_allocations rows
        // exist) must be corrected through detach()/unmerge(), not by
        // editing amount_paid directly — that would desync the allocation
        // ledger against the bank deposit it's tied to.
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(1); // 1 existing allocation row

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $svc = new InvoiceReconciliationService($pdo);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unmerge/detach');
        $svc->correctManualPayment(231, 378.16, 'Should be refused', 1);
    }
    public function testCandidateCarriesLikelyRecordedSignalWhenSamePayerPaymentsSumToDeposit(): void
    {
        // Real scenario (John Hughes, Jul 2026): six $50.40 invoices were recorded
        // as paid by e-Transfer by hand; the $302.40 bank deposit was imported later
        // with no allocation rows, so the matcher kept offering it forever.
        $invoiceStmt = $this->createMock(PDOStatement::class);
        $invoiceStmt->method('execute')->willReturn(true);
        $invoiceStmt->method('fetchAll')->willReturn([$this->invoice([
            'balance_due' => 50.40, 'total' => 50.40, 'contact_name' => 'John Ellen Hughes',
            'invoice_date' => '2026-07-18', 'due_date' => '2026-07-31',
        ])]);

        $depositStmt = $this->createMock(PDOStatement::class);
        $depositStmt->method('bindValue')->willReturn(true);
        $depositStmt->method('execute')->willReturn(true);
        $depositStmt->method('fetchAll')->willReturn([[
            'id' => 24438, 'transaction_date' => '2026-07-20', 'amount' => 302.40,
            'description' => 'e-Transfer credit Ref 20260720111256669848 JOHN HUGHES', 'bank_account' => 'Vancity',
        ]]);

        // EtransferInboxService::findLikelyDuplicatePayment reads paid e-Transfer invoices
        $paidStmt = $this->createMock(PDOStatement::class);
        $paidStmt->method('execute')->willReturn(true);
        $paidStmt->method('fetchAll')->willReturn(array_map(fn($n) => [
            'invoice_number' => 'INV-2026-0' . $n, 'amount_paid' => 50.40,
            'pay_day' => '2026-07-31', 'payer_name' => 'John Ellen Hughes',
        ], [116, 117, 134, 175, 204, 249]));

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($invoiceStmt, $depositStmt, $paidStmt) {
            if (strpos($sql, "payment_method = 'e_transfer'") !== false) return $paidStmt;
            return strpos($sql, 'FROM invoices') !== false ? $invoiceStmt : $depositStmt;
        });

        $candidates = (new InvoiceReconciliationService($pdo))->candidatesForInvoice(1);

        $this->assertCount(1, $candidates);
        $this->assertNotNull($candidates[0]['likely_recorded']);
        $this->assertSame('2026-07-31', $candidates[0]['likely_recorded']['pay_date']);
        $this->assertCount(6, $candidates[0]['likely_recorded']['invoice_numbers']);
    }

    public function testLikelyRecordedIsNullWhenNoMatchingPayments(): void
    {
        $svc = $this->serviceWith(
            [$this->invoice()],
            [['id' => 101, 'transaction_date' => '2026-05-30', 'amount' => 364.65,
              'description' => 'INTERAC E-TRF ALEXANDRA BEE', 'bank_account' => 'Vancity']]
        );
        $candidates = $svc->candidatesForInvoice(1);
        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]['likely_recorded']);
    }

    public function testMarkDepositAlreadyRecordedRefusesWhenAllocationsExist(): void
    {
        $depStmt = $this->createMock(PDOStatement::class);
        $depStmt->method('execute')->willReturn(true);
        $depStmt->method('fetch')->willReturn(['id' => 5, 'type' => 'income', 'amount' => 302.40,
            'transaction_date' => '2026-07-20', 'description' => 'x', 'account_id' => 24]);
        $allocStmt = $this->createMock(PDOStatement::class);
        $allocStmt->method('execute')->willReturn(true);
        $allocStmt->method('fetchColumn')->willReturn(50.40);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(fn(string $sql) =>
            strpos($sql, 'invoice_payment_allocations') !== false ? $allocStmt : $depStmt);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('detach');
        (new InvoiceReconciliationService($pdo))->markDepositAlreadyRecorded(5, 1);
    }

    public function testMarkDepositAlreadyRecordedRefusesUnknownOrReconciledDeposit(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $this->expectException(RuntimeException::class);
        (new InvoiceReconciliationService($pdo))->markDepositAlreadyRecorded(99, 1);
    }

    public function testMarkDepositAlreadyRecordedFlipsRowToTransfer(): void
    {
        $depStmt = $this->createMock(PDOStatement::class);
        $depStmt->method('execute')->willReturn(true);
        $depStmt->method('fetch')->willReturn(['id' => 5, 'type' => 'income', 'amount' => 302.40,
            'transaction_date' => '2026-07-20', 'description' => 'x', 'account_id' => 24]);
        $allocStmt = $this->createMock(PDOStatement::class);
        $allocStmt->method('execute')->willReturn(true);
        $allocStmt->method('fetchColumn')->willReturn(0);
        $writes = [];
        $writeStmt = $this->createMock(PDOStatement::class);
        $writeStmt->method('execute')->willReturnCallback(function ($p) use (&$writes) { $writes[] = $p; return true; });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($depStmt, $allocStmt, $writeStmt) {
            if (strpos($sql, 'invoice_payment_allocations') !== false) return $allocStmt;
            if (stripos($sql, 'UPDATE') === 0 || strpos($sql, 'UPDATE ') !== false) return $writeStmt;
            return $depStmt;
        });

        $result = (new InvoiceReconciliationService($pdo))->markDepositAlreadyRecorded(5, 7, 'used up');

        $this->assertSame(5, $result['transaction_id']);
        $this->assertEqualsWithDelta(302.40, $result['amount'], 0.001);
        $this->assertNotEmpty($writes);
        $this->assertSame('7', $writes[0][0]);                       // matched_by = user id
        $this->assertStringContainsString('used up', $writes[0][1]); // note appended
    }
}
