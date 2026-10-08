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
 * Tim's iCloud (mowology@icloud.com) is read by IcloudInboxRouter, which hands mail to or
 * from a contact to ingest() here with mailbox 'icloud …' and ours = his iCloud address.
 * The rest of his personal mail is never stored.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class SalesInboxService
{
    public const OUR_DOMAINS = ['mowology.ca'];
    public const SNIPPET_MAX = 800;
    /** The sender's signature block, kept apart from the snippet (migration 1206) for the clues check. */
    public const SIGNATURE_MAX = 300;

    private PDO $db;
    /** @var array<string, int>|null lowercase email => contact id */
    private ?array $contacts = null;
    /** message_key of the last ingest() — so the cron can add the text afterwards. */
    public string $lastKey = '';
    /** direction of the last ingest() — only inbound mail gets a signature. */
    public string $lastDirection = '';
    private ?bool $sigReady = null;

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
    public function contactMap(): array
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
     * Dedupe is by Message-ID across every mailbox (unique message_key), so an email that
     * reached both office@ and iCloud is stored once — under whichever mailbox read it first.
     * @param array{mailbox: string, message_id: ?string, from: string, to: string, subject: string, body: string, date: string, ours?: string[]} $m
     *        ours: extra addresses that are "us" in this mailbox (iCloud: mowology@icloud.com)
     */
    public function ingest(array $m): string
    {
        $c = self::classify((string)$m['from'], (string)$m['to'], $this->contactMap(), (array)($m['ours'] ?? []));
        if ($c === null) return 'skipped';
        $sentAt = strtotime((string)$m['date']) ?: time();
        $key = trim((string)($m['message_id'] ?? '')) !== ''
            ? mb_substr(trim((string)$m['message_id']), 0, 191)
            : 'h-' . sha1($m['mailbox'] . '|' . $m['from'] . '|' . $m['to'] . '|' . $m['subject'] . '|' . $sentAt);
        $this->lastKey = $key;
        $this->lastDirection = $c['direction'];
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

    /** Migration 1206 has added sales_messages.signature. */
    public function signatureReady(): bool
    {
        if ($this->sigReady === null) {
            try {
                $this->db->query('SELECT signature FROM sales_messages LIMIT 0');
                $this->sigReady = true;
            } catch (Throwable $e) {
                $this->sigReady = false;
            }
        }
        return $this->sigReady;
    }

    /** Inbound mail from a known contact: keep the signature block (clues check). Never overwrites. */
    public function setSignature(string $key, string $signature): void
    {
        if ($key === '' || $signature === '' || !$this->signatureReady()) return;
        $this->db->prepare("UPDATE sales_messages SET signature = ? WHERE message_key = ? AND direction = 'inbound' AND signature IS NULL")
           ->execute([$signature, $key]);
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

    /** Our own domain, or one of the extra addresses that are "us" in this mailbox (Tim's iCloud). */
    public static function isOurs(string $email, array $ours = []): bool
    {
        $email = strtolower(trim($email));
        if ($ours && in_array($email, array_map('strtolower', $ours), true)) return true;
        $d = substr(strrchr($email, '@') ?: '', 1);
        return in_array($d, self::OUR_DOMAINS, true);
    }

    /**
     * inbound  — from a known contact (not us)
     * outbound — from us, to a known contact
     * null     — anything else (not a customer conversation)
     * @return array{direction: string, contact_id: int, from: string, to: string}|null
     */
    public static function classify(string $from, string $to, array $contactMap, array $ours = []): ?array
    {
        $f = self::addresses($from)[0] ?? '';
        if ($f === '') return null;
        if (!self::isOurs($f, $ours)) {
            return isset($contactMap[$f]) ? ['direction' => 'inbound', 'contact_id' => $contactMap[$f], 'from' => $f, 'to' => implode(', ', self::addresses($to))] : null;
        }
        foreach (self::addresses($to) as $t) {
            if (!self::isOurs($t, $ours) && isset($contactMap[$t])) {
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

    /** Sign-offs that end the message and start the signature ("Thanks," / "Kind regards"). */
    private const SIGN_OFF = '/^(?:thanks(?: again| so much)?|thank you|many thanks|regards|kind regards|best regards|warm regards|warmest regards|with thanks|best|all the best|cheers|sincerely|yours truly|respectfully)\b[\s,.!\-]*$/i';

    /**
     * The sender's signature: the last lines of the new text, before the quoted history —
     * after a "-- " line, else after the last sign-off ("Thanks,"), else the trailing block
     * when it is short and not the whole message. Up to SIGNATURE_MAX characters; '' if none.
     * The snippet can be cut off before this, so the cron stores it separately.
     */
    public static function signature(string $body): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $body);
        $t = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/(p|div|tr)>/i', "\n", $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cut = [
            '/^On .{5,200}wrote:\s*$/m',
            '/^-{2,}\s*Original Message\s*-{2,}/mi',
            '/^From:\s.+$/m',
            '/^_{5,}\s*$/m',
            '/^Sent from my (iPhone|iPad|Android|Galaxy|mobile).*$/mi',
        ];
        $end = strlen($t);
        foreach ($cut as $re) {
            if (preg_match($re, $t, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) $end = min($end, $m[0][1]);
        }
        $lines = array_map(fn($l) => trim((string)(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l) ?? $l)),
            array_filter(explode("\n", substr($t, 0, $end)), fn($l) => strpos(ltrim($l), '>') !== 0));
        while ($lines && end($lines) === '') array_pop($lines);
        $lines = array_values($lines);
        if (count($lines) < 2) return '';

        $start = null;
        foreach ($lines as $i => $l) {
            if ($l === '--' || $l === '-- ') $start = $i + 1;
        }
        if ($start === null) {
            for ($i = count($lines) - 2; $i >= 0; $i--) {
                if ($lines[$i] !== '' && preg_match(self::SIGN_OFF, $lines[$i])) { $start = $i + 1; break; }
            }
        }
        if ($start === null) {
            // No sign-off: the last block, if short and something came before it.
            for ($i = count($lines) - 1; $i > 0; $i--) {
                if ($lines[$i] === '') { $start = $i + 1; break; }
            }
            if ($start === null || count($lines) - $start < 2 || count($lines) - $start > 8) return '';
        }
        $sig = array_values(array_filter(array_slice($lines, $start), fn($l) => $l !== ''));
        if (!$sig) return '';
        $s = implode("\n", $sig);
        return mb_strlen($s) > self::SIGNATURE_MAX ? rtrim(mb_substr($s, 0, self::SIGNATURE_MAX)) : $s;
    }
}
