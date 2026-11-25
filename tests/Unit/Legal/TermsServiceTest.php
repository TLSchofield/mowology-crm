<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * TermsService — the sign-in gate must be off until a version is activated,
 * must clear once the user accepts, and must come back when a new version ships.
 * Runs against in-memory SQLite mirroring migration 1381.
 */
class TermsServiceTest extends TestCase
{
    private PDO $db;
    private TermsService $svc;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE terms_versions (id INTEGER PRIMARY KEY, doc_key TEXT DEFAULT 'platform',
            version TEXT, is_current INT DEFAULT 0, activated_at TEXT, created_at TEXT)");
        $this->db->exec("CREATE TABLE terms_acceptances (id INTEGER PRIMARY KEY, user_id INT,
            doc_key TEXT DEFAULT 'platform', version TEXT, accepted_at TEXT DEFAULT CURRENT_TIMESTAMP,
            ip_address TEXT, user_agent TEXT, UNIQUE (user_id, doc_key, version))");
        $this->svc = new TermsService($this->db);
    }

    private function activate(string $version): void
    {
        $this->db->exec("UPDATE terms_versions SET is_current = 0");
        $this->db->exec("INSERT INTO terms_versions (version, is_current) VALUES ('$version', 1)");
    }

    public function testGateIsOffWhenNoVersionIsActive(): void
    {
        $this->assertNull($this->svc->currentVersion());
        $this->assertFalse($this->svc->needsAcceptance(1));
        $this->assertFalse($this->svc->recordAcceptance(1, '1.2.3.4', 'UA'));
    }

    public function testNeedsAcceptanceUntilAccepted(): void
    {
        $this->activate('2026-11-01');
        $this->assertTrue($this->svc->needsAcceptance(7));
        $this->assertTrue($this->svc->recordAcceptance(7, '1.2.3.4', 'UA'));
        $this->assertFalse($this->svc->needsAcceptance(7));
        $this->assertTrue($this->svc->needsAcceptance(8), 'another user is unaffected');
    }

    public function testRecordingTwiceKeepsOneRow(): void
    {
        $this->activate('2026-11-01');
        $this->svc->recordAcceptance(7, '1.2.3.4', 'UA');
        $this->svc->recordAcceptance(7, '5.6.7.8', 'UA2');
        $count = (int)$this->db->query("SELECT COUNT(*) FROM terms_acceptances")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testNewVersionAsksAgainAndKeepsOldAcceptance(): void
    {
        $this->activate('2026-11-01');
        $this->svc->recordAcceptance(7, null, null);
        $this->activate('2027-03-01');
        $this->assertTrue($this->svc->needsAcceptance(7));
        $old = (int)$this->db->query("SELECT COUNT(*) FROM terms_acceptances WHERE version = '2026-11-01'")->fetchColumn();
        $this->assertSame(1, $old);
    }

    public function testMissingTablesFailOpen(): void
    {
        $svc = new TermsService(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
        $this->assertFalse($svc->needsAcceptance(1));
    }
}
