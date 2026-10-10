<?php
/**
 * EmailLeadService — work enquiries that arrive by email from someone the CRM doesn't know.
 *
 * Free and rule-based (no AI). IcloudInboxRouter asks score() about mail from unknown
 * senders that isn't automated (isAutomated: List-Unsubscribe / List-Id, bulk precedence,
 * auto-submitted, noreply senders) and isn't from a payment platform (paymentPlatform()).
 *
 * An enquiry is a request FOR Mowology's services: customer-intent phrasing ("can you",
 * "do you", "how much would", "quote for my", "are you available", "my lawn"…) together
 * with a service word. Cold sales pitches TO Mowology are never enquiries (pitchSignals():
 * "… for MOWOLOGY" in the subject, "we offer", SEO, "more leads", engraved / branded gifts,
 * marketing-looking sender domains, unsubscribe footers…). 2026-10-07 dry run: an engraved-
 * gifts pitch scored strong (its own street address sat in the footer) and a PayPal "You
 * sent a payment" scored weak — both are excluded now. score()['why'] lists the signals.
 *   strong — intent + a service AND their property's address, or two services
 *            → a real lead in quote_requests, made the way the public quote form makes one
 *              (a prospect contact + property, status 'new', source 'email'), shown in Sam's
 *              "New leads" list. Only for mail from the last FRESH_DAYS — older mail never
 *              makes a lead on its own (the quote-request reminder would text Tim about it).
 *   weak   — intent + one service (or a strong one carrying a single soft pitch signal)
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

    /** Words that ask for a price or a visit (kept for the score; intent is what gates). */
    private const ASK = '/\bquote\b|\bquotes\b|\bestimate\b|\bhow much\b|\bare you available\b|\bwould you be available\b|\bpricing\b|\bprice for\b|\bdo you (?:do|offer|service|provide)\b|\bcan you (?:quote|come|do|help)\b|\bcould you (?:quote|come|do)\b|\blooking for (?:someone|a|help)\b|\binterested in\b|\bneed (?:someone|help)\b/i';

    /** Customer intent: someone asking Mowology to do work for THEM. label => pattern. */
    private const INTENT = [
        'can/could/would you' => '/\b(?:can|could|would|will) you\b/i',
        'do you'              => '/\bdo you\b|\bdoes your (?:crew|team|company)\b/i',
        'are you available'   => '/\bare you (?:available|free|taking|booking|accepting)\b|\bwould you be available\b|\bavailability\b/i',
        'quote for my/our'    => '/\b(?:quote|estimate|price|pricing|proposal)s? (?:for|on|to)\s+(?:my|our|the|a)\b|\b(?:quote|estimate) (?:my|our)\b/i',
        'need someone'        => '/\bneed (?:someone|somebody|a (?:landscaper|gardener|crew|company|quote|price)|help|an estimate)\b|\bwe need\b|\bi need\b/i',
        'looking for'         => '/\blooking for (?:someone|somebody|a |an |help|landscap|lawn|garden|snow|hedge)/i',
        'how much'            => '/\bhow much (?:would|will|do|does|is|for|to|it)\b|\bwhat (?:would|do|does) (?:it|you) (?:cost|charge)\b/i',
        'my property'         => '/\b(?:my|our) (?:front |back |side )?(?:lawn|yard|yards|hedges?|garden|gardens|property|properties|house|home|backyard|driveway|strata|building|complex|townhouse|condo|trees?|grass|beds?)\b/i',
        'request a quote'     => '/\b(?:request(?:ing)?|like|want|get) (?:a |an )?(?:quote|estimate|price|proposal)\b/i',
    ];

    /** A cold sales pitch TO Mowology. label => pattern (subject + text). */
    private const PITCH = [
        'for your business'   => '/\bfor (?:your|ur) (?:business|company|team|brand|clients|customers|employees|staff)\b/i',
        'your company'        => '/(?<!does )(?<!do )(?<!can )(?<!is )\byour (?:company|business|brand|online presence|google (?:ranking|listing|profile|business))\b/i',
        'we offer'            => '/\bwe (?:offer|provide|specialize|specialise|create|design|build|help (?:businesses|companies|brands|landscap\w*|local\w*))\b|\bwe can (?:get|help|bring|send) you\b/i',
        'our services'        => '/\bour (?:services|products|agency|team of|solutions|platform|software|app|catalog(?:ue)?)\b/i',
        'partner'             => '/\bpartner(?:ship|ing)?\b|\bcollaborat(?:e|ion)\b|\bsponsor/i',
        'promote'             => '/\bpromot(?:e|ion|ional|ing)\b/i',
        'SEO'                 => '/\bSEO\b|\bsearch engines?\b|\brank(?:ing)? (?:on|higher|#1|first)\b|\bfirst page of google\b/i',
        'leads for you'       => '/\b(?:more|new|qualified|exclusive|steady|guaranteed|free)\b(?:\s+[a-z-]+){0,2}\s+(?:leads|appointments)\b|\b(?:more|new|qualified|steady|guaranteed) (?:customers|clients|jobs)\b|\bleads for you\b|\blead generation\b/i',
        'aimed at businesses' => '/\b(?:landscaping|lawn care|landscape|home service|local|contractor)s? (?:companies|businesses|pros|owners)\b|\b(?:businesses|companies) like yours\b/i',
        'custom gifts'        => '/\bcustom\b.{0,40}\bgifts?\b|\bcorporate gifts?\b|\bswag\b|\bmerch(?:andise)?\b/is',
        'engraved'            => '/\bengrav(?:ed|ing)\b/i',
        'branded'             => '/\bbranded\b|\byour logo\b/i',
        'marketing'           => '/\bmarketing\b|\badvertis(?:e|ing)\b|\bsocial media management\b/i',
        'website design'      => '/\bweb ?(?:site)? ?(?:design|development|redesign)\b/i',
        'investment'          => '/\binvest(?:ment|or|ors|ing)?\b(?!\s+propert)|\bacquisition\b|\bbuy your business\b/i',
        'loan offer'          => '/\bloan\b|\bfunding\b|\bline of credit\b|\bmerchant cash\b|\bfinancing (?:offer|options)\b/i',
        'free audit/demo'     => '/\bfree (?:audit|trial|demo|sample|mockup)\b|\bbook a (?:call|demo)\b|\b15[- ]minute (?:call|chat)\b/i',
        'unsubscribe footer'  => '/\bunsubscribe\b|\bopt[- ]out\b|\bremove you from\b|\bno longer (?:wish|want) to receive\b/i',
    ];

    /** Pitch signals that are decisive on their own. */
    private const PITCH_DECISIVE = ['pitch in subject', 'marketing sender', 'bulk mail headers', 'unsubscribe footer'];

    /** Free-mail domains: never "marketing-looking", whatever the name. */
    private const FREE_MAIL = ['gmail', 'googlemail', 'outlook', 'hotmail', 'live', 'msn', 'yahoo', 'icloud', 'me', 'mac',
                               'aol', 'shaw', 'telus', 'rogers', 'sympatico', 'protonmail', 'proton', 'gmx', 'mail'];

    /** Payment platforms — never enquiries. Matched on the sender's domain. label => pattern. */
    private const PAYMENT_PLATFORMS = [
        'PayPal'  => '/(?:^|\.)paypal\.[a-z.]+$/',
        'Stripe'  => '/(?:^|\.)stripe\.(?:com|network)$/',
        'Square'  => '/(?:^|\.)squareup\.[a-z.]+$|(?:^|\.)square\.com$/',
        'Wise'    => '/(?:^|\.)(?:wise|transferwise)\.com$/',
        'Interac' => '/(?:^|\.)interac\.ca$/',
        'Wave'    => '/(?:^|\.)waveapps\.[a-z.]+$|(?:^|\.)wave\.com$/',
    ];

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

    /** The lowercase domain of an address ('' if none). */
    private static function domainOf(string $addr): string
    {
        $at = strrchr(strtolower(trim($addr)), '@');
        return $at === false ? '' : (string)substr($at, 1);
    }

    /** 'PayPal' / 'Stripe' / 'Square' / 'Wise' / 'Interac' / 'Wave' when the sender is one, else null. */
    public static function paymentPlatform(string $fromAddr): ?string
    {
        $domain = self::domainOf($fromAddr);
        if ($domain === '') return null;
        foreach (self::PAYMENT_PLATFORMS as $name => $re) {
            if (preg_match($re, $domain)) return $name;
        }
        return null;
    }

    /**
     * What a payment platform's email is about, from its subject:
     *   'sent'     — money Tim paid out (PayPal "You sent a payment", "Receipt for your payment") → a receipt
     *   'received' — money in ("You received a payment", "You've got money") → a payment notice
     *   null       — anything else (statements, security notices, marketing) → ignored
     */
    public static function paymentKind(string $subject): ?string
    {
        if (preg_match('/\byou sent (?:a payment|an? automatic payment|\$|money|[A-Z]{2,3}\b)|\breceipt for (?:your )?(?:payment|purchase)|\byou(?:\'ve)? paid\b|\byour payment to\b|\bpayment sent\b|\byou\'ve sent\b|\breceipt from\b|\bautomatic payment\b|\bpre-?approved payment\b/i', $subject)) {
            return 'sent';
        }
        if (preg_match('/\byou(?:\'ve| have)? (?:received|got) (?:a payment|money|\$|a new payment)|\bsent you (?:a payment|money|\$)|\bpayment received\b|\breceived a payment\b|\bnew payment\b|\bmoney is waiting\b|\binvoice (?:was )?paid\b|\bpaid your invoice\b/i', $subject)) {
            return 'received';
        }
        return null;
    }

    /**
     * Who a payment was sent to, from a platform's "you sent a payment" email ('' if not found).
     * "You sent $45.00 CAD to Acme Tree Care" / "Receipt for your payment to Acme Tree Care".
     */
    public static function paymentPayee(string $subject, string $body): string
    {
        $end = '\s*(?:$|[\r\n]|\.(?:\s|$)|\(|\bfor\b|\bon\b)';
        $patterns = [
            '/\bpayment to\s+([^\r\n]{2,80}?)' . $end . '/im',
            '/\b(?:sent|paid)\s+(?:a payment of\s+)?(?:[A-Z]{0,3}\s?\$|\$)?\s?[\d,]+(?:\.\d{2})?\s*(?:[A-Z]{3}\b)?\s+to\s+([^\r\n]{2,80}?)' . $end . '/im',
            '/^\s*(?:merchant|paid to|recipient|sent to)\s*:?\s*\r?\n?\s*([^\r\n]{2,80})/im',
        ];
        foreach ([$subject, $body] as $text) {
            foreach ($patterns as $re) {
                if (preg_match($re, $text, $m)) {
                    $p = trim($m[1], " \t.,:;\"'");
                    if ($p !== '' && !preg_match('/^(?:you|your|the payment)\b/i', $p)) return mb_substr($p, 0, 120);
                }
            }
        }
        return '';
    }

    /** A sender domain that looks like a marketing / lead-gen / bulk-mail outfit (never free mail). */
    public static function marketingDomain(string $fromAddr): bool
    {
        $domain = self::domainOf($fromAddr);
        if ($domain === '') return false;
        $labels = explode('.', $domain);
        $name = $labels[count($labels) - 2] ?? '';
        if (in_array($name, self::FREE_MAIL, true)) return false;
        if (count($labels) >= 3 && in_array($labels[0], ['mail', 'email', 'e', 'em', 'news', 'newsletter', 'mg', 'mkt', 'marketing',
                'promo', 'send', 'bounce', 'campaign', 'campaigns', 'offers', 'deals', 'click'], true)) {
            return true;
        }
        return (bool)preg_match('/promo|smart|marketing|leads?(?:gen)?$|^leads?|seo|growth|agency|advert|outreach|campaign|blast|mailer|newsletter/', $name);
    }

    /**
     * Cold-sales signals: the email is someone selling TO Mowology, not asking for work.
     * @param string[] $businessNames our names — "… for MOWOLOGY" in a subject is a pitch
     * @return string[] signal labels (empty = no pitch)
     */
    public static function pitchSignals(string $subject, string $body, string $fromAddr = '', string $rawHeaders = '', array $businessNames = ['Mowology']): array
    {
        $out = [];
        foreach ($businessNames as $n) {
            $n = trim((string)$n);
            if ($n !== '' && preg_match('/\b(?:for|to|with)\s+(?:the\s+)?' . preg_quote($n, '/') . '\b/i', $subject)) {
                $out[] = 'pitch in subject';
                break;
            }
        }
        if ($fromAddr !== '' && self::marketingDomain($fromAddr)) $out[] = 'marketing sender';
        if ($rawHeaders !== '' && self::isAutomated('person@example.invalid', $rawHeaders)) $out[] = 'bulk mail headers';
        $text = $subject . "\n" . $body;
        foreach (self::PITCH as $label => $re) {
            if (preg_match($re, $text)) $out[] = $label;
        }
        return array_values(array_unique($out));
    }

    /**
     * A street address that reads as the WRITER's property ("at 123 W 4th", "my house on 4512
     * Oak St", "our strata at …") — not one sitting alone on a line of a signature or footer.
     */
    public static function theirAddress(string $text): ?string
    {
        $addr = self::findAddress($text);
        if ($addr === null) return null;
        $pos = strpos($text, $addr);
        if ($pos === false) return null;
        $before = substr($text, max(0, $pos - 80), min(80, $pos));
        $line = (string)preg_replace('/^.*[\r\n]/s', '', $before);   // the same line only
        return preg_match('/\b(?:at|on|is|my|our|property|house|home|place|live|located|address|building|strata|complex|for|of)\b\W*$/i', $line) ? $addr : null;
    }

    /**
     * How strongly an email reads as a request FOR Mowology's work, and why.
     * Needs customer intent + a service word; any cold-sales signal decisive on its own (pitch in
     * the subject, marketing sender, bulk headers, unsubscribe footer) or two soft ones exclude it,
     * one soft one turns strong into weak. Payment-platform senders are never enquiries.
     * @param string $fromAddr sender (marketing-domain / payment-platform checks; '' to skip)
     * @param string $rawHeaders header block (bulk-mail headers are a pitch signal; '' to skip)
     * @return array{strength: ?string, score: int, services: string[], address: ?string, ask: bool,
     *               intent: string[], pitch: string[], why: string}
     */
    public static function score(string $subject, string $body, string $fromAddr = '', string $rawHeaders = ''): array
    {
        $text = $subject . "\n" . $body;
        $services = [];
        foreach (self::SERVICES as $tag => $re) {
            if (preg_match($re, $text)) $services[] = $tag;
        }
        $ask = (bool)preg_match(self::ASK, $text);
        $intent = [];
        foreach (self::INTENT as $label => $re) {
            if (preg_match($re, $text)) $intent[] = $label;
        }
        $address = self::theirAddress($text);
        if ($address !== null) $intent[] = 'their address';
        $platform = $fromAddr !== '' ? self::paymentPlatform($fromAddr) : null;
        $pitch = self::pitchSignals($subject, $body, $fromAddr, $rawHeaders);
        $decisive = array_intersect($pitch, self::PITCH_DECISIVE);
        $n = count($services);

        $strength = null;
        if ($platform === null && !$decisive && count($pitch) < 2 && $intent && $n >= 1) {
            $strength = ($address !== null || $n >= 2) ? 'strong' : 'weak';
            if ($strength === 'strong' && $pitch) $strength = 'weak';   // one soft pitch signal: Tim decides
        }
        $score = ($intent ? 2 : 0) + $n + ($address !== null ? 2 : 0) - 2 * count($pitch);

        $why = [];
        if ($platform !== null) $why[] = 'payment platform: ' . $platform;
        if ($intent) $why[] = 'intent: ' . implode(', ', $intent);
        if ($services) $why[] = 'services: ' . implode(', ', $services);
        if ($address !== null) $why[] = 'address: ' . $address;
        if ($pitch) $why[] = 'pitch: ' . implode(', ', $pitch);
        if (!$intent) $why[] = 'no customer intent';
        elseif (!$services) $why[] = 'no service words';
        return ['strength' => $strength, 'score' => $score, 'services' => $services, 'address' => $address, 'ask' => $ask,
                'intent' => $intent, 'pitch' => $pitch, 'why' => implode('; ', $why)];
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
