<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Phase 2.2: credentials for website connectors are stored encrypted and never shown. */
final class MiaTokenVaultTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!defined('SOCIAL_ENCRYPTION_KEY')) { define('SOCIAL_ENCRYPTION_KEY', base64_encode(str_repeat('k', 32))); }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY, site_id INT)");
        $this->db->exec("INSERT INTO mia_websites VALUES (1, 1), (2, 2)");
        $this->db->exec("CREATE TABLE mia_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, website_id INT, kind TEXT, secret_enc TEXT, saved_by INT, saved_at TEXT, UNIQUE(site_id, website_id, kind))");
    }

    public function testASecretIsStoredEncryptedAndReadBackOnlyByServerCode(): void
    {
        $v = new MiaTokenVault($this->db, 1);
        $this->assertTrue($v->put(1, 'wp_app_password', 'abcd efgh ijkl', 7)['ok']);
        $raw = (string)$this->db->query("SELECT secret_enc FROM mia_secrets")->fetchColumn();
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('abcd', $raw, 'never stored as plain text');
        $this->assertSame('abcd efgh ijkl', $v->get(1, 'wp_app_password'));
    }

    public function testStatusNeverContainsTheSecret(): void
    {
        $v = new MiaTokenVault($this->db, 1);
        $this->assertSame(['saved' => false, 'usable' => false, 'saved_at' => null], $v->status(1, 'api_token'));
        $v->put(1, 'api_token', 'topsecretvalue', 7, new DateTimeImmutable('2026-10-26 09:00:00'));
        $st = $v->status(1, 'api_token');
        $this->assertSame(['saved' => true, 'usable' => true, 'saved_at' => '2026-10-26 09:00:00'], $st);
        $this->assertStringNotContainsString('topsecretvalue', json_encode($st));
    }

    public function testSavingAgainReplacesAndForgetRemoves(): void
    {
        $v = new MiaTokenVault($this->db, 1);
        $v->put(1, 'api_token', 'one', 7);
        $v->put(1, 'api_token', 'two', 7);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM mia_secrets")->fetchColumn());
        $this->assertSame('two', $v->get(1, 'api_token'));
        $this->assertTrue($v->forget(1, 'api_token'));
        $this->assertNull($v->get(1, 'api_token'));
        $this->assertFalse($v->forget(1, 'api_token'));
    }

    public function testBadInputAndOtherSitesAreRefused(): void
    {
        $v = new MiaTokenVault($this->db, 1);
        $this->assertFalse($v->put(1, 'password', 'x', 7)['ok'], 'unknown kind');
        $this->assertFalse($v->put(1, 'api_token', '   ', 7)['ok'], 'empty');
        $this->assertFalse($v->put(1, 'api_token', str_repeat('a', 5000), 7)['ok'], 'too long');
        $this->assertFalse($v->put(1, 'api_token', 'x', 0)['ok'], 'no person');
        $this->assertFalse($v->put(2, 'api_token', 'x', 7)['ok'], 'a website of another site');
        $this->assertFalse($v->put(99, 'api_token', 'x', 7)['ok'], 'no such website');
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_secrets")->fetchColumn());
        $this->assertNull((new MiaTokenVault($this->db, 2))->get(1, 'api_token'), 'a different site cannot read it');
    }

    public function testASecretThatNoLongerDecryptsIsReportedUnusableNotEmpty(): void
    {
        $v = new MiaTokenVault($this->db, 1);
        $v->put(1, 'api_token', 'x1', 7);
        $this->db->exec("UPDATE mia_secrets SET secret_enc = 'bm90LWEtcmVhbC1jaXBoZXJ0ZXh0LWF0LWFsbC0xMjM0NTY3ODkwMTIzNDU2'");
        $this->assertNull($v->get(1, 'api_token'));
        $this->assertSame(['saved' => true, 'usable' => false], array_intersect_key($v->status(1, 'api_token'), ['saved' => 1, 'usable' => 1]));
    }
}
