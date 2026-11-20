<?php
/**
 * EmbedLeadService — data access for the embeddable lead form. Every query is scoped by site_id (the tenant).
 *
 * SQL is kept portable (timestamps are passed in, no NOW()) so the isolation test can run on SQLite.
 */
final class EmbedLeadService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The enabled, active tenant for a public key, or null. */
    public function siteForKey(string $key): ?array
    {
        if (!EmbedLeadRules::keyLooksValid($key)) return null;
        $st = $this->db->prepare(
            "SELECT id, slug, name, email, lead_notify_email, embed_origins
               FROM cms_sites
              WHERE embed_key = ? AND embed_enabled = 1 AND is_active = 1"
        );
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function countFromIp(int $siteId, string $ip, string $since): int
    {
        $st = $this->db->prepare(
            "SELECT COUNT(*) FROM cms_form_submissions WHERE site_id = ? AND ip_address = ? AND created_at > ?"
        );
        $st->execute([$siteId, $ip, $since]);
        return (int)$st->fetchColumn();
    }

    public function countForSite(int $siteId, string $since): int
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM cms_form_submissions WHERE site_id = ? AND created_at > ?");
        $st->execute([$siteId, $since]);
        return (int)$st->fetchColumn();
    }

    /** @param array $f fields from CmsFormSubmitRules::fields() */
    public function store(int $siteId, array $f, string $ip, string $now): int
    {
        $this->db->prepare(
            "INSERT INTO cms_form_submissions
                (site_id, form_id, name, email, phone, message, source_url, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $siteId, $f['form_id'], $f['name'], $f['email'],
            $f['phone'] !== '' ? $f['phone'] : null,
            $f['message'] !== '' ? $f['message'] : null,
            $f['source_url'] !== '' ? $f['source_url'] : null,
            $ip !== '' ? $ip : null,
            $now,
        ]);
        return (int)$this->db->lastInsertId();
    }
}
