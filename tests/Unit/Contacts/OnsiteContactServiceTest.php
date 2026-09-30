<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OnsiteContactService — pure logic plus the table-missing degrade path, via mocked PDO.
 */
class OnsiteContactServiceTest extends TestCase
{
    protected function setUp(): void
    {
        OnsiteContactService::resetProbeCache();
    }

    private function pdoWithTable(bool $exists): PDO
    {
        $probe = $this->createMock(PDOStatement::class);
        $probe->method('fetchColumn')->willReturn($exists ? 'property_contacts' : false);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($probe);
        return $pdo;
    }

    /** @test */
    public function shape_prefers_mobile_over_landline_and_nulls_blanks(): void
    {
        $r = OnsiteContactService::shape([
            'id' => 7, 'first_name' => 'Ron', 'last_name' => 'Harvie',
            'phone' => '604-555-0100', 'mobile' => ' 778-555-0199 ', 'email' => '',
        ]);
        $this->assertSame(7, $r['contact_id']);
        $this->assertSame('Ron Harvie', $r['name']);
        $this->assertSame('778-555-0199', $r['phone']);
        $this->assertNull($r['email']);
    }

    /** @test */
    public function splitName_handles_single_and_multi_word_names(): void
    {
        $this->assertSame(['Maria', 'de la Cruz'], OnsiteContactService::splitName('  Maria   de la Cruz '));
        $this->assertSame(['Cher', null], OnsiteContactService::splitName('Cher'));
    }

    /** @test */
    public function primaryIdSubquery_targets_site_supervisor_role_for_alias(): void
    {
        $sql = OnsiteContactService::primaryIdSubquery('prop');
        $this->assertStringContainsString("pc.property_id = prop.id", $sql);
        $this->assertStringContainsString("'site_supervisor'", $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
    }

    /** @test */
    public function reads_degrade_to_null_when_table_is_missing(): void
    {
        $pdo = $this->pdoWithTable(false);
        $pdo->expects($this->never())->method('prepare');
        $svc = new OnsiteContactService($pdo);
        $this->assertFalse($svc->isAvailable());
        $this->assertNull($svc->getForProperty(12));
    }

    /** @test */
    public function set_replaces_then_inserts_when_table_exists(): void
    {
        $pdo  = $this->pdoWithTable(true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $seen = [];
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$seen, $stmt) {
            $seen[] = $sql;
            return $stmt;
        });

        (new OnsiteContactService($pdo))->setForProperty(3, 9);

        $this->assertCount(2, $seen);
        $this->assertStringContainsString('DELETE FROM property_contacts', $seen[0]);
        $this->assertStringContainsString('INSERT INTO property_contacts', $seen[1]);
    }

    /** @test */
    public function set_refuses_when_table_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        (new OnsiteContactService($this->pdoWithTable(false)))->setForProperty(3, 9);
    }

    /** @test */
    public function quickAdd_rejects_blank_name_and_bad_email(): void
    {
        $svc = new OnsiteContactService($this->pdoWithTable(true));
        try {
            $svc->quickAddAndAssign(1, '   ', null, null);
            $this->fail('blank name accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('name', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $svc->quickAddAndAssign(1, 'Pat', null, 'not-an-email');
    }
}
