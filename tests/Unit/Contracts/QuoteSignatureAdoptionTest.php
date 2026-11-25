<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * adoptQuoteSignature() — a contract made from a contract quote that was signed
 * online starts signed, carrying that signature and the wording the client saw.
 *
 * Runs against in-memory SQLite.
 */
class QuoteSignatureAdoptionTest extends TestCase
{
    private PDO $db;
    private ContractService $svc;

    private const SNOW = "TERM\n\nThe Company assumes no responsibility for slip and fall accidents.";

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', static fn () => date('Y-m-d H:i:s'));

        $this->db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, quote_number TEXT, status TEXT,
            terms TEXT, signature_data TEXT, signature_timestamp TEXT, accepted_at TEXT,
            accepted_by_name TEXT, accepted_by_email TEXT, accepted_ip_address TEXT)");
        $this->db->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, contract_number TEXT,
            title TEXT, signature_status TEXT DEFAULT 'unsigned', current_version INT DEFAULT 1,
            billing_cycle TEXT, billing_amount TEXT, invoice_timing TEXT, start_date TEXT,
            end_date TEXT, renewal_date TEXT, auto_renew INT DEFAULT 0,
            renewal_increase_pct TEXT DEFAULT '0.00', notes TEXT, terms_template_id INT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE contract_versions (id INTEGER PRIMARY KEY, contract_id INT,
            version_number INT, changed_by INT, change_reason TEXT, title TEXT, billing_cycle TEXT,
            billing_amount TEXT, invoice_timing TEXT, start_date TEXT, end_date TEXT, renewal_date TEXT,
            auto_renew INT, renewal_increase_pct TEXT, notes TEXT, signature_status TEXT,
            signed_at TEXT, signed_by_name TEXT, terms_template_id INT, terms_template_version INT,
            terms_body TEXT)");
        $this->db->exec("CREATE TABLE contract_signatures (id INTEGER PRIMARY KEY, contract_id INT,
            contract_version INT, signature_token TEXT, token_expires_at TEXT, signer_name TEXT,
            signer_email TEXT, status TEXT, signature_method TEXT DEFAULT 'electronic',
            signature_data TEXT, terms_acknowledged INT DEFAULT 0, terms_body_hash TEXT,
            signed_at TEXT, signed_ip TEXT, sent_by INT, created_at TEXT)");
        $this->db->exec("CREATE TABLE contract_terms_templates (id INTEGER PRIMARY KEY, name TEXT,
            scope TEXT, service_type TEXT, body TEXT, version INT DEFAULT 1, is_active INT DEFAULT 1,
            is_default INT DEFAULT 0, sort_order INT DEFAULT 0)");
        $this->db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, contract_id INT, service_type TEXT)");

        $this->db->prepare("INSERT INTO contract_terms_templates (id, name, scope, service_type, body, version)
                            VALUES (1, 'Snow & Ice', 'service_type', 'snow_removal', ?, 3)")->execute([self::SNOW]);
        $this->db->exec("INSERT INTO contracts (id, contract_number, terms_template_id, current_version)
                         VALUES (1, 'CTR-2026-0001', 1, 1)");

        $this->svc = new ContractService($this->db);
    }

    private function quote(array $over = []): void
    {
        $row = array_merge([
            'id' => 9, 'quote_number' => 'QUO-2026-0059', 'status' => 'accepted', 'terms' => self::SNOW,
            'signature_data' => 'data:image/png;base64,AAAA', 'signature_timestamp' => '2026-10-20 09:15:00',
            'accepted_at' => '2026-10-20 09:15:00', 'accepted_by_name' => 'Eva Duran',
            'accepted_by_email' => 'eva@example.com', 'accepted_ip_address' => '203.0.113.7',
        ], $over);
        $this->db->prepare("INSERT INTO quotes (" . implode(',', array_keys($row)) . ")
                            VALUES (" . implode(',', array_fill(0, count($row), '?')) . ")")
                 ->execute(array_values($row));
    }

    private function one(string $sql): array
    {
        return $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function testSignedQuoteMakesTheContractSigned(): void
    {
        $this->quote();
        $this->assertTrue($this->svc->adoptQuoteSignature(1, 9, 7));

        $this->assertSame('signed', $this->one("SELECT signature_status FROM contracts WHERE id = 1")['signature_status']);

        $sig = $this->one("SELECT * FROM contract_signatures WHERE contract_id = 1");
        $this->assertSame('signed', $sig['status']);
        $this->assertSame('Eva Duran', $sig['signer_name']);
        $this->assertSame('data:image/png;base64,AAAA', $sig['signature_data']);
        $this->assertSame('2026-10-20 09:15:00', $sig['signed_at']);
        $this->assertSame('203.0.113.7', $sig['signed_ip']);
        $this->assertSame(1, (int)$sig['terms_acknowledged']);
        $this->assertSame(ContractTermsService::hashBody(self::SNOW), $sig['terms_body_hash']);

        $ver = $this->one("SELECT * FROM contract_versions WHERE contract_id = 1");
        $this->assertSame('signed', $ver['signature_status']);
        $this->assertSame('Eva Duran', $ver['signed_by_name']);
        $this->assertSame(self::SNOW, $ver['terms_body']);
        $this->assertSame(3, (int)$ver['terms_template_version'], 'matching text keeps the revision');
    }

    public function testDifferentQuoteWordingIsSealedAndRevisionCleared(): void
    {
        $edited = self::SNOW . "\n\nExtra clause typed on this quote.";
        $this->quote(['terms' => $edited]);
        $this->assertTrue($this->svc->adoptQuoteSignature(1, 9, 7));

        $ver = $this->one("SELECT terms_body, terms_template_version FROM contract_versions WHERE contract_id = 1");
        $this->assertSame($edited, $ver['terms_body'], 'the version holds what the client saw');
        $this->assertNull($ver['terms_template_version'], 'never claim a revision the client did not see');
        $this->assertSame(ContractTermsService::hashBody($edited),
            $this->one("SELECT terms_body_hash FROM contract_signatures")['terms_body_hash']);
    }

    public function testVerbalApprovalCarriesNothing(): void
    {
        $this->quote(['signature_data' => null, 'accepted_by_name' => 'Customer (verbal)']);
        $this->assertFalse($this->svc->adoptQuoteSignature(1, 9, 7));
        $this->assertSame('unsigned', $this->one("SELECT signature_status FROM contracts WHERE id = 1")['signature_status']);
        $this->assertSame([], $this->one("SELECT * FROM contract_signatures"));
    }

    public function testUnacceptedQuoteCarriesNothing(): void
    {
        $this->quote(['status' => 'sent']);
        $this->assertFalse($this->svc->adoptQuoteSignature(1, 9, 7));
    }

    public function testAlreadySignedContractIsLeftAlone(): void
    {
        $this->quote();
        $this->db->exec("UPDATE contracts SET signature_status = 'signed' WHERE id = 1");
        $this->assertFalse($this->svc->adoptQuoteSignature(1, 9, 7));
        $this->assertSame([], $this->one("SELECT * FROM contract_signatures"));
    }
}
