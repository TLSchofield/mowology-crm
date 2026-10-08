<?php
/**
 * EmailLeadService — work enquiries that arrive by email from someone the CRM doesn't know.
 *
 * Free and rule-based (no AI). IcloudInboxRouter asks score() about mail from unknown
 * senders that isn't automated (isAutomated: List-Unsubscribe / List-Id, bulk precedence,
 * auto-submitted, noreply senders):
 *   strong — they ask (quote, estimate, "how much", "are you available"…) about a service
 *            (lawn, hedge, snow…) AND give an address or name two services
 *            → a real lead in quote_requests, made the way the public quote form makes one
 *              (a prospect contact + property, status 'new', source 'email'), shown in Sam's
 *              "New leads" list. Only for mail from the last FRESH_DAYS — older mail never
 *              makes a lead on its own (the quote-request reminder would text Tim about it).
 *   weak   — an ask about a service, two services, or an ask with an address
 *            → stored as a "maybe a lead" on Sam's card; Tim accepts (→ the same lead) or
 *              dismisses.
 * Only the new text (≤ 800 characters) plus the sender's name and email is kept.
 *
 * Table: email_lead_candidates (migration 1223). No namespace / no autoloader: require_once.
 */
class EmailLeadService
{
    public const FRESH_DAYS = 14;
    /** Mail older than this is never stored as a lead (Sam's list stops at 60 days). */
    public const MAX_AGE_DAYS = 60;

    /** Service words → the service_types tag stored on the lead. */
    private const SERVICES = [
        'lawn' => '/\blawns?\b|\bgrass\b|\bturf\b/i',
        'mowing' => '/\bmow(?:s|ed|ing)?\b/i',
        'hedge' => '/\bhedges?\b|\bhedging\b/i',
        'garden' => '/\bgardens?\b|\bgardening\b|\bflower ?beds?\b/i',
        'cleanup' => '/\bclean[\s-]?ups?\b|\byard waste\b|\bleaves\b|\bleaf removal\b/i',
        'aeration' => '/\baerat(?:e|ion|ing)\b/i',
        'mulch' => '/\bmulch(?:ing)?\b/i',
        'snow' => '/\bsnow\b|\bplow(?:ing)?\b|\bshovel(?:l?ing)?\b/i',
        'salting' => '/\bsalt(?:ing)?\b|\bde-?icing\b/i',
        'landscaping' => '/\blandscap(?:e|ing|er|ers)\b/i',
        'maintenance' => '/\bmaintenance\b|\bmaintain\b/i',
        'strata' => '/\bstrata\b/i',
        'pruning' => '/\bprun(?:e|ing)\b|\btrim(?:ming)?\b/i',
    ];

    /** Someone asking for work. */
    private const ASK = '/\bquote\b|\bquotes\b|\bestimate\b|\bhow much\b|\bare you available\b|\bwould you be available\b|\bpricing\b|\bprice for\b|\bdo you (?:do|offer|service|provide)\b|\bcan you (?:quote|come|do|help)\b|\bcould you (?:quote|come|do)\b|\blooking for (?:someone|a|help)\b|\binterested in\b|\bneed (?:someone|help)\b/i';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'email_lead_candidates'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Newsletters, notifications and other robots: never a lead.
     * @param string $rawHeaders the message's raw header block
     */
    public static function isAutomated(string $fromAddr, string $rawHeaders): bool
    {
        $local = strtolower((string)strstr(strtolower($fromAddr), '@', true));
        if (preg_match('/^(no-?reply|do-?not-?reply|donotreply|noreply|notifications?|notify|mailer-daemon|postmaster|bounce[s]?|newsletter|news|marketing|promo(tions)?|updates?|alerts?|automated|system|support)([._+\-].*)?$/i', $local)) {
            return true;
        }
        $h = (string)preg_replace("/\r?\n[ \t]+/", ' ', $rawHeaders);
        if (preg_match('/^(List-Unsubscribe|List-Id|List-Post|X-Campaign(?:-?Id)?|X-Mailchimp-[A-Za-z-]+|X-SG-EID|Feedback-ID):/mi', $h)) return true;
        if (preg_match('/^Precedence:\s*(bulk|list|junk)\b/mi', $h)) return true;
        if (preg_match('/^Auto-Submitted:\s*(?!no\b)\S+/mi', $h)) return true;
        return false;
    }

