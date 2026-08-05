<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for EtransferInboxService::matchBankTransaction() — the bank↔email
 * leg of three-way reconciliation (bank record + invoice + email).
 *
 * Mocks PDO so the candidate query returns canned bank_import_rows/
 * accounting_transactions joins, exercising the amount + date + sender-name
 * scoring without a database.
 */
class EtransferBankMatchTest extends TestCase
{
    private function serviceWith(array $candidateRows): EtransferInboxService
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($candidateRows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return new EtransferInboxService($pdo);
    }

    public function testNoCandidatesReturnsNull(): void
    {
        $svc = $this->serviceWith([]);
        $result = $svc->matchBankTransaction(['amount' => 262.50, 'sender_name' => 'STRATA BCS4079', 'email_date' => '2026-07-12']);
        $this->assertNull($result);
    }

    public function testMissingAmountReturnsNullWithoutQuerying(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $svc = new EtransferInboxService($pdo);

        $this->assertNull($svc->matchBankTransaction(['amount' => null, 'sender_name' => 'STRATA BCS4079', 'email_date' => '2026-07-12']));
        $this->assertNull($svc->matchBankTransaction(['amount' => 0, 'sender_name' => 'STRATA BCS4079', 'email_date' => '2026-07-12']));
    }

    public function testExactAmountSameDayAndNameOverlapScoresHigh(): void
    {
        $svc = $this->serviceWith([[
            'tx_id'            => 55,
            'transaction_date' => '2026-07-12',
            'description'      => 'e-Transfer credit Ref 20260712075818668336 STRATA PLAN BCS-2106',
        ]]);

        $result = $svc->matchBankTransaction([
            'amount'      => 364.64,
            'sender_name' => 'STRATA PLAN BCS-2106',
            'email_date'  => '2026-07-12',
        ]);

        $this->assertNotNull($result);
        $this->assertSame(55, $result['tx_id']);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
    }

    public function testAmountOnlyWithNoDateOrNameSignalFallsBelowThreshold(): void
    {
        // Amount match alone is only 50 points; below the 60-point bar without
        // date proximity or a sender-name overlap, so it should NOT be trusted
        // as a reconciliation link (too weak a signal on its own).
        $svc = $this->serviceWith([[
            'tx_id'            => 77,
            'transaction_date' => '2026-01-01',
            'description'      => 'e-Transfer credit Ref 99999 SOMEONE ELSE ENTIRELY',
        ]]);

        $result = $svc->matchBankTransaction([
            'amount'      => 100.00,
            'sender_name' => 'STRATA BCS4079',
            'email_date'  => '2026-07-12',
        ]);

        $this->assertNull($result);
    }

    public function testFarApartDateDoesNotMatchEvenWithAmountAndNameOverlap(): void
    {
        // Regression: amount(50) + sender-name overlap(25) = 75, which used to
        // clear the 60-point bar even when the bank deposit was weeks away from
        // the email — silently linking two unrelated transfers just because
        // they happened to share an amount and a name. Real case: a July 3
        // deposit got linked to an August 1 notification this way. Date
        // proximity must now be a hard gate (<=10 days), not just a bonus.
        $svc = $this->serviceWith([[
            'tx_id'            => 24493,
            'transaction_date' => '2026-07-03',
            'description'      => 'e-Transfer credit Ref 20260702225910818756 ALEXSHIKEWANG',
        ]]);

        $result = $svc->matchBankTransaction([
            'amount'      => 378.16,
            'sender_name' => 'ALEX SHI KE WANG',
            'email_date'  => '2026-08-01',
        ]);

        $this->assertNull($result);
    }

    public function testPicksHighestScoringCandidateWhenMultipleMatch(): void
    {
        $svc = $this->serviceWith([
            [
                'tx_id'            => 1,
                'transaction_date' => '2026-01-01', // far from email date, no name overlap
                'description'      => 'e-Transfer credit Ref 1 UNRELATED',
            ],
            [
                'tx_id'            => 2,
                'transaction_date' => '2026-07-12', // same day + name overlap
                'description'      => 'e-Transfer credit Ref 2 STRATA BCS4079',
            ],
        ]);

        $result = $svc->matchBankTransaction([
            'amount'      => 262.50,
            'sender_name' => 'STRATA BCS4079',
            'email_date'  => '2026-07-12',
        ]);

        $this->assertNotNull($result);
        $this->assertSame(2, $result['tx_id']);
    }
}
