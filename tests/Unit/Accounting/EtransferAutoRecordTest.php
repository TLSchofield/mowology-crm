<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for EtransferInboxService::autoRecordFullyCertain() — the narrow
 * "100% certain" auto-record bar (hard identity match + high-confidence bank
 * deposit + exact amount). recordPayment() itself has no unit coverage
 * anywhere in this suite (it's a full DB integration verified on production,
 * per its own docblock) so it's stubbed via a partial mock here — this test
 * only exercises the candidate query + exact-amount gate + result rollup,
 * not the actual payment recording.
 */
class EtransferAutoRecordTest extends TestCase
{
    private function serviceWith(array $candidateRows, ?callable $recordPaymentStub = null): EtransferInboxService
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($candidateRows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $svc = $this->getMockBuilder(EtransferInboxService::class)
            ->setConstructorArgs([$pdo])
            ->onlyMethods(['recordPayment'])
            ->getMock();

        if ($recordPaymentStub) {
            $svc->method('recordPayment')->willReturnCallback($recordPaymentStub);
        }

        return $svc;
    }

    public function testNoCandidatesRecordsNothing(): void
    {
        $svc = $this->serviceWith([]);
        $result = $svc->autoRecordFullyCertain(1);
        $this->assertSame(0, $result['checked']);
        $this->assertSame(0, $result['recorded']);
    }

    public function testExactAmountMatchCallsRecordPaymentAndCountsSuccess(): void
    {
        $svc = $this->serviceWith(
            [['id' => 5, 'amount' => 262.50, 'matched_invoice_id' => 236, 'invoice_balance' => 262.50]],
            fn() => ['ok' => true, 'message' => 'Recorded.']
        );

        $result = $svc->autoRecordFullyCertain(1);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['recorded']);
        $this->assertSame([5], $result['recorded_ids']);
        $this->assertEmpty($result['skipped']);
    }

    public function testAmountMismatchSkipsWithoutCallingRecordPayment(): void
    {
        // Even one cent off the invoice balance is enough ambiguity (GST,
        // rounding, partial payment) that this must NOT be auto-recorded.
        $svc = $this->serviceWith(
            [['id' => 6, 'amount' => 100.01, 'matched_invoice_id' => 300, 'invoice_balance' => 100.00]]
        );
        $svc->expects($this->never())->method('recordPayment');

        $result = $svc->autoRecordFullyCertain(1);

        $this->assertSame(0, $result['recorded']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(6, $result['skipped'][0]['id']);
    }

    public function testRecordPaymentFailureIsReportedAsSkipped(): void
    {
        $svc = $this->serviceWith(
            [['id' => 7, 'amount' => 50.00, 'matched_invoice_id' => 400, 'invoice_balance' => 50.00]],
            fn() => ['ok' => false, 'message' => 'Already recorded']
        );

        $result = $svc->autoRecordFullyCertain(1);

        $this->assertSame(0, $result['recorded']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('Already recorded', $result['skipped'][0]['reason']);
    }
}
