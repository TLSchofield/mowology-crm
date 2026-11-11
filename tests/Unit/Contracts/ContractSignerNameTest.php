<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: the name typed at signing is the one stored. */
final class ContractSignerNameTest extends TestCase
{
    public function testTypedNameWins(): void
    {
        $this->assertSame('Jane Q Doe', ContractService::signedByName("  Jane   Q Doe ", 'Jane Doe'));
        $this->assertSame('Jane Doe', ContractService::signedByName('', 'Jane Doe'));
        $this->assertSame('', ContractService::signedByName(' ', null));
    }

    public function testRecordSignatureStoresTypedName(): void
    {
        $sigRow = ['id' => 5, 'contract_id' => 1, 'contract_version' => 1, 'signer_name' => 'Jane Doe', 'status' => 'pending'];
        $seen = [];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn($sigRow);
        $stmt->method('execute')->willReturnCallback(function ($p = null) use (&$seen) { $seen[] = $p; return true; });
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);

        $this->assertTrue((new ContractService($db))->recordSignature('t', 'data:image/png;base64,abc', '1.2.3.4', true, 'Janet Doe'));
        $flat = array_merge(...array_map(fn($p) => (array)$p, $seen));
        $this->assertContains('Janet Doe', $flat);
    }
}
