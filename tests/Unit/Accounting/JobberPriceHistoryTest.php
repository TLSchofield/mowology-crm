<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Per-service price history from Jobber invoice line items (real Susan Lim rows as fixtures). */
class JobberPriceHistoryTest extends TestCase
{
    private function inv(string $date, string $items, string $status = 'paid', string $no = '1'): array
    {
        return ['jobber_number' => $no, 'issued_date' => $date, 'status' => $status, 'line_items' => $items];
    }

    public function testParsesLineTotalsAndNames(): void
    {
        $l = JobberPriceHistory::parseLineItems('Green Waste Tarp (1, $35.00), Hedges Trim (4, $361.25)');
        $this->assertCount(2, $l);
        $this->assertSame(['name' => 'Hedges Trim', 'qty' => 4.0, 'total' => 361.25], $l[1]);
    }

    public function testNamesWithCommasChineseAndThousands(): void
    {
        $l = JobberPriceHistory::parseLineItems('Lawn Cut Service - 割草 (8, $1,504.00), Mulch, Aged (2, $360.00)');
        $this->assertSame('Lawn Cut Service - 割草', $l[0]['name']);
        $this->assertSame(1504.0, $l[0]['total']);
        $this->assertSame('Mulch, Aged', $l[1]['name']);
    }

    public function testEmptyAndGarbage(): void
    {
        $this->assertSame([], JobberPriceHistory::parseLineItems(null));
        $this->assertSame([], JobberPriceHistory::parseLineItems('no line items here'));
    }

    public function testHedgeTrimByYearUsesUnitPriceNotLineTotal(): void
    {
        $s = JobberPriceHistory::summarize([
            $this->inv('2025-09-21', 'Green Waste Tarp (1, $35.00), Hedges Trim (4, $361.25)', 'paid', '10'),
            $this->inv('2025-10-03', 'Lawn Cut Service - 割草 (1, $48.00), Hedges Trim (1, $20.00)', 'paid', '11'),
            $this->inv('2024-10-02', 'Blade Sharpening (1, $25.00), Hedges Trim (1, $142.50)', 'paid', '9'),
            $this->inv('2022-10-09', 'Hedges Trim (5, $375.00), Green Waste Tarp (1, $35.00)', 'paid', '8'),
        ]);
        $by = array_column($s, null, 'service');
        $h = $by['Hedges Trim'];
        $this->assertSame(4, $h['count']);
        $this->assertSame('2025-10-03', $h['last']['date']);
        $this->assertSame([2025, 2024, 2022], array_keys($h['years']));
        $this->assertSame(2, $h['years'][2025]['n']);
        $this->assertSame(381.25, $h['years'][2025]['sum']);
        $this->assertSame(20.0, $h['years'][2025]['unit_min']);
        $this->assertSame(90.31, $h['years'][2025]['unit_max']);
        $this->assertSame(75.0, $h['years'][2022]['unit_min']);
    }

    public function testDraftVoidBadDebtAndZeroLinesAreIgnored(): void
    {
        $s = JobberPriceHistory::summarize([
            $this->inv('2026-01-05', 'Areas (1, $0.00), Snow Removal (1, $240.75)', 'draft'),
            $this->inv('2026-01-06', 'Snow Removal (1, $240.75)', 'bad_debt'),
            $this->inv('2026-01-07', 'Areas (1, $0.00)', 'paid'),
        ]);
        $this->assertSame([], $s);
    }

    public function testQtyZeroIsAFlatChargeWithNoUnitPrice(): void
    {
        $s = JobberPriceHistory::summarize([$this->inv('2022-11-22', 'Leaf Clean Up (0, $49.50)')]);
        $this->assertSame(49.5, $s[0]['last']['total']);
        $this->assertNull($s[0]['last']['unit']);
        $this->assertArrayNotHasKey('unit_min', $s[0]['years'][2022]);
    }

