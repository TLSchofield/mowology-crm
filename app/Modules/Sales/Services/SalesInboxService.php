<?php
/**
 * SalesInboxService — customer email history for Sam, read from office@ (read-only).
 *
 * Every CRM email sets Reply-To office@mowology.ca, so customers' replies land there; the
 * office's own replies sit in its Sent folder. The cron (Sales/Cron/sales_inbox_poll.php)
 * opens both folders READ-ONLY (OP_READONLY, FT_PEEK — it never marks, moves or deletes
 * mail; office@ is read by people) and hands each message here. Only mail from or to a
 * known contact is kept — everything else in office@ (suppliers, receipts, Interac) is
 * ignored and never stored. What's kept is the new text only (quoted history stripped),
 * up to ~800 characters: enough to see who said what last.
 *
 * Tim's personal mail (iCloud/Gmail) is NOT read.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class SalesInboxService
{
    public const OUR_DOMAINS = ['mowology.ca'];
    public const SNIPPET_MAX = 800;

    private PDO $db;
    /** @var array<string, int>|null lowercase email => contact id */
    private ?array $contacts = null;
    /** message_key of the last ingest() — so the cron can add the text afterwards. */
    public string $lastKey = '';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'sales_messages'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** lowercase email => contact id (active contacts; the newest wins a shared address). */
    private function contactMap(): array
    {
        if ($this->contacts !== null) return $this->contacts;
        $this->contacts = [];
        foreach ($this->db->query("SELECT id, LOWER(TRIM(email)) AS e FROM contacts WHERE email IS NOT NULL AND email <> '' ORDER BY id")
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $this->contacts[$r['e']] = (int)$r['id'];
        }
        return $this->contacts;
    }

    /**
     * Store one email if it's to or from a customer. Returns 'stored', 'dupe' or 'skipped'.
     * @param array{mailbox: string, message_id: ?string, from: string, to: string, subject: string, body: string, date: string} $m
     */
    public function ingest(array $m): string
    {
        $c = self::classify((string)$m['from'], (string)$m['to'], $this->contactMap());
        if ($c === null) return 'skipped';
        $sentAt = strtotime((string)$m['date']) ?: time();
        $key = trim((string)($m['message_id'] ?? '')) !== ''
            ? mb_substr(trim((string)$m['message_id']), 0, 191)
            : 'h-' . sha1($m['mailbox'] . '|' . $m['from'] . '|' . $m['to'] . '|' . $m['subject'] . '|' . $sentAt);
        $this->lastKey = $key;
        $s = $this->db->prepare("
            INSERT IGNORE INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, to_addr, subject, snippet, sent_at)
            VALUES (?, ?, ?, 'email', ?, ?, ?, ?, ?, ?)
        ");
        $s->execute([
            mb_substr((string)$m['mailbox'], 0, 60), $key, $c['direction'], $c['contact_id'],
            mb_substr($c['from'], 0, 255), mb_substr($c['to'], 0, 255),
            mb_substr(trim((string)$m['subject']), 0, 255),
            self::snippet((string)$m['body']),
            date('Y-m-d H:i:s', $sentAt),
        ]);
        return $s->rowCount() > 0 ? 'stored' : 'dupe';
    }

    /** The cron reads a customer email's text only after it knows it's customer mail. */
    public function setSnippet(string $key, string $snippet): void
    {
        if ($key === '' || $snippet === '') return;
        $this->db->prepare("UPDATE sales_messages SET snippet = ? WHERE message_key = ? AND (snippet IS NULL OR snippet = '')")
           ->execute([$snippet, $key]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** All addresses in a header value ("Jodi <a@b.ca>, c@d.com"), lowercase. */
    public static function addresses(string $header): array
    {
        preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $header, $m);
        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    public static function isOurs(string $email): bool
    {
        $d = strtolower(substr(strrchr($email, '@') ?: '', 1));
        return in_array($d, self::OUR_DOMAINS, true);
    }

    /**
     * inbound  — from a known contact (not us)
     * outbound — from us, to a known contact
     * null     — anything else (not a customer conversation)
     * @return array{direction: string, contact_id: int, from: string, to: string}|null
     */
    public static function classify(string $from, string $to, array $contactMap): ?array
    {
        $f = self::addresses($from)[0] ?? '';
        if ($f === '') return null;
        if (!self::isOurs($f)) {
            return isset($contactMap[$f]) ? ['direction' => 'inbound', 'contact_id' => $contactMap[$f], 'from' => $f, 'to' => implode(', ', self::addresses($to))] : null;
        }
        foreach (self::addresses($to) as $t) {
            if (!self::isOurs($t) && isset($contactMap[$t])) {
                return ['direction' => 'outbound', 'contact_id' => $contactMap[$t], 'from' => $f, 'to' => $t];
            }
        }
        return null;
    }

    /** The new text only: quoted history, signatures' "Sent from my iPhone" and blank runs removed. */
    public static function snippet(string $body): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $body);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cut = [
            '/^On .{5,200}wrote:\s*$/m',                       // Gmail/Apple: On Mon, Oct 5 … wrote:
            '/^-{2,}\s*Original Message\s*-{2,}/mi',
            '/^From:\s.+$/m',                                  // Outlook header block
            '/^_{5,}\s*$/m',
            '/^Sent from my (iPhone|iPad|Android|Galaxy|mobile).*$/mi',
        ];
        $end = strlen($t);
        foreach ($cut as $re) {
            if (preg_match($re, $t, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) $end = min($end, $m[0][1]);
        }
        $t = substr($t, 0, $end);
        $t = implode("\n", array_filter(explode("\n", $t), fn($l) => strpos(ltrim($l), '>') !== 0));
        $t = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $t)));
        return mb_strlen($t) > self::SNIPPET_MAX ? rtrim(mb_substr($t, 0, self::SNIPPET_MAX - 1)) . '…' : $t;
    }
}
