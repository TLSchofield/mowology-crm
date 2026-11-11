<?php
/**
 * MiaTokenVault — where Mia keeps the credentials a website connector needs (Phase 2.2).
 *
 * Reuses the CRM's existing encryption (SocialEncryption, SOCIAL_ENCRYPTION_KEY), so there is no second key store.
 * A secret goes in once and is never read back to a browser or written to a log: only get() returns it, and only
 * to server code that is about to use it. A secret that no longer decrypts (key changed) is reported as unusable,
 * never returned as an empty value.
 * Nothing uses this yet: it exists so the WordPress connector does not have to invent storage.
 */
require_once dirname(__DIR__, 2) . '/Social/Services/SocialEncryption.php';

class MiaTokenVault
{
    public const KINDS = ['wp_app_password', 'api_token'];
    public const MAX_LENGTH = 4096;

    private PDO $db;
    private int $siteId;

    public function __construct(PDO $db, int $siteId)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaTokenVault needs a site'); }
        $this->db = $db;
        $this->siteId = $siteId;
    }

    /** @return array{ok:bool, error?:string} */
    public function put(int $websiteId, string $kind, string $secret, int $userId, ?DateTimeImmutable $now = null): array
    {
        if (!in_array($kind, self::KINDS, true)) { return ['ok' => false, 'error' => 'Unknown kind of credential']; }
        if ($websiteId <= 0 || $userId <= 0) { return ['ok' => false, 'error' => 'A website and a signed-in person are required']; }
        $secret = trim($secret);
        if ($secret === '' || strlen($secret) > self::MAX_LENGTH) { return ['ok' => false, 'error' => 'The credential is empty or too long']; }
        $own = $this->db->prepare("SELECT 1 FROM mia_websites WHERE id = ? AND site_id = ?");
        $own->execute([$websiteId, $this->siteId]);
        if (!$own->fetchColumn()) { return ['ok' => false, 'error' => 'That website is not connected']; }
        $enc = SocialEncryption::encrypt($secret);
        if ($enc === '') { return ['ok' => false, 'error' => 'The credential could not be encrypted, so nothing was saved']; }
        $at = ($now ?: new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $u = $this->db->prepare("UPDATE mia_secrets SET secret_enc = ?, saved_by = ?, saved_at = ? WHERE site_id = ? AND website_id = ? AND kind = ?");
        $u->execute([$enc, $userId, $at, $this->siteId, $websiteId, $kind]);
        if ($u->rowCount() === 0) {
            $this->db->prepare("INSERT INTO mia_secrets (site_id, website_id, kind, secret_enc, saved_by, saved_at) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$this->siteId, $websiteId, $kind, $enc, $userId, $at]);
        }
        return ['ok' => true];
    }

    /** The plaintext for server-side use, or null when none is stored or it cannot be decrypted. */
    public function get(int $websiteId, string $kind): ?string
    {
        $s = $this->db->prepare("SELECT secret_enc FROM mia_secrets WHERE site_id = ? AND website_id = ? AND kind = ?");
        $s->execute([$this->siteId, $websiteId, $kind]);
        $enc = $s->fetchColumn();
        if (!is_string($enc) || $enc === '') { return null; }
        $plain = SocialEncryption::decrypt($enc);
        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    /** What the owner may see: whether one is saved and usable, never the value. @return array{saved:bool, usable:bool, saved_at:?string} */
    public function status(int $websiteId, string $kind): array
    {
        $s = $this->db->prepare("SELECT saved_at FROM mia_secrets WHERE site_id = ? AND website_id = ? AND kind = ?");
        $s->execute([$this->siteId, $websiteId, $kind]);
        $at = $s->fetchColumn();
        if ($at === false) { return ['saved' => false, 'usable' => false, 'saved_at' => null]; }
        return ['saved' => true, 'usable' => $this->get($websiteId, $kind) !== null, 'saved_at' => (string)$at];
    }

    public function forget(int $websiteId, string $kind): bool
    {
        $d = $this->db->prepare("DELETE FROM mia_secrets WHERE site_id = ? AND website_id = ? AND kind = ?");
        $d->execute([$this->siteId, $websiteId, $kind]);
        return $d->rowCount() > 0;
    }
}
