<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Jobber quotes kept as read-only history: parsing, outcome labels, idempotent import, client scoping. */
class JobberQuoteHistoryTest extends TestCase
{
    private const HEAD = 'Quote #,Client name,Client email,Client phone,Service street,Title,Status,Line items,Subtotal ($),Total ($),Drafted date,Sent date,Approved date,Converted date,Archived date';

    private function csv(array $lines): string
    {
        return self::HEAD . "\n" . implode("\n", $lines) . "\n";
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE jobber_quotes (id INTEGER PRIMARY KEY, quote_number TEXT UNIQUE, contact_id INTEGER, company_id INTEGER, property_id INTEGER,
            match_how TEXT, client_name TEXT, client_email TEXT, service_street TEXT, title TEXT, status TEXT, status_raw TEXT, subtotal REAL, total REAL,
            drafted_date TEXT, sent_date TEXT, approved_date TEXT, converted_date TEXT, archived_date TEXT, line_items TEXT, imported_at TEXT)");
        return $db;
    }

    public function testParseReadsDatesStatusAndDashes(): void
    {
        $p = JobberQuoteHistory::parse($this->csv([
            '908,Susan Lim,s@x.ca,(604) 555-0100,"1 Main St",Moss Control + Rake,Converted,"Power Rake (1, $185.00)",185.0,194.25,"Mar 03, 2025","Mar 03, 2025","Mar 04, 2025","Mar 05, 2025",-',
        ]));
        $this->assertNull($p['error']);
        $r = $p['rows'][0];
        $this->assertSame('908', $r['quote_number']);
        $this->assertSame('converted', $r['status']);
        $this->assertSame('2025-03-04', $r['approved_date']);
        $this->assertNull($r['archived_date']);
        $this->assertSame(194.25, $r['total']);
    }

    public function testRejectsAnExportThatIsNotQuotes(): void
    {
        $this->assertNotNull(JobberQuoteHistory::parse("Invoice #,Client name\n1,x\n")['error']);
    }

    public function testOutcomeLabels(): void
    {
        $this->assertSame('accepted', JobberQuoteHistory::outcome('converted'));
        $this->assertSame('accepted', JobberQuoteHistory::outcome('archived', '2024-01-01'));   // approved, later archived
        $this->assertSame('archived', JobberQuoteHistory::outcome('archived'));
        $this->assertSame('open', JobberQuoteHistory::outcome('awaiting'));
        $this->assertSame('draft', JobberQuoteHistory::outcome('draft'));
    }

    public function testImportIsIdempotentAndUpdatesInPlace(): void
    {
        $db = $this->db();
        $svc = new JobberQuoteHistory($db);
        $row = '223,Susan Lim,,,"1 Main St",Mulch,Archived,"Mulch - Aged (8, $960.00)",960,1008,"Mar 22, 2019","Mar 22, 2019",-,-,"Apr 01, 2019"';
        $a = $svc->import($this->csv([$row]));
        $this->assertSame(['rows' => 1, 'inserted' => 1, 'updated' => 0, 'linked' => 0], $a);
        $b = $svc->import($this->csv([str_replace('Archived', 'Converted', $row)]));
        $this->assertSame(1, $b['updated']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM jobber_quotes")->fetchColumn());
        $this->assertSame('converted', $db->query("SELECT status FROM jobber_quotes")->fetchColumn());
    }

    public function testForScopeReturnsOutcomeAndLines(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO jobber_quotes (quote_number, company_id, client_name, title, status, total, sent_date, line_items)
                   VALUES ('1', 14, 'Beedie', 'Fall clean up', 'archived', 1300, '2025-10-01', 'Fall Clean Up (2, \$1,300.00)'),
                          ('2', 99, 'Other', 'x', 'converted', 5, '2025-10-02', NULL)");
        $q = (new JobberQuoteHistory($db))->forScope(['company_id = ?', [14]]);
        $this->assertCount(1, $q);
        $this->assertSame('archived', $q[0]['outcome']);
        $this->assertSame(1300.0, $q[0]['lines'][0]['total']);
    }

    public function testRealQuotesExportParsesEveryRow(): void
    {
        $f = glob('/Users/timschofield/Library/Containers/com.apple.mail/Data/Library/Mail Downloads/FD*/Quotes_Report*.csv');
        if (!$f) $this->markTestSkipped('real export not on this machine');
        $p = JobberQuoteHistory::parse((string)file_get_contents($f[0]));
        $this->assertNull($p['error']);
        $this->assertCount(950, $p['rows']);
        $this->assertSame(0, count(array_filter($p['rows'], fn($r) => $r['status'] === 'unknown')));
    }
}
