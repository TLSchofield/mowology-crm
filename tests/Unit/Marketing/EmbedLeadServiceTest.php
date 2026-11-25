<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tenant isolation for the embeddable lead form (in-memory SQLite, portable SQL only). */
class EmbedLeadServiceTest extends TestCase
{
    private PDO $db;
    private EmbedLeadService $svc;
    private const KEY_A = 'aaaaaaaaaaaaaaaaaaaaaaaa';
    private const KEY_B = 'bbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE cms_sites (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, email TEXT, lead_notify_email TEXT,
            embed_key TEXT, embed_origins TEXT, embed_enabled INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1)");
        $this->db->exec("CREATE TABLE cms_form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL DEFAULT 1,
            form_id TEXT, name TEXT, email TEXT, phone TEXT, message TEXT, source_url TEXT, ip_address TEXT, created_at TEXT)");
        $this->db->exec("INSERT INTO cms_sites (id, slug, name, email, lead_notify_email, embed_key, embed_origins, embed_enabled)
            VALUES (1, 'mowology', 'Mowology', 'o@m.ca', NULL, NULL, NULL, 0),
                   (2, 'talkinghands', 'Talking Hands', 'i@t.ca', 'collective@t.ca', '" . self::KEY_A . "', 'https://talkinghands.ca', 1),
                   (3, 'other', 'Other', 'o@o.ca', NULL, '" . self::KEY_B . "', 'https://other.ca', 1)");
        $this->svc = new EmbedLeadService($this->db);
    }

    private function lead(string $name = 'Sam'): array
    {
        return ['form_id' => 'embed-contact', 'name' => $name, 'email' => 's@x.ca', 'phone' => '', 'message' => 'hi', 'source_url' => 'https://talkinghands.ca/'];
    }

    public function test_key_resolves_only_its_own_enabled_tenant(): void
    {
        $this->assertSame(2, (int)$this->svc->siteForKey(self::KEY_A)['id']);
        $this->assertSame(3, (int)$this->svc->siteForKey(self::KEY_B)['id']);
        $this->assertNull($this->svc->siteForKey(str_repeat('c', 24)));
        $this->assertNull($this->svc->siteForKey("x' OR '1'='1"));
    }

    public function test_disabled_or_inactive_tenant_is_not_resolved(): void
    {
        $this->db->exec("UPDATE cms_sites SET embed_enabled = 0 WHERE id = 2");
        $this->assertNull($this->svc->siteForKey(self::KEY_A));
        $this->db->exec("UPDATE cms_sites SET embed_enabled = 1, is_active = 0 WHERE id = 2");
        $this->assertNull($this->svc->siteForKey(self::KEY_A));
    }

    public function test_a_lead_is_stored_under_its_own_tenant_only(): void
    {
        $id = $this->svc->store(2, $this->lead(), '203.0.113.9', '2026-10-18 10:00:00');
        $row = $this->db->query("SELECT * FROM cms_form_submissions WHERE id = $id")->fetch();
        $this->assertSame(2, (int)$row['site_id']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM cms_form_submissions WHERE site_id = 1")->fetchColumn());
    }

    public function test_limits_count_per_tenant_not_across_tenants(): void
    {
        for ($i = 0; $i < 3; $i++) $this->svc->store(2, $this->lead("A$i"), '203.0.113.9', '2026-10-18 10:00:00');
        $this->svc->store(1, $this->lead('M'), '203.0.113.9', '2026-10-18 10:00:00');
        $since = '2026-10-17 12:00:00';
        $this->assertSame(3, $this->svc->countFromIp(2, '203.0.113.9', $since));
        $this->assertSame(0, $this->svc->countFromIp(3, '203.0.113.9', $since), 'same visitor, different tenant: own allowance');
        $this->assertSame(3, $this->svc->countForSite(2, $since));
        $this->assertSame(1, $this->svc->countForSite(1, $since));
    }

    public function test_old_enquiries_fall_out_of_the_window(): void
    {
        $this->svc->store(2, $this->lead(), '203.0.113.9', '2026-10-10 10:00:00');
        $this->assertSame(0, $this->svc->countFromIp(2, '203.0.113.9', '2026-10-17 12:00:00'));
    }
}
