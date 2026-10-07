<?php
/**
 * MiaCampaignService — campaigns Mia proposes and Tim approves with one tap.
 *
 * Autonomy: Mia only ever PROPOSES a campaign — the list size, how many passed the consent
 * ledger, the words and the photo. Nothing goes to anyone until Tim taps Approve; only then
 * is a marketing_campaigns row created (status 'sending', segment 'custom_list') with one
 * campaign_sends row per person who passed the ledger at that moment. The existing campaign
 * sender cron sends them in batches with the branded footer, an unsubscribe link per person
 * and its own consent re-check. There is no other path that creates campaign sends here.
 *
 * Campaigns (since migration 1195): the calendar in MiaCalendar / the mia_calendar table — a
 * full Lower Mainland year (spring early-bird, hedges before nesting, spring lawns, moss, mulch,
 * snow & salt, fall lawns, leaves, next-year contracts). Each is proposed ~7 days before its
 * send window, held while its conditions fail (lawn seeding in Stage 2/3 watering
 * restrictions), sent to its audience at each person's best time inside the window
 * (SendTimeService), and followed by its reminder / last call to non-responders only
 * (MiaSequenceService) — Tim's one approval covers the whole sequence, shown on the proposal.
 * Before migration 1195 runs, Mia behaves exactly as v1 below.
 *
 * Campaigns (v1 — one):
 *   post_drought_<year> — Metro Vancouver's lawn watering restrictions lift on October 15;
 *       the second half of October is the window for aeration, overseeding and
 *       top-dressing. Audience: current clients (an active plan or a completed job in the
 *       last 12 months) plus neighbours of current clients (other customers on file whose
 *       property is within 400 m of a client's). Proposed Oct 1–31. The words never tell
 *       anyone to water during the restrictions.
 *
 * Never: a discount, a price Tim hasn't written, a client's address or face (the photo is a
 * published before/after pair from the public portfolio, shown without any address), a
 * send to anyone without a consent record.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/MiaFinder.php';
require_once __DIR__ . '/MiaWording.php';
require_once __DIR__ . '/MiaCalendar.php';
require_once __DIR__ . '/WaterRestrictionService.php';
require_once __DIR__ . '/SendTimeService.php';
require_once __DIR__ . '/MiaSequenceService.php';
require_once dirname(__DIR__, 2) . '/Consent/Services/ConsentLedgerService.php';

class MiaCampaignService
{
    public const NEIGHBOUR_METRES = 400;
    public const SITE = 'https://mowology.ca';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The campaigns Mia knows, and when each is worth proposing. */
    public static function catalogue(int $year): array
    {
        return [
            'post_drought_' . $year => [
                'name'  => "Post-drought lawn recovery ($year)",
                'from'  => "$year-10-01",
                'to'    => "$year-10-31",
                'why'   => 'The lawn watering restrictions lift on October 15. With the fall rain coming, the second half of October is the window for aeration, overseeding and top-dressing.',
                'photo' => '/aerat|overseed|top.?dress|lawn|turf|grass/i',
                // Tim's own words, approved 2026-10-06 (copy-edited with the copywriting skills):
                // the leaf warning shows knowledge and steers treed lawns to aerate now / seed in
                // spring; real prices from his pricing rules; the P.S. is the spring pre-book.
                'subject' => MiaCalendar::LEGACY_SUBJECT,
                // How Mia names it in her brief: "Colleen replied to the fall lawn email."
                'label'   => 'the fall lawn email',
                'body'    => MiaCalendar::LEGACY_BODY,
            ],
        ];
    }

    /**
     * Propose whatever is due and not yet proposed. Returns how many were added.
     * With migration 1195: the calendar (MiaCalendar), ~7 days before each send window.
     * Before it: the single post-drought campaign, as in v1.
     */
    public function propose(DateTimeImmutable $today): int
    {
        return $this->hubReady() ? $this->proposeFromCalendar($today) : $this->proposeLegacy($today);
    }

    /** v1: the one hard-coded campaign (kept so nothing changes before migration 1195 runs). */
    private function proposeLegacy(DateTimeImmutable $today): int
    {
        $added = 0;
        $date = $today->format('Y-m-d');
        $season = array_filter(self::catalogue((int)$today->format('Y')), fn($c) => $date >= $c['from'] && $date <= $c['to']);
        // A proposal Tim never decided lapses when its season ends.
        $live = $season ? implode(',', array_map(fn($k) => $this->db->quote($k), array_keys($season))) : "''";
        $this->db->exec("UPDATE mia_campaigns SET status = 'expired' WHERE status = 'proposed' AND campaign_key NOT IN ($live)");
        foreach ($season as $key => $c) {
            $seen = $this->db->prepare("SELECT 1 FROM mia_campaigns WHERE campaign_key = ?");
            $seen->execute([$key]);
            if ($seen->fetchColumn()) continue; // one proposal per campaign, whatever Tim decided
            $a = $this->audience($today);
            $photo = $this->photo($c['photo']);
            try {
                $this->db->prepare("
                    INSERT INTO mia_campaigns (campaign_key, name, why, subject, body_text, photo_json, audience_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$key, $c['name'], $c['why'], $c['subject'], $c['body'], $photo ? json_encode($photo) : null, json_encode($a['counts'])]);
                $added++;
            } catch (PDOException $e) { /* proposed by another request a moment ago (unique key) */ }
        }
        return $added;
    }

    /** The proposal (or approved campaign) the card shows, with fresh counts. */
    public function current(DateTimeImmutable $today): ?array
    {
        // A proposal waiting for Tim comes first; otherwise the latest approved one, with its results.
        $r = $this->db->query("SELECT * FROM mia_campaigns WHERE status = 'proposed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)
            ?: $this->db->query("SELECT * FROM mia_campaigns WHERE status = 'approved' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $out = [
            'id' => (int)$r['id'], 'key' => $r['campaign_key'], 'name' => $r['name'], 'why' => $r['why'],
            'subject' => $r['subject'], 'body' => $r['body_text'], 'status' => $r['status'],
            'photo' => $r['photo_json'] ? json_decode($r['photo_json'], true) : null,
            'counts' => json_decode((string)$r['audience_json'], true) ?: [],
        ];
        $hub = $this->hubReady() && !empty($r['calendar_key']);
        if ($hub) $out += $this->hubDetails($r, $today);
        if ($r['status'] === 'proposed') {
            $out['counts'] = $hub ? $this->audienceFor($this->calendarEntry((string)$r['calendar_key']), $today)['counts'] : $this->audience($today)['counts'];
        } elseif ($r['marketing_campaign_id']) {
            try {
                $p = $this->db->prepare("SELECT status, COUNT(*) n FROM campaign_sends WHERE campaign_id = ? GROUP BY status");
                $p->execute([(int)$r['marketing_campaign_id']]);
                $out['progress'] = array_map('intval', array_column($p->fetchAll(PDO::FETCH_ASSOC), 'n', 'status'));
            } catch (Throwable $e) {}
            $out['recipients'] = (int)$r['recipients'];
            $out['results'] = $this->results((int)$r['marketing_campaign_id']);
        }
        return $out;
    }

    /** Within this many days of each person's email, what they did counts for the campaign. */
    public const RESULT_DAYS = 30;

    /**
     * What the campaign earned — bookings first (Tim's rule: judge campaigns by bookings, not
     * opens). Same definitions as Mia's one-to-one outcomes: booked = a quote accepted or a plan
     * started; quoted = a quote created; replied = an email in (Sam's office@ log). Spring holds
     * are "Booked for spring" answers. Opens are counted but are rough (Apple Mail opens mail itself).
     */
    public function results(int $campaignId): array
    {
        try {
            $s = $this->db->prepare("SELECT contact_id, status, sent_at, opened_at FROM campaign_sends WHERE campaign_id = ?");
            $s->execute([$campaignId]);
            $recipients = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $sent = array_filter($recipients, fn($r) => $r['status'] === 'sent' && $r['sent_at']);
        if (!$sent) return self::tally($recipients, [], [], [], []);
        $ids = implode(',', array_map(fn($r) => (int)$r['contact_id'], $sent));   // ints only
        $from = min(array_column($sent, 'sent_at'));
        $quotes = $plans = $replies = $spring = [];
        try {
            $q = $this->db->prepare("
                SELECT q.id, q.status, q.created_at, COALESCE(NULLIF(q.total_amount, 0), q.amount, 0) AS amount,
                       COALESCE(q.contact_id, p.site_contact_id) AS contact_id
                FROM quotes q LEFT JOIN properties p ON p.id = q.property_id
                WHERE q.created_at >= ? AND (q.contact_id IN ($ids) OR p.site_contact_id IN ($ids))");
            $q->execute([$from]);
            $quotes = $q->fetchAll(PDO::FETCH_ASSOC);
            $p = $this->db->prepare("
                SELECT jp.id, jp.created_at, p.site_contact_id AS contact_id
                FROM job_plans jp JOIN properties p ON p.id = jp.property_id
                WHERE jp.created_at >= ? AND p.site_contact_id IN ($ids)");
            $p->execute([$from]);
            $plans = $p->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('Mia campaign results: ' . $e->getMessage()); }
        try {
            $m = $this->db->prepare("SELECT contact_id, sent_at FROM sales_messages WHERE direction = 'inbound' AND sent_at >= ? AND contact_id IN ($ids)");
            $m->execute([$from]);
            $replies = $m->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* no office@ log yet */ }
        try {
            foreach ($this->db->query("SELECT subject_key FROM mia_questions WHERE kind = 'spring_prebook' AND answer = 'booked'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
                if (preg_match('/^mia:spring:\d+:(\d+)$/', (string)$k, $mm)) $spring[] = (int)$mm[1];
            }
        } catch (Throwable $e) { /* none yet */ }
        return self::tally($recipients, $quotes, $plans, $replies, $spring);
    }

    /** Replies to campaigns approved this long ago or less are still worth a brief item. */
    public const REPLY_LOOKBACK_DAYS = 90;

    /**
     * Each campaign reply as one of Mia's brief items (for Charlie). Read-only and cheap:
     * a handful of queries over the recent approved campaigns. A reply is an inbound
     * sales_messages row from a recipient within RESULT_DAYS of their send — the same
     * definition results() counts. The item goes once a quote exists for that contact
     * (quotes.contact_id or the property's site contact) created after the reply.
     * @return array<int, array> brief items (contract in CharlieBriefService)
     */
    public function replyItems(DateTimeImmutable $today): array
    {
        try {
            $s = $this->db->prepare("SELECT id, campaign_key, name, marketing_campaign_id FROM mia_campaigns
                                     WHERE status = 'approved' AND marketing_campaign_id IS NOT NULL AND decided_at >= ?
                                     ORDER BY id");
            $s->execute([$today->modify('-' . self::REPLY_LOOKBACK_DAYS . ' days')->format('Y-m-d')]);
            $campaigns = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return []; // no campaigns table yet
        }
        $out = [];
        foreach ($campaigns as $c) {
            try {
                $out = array_merge($out, $this->replyItemsFor($c));
            } catch (Throwable $e) {
                error_log('Mia campaign replies: ' . $e->getMessage());
            }
        }
        return $out;
    }

    private function replyItemsFor(array $c): array
    {
        $s = $this->db->prepare("SELECT contact_id, status, sent_at FROM campaign_sends WHERE campaign_id = ? AND status = 'sent' AND sent_at IS NOT NULL");
        $s->execute([(int)$c['marketing_campaign_id']]);
        $recipients = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$recipients) return [];
        $ids = implode(',', array_map(fn($r) => (int)$r['contact_id'], $recipients)); // ints only
        $from = min(array_column($recipients, 'sent_at'));

        try {
            $m = $this->db->prepare("SELECT contact_id, sent_at, snippet FROM sales_messages
                                     WHERE direction = 'inbound' AND sent_at >= ? AND contact_id IN ($ids) ORDER BY sent_at");
            $m->execute([$from]);
            $replies = $m->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return []; // no office@ log yet
        }
        if (!$replies) return [];
        $replied = implode(',', array_unique(array_map(fn($r) => (int)$r['contact_id'], $replies)));

        $q = $this->db->prepare("
            SELECT q.contact_id, p.site_contact_id, q.created_at
            FROM quotes q LEFT JOIN properties p ON p.id = q.property_id
            WHERE q.created_at >= ? AND (q.contact_id IN ($replied) OR p.site_contact_id IN ($replied))");
        $q->execute([$from]);
        $quotes = $q->fetchAll(PDO::FETCH_ASSOC);

        $people = [];
        foreach ($this->db->query("SELECT id, first_name FROM contacts WHERE id IN ($replied)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $people[(int)$r['id']] = ['first_name' => (string)$r['first_name'], 'property_id' => null];
        }
        // Their live property: billing contact first, else the one whose quotes go to them (strata reps);
        // archived duplicates never.
        $props = "SELECT site_contact_id AS cid, MIN(id) AS pid FROM properties WHERE site_contact_id IN ($replied) AND status <> 'archived' GROUP BY site_contact_id";
        try {
            $this->db->query("SELECT quote_contact_id FROM properties LIMIT 0");
            $props .= " UNION ALL SELECT quote_contact_id AS cid, MIN(id) AS pid FROM properties WHERE quote_contact_id IN ($replied) AND status <> 'archived' GROUP BY quote_contact_id";
        } catch (Throwable $e) { /* before migration 1186 */ }
        foreach ($this->db->query($props)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (int)$r['cid'];
            if (isset($people[$cid]) && empty($people[$cid]['property_id'])) $people[$cid]['property_id'] = (int)$r['pid'];
        }
        $label = null;
        foreach (self::catalogue((int)substr((string)$c['campaign_key'], -4)) as $k => $cat) {
            if ($k === $c['campaign_key']) $label = $cat['label'] ?? null;
        }
        $cal = MiaCalendar::load($this->db)[(string)preg_replace('/_\d{4}$/', '', (string)$c['campaign_key'])] ?? null;
        if ($cal && $cal['label'] !== '') $label = $cal['label'];
        return self::replyBriefItems(['id' => (int)$c['id'], 'name' => (string)$c['name'], 'label' => $label],
            $recipients, $replies, $quotes, $people);
    }

    /**
     * Pure: one brief item per person who replied to the campaign and has no quote since.
     * @param array $campaign   ['id' => mia_campaigns.id, 'name' => string, 'label' => ?string ("the fall lawn email")]
     * @param array $recipients [['contact_id', 'status', 'sent_at']]
     * @param array $replies    [['contact_id', 'sent_at', 'snippet']] inbound messages
     * @param array $quotes     [['contact_id', 'site_contact_id', 'created_at']]
     * @param array $people     contact_id => ['first_name' => string, 'property_id' => ?int]
     */
    public static function replyBriefItems(array $campaign, array $recipients, array $replies, array $quotes, array $people, int $days = self::RESULT_DAYS): array
    {
        $win = [];
        foreach ($recipients as $r) {
            if (($r['status'] ?? '') !== 'sent' || empty($r['sent_at'])) continue;
            $t = strtotime((string)$r['sent_at']);
            if ($t !== false) $win[(int)$r['contact_id']] = [$t, $t + $days * 86400];
        }
        // First reply in each person's window; "spring" anywhere in any of their replies.
        $first = $spring = [];
        foreach ($replies as $m) {
            $cid = (int)$m['contact_id'];
            $t = strtotime((string)$m['sent_at']);
            if (!isset($win[$cid]) || $t === false || $t < $win[$cid][0] || $t > $win[$cid][1]) continue;
            if (!isset($first[$cid]) || $t < $first[$cid]) $first[$cid] = $t;
            if (preg_match('/\bspring\b/i', (string)($m['snippet'] ?? ''))) $spring[$cid] = true;
        }
        // A quote for them made after they replied means the reply has been dealt with.
        foreach ($quotes as $q) {
            $t = strtotime((string)$q['created_at']);
            if ($t === false) continue;
            foreach ([(int)($q['contact_id'] ?? 0), (int)($q['site_contact_id'] ?? 0)] as $cid) {
                if ($cid && isset($first[$cid]) && $t >= $first[$cid]) unset($first[$cid]);
            }
        }
        asort($first);
        $label = trim((string)($campaign['label'] ?? '')) ?: 'the ' . trim(preg_replace('/\s*\(\d{4}\)\s*$/', '', (string)($campaign['name'] ?? 'campaign'))) . ' email';
        $items = [];
        foreach ($first as $cid => $t) {
            $p = $people[$cid] ?? [];
            $name = self::firstNameOnly((string)($p['first_name'] ?? ''));
            $text = ($name !== '' ? $name : 'Someone') . ' replied to ' . $label
                . (isset($spring[$cid]) ? ' and wants it done in spring' : '') . '. Start the quote.';
            $pid = (int)($p['property_id'] ?? 0);
            $items[] = [
                'key'      => 'mia:campaign_reply:' . (int)$campaign['id'] . ':' . $cid,
                'kind'     => 'campaign_reply',
                'text'     => $text,
                'since'    => date('Y-m-d', $t),
                // The property page: measurements, zones and Otto's gaps first, then quote from there.
                'url'      => $pid
                    ? '/crm/properties/view.php?id=' . $pid
                    : '/crm/clients_appstack.php?action=view_contact&id=' . $cid,
                'priority' => 1, // a yes is money
            ];
        }
        return $items;
    }

    /** A first name fit to show: never an email address, never more than one word. */
    public static function firstNameOnly(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strpos($name, '@') !== false) return '';
        $name = (string)strtok($name, " \t");
        return ucfirst(mb_substr($name, 0, 40));
    }

    /** Pure: one line per person, counted once each in their best outcome's column. */
    public static function tally(array $recipients, array $quotes, array $plans, array $replies, array $springContacts, int $days = self::RESULT_DAYS): array
    {
        $win = [];
        foreach ($recipients as $r) {
            if ($r['status'] !== 'sent' || !$r['sent_at']) continue;
            $t = strtotime((string)$r['sent_at']);
            $win[(int)$r['contact_id']] = [$t, $t + $days * 86400];
        }
        $in = function (int $cid, string $at) use ($win): bool {
            if (!isset($win[$cid])) return false;
            $t = strtotime($at);
            return $t !== false && $t >= $win[$cid][0] && $t <= $win[$cid][1];
        };
        $booked = $quoted = $replied = [];
        $amount = 0.0;
        foreach ($quotes as $q) {
            $cid = (int)$q['contact_id'];
            if (!$in($cid, (string)$q['created_at'])) continue;
            if (in_array($q['status'], ['accepted', 'approved_verbal'], true)) { $booked[$cid] = true; $amount += (float)$q['amount']; }
            else $quoted[$cid] = true;
        }
        foreach ($plans as $p) if ($in((int)$p['contact_id'], (string)$p['created_at'])) $booked[(int)$p['contact_id']] = true;
        foreach ($replies as $m) if ($in((int)$m['contact_id'], (string)$m['sent_at'])) $replied[(int)$m['contact_id']] = true;
        $quoted = array_diff_key($quoted, $booked);
        $spring = array_intersect(array_unique(array_map('intval', $springContacts)), array_keys($win));
        $opened = count(array_filter($recipients, fn($r) => $r['status'] === 'sent' && !empty($r['opened_at'])));
        return [
            'sent'          => count($win),
            'booked'        => count($booked),
            'booked_amount' => round($amount, 2),
            'quoted'        => count($quoted),
            'spring_holds'  => count($spring),
            'replied'       => count($replied),
            'opened'        => $opened,
            'days'          => $days,
        ];
    }

    /**
     * Who it would go to right now.
     * @return array{ids: int[], counts: array{clients: int, neighbours: int, considered: int, consented: int}}
     */
    public function audience(DateTimeImmutable $today): array
    {
        $since = $today->modify('-12 months')->format('Y-m-d');
        $clients = [];
        $clientSpots = [];
        $s = $this->db->prepare("
            SELECT DISTINCT p.site_contact_id AS cid, p.latitude AS lat, p.longitude AS lng
            FROM properties p
            JOIN job_plans jp ON jp.property_id = p.id
            WHERE p.site_contact_id IS NOT NULL
              AND (jp.status = 'active' OR EXISTS (
                    SELECT 1 FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'completed' AND jv.scheduled_date >= ?))
        ");
        $s->execute([$since]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $clients[(int)$r['cid']] = true;
            if ($r['lat'] !== null && $r['lng'] !== null && (float)$r['lat'] != 0.0) $clientSpots[] = [(float)$r['lat'], (float)$r['lng']];
        }
        $neighbours = [];
        if ($clientSpots) {
            $o = $this->db->query("SELECT site_contact_id AS cid, latitude AS lat, longitude AS lng FROM properties
                                   WHERE site_contact_id IS NOT NULL AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude <> 0");
            foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cid = (int)$r['cid'];
                if (isset($clients[$cid]) || isset($neighbours[$cid])) continue;
                if (self::near((float)$r['lat'], (float)$r['lng'], $clientSpots, self::NEIGHBOUR_METRES)) $neighbours[$cid] = true;
            }
        }
        $ids = array_keys($clients + $neighbours);
        $ids = $this->leaveOut($ids, $today);
        $consent = (new ConsentLedgerService($this->db))->bulk($ids, 'email', $today);
        $ok = array_keys(array_filter($consent, fn($c) => !empty($c['ok'])));
        return ['ids' => $ok, 'counts' => [
            'clients' => count($clients), 'neighbours' => count($neighbours),
            'considered' => count($ids), 'consented' => count($ok),
        ]];
    }

    /** Sam's open quotes, "never" mutes, inactive or test contacts and no email are left out. */
    private function leaveOut(array $ids, DateTimeImmutable $today): array
    {
        if (!$ids) return [];
        $sam = (new MiaFinder($this->db))->samOwns($today);
        $never = [];
        foreach ($this->db->query("SELECT subject_key FROM mia_mutes WHERE until_date IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $k) {
            if (preg_match('/^mia:contact:(\d+)$/', $k, $m)) $never[(int)$m[1]] = true;
        }
        $keep = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->db->prepare("SELECT id, first_name, last_name, email FROM contacts WHERE id IN ($in) AND is_active = 1 AND email IS NOT NULL AND email <> ''");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $id = (int)$c['id'];
                if (isset($sam[$id]) || isset($never[$id]) || MiaFinder::isTest($c)) continue;
                $keep[] = $id;
            }
        }
        return $keep;
    }

    /** Tim's own published before/after pair that fits, without any address. */
    private function photo(string $pattern): ?array
    {
        try {
            require_once dirname(__DIR__, 2) . '/Portfolio/Services/BeforeAfterService.php';
            foreach ((new BeforeAfterService($this->db))->published(40) as $p) {
                if (preg_match($pattern, ($p['service'] ?? '') . ' ' . ($p['category'] ?? '') . ' ' . ($p['label'] ?? ''))) {
                    return ['before' => self::absolute($p['before_url']), 'after' => self::absolute($p['after_url']),
                            'alt_before' => (string)$p['alt_before'], 'alt_after' => (string)$p['alt_after']];
                }
            }
        } catch (Throwable $e) { /* no portfolio here — the campaign goes without a photo */ }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The calendar (migration 1195)
    // ─────────────────────────────────────────────────────────────────────────

    private ?bool $hub = null;

    /** Migration 1195 has run: calendar columns on mia_campaigns, sequence columns on campaign_sends. */
    public function hubReady(): bool
    {
        if ($this->hub === null) {
            try {
                $this->db->query("SELECT calendar_key, season_year, send_from, send_to, sequence_json, flags_json FROM mia_campaigns LIMIT 0");
                $this->db->query("SELECT step, scheduled_at FROM campaign_sends LIMIT 0");
                $this->hub = true;
            } catch (Throwable $e) {
                $this->hub = false;
            }
        }
        return $this->hub;
    }

    private ?array $calendar = null;

    public function calendarEntry(string $key): ?array
    {
        $this->calendar = $this->calendar ?? MiaCalendar::load($this->db);
        return $this->calendar[$key] ?? MiaCalendar::defaults()[$key] ?? null;
    }

    /** The CRM's real prices per service ("starts at"), for {price:…} tokens. */
    public function prices(): array
    {
        try {
            $has = function (string $c): bool {
                try { $this->db->query("SELECT $c FROM products LIMIT 0"); return true; } catch (Throwable $e) { return false; }
            };
            $arch = $has('is_archived') ? ' AND is_archived = 0' : '';
            $svc = $has('service_type') ? 'service_type' : 'NULL AS service_type';
            $min = $has('min_price') ? 'min_price' : 'NULL AS min_price';
            $rows = $this->db->query("SELECT name, $svc, base_price, $min FROM products WHERE active = 1$arch")->fetchAll(PDO::FETCH_ASSOC);
            return MiaCalendar::pricesFrom($rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Propose each calendar campaign once, ~7 days before its send window, if its conditions hold. */
    private function proposeFromCalendar(DateTimeImmutable $today): int
    {
        $t = $today->format('Y-m-d');
        // A proposal Tim never decided lapses when its send window ends.
        $this->db->prepare("UPDATE mia_campaigns SET status = 'expired' WHERE status = 'proposed' AND send_to IS NOT NULL AND send_to < ?")->execute([$t]);
        $water = (new WaterRestrictionService($this->db))->current($today);
        $ctx = ['stage' => $water['stage'], 'drought_year' => $water['drought_year']];
        $prices = null;
        $added = 0;
        $this->calendar = MiaCalendar::load($this->db);
        foreach ($this->calendar as $e) {
            if (!MiaCalendar::proposable($e, $today)) continue;
            $occ = MiaCalendar::occurrence($e, $today);
            $seen = $this->db->prepare("SELECT 1 FROM mia_campaigns WHERE campaign_key = ?");
            $seen->execute([$occ['key']]);
            if ($seen->fetchColumn()) continue; // one proposal per campaign per year, whatever Tim decided
            if (!MiaCalendar::conditionsHold($e, $ctx)['ok']) continue; // held — the card says why
            $prices = $prices ?? $this->prices();
            $fill = fn(string $x) => MiaCalendar::fill($x, $prices, $occ['year']);
            $steps = [];
            if (!empty($e['reminder_days']) && trim($e['reminder_body']) !== '') {
                $steps[] = ['step' => 1, 'days' => (int)$e['reminder_days'], 'subject' => $fill($e['reminder_subject'] ?: $e['subject']), 'body' => $fill($e['reminder_body'])];
            }
            if (!empty($e['lastcall_days']) && trim($e['lastcall_body']) !== '') {
                $steps[] = ['step' => 2, 'days' => (int)$e['lastcall_days'], 'subject' => $fill($e['lastcall_subject'] ?: $e['subject']), 'body' => $fill($e['lastcall_body'])];
            }
            $subject = $fill($e['subject']);
            $body = $fill($e['body']);
            $flags = self::problems($subject, $body, (int)($ctx['stage'] ?? 0));
            if (!empty($e['conditions']['stage_aware']) && (int)($ctx['stage'] ?? 0) >= 2) {
                $flags[] = 'Stage ' . (int)$ctx['stage'] . ' is on: point people to a dormant brown lawn (' . WaterRestrictionService::DORMANCY_URL . '), never to watering';
            }
            $a = $this->audienceFor($e, $today);
            $photo = $e['photo'] !== '' ? $this->photo($e['photo']) : null;
            $name = $e['name'] . ' (' . $occ['year'] . ')';
            try {
                $this->db->prepare("
                    INSERT INTO mia_campaigns (campaign_key, calendar_key, season_year, send_from, send_to, name, why, subject, body_text,
                                               sequence_json, flags_json, photo_json, audience_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$occ['key'], $e['key'], $occ['year'], $occ['send_from'], $occ['send_to'], $name, $e['why'], $subject, $body,
                             $steps ? json_encode($steps) : null, $flags ? json_encode(array_values($flags)) : null,
                             $photo ? json_encode($photo) : null, json_encode($a['counts'])]);
                $added++;
            } catch (PDOException $ex) { /* proposed by another request a moment ago (unique key) */ }
        }
        return $added;
    }

    /** Calendar extras for the card: send window, sequence note, flags, last year's result. */
    private function hubDetails(array $r, DateTimeImmutable $today): array
    {
        $out = ['send_window' => null, 'sequence_note' => '', 'flags' => [], 'last_year' => null, 'audience' => null];
        $e = $this->calendarEntry((string)$r['calendar_key']);
        if ($e) $out['audience'] = $e['audience'];
        if (!empty($r['send_from'])) {
            $out['send_window'] = MiaCalendar::short((string)$r['send_from']) . ' to ' . MiaCalendar::short((string)$r['send_to']);
            $main = MiaCalendar::mainDate(['send_from' => (string)$r['send_from']], $today);
            $dates = [];
            foreach (json_decode((string)($r['sequence_json'] ?? ''), true) ?: [] as $st) {
                $dates[(int)$st['step']] = (new DateTimeImmutable($main))->modify('+' . (int)$st['days'] . ' days')->format('Y-m-d');
            }
            $out['sequence_note'] = MiaCalendar::sequenceNote($dates);
        }
        if ($r['status'] === 'proposed') {
            $water = (new WaterRestrictionService($this->db))->current($today);
            $out['flags'] = self::problems((string)$r['subject'], (string)$r['body_text'], (int)($water['stage'] ?? 0));
            foreach (json_decode((string)($r['flags_json'] ?? ''), true) ?: [] as $f) {
                if (strpos((string)$f, 'dormant brown lawn') !== false) $out['flags'][] = (string)$f;
            }
            $out['flags'] = array_values(array_unique($out['flags']));
        }
        try {
            $q = $this->db->prepare("SELECT * FROM mia_calendar_results WHERE calendar_key = ? AND season_year < ? ORDER BY season_year DESC LIMIT 1");
            $q->execute([(string)$r['calendar_key'], (int)$r['season_year']]);
            $ly = $q->fetch(PDO::FETCH_ASSOC);
            if ($ly) $out['last_year'] = self::lastYearLine($ly);
        } catch (Throwable $e) {}
        return $out;
    }

    /** "In 2025 this booked 14 jobs ($9,800) from 212 emails · 30 replied, 41 clicked." */
    public static function lastYearLine(array $ly): string
    {
        $money = (float)$ly['booked_amount'] > 0 ? ' ($' . number_format((float)$ly['booked_amount'], 0) . ')' : '';
        return 'In ' . (int)$ly['season_year'] . ' this booked ' . (int)$ly['booked'] . ' job' . ((int)$ly['booked'] === 1 ? '' : 's') . $money
            . ' from ' . (int)$ly['sent'] . ' emails · ' . (int)$ly['replied'] . ' replied, ' . (int)$ly['clicked'] . ' clicked.';
    }

    /**
     * Who a calendar entry goes to right now (same contract as audience()).
     * Applies the entry's recipient conditions: skip_if_leaf_heavy, exclude_responders_of.
     */
    public function audienceFor(?array $e, DateTimeImmutable $today): array
    {
        $spec = $e ? (string)$e['audience'] : 'all_clients';
        if ($spec === 'all_clients' && empty($e['conditions']['skip_if_leaf_heavy']) && empty($e['conditions']['exclude_responders_of'])) {
            return $this->audience($today);
        }
        $ids = $this->segmentIds($spec, $today);
        $raw = count($ids);
        if (!empty($e['conditions']['skip_if_leaf_heavy'])) $ids = array_values(array_diff($ids, $this->leafHeavy($today)));
        if (!empty($e['conditions']['exclude_responders_of'])) $ids = array_values(array_diff($ids, $this->respondersOf((string)$e['conditions']['exclude_responders_of'], $today)));
        $ids = $this->leaveOut($ids, $today);
        $consent = (new ConsentLedgerService($this->db))->bulk($ids, 'email', $today);
        $ok = array_keys(array_filter($consent, fn($c) => !empty($c['ok'])));
        return ['ids' => $ok, 'counts' => [
            'clients' => $raw, 'neighbours' => 0, 'segment' => $spec,
            'considered' => count($ids), 'consented' => count($ok),
        ]];
    }

    /**
     * Raw contact ids for one or more audiences ("property_managers,stratas"), before consent.
     * Each part is best-effort: a missing table or column just contributes nobody.
     */
    public function segmentIds(string $spec, DateTimeImmutable $today): array
    {
        $ids = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $seg) {
            foreach ($this->segment($seg, $today) as $id) $ids[(int)$id] = true;
        }
        unset($ids[0]);
        return array_keys($ids);
    }

    private function segment(string $seg, DateTimeImmutable $today): array
    {
        $col = function (string $sql, array $p = []): array {
            try {
                $s = $this->db->prepare($sql);
                $s->execute($p);
                return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) {
                return [];
            }
        };
        switch ($seg) {
            case 'all_clients':
                return $this->clientsAndNeighbours($today);
            case 'homeowners':
                return array_values(array_diff($this->clientsAndNeighbours($today), $this->segmentIds('property_managers,stratas', $today)));
            case 'property_managers':
                return array_merge(
                    $col("SELECT primary_contact_id FROM companies WHERE company_type = 'property_manager' AND primary_contact_id IS NOT NULL"),
                    $col("SELECT billing_contact_id FROM companies WHERE company_type = 'property_manager' AND billing_contact_id IS NOT NULL"),
                    $col("SELECT DISTINCT COALESCE(co.primary_contact_id, co.billing_contact_id) FROM properties p JOIN companies co ON co.id = p.property_manager_id
                          WHERE COALESCE(co.primary_contact_id, co.billing_contact_id) IS NOT NULL")
                );
            case 'stratas':
                return array_merge(
                    $col("SELECT primary_contact_id FROM companies WHERE company_type = 'strata' AND primary_contact_id IS NOT NULL"),
                    $col("SELECT billing_contact_id FROM companies WHERE company_type = 'strata' AND billing_contact_id IS NOT NULL"),
                    $col("SELECT DISTINCT site_contact_id FROM properties WHERE property_type = 'strata' AND site_contact_id IS NOT NULL")
                );
            case 'past_seed':
                $since = $today->modify('-24 months')->format('Y-m-d');
                return array_merge(
                    $col("SELECT DISTINCT COALESCE(q.contact_id, p.site_contact_id) FROM quote_line_items li JOIN quotes q ON q.id = li.quote_id
                          LEFT JOIN properties p ON p.id = q.property_id
                          WHERE q.status IN ('accepted', 'approved_verbal') AND q.created_at >= ?
                            AND (li.service_type LIKE '%seed%' OR li.description LIKE '%seed%')", [$since]),
                    $col("SELECT DISTINCT p.site_contact_id FROM job_plans jp JOIN properties p ON p.id = jp.property_id
                          WHERE jp.created_at >= ? AND (jp.service_type LIKE '%seed%' OR jp.title LIKE '%seed%')", [$since])
                );
            case 'spring_holds':
                return $this->springHolds($today);
        }
        return [];
    }

    /** Current clients (active plan or a visit in 12 months) and their neighbours within 400 m. */
    private function clientsAndNeighbours(DateTimeImmutable $today): array
    {
        $since = $today->modify('-12 months')->format('Y-m-d');
        $clients = $spots = [];
        $s = $this->db->prepare("
            SELECT DISTINCT p.site_contact_id AS cid, p.latitude AS lat, p.longitude AS lng
            FROM properties p JOIN job_plans jp ON jp.property_id = p.id
            WHERE p.site_contact_id IS NOT NULL
              AND (jp.status = 'active' OR EXISTS (
                    SELECT 1 FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'completed' AND jv.scheduled_date >= ?))");
        $s->execute([$since]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $clients[(int)$r['cid']] = true;
            if ($r['lat'] !== null && $r['lng'] !== null && (float)$r['lat'] != 0.0) $spots[] = [(float)$r['lat'], (float)$r['lng']];
        }
        if ($spots) {
            $o = $this->db->query("SELECT site_contact_id AS cid, latitude AS lat, longitude AS lng FROM properties
                                   WHERE site_contact_id IS NOT NULL AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude <> 0");
            foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cid = (int)$r['cid'];
                if (!isset($clients[$cid]) && self::near((float)$r['lat'], (float)$r['lng'], $spots, self::NEIGHBOUR_METRES)) $clients[$cid] = true;
            }
        }
        return array_keys($clients);
    }

    /** People who asked for spring: "Booked for spring" answers, and "spring" replies to recent campaigns. */
    private function springHolds(DateTimeImmutable $today): array
    {
        $ids = [];
        try {
            foreach ($this->db->query("SELECT subject_key FROM mia_questions WHERE kind = 'spring_prebook' AND answer = 'booked'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
                if (preg_match('/^mia:spring:\d+:(\d+)$/', (string)$k, $m)) $ids[(int)$m[1]] = true;
            }
        } catch (Throwable $e) {}
        try {
            $s = $this->db->prepare("SELECT DISTINCT sm.contact_id, sm.snippet FROM sales_messages sm
                                     JOIN campaign_sends cs ON cs.contact_id = sm.contact_id AND cs.status = 'sent' AND sm.sent_at >= cs.sent_at
                                     WHERE sm.direction = 'inbound' AND sm.sent_at >= ? AND sm.snippet LIKE '%spring%'");
            $s->execute([$today->modify('-6 months')->format('Y-m-d')]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (preg_match('/\bspring\b/i', (string)$r['snippet'])) $ids[(int)$r['contact_id']] = true;
            }
        } catch (Throwable $e) {}
        return array_keys($ids);
    }

    /** Lawns under trees: a leaf cleanup on the property last fall (Oct–Dec). Their seed waits for spring. */
    private function leafHeavy(DateTimeImmutable $today): array
    {
        $y = (int)$today->format('Y') - 1;
        try {
            $s = $this->db->prepare("SELECT DISTINCT p.site_contact_id FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id JOIN properties p ON p.id = jp.property_id
                                     WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?
                                       AND (jp.service_type LIKE '%leaf%' OR jp.title LIKE '%leaf%' OR jp.title LIKE '%leaves%')");
            $s->execute(["$y-10-01", "$y-12-31"]);
            return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Recipients of this year's <calKey> campaign who replied, were quoted or booked since. */
    private function respondersOf(string $calKey, DateTimeImmutable $today): array
    {
        try {
            $s = $this->db->prepare("SELECT marketing_campaign_id FROM mia_campaigns WHERE calendar_key = ? AND season_year = ? AND marketing_campaign_id IS NOT NULL");
            $s->execute([$calKey, (int)$today->format('Y')]);
            $mc = (int)$s->fetchColumn();
            if (!$mc) return [];
            $q = $this->db->prepare("SELECT cs.contact_id FROM campaign_sends cs WHERE cs.campaign_id = ? AND cs.status = 'sent'
                                     AND (cs.replied_at IS NOT NULL OR cs.booked_at IS NOT NULL
                                          OR EXISTS (SELECT 1 FROM quotes q WHERE q.contact_id = cs.contact_id AND q.created_at >= cs.sent_at))");
            $q->execute([$mc]);
            return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tim's decision
    // ─────────────────────────────────────────────────────────────────────────

    /** One tap: create the campaign and its sends for everyone who passes consent now. */
    public function approve(int $id, array $user, ?string $subject = null, ?string $body = null, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        $s = $this->db->prepare("SELECT * FROM mia_campaigns WHERE id = ? AND status = 'proposed'");
        $s->execute([$id]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['ok' => false, 'error' => 'That campaign was already decided.'];
        $subject = trim((string)($subject ?? $c['subject']));
        $body = trim(str_replace("\r\n", "\n", (string)($body ?? $c['body_text'])));
        $hub = $this->hubReady() && !empty($c['calendar_key']);
        $entry = $hub ? $this->calendarEntry((string)$c['calendar_key']) : null;
        $stage = 0;
        if ($hub) {
            $water = (new WaterRestrictionService($this->db))->current($today);
            $stage = (int)($water['stage'] ?? 0);
            if ($entry) {
                $hold = MiaCalendar::conditionsHold($entry, ['stage' => $water['stage'], 'drought_year' => true]);
                if (!$hold['ok']) return ['ok' => false, 'error' => 'Not approved: ' . $hold['reason'] . '.'];
            }
        }
        $p = self::problems($subject, $body, $stage);
        foreach ($hub ? (json_decode((string)($c['sequence_json'] ?? ''), true) ?: []) : [] as $st) {
            foreach (self::problems((string)$st['subject'], (string)$st['body'], $stage) as $x) $p[] = 'the ' . (MiaSequenceService::STEP_NAMES[(int)$st['step']] ?? 'follow-up') . ': ' . $x;
        }
        if ($p) return ['ok' => false, 'error' => 'Not approved: ' . implode('; ', array_unique($p)) . '.'];

        $a = $entry ? $this->audienceFor($entry, $today) : $this->audience($today);
        if (!$a['ids']) return ['ok' => false, 'error' => 'Nobody on the list has a consent record right now.'];
        $photo = $c['photo_json'] ? json_decode($c['photo_json'], true) : null;
        $html = self::html($body, $photo);
        $uid = (int)($user['id'] ?? 0);

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO marketing_campaigns (name, segment_type, segment_rules, trigger_type, auto_send, subject_override, body_override, status, recipient_count, created_by)
                VALUES (?, 'custom_list', ?, 'manual', 0, ?, ?, 'sending', ?, ?)
            ")->execute([$c['name'], json_encode(['source' => 'mia', 'mia_campaign' => $c['campaign_key'], 'approved_by' => $uid]),
                         $subject, $html, count($a['ids']), $uid ?: null]);
            $mcId = (int)$this->db->lastInsertId();
            if ($hub) {
                // Each person's email waits for their best slot inside the send window (SendTimeService).
                $from = max($today->format('Y-m-d'), (string)($c['send_from'] ?: $today->format('Y-m-d')));
                $to = max($from, (string)($c['send_to'] ?: $from));
                $now = $today->format('Y-m-d') === date('Y-m-d') ? new DateTimeImmutable() : $today;
                $slots = (new SendTimeService($this->db))->schedule($a['ids'], $this->segmentIds('property_managers,stratas', $today), $now, $from, $to);
                $ins = $this->db->prepare("INSERT INTO campaign_sends (campaign_id, contact_id, email, status, step, scheduled_at, created_at)
                                           SELECT ?, id, email, 'pending', 0, ?, NOW() FROM contacts WHERE id = ?");
                foreach ($a['ids'] as $cid) $ins->execute([$mcId, $slots[$cid] ?? null, $cid]);
            } else {
                $ins = $this->db->prepare("INSERT INTO campaign_sends (campaign_id, contact_id, email, status, created_at) SELECT ?, id, email, 'pending', NOW() FROM contacts WHERE id = ?");
                foreach ($a['ids'] as $cid) $ins->execute([$mcId, $cid]);
            }
            $this->db->prepare("UPDATE mia_campaigns SET status = 'approved', subject = ?, body_text = ?, marketing_campaign_id = ?, recipients = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")
                ->execute([$subject, $body, $mcId, count($a['ids']), $uid, $id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['ok' => true, 'recipients' => count($a['ids']), 'campaign_id' => $mcId];
    }

    public function dismiss(int $id, array $user): array
    {
        $s = $this->db->prepare("UPDATE mia_campaigns SET status = 'dismissed', decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'proposed'");
        $s->execute([(int)($user['id'] ?? 0), $id]);
        return $s->rowCount() > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'That campaign was already decided.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Within $metres of any of the client spots ([lat, lng]). Haversine. */
    public static function near(float $lat, float $lng, array $spots, int $metres): bool
    {
        foreach ($spots as [$la, $lo]) {
            if (abs($la - $lat) > 0.01 || abs($lo - $lng) > 0.015) continue; // ~1 km box first
            $dLat = deg2rad($la - $lat);
            $dLng = deg2rad($lo - $lng);
            $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($la)) * sin($dLng / 2) ** 2;
            if (2 * 6371000 * asin(min(1, sqrt($h))) <= $metres) return true;
        }
        return false;
    }

    /**
     * What would stop a campaign going out as written. Empty when it's fine.
     * $stage is today's Metro Vancouver watering stage (0 = none): from Stage 2 no lawn may be
     * watered, so any line that ties watering to a lawn, grass, seed or sod is refused.
     */
    public static function problems(string $subject, string $body, int $stage = 0): array
    {
        $p = [];
        $all = $subject . "\n" . $body;
        if ($subject === '' || $body === '') $p[] = 'it needs a subject and a message';
        if (strpos($all, '!') !== false) $p[] = 'no exclamation marks';
        // Tim writes the words he approves, so his own prices are fine ("Aeration starts at $95").
        // Discounts stay out: a discount is a decision of its own, not a line slipped into a campaign.
        if (preg_match('/\d+\s?%\s*off|\bdiscount|\bcoupon|\bpromo code|\bper cent off|\$\s?\d[\d.,]*\s*off\b/i', $all)) $p[] = 'a discount needs a campaign of its own';
        if (preg_match('/\b(water|sprinkl\w*|irrigat\w*)\b[^.]*\b(now|today|this week|daily|every day|more)\b/i', $all)) $p[] = 'never tell people to water while the restrictions are on';
        if (preg_match('/\breview\b/i', $all) && preg_match('/\b(free|discount|gift|reward|draw|entry)\b/i', $all)) $p[] = 'no rewards for reviews';
        if (preg_match('/\{\{(?!first_name\}\})[a-z_]+\}\}/', $all)) $p[] = 'only {{first_name}} is filled in per person';
        if (preg_match_all('/\{price:([a-z_]+)\}/', $all, $m)) $p[] = 'no CRM price found for ' . implode(', ', array_unique($m[1])) . ' — write the price in or take the line out';
        if ($stage >= 2 && self::mentionsLawnWatering($all)) $p[] = "Stage $stage is on: no lawn may be watered, so don't suggest it (a brown lawn is dormant, not dead)";
        return $p;
    }

    /** Does the text tie watering to a lawn, grass, seed or sod? ("watering restrictions" and blow-outs are fine.) */
    public static function mentionsLawnWatering(string $text): bool
    {
        $w = '(?:water(?:s|ed|ing)?|sprinkl\w*|irrigat\w*|hose\w*)';
        $l = '(?:lawns?|grass|turf|seed(?:ed|ing)?|sod)';
        foreach (preg_split('/(?<=[.?!\n])/', $text) as $sentence) {
            if (preg_match('/\bblow(?:-|\s)?out|\bblow\s+it\s+out/i', $sentence)) continue;
            $clean = (string)preg_replace('/\b(?:lawn\s+)?watering\s+(?:restrictions?|ban|stages?|rules)\b/i', ' ', $sentence);
            if (preg_match("/\\b$w\\b.{0,50}\\b$l\\b|\\b$l\\b.{0,50}\\b$w\\b/i", $clean)) return true;
        }
        return false;
    }

    /** The campaign body as email HTML: Tim's words, then his before/after photo if there is one. */
    public static function html(string $body, ?array $photo): string
    {
        $html = MiaWording::toHtml($body);
        if ($photo && !empty($photo['before']) && !empty($photo['after'])) {
            $img = fn($src, $alt) => '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8')
                . '" width="260" style="width:48%;max-width:260px;height:auto;border-radius:6px;">';
            $html .= '<p style="margin:0 0 6px;text-align:center;">' . $img($photo['before'], $photo['alt_before'] ?: 'Before')
                . ' ' . $img($photo['after'], $photo['alt_after'] ?: 'After') . '</p>'
                . '<p style="margin:0 0 14px;text-align:center;font-size:12px;color:#6f8a7e;">Before and after, from our own work.</p>';
        }
        return $html;
    }

    public static function absolute(?string $path): string
    {
        $path = (string)$path;
        return preg_match('~^https?://~', $path) ? $path : self::SITE . '/' . ltrim($path, '/');
    }
}
