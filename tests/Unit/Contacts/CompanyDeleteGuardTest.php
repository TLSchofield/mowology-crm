<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A company with quotes, job plans or invoices is never hard-deleted (Fable review 2026-10-12). */
class CompanyDeleteGuardTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['quotes', 'job_plans', 'invoices'] as $t) {
            $this->db->exec("CREATE TABLE {$t} (id INTEGER PRIMARY KEY, company_id INTEGER)");
        }
        $this->db->exec("INSERT INTO invoices (company_id) VALUES (2), (2)");
        $this->db->exec("INSERT INTO job_plans (company_id) VALUES (3)");
        $this->db->exec("INSERT INTO quotes (company_id) VALUES (4)");
    }

    public function test_company_without_related_records_is_deletable(): void
    {
        $g = new CompanyDeleteGuard($this->db);
        $this->assertSame([], $g->blockers([1]));
        $this->assertSame([1], $g->deletable([1]));
    }

    public function test_invoices_job_plans_and_quotes_each_block(): void
    {
        $g = new CompanyDeleteGuard($this->db);
        $this->assertSame(['invoices' => 2], $g->blockers([2]));
        $this->assertSame(['job_plans' => 1], $g->blockers([3]));
        $this->assertSame(['quotes' => 1], $g->blockers([4]));
        $this->assertSame([1, 5], $g->deletable([1, 2, 3, 4, 5]));
        $this->assertSame('2 invoice(s)', $g->describe($g->blockers([2])));
    }

    public function test_unreadable_table_blocks_rather_than_allows(): void
    {
        $this->db->exec('DROP TABLE quotes');
        $g = new CompanyDeleteGuard($this->db);
        $this->assertArrayHasKey('quotes', $g->blockers([1]));
        $this->assertSame([], $g->deletable([1]));
    }
}