    public function testServicesSortedNewestFirstAndCaseInsensitive(): void
    {
        $s = JobberPriceHistory::summarize([
            $this->inv('2024-05-01', 'Power Rake (1, $185.00)'),
            $this->inv('2025-05-01', 'power rake (1, $190.00), Lawn Cut (1, $48.00)'),
        ]);
        $this->assertCount(2, $s);
        $pr = array_values(array_filter($s, fn($x) => strtolower($x['service']) === 'power rake'))[0];
        $this->assertSame(2, $pr['count']);
    }

    public function testCompanyPanelFindsJobberClientNamedWithSuffix(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE companies (id INTEGER, company_name TEXT)");
        $db->exec("CREATE TABLE company_properties (company_id INTEGER, property_id INTEGER)");
        $db->exec("CREATE TABLE jobber_invoices (jobber_number TEXT, issued_date TEXT, status TEXT, line_items TEXT,
                   company_id INTEGER, property_id INTEGER, contact_id INTEGER, client_name TEXT)");
        $db->exec("INSERT INTO companies VALUES (14, 'Beedie Development')");
        $ins = $db->prepare("INSERT INTO jobber_invoices (jobber_number, issued_date, status, line_items, client_name) VALUES (?,?,?,?,?)");
        $ins->execute(['1', '2024-10-01', 'paid', 'Full Day Clean Up (1, $1,800.00)', 'Beedie Development - Kanco 2130 Cambridge Apartments Ltd.']);
        $ins->execute(['2', '2025-10-01', 'paid', 'MAINTENANCE FALL CLEAN UP (1, $1,300.00)', 'Beedie Development c/o Kanco']);
        $ins->execute(['3', '2025-10-01', 'paid', 'Hedges Trim (1, $65.00)', 'Beedie Development Two Ltd.']);   // a different client
        $svc = array_column((new JobberPriceHistory($db))->forCompany(14), 'service');
        sort($svc);
        $this->assertSame(['Full Day Clean Up', 'MAINTENANCE FALL CLEAN UP'], $svc);
    }

    public function testBackfillFillsOnlyEmptyLineItemsAndTouchesNothingElse(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE jobber_invoices (jobber_number TEXT, status TEXT, balance REAL, total REAL, line_items TEXT)");
        $db->exec("INSERT INTO jobber_invoices VALUES ('100','paid',0,50.4,NULL), ('101','paid',0,10,'Keep Me (1, $10.00)'), ('102','past_due',99,99,'')");
        $csv = "Invoice #,Client name,Total (\$),Line items,Status,Created date,Issued date\n"
             . "100,Susan Lim,50.4,\"Lawn Cut Service - 割草 (1, \$48.00)\",Paid,\"May 01, 2026\",\"May 01, 2026\"\n"
             . "101,Susan Lim,10,\"Replaced (1, \$10.00)\",Paid,\"May 01, 2026\",\"May 01, 2026\"\n"
             . "102,Bob,99,\"Snow (1, \$99.00)\",Past Due,\"May 01, 2026\",\"May 01, 2026\"\n"
             . "999,Nobody,5,\"X (1, \$5.00)\",Paid,\"May 01, 2026\",\"May 01, 2026\"\n";
        $res = (new JobberPriceHistory($db))->backfillLineItems($csv);
        $this->assertSame(['rows' => 4, 'matched' => 3, 'filled' => 2, 'already_had' => 1, 'not_found' => 1, 'no_lines' => 0], $res);
        $rows = array_column($db->query("SELECT * FROM jobber_invoices")->fetchAll(PDO::FETCH_ASSOC), null, 'jobber_number');
        $this->assertSame('Lawn Cut Service - 割草 (1, $48.00)', $rows['100']['line_items']);
        $this->assertSame('Keep Me (1, $10.00)', $rows['101']['line_items']);   // never overwritten
        $this->assertSame('Snow (1, $99.00)', $rows['102']['line_items']);
        $this->assertSame('past_due', $rows['102']['status']);                  // nothing else changes
        $this->assertEquals(99.0, $rows['102']['balance']);
    }

    public function testBackfillRejectsAnExportThatIsNotInvoices(): void
    {
        $db = new PDO('sqlite::memory:');
        $res = (new JobberPriceHistory($db))->backfillLineItems("Quote #,Client name\n1,x\n");
        $this->assertArrayHasKey('error', $res);
    }
}
