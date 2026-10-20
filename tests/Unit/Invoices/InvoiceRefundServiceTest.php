<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for InvoiceRefundService.
 *
 * The pure rules (refundable ceiling, cents, net ledger income) are tested
 * directly. refund() is exercised with a mocked PDO that records every executed
 * statement and a fake Stripe client — no network, no database. Stripe being
 * called (or not) is the important assertion: no refund may reach Stripe unless
 * every check passed, and a failure at Stripe must leave invoices untouched.
 */
class InvoiceRefundServiceTest extends TestCase
{
    // ── pure rules ────────────────────────────────────────────────────────

    public function testRefundableIsPaidMinusRefunded(): void
    {
        $this->assertSame(78.70, InvoiceRefundService::refundable(157.40, 78.70));
        $this->assertSame(0.0, InvoiceRefundService::refundable(78.70, 78.70));
        $this->assertSame(0.0, InvoiceRefundService::refundable(10.00, 25.00));
    }

    public function testRefundableNeverExceedsWhatStripeCharged(): void
    {
        // Invoice shows 157.40 paid but only 78.70 went through Stripe (rest by e-Transfer).
        $this->assertSame(78.70, InvoiceRefundService::refundable(157.40, 0.0, 78.70));
        $this->assertSame(50.00, InvoiceRefundService::refundable(157.40, 28.70, 78.70));
    }

    public function testAmountToCentsRoundsAndValidates(): void
    {
        $this->assertSame(7870, InvoiceRefundService::amountToCents(78.70, 78.70));
        $this->assertSame(1999, InvoiceRefundService::amountToCents(19.99, 78.70));
        $this->assertSame(7870, InvoiceRefundService::amountToCents(78.701, 78.70)); // sub-cent noise
    }

