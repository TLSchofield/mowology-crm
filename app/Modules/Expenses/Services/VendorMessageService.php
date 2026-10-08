<?php
/**
 * VendorMessageService — who is a vendor (from Penny's `vendors` directory) and the
 * read-only history of mail to/from them that is not a receipt.
 *
 * matchVendor() is how IcloudInboxRouter recognises a vendor: the sender's domain against
 * each vendor's website domain, name and aliases ("homedepot.ca" ↔ "HomeDepot"), or the
 * sender's display name containing the vendor name/alias as whole words ("The Home Depot").
 * Free-mail domains (gmail, icloud, shaw…) never match by domain, and aliases shorter than
 * 4 characters ("HD", "CTC") are ignored — too many false hits.
 *
 * vendor_messages (migration 1223) keeps the new text only (≤ 800 chars), once per
 * Message-ID. No namespace / no autoloader: require_once and `new`.
 */
class VendorMessageService
{
    /** Domains that say nothing about who the sender works for. */
    public const FREE_MAIL = ['gmail', 'googlemail', 'outlook', 'hotmail', 'live', 'msn', 'yahoo', 'icloud', 'me', 'mac',
                              'aol', 'shaw', 'telus', 'rogers', 'sympatico', 'protonmail', 'proton', 'gmx', 'mail'];
    /** Sub-domain labels that are not a company name. */
    private const NOISE_LABELS = ['www', 'mail', 'email', 'e', 'em', 'order', 'orders', 'shop', 'store', 'info', 'news',
                                  'notify', 'notifications', 'receipts', 'receipt', 'billing', 'invoices', 'mg', 'mx', 'send', 'reply'];
    private const MIN_KEY = 4;

    private PDO $db;
    private ?array $vendors = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'vendor_messages'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Active vendors: id, name, aliases, website. */
    public function vendors(): array
    {
        if ($this->vendors !== null) return $this->vendors;
        try {
            $this->vendors = $this->db->query("SELECT id, name, aliases, website FROM vendors WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->vendors = [];
        }
        return $this->vendors;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    private static function squash(string $s): string
    {
        return (string)preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    /** Domain labels of an address that could name a company ("orders.lawnboy.ca" → ['lawnboy']). */
    public static function domainLabels(string $email): array
    {
        $host = strtolower(substr(strrchr($email, '@') ?: '', 1));
        $labels = array_values(array_filter(explode('.', $host)));
        if (count($labels) < 2) return [];
        array_pop($labels);                                   // TLD
        if ($labels && in_array(end($labels), ['co', 'com', 'org', 'net', 'gc', 'bc'], true) && count($labels) > 1) array_pop($labels); // .co.uk / .gc.ca
        $out = [];
        foreach ($labels as $l) {
            if (in_array($l, self::NOISE_LABELS, true)) continue;
            $out[] = self::squash($l);
        }
        return array_values(array_filter($out));
    }

    /**
     * The vendor this sender belongs to, or null.
     * @param array<int, array{id: int|string, name: string, aliases: ?string, website: ?string}> $vendors
     */
    public static function matchVendor(string $email, string $displayName, array $vendors): ?int
    {
        $labels = self::domainLabels($email);
        $free = (bool)array_intersect($labels, self::FREE_MAIL);
        $name = ' ' . strtolower((string)preg_replace('/[^a-z0-9]+/i', ' ', $displayName)) . ' ';
        foreach ($vendors as $v) {
            $keys = array_merge([(string)$v['name']], explode(',', (string)($v['aliases'] ?? '')));
            if (!$free && !empty($v['website'])) {
                $site = (string)preg_replace('#^[a-z]+://#i', '', strtolower(trim((string)$v['website'])));
                $siteLabels = self::domainLabels('x@' . explode('/', $site)[0]);
                if ($siteLabels && array_intersect($labels, $siteLabels)) return (int)$v['id'];
            }
            foreach ($keys as $k) {
                $sq = self::squash($k);
                if (strlen($sq) < self::MIN_KEY) continue;
                if (!$free) {
                    foreach ($labels as $l) {
                        if ($l === $sq || (strlen($sq) >= 6 && strpos($l, $sq) !== false)) return (int)$v['id'];
                    }
                }
                $words = trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', ' ', $k)));
                if ($words !== '' && strpos($name, ' ' . $words . ' ') !== false) return (int)$v['id'];
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Storage
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Store one vendor email. Returns 'stored' | 'dupe'.
     * @param array{message_key: string, mailbox: string, vendor_id: int, direction: string,
     *              from: string, to: string, subject: string, snippet: string, sent_at: string} $m
     */
    public function store(array $m): string
    {
        $s = $this->db->prepare("
            INSERT IGNORE INTO vendor_messages (message_key, mailbox, vendor_id, direction, from_addr, to_addr, subject, snippet, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $s->execute([
            mb_substr($m['message_key'], 0, 191), mb_substr($m['mailbox'], 0, 60), (int)$m['vendor_id'],
            $m['direction'] === 'outbound' ? 'outbound' : 'inbound',
            mb_substr($m['from'], 0, 255), mb_substr($m['to'], 0, 255), mb_substr($m['subject'], 0, 255),
            $m['snippet'], date('Y-m-d H:i:s', strtotime($m['sent_at']) ?: time()),
        ]);
        return $s->rowCount() > 0 ? 'stored' : 'dupe';
    }

    /** One vendor's recent mail, newest first. */
    public function history(int $vendorId, int $limit = 20): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("SELECT direction, from_addr, to_addr, subject, snippet, sent_at FROM vendor_messages WHERE vendor_id = ? ORDER BY sent_at DESC LIMIT " . max(1, min(100, $limit)));
        $s->execute([$vendorId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
