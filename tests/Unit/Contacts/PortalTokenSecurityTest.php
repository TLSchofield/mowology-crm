<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Contacts/Services/PortalTokenService.php';
require_once __DIR__ . '/../../../app/Modules/Contacts/Services/PortalEmailChangeService.php';

/** Security review #14 (2026-10-12): portal links expire and rotate; email changes need the new address. */
class PortalTokenSecurityTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $now = static fn() => date('Y-m-d H:i:s');
        if (method_exists($db, 'createFunction')) $db->createFunction('NOW', $now, 0); else $db->sqliteCreateFunction('NOW', $now, 0);
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, email TEXT, portal_token TEXT, portal_token_issued_at TEXT)");
        $db->exec("INSERT INTO contacts VALUES (7, 'Jo', 'jo@old.ca', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '2026-10-12 00:00:00')");
        $db->exec("CREATE TABLE contact_email_changes (id INTEGER PRIMARY KEY, contact_id INTEGER, old_email TEXT, new_email TEXT,
                   token_hash TEXT UNIQUE, expires_at TEXT, confirmed_at TEXT, ip_address TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE activity_log (id INTEGER PRIMARY KEY, user_id INTEGER, contact_id INTEGER, action TEXT, details TEXT, ip_address TEXT)");
        return $db;
    }

    public function test_tokens_live_365_days_and_undated_tokens_are_dead(): void
    {
        $now = strtotime('2026-10-12 12:00:00');
        $this->assertTrue(PortalTokenService::isLive('2026-10-12 00:00:00', $now));
        $this->assertTrue(PortalTokenService::isLive('2025-10-13 13:00:00', $now));
        $this->assertFalse(PortalTokenService::isLive('2025-10-12 11:00:00', $now));
        $this->assertFalse(PortalTokenService::isLive(null, $now));
    }

    public function test_live_sql_only_after_migration(): void
    {
        $this->assertStringContainsString("c.portal_token_issued_at > DATE_SUB(NOW(), INTERVAL 365 DAY)", PortalTokenService::liveSql($this->db(), 'c'));
        $bare = new PDO('sqlite::memory:');
        $bare->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, portal_token TEXT)");
        $this->assertSame('', PortalTokenService::liveSql($bare));
    }

    public function test_regenerate_kills_the_old_link_and_stamps_the_new_one(): void
    {
        $db = $this->db();
        $new = PortalTokenService::regenerate($db, 7, 1, '10.0.0.1');
        $row = $db->query("SELECT portal_token, portal_token_issued_at FROM contacts WHERE id = 7")->fetch();
        $this->assertSame($new, $row['portal_token']);
        $this->assertNotSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $new);
        $this->assertNotEmpty($row['portal_token_issued_at']);
        $this->assertSame('Portal link regenerated', $db->query("SELECT action FROM activity_log")->fetchColumn());
    }

    public function test_email_change_waits_for_the_new_address_and_tells_the_old(): void
    {
        $db = $this->db();
        $sent = [];
        $svc = new PortalEmailChangeService($db, function (string $to, string $subject, string $html) use (&$sent) { $sent[] = [$to, $subject, $html]; return true; });
        $this->assertTrue($svc->request(7, 'jo@old.ca', 'thief@evil.com', 'Jo', '10.0.0.2', 'https://mowology.ca'));
        $this->assertSame('jo@old.ca', $db->query("SELECT email FROM contacts WHERE id = 7")->fetchColumn(), 'not applied yet');
        $this->assertSame(['thief@evil.com', 'jo@old.ca'], array_column($sent, 0));
        $this->assertStringContainsString('t****@evil.com', $sent[1][2]);
        preg_match('/t=([a-f0-9]{64})/', $sent[0][2], $m);
        $this->assertSame('thief@evil.com', $svc->confirm($m[1], '10.0.0.3'));
        $this->assertSame('thief@evil.com', $db->query("SELECT email FROM contacts WHERE id = 7")->fetchColumn());
        $this->assertNull($svc->confirm($m[1], '10.0.0.3'), 'single use');
        $this->assertNull($svc->confirm(str_repeat('0', 64), ''));
    }

    public function test_same_or_blank_email_needs_no_confirmation(): void
    {
        $this->assertFalse(PortalEmailChangeService::needsConfirmation('Jo@Old.ca', 'jo@old.ca '));
        $this->assertFalse(PortalEmailChangeService::needsConfirmation('jo@old.ca', ''));
        $this->assertTrue(PortalEmailChangeService::needsConfirmation(null, 'jo@new.ca'));
    }

    public function test_marketing_opt_in_writes_a_consent_log_row(): void
    {
        $db = $this->db();
        $db->exec("CREATE TABLE consent_log (id INTEGER PRIMARY KEY, contact_id INTEGER, consent_type TEXT, consent_given INTEGER,
                   consent_text TEXT, consent_source TEXT, ip_address TEXT, user_agent TEXT)");
        PortalProfileAudit::record($db, 7, PortalProfileAudit::diff(['receive_marketing' => 0], ['receive_marketing' => 1]), '10.0.0.4', 'UA');
        $row = $db->query("SELECT consent_type, consent_given, consent_source, ip_address FROM consent_log")->fetch();
        $this->assertSame(['consent_type' => 'marketing_email', 'consent_given' => 1, 'consent_source' => 'customer_portal', 'ip_address' => '10.0.0.4'], $row);
    }

    public function test_every_customer_token_lookup_checks_expiry(): void
    {
        $root = dirname(__DIR__, 3) . '/public/customer';
        $bad = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (substr((string)$f, -4) !== '.php') continue;
            foreach (file((string)$f) as $n => $line) {
                if (preg_match('/portal_token\s*=\s*\?/', $line) && strpos($line, 'liveSql') === false) $bad[] = basename((string)$f) . ':' . ($n + 1);
            }
        }
        $this->assertSame([], $bad);
        $upd = (string)file_get_contents($root . '/api/profile-update.php');
        $this->assertStringContainsString('PortalEmailChangeService', $upd);
        $this->assertStringContainsString('$email = $oldEmail;', $upd);
    }
}
