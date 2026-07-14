<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ContractServiceTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeStmt(
        mixed $fetchReturn     = false,
        mixed $fetchAllReturn  = [],
        mixed $fetchColReturn  = 0
    ): PDOStatement {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetch')->willReturn($fetchReturn);
        $s->method('fetchAll')->willReturn($fetchAllReturn);
        $s->method('fetchColumn')->willReturn($fetchColReturn);
        return $s;
    }

    private function baseContract(array $overrides = []): array
    {
        return array_merge([
            'id'               => 1,
            'contract_number'  => 'CON-2026-0001',
            'title'            => 'Lawn Care 2026',
            'signature_status' => 'unsigned',
            'current_version'  => 1,
            'billing_cycle'    => 'monthly',
            'billing_amount'   => '250.00',
            'invoice_timing'   => null,
            'start_date'       => '2026-04-01',
            'end_date'         => null,
            'renewal_date'     => null,
            'auto_renew'       => 1,
            'renewal_increase_pct' => '0.00',
            'notes'            => '',
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateToken (via sendForSignature smoke)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function send_for_signature_throws_when_contract_not_found(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc = new ContractService($db);
        $this->expectException(InvalidArgumentException::class);
        $svc->sendForSignature(999, 'Jane Doe', 'jane@example.com', 1);
    }

    /** @test */
    public function send_for_signature_throws_when_already_signed(): void
    {
        $contract = $this->baseContract(['signature_status' => 'signed']);

        $db   = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($contract));

        $svc = new ContractService($db);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already signed/i');
        $svc->sendForSignature(1, 'Jane Doe', 'jane@example.com', 1);
    }

    /** @test */
    public function send_for_signature_returns_64_char_hex_token(): void
    {
        $contract = $this->baseContract();

        // sendForSignature calls (no existing versions path):
        // #1 getContract, #2 getVersionCount(=0),
        // #3 snapshotVersion→getContract, #4 snapshotVersion→getVersionCount,
        // #5 snapshotVersion→INSERT, #6 snapshotVersion→UPDATE current_version,
        // #7 expirePendingRequests, #8 INSERT contract_signatures,
        // #9 UPDATE contracts.signature_status
        $generic   = $this->makeStmt($contract, [], 0);
        $db        = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($generic);

        $svc   = new ContractService($db);
        $token = $svc->sendForSignature(1, 'Jane Doe', 'jane@example.com', 1);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getSignatureRequest
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function get_signature_request_returns_null_for_empty_token(): void
    {
        $db  = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');

        $svc = new ContractService($db);
        $this->assertNull($svc->getSignatureRequest(''));
    }

    /** @test */
    public function get_signature_request_returns_null_when_not_found(): void
    {
        $db  = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc = new ContractService($db);
        $this->assertNull($svc->getSignatureRequest('abc123'));
    }

    /** @test */
    public function get_signature_request_returns_row_when_valid(): void
    {
        $expected = ['id' => 7, 'contract_id' => 1, 'signer_name' => 'Jane Doe', 'status' => 'pending'];

        $db  = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($expected));

        $svc    = new ContractService($db);
        $result = $svc->getSignatureRequest('validtoken64chars');
        $this->assertSame($expected, $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // recordSignature
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function record_signature_returns_false_when_token_invalid(): void
    {
        $db  = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc = new ContractService($db);
        $this->assertFalse($svc->recordSignature('badtoken', 'data:image/png;base64,abc', '127.0.0.1'));
    }

    /** @test */
    public function record_signature_commits_transaction_on_success(): void
    {
        $sigRow = [
            'id'               => 5,
            'contract_id'      => 1,
            'contract_version' => 1,
            'signer_name'      => 'Jane Doe',
            'status'           => 'pending',
        ];

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($sigRow));
        $db->expects($this->once())->method('beginTransaction')->willReturn(true);
        $db->expects($this->once())->method('commit')->willReturn(true);
        $db->expects($this->never())->method('rollBack');

        $svc    = new ContractService($db);
        $result = $svc->recordSignature('validtoken', 'data:image/png;base64,abc', '1.2.3.4');
        $this->assertTrue($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // declineSignature
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function decline_signature_returns_false_for_invalid_token(): void
    {
        $db  = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc = new ContractService($db);
        $this->assertFalse($svc->declineSignature('badtoken', 'changed mind', '127.0.0.1'));
    }

    /** @test */
    public function decline_signature_returns_true_and_updates_rows(): void
    {
        $row = ['contract_id' => 1, 'signature_status' => 'pending'];

        $selectStmt = $this->makeStmt($row);
        $updateSig  = $this->makeStmt();
        $updateCtr  = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        $db->method('prepare')
           ->willReturnOnConsecutiveCalls($selectStmt, $updateSig, $updateCtr);

        $svc    = new ContractService($db);
        $result = $svc->declineSignature('validtoken', 'Too expensive', '5.6.7.8');
        $this->assertTrue($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // revokeSignatureRequest
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function revoke_signature_request_expires_and_resets_status(): void
    {
        $expireStmt = $this->makeStmt();
        $updateStmt = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        $db->expects($this->exactly(2))
           ->method('prepare')
           ->willReturnOnConsecutiveCalls($expireStmt, $updateStmt);

        $svc = new ContractService($db);
        $svc->revokeSignatureRequest(1); // should not throw
        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getActiveSignatureRequest
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function get_active_signature_request_returns_null_when_none(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc = new ContractService($db);
        $this->assertNull($svc->getActiveSignatureRequest(1));
    }

    /** @test */
    public function get_active_signature_request_returns_row_when_pending(): void
    {
        $row = ['id' => 3, 'contract_id' => 1, 'status' => 'pending'];

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($row));

        $svc    = new ContractService($db);
        $result = $svc->getActiveSignatureRequest(1);
        $this->assertSame($row, $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getSignatureHistory
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function get_signature_history_returns_all_rows(): void
    {
        $rows = [
            ['id' => 2, 'status' => 'signed',  'sent_by_name' => 'Tim'],
            ['id' => 1, 'status' => 'expired', 'sent_by_name' => 'Tim'],
        ];

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false, $rows));

        $svc    = new ContractService($db);
        $result = $svc->getSignatureHistory(1);
        $this->assertCount(2, $result);
        $this->assertSame('signed', $result[0]['status']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // snapshotVersion
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function snapshot_version_throws_when_contract_not_found(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc = new ContractService($db);
        $this->expectException(InvalidArgumentException::class);
        $svc->snapshotVersion(999, 1, 'Test');
    }

    /** @test */
    public function snapshot_version_inserts_next_version_number(): void
    {
        $contract = $this->baseContract();

        $getContractStmt = $this->makeStmt($contract);
        $countStmt       = $this->makeStmt(false, [], 2); // 2 existing versions → next = 3
        $insertStmt      = $this->makeStmt();
        $updateStmt      = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        $db->expects($this->exactly(4))
           ->method('prepare')
           ->willReturnOnConsecutiveCalls($getContractStmt, $countStmt, $insertStmt, $updateStmt);

        // Capture the UPDATE argument to verify version number
        $updateStmt->expects($this->once())
                   ->method('execute')
                   ->with([3, 1]) // nextVersion=3, contractId=1
                   ->willReturn(true);

        $svc = new ContractService($db);
        $svc->snapshotVersion(1, 1, 'Amendment reason');
        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getVersions
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function get_versions_returns_all_versions_newest_first(): void
    {
        $rows = [
            ['version_number' => 2, 'changed_by_name' => 'Tim', 'signature_status' => 'unsigned'],
            ['version_number' => 1, 'changed_by_name' => 'Tim', 'signature_status' => 'signed'],
        ];

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false, $rows));

        $svc    = new ContractService($db);
        $result = $svc->getVersions(1);
        $this->assertCount(2, $result);
        $this->assertSame(2, $result[0]['version_number']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // amendContract
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function amend_contract_resets_signature_status_to_unsigned(): void
    {
        $contract = $this->baseContract(['signature_status' => 'signed']);

        $getContractStmt1 = $this->makeStmt($contract); // snapshotVersion → getContract
        $countStmt        = $this->makeStmt(false, [], 1);
        $getContractStmt2 = $this->makeStmt($contract); // snapshotVersion internal getContract
        $insertStmt       = $this->makeStmt();
        $updateVersionStmt = $this->makeStmt();
        $updateSigStmt    = $this->makeStmt(); // reset signature_status

        $db = $this->createMock(PDO::class);
        // amendContract call order:
        // #1 amendContract→getContract, #2 snapshotVersion→getContract,
        // #3 snapshotVersion→getVersionCount, #4 snapshotVersion→INSERT,
        // #5 snapshotVersion→UPDATE current_version, #6 amendContract→UPDATE signature_status
        $db->expects($this->exactly(6))
           ->method('prepare')
           ->willReturnOnConsecutiveCalls(
               $getContractStmt1,    // amendContract→getContract
               $getContractStmt2,    // snapshotVersion→getContract
               $countStmt,           // snapshotVersion→getVersionCount
               $insertStmt,          // snapshotVersion→INSERT
               $updateVersionStmt,   // snapshotVersion→UPDATE current_version
               $updateSigStmt        // amendContract→UPDATE signature_status='unsigned'
           );

        // The final update should set status to 'unsigned'
        $updateSigStmt->expects($this->once())
                      ->method('execute')
                      ->with([1]) // contractId
                      ->willReturn(true);

        $svc = new ContractService($db);
        $svc->amendContract(1, 1, 'Rate increase');
        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // isPlanContractBilled / getContractBilledPlanIds
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function is_plan_contract_billed_false_for_invalid_plan_id(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');

        $svc = new ContractService($db);
        $this->assertFalse($svc->isPlanContractBilled(0));
    }

    /** @test */
    public function is_plan_contract_billed_false_when_no_contract_row(): void
    {
        // No contract_id, or the joined contracts row doesn't exist.
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));

        $svc = new ContractService($db);
        $this->assertFalse($svc->isPlanContractBilled(42));
    }

    /** @test */
    public function is_plan_contract_billed_true_when_active_and_not_per_visit(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(['status' => 'active', 'billing_cycle' => 'monthly']));

        $svc = new ContractService($db);
        $this->assertTrue($svc->isPlanContractBilled(42));
    }

    /** @test */
    public function is_plan_contract_billed_false_when_contract_not_active(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(['status' => 'cancelled', 'billing_cycle' => 'monthly']));

        $svc = new ContractService($db);
        $this->assertFalse($svc->isPlanContractBilled(42));
    }

    /** @test */
    public function is_plan_contract_billed_false_when_contract_billing_cycle_is_per_visit(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(['status' => 'active', 'billing_cycle' => 'per_visit']));

        $svc = new ContractService($db);
        $this->assertFalse($svc->isPlanContractBilled(42));
    }

    /** @test */
    public function get_contract_billed_plan_ids_returns_empty_for_empty_input(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');

        $svc = new ContractService($db);
        $this->assertSame([], $svc->getContractBilledPlanIds([]));
    }

    /** @test */
    public function get_contract_billed_plan_ids_returns_mixed_results(): void
    {
        $rows = [
            ['plan_id' => 1, 'status' => 'active', 'billing_cycle' => 'monthly'],
            ['plan_id' => 3, 'status' => 'cancelled', 'billing_cycle' => 'monthly'],
        ];

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false, $rows));

        $svc    = new ContractService($db);
        $result = $svc->getContractBilledPlanIds([1, 2, 3]);

        $this->assertSame([
            1 => true,   // active, monthly -> contract-billed
            2 => false,  // no contract row -> not contract-billed
            3 => false,  // cancelled -> not contract-billed
        ], $result);
    }
}
