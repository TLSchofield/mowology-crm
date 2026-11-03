<?php
/**
 * SalesDeskService — Sam, the sales head on the dashboard: his numbers, the quotes
 * nobody has answered (one card per customer), and new leads ranked by likely value.
 *
 * Sam's job: more quotes accepted, no lead forgotten. He never sends anything — every
 * card carries a suggested follow-up that Tim sends, edits, skips or snoozes
 * (SamFollowupService). What counts as "waiting":
 *   - a quote sent (or viewed) with no acceptance, still within its valid-until date;
 *   - grouped by customer: a property manager with ten open quotes gets one note, not ten;
 *   - due once the last touch (sent, follow-up, office email) is older than the wait for
 *     that service — learned from how long accepted quotes took (median, 5+ quotes),
 *     else the sam_stale_days setting (default 5);
 *   - the customer wrote back after our last email (sales_messages, read from office@)
 *     → "they replied, you haven't" comes first, before any nudge;
 *   - at most 3 follow-ups; quotes past valid-until become questions (SamQuestionService).
 * Test records (anything named ZZTEST) are left out everywhere.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 2) . '/Quotes/Services/QuoteService.php'; // colourState()
require_once __DIR__ . '/LeadScore.php';                                // heat, buckets, flat guard (2026-11-03)
require_once __DIR__ . '/EmailLeadService.php';                         // serviceTags(): what we offer
require_once __DIR__ . '/ClientRhythm.php';     // quiet / gone clients against their own rhythm (2026-11-03)
require_once __DIR__ . '/ClientTier.php';
require_once __DIR__ . '/RenewalRadar.php';
require_once __DIR__ . '/SamSignals.php';

class SalesDeskService
{
    public const DEFAULT_STALE_DAYS = 5;
    public const MAX_FOLLOWUPS = 3;
    /** A follow-up "won" if one of its quotes was accepted within this many days of it. */
    public const WON_WINDOW_DAYS = 21;
    /** Learned waits per service are clamped to this range and need this many accepted quotes. */
    public const LEARN_MIN_QUOTES = 5;
    public const LEARN_MIN_DAYS = 3;
    public const LEARN_MAX_DAYS = 14;
    /** Leads older than this are no longer "new". */
    public const LEAD_MAX_AGE_DAYS = 60;
    /** Quotes this close to valid-until get the "last call" note. */
    public const LAST_CALL_DAYS = 5;
    /** Unclaimed replies about a quote handed to Charlie per brief (yeses first). Other client replies are Yui's. */
    public const UNCLAIMED_BRIEF_MAX = 8;

    /** Dollars on a quote: some writers fill total_amount, older ones only amount. */
    public const AMOUNT_SQL = "COALESCE(NULLIF(q.total_amount, 0), q.amount, 0)";
    public const NOT_TEST_SQL = "CONCAT_WS(' ', c.first_name, c.last_name, q.title) NOT LIKE '%ZZTEST%'";

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Migration 1140 has run. */
    public function ready(): bool
    {
        return $this->hasTable('sam_followups');
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

    public function hasColumn(string $t, string $c): bool
    {
        try {
            $t = preg_replace('/[^a-z0-9_]/', '', $t);
            $c = preg_replace('/[^a-z0-9_]/', '', $c);
            $this->db->query("SELECT `{$c}` FROM `{$t}` LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function staleDays(): int
    {
        try {
            $v = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'sam_stale_days'")->fetchColumn();
            if ($v !== false && (int)$v > 0) return max(1, min(30, (int)$v));
        } catch (Throwable $e) { /* default */ }
        return self::DEFAULT_STALE_DAYS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Numbers
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{won:int, lost:int, win_rate:?int, avg_days:?float, waiting_amount:float, waiting_quotes:int, waiting_people:int, won_by_followup:int, won_by_followup_amount:float, leads_new:int} */
    public function stats(): array
    {
        $amt = self::AMOUNT_SQL;
        $notTest = self::NOT_TEST_SQL;
        $r = $this->db->query("
            SELECT
              SUM(q.status = 'accepted') AS won,
              SUM(q.status IN ('declined', 'expired')
                  OR (q.status IN ('sent', 'viewed') AND q.valid_until IS NOT NULL AND q.valid_until < CURDATE())) AS lost,
              AVG(CASE WHEN q.status = 'accepted' AND q.accepted_at IS NOT NULL AND q.sent_at IS NOT NULL AND q.accepted_at >= q.sent_at
                       THEN DATEDIFF(q.accepted_at, q.sent_at) END) AS avg_days
            FROM quotes q
            LEFT JOIN contacts c ON c.id = q.contact_id
            WHERE q.status <> 'draft'
              AND COALESCE(q.sent_at, q.accepted_at, q.created_at) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
              AND {$notTest}
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $w = $this->db->query("
            SELECT COUNT(*) AS n, COALESCE(SUM({$amt}), 0) AS amount,
                   COUNT(DISTINCT COALESCE(q.contact_id, -q.id)) AS people
            FROM quotes q
            LEFT JOIN contacts c ON c.id = q.contact_id
            WHERE q.status IN ('sent', 'viewed')
              AND (q.valid_until IS NULL OR q.valid_until >= CURDATE())
              AND {$notTest}
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $won = (int)($r['won'] ?? 0);
        $lost = (int)($r['lost'] ?? 0);
        $byFollowup = $this->wonByFollowup();
        return [
            'won'                    => $won,
            'lost'                   => $lost,
            'win_rate'               => self::winRate($won, $lost),
            'avg_days'               => isset($r['avg_days']) && $r['avg_days'] !== null ? round((float)$r['avg_days'], 1) : null,
            'waiting_amount'         => round((float)($w['amount'] ?? 0), 2),
            'waiting_quotes'         => (int)($w['n'] ?? 0),
            'waiting_people'         => (int)($w['people'] ?? 0),
            'won_by_followup'        => $byFollowup['n'],
            'won_by_followup_amount' => $byFollowup['amount'],
            'leads_new'              => $this->countNewLeads(),
        ];
    }

    /** Follow-ups Tim sent through Sam whose quote was accepted within the window afterwards. */
    public function wonByFollowup(): array
    {
        if (!$this->ready()) return ['n' => 0, 'amount' => 0.0];
        try {
            $amt = self::AMOUNT_SQL;
            $r = $this->db->query("
                SELECT COUNT(DISTINCT f.id) AS n, COALESCE(SUM({$amt}), 0) AS amount
                FROM sam_followups f
                JOIN quotes q ON FIND_IN_SET(q.id, f.quote_ids) > 0
                WHERE f.status IN ('sent', 'edited')
                  AND q.status = 'accepted' AND q.accepted_at IS NOT NULL
                  AND q.accepted_at BETWEEN f.decided_at AND DATE_ADD(f.decided_at, INTERVAL " . self::WON_WINDOW_DAYS . " DAY)
            ")->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['n' => (int)($r['n'] ?? 0), 'amount' => round((float)($r['amount'] ?? 0), 2)];
        } catch (Throwable $e) {
            return ['n' => 0, 'amount' => 0.0];
        }
    }

    private function countNewLeads(): int
    {
        try {
            return (int)$this->db->query("
                SELECT COUNT(*) FROM quote_requests
                WHERE status IN ('new', 'reviewing') AND quote_id IS NULL
                  AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::LEAD_MAX_AGE_DAYS . " DAY)
            ")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Quotes waiting on a reply
    // ─────────────────────────────────────────────────────────────────────────

    /** Open quotes with their customer — the raw rows behind the cards. */
    public function openQuotes(): array
    {
        $amt = self::AMOUNT_SQL;
        $notTest = self::NOT_TEST_SQL;
        return $this->db->query("
            SELECT q.id, q.quote_number, q.title, q.service_type, {$amt} AS amount, q.status,
                   q.sent_at, q.viewed_at, q.last_viewed_at, q.email_opened_at, q.view_count, q.valid_until,
                   q.follow_up_sent_at, COALESCE(q.follow_up_count, 0) AS follow_up_count,
                   c.id AS contact_id, c.first_name, c.last_name, c.email, COALESCE(NULLIF(c.mobile, ''), c.phone) AS phone,
                   co.company_name AS company_name, p.address, q.company_id, c.company_id AS contact_company_id
            FROM quotes q
            LEFT JOIN properties p ON p.id = q.property_id
            LEFT JOIN contacts c ON c.id = COALESCE(q.contact_id, p.site_contact_id)
            LEFT JOIN companies co ON co.id = q.company_id
            WHERE q.status IN ('sent', 'viewed') AND q.sent_at IS NOT NULL
              AND (q.valid_until IS NULL OR q.valid_until >= CURDATE())
              AND {$notTest}
            ORDER BY q.sent_at
            LIMIT 300
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Per customer: last email in, last email out (office@ + Sam), last follow-up through
     * Sam, snooze, and the latest few messages for the card.
     * @return array<int, array{last_in: ?string, last_out: ?string, last_sam: ?string, snooze_until: ?string, thread: array}>
     */
    public function touches(array $contactIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        $out = [];
        foreach ($ids as $id) $out[$id] = ['last_in' => null, 'last_out' => null, 'last_sam' => null, 'snooze_until' => null, 'thread' => []];
        if (!$ids) return $out;
        $in = implode(',', $ids);   // ints only

        if ($this->hasTable('sales_messages')) {
            foreach ($this->db->query("
                SELECT contact_id, MAX(sent_at) AS last_out
                FROM sales_messages WHERE contact_id IN ({$in}) AND direction = 'outbound' GROUP BY contact_id
            ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['contact_id']]['last_out'] = $r['last_out'];
            }
            // "They wrote back" counts only mail that is Sam's (or a plain conversation): a billing
            // email, a site issue or a review routed to Penny / Otto / Mia is not a reply to a quote
            // (2026-10-08: VML's "EFT Direct deposit form" made Sam say they were waiting on him).
            $routed = true;
            try { $this->db->query('SELECT head, head_source FROM sales_messages LIMIT 0'); } catch (Throwable $e) { $routed = false; }
            // Joined mail (migration 1314): someone other than the contact wrote about their quote
            // (Monica at Macdonald PM about Linda's QUO-2026-0073) — the card names who wrote.
            $joined = $this->hasColumn('sales_messages', 'joined_by');
            $who = $joined ? ', from_name, joined_by' : '';
            foreach ($this->db->query("
                SELECT contact_id, sent_at, from_addr, subject, snippet" . ($routed ? ", head, head_source" : "") . $who . "
                FROM sales_messages WHERE contact_id IN ({$in}) AND direction = 'inbound'
            ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!self::countsAsSamReply($r)) continue;
                $cid = (int)$r['contact_id'];
                if ($out[$cid]['last_in'] === null || (string)$r['sent_at'] > (string)$out[$cid]['last_in']) {
                    $out[$cid]['last_in'] = $r['sent_at'];
                    $out[$cid]['last_in_by'] = !empty($r['joined_by']) ? self::senderLabel($r) : null;
                }
            }
            foreach ($this->db->query("
                SELECT contact_id, direction, channel, subject, snippet, sent_at" . ($joined ? ', from_addr, from_name, joined_by' : '') . "
                FROM sales_messages WHERE contact_id IN ({$in}) AND sent_at >= DATE_SUB(NOW(), INTERVAL 120 DAY)
                ORDER BY sent_at DESC, id DESC
            ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cid = (int)$r['contact_id'];
                if (count($out[$cid]['thread']) < 4) {
                    $out[$cid]['thread'][] = ['dir' => $r['direction'], 'channel' => $r['channel'], 'subject' => $r['subject'],
                                              'snippet' => $r['snippet'], 'at' => $r['sent_at'],
                                              'by' => ($r['direction'] === 'inbound' && !empty($r['joined_by'])) ? self::senderLabel($r) : null];
                }
            }
        }
        if ($this->ready()) {
            foreach ($this->db->query("
                SELECT contact_id,
                       MAX(CASE WHEN status IN ('sent', 'edited') THEN decided_at END) AS last_sam,
                       MAX(CASE WHEN status IN ('snoozed', 'skipped') THEN snooze_until END) AS snooze_until
                FROM sam_followups WHERE contact_id IN ({$in}) GROUP BY contact_id
            ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['contact_id']]['last_sam'] = $r['last_sam'];
                $out[(int)$r['contact_id']]['snooze_until'] = $r['snooze_until'];
            }
        }
        return $out;
    }

    /**
     * The latest Sam-relevant email in from each company and each mail organisation (120 days):
     * ['company' => [company_id => [at, by, contact_id, quote_ids]], 'org' => [org => [at, by, contact_id, quote_ids]]];
     * quote_ids lists the quotes the reply was matched to (empty when it names none).
     */
    public function orgReplies(): array
    {
        $out = ['company' => [], 'org' => []];
        if (!$this->hasTable('sales_messages')) return $out;
        require_once __DIR__ . '/SamAcceptanceService.php';
        $routed = true;
        try { $this->db->query('SELECT head, head_source FROM sales_messages LIMIT 0'); } catch (Throwable $e) { $routed = false; }
        $rows = $this->db->query("
            SELECT m.contact_id, m.message_key, m.sent_at, m.from_addr, m.from_name, m.subject, m.snippet" . ($routed ? ", m.head, m.head_source" : "") . ",
                   c.company_id
            FROM sales_messages m LEFT JOIN contacts c ON c.id = m.contact_id
            WHERE m.direction = 'inbound' AND m.sent_at >= DATE_SUB(NOW(), INTERVAL 120 DAY)
        ")->fetchAll(PDO::FETCH_ASSOC);
        // A reply the signed-quote matching has already tied to specific quotes (sam_acceptances, migration 1366) pauses
        // only those quotes, not every quote the company has (2026-10-22: Alena's nine signed quotes made Sam say Eva's
        // quote for a different building was answered, and he stopped chasing it).
        $tied = [];
        if ($this->hasTable('sam_acceptances')) {
            try {
                foreach ($this->db->query("SELECT message_key, quote_id FROM sam_acceptances")->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $tied[(string)$t['message_key']][] = (int)$t['quote_id'];
                }
            } catch (Throwable $e) { $tied = []; }
        }
        // No acceptance record? The files kept with the mail still name the quotes ("MOHAWK - MOWOLOGY - SNOW 0062 -
        // SIGNED.pdf"), so read them the way Sam's acceptance matching does.
        $files = []; $open = [];
        if ($this->hasTable('inbound_message_attachments')) {
            try {
                foreach ($this->db->query("SELECT message_key, id, filename FROM inbound_message_attachments")->fetchAll(PDO::FETCH_ASSOC) as $f) {
                    $files[(string)$f['message_key']][(int)$f['id']] = (string)$f['filename'];
                }
                // Every recent quote, whatever its status: the ones a reply names may already be accepted. Last 120 days only,
                // so QUO-2025-0060 and QUO-2026-0060 can never both be candidates for "0060".
                $open = $this->db->query("SELECT id, quote_number AS number FROM quotes WHERE created_at >= DATE_SUB(NOW(), INTERVAL 120 DAY)")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $files = []; $open = []; }
        }
        foreach ($rows as $r) {
            if (!self::countsAsSamReply($r)) continue;
            $key = (string)($r['message_key'] ?? '');
            $ids = $tied[$key] ?? [];
            if (!$ids && !empty($files[$key]) && $open) {
                $ids = self::quoteIdsNamedBy($open, (string)$r['subject'], (string)$r['snippet'], $files[$key]);
            }
            $hit = ['at' => (string)$r['sent_at'], 'by' => self::senderLabel($r), 'contact_id' => (int)$r['contact_id'],
                    'quote_ids' => $ids];
            if (!empty($r['company_id'])) {
                $k = (int)$r['company_id'];
                if (!isset($out['company'][$k]) || $hit['at'] > $out['company'][$k]['at']) $out['company'][$k] = $hit;
            }
            $email = preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string)$r['from_addr'], $em) ? $em[0] : '';
            $org = SamAcceptanceService::orgOf($email);
            if ($org !== null && (!isset($out['org'][$org]) || $hit['at'] > $out['org'][$org]['at'])) $out['org'][$org] = $hit;
        }
        return $out;
    }

    /**
     * The open quotes an email names — by a QUO number in the text, or a quote number in a file name — read the way
     * Sam's acceptance matching reads them. Empty when it names none. Pure.
     * @param array<int, array{id: int|string, number: string}> $open
     * @param array<int, string> $files attachment id => file name
     * @return int[]
     */
    public static function quoteIdsNamedBy(array $open, string $subject, string $text, array $files): array
    {
        if (!class_exists('SamAcceptanceService')) require_once __DIR__ . '/SamAcceptanceService.php';
        return array_values(array_map('intval', array_column(SamAcceptanceService::match($open, $subject, $text, $files), 'quote_id')));
    }

    /**
     * A contact's quotes count as answered when anyone at the quote's company, or writing from the contact's mail
     * organisation, wrote in later than the contact did — the card then names who wrote. Pure.
     */
    public static function mergeOrgReplies(array $touches, array $quotes, array $orgReplies): array
    {
        if (!class_exists('SamAcceptanceService')) require_once __DIR__ . '/SamAcceptanceService.php';
        foreach ($quotes as $q) {
            $cid = (int)($q['contact_id'] ?? 0);
            if (!$cid || !isset($touches[$cid])) continue;
            $hits = [];
            foreach ([(int)($q['company_id'] ?? 0), (int)($q['contact_company_id'] ?? 0)] as $co) {
                if ($co && isset($orgReplies['company'][$co])) $hits[] = $orgReplies['company'][$co];
            }
            $org = SamAcceptanceService::orgOf((string)($q['email'] ?? ''));
            if ($org !== null && isset($orgReplies['org'][$org])) $hits[] = $orgReplies['org'][$org];
            foreach ($hits as $h) {
                if ($h['contact_id'] === $cid) continue;   // their own mail is already counted
                // tied to specific quotes (signed-quote matching) and not this one: it is about someone else's quote
                if (!empty($h['quote_ids']) && !in_array((int)($q['id'] ?? 0), $h['quote_ids'], true)) continue;
                if ($touches[$cid]['last_in'] === null || $h['at'] > (string)$touches[$cid]['last_in']) {
                    $touches[$cid]['last_in'] = $h['at'];
                    $touches[$cid]['last_in_by'] = $h['by'];
                }
            }
        }
        return $touches;
    }

    /** "Monica Nicule" — or her address — for an email someone other than the contact wrote. */
    public static function senderLabel(array $r): string
    {
        $n = trim((string)($r['from_name'] ?? ''));
        return $n !== '' ? $n : trim((string)($r['from_addr'] ?? ''));
    }

    /** Learned wait per service: median days sent → accepted, from accepted quotes. */
    public function learnedWaits(): array
    {
        // Worked out once a day, not on every desk load (Codex review 2026-10-15).
        static $memo = null;
        if ($memo !== null) return $memo;
        try {
            $c = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'sam_learned_waits'");
            $c->execute();
            $v = json_decode((string)$c->fetchColumn(), true);
            if (is_array($v) && ($v['day'] ?? '') === date('Y-m-d') && is_array($v['waits'] ?? null)) return $memo = $v['waits'];
        } catch (Throwable $e) { /* compute */ }
        $memo = $this->computeWaits();
        try {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES ('sam_learned_waits', ?, 'Sam: median days sent → accepted per service, worked out daily')
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([json_encode(['day' => date('Y-m-d'), 'waits' => $memo])]);
        } catch (Throwable $e) { /* cache is a bonus */ }
        return $memo;
    }

    private function computeWaits(): array
    {
        try {
            $rows = $this->db->query("
                SELECT LOWER(TRIM(COALESCE(NULLIF(q.service_type, ''), 'other'))) AS service, DATEDIFF(q.accepted_at, q.sent_at) AS days
                FROM quotes q
                WHERE q.status = 'accepted' AND q.accepted_at IS NOT NULL AND q.sent_at IS NOT NULL AND q.accepted_at >= q.sent_at
                  AND q.sent_at >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return self::learnTiming($rows);
    }

    /** The carousel: one card per customer whose quotes need a nudge or an answer. */
    /**
     * A card for a contact even when nothing of theirs is waiting any more (2026-10-15: Tim's message to Alena was
     * refused because her quote had just been accepted). Only for 'c<contact id>' keys; the card carries no quotes,
     * so the email goes without quote links. Null when the contact is gone or has no address.
     */
    public function cardForKey(string $key): ?array
    {
        if (!preg_match('/^c(\d+)$/', $key, $m)) return null;
        $s = $this->db->prepare("SELECT c.id, c.first_name, c.last_name, c.email, COALESCE(NULLIF(c.mobile, ''), c.phone) AS phone,
                                        co.company_name
                                 FROM contacts c LEFT JOIN companies co ON co.id = c.company_id WHERE c.id = ?");
        $s->execute([(int)$m[1]]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        // Only someone we actually sent a quote to (Codex review 2026-10-15: a made-up key must not reach any contact).
        $q = $this->db->prepare("SELECT 1 FROM quotes q LEFT JOIN properties p ON p.id = q.property_id
                                 WHERE COALESCE(q.contact_id, p.site_contact_id) = ? AND q.sent_at IS NOT NULL LIMIT 1");
        $q->execute([(int)$c['id']]);
        if (!$q->fetchColumn()) return null;
        $first = trim((string)$c['first_name']);
        return ['key' => $key, 'kind' => 'stale', 'template' => 'reply', 'contact_id' => (int)$c['id'],
                'name' => trim($first . ' ' . (string)$c['last_name']) ?: ((string)$c['company_name'] ?: 'Customer'),
                'first_name' => $first, 'company' => (string)($c['company_name'] ?? ''), 'email' => (string)($c['email'] ?? ''),
                'phone' => (string)($c['phone'] ?? ''), 'amount' => 0.0, 'quotes' => [], 'gone' => true];
    }

    public function queue(): array
    {
        $quotes = $this->openQuotes();
        $touches = $this->touches(array_column($quotes, 'contact_id'));
        // Anyone at the client's company or organisation answering counts (2026-10-15: Alena at VML returned the
        // signed quotes Ron was sent; Sam kept chasing Ron).
        $touches = self::mergeOrgReplies($touches, $quotes, $this->orgReplies());
        return self::groupStale($quotes, $touches, date('Y-m-d H:i:s'), $this->staleDays(), $this->learnedWaits());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // New leads
    // ─────────────────────────────────────────────────────────────────────────

    public function leads(int $limit = 8): array
    {
        $rows = $this->db->query("
            SELECT qr.id, qr.service_types, qr.urgency, qr.status, qr.source, qr.project_description, qr.created_at,
                   c.id AS contact_id, c.first_name, c.last_name, c.email, COALESCE(NULLIF(c.mobile, ''), c.phone) AS phone,
                   p.address, p.city
            FROM quote_requests qr
            LEFT JOIN contacts c ON c.id = qr.contact_id
            LEFT JOIN properties p ON p.id = qr.property_id
            WHERE qr.status IN ('new', 'reviewing') AND qr.quote_id IS NULL
              AND qr.created_at >= DATE_SUB(NOW(), INTERVAL " . self::LEAD_MAX_AGE_DAYS . " DAY)
              AND CONCAT_WS(' ', c.first_name, c.last_name) NOT LIKE '%ZZTEST%'
            ORDER BY qr.created_at DESC
            LIMIT 60
        ")->fetchAll(PDO::FETCH_ASSOC);
        // A quote visit already booked (AppointmentService, 2026-10-15): Sam stops asking to book one.
        if ($rows) {
            try {
                $ids = array_map(fn($r) => (int)$r['id'], $rows);
                $s = $this->db->prepare("SELECT quote_request_id, appt_date, appt_time FROM appointments
                                         WHERE status = 'booked' AND quote_request_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
                $s->execute($ids);
                $visits = [];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $v) $visits[(int)$v['quote_request_id']] = $v;
                foreach ($rows as &$r) {
                    if (isset($visits[(int)$r['id']])) { $r['visit_date'] = $visits[(int)$r['id']]['appt_date']; $r['visit_time'] = $visits[(int)$r['id']]['appt_time']; }
                }
                unset($r);
            } catch (Throwable $e) { /* before migration 1369: no visits */ }
        }
        return array_slice(self::rankLeads($rows, $this->averageByService(), date('Y-m-d H:i:s'), $this->leadSignals($rows)), 0, max(1, $limit));
    }

    /**
     * What the CRM knows about each lead's contact (2026-11-03): last reply and last touch of ours
     * (sales_messages, all time; touch count over 30 days) and whether they have said yes to a quote before. One batch of
     * two queries; if either fails every lead is marked `available => false` so the score says
     * "no reply data" instead of quietly treating absence as zero.
     * @return array<int, array> contact_id => signals (see LeadScore)
     */
    public function leadSignals(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['contact_id'] ?? 0), $rows))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($ids as $id) $out[$id] = ['available' => true, 'inbound_at' => null, 'outbound_at' => null, 'outbound_count' => 0, 'repeat' => false];
        try {
            // Last touch either way is all-time (a lead we wrote to 40 days ago is not "untouched");
            // only the touch COUNT is windowed to 30 days.
            $s = $this->db->prepare("SELECT contact_id, direction, MAX(sent_at) AS last_at,
                                            SUM(CASE WHEN sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS n
                                     FROM sales_messages WHERE contact_id IN ($in)
                                     GROUP BY contact_id, direction");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $cid = (int)$m['contact_id'];
                if ($m['direction'] === 'inbound') $out[$cid]['inbound_at'] = (string)$m['last_at'];
                else { $out[$cid]['outbound_at'] = (string)$m['last_at']; $out[$cid]['outbound_count'] = (int)$m['n']; }
            }
            $s = $this->db->prepare("SELECT DISTINCT q.contact_id FROM quotes q WHERE q.status = 'accepted' AND q.contact_id IN ($in)");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $cid) $out[(int)$cid]['repeat'] = true;
        } catch (Throwable $e) {
            error_log('Sam lead signals: ' . $e->getMessage());
            foreach ($out as &$o) $o = ['available' => false];
            unset($o);
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Harvested 2026-11-03: quiet clients, renewals, hygiene, pipeline (see docs/plans/2026-11-03-penny-harvest.md "Next for Sam")
    // ─────────────────────────────────────────────────────────────────────────

    /** Season switch: ops_settings business_seasonal (default on) with season months 3–11 unless season_start/end_month say otherwise. */
    private function inSeason(?string $today = null): bool
    {
        $m = (int)date('n', strtotime($today ?: date('Y-m-d')));
        $start = 3; $end = 11; $seasonal = true;
        try {
            $st = $this->db->query("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ('business_seasonal','season_start_month','season_end_month')")->fetchAll(PDO::FETCH_KEY_PAIR);
            if (isset($st['business_seasonal'])) $seasonal = $st['business_seasonal'] !== '0';
            if (!empty($st['season_start_month'])) $start = (int)$st['season_start_month'];
            if (!empty($st['season_end_month'])) $end = (int)$st['season_end_month'];
        } catch (Throwable $e) { /* defaults */ }
        return !$seasonal || ($m >= $start && $m <= $end);
    }

    /**
     * Clients gone quiet against THEIR OWN rhythm (ClientRhythm): purchases = invoices issued in the last 24 months, keyed by
     * payer (company, else client, else contact); ranked by 12-month spend; each with a tier (ClientTier) and a first win-back
     * draft that does not pitch. Read-only.
     */
    public function quietClients(int $limit = 6, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        try {
            $rows = $this->db->query("
                SELECT i.issue_date, " . self::AMOUNT_SQL_INV . " AS amt, i.company_id, i.client_id, i.contact_id,
                       c.company_name, ct.first_name, ct.last_name, COALESCE(c.email, ct.email) AS email, p.city
                FROM invoices i LEFT JOIN companies c ON c.id = i.company_id LEFT JOIN contacts ct ON ct.id = i.contact_id
                LEFT JOIN properties p ON p.id = i.property_id
                WHERE i.status NOT IN ('draft','cancelled','void') AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                  AND CONCAT_WS(' ', ct.first_name, ct.last_name, c.company_name) NOT LIKE '%ZZTEST%'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Sam quiet clients: ' . $e->getMessage());
            return [];
        }
        $clients = []; $cities = [];
        $cut = date('Y-m-d', strtotime($today . ' -12 months'));
        foreach ($rows as $r) {
            $key = !empty($r['company_id']) ? 'company:' . (int)$r['company_id'] : (!empty($r['client_id']) ? 'client:' . (int)$r['client_id'] : 'contact:' . (int)$r['contact_id']);
            $c = &$clients[$key];
            $c['name'] = $c['name'] ?? ((string)($r['company_name'] ?: trim((string)$r['first_name'] . ' ' . (string)$r['last_name'])) ?: 'Client');
            $c['first_name'] = $c['first_name'] ?? (string)($r['company_name'] ? '' : $r['first_name']);
            $c['email'] = $c['email'] ?? (string)($r['email'] ?? '');
            $c['purchases'][] = (string)$r['issue_date'];
            if ((string)$r['issue_date'] >= $cut) $c['spend_12m'] = ($c['spend_12m'] ?? 0) + (float)$r['amt'];
            if (!empty($r['city'])) { $c['city'] = $c['city'] ?? (string)$r['city']; $cities[(string)$r['city']][$key] = true; }
            unset($c);
        }
        $quiet = array_slice(ClientRhythm::find($clients, $today, $this->inSeason($today)), 0, max(1, $limit));
        $open = [];
        try {
            foreach ($this->db->query("SELECT company_id, contact_id, COUNT(*) n FROM quotes WHERE status IN ('sent','viewed') GROUP BY company_id, contact_id")->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $open[!empty($q['company_id']) ? 'company:' . (int)$q['company_id'] : 'contact:' . (int)$q['contact_id']] = (int)$q['n'];
            }
        } catch (Throwable $e) { /* no open-work signal */ }
        $signoff = self::ownerName((array)(function_exists('getCurrentUser') ? (getCurrentUser() ?? []) : []));
        foreach ($quiet as &$q) {
            $city = $clients[$q['key']]['city'] ?? null;
            $q['tier'] = ClientTier::score([
                'recurring' => $q['n'] >= 4, 'on_route' => $city !== null && count($cities[$city] ?? []) >= 4, 'priced_service' => true,
                'open_work' => !empty($open[$q['key']]), 'last_activity' => $q['last'], 'contacts' => 1,
            ], $today);
            $q['draft'] = ClientRhythm::firstMessage($q, $signoff ?: 'Mowology');
            $q['url'] = strpos($q['key'], 'company:') === 0 ? '/crm/companies_appstack.php?id=' . (int)substr($q['key'], 8) : (strpos($q['key'], 'contact:') === 0 ? '/crm/contacts_appstack.php?id=' . (int)substr($q['key'], 8) : '/crm/clients_appstack.php');
        }
        unset($q);
        return $quiet;
    }

    /** Contracts renewing inside the radar window with a verdict (RenewalRadar). Read-only. */
    public function renewals(?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        if (!$this->hasTable('contracts')) return [];
        try {
            $auto = $this->hasColumn('contracts', 'auto_renew') ? 'c.auto_renew' : '0 AS auto_renew';
            $rows = $this->db->prepare("
                SELECT c.id, c.contract_number, c.title, c.renewal_date, c.billing_amount, c.billing_cycle, c.contact_id, c.property_id, $auto,
                       COALESCE(co.company_name, CONCAT(ct.first_name, ' ', ct.last_name)) AS name,
                       (SELECT MAX(m.sent_at) FROM sales_messages m WHERE m.contact_id = c.contact_id) AS last_activity
                FROM contracts c LEFT JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN properties p ON p.id = c.property_id LEFT JOIN companies co ON co.id = COALESCE(p.company_id, ct.company_id)
                WHERE c.status = 'active' AND c.renewal_date IS NOT NULL AND c.renewal_date BETWEEN DATE_SUB(?, INTERVAL 30 DAY) AND DATE_ADD(?, INTERVAL ? DAY)");
            $rows->execute([$today, $today, RenewalRadar::WINDOW_DAYS]);
            $per = ['monthly' => 12, 'weekly' => 52, 'biweekly' => 26, 'quarterly' => 4, 'annual' => 1, 'yearly' => 1, 'seasonal' => 1, 'one_time' => 1];
            $in = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $in[] = ['id' => (int)$r['id'], 'contract_number' => (string)$r['contract_number'], 'name' => (string)($r['name'] ?: $r['title']), 'renewal_date' => (string)$r['renewal_date'],
                         'annual_amount' => (float)$r['billing_amount'] * ($per[strtolower((string)$r['billing_cycle'])] ?? 1), 'auto_renew' => !empty($r['auto_renew']),
                         'last_activity' => $r['last_activity'] ? substr((string)$r['last_activity'], 0, 10) : null, 'open_complaint' => false, 'signer_changed' => false, 'expansion' => false];
            }
            $out = RenewalRadar::scan($in, $today);
            foreach ($out as &$o) $o['url'] = '/crm/contracts/view.php?id=' . $o['id'];
            unset($o);
            return $out;
        } catch (Throwable $e) {
            error_log('Sam renewals: ' . $e->getMessage());
            return [];
        }
    }

    /** Open quotes that are not fit to chase: no amount, or no service named (sales-plugin hygiene). */
    public function hygiene(): array
    {
        $out = [];
        foreach ($this->openQuotes() as $q) {
            $why = [];
            if ((float)($q['amount'] ?? 0) <= 0) $why[] = 'no amount';
            if (trim((string)($q['service_type'] ?? '')) === '') $why[] = 'no service named';
            if ($why) $out[] = ['id' => (int)$q['id'], 'quote_number' => (string)$q['quote_number'], 'name' => trim((string)($q['first_name'] ?? '') . ' ' . (string)($q['last_name'] ?? '')) ?: (string)($q['company_name'] ?? 'Client'), 'why' => implode(', ', $why), 'url' => '/crm/quotes/view.php?id=' . (int)$q['id']];
        }
        return $out;
    }

    /** Open quotes expiring within SamSignals::EXPIRY_DAYS with no reply on record. */
    public function expiringQuotes(?string $today = null): array
    {
        $t = strtotime($today ?: date('Y-m-d'));
        $out = [];
        foreach ($this->openQuotes() as $q) {
            if (empty($q['valid_until'])) continue;
            $left = (int)floor((strtotime((string)$q['valid_until']) - $t) / 86400);
            if ($left <= SamSignals::EXPIRY_DAYS) $out[] = ['quote_number' => (string)$q['quote_number'], 'name' => trim((string)($q['first_name'] ?? '') . ' ' . (string)($q['last_name'] ?? '')) ?: (string)($q['company_name'] ?? 'Client'), 'amount' => (float)$q['amount'], 'days_left' => $left];
        }
        return $out;
    }

    /**
     * Sam → Penny: what the open quotes would bring in if they land at Sam's win rate, by week (CashFlowForecastService shows it
     * as its own sentence, never inside the booked line). Expected date = sent + days-to-yes + terms + the usual lateness.
     */
    public function pipeline(int $termsDays, int $lateDays, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $st = $this->stats();
        $win = $st['win_rate'] !== null ? (float)$st['win_rate'] / 100 : null;
        $days = (int)round((float)($st['avg_days'] ?? 0));
        $open = $this->openQuotes();
        $total = 0.0; $weighted = 0.0; $byWeek = [];
        foreach ($open as $q) {
            $amt = (float)$q['amount']; $total += $amt;
            if ($win === null) continue;
            $exp = date('Y-m-d', strtotime(substr((string)$q['sent_at'], 0, 10) . ' +' . ($days + $termsDays + $lateDays) . ' days'));
            if ($exp < $today) $exp = $today;
            $wk = date('Y-m-d', strtotime('monday this week', strtotime($exp)));
            $byWeek[$wk] = ($byWeek[$wk] ?? 0) + $amt * $win;
            $weighted += $amt * $win;
        }
        ksort($byWeek);
        return ['count' => count($open), 'total' => round($total, 2), 'win_rate' => $st['win_rate'], 'weighted' => round($weighted, 2), 'by_week' => array_map(fn($v) => round($v, 2), $byWeek), 'basis' => $win === null ? 'no win rate yet (fewer than the minimum decided quotes)' : 'win rate over the last 12 months × open quotes, dated by the average days to a yes plus terms'];
    }

    /** Average accepted quote by service (last 24 months), plus an 'all' fallback. */
    public function averageByService(): array
    {
        $amt = self::AMOUNT_SQL;
        $out = [];
        try {
            foreach ($this->db->query("
                SELECT LOWER(TRIM(COALESCE(NULLIF(q.service_type, ''), 'other'))) AS service, AVG({$amt}) AS avg_amount
                FROM quotes q
                WHERE q.status = 'accepted' AND COALESCE(q.accepted_at, q.created_at) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                GROUP BY service
            ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['service']] = round((float)$r['avg_amount'], 2);
            }
            $out['all'] = round((float)$this->db->query("
                SELECT COALESCE(AVG({$amt}), 0) FROM quotes q
                WHERE q.status = 'accepted' AND COALESCE(q.accepted_at, q.created_at) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
            ")->fetchColumn(), 2);
        } catch (Throwable $e) { /* no averages → leads rank by tier and age */ }
        return $out;
    }

    /** Lead marked "not a lead" by Tim (spam, wrong number, out of area). */
    public function dismissLead(int $id): bool
    {
        $s = $this->db->prepare("UPDATE quote_requests SET status = 'declined', reviewed_at = NOW() WHERE id = ? AND status IN ('new', 'reviewing') AND quote_id IS NULL");
        $s->execute([$id]);
        return $s->rowCount() > 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Brief for Charlie (shared contract with every head)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Read-only and cheap (no AI, no writes) — Charlie calls it for every head.
     * item: key (stable, e.g. sam:lead:12), kind, value ($), since, text, url, priority 1|2|3
     * @return array{head: string, headline: string, items: array, count: int}
     */
    public function brief(string $ownerFirstName = ''): array
    {
        $cards = $this->queue();
        $leads = $this->leads(3);
        $items = [];
        // The digest (SamSignals): only threshold crossings; an empty one is the good news.
        $quiet = []; $renewals = []; $expiring = [];
        try { $quiet = $this->quietClients(4); $renewals = $this->renewals(); $expiring = $this->expiringQuotes(); } catch (Throwable $e) { error_log('Sam brief extras: ' . $e->getMessage()); }
        foreach ($renewals as $r) {
            if ($r['verdict'] === 'on_track') continue;
            $items[] = ['key' => 'sam:renewal:' . $r['id'], 'kind' => 'renewal', 'value' => $r['annual_amount'], 'since' => null, 'text' => 'Renewal ' . $r['text'], 'url' => $r['url'], 'priority' => $r['verdict'] === 'at_risk' ? 1 : 2];
        }
        foreach ($quiet as $q) {
            $items[] = ['key' => 'sam:quiet:' . $q['key'], 'kind' => 'quiet_client', 'value' => $q['spend_12m'], 'since' => $q['last'],
                        'text' => $q['name'] . ' has gone ' . $q['state'] . ' — ' . $q['reason'] . ' (worth $' . number_format($q['spend_12m'], 0) . ' a year; a no-pitch check-in is drafted)', 'url' => $q['url'], 'priority' => $q['state'] === 'gone' && $q['spend_12m'] >= SamSignals::GONE_WORTH ? 1 : 2];
        }
        foreach (array_slice($cards, 0, 3) as $c) {
            $items[] = [
                'key'      => 'sam:contact:' . $c['key'],
                'kind'     => $c['kind'] === 'replied' ? 'customer_replied' : 'quote_followup',
                'value'    => $c['amount'],
                'since'    => $c['kind'] === 'replied' ? $c['last_in'] : null,
                'text'     => $c['kind'] === 'replied'
                    ? $c['name'] . ' replied about ' . $c['label'] . ' — they\'re waiting on you'
                    : $c['name'] . ': ' . $c['label'] . ' (' . self::money($c['amount']) . ') — ' . $c['days'] . ' days, no reply',
                // Opens Sam's card on THIS customer, with their latest emails showing (sam-card.js reads ?sam=).
                'url'      => '/crm/dashboard_appstack.php?sam=' . rawurlencode((string)$c['key']) . '#mw-sam',
                'priority' => $c['kind'] === 'replied' ? 1 : 2,
            ];
        }
        foreach ($leads as $l) {
            $bucket = (string)($l['bucket'] ?? 'qualified');
            $why = array_slice((array)($l['reasons'] ?? []), 0, 2);
            $label = ['hot' => 'Hot lead', 'unclear' => 'Unclear lead', 'out_of_scope' => 'Not a fit'][$bucket] ?? 'New lead';
            $items[] = ['key' => 'sam:lead:' . $l['id'], 'kind' => 'new_lead', 'value' => $l['value'], 'since' => null,
                        'text' => $label . ': ' . $l['name'] . ' — ' . $l['next'] . ($bucket === 'hot' && $why ? ' (' . implode(', ', $why) . ')' : ''),
                        'url' => $l['url'], 'priority' => $bucket === 'hot' ? 1 : 3];
        }
        // Other replies about a quote nobody has answered (hand-sent emails, texts), kind quote_reply.
        // Replies that aren't about a quote are Yui's (client_reply) — never both.
        // Mia brings them in, Sam sells: anyone who answered one of her campaigns and has no quote yet.
        // Same keys as before (mia:campaign_reply:…) so earlier "Not now"s still hold.
        try {
            require_once APP_ROOT . '/Modules/Marketing/Services/MiaCampaignService.php';
            foreach ((new MiaCampaignService($this->db))->replyItems(new DateTimeImmutable('today')) as $c) {
                $c['text'] = 'From Mia\'s campaign: ' . $c['text'];
                array_unshift($items, $c);
            }
        } catch (Throwable $e) { error_log('Sam brief campaign leads: ' . $e->getMessage()); }

        $unclaimed = $this->unclaimed($cards);
        foreach (array_slice($unclaimed, 0, self::UNCLAIMED_BRIEF_MAX) as $u) {
            $items[] = array_intersect_key($u, array_flip(['key', 'kind', 'value', 'since', 'text', 'url', 'priority', 'yes']));
        }
        $replied = count(array_filter($cards, fn($c) => $c['kind'] === 'replied'));
        $amount = array_sum(array_column($cards, 'amount'));
        $headline = $unclaimed && $replied === 0
            ? count($unclaimed) . ' customer repl' . (count($unclaimed) === 1 ? 'y is' : 'ies are') . ' waiting on an answer'
            : ($replied > 0
            ? $replied . ' customer' . ($replied === 1 ? '' : 's') . ' wrote back and ' . ($replied === 1 ? 'is' : 'are') . ' waiting on you'
            : (count($cards) > 0 ? count($cards) . ' follow-up' . (count($cards) === 1 ? '' : 's') . ' due · ' . self::money($amount) . ' waiting'
                                 : 'No quotes need a nudge today'));
        $digest = SamSignals::digest($cards, [], $leads, $quiet, $expiring);
        if (!$digest['today'] && !$digest['week']) $headline .= ' ' . $digest['line'];
        return ['head' => 'sam', 'headline' => $headline, 'items' => $items, 'count' => count($cards) + count($leads) + count($unclaimed)];
    }

    /**
     * Replies about a quote that no net has caught (UnclaimedReplyService, 'quote' lane), leaving
     * out the people this queue already shows as "replied". The 'client' lane is Yui's inbox.
     * Never throws — a failure here costs only the extra list.
     * @param array|null $cards queue() if the caller already has it
     */
    public function unclaimed(?array $cards = null): array
    {
        try {
            require_once __DIR__ . '/UnclaimedReplyService.php';
            $cards = $cards ?? $this->queue();
            $replied = array_values(array_filter(array_map(fn($c) => $c['kind'] === 'replied' ? (int)$c['contact_id'] : 0, $cards)));
            return array_values(array_filter((new UnclaimedReplyService($this->db))->items($replied),
                fn($u) => ($u['lane'] ?? 'quote') === 'quote'));
        } catch (Throwable $e) {
            error_log('Sam unclaimed replies: ' . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Does this inbound message make a quote card "they replied, you haven't"?
     *   - routed to Sam: yes;
     *   - routed to Yui by the rules (a plain conversation — "any news?"): yes, as before;
     *     moved to Yui by Tim or the re-route: no — he said it isn't Sam's;
     *   - routed to Penny / Otto / Mia: no;
     *   - read before migration 1280 (no head): classified now (InboundTopicClassifier).
     */
    public static function countsAsSamReply(array $r): bool
    {
        $head = strtolower((string)($r['head'] ?? ''));
        if ($head === '') {
            if (!class_exists('InboundTopicClassifier')) require_once dirname(__DIR__, 2) . '/Comms/Services/InboundTopicClassifier.php';
            $head = InboundTopicClassifier::classify(['from' => (string)($r['from_addr'] ?? ''), 'subject' => (string)($r['subject'] ?? ''),
                                                      'snippet' => (string)($r['snippet'] ?? '')])['head'];
            return $head === 'sam' || $head === 'yui';
        }
        if ($head === 'sam') return true;
        if ($head === 'yui') return !in_array((string)($r['head_source'] ?? ''), ['moved', 'reroute'], true);
        return false;
    }

    /** The name Sam calls the owner: first name, else the first word of the full name. */
    public static function ownerName(array $user): string
    {
        $n = trim((string)($user['first_name'] ?? '')) ?: (string)strtok(trim((string)($user['full_name'] ?? $user['name'] ?? '')), ' ');
        $n = trim($n);
        return $n !== '' && strtoupper($n) === $n ? ucfirst(strtolower($n)) : $n;
    }

    public static function winRate(int $won, int $lost): ?int
    {
        return ($won + $lost) > 0 ? (int)round($won / ($won + $lost) * 100) : null;
    }

    public static function money(float $v): string
    {
        return '$' . number_format($v, $v >= 100 ? 0 : 2);
    }

    /** Median days to accept per service, clamped; only services with enough accepted quotes. */
    public static function learnTiming(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) $by[(string)$r['service']][] = max(0, (int)$r['days']);
        $out = [];
        foreach ($by as $svc => $days) {
            if (count($days) < self::LEARN_MIN_QUOTES) continue;
            sort($days);
            $n = count($days);
            $median = $n % 2 ? $days[intdiv($n, 2)] : ($days[$n / 2 - 1] + $days[$n / 2]) / 2;
            $out[$svc] = (int)max(self::LEARN_MIN_DAYS, min(self::LEARN_MAX_DAYS, (int)ceil($median)));
        }
        return $out;
    }

    /**
     * One card per customer whose quotes need Tim. Newest touches decide:
     *   replied  — the customer's last email is newer than every email we sent → answer them
     *   stale    — last touch older than the wait for the service, fewer than MAX_FOLLOWUPS
     * Snoozed/skipped customers stay hidden until their snooze ends.
     * @param array  $quotes   openQuotes() rows
     * @param array  $touches  touches() by contact id
     * @param string $now      'Y-m-d H:i:s'
     * @param int    $defaultWait days
     * @param array  $learned  service => days
     */
    public static function groupStale(array $quotes, array $touches, string $now, int $defaultWait, array $learned = []): array
    {
        $groups = [];
        foreach ($quotes as $q) {
            $key = $q['contact_id'] ? 'c' . (int)$q['contact_id'] : 'q' . (int)$q['id'];
            $groups[$key][] = $q;
        }
        $nowTs = strtotime($now);
        $today = date('Y-m-d', $nowTs);
        $cards = [];
        foreach ($groups as $key => $qs) {
            $cid = (int)($qs[0]['contact_id'] ?? 0);
            $t = $touches[$cid] ?? ['last_in' => null, 'last_out' => null, 'last_sam' => null, 'snooze_until' => null, 'thread' => []];
            if (!empty($t['snooze_until']) && $t['snooze_until'] > $today) continue;

            $lastSent = max(array_map(fn($q) => (string)$q['sent_at'], $qs));
            $lastFollow = max(array_map(fn($q) => (string)($q['follow_up_sent_at'] ?? ''), $qs));
            $followups = max(array_map(fn($q) => (int)$q['follow_up_count'], $qs));
            $lastOut = max($lastSent, $lastFollow, (string)($t['last_out'] ?? ''), (string)($t['last_sam'] ?? ''));
            $lastIn = (string)($t['last_in'] ?? '');

            $replied = $lastIn !== '' && $lastIn > $lastOut;
            $days = (int)floor(($nowTs - strtotime($lastOut)) / 86400);
            $waits = [];
            foreach ($qs as $q) {
                $svc = strtolower(trim((string)($q['service_type'] ?: 'other')));
                if (isset($learned[$svc])) $waits[] = (int)$learned[$svc];
            }
            $wait = $waits ? min($waits) : $defaultWait;   // learned for this kind of job, else the setting

            if (!$replied) {
                if ($days < $wait || $followups >= self::MAX_FOLLOWUPS) continue;
            }

            $amount = round(array_sum(array_map(fn($q) => (float)$q['amount'], $qs)), 2);
            $soonest = min(array_map(fn($q) => (string)($q['valid_until'] ?: '9999-12-31'), $qs));
            $viewed = (bool)array_filter($qs, fn($q) => !empty($q['viewed_at']) || !empty($q['last_viewed_at']) || !empty($q['email_opened_at']));
            $lastCall = $soonest !== '9999-12-31' && (strtotime($soonest) - $nowTs) / 86400 <= self::LAST_CALL_DAYS;

            $template = $replied ? 'reply'
                : (count($qs) > 1 ? 'multi'
                : ($lastCall ? 'last_call'
                : ($viewed ? 'viewed'
                : ($followups === 0 ? 'first_nudge' : 'second_nudge'))));

            $first = trim((string)($qs[0]['first_name'] ?? ''));
            $name = trim($first . ' ' . (string)($qs[0]['last_name'] ?? '')) ?: ((string)($qs[0]['company_name'] ?? '') ?: 'Customer');
            $cards[] = [
                'key'          => $key,
                'kind'         => $replied ? 'replied' : 'stale',
                'template'     => $template,
                'contact_id'   => $cid ?: null,
                'name'         => $name,
                'first_name'   => $first,
                'company'      => (string)($qs[0]['company_name'] ?? ''),
                'email'        => (string)($qs[0]['email'] ?? ''),
                'phone'        => (string)($qs[0]['phone'] ?? ''),
                'amount'       => $amount,
                'days'         => max(0, $days),
                'wait'         => $wait,
                'followups'    => $followups,
                'viewed'       => $viewed,
                'valid_until'  => $soonest === '9999-12-31' ? null : $soonest,
                'last_in'      => $lastIn ?: null,
                'last_in_by'   => $t['last_in_by'] ?? null,   // set when someone other than the contact wrote last
                'thread'       => $t['thread'] ?? [],
                'label'        => count($qs) === 1 ? (string)$qs[0]['quote_number'] : count($qs) . ' quotes',
                'quotes'       => array_map(fn($q) => [
                    'id'          => (int)$q['id'],
                    'number'      => (string)$q['quote_number'],
                    'title'       => (string)($q['title'] ?? ''),
                    'service'     => (string)($q['service_type'] ?? ''),
                    'address'     => (string)($q['address'] ?? ''),
                    'amount'      => round((float)$q['amount'], 2),
                    'sent_at'     => $q['sent_at'],
                    'valid_until' => $q['valid_until'],
                    'views'       => (int)($q['view_count'] ?? 0),
                    'state'       => QuoteService::colourState($q),
                ], $qs),
            ];
        }
        usort($cards, function ($a, $b) {
            if ($a['kind'] !== $b['kind']) return $a['kind'] === 'replied' ? -1 : 1;
            return $b['amount'] <=> $a['amount'];
        });
        return $cards;
    }

    /** jobFlow's classification tag inside project_description: [Classification: … tier:high …]. */
    public static function leadTag(string $description, string $key): ?string
    {
        return preg_match('/\b' . preg_quote($key, '/') . ':([a-z0-9_\-]+)/i', $description, $m) ? strtolower($m[1]) : null;
    }

    /**
     * Likely value = the average accepted quote for the services asked for (else the
     * overall average), times jobFlow's tier and the urgency, fading as the lead ages.
     * Beside it, since 2026-11-03, LeadScore's heat (0–100 from replies, fit, urgency and how
     * recently we wrote) and bucket (hot / qualified / unclear / out_of_scope). Leads are ordered
     * by heat, then value — unless the heat is flat, when they are ordered by what the CRM knows
     * and `ranked_by` says 'signal' so no number is shown.
     * @param array<int,array> $signals contact_id => LeadScore signals (leadSignals()); empty when unknown
     */
    public static function rankLeads(array $rows, array $avgByService, string $now, array $signals = []): array
    {
        $nowTs = strtotime($now);
        $offered = class_exists('EmailLeadService') ? EmailLeadService::serviceTags() : [];
        $out = [];
        foreach ($rows as $r) {
            $services = array_values(array_filter(array_map('trim', explode(',', strtolower((string)$r['service_types'])))));
            $value = 0.0;
            foreach ($services as $s) {
                foreach ($avgByService as $svc => $avg) {
                    if ($svc === 'all') continue;
                    $a = str_replace(['_', '-'], ' ', $s);
                    $b = str_replace(['_', '-'], ' ', (string)$svc);
                    if ($a !== '' && (strpos($b, $a) !== false || strpos($a, $b) !== false)) $value = max($value, (float)$avg);
                }
            }
            if ($value <= 0) $value = (float)($avgByService['all'] ?? 0);
            $tier = self::leadTag((string)$r['project_description'], 'tier');
            $mult = ['high' => 1.5, 'medium' => 1.0, 'low' => 0.7][$tier ?? ''] ?? 1.0;
            $urg = ['asap' => 1.3, 'soon' => 1.1][(string)$r['urgency']] ?? 1.0;
            $ageDays = max(0, ($nowTs - strtotime((string)$r['created_at'])) / 86400);
            $fresh = $ageDays <= 2 ? 1.0 : max(0.6, 1 - ($ageDays - 2) * 0.03);
            $score = round(max(1.0, $value) * $mult * $urg * $fresh, 2);
            $sig = $signals[(int)($r['contact_id'] ?? 0)] ?? [];
            $heat = LeadScore::score($r, $sig, $avgByService, $value, $offered, $now);

            $first = trim((string)($r['first_name'] ?? ''));
            $name = trim($first . ' ' . (string)($r['last_name'] ?? '')) ?: 'New lead';
            $what = $services ? implode(' & ', array_map(fn($s) => str_replace('_', ' ', $s), array_slice($services, 0, 2))) : 'a quote';
            $hot = $heat['bucket'] === 'hot' || ($heat['bucket'] !== 'out_of_scope' && ($tier === 'high' || $r['urgency'] === 'asap'));
            $phone = trim((string)($r['phone'] ?? ''));
            $visitAt = !empty($r['visit_date']) ? strtotime($r['visit_date'] . ' ' . ($r['visit_time'] ?? '00:00')) : false;
            if ($visitAt) {
                $next = 'Quote visit booked ' . date('D M j, g:i a', $visitAt) . ' — measure there, then send the quote';
            } elseif ($ageDays > 3) {
                $next = 'Going cold (' . (int)floor($ageDays) . ' days) — ' . ($phone !== '' ? 'call ' . ($first ?: 'them') . ' today' : 'email ' . ($first ?: 'them') . ' today');
            } elseif ($hot && $phone !== '') {
                $next = 'Call ' . ($first ?: 'them') . ' today — ' . ($tier === 'high' ? 'high-value ' : '') . $what . ($r['urgency'] === 'asap' ? ', wants it soon' : '');
            } elseif ($phone !== '') {
                $next = 'Call ' . ($first ?: 'them') . ' to book a look at ' . $what . ', then quote';
            } else {
                $next = 'Email ' . ($first ?: 'them') . ' to book a site visit for ' . $what;
            }
            if ($heat['bucket'] === 'unclear' && !$visitAt) {
                $missing = !$services ? 'what work they need' : ((string)($r['address'] ?? '') === '' && (string)($r['city'] ?? '') === '' ? 'where the property is' : 'when they want it done');
                $next = 'Ask one thing — ' . $missing . ' (reply drafted)';
            } elseif ($heat['bucket'] === 'out_of_scope') {
                $next = 'Not work we do — honest reply drafted, or mark not a lead';
            }
            $out[] = [
                'id'        => (int)$r['id'],
                'name'      => $name,
                'services'  => $what,
                'address'   => trim((string)($r['address'] ?? '') . ((string)($r['city'] ?? '') !== '' ? ', ' . $r['city'] : '')),
                'phone'     => $phone,
                'email'     => (string)($r['email'] ?? ''),
                'source'    => (string)($r['source'] ?? ''),
                'tier'      => $tier,
                'urgency'   => (string)$r['urgency'],
                'age_days'  => (int)floor($ageDays),
                'value'     => round($value, 2),
                'score'     => $score,
                'hot'       => $hot,
                'heat'      => $heat['heat'],
                'bucket'    => $heat['bucket'],
                'respond_by' => ['hot' => 'today', 'qualified' => 'within 48 hours', 'unclear' => 'this week', 'out_of_scope' => 'this week'][$heat['bucket']] ?? 'this week',   // sales-plugin SLAs
                'reasons'   => $heat['reasons'],
                'ask'       => $heat['ask'],
                'untouched' => $heat['untouched'],
                'created_at' => (string)$r['created_at'],
                'next'      => $next,
                'url'       => '/crm/quotes/create.php?quote_request_id=' . (int)$r['id'],
            ];
        }
        $ordered = LeadScore::order($out);
        return array_map(function ($l) use ($ordered) { $l['ranked_by'] = $ordered['ranked_by']; return $l; }, $ordered['items']);
    }
}
