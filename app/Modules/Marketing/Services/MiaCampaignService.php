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
                'subject' => 'Your lawn after the watering ban',
                // How Mia names it in her brief: "Colleen replied to the fall lawn email."
                'label'   => 'the fall lawn email',
                'body'  => "Hi {{first_name}},\n\n"
                    . "A lot of lawns came through this summer brown and thin. The watering restrictions lift on October 15, and with the fall rain on its way, the second half of October is the right time to bring yours back. A lawn that goes into winter thin usually comes out of it full of moss and weeds.\n\n"
                    . "Three things do most of the work:\n"
                    . "- Aeration opens up compacted soil so rain and air reach the roots.\n"
                    . "- Overseeding fills the thin and bare patches before winter.\n"
                    . "- Top-dressing goes on with the seed: a thin layer of compost that holds moisture and feeds it.\n\n"
                    . "One thing to know first: new seed needs light. If your lawn sits under trees, falling leaves will smother the seed before it takes. For those lawns we aerate now, and overseed and top-dress in spring once the leaves are done.\n\n"
                    . "What it costs: aeration starts at $95 and overseeding at $90. Both go by the size of your lawn, so I'll measure yours, tell you what it actually needs, and send a fixed price before any work starts. Top-dressing depends on how much compost it takes, and goes in the same quote. You don't need to be home, and you'll get photos of the finished work.\n\n"
                    . "If you'd like us to look at your lawn, reply to this email and I'll get back to you within a day.\n\n"
                    . "Thanks,\nTim\n\n"
                    . "P.S. Spring is our busiest season, so we only take spring work that's booked ahead. If you'd rather do it all in spring, reply \"spring\" and I'll hold a timeslot open for you whilst we work out the details together.",
            ],
        ];
    }

    /** Propose whatever is in season and not yet proposed. Returns how many were added. */
    public function propose(DateTimeImmutable $today): int
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
        $r = $this->db->query("SELECT * FROM mia_campaigns WHERE status IN ('proposed', 'approved') ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $out = [
            'id' => (int)$r['id'], 'key' => $r['campaign_key'], 'name' => $r['name'], 'why' => $r['why'],
            'subject' => $r['subject'], 'body' => $r['body_text'], 'status' => $r['status'],
            'photo' => $r['photo_json'] ? json_decode($r['photo_json'], true) : null,
            'counts' => json_decode((string)$r['audience_json'], true) ?: [],
        ];
        if ($r['status'] === 'proposed') {
            $out['counts'] = $this->audience($today)['counts'];
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
        if ($p = self::problems($subject, $body)) return ['ok' => false, 'error' => 'Not approved: ' . implode('; ', $p) . '.'];

        $a = $this->audience($today);
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
            $ins = $this->db->prepare("INSERT INTO campaign_sends (campaign_id, contact_id, email, status, created_at) SELECT ?, id, email, 'pending', NOW() FROM contacts WHERE id = ?");
            foreach ($a['ids'] as $cid) $ins->execute([$mcId, $cid]);
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

    /** What would stop a campaign going out as written. Empty when it's fine. */
    public static function problems(string $subject, string $body): array
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
        return $p;
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
