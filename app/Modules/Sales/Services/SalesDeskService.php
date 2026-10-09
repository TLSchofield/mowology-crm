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
                   co.company_name AS company_name, p.address
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
                SELECT contact_id,
                       MAX(CASE WHEN direction = 'inbound' THEN sent_at END) AS last_in,
                       MAX(CASE WHEN direction = 'outbound' THEN sent_at END) AS last_out
                FROM sales_messages WHERE contact_id IN ({$in}) GROUP BY contact_id
            ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['contact_id']]['last_in'] = $r['last_in'];
                $out[(int)$r['contact_id']]['last_out'] = $r['last_out'];
            }
            foreach ($this->db->query("
                SELECT contact_id, direction, channel, subject, snippet, sent_at
                FROM sales_messages WHERE contact_id IN ({$in}) AND sent_at >= DATE_SUB(NOW(), INTERVAL 120 DAY)
                ORDER BY sent_at DESC, id DESC
            ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cid = (int)$r['contact_id'];
                if (count($out[$cid]['thread']) < 4) {
                    $out[$cid]['thread'][] = ['dir' => $r['direction'], 'channel' => $r['channel'], 'subject' => $r['subject'],
                                              'snippet' => $r['snippet'], 'at' => $r['sent_at']];
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

    /** Learned wait per service: median days sent → accepted, from accepted quotes. */
    public function learnedWaits(): array
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
    public function queue(): array
    {
        $quotes = $this->openQuotes();
        $touches = $this->touches(array_column($quotes, 'contact_id'));
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
        return array_slice(self::rankLeads($rows, $this->averageByService(), date('Y-m-d H:i:s')), 0, max(1, $limit));
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
        foreach (array_slice($cards, 0, 3) as $c) {
            $items[] = [
                'key'      => 'sam:contact:' . $c['key'],
                'kind'     => $c['kind'] === 'replied' ? 'customer_replied' : 'quote_followup',
                'value'    => $c['amount'],
                'since'    => $c['kind'] === 'replied' ? $c['last_in'] : null,
                'text'     => $c['kind'] === 'replied'
                    ? $c['name'] . ' replied about ' . $c['label'] . ' — they\'re waiting on you'
                    : $c['name'] . ': ' . $c['label'] . ' (' . self::money($c['amount']) . ') — ' . $c['days'] . ' days, no reply',
                'url'      => '/crm/dashboard_appstack.php#mw-sam',
                'priority' => $c['kind'] === 'replied' ? 1 : 2,
            ];
        }
        foreach ($leads as $l) {
            $items[] = ['key' => 'sam:lead:' . $l['id'], 'kind' => 'new_lead', 'value' => $l['value'], 'since' => null,
                        'text' => 'New lead: ' . $l['name'] . ' — ' . $l['next'], 'url' => $l['url'], 'priority' => $l['hot'] ? 1 : 3];
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
     */
    public static function rankLeads(array $rows, array $avgByService, string $now): array
    {
        $nowTs = strtotime($now);
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

            $first = trim((string)($r['first_name'] ?? ''));
            $name = trim($first . ' ' . (string)($r['last_name'] ?? '')) ?: 'New lead';
            $what = $services ? implode(' & ', array_map(fn($s) => str_replace('_', ' ', $s), array_slice($services, 0, 2))) : 'a quote';
            $hot = ($tier === 'high' || $r['urgency'] === 'asap');
            $phone = trim((string)($r['phone'] ?? ''));
            if ($ageDays > 3) {
                $next = 'Going cold (' . (int)floor($ageDays) . ' days) — ' . ($phone !== '' ? 'call ' . ($first ?: 'them') . ' today' : 'email ' . ($first ?: 'them') . ' today');
            } elseif ($hot && $phone !== '') {
                $next = 'Call ' . ($first ?: 'them') . ' today — ' . ($tier === 'high' ? 'high-value ' : '') . $what . ($r['urgency'] === 'asap' ? ', wants it soon' : '');
            } elseif ($phone !== '') {
                $next = 'Call ' . ($first ?: 'them') . ' to book a look at ' . $what . ', then quote';
            } else {
                $next = 'Email ' . ($first ?: 'them') . ' to book a site visit for ' . $what;
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
                'next'      => $next,
                'url'       => '/crm/quotes/create.php?quote_request_id=' . (int)$r['id'],
            ];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }
}
