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
 * Joined mail (migration 1314, owner 2026-10-10): someone who isn't a contact can still be
 * part of a quote's conversation — Monica at Macdonald PM wrote "council approved" about
 * Linda's quote QUO-2026-0073. Such an email is kept under the quote's contact when it names
 * the quote number, starts with the quote's property address (our subjects do), or comes
 * from the same company's domain (one contact there, never gmail and the like). Rules only.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class SalesInboxService
{
    public const OUR_DOMAINS = ['mowology.ca'];
    public const SNIPPET_MAX = 800;
    /** The sender's signature block, kept apart from the snippet (migration 1206) for the clues check. */
    public const SIGNATURE_MAX = 300;
    /** Mail providers anyone can use: a shared domain here never means "same company". */
    public const FREE_DOMAINS = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.ca', 'outlook.com', 'live.com', 'live.ca',
        'msn.com', 'yahoo.com', 'yahoo.ca', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'shaw.ca', 'telus.net', 'rogers.com',
        'bell.net', 'sympatico.ca', 'protonmail.com', 'proton.me', 'gmx.com', 'mail.com', 'zoho.com', 'yandex.com', 'fastmail.com'];

    private PDO $db;
    /** @var array<string, int>|null lowercase email => contact id */
    private ?array $contacts = null;
    /** message_key of the last ingest() — so the cron can add the text afterwards. */
    public string $lastKey = '';
    /** direction of the last ingest() — only inbound mail gets a signature. */
    public string $lastDirection = '';
    /** head of the last stored inbound message ('' if not routed) — penny / sam / otto / mia / yui. */
    public string $lastHead = '';
    private ?bool $sigReady = null;
    private ?bool $joinReady = null;
    /** @var array{quotes: array, domains: array}|null */
    private ?array $join = null;
    /** @var InboundRouteService|null */
    private $router = null;

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
        $c = self::classifyAny((string)$m['from'], (string)$m['to'], (string)($m['subject'] ?? ''),
                               $this->contactMap(), $this->joinContext(), (array)($m['ours'] ?? []));
        if ($c === null) return 'skipped';
        $sentAt = strtotime((string)$m['date']) ?: time();
        $key = trim((string)($m['message_id'] ?? '')) !== ''
            ? mb_substr(trim((string)$m['message_id']), 0, 191)
            : 'h-' . sha1($m['mailbox'] . '|' . $m['from'] . '|' . $m['to'] . '|' . $m['subject'] . '|' . $sentAt);
        $this->lastKey = $key;
        $this->lastDirection = $c['direction'];
        $row = [
            mb_substr((string)$m['mailbox'], 0, 60), $key, $c['direction'], $c['contact_id'],
            mb_substr($c['from'], 0, 255), mb_substr($c['to'], 0, 255),
            mb_substr(trim((string)$m['subject']), 0, 255),
            self::snippet((string)$m['body']),
            date('Y-m-d H:i:s', $sentAt),
        ];
        if ($this->joinReady()) {
            $s = $this->db->prepare("
                INSERT IGNORE INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, to_addr, subject, snippet, sent_at,
                                                   from_name, quote_id, joined_by)
                VALUES (?, ?, ?, 'email', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $name = $c['direction'] === 'inbound' ? self::senderName((string)$m['from']) : '';
            $row[] = $name !== '' ? mb_substr($name, 0, 120) : null;
            $row[] = $c['quote_id'] ?? null;
            $row[] = $c['joined_by'] ?? null;
        } else {
            if (!empty($c['joined_by'])) return 'skipped';   // migration 1314 not run: keep the old behaviour
            $s = $this->db->prepare("
                INSERT IGNORE INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, to_addr, subject, snippet, sent_at)
                VALUES (?, ?, ?, 'email', ?, ?, ?, ?, ?, ?)
            ");
        }
        $s->execute($row);
        $stored = $s->rowCount() > 0;
        $this->lastHead = '';
        if ($stored && $c['direction'] === 'inbound') $this->route($key);
        return $stored ? 'stored' : 'dupe';
    }

    /** Migration 1314 has added sales_messages.from_name / quote_id / joined_by. */
    public function joinReady(): bool
    {
        if ($this->joinReady === null) {
            try {
                $this->db->query('SELECT from_name, quote_id, joined_by FROM sales_messages LIMIT 0');
                $this->joinReady = true;
            } catch (Throwable $e) {
                $this->joinReady = false;
            }
        }
        return $this->joinReady;
    }

    /**
     * What joinUnknown() matches against: the last year's quotes (newest first) with their
     * number, contact and property address; and company domains held by exactly one contact.
     * @return array{quotes: array<int, array{id:int, number:string, contact_id:int, address_key:string}>, domains: array<string,int>}
     */
    public function joinContext(): array
    {
        if ($this->join !== null) return $this->join;
        $this->join = ['quotes' => [], 'domains' => []];
        try {
            foreach ($this->db->query("
                SELECT q.id, q.quote_number, q.contact_id, p.address
                FROM quotes q LEFT JOIN properties p ON p.id = q.property_id
                WHERE q.contact_id IS NOT NULL AND q.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
                ORDER BY q.created_at DESC, q.id DESC
            ")->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $this->join['quotes'][] = ['id' => (int)$q['id'], 'number' => strtoupper((string)$q['quote_number']),
                                           'contact_id' => (int)$q['contact_id'], 'address_key' => self::addressKey((string)$q['address'])];
            }
        } catch (Throwable $e) {
            error_log('[sales inbox] join quotes: ' . $e->getMessage());
        }
        $byDomain = [];
        foreach ($this->contactMap() as $email => $cid) {
            $d = self::domain($email);
            if ($d === '' || in_array($d, self::FREE_DOMAINS, true) || in_array($d, self::OUR_DOMAINS, true)) continue;
            $byDomain[$d][$cid] = true;
        }
        foreach ($byDomain as $d => $ids) {
            if (count($ids) === 1) $this->join['domains'][$d] = (int)array_key_first($ids);
        }
        return $this->join;
    }

    /** The cron reads a customer email's text only after it knows it's customer mail. */
    public function setSnippet(string $key, string $snippet): void
    {
        if ($key === '' || $snippet === '') return;
        $this->db->prepare("UPDATE sales_messages SET snippet = ? WHERE message_key = ? AND (snippet IS NULL OR snippet = '')")
           ->execute([$snippet, $key]);
        $this->route($key);   // the words are in now: decide again (inbound only; Tim's moves are kept)
    }

    /**
     * Which department head owns an inbound message (InboundRouteService::stamp — migration 1280;
     * before it, nothing happens). Sets lastHead for the reader (Penny's mail keeps its attachments).
     */
    private function route(string $key): void
    {
        try {
            if ($this->router === null) {
                require_once dirname(__DIR__, 2) . '/Comms/Services/InboundRouteService.php';
                $this->router = new InboundRouteService($this->db);
            }
            $d = $this->router->stamp($key);
            if ($d !== null) $this->lastHead = $d['head'];
        } catch (Throwable $e) {
            error_log('Inbound routing (' . $key . '): ' . $e->getMessage());   // routing is a bonus — never lose the mail
        }
    }

    /** Keep a stored inbound email's PDF / photo attachments when it is Penny's (migration 1283). */
    public function keepAttachment(string $key, string $filename, string $mime, string $bytes): ?int
    {
        if ($this->lastHead !== 'penny' || $this->router === null) return null;
        try {
            return $this->router->attachments()->store($key, $filename, $mime, $bytes);
        } catch (Throwable $e) {
            error_log('Inbound attachment (' . $key . '): ' . $e->getMessage());
            return null;
        }
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

    /**
     * classify(), then — for a sender (or, on our mail, a recipient) who isn't a contact — the
     * quote's conversation the email is about (joinUnknown). Joined results carry quote_id and
     * joined_by; plain contact mail is returned exactly as classify() returns it.
     * @param array{quotes?: array, domains?: array<string,int>} $join
     */
    public static function classifyAny(string $from, string $to, string $subject, array $contactMap, array $join, array $ours = []): ?array
    {
        $c = self::classify($from, $to, $contactMap, $ours);
        if ($c !== null) return $c;
        $f = self::addresses($from)[0] ?? '';
        if ($f === '') return null;
        if (!self::isOurs($f, $ours)) {
            $j = self::joinUnknown($f, $subject, $join);
            return $j ? ['direction' => 'inbound', 'contact_id' => $j['contact_id'], 'from' => $f,
                         'to' => implode(', ', self::addresses($to)), 'quote_id' => $j['quote_id'], 'joined_by' => $j['joined_by']] : null;
        }
        foreach (self::addresses($to) as $t) {
            if (self::isOurs($t, $ours)) continue;
            $j = self::joinUnknown($t, $subject, $join);
            if ($j) return ['direction' => 'outbound', 'contact_id' => $j['contact_id'], 'from' => $f, 'to' => $t,
                            'quote_id' => $j['quote_id'], 'joined_by' => $j['joined_by']];
        }
        return null;
    }

    /**
     * Which quote's conversation an email from a non-contact belongs to, first rule wins:
     *   quote_number — the subject names one of our quotes (QUO-2026-0073);
     *   address      — the subject carries the quote's property address (our subjects start with it);
     *   domain       — the person writes from a company domain exactly one contact uses, and that
 *                  contact has a quote with us (never gmail…).
     * $join['quotes'] is newest first, so a property with several quotes joins the newest.
     * @return array{contact_id:int, quote_id:?int, joined_by:string}|null
     */
    public static function joinUnknown(string $email, string $subject, array $join): ?array
    {
        $quotes = (array)($join['quotes'] ?? []);
        foreach (self::quoteRefs($subject) as $ref) {
            foreach ($quotes as $q) {
                if ($q['number'] === $ref) return ['contact_id' => (int)$q['contact_id'], 'quote_id' => (int)$q['id'], 'joined_by' => 'quote_number'];
            }
        }
        $subj = ' ' . self::addressKey($subject, false) . ' ';
        foreach ($quotes as $q) {
            $k = (string)$q['address_key'];
            if ($k !== '' && strpos($subj, ' ' . $k . ' ') !== false) {
                return ['contact_id' => (int)$q['contact_id'], 'quote_id' => (int)$q['id'], 'joined_by' => 'address'];
            }
        }
        // Same company: only onto a contact who has a quote with us. A placeholder at the
        // firm ("Valued Customer", dkrental@macdonaldpm.com) is not a conversation to join
        // (2026-10-10: Monica's "council approved" landed there instead of on Linda's quote).
        $d = self::domain($email);
        $cid = (int)(($join['domains'] ?? [])[$d] ?? 0);
        if ($d !== '' && $cid > 0 && !in_array($d, self::FREE_DOMAINS, true)) {
            foreach ($quotes as $q) {
                if ((int)$q['contact_id'] === $cid) return ['contact_id' => $cid, 'quote_id' => (int)$q['id'], 'joined_by' => 'domain'];
            }
        }
        return null;
    }

    /** Quote numbers named in a subject or text, uppercase ("QUO-2026-0073"). */
    public static function quoteRefs(string $text): array
    {
        preg_match_all('/\bQUO-\d{4}-\d{3,5}\b/i', $text, $m);
        return array_values(array_unique(array_map('strtoupper', $m[0])));
    }

    /**
     * A street address reduced for matching: lowercase words, common words shortened
     * ("1685 West 14th Avenue" → "1685 w 14 ave"). As a quote's key it must start with a
     * street number and have a street name after it, or it is '' (never matched).
     */
    public static function addressKey(string $s, bool $asKey = true): string
    {
        $t = strtolower($s);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';
        $t = preg_replace('/\b(\d+)(st|nd|rd|th)\b/', '$1', $t) ?? '';
        $short = ['avenue' => 'ave', 'av' => 'ave', 'street' => 'st', 'road' => 'rd', 'drive' => 'dr', 'boulevard' => 'blvd',
                  'place' => 'pl', 'crescent' => 'cres', 'court' => 'ct', 'lane' => 'ln', 'highway' => 'hwy', 'parkway' => 'pkwy',
                  'west' => 'w', 'east' => 'e', 'north' => 'n', 'south' => 's'];
        $words = array_map(fn($w) => $short[$w] ?? $w, preg_split('/\s+/', trim($t)) ?: []);
        $k = trim(implode(' ', array_filter($words, fn($w) => $w !== '')));
        if (!$asKey) return $k;
        return preg_match('/^\d+[a-z]? [a-z0-9]+ [a-z0-9]+/', $k) ? $k : '';
    }

    /** The part after @, lowercase. */
    public static function domain(string $email): string
    {
        return strtolower(substr(strrchr(trim($email), '@') ?: '', 1));
    }

    /** "Monica Nicule <mnicule@x.com>" → "Monica Nicule"; '' when there is only an address. */
    public static function senderName(string $header): string
    {
        if (!preg_match('/^\s*([^<]*?)\s*</', $header, $m)) return '';
        $n = trim($m[1], "\"' ");
        // "Nicule, Monica" → "Monica Nicule"
        if (preg_match('/^([^,]+),\s*([^,]+)$/', $n, $p)) $n = trim($p[2]) . ' ' . trim($p[1]);
        return $n;
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
