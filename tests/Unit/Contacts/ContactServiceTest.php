<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ContactService pure-logic paths.
 *
 * All DB-touching methods are tested via a mocked PDO so no real
 * database is required.
 */
class ContactServiceTest extends TestCase
{
    // ── helpers ──────────────────────────────────────────────────────────────

    private function mockPdo(): PDO
    {
        return $this->createMock(PDO::class);
    }

    private function mockStmt(mixed $returnValue = true): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($returnValue);
        $stmt->method('fetchAll')->willReturn(is_array($returnValue) ? $returnValue : []);
        $stmt->method('fetchColumn')->willReturn(is_int($returnValue) ? $returnValue : 0);
        return $stmt;
    }

    // ── addNote ───────────────────────────────────────────────────────────────

    /** @test */
    public function addNote_inserts_with_valid_type(): void
    {
        $pdo  = $this->mockPdo();
        $stmt = $this->mockStmt();

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO client_notes'))
            ->willReturn($stmt);

        $stmt->expects($this->once())
            ->method('execute')
            ->with([42, 'follow_up', 'Call scheduled', 1]);

        $svc = new ContactService($pdo);
        $svc->addNote(42, 'Call scheduled', 'follow_up', 1);
    }

    /** @test */
    public function addNote_defaults_invalid_type_to_general(): void
    {
        $pdo  = $this->mockPdo();
        $stmt = $this->mockStmt();

        $pdo->method('prepare')->willReturn($stmt);

        $stmt->expects($this->once())
            ->method('execute')
            ->with([1, 'general', 'Some note', 5]);

        $svc = new ContactService($pdo);
        $svc->addNote(1, 'Some note', 'not_a_valid_type', 5);
    }

    // ── updatePropertyCoords ─────────────────────────────────────────────────

    /** @test */
    public function updatePropertyCoords_calls_correct_sql(): void
    {
        $pdo  = $this->mockPdo();
        $stmt = $this->mockStmt();

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('UPDATE properties SET latitude'))
            ->willReturn($stmt);

        $stmt->expects($this->once())
            ->method('execute')
            ->with([49.2827, -123.1207, 7]);

        $svc = new ContactService($pdo);
        $svc->updatePropertyCoords(7, 49.2827, -123.1207);
    }

    // ── linkCompanyToContact ──────────────────────────────────────────────────

    /** @test */
    public function linkCompanyToContact_sets_both_contact_fields(): void
    {
        $pdo  = $this->mockPdo();
        $stmt = $this->mockStmt();

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('primary_contact_id'))
            ->willReturn($stmt);

        $stmt->expects($this->once())
            ->method('execute')
            ->with([3, 3, 10]);

        $svc = new ContactService($pdo);
        $svc->linkCompanyToContact(10, 3);
    }

    // ── unlinkProperty ────────────────────────────────────────────────────────

    /** @test */
    public function unlinkProperty_returns_false_when_property_not_owned(): void
    {
        $pdo       = $this->mockPdo();
        $checkStmt = $this->mockStmt(false); // fetch() returns false

        $pdo->method('prepare')->willReturn($checkStmt);

        $svc    = new ContactService($pdo);
        $result = $svc->unlinkProperty(99, 1);

        $this->assertFalse($result);
    }

    /** @test */
    public function unlinkProperty_sets_null_and_returns_true_when_owned(): void
    {
        $pdo = $this->mockPdo();

        $checkStmt  = $this->mockStmt(['id' => 5]);   // fetch() returns a row
        $updateStmt = $this->mockStmt();

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($checkStmt, $updateStmt);

        $updateStmt->expects($this->once())
            ->method('execute')
            ->with([5]);

        $svc    = new ContactService($pdo);
        $result = $svc->unlinkProperty(5, 1);

        $this->assertTrue($result);
    }

    // ── addPropertyToContact ─────────────────────────────────────────────────

    /** @test */
    public function addPropertyToContact_links_existing_unowned_property(): void
    {
        $pdo = $this->mockPdo();

        $checkStmt  = $this->mockStmt(['id' => 20, 'site_contact_id' => null]);
        $updateStmt = $this->mockStmt();

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($checkStmt, $updateStmt);

        $svc    = new ContactService($pdo);
        $result = $svc->addPropertyToContact(3, ['address' => '123 Main St']);

        $this->assertSame(20, $result['property_id']);
        $this->assertFalse($result['created']);
    }

    /** @test */
    public function addPropertyToContact_does_not_overwrite_existing_owner(): void
    {
        $pdo = $this->mockPdo();

        // Property already has an owner (site_contact_id = 99)
        $checkStmt = $this->mockStmt(['id' => 20, 'site_contact_id' => 99]);

        // prepare() called once for SELECT, NOT for UPDATE (no overwrite)
        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($checkStmt);

        $svc    = new ContactService($pdo);
        $result = $svc->addPropertyToContact(3, ['address' => '123 Main St']);

        $this->assertSame(20, $result['property_id']);
        $this->assertFalse($result['created']);
    }

    /** @test */
    public function addPropertyToContact_inserts_new_property(): void
    {
        $pdo = $this->mockPdo();

        $checkStmt  = $this->mockStmt(false); // no existing property
        $insertStmt = $this->mockStmt();

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($checkStmt, $insertStmt);

        $pdo->method('lastInsertId')->willReturn('42');

        $svc    = new ContactService($pdo);
        $result = $svc->addPropertyToContact(3, [
            'address'     => '456 Oak Ave',
            'city'        => 'Burnaby',
            'postal_code' => 'V5H 1A1',
        ]);

        $this->assertSame(42, $result['property_id']);
        $this->assertTrue($result['created']);
    }

    // ── updateContact ─────────────────────────────────────────────────────────

    /** @test */
    public function updateContact_returns_old_values(): void
    {
        $pdo = $this->mockPdo();

        $old = ['first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'old@example.com',
                'phone' => '', 'mobile' => '', 'preferred_contact_method' => 'email',
                'receive_sms' => 0, 'receive_marketing' => 0, 'consent_quote_followup' => 0, 'notes' => ''];

        $selectStmt = $this->mockStmt($old);
        $updateStmt = $this->mockStmt();

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($selectStmt, $updateStmt);

        $svc    = new ContactService($pdo);
        $result = $svc->updateContact(1, [
            'first_name' => 'Bob', 'last_name' => 'Jones', 'email' => 'new@example.com',
        ]);

        $this->assertSame('Alice', $result['first_name']);
        $this->assertSame('old@example.com', $result['email']);
    }

    // ── deleteCompany ─────────────────────────────────────────────────────────

    /** @test */
    public function deleteCompany_executes_delete_query(): void
    {
        $pdo  = $this->mockPdo();
        $stmt = $this->mockStmt();

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE FROM companies'))
            ->willReturn($stmt);

        $stmt->expects($this->once())->method('execute')->with([5]);

        $svc = new ContactService($pdo);
        $svc->deleteCompany(5);
    }

    // ── bulkDeleteCompanies ───────────────────────────────────────────────────

    /** @test */
    public function bulkDeleteCompanies_skips_companies_with_related_records(): void
    {
        $pdo = $this->mockPdo();

        // Build stubs directly (don't use mockStmt() so fetchColumn has a single matcher)
        $makeStmt = function (int $fetchColumnValue = 0): PDOStatement {
            $s = $this->createMock(PDOStatement::class);
            $s->method('execute')->willReturn(true);
            $s->method('fetchColumn')->willReturn($fetchColumnValue);
            return $s;
        };

        $cntStmt1 = $makeStmt(3); // Company 1: 3 related records → skip
        $cntStmt2 = $makeStmt(0); // Company 2: 0 related records → delete
        $passStmt = $makeStmt(0); // DELETE calls (execute only, fetchColumn irrelevant)

        $pdo->method('prepare')
            ->willReturnOnConsecutiveCalls($cntStmt1, $cntStmt2, $passStmt, $passStmt);

        $svc    = new ContactService($pdo);
        $result = $svc->bulkDeleteCompanies([1, 2]);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['skipped']);
    }

    /** @test */
    public function bulkDeleteCompanies_empty_array_returns_zeroes(): void
    {
        $pdo = $this->mockPdo();
        $pdo->expects($this->never())->method('prepare');

        $svc    = new ContactService($pdo);
        $result = $svc->bulkDeleteCompanies([]);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(0, $result['skipped']);
    }
}