    public function testAmountToCentsRejectsZeroNegativeAndOverRefund(): void
    {
        foreach ([0.0, -5.0, 78.71] as $bad) {
            try {
                InvoiceRefundService::amountToCents($bad, 78.70);
                $this->fail("expected InvalidArgumentException for {$bad}");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testNetIncomeUnchangedWhenNothingRefunded(): void
    {
        // Same as AccountingService::syncFromInvoices: GST in proportion to what was paid.
        $this->assertSame([78.70, 3.75], InvoiceRefundService::netIncome(78.70, 0.0, 3.75, 78.70));
        $this->assertSame([40.00, 1.91], InvoiceRefundService::netIncome(40.00, 0.0, 3.75, 78.70)); // part-paid
    }

    public function testNetIncomeScalesGstWithRefund(): void
    {
        // $78.70 paid incl. $3.75 GST; refund half => income 39.35, GST 1.88 (rounded).
        [$amount, $gst] = InvoiceRefundService::netIncome(78.70, 39.35, 3.75, 78.70);
        $this->assertSame(39.35, $amount);
        $this->assertSame(1.88, $gst);
    }

    public function testNetIncomeFullRefundIsZero(): void
    {
        $this->assertSame([0.0, 0.0], InvoiceRefundService::netIncome(78.70, 78.70, 3.75, 78.70));
    }

    // ── refund() orchestration ────────────────────────────────────────────

    /** Stripe invoice that is paid and has nothing refunded yet. */
    private function invoiceRow(array $over = []): array
    {
        return array_merge([
            'id'                       => 420,
            'invoice_number'           => 'INV-2026-0410',
            'total'                    => 78.70,
            'tax_amount'               => 3.75,
            'amount_paid'              => 78.70,
            'amount_refunded'          => 0.00,
            'payment_method'           => 'stripe',
            'stripe_payment_intent_id' => 'pi_test_123',
        ], $over);
    }

    /**
     * @param array $opts invoice (row|null), inFlight (int), charged (int cents|null), refundedAfter (float)
     * @return array{0:PDO,1:ArrayObject} the PDO mock and the log of executed [sql, params]
     */
    private function fakeDb(array $opts = []): array
    {
        $invoice       = array_key_exists('invoice', $opts) ? $opts['invoice'] : $this->invoiceRow();
        $inFlight      = $opts['inFlight'] ?? 0;
        $charged       = array_key_exists('charged', $opts) ? $opts['charged'] : 7870;
        $refundedAfter = $opts['refundedAfter'] ?? 78.70;
        $log           = new ArrayObject();

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('lastInsertId')->willReturn('5');
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($invoice, $inFlight, $charged, $refundedAfter, $log) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturnCallback(function ($params = null) use ($sql, $log) {
                    $log->append([$sql, $params]);
                    return true;
                });
                $stmt->method('fetch')->willReturnCallback(function () use ($sql, $invoice, $refundedAfter) {
                    if (str_contains($sql, 'FOR UPDATE')) return $invoice ?: false;
                    // syncIncomeRow's read of the invoice after amount_refunded was bumped
                    if (str_contains($sql, 'COALESCE(amount_refunded')) {
                        return ['paid' => 78.70, 'refunded' => $refundedAfter, 'tax' => 3.75, 'total' => 78.70];
                    }
                    return false;
                });
                $stmt->method('fetchColumn')->willReturnCallback(function () use ($sql, $inFlight, $charged, $refundedAfter) {
                    if (str_contains($sql, 'amount_cents'))    return $charged ?? false;
                    if (str_contains($sql, "status = 'pending'")) return $inFlight;
                    if (str_contains($sql, 'SELECT amount_refunded')) return $refundedAfter;
                    return false;
                });
                return $stmt;
            }
        );
        return [$pdo, $log];
    }

    /** Fake Stripe client recording create() calls; optionally throws. */
    private function fakeStripe(?Throwable $throws = null): object
    {
        return new class($throws) {
            public array $calls = [];
            public object $refunds;
            public function __construct(?Throwable $throws)
            {
                $outer = $this;
                $this->refunds = new class($outer, $throws) {
                    public function __construct(private object $outer, private ?Throwable $throws) {}
                    public function create(array $params, array $opts = []): object
                    {
                        $this->outer->calls[] = [$params, $opts];
                        if ($this->throws) throw $this->throws;
                        return (object) ['id' => 're_test_999'];
                    }
                };
            }
        };
    }

    private function service(PDO $pdo, object $stripe): InvoiceRefundService
    {
        $svc = new InvoiceRefundService($pdo);
        $svc->setStripeClientFactory(fn() => $stripe);
        return $svc;
    }

    private function sqlLog(ArrayObject $log): string
    {
        return implode("\n", array_map(fn($e) => $e[0], $log->getArrayCopy()));
    }

    public function testSuccessfulRefundCallsStripeOnceWithIdempotencyKeyAndRecordsIt(): void
    {
        [$pdo, $log] = $this->fakeDb();
        $stripe = $this->fakeStripe();

        $result = $this->service($pdo, $stripe)->refund(420, 78.70, 'Billed twice for Sep 22', 7);

        $this->assertCount(1, $stripe->calls);
        [$params, $opts] = $stripe->calls[0];
        $this->assertSame('pi_test_123', $params['payment_intent']);
        $this->assertSame(7870, $params['amount']);
        $this->assertSame('mw-refund-5', $opts['idempotency_key']);
        $this->assertSame('re_test_999', $result['stripe_refund_id']);
        $this->assertSame(78.70, $result['amount']);

        $sql = $this->sqlLog($log);
        $this->assertStringContainsString('INSERT INTO invoice_refunds', $sql);
        $this->assertStringContainsString("status = 'succeeded'", $sql);
        $this->assertStringContainsString('amount_refunded = amount_refunded +', $sql);
        // Full refund: the cash-basis income row is removed.
        $this->assertStringContainsString('DELETE FROM accounting_transactions', $sql);
    }

    public function testPartialRefundUpdatesIncomeRowInsteadOfDeleting(): void
    {
        // After the partial refund the invoice reads paid 78.70, refunded 39.35.
        [$pdo, $log] = $this->fakeDb(['refundedAfter' => 39.35]);
        $stripe = $this->fakeStripe();

        $this->service($pdo, $stripe)->refund(420, 39.35, 'Half back', 7);

        $this->assertSame(3935, $stripe->calls[0][0]['amount']);
        $sql = $this->sqlLog($log);
        $this->assertStringContainsString('UPDATE accounting_transactions', $sql);
        $this->assertStringNotContainsString('DELETE FROM accounting_transactions', $sql);
        // Ledger row now carries net income 39.35 with GST scaled to 1.88.
        $update = array_values(array_filter($log->getArrayCopy(), fn($e) => str_contains($e[0], 'UPDATE accounting_transactions')))[0];
        $this->assertSame([39.35, 1.88, 420], $update[1]);
    }

    public function testStripeFailureLeavesInvoiceUntouchedAndMarksRowFailed(): void
    {
        [$pdo, $log] = $this->fakeDb();
        $stripe = $this->fakeStripe(new RuntimeException('card closed'));

        try {
            $this->service($pdo, $stripe)->refund(420, 78.70, 'Billed twice', 7);
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('card closed', $e->getMessage());
        }

        $sql = $this->sqlLog($log);
        $this->assertStringContainsString("status = 'failed'", $sql);
        $this->assertStringNotContainsString('UPDATE invoices', $sql);
        $this->assertStringNotContainsString('accounting_transactions', $sql);
    }

    public function testOverRefundNeverReachesStripe(): void
    {
        [$pdo] = $this->fakeDb();
        $stripe = $this->fakeStripe();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service($pdo, $stripe)->refund(420, 100.00, 'Too much', 7);
        } finally {
            $this->assertCount(0, $stripe->calls);
        }
    }

