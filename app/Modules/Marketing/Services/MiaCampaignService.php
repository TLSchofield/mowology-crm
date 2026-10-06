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
        }
        return $out;
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
