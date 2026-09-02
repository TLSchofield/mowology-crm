<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ExpenseLookupService — the vendor/job/category/duplicate lookups shared by
 * the session web handlers and the JWT expense-lookup.php endpoint. The load-bearing
 * rules: too-short queries never hit the DB, duplicate checks need total+date, and the
 * category list is a single static source of truth for both clients.
 */
class ExpenseLookupServiceTest extends TestCase
{
    private function makeStmt(array $rows = []): PDOStatement
    {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetchAll')->willReturn($rows);
        return $s;
    }

    // ── normalizeQuery ───────────────────────────────────────────────────

    public function test_normalize_query_rejects_short_and_blank(): void
    {
        $this->assertNull(ExpenseLookupService::normalizeQuery(null));
        $this->assertNull(ExpenseLookupService::normalizeQuery(''));
        $this->assertNull(ExpenseLookupService::normalizeQuery(' a '));
    }

    public function test_normalize_query_trims_valid_input(): void
    {
        $this->assertSame('home depot', ExpenseLookupService::normalizeQuery('  home depot '));
    }

    // ── searchVendors / searchJobs short-circuit ────────────────────────

    public function test_short_vendor_query_never_touches_db(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');
        $this->assertSame([], (new ExpenseLookupService($db))->searchVendors('h'));
    }

    public function test_short_job_query_never_touches_db(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');
        $this->assertSame([], (new ExpenseLookupService($db))->searchJobs(' '));
    }

    public function test_vendor_search_returns_rows(): void
    {
        $rows = [['id' => 3, 'name' => 'Home Depot', 'aliases' => null, 'default_accounting_category' => 'Materials', 'default_gbp_category' => null]];
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($rows));
        $this->assertSame($rows, (new ExpenseLookupService($db))->searchVendors('home'));
    }

    public function test_job_search_returns_property_and_contact_ids(): void
    {
        $rows = [['id' => 7, 'plan_number' => 'JOB-2026-0007', 'service_type' => 'Lawn', 'status' => 'active',
                  'property_id' => 12, 'contact_id' => 44, 'address' => '1 Main St', 'contact_name' => 'Pat Smith']];
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($rows));
        $result = (new ExpenseLookupService($db))->searchJobs('smith');
        $this->assertSame(12, $result[0]['property_id']);
        $this->assertSame(44, $result[0]['contact_id']);
    }

    // ── categories ───────────────────────────────────────────────────────

    public function test_categories_exposes_all_three_lists(): void
    {
        $cats = ExpenseLookupService::categories();
        $this->assertArrayHasKey('accounting_categories', $cats);
        $this->assertArrayHasKey('gbp_categories', $cats);
        $this->assertArrayHasKey('payment_methods', $cats);
        $this->assertContains('Materials', $cats['accounting_categories']);
        $this->assertContains('credit_card', $cats['payment_methods']);
    }

    // ── findDuplicates ───────────────────────────────────────────────────

    public function test_can_check_duplicates_requires_total_and_date(): void
    {
        $this->assertFalse(ExpenseLookupService::canCheckDuplicates(null, '2026-09-02'));
        $this->assertFalse(ExpenseLookupService::canCheckDuplicates(0.0, '2026-09-02'));
        $this->assertFalse(ExpenseLookupService::canCheckDuplicates(12.5, null));
        $this->assertFalse(ExpenseLookupService::canCheckDuplicates(12.5, ''));
        $this->assertTrue(ExpenseLookupService::canCheckDuplicates(12.5, '2026-09-02'));
    }

    public function test_find_duplicates_without_signal_never_touches_db(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');
        $this->assertSame([], (new ExpenseLookupService($db))->findDuplicates('X', null, 0.0, '2026-09-02'));
    }

    public function test_find_duplicates_proxies_receipt_path(): void
    {
        $rows = [
            ['id' => 1, 'expense_date' => '2026-09-01', 'total' => '12.50', 'status' => 'draft',
             'vendor_name_raw' => 'X', 'receipt_media_id' => 99, 'vendor_name' => null],
            ['id' => 2, 'expense_date' => '2026-09-01', 'total' => '12.50', 'status' => 'draft',
             'vendor_name_raw' => 'X', 'receipt_media_id' => null, 'vendor_name' => null],
        ];
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($rows));
        $result = (new ExpenseLookupService($db))->findDuplicates('X', null, 12.5, '2026-09-02');
        $this->assertSame('/crm/api/serve-receipt.php?id=99', $result[0]['receipt_path']);
        $this->assertNull($result[1]['receipt_path']);
    }
}
