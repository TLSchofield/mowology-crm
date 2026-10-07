<?php
/**
 * UnclaimedReplyService — every customer reply that no other net caught, split by who owns it.
 *
 * Why (2026-10-06): Gaby at Cambridge Apartments replied "Yes" to a fall-cleanup email Tim
 * sent by hand from office@, and nothing in the CRM surfaced it. Each existing net only
 * catches its own kind of reply: Sam's quote queue (replies about open quotes), Mia's
 * campaign_reply items, and Field Ask-first (replies to its Send button). This is the
 * catch-all.
 *
 * A reply is "unclaimed" when it is an inbound sales_messages row (email or text) that is
 *   - from the last WINDOW_DAYS days and from a known contact;
 *   - not covered by another net: Sam's queue shows the contact as "replied"; Mia lists the
 *     contact's campaign reply; or a Field Ask-first note to that contact was answered by
 *     it (FieldAskService::classifyAsk — the reply came after the ask);
 *   - not answered: no outbound message to the contact, no quote created for them
 *     (quotes.contact_id or the property's site contact), after the reply — and the item's
 *     key was not dismissed (any time) or snoozed (until a future day) in Charlie;
 *   - not automated (no-reply senders, bounces, out-of-office).
 * One item per contact: their latest such reply. A short "yes" ranks first (isYes()).
 *
 * Two lanes (2026-10-06, Yui — comms / client relations — took over client conversations):
 *   quote  — the reply talks about a quote / estimate / proposal (aboutQuote()): Sam's, shown
 *            on his card under "Replies waiting", kind quote_reply, key sam:reply:<contact>:<hash>;
 *   client — everything else: Yui's inbox, kind client_reply, key yui:reply:<contact>:<hash>.
 * Each reply is in exactly one lane, so it is never shown twice. A key "Handled" under
 * either prefix hides the reply (Sam's old dismissals keep counting after the move).
 *
 * Read-only and cheap: Sam's and Yui's brief() call it for Charlie. "Handled" is Charlie's
 * act/dismiss for the key (Sam's card) or Yui's own handled log (passed in as $extraHidden).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class UnclaimedReplyService
{
    public const WINDOW_DAYS = 14;
    public const KEY_PREFIX = 'sam:reply:';
    public const YUI_PREFIX = 'yui:reply:';
    public const QUOTE_CHARS = 60;
    /** isYes(): a message longer than this is not a "short" yes. */
    public const YES_MAX_CHARS = 200;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function hasTable(string $t): bool
    {
        try {
            // Literal, not a placeholder: MySQL won't take SHOW TABLES LIKE ? as a native prepared statement.
            return $this->db->query("SHOW TABLES LIKE " . $this->db->quote(preg_replace('/[^a-z0-9_]/', '', $t)))->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The unclaimed replies, best first.
     * @param int[]    $samRepliedContactIds contacts Sam's quote queue already shows as "replied"
     * @param string[] $extraHidden          more keys to hide (Yui's "Handled" log)
     * @return array<int, array> brief items plus card fields (contact_id, name, subject, quote, channel, yes, at, lane, message_key)
     */
    public function items(array $samRepliedContactIds = [], ?string $now = null, array $extraHidden = []): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        if (!$this->hasTable('sales_messages')) return [];
        $from = date('Y-m-d H:i:s', strtotime($now) - self::WINDOW_DAYS * 86400);

        $s = $this->db->prepare("
            SELECT m.message_key, m.contact_id, m.channel, m.from_addr, m.subject, m.snippet, m.sent_at, c.first_name
            FROM sales_messages m
            JOIN contacts c ON c.id = m.contact_id
            WHERE m.direction = 'inbound' AND m.contact_id IS NOT NULL AND m.sent_at >= ?
              AND CONCAT_WS(' ', c.first_name, c.last_name) NOT LIKE '%ZZTEST%'
            ORDER BY m.sent_at DESC, m.id DESC
            LIMIT 400
        ");
        $s->execute([$from]);
        $replies = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$replies) return [];
        $ids = implode(',', array_unique(array_map(fn($r) => (int)$r['contact_id'], $replies)));   // ints only

        $outbound = [];
        $o = $this->db->prepare("SELECT contact_id, MAX(sent_at) FROM sales_messages
                                 WHERE direction = 'outbound' AND contact_id IN ({$ids}) AND sent_at >= ? GROUP BY contact_id");
        $o->execute([$from]);
        foreach ($o->fetchAll(PDO::FETCH_NUM) as $r) $outbound[(int)$r[0]] = (string)$r[1];

        $quotes = [];
        try {
            $q = $this->db->prepare("SELECT q.contact_id, p.site_contact_id, q.created_at
                                     FROM quotes q LEFT JOIN properties p ON p.id = q.property_id
                                     WHERE q.created_at >= ? AND (q.contact_id IN ({$ids}) OR p.site_contact_id IN ({$ids}))");
            $q->execute([$from]);
            $quotes = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* no quotes → nothing answered by a quote */ }

        return self::unclaimed($replies, [
            'sam'      => $samRepliedContactIds,
            'mia'      => $this->miaContacts($now),
            'asks'     => $this->asks($now),
            'outbound' => $outbound,
            'quotes'   => $quotes,
            'hidden'   => array_merge($this->hiddenKeys($now), $extraHidden),
        ], $now);
    }

    /** Contacts Mia already lists as having replied to her campaign. */
    private function miaContacts(string $now): array
    {
        $file = dirname(__DIR__, 2) . '/Marketing/Services/MiaCampaignService.php';
        if (!class_exists('MiaCampaignService') && !is_file($file)) return [];
        try {
            require_once $file;
            $out = [];
            foreach ((new MiaCampaignService($this->db))->replyItems(new DateTimeImmutable($now)) as $it) {
                // key: mia:campaign_reply:<campaign id>:<contact id>
                $parts = explode(':', (string)($it['key'] ?? ''));
                if (count($parts) === 4) $out[] = (int)$parts[3];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('Unclaimed replies (Mia): ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Open Ask-first notes — the same rows FieldAskService::forSam() reads (status asked,
     * within MAX_AGE_DAYS), each with the two people whose reply counts: the person asked
     * and the billing contact. Read-only (forSam() stamps replied_at; this doesn't).
     */
    private function asks(string $now): array
    {
        if (!$this->hasTable('field_observations')) return [];
        $file = dirname(__DIR__, 2) . '/Products/Services/FieldAskService.php';
        if (!class_exists('FieldAskService') && is_file($file)) require_once $file;
        $maxAge = class_exists('FieldAskService') ? FieldAskService::MAX_AGE_DAYS : 45;
        try {
            $s = $this->db->prepare("
                SELECT fo.ask_contact_id, COALESCE(p.site_contact_id, fo.contact_id) AS billing_contact_id, fo.asked_at
                FROM field_observations fo LEFT JOIN properties p ON p.id = fo.property_id
                WHERE fo.intent = 'ask' AND fo.status = 'asked' AND fo.asked_at IS NOT NULL AND fo.asked_at >= ?
            ");
            $s->execute([date('Y-m-d H:i:s', strtotime($now) - $maxAge * 86400)]);
            return array_map(fn($r) => [
                'contacts' => array_values(array_filter([(int)$r['ask_contact_id'], (int)$r['billing_contact_id']])),
                'asked_at' => (string)$r['asked_at'],
            ], $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Keys Tim marked "Handled" (Charlie dismiss, any day) or snoozed to a later day. */
    private function hiddenKeys(string $now): array
    {
        if (!$this->hasTable('charlie_items')) return [];
        try {
            $s = $this->db->prepare("SELECT item_key FROM charlie_items
                                     WHERE (item_key LIKE 'sam:reply:%' OR item_key LIKE 'yui:reply:%')
                                       AND (dismissed_at IS NOT NULL OR snoozed_until > ?)");
            $s->execute([substr($now, 0, 10)]);
            return $s->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array $replies inbound rows: message_key, contact_id, channel, from_addr, subject, snippet, sent_at, first_name
     * @param array $ctx     sam: int[] · mia: int[] · asks: [['contacts' => int[], 'asked_at']] ·
     *                       outbound: contact_id => latest sent_at · quotes: [['contact_id', 'site_contact_id', 'created_at']] ·
     *                       hidden: item keys
     * @param string $now    'Y-m-d H:i:s'
     */
    public static function unclaimed(array $replies, array $ctx, string $now): array
    {
        $from = strtotime($now) - self::WINDOW_DAYS * 86400;
        $sam = array_flip(array_map('intval', $ctx['sam'] ?? []));
        $mia = array_flip(array_map('intval', $ctx['mia'] ?? []));
        $hidden = array_flip($ctx['hidden'] ?? []);

        // Latest reply per contact that no other net has, and that a person wrote.
        $latest = [];
        foreach ($replies as $r) {
            $cid = (int)($r['contact_id'] ?? 0);
            $t = strtotime((string)($r['sent_at'] ?? ''));
            if ($cid <= 0 || $t === false || $t < $from) continue;
            if (isset($sam[$cid]) || isset($mia[$cid])) continue;
            if (self::isAutomated((string)($r['from_addr'] ?? ''), (string)($r['subject'] ?? ''), (string)($r['snippet'] ?? ''))) continue;
            if (self::coveredByAsk($cid, (string)$r['sent_at'], $ctx['asks'] ?? [], $now)) continue;
            if (!isset($latest[$cid]) || $t > $latest[$cid]['_t']) $latest[$cid] = $r + ['_t' => $t];
        }

        $items = [];
        foreach ($latest as $cid => $r) {
            if (self::answered($cid, (string)$r['sent_at'], $ctx)) continue;
            $mk = (string)$r['message_key'];
            if (isset($hidden[self::key($cid, $mk, 'quote')]) || isset($hidden[self::key($cid, $mk, 'client')])) continue;
            $lane = self::lane((string)($r['subject'] ?? ''), (string)($r['snippet'] ?? ''));
            $items[] = self::item($cid, $r, self::key($cid, $mk, $lane), $lane);
        }
        usort($items, function ($a, $b) {
            if ($a['priority'] !== $b['priority']) return $a['priority'] <=> $b['priority'];
            return strcmp((string)$b['at'], (string)$a['at']);   // newest first
        });
        return $items;
    }

    /** Something happened after the reply: we wrote or texted them, or a quote was made for them. */
    public static function answered(int $cid, string $replyAt, array $ctx): bool
    {
        $out = $ctx['outbound'][$cid] ?? null;
        if ($out !== null && $out !== '' && $out > $replyAt) return true;
        foreach ($ctx['quotes'] ?? [] as $q) {
            if (((int)($q['contact_id'] ?? 0) === $cid || (int)($q['site_contact_id'] ?? 0) === $cid)
                && (string)$q['created_at'] >= $replyAt) {
                return true;
            }
        }
        return false;
    }

    /** Field Ask-first already shows this reply: it came after an open ask to this contact. */
    public static function coveredByAsk(int $cid, string $replyAt, array $asks, string $now): bool
    {
        foreach ($asks as $a) {
            if (!in_array($cid, array_map('intval', $a['contacts'] ?? []), true)) continue;
            $replied = class_exists('FieldAskService')
                ? FieldAskService::classifyAsk((string)$a['asked_at'], $replyAt, $now) === 'replied'
                : $replyAt > (string)$a['asked_at'];
            if ($replied) return true;
        }
        return false;
    }

    /** @param string $lane 'quote' (Sam) | 'client' (Yui) */
    public static function key(int $cid, string $messageKey, string $lane = 'quote'): string
    {
        return ($lane === 'client' ? self::YUI_PREFIX : self::KEY_PREFIX) . $cid . ':' . substr(sha1($messageKey), 0, 12);
    }

    /**
     * Whose reply is it? Sam's when it is about a quote, OR when it is a clear yes to work
     * (Gaby: "Yes" to "fall cleanup" — that is a sale to quote, not a conversation to keep).
     * A yes about money (invoices, payments, statements) stays with Yui.
     */
    public static function lane(string $subject, string $snippet): string
    {
        if (self::aboutQuote($subject, $snippet)) return 'quote';
        $money = (bool)preg_match('/\b(invoice|payment|paid|statement|receipt|e-?transfer|overdue|balance)\b/i', $subject . ' ' . $snippet);
        return (!$money && self::isYes($snippet)) ? 'quote' : 'client';
    }

    /** Is the reply about a quote (Sam's lane)? Its subject or text names a quote, estimate or proposal. */
    public static function aboutQuote(string $subject, string $snippet): bool
    {
        return (bool)preg_match('/\b(?:quotes?|quoted|quotation|estimates?|proposals?|QUO-\d{4}-\d+)\b/i', $subject . "\n" . $snippet);
    }

    /** Machines, not people: no-reply senders, bounces, notifications, out-of-office. */
    public static function isAutomated(string $from, string $subject, string $snippet): bool
    {
        $addr = strtolower(trim($from));
        if (preg_match('/<([^>]+)>/', $addr, $m)) $addr = $m[1];
        $local = (string)strtok($addr, '@');
        if (strpos($addr, 'noreply') !== false || strpos($addr, 'no-reply') !== false
            || preg_match('/^(do-?not-?reply|mailer-daemon|postmaster|notifications?|notify|bounces?|auto-?reply|autoresponder)$/', $local)) {
            return true;
        }
        if (preg_match('/^\s*(automatic reply|auto(matic)?[- ]?reply|autoreply|out of (the )?office|undeliverable|delivery status notification|mail delivery (failed|failure)|returned mail)\b/i', $subject)) {
            return true;
        }
        return (bool)preg_match('/\b(this is an automated (message|response|reply)|i am (currently )?(out of the office|away from the office|on vacation|on leave)'
            . '|i\'m (currently )?(out of the office|away from the office|on vacation|on leave)'
            . '|(will|i\'ll) (respond|reply|get back to you)[^.]{0,40}(when|upon) (i|my) return'
            . '|limited access to (my )?e-?mail)/i', $snippet);
    }

    /**
     * Is this a short "yes, go ahead"? The rule:
     *   1. The whole message (newlines folded) is at most YES_MAX_CHARS characters, and does
     *      not open with no / nope / not / no thanks.
     *   2. Leading sentences that are only a greeting or a thank-you ("Hi Tim", "Thanks Tim.")
     *      are skipped; a greeting in front of the first sentence is trimmed off it.
     *   3. That first real sentence either
     *      a. opens with an affirmative — yes / yep / yeah / sure / ok / okay / perfect /
     *         approved / please do / please go ahead / please proceed / go ahead / go for it /
     *         sounds good|great / let's do it / book it|us|me in / that works — is not itself a
     *         question (no "?"), and carries no hedge (but, however, though, unless, not, n't,
     *         wait, hold off, maybe); a later question is fine ("Yes please. Monday?"); or
     *      b. asks us to proceed — "if you could / could you / can you / please / I'd like you
     *         to …" ending in "that would be great|perfect|appreciated", or "go ahead", "do what's
     *         needed", "take care of it" — with no "?" anywhere in the message and no talk of
     *         price / quote / estimate / cost (that's a request for a quote, not a yes).
     *   Question-only replies are never a yes.
     */
    public static function isYes(string $snippet): bool
    {
        $t = trim(preg_replace('/\s+/u', ' ', str_replace(["\u{2019}", "\u{2018}"], "'", $snippet)));
        if ($t === '' || mb_strlen($t) > self::YES_MAX_CHARS) return false;
        $lower = mb_strtolower($t);
        if (preg_match("/^(no|nope|not|nah)\b/", $lower)) return false;

        $stop = "(?!(?:yes|yep|yeah|no|not|please|ok|okay|sure|go|sounds|let'?s|book|approved|perfect|so|very|for|again|that|if|could|can)\b)";
        $greet = "/^(?:hi|hello|hey|good (?:morning|afternoon|evening)|dear)\b[\s,!.:-]*(?:{$stop}[a-z][a-z'-]*)?[\s,!.:-]*/i";
        $thanks = "/^(?:thanks|thank you|thx|ty)(?: (?:so|very) much)?(?: again)?[\s,!.]*(?:{$stop}[a-z][a-z'-]*)?[\s,!.]*$/i";

        // Sentences with their terminator, so a "?" stays with its sentence.
        preg_match_all('/[^.!?]+[.!?]*/', $lower, $m);
        $first = null;
        foreach ($m[0] as $s) {
            $s = trim($s);
            $s = trim((string)preg_replace($greet, '', $s));
            if ($s === '' || preg_match('/^[.!?,\s]*$/', $s) || preg_match($thanks, $s)) continue;
            $first = $s;
            break;
        }
        if ($first === null) return false;

        $affirm = "/^(?:yes|yep|yup|yeah|yea|sure|ok|okay|k|perfect|approved|i approve|please do|please go ahead|please proceed|proceed|go ahead|go for it"
                . "|sounds (?:good|great|perfect)|let'?s do it|let'?s go ahead|book (?:it|us|me)|that works|absolutely|definitely)\b/";
        $hedge = "/\b(?:but|however|though|although|unless|not|wait|hold off|maybe)\b|n't\b/";
        if (preg_match($affirm, $first)) {
            return strpos($first, '?') === false && !preg_match($hedge, $first);
        }

        $proceed = "/^(?:if you could|could you|can you|please|i'?d like you to|we'?d like you to|you can)\b.*"
                 . "(?:(?:that|it) would be (?:great|perfect|appreciated|wonderful)|go ahead|do what'?s needed|take care of it)/";
        return (bool)preg_match($proceed, $first)
            && strpos($t, '?') === false
            && !preg_match('/\b(?:price|pricing|quote|estimate|cost|how much)\b/', $lower);
    }

    /** A first name fit to show: never an email address or a phone number, one word. */
    public static function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strpos($name, '@') !== false || preg_match('/\d/', $name)) return '';
        return (string)strtok($name, ' ');
    }

    /** Text safe to quote: no email addresses or phone numbers. */
    public static function scrub(string $s): string
    {
        $s = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '…', $s);
        $s = preg_replace('/\+?\d[\d\s().-]{6,}\d/', '…', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** The first line of the reply, up to QUOTE_CHARS characters. */
    public static function quote(string $snippet): string
    {
        $line = '';
        foreach (preg_split('/\R/u', $snippet) as $l) {
            if (trim($l) !== '') { $line = self::scrub($l); break; }
        }
        return mb_strlen($line) > self::QUOTE_CHARS ? rtrim(mb_substr($line, 0, self::QUOTE_CHARS - 1)) . '…' : $line;
    }

    /** The subject without Re:/Fwd: and without anything that identifies a person. */
    public static function cleanSubject(string $s): string
    {
        $s = preg_replace('/^\s*((re|fwd?|fw)\s*:\s*)+/i', '', $s);
        $s = self::scrub($s);
        return mb_strlen($s) > 80 ? rtrim(mb_substr($s, 0, 79)) . '…' : $s;
    }

    private static function item(int $cid, array $r, string $key, string $lane = 'quote'): array
    {
        $name = self::firstName((string)($r['first_name'] ?? ''));
        $who = $name !== '' ? $name : 'A customer';
        $quote = self::quote((string)($r['snippet'] ?? ''));
        $subject = self::cleanSubject((string)($r['subject'] ?? ''));
        $isText = ($r['channel'] ?? '') === 'sms';
        $yes = self::isYes((string)($r['snippet'] ?? ''));

        $text = $who . ($isText ? ' texted' : ' replied')
            . ($quote !== '' ? ' "' . $quote . '"' : '')
            . (!$isText && $subject !== '' ? ' to "' . $subject . '"' : '')
            . '. ' . ($yes ? ($lane === 'quote' ? 'That\'s a yes. Send the quote.' : 'That\'s a yes. Answer them.') : 'Answer them.');
        return [
            'key'        => $key,
            'kind'       => $lane === 'client' ? 'client_reply' : 'quote_reply',
            'value'      => null,
            'since'      => date('Y-m-d', (int)$r['_t']),
            'text'       => $text,
            'url'        => '/crm/clients_appstack.php?action=view_contact&id=' . $cid,
            'priority'   => $yes ? 1 : 2,
            'yes'        => $yes,
            // for the card (Sam's "Replies waiting" / Yui's inbox)
            'lane'       => $lane,
            'message_key'=> (string)$r['message_key'],
            'contact_id' => $cid,
            'name'       => $who,
            'subject'    => $subject,
            'quote'      => $quote,
            'channel'    => $isText ? 'sms' : 'email',
            'yes'        => $yes,
            'at'         => (string)$r['sent_at'],
        ];
    }
}