    /** A street address in free text ("123 W 4th", "4512 Oak St", "88 East 12th Avenue"). */
    public static function findAddress(string $text): ?string
    {
        $suffix = '(?:St|Street|Ave|Avenue|Rd|Road|Dr|Drive|Blvd|Boulevard|Way|Cres|Crescent|Pl|Place|Ct|Court|Lane|Ln|Hwy|Highway|Terrace|Close|Mews|Grove|Pkwy|Parkway)\b\.?';
        $dir = '(?:[NSEW]\.?|North|South|East|West)';
        $patterns = [
            // 123 W 4th [Ave]
            '/\b\d{1,5}\s+' . $dir . '\s+\d{1,3}(?:st|nd|rd|th)\b(?:\s+' . $suffix . ')?/i',
            // 4512 Oak St / 88 East 12th Avenue / 1200 Marine Drive
            '/\b\d{1,5}\s+(?:' . $dir . '\s+)?(?:\d{1,3}(?:st|nd|rd|th)|[A-Z][a-zA-Z\']+(?:\s+[A-Z][a-zA-Z\']+)?)\s+' . $suffix . '/',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $text, $m)) return trim($m[0], " .");
        }
        return null;
    }

    /**
     * How strongly an email reads as a request for work.
     * @return array{strength: ?string, score: int, services: string[], address: ?string, ask: bool}
     */
    public static function score(string $subject, string $body): array
    {
        $text = $subject . "\n" . $body;
        $services = [];
        foreach (self::SERVICES as $tag => $re) {
            if (preg_match($re, $text)) $services[] = $tag;
        }
        $ask = (bool)preg_match(self::ASK, $text);
        $address = self::findAddress($text);
        $n = count($services);
        $strength = null;
        if ($ask && $n >= 1 && ($address !== null || $n >= 2)) {
            $strength = 'strong';
        } elseif (($ask && $n >= 1) || $n >= 2 || ($ask && $address !== null)) {
            $strength = 'weak';
        }
        $score = ($ask ? 2 : 0) + $n + ($address !== null ? 2 : 0);
        return ['strength' => $strength, 'score' => $score, 'services' => $services, 'address' => $address, 'ask' => $ask];
    }

    /** "Jane Doe <jane@x.ca>" → ['Jane', 'Doe']; falls back to the email's local part. */
    public static function splitName(string $name, string $email): array
    {
        $name = trim(preg_replace('/\s+/', ' ', str_replace(['"', "'"], '', $name)) ?? '');
        if ($name === '' || strpos($name, '@') !== false) {
            $name = ucwords(str_replace(['.', '_', '-'], ' ', (string)strstr($email, '@', true)));
        }
        $parts = explode(' ', $name, 2);
        return [trim($parts[0]), trim($parts[1] ?? '')];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Storage
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record one enquiry. A fresh strong one becomes a lead straight away; anything else
     * waits as "maybe". Returns 'lead' | 'maybe' | 'dupe' | 'stale'.
     * @param array{message_key: string, mailbox: string, from_name: string, from_addr: string,
     *              subject: string, snippet: string, received_at: string} $m
     */
    public function record(array $m, array $score): string
    {
        $ageDays = (time() - (strtotime($m['received_at']) ?: time())) / 86400;
        if ($ageDays > self::MAX_AGE_DAYS || $score['strength'] === null) return 'stale';
        $s = $this->db->prepare("
            INSERT IGNORE INTO email_lead_candidates
                (message_key, mailbox, from_name, from_addr, subject, snippet, address, services, strength, score, status, received_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'maybe', ?)
        ");
        $s->execute([
            mb_substr($m['message_key'], 0, 191), mb_substr($m['mailbox'], 0, 60),
            mb_substr($m['from_name'], 0, 255), mb_substr(strtolower($m['from_addr']), 0, 255),
            mb_substr($m['subject'], 0, 255), $m['snippet'],
            $score['address'] !== null ? mb_substr($score['address'], 0, 255) : null,
            mb_substr(implode(',', $score['services']), 0, 255),
            $score['strength'], (int)$score['score'],
            date('Y-m-d H:i:s', strtotime($m['received_at']) ?: time()),
        ]);
        if ($s->rowCount() === 0) return 'dupe';
        $id = (int)$this->db->lastInsertId();
        if ($score['strength'] === 'strong' && $ageDays <= self::FRESH_DAYS) {
            $this->makeLead($id, null);
            return 'lead';
        }
        return 'maybe';
    }

    /** Maybe-leads waiting for Tim (newest first). */
    public function maybes(int $limit = 10): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("
            SELECT id, from_name, from_addr, subject, snippet, address, services, received_at
            FROM email_lead_candidates
            WHERE status = 'maybe' AND received_at >= DATE_SUB(NOW(), INTERVAL " . self::MAX_AGE_DAYS . " DAY)
            ORDER BY received_at DESC
            LIMIT " . max(1, min(50, $limit)));
        $s->execute();
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => trim((string)$r['from_name']) ?: (string)$r['from_addr'],
            'email' => (string)$r['from_addr'], 'subject' => (string)$r['subject'], 'snippet' => (string)$r['snippet'],
            'address' => (string)$r['address'], 'services' => str_replace(',', ', ', (string)$r['services']),
            'at' => (string)$r['received_at'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Tim: "Make it a lead". Returns the quote_requests id, or null if already decided. */
    public function accept(int $id, int $userId): ?int
    {
        return $this->makeLead($id, $userId);
    }

    /** Tim: "Not a lead". */
    public function dismiss(int $id, int $userId): bool
    {
        $s = $this->db->prepare("UPDATE email_lead_candidates SET status = 'dismissed', decided_by = ?, decided_at = ? WHERE id = ? AND status = 'maybe'");
        $s->execute([$userId, date('Y-m-d H:i:s'), $id]);
        return $s->rowCount() > 0;
    }

    /**
     * The lead, the way the public quote form makes one: the sender as a prospect contact
     * (reused if their email is already on file), a property when an address was spotted,
     * and a quote_requests row (status 'new', source 'email').
     */
    private function makeLead(int $candidateId, ?int $userId): ?int
    {
        $s = $this->db->prepare("SELECT * FROM email_lead_candidates WHERE id = ? AND status = 'maybe'");
        $s->execute([$candidateId]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;

        $email = strtolower(trim((string)$c['from_addr']));
        $find = $this->db->prepare("SELECT id FROM contacts WHERE LOWER(TRIM(email)) = ? ORDER BY id DESC LIMIT 1");
        $find->execute([$email]);
        $contactId = (int)($find->fetchColumn() ?: 0);
        $address = trim((string)$c['address']);
        if ($contactId === 0) {
            require_once APP_ROOT . '/Modules/Contacts/Services/ContactService.php';
            [$first, $last] = self::splitName((string)$c['from_name'], $email);
            $contactId = (new ContactService($this->db))->createContact([
                'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => '', 'mobile' => '',
                'preferred_contact_method' => 'email', 'receive_sms' => 0, 'receive_marketing' => 0, 'consent_quote_followup' => 0,
                'notes' => 'From an email enquiry (' . $c['mailbox'] . ')',
                'property_address' => $address, 'property_city' => 'Vancouver', 'property_postal_code' => '',
                'property_latitude' => null, 'property_longitude' => null,
            ]);
        }
        $propertyId = null;
        if ($address !== '') {
            $p = $this->db->prepare("SELECT id FROM properties WHERE address = ? ORDER BY id DESC LIMIT 1");
            $p->execute([$address]);
            $propertyId = ((int)$p->fetchColumn()) ?: null;
        }
        $desc = 'From an email' . ($c['subject'] !== '' ? ' — "' . $c['subject'] . '"' : '') . ":\n" . $c['snippet'];
        $this->db->prepare("
            INSERT INTO quote_requests (contact_id, property_id, service_types, urgency, project_description, status, source, created_at)
            VALUES (?, ?, ?, 'inquiring', ?, 'new', 'email', ?)
        ")->execute([$contactId, $propertyId, (string)$c['services'] ?: null, $desc, $c['received_at']]);
        $qrId = (int)$this->db->lastInsertId();
        $this->db->prepare("UPDATE email_lead_candidates SET status = 'created', quote_request_id = ?, contact_id = ?, decided_by = ?, decided_at = ? WHERE id = ?")
           ->execute([$qrId, $contactId, $userId, date('Y-m-d H:i:s'), $candidateId]);
        return $qrId;
    }
}