    public function testAlreadyRefundedInvoiceCannotBeRefundedAgain(): void
    {
        [$pdo] = $this->fakeDb(['invoice' => $this->invoiceRow(['amount_refunded' => 78.70])]);
        $stripe = $this->fakeStripe();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service($pdo, $stripe)->refund(420, 1.00, 'Again', 7);
        } finally {
            $this->assertCount(0, $stripe->calls);
        }
    }

    public function testNonStripeInvoiceIsRefused(): void
    {
        [$pdo] = $this->fakeDb(['invoice' => $this->invoiceRow(['payment_method' => 'etransfer', 'stripe_payment_intent_id' => null])]);
        $stripe = $this->fakeStripe();

        $this->expectException(RuntimeException::class);
        try {
            $this->service($pdo, $stripe)->refund(420, 78.70, 'Nope', 7);
        } finally {
            $this->assertCount(0, $stripe->calls);
        }
    }

    public function testRefundInFlightBlocksSecondAttempt(): void
    {
        [$pdo] = $this->fakeDb(['inFlight' => 1]);
        $stripe = $this->fakeStripe();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already in progress');
        try {
            $this->service($pdo, $stripe)->refund(420, 78.70, 'Double click', 7);
        } finally {
            $this->assertCount(0, $stripe->calls);
        }
    }

    public function testReasonIsRequired(): void
    {
        [$pdo] = $this->fakeDb();
        $stripe = $this->fakeStripe();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service($pdo, $stripe)->refund(420, 78.70, '   ', 7);
        } finally {
            $this->assertCount(0, $stripe->calls);
        }
    }

    public function testMissingInvoiceIsRefused(): void
    {
        [$pdo] = $this->fakeDb(['invoice' => null]);
        $stripe = $this->fakeStripe();

        $this->expectException(RuntimeException::class);
        try {
            $this->service($pdo, $stripe)->refund(999, 5.00, 'Ghost', 7);
        } finally {
            $this->assertCount(0, $stripe->calls);
        }
    }
}
