<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * recordWetSignature() — filing a contract the client printed and signed by hand.
 *
 * The electronic path proves what was on screen, when, and from where. A scan
 * proves none of that. The one thing that can still be tied to it is the terms
 * revision printed in the PDF footer, so the guard that matters here is that it
 * cannot be filed without one — a signature with no provable link to the
 * disclaimers looks like a record while being worth less than none.
 *
 * Runs against in-memory SQLite.
 */
class WetSignatureTest extends TestCase
{
    private PDO $db;
    private ContractService $svc;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // The service writes MySQL's NOW() — shim it rather than weaken the
        // production SQL to suit the test database.
        $this->db->sqliteCreateFunction('NOW', static fn () => date('Y-m-d H:i:s'));

        $this->db->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, contract_number TEXT,
            title TEXT, signature_status TEXT DEFAULT 'unsigned', current_version INT DEFAULT 1,
            billing_cycle TEXT, billing_amount TEXT, invoice_timing TEXT, start_date TEXT,
            end_date TEXT, renewal_date TEXT, auto_renew INT DEFAULT 1,
            renewal_increase_pct TEXT DEFAULT '0.00', notes TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE contract_signatures (id INTEGER PRIMARY KEY, contract_id INT,
            contract_version INT, signature_token TEXT, token_expires_at TEXT, signer_name TEXT,
            signer_email TEXT, status TEXT, signature_method TEXT DEFAULT 'electronic',
            signature_data TEXT, terms_acknowledged INT DEFAULT 0, terms_body_hash TEXT,
            wet_terms_version INT, wet_media_id INT, wet_filed_by INT, wet_filed_at TEXT,
            signed_at TEXT, signed_ip TEXT, sent_by INT, created_at TEXT)");
        $this->db->exec("CREATE TABLE contract_versions (id INTEGER PRIMARY KEY, contract_id INT,
            version_number INT, signature_status TEXT, signed_at TEXT, signed_by_name TEXT)");
        $this->db->exec("CREATE TABLE media_links (id INTEGER PRIMARY KEY, media_id INT,
            context_type TEXT, context_id INT, category TEXT, visibility TEXT, linked_by INT)");

        $this->db->exec("INSERT INTO contracts (id, contract_number, current_version)
                         VALUES (1, 'CTR-2026-0001', 1)");
        $this->db->exec("INSERT INTO contract_versions (contract_id, version_number) VALUES (1, 1)");

        $this->svc = new ContractService($this->db);
    }

    public function testFilingAPaperCopyMarksTheContractSigned(): void
    {
        $ok = $this->svc->recordWetSignature(1, 55, 2, 'Ron Harvie', '2026-11-04', 7);
        $this->assertTrue($ok);

        $c = $this->db->query("SELECT signature_status FROM contracts WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('signed', $c['signature_status']);

        $sig = $this->db->query("SELECT * FROM contract_signatures WHERE contract_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('wet', $sig['signature_method']);
        $this->assertSame(2, (int)$sig['wet_terms_version']);
        $this->assertSame(55, (int)$sig['wet_media_id']);
        $this->assertSame('Ron Harvie', $sig['signer_name']);
        $this->assertSame('2026-11-04', $sig['signed_at']);
    }

    public function testTermsRevisionIsRequired(): void
    {
        // The whole point of filing it. Without the revision the scan cannot be
        // tied to the disclaimers it was signed against.
        $this->expectException(InvalidArgumentException::class);
        $this->svc->recordWetSignature(1, 55, 0, 'Ron Harvie', '2026-11-04', 7);
    }

    public function testAScanIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->recordWetSignature(1, 0, 2, 'Ron Harvie', '2026-11-04', 7);
    }

    public function testTheFiledCopyDoesNotHandOutAUsableSigningLink(): void
    {
        $this->svc->recordWetSignature(1, 55, 2, 'Ron Harvie', '2026-11-04', 7);
        $sig = $this->db->query("SELECT signature_token, status FROM contract_signatures WHERE contract_id = 1")
                        ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('signed', $sig['status'], 'already spent — not pending');
        $this->assertSame(64, strlen((string)$sig['signature_token']));
    }

    public function testTheScanIsLinkedIntoTheMediaSystem(): void
    {
        $this->svc->recordWetSignature(1, 55, 2, 'Ron Harvie', '2026-11-04', 7);
        $link = $this->db->query("SELECT * FROM media_links WHERE context_type = 'contract'")
                         ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(55, (int)$link['media_id']);
        $this->assertSame(1, (int)$link['context_id']);
        $this->assertSame('signed_copy', $link['category']);
        $this->assertSame('internal', $link['visibility'], 'a signed contract is not client-gallery material');
    }

    public function testUnknownContractIsRejected(): void
    {
        $this->assertFalse($this->svc->recordWetSignature(999, 55, 2, 'Nobody', '2026-11-04', 7));
    }

    public function testTheVersionSnapshotIsSealedToo(): void
    {
        $this->svc->recordWetSignature(1, 55, 2, 'Ron Harvie', '2026-11-04', 7);
        $v = $this->db->query("SELECT * FROM contract_versions WHERE contract_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('signed', $v['signature_status']);
        $this->assertSame('Ron Harvie', $v['signed_by_name']);
    }
}
