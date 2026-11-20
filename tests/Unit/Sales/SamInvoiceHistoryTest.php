<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SamInvoiceHistory — Sam's "we did this here before" lines for a new invoice.
 * Fixture is production 2026-11-20: 4491 West 6th Avenue (property 1508), four Jobber invoices and no CRM invoice;
 * the visit is a "Cleanup" on 2026-11-20 with no plan lines.
 */
class SamInvoiceHistoryTest extends TestCase
{
    private PDO $db;
    private const TODAY = '2026-11-20';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE jobber_invoices (id INTEGER PRIMARY KEY, jobber_number TEXT, property_id INT, issued_date TEXT,
                   subtotal REAL, line_items TEXT, status TEXT, crm_invoice_id INT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, property_id INT, issue_date TEXT, subtotal REAL, status TEXT)");
        $db->exec("CREATE TABLE invoice_line_items (id INTEGER PRIMARY KEY, invoice_id INT, description TEXT, quantity REAL, unit_price REAL, line_total REAL, sort_order INT DEFAULT 0)");
        $ins = $db->prepare("INSERT INTO jobber_invoices (jobber_number, property_id, issued_date, subtotal, line_items, status) VALUES (?,?,?,?,?,'paid')");
        $ins->execute(['10952', 1508, '2025-04-16', 550, 'MAINTENANCE SPRING CLEAN UP (1, $550.00)']);
        $ins->execute(['10381', 1508, '2024-10-01', 915, 'Half Day (1, $650.00), 1/2 Load Green Waste (1, $240.00), Blade Sharpening (1, $25.00)']);
        $ins->execute(['9290', 1508, '2023-06-06', 780, 'Half Day (1, $600.00), 1/2 Load Green Waste (1, $180.00)']);
        $ins->execute(['6350', 1508, '2020-10-30', 690, 'Fall Clean Up (1, $545.00), Full Load Green Waste (1, $145.00)']);
        $ins->execute(['777', 99, '2024-10-01', 100, 'Lawn Cut (1, $100.00)']);
        $this->db = $db;
    }

    public function testFallCleanupFindsLastFallNotLastSpring(): void
    {
        $r = (new SamInvoiceHistory($this->db, self::TODAY))->forProperty(1508, 'Cleanup');
        $this->assertTrue($r['has']);
        $this->assertSame('Jobber #10381', $r['primary']['ref']);
        $this->assertSame('Fall clean-up last done here Oct 2024', $r['headline']);
        $this->assertSame(915.0, $r['primary']['subtotal']);
        $this->assertCount(3, $r['primary']['lines']);
        $this->assertSame('1/2 Load Green Waste', $r['primary']['lines'][1]['description']);
        $this->assertSame(240.0, $r['primary']['lines'][1]['unit_price']);
        $this->assertSame(25, $r['primary']['months_ago']);
        $this->assertStringContainsString('over two years', (string)$r['caveat']);
        $this->assertStringContainsString('2024 prices', (string)$r['caveat']);
        $this->assertStringContainsString('GST', (string)$r['caveat']);
        $this->assertCount(3, $r['earlier']);
    }

    public function testOtherPropertiesNeverLeakIn(): void
    {
        $r = (new SamInvoiceHistory($this->db, self::TODAY))->forProperty(99, 'Cleanup');
        $this->assertFalse($r['has'], 'a lawn cut at another property is not a clean-up here');
        $this->assertSame(1, $r['searched']);
    }

    public function testNothingMatchingTheWorkSuggestsNothing(): void
    {
        $r = (new SamInvoiceHistory($this->db, self::TODAY))->forProperty(1508, 'Snow removal');
        $this->assertFalse($r['has']);
        $this->assertNull($r['primary']);
    }

    public function testNoServiceGivenOffersNewestEarlierInvoice(): void
    {
        $r = (new SamInvoiceHistory($this->db, self::TODAY))->forProperty(1508, '');
        $this->assertSame('Jobber #10381', $r['primary']['ref'], 'same season (fall) beats the newer spring invoice');
    }

    public function testCrmInvoiceBeatsOldJobberAndDraftsAreIgnored(): void
    {
        $this->db->exec("INSERT INTO invoices VALUES (1, 'INV-2025-0100', 1508, '2025-11-02', 700, 'paid'), (2, 'INV-2025-0101', 1508, '2025-11-05', 9999, 'draft')");
        $this->db->exec("INSERT INTO invoice_line_items (invoice_id, description, quantity, unit_price, line_total) VALUES (1, 'Fall Clean Up', 1, 700, 700), (2, 'Fall Clean Up', 1, 9999, 9999)");
        $r = (new SamInvoiceHistory($this->db, self::TODAY))->forProperty(1508, 'Cleanup');
        $this->assertSame('INV-2025-0100', $r['primary']['ref']);
        $this->assertSame(12, $r['primary']['months_ago']);
    }

    public function testRecentPriceHasNoStaleWarning(): void
    {
        $this->db->exec("INSERT INTO jobber_invoices (jobber_number, property_id, issued_date, subtotal, line_items, status) VALUES ('1', 5, '2026-10-01', 100, 'Fall Clean Up (1, \$100.00)', 'paid')");
        $r = (new SamInvoiceHistory($this->db, self::TODAY))->forProperty(5, 'Cleanup');
        $this->assertSame('GST is added on top of these.', $r['caveat']);
    }

    public function testMissingTablesAreQuiet(): void
    {
        $empty = new PDO('sqlite::memory:');
        $empty->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $r = (new SamInvoiceHistory($empty, self::TODAY))->forProperty(1508, 'Cleanup');
        $this->assertFalse($r['has']);
        $this->assertFalse((new SamInvoiceHistory($this->db, self::TODAY))->forProperty(0, 'Cleanup')['has']);
    }

    public function testJobberLineParserKeepsCommasAndSkipsJunk(): void
    {
        $l = SamInvoiceHistory::parseJobberLines('Mulch, bark, 2 yd (2, $1,200.50), Disposal (1, $75.00)');
        $this->assertCount(2, $l);
        $this->assertSame('Mulch, bark, 2 yd', $l[0]['description']);
        $this->assertSame(2400.0 + 1.0, $l[0]['line_total']);
        $this->assertSame([], SamInvoiceHistory::parseJobberLines('nothing priced here'));
        $this->assertSame([], SamInvoiceHistory::parseJobberLines(null));
    }

    public function testMalformedItemIsSkippedNotMerged(): void
    {
        $l = SamInvoiceHistory::parseJobberLines('Broken (x, $20), Good (1, $30)');
        $this->assertCount(1, $l);
        $this->assertSame('Good', $l[0]['description']);
    }

    public function testDiscountsAndCancelledJobberInvoicesAreNotOffered(): void
    {
        $this->db->exec("INSERT INTO jobber_invoices (jobber_number, property_id, issued_date, subtotal, line_items, status) VALUES
            ('50', 7, '2026-10-20', 900, 'Cleanup (1, \$900.00)', 'Cancelled'),
            ('49', 7, '2026-10-10', 175, 'Cleanup (1, \$200.00), Discount (1, -\$25.00)', 'paid')");
        $r = (new SamInvoiceHistory($this->db, self::TODAY))->forProperty(7, 'Cleanup');
        $this->assertSame('Jobber #49', $r['primary']['ref']);
        $this->assertCount(1, $r['primary']['lines']);
        $this->assertSame(200.0, $r['primary']['subtotal']);
    }
}
