<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A rejected receipt kept as the paper for a filed entry (migration 1421; Tim, 2026-10-26). */
class ReceiptDocumentLinkServiceTest extends TestCase
{
    private PDO $db;
    private ReceiptDocumentLinkService $svc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, vendor_id INTEGER, vendor_name_raw TEXT, expense_date TEXT, total REAL, status TEXT, receipt_media_id INTEGER)");
        $this->db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, source_type TEXT, source_id INTEGER, status TEXT, reversed_by_entry_id INTEGER)");
        $this->db->exec("CREATE TABLE receipt_document_links (id INTEGER PRIMARY KEY AUTOINCREMENT, expense_id INTEGER NOT NULL, bank_transaction_id INTEGER, journal_entry_id INTEGER,
            relation TEXT NOT NULL DEFAULT 'supports', note TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        // Midland Liquidators: receipt #16 (rejected) documents bank line 5474, whose live entry is #823 (filed 2025).
        $this->db->exec("INSERT INTO expenses VALUES (16, NULL, 'MIDLAND LIQUIDATORS', '2025-04-29', 53.21, 'rejected', 54)");
        $this->db->exec("INSERT INTO expenses VALUES (15, NULL, 'MIDLAND LIQUIDATORS', '2025-04-29', 2.38, 'rejected', 53)");
        $this->db->exec("INSERT INTO journal_entries VALUES (823, 'bank_import', 5474, 'posted', NULL)");
        $this->db->exec("INSERT INTO journal_entries VALUES (900, 'bank_import', 5474, 'posted', 901)");   // an older, reversed one: not the live entry
        $this->svc = new ReceiptDocumentLinkService($this->db);
    }

    public function testALinkFindsTheLiveEntryOfItsBankLine(): void
    {
        $r = $this->svc->link(16, 5474, null, 'supports', 'Same receipt as #15', 6);
        $this->assertTrue($r['ok']);
        $row = $this->db->query("SELECT * FROM receipt_document_links WHERE id = " . (int)$r['id'])->fetch();
        $this->assertSame(823, (int)$row['journal_entry_id']);
        $this->assertSame(6, (int)$row['created_by']);
    }

    public function testLinkingTwiceDoesNotDuplicate(): void
    {
        $a = $this->svc->link(16, 5474, null, 'supports', '', null);
        $b = $this->svc->link(16, 5474, null, 'supports', 'again', null);
        $this->assertSame('Already linked', $b['message']);
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM receipt_document_links")->fetchColumn());
    }

    public function testTheEntryAndItsBankLineBothFindTheDocument(): void
    {
        $this->svc->link(16, 5474, null, 'supports', 'The paper', null);
        $this->svc->link(15, 5474, null, 'copy_of', 'merged into #16', null);
        $docs = $this->svc->forEntry(823, 5474);
        $this->assertCount(2, $docs);
        $this->assertSame([16, 15], array_column($docs, 'expense_id'));
        $this->assertSame('rejected', $docs[0]['status']);
        $this->assertSame(54, $docs[0]['media_id']);
        $this->assertSame('Another copy of the receipt for this entry', $docs[1]['relation_label']);
        $this->assertSame([], $this->svc->forEntry(824, null), 'another entry has none');
    }

    public function testBadInputIsRefused(): void
    {
        $this->assertFalse($this->svc->link(0, 5474, null, 'supports', '', null)['ok']);
        $this->assertFalse($this->svc->link(16, null, null, 'supports', '', null)['ok']);
        $this->assertFalse($this->svc->link(16, 5474, null, 'nonsense', '', null)['ok']);
    }

    public function testBeforeTheMigrationItIsQuietNotBroken(): void
    {
        $svc = new ReceiptDocumentLinkService(new PDO('sqlite::memory:'));
        $this->assertFalse($svc->ready());
        $this->assertSame([], $svc->forEntry(823, 5474));
        $this->assertFalse($svc->link(16, 5474, null, 'supports', '', null)['ok']);
    }

    public function testASuppliedEntryMustBelongToTheBankLine(): void
    {
        $this->db->exec("INSERT INTO journal_entries VALUES (700, 'invoice', 12, 'posted', NULL)");
        $bad = $this->svc->link(16, 5474, 700, 'supports', '', null);
        $this->assertFalse($bad['ok']);
        $this->assertSame('That entry was not posted by that bank line', $bad['message']);
        $this->assertTrue($this->svc->link(16, 5474, 823, 'supports', '', null)['ok']);
    }

    public function testSeveralLiveEntriesForOneBankLineAreNotGuessed(): void
    {
        $this->db->exec("INSERT INTO journal_entries VALUES (650, 'bank_import', 5474, 'posted', NULL)");   // now two live entries for 5474
        $this->assertNull($this->svc->liveEntryForBankLine(5474));
        $r = $this->svc->link(16, 5474, null, 'supports', '', null);
        $this->assertTrue($r['ok']);
        $this->assertNull($this->db->query("SELECT journal_entry_id FROM receipt_document_links WHERE id = " . (int)$r['id'])->fetchColumn(), 'linked to the bank line only');
        $this->assertCount(1, $this->svc->forEntry(823, 5474), 'found through the bank line');
    }
}

