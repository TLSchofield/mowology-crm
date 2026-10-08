<?php
/**
 * SpecialRequestService — a client's ask about work that is already on the schedule.
 *
 *   1. Proposed (status pending) from an inbound client email / text (scanInbound(), Yui's lane)
 *      or added by hand on a visit / Otto's card (head otto). The matcher finds the properties
 *      and the next visit(s) there; nothing is attached until Tim taps "Attach" (confirm()).
 *   2. Attached → the crew leader gets an SMS (sendSms(), carrier rules: plain text, ≤160, no
 *      links) and every crew member on the visit a push. Every send is logged
 *      (special_request_events).
 *   3. On the visit, every crew member must read it and tap "Got it" (ack()) before they can
 *      start the job or take a photo — enforced in the app AND on the server (SpecialRequestGate).
 *      The first start / auto-arrival attempt pushes the request again ("arrival").
 *   4. The crew answer per visit (outcome()): done / not done (reason) / extra work done (minutes
 *      → the visit's existing extras_minutes / extras_amount / extras_note, which every invoice
 *      path already reads — added ONCE, see foldExtras()). The visit's notes record the outcome.
 *
 * Feature flags (ops_settings, migration 1299): special_requests_enabled ('0' = all inert) and
 * special_requests_user_ids (crew side only for these users, for one-device testing).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/SpecialRequestMatcher.php';

class SpecialRequestService
{
    /** Who raised it — shown with their face (/crm/img/heads/<slug>.jpg) like the dashboard deck. */
    public const HEADS = [
        'yui'  => ['name' => 'Yui',  'role' => 'Comms'],
        'otto' => ['name' => 'Otto', 'role' => 'Operations'],
    ];
    public const FACE_BASE = 'https://mowology.ca/crm/img/heads/';

    public static function headPhoto(string $head): string
    {
        return '/crm/img/heads/' . (isset(self::HEADS[$head]) ? $head : 'otto') . '.jpg';
    }
    public const OUTCOMES = ['done', 'not_done', 'extra_done'];
    public const OFFICE_PHONE = '(778) 846-9273';
    public const SMS_MAX = 160;

    private PDO $db;
    private ?string $now;
    /** @var callable(string $phone, string $text): array */
    private $sms;
    /** @var callable(array $userIds, string $title, string $body, array $data): void */
    private $push;
    private array $settings = [];

    public function __construct(PDO $db, ?string $now = null, ?callable $sms = null, ?callable $push = null)
    {
        $this->db = $db;
        $this->now = $now;
        $this->sms = $sms;
        $this->push = $push;
    }

    public function now(): string { return $this->now ?? date('Y-m-d H:i:s'); }
    public function today(): string { return substr($this->now(), 0, 10); }

    // ─────────────────────────────────────────────────────────────────────────
    // Flags
    // ─────────────────────────────────────────────────────────────────────────

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT id FROM special_request_visits LIMIT 0');
            $this->db->query('SELECT id FROM special_request_acks LIMIT 0');
            $this->db->query('SELECT id FROM special_request_events LIMIT 0');
            $this->db->query('SELECT id FROM special_requests LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function setting(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $this->settings)) {
            try {
                $st = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
                $st->execute([$key]);
                $v = $st->fetchColumn();
                $this->settings[$key] = $v === false || $v === null ? $default : (string)$v;
            } catch (Throwable $e) {
                $this->settings[$key] = $default;
            }
        }
        return $this->settings[$key];
    }

    /** Master switch — '0' (default) makes every path inert. */
    public function enabled(): bool
    {
        return trim($this->setting('special_requests_enabled', '0')) === '1' && $this->ready();
    }

    /** @return list<int> */
    public function testUserIds(): array
    {
        $raw = $this->setting('special_requests_user_ids', '');
        return array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw) ?: []))));
    }

    /** The crew side (cards, gate, SMS, pushes) applies to this user. */
    public function appliesToUser(int $userId): bool
    {
        if (!$this->enabled()) return false;
        $ids = $this->testUserIds();
        return !$ids || in_array($userId, $ids, true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Matching (read-only)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The client's properties (every link: on-site/other contact, site contact, company,
     * company_properties, company's plans), their active plans and open visits from $fromDate.
     */
    public function candidates(int $contactId, string $fromDate, int $horizonDays = 14): array
    {
        $ids = [];
        $companyId = null;
        try {
            $st = $this->db->prepare('SELECT company_id FROM contacts WHERE id = ?');
            $st->execute([$contactId]);
            $c = $st->fetchColumn();
            $companyId = $c ? (int)$c : null;
        } catch (Throwable $e) { /* no company link */ }

        $sources = [
            ['SELECT property_id FROM property_contacts WHERE contact_id = ?', $contactId],
            ['SELECT id FROM properties WHERE site_contact_id = ?', $contactId],
        ];
        if ($companyId) {
            $sources[] = ['SELECT id FROM properties WHERE company_id = ?', $companyId];
            $sources[] = ['SELECT property_id FROM company_properties WHERE company_id = ?', $companyId];
            $sources[] = ["SELECT property_id FROM job_plans WHERE company_id = ? AND status = 'active'", $companyId];
        }
        foreach ($sources as [$sql, $arg]) {
            try {
                $st = $this->db->prepare($sql);
                $st->execute([$arg]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    if ((int)$pid > 0) $ids[(int)$pid] = true;
                }
            } catch (Throwable $e) { /* table not on this install */ }
        }
        $ids = array_keys($ids);
        if (!$ids) {
            return ['company_id' => $companyId, 'properties' => [], 'plans' => [], 'visits' => []];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $st = $this->db->prepare("SELECT id, address, city, property_name FROM properties WHERE id IN ($in) ORDER BY id");
        $st->execute($ids);
        $properties = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $this->db->prepare("SELECT id, property_id, plan_number, title, description, service_type
                                  FROM job_plans WHERE property_id IN ($in) AND status = 'active' ORDER BY id");
        $st->execute($ids);
        $plans = $st->fetchAll(PDO::FETCH_ASSOC);

        $until = date('Y-m-d', strtotime($fromDate . ' +' . $horizonDays . ' days'));
        $st = $this->db->prepare("SELECT jv.id AS visit_id, jp.property_id, jv.plan_id, jv.scheduled_date, jv.status,
                                         jv.stop_id, jv.visit_number, jp.plan_number, jp.title AS plan_title
                                  FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                                  WHERE jp.property_id IN ($in) AND jv.scheduled_date >= ? AND jv.scheduled_date <= ?
                                    AND jv.status IN ('scheduled', 'in_progress')
                                  ORDER BY jv.scheduled_date, jv.id");
        $st->execute(array_merge($ids, [$fromDate, $until]));
        $visits = $st->fetchAll(PDO::FETCH_ASSOC);

        return ['company_id' => $companyId, 'properties' => $properties, 'plans' => $plans, 'visits' => $visits];
    }

    /** Read-only: which properties / visits a message would attach to, and what's included vs extra. */
    public function dryRun(string $text, int $contactId, ?string $fromDate = null): array
    {
        $fromDate = $fromDate ?: $this->today();
        $cand = $this->candidates($contactId, $fromDate);
        $p = SpecialRequestMatcher::propose($text, $cand['properties'], $cand['plans'], $cand['visits'], $fromDate);
        foreach ($p['visits'] as &$v) {
            $v['address'] = $this->propertyAddress((int)$v['property_id'], $cand['properties']);
            $v['crew'] = $this->crewNames($this->crewForVisit((int)$v['visit_id'])['all']);
        }
        unset($v);
        $p['contact_id'] = $contactId;
        $p['company_id'] = $cand['company_id'];
        $p['from_date'] = $fromDate;
        $p['candidate_properties'] = count($cand['properties']);
        return $p;
    }

    private function propertyAddress(int $pid, array $props): string
    {
        foreach ($props as $p) {
            if ((int)$p['id'] === $pid) return trim((string)$p['address']);
        }
        return '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Creating
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A pending request for Tim to confirm. Idempotent on $in['message_key'].
     * $in: text, contact_id, source (email|sms|manual), head (yui|otto), message_key?, received_at?,
     *      from_date?, created_by?
     * @return array{id:?int, created:bool, proposal:array}
     */
    public function propose(array $in): array
    {
        $key = isset($in['message_key']) && $in['message_key'] !== '' ? (string)$in['message_key'] : null;
        if ($key !== null) {
            $st = $this->db->prepare('SELECT id FROM special_requests WHERE source_message_key = ?');
            $st->execute([$key]);
            if ($id = $st->fetchColumn()) {
                return ['id' => (int)$id, 'created' => false, 'proposal' => []];
            }
        }
        $contactId = (int)($in['contact_id'] ?? 0);
        $fromDate = (string)($in['from_date'] ?? '') ?: $this->today();
        $proposal = $this->dryRun((string)$in['text'], $contactId, $fromDate);
        $head = in_array($in['head'] ?? '', ['yui', 'otto'], true) ? $in['head'] : 'yui';

        $this->db->prepare('INSERT INTO special_requests
              (status, head, source, source_message_key, contact_id, company_id, summary, client_words,
               included_items, extra_items, proposal_json, received_at, created_by, created_at)
              VALUES (\'pending\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $head, (string)($in['source'] ?? 'manual'), $key, $contactId ?: null, $proposal['company_id'],
                mb_substr($proposal['summary'], 0, 255), $proposal['client_words'],
                implode("\n", $proposal['included']), implode("\n", $proposal['extra']),
                json_encode($proposal), $in['received_at'] ?? $this->now(),
                isset($in['created_by']) ? (int)$in['created_by'] : null, $this->now(),
            ]);
        $id = (int)$this->db->lastInsertId();
        $this->event($id, null, null, null, 'propose', true, count($proposal['visits']) . ' visit(s) proposed');
        return ['id' => $id, 'created' => true, 'proposal' => $proposal];
    }

    /**
     * Inbound client messages (sales_messages, office@ / iCloud / texts) from known contacts that
     * read like a work request about a property with a visit coming up → pending, for Yui's card.
     * Never attaches. Inert when the feature is off. Cheap: one query + a few per candidate.
     */
    public function scanInbound(int $hours = 72, int $limit = 40): int
    {
        if (!$this->enabled()) return 0;
        $since = date('Y-m-d H:i:s', strtotime($this->now()) - $hours * 3600);
        try {
            $st = $this->db->prepare("SELECT m.message_key, m.channel, m.contact_id, m.subject, m.snippet, m.sent_at
                FROM sales_messages m
                LEFT JOIN special_requests r ON r.source_message_key = m.message_key
                WHERE m.direction = 'inbound' AND m.contact_id IS NOT NULL AND m.sent_at >= ? AND r.id IS NULL
                ORDER BY m.sent_at DESC LIMIT " . (int)$limit);
            $st->execute([$since]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return 0;
        }
        $made = 0;
        foreach ($rows as $m) {
            $text = trim((string)$m['snippet']);
            if ($text === '' || !SpecialRequestMatcher::isWorkRequest($text)) continue;
            $from = substr((string)$m['sent_at'], 0, 10) ?: $this->today();
            $p = $this->dryRun($text, (int)$m['contact_id'], max($from, $this->today()));
            if (!$p['visits']) continue;
            $r = $this->propose([
                'text' => $text, 'contact_id' => (int)$m['contact_id'],
                'source' => $m['channel'] === 'sms' ? 'sms' : 'email', 'head' => 'yui',
                'message_key' => $m['message_key'], 'received_at' => $m['sent_at'],
                'from_date' => max($from, $this->today()),
            ]);
            if ($r['created']) $made++;
        }
        return $made;
    }

    /**
     * Tim's one tap: attach a pending request to its proposed visits (or the ones he picked),
     * then text the crew leader and push every crew member. Returns what was attached + sent.
     *
     * $edits: optional included[] / extra[] / visit_ids[] overrides.
     */
    public function confirm(int $requestId, int $actorId, array $edits = []): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'Special requests are switched off (special_requests_enabled).'];
        }
        $req = $this->request($requestId);
        if (!$req) return ['ok' => false, 'error' => 'Request not found.'];
        if ($req['status'] !== 'pending') return ['ok' => false, 'error' => 'Already ' . $req['status'] . '.'];

        $proposal = json_decode((string)$req['proposal_json'], true) ?: [];
        $visits = [];
        foreach (($proposal['visits'] ?? []) as $v) $visits[(int)$v['visit_id']] = $v;
        if (!empty($edits['visit_ids'])) {
            $want = array_map('intval', (array)$edits['visit_ids']);
            $visits = array_intersect_key($visits, array_flip($want));
            foreach ($want as $vid) {
                if (!isset($visits[$vid])) $visits[$vid] = ['visit_id' => $vid];
            }
        }
        if (!$visits) return ['ok' => false, 'error' => 'No visits to attach to.'];

        $included = isset($edits['included']) ? self::lines($edits['included']) : null;
        $extra = isset($edits['extra']) ? self::lines($edits['extra']) : null;
        if ($included !== null || $extra !== null) {
            $this->db->prepare('UPDATE special_requests SET included_items = ?, extra_items = ?, summary = ? WHERE id = ?')
                ->execute([implode("\n", $included ?? []), implode("\n", $extra ?? []),
                    mb_substr(SpecialRequestMatcher::summary($included ?? [], $extra ?? []), 0, 255), $requestId]);
        }

        $attached = [];
        foreach ($visits as $vid => $v) {
            $info = $this->visitInfo((int)$vid);
            if (!$info) continue;
            $inc = $included ?? ($v['included'] ?? null);
            $ext = $extra ?? ($v['extra'] ?? null);
            try {
                $this->db->prepare('INSERT INTO special_request_visits
                      (request_id, visit_id, property_id, status, included_items, extra_items, attached_at, created_at)
                      VALUES (?, ?, ?, \'attached\', ?, ?, ?, ?)')
                    ->execute([$requestId, (int)$vid, (int)$info['property_id'],
                        $inc === null ? null : implode("\n", $inc), $ext === null ? null : implode("\n", $ext),
                        $this->now(), $this->now()]);
            } catch (Throwable $e) {
                continue; // already attached to this visit
            }
            $srvId = (int)$this->db->lastInsertId();
            $attached[] = $srvId;
            $this->event($requestId, $srvId, (int)$vid, $actorId, 'attach', true, 'Attached to visit ' . ($info['visit_number'] ?? $vid));
            $this->visitNote((int)$vid, $actorId, 'Special request attached: ' . $this->payload($srvId, 0)['summary']);
        }
        $this->db->prepare("UPDATE special_requests SET status = 'attached', decided_by = ?, decided_at = ? WHERE id = ?")
            ->execute([$actorId, $this->now(), $requestId]);

        $sent = [];
        foreach ($attached as $srvId) {
            $sent[$srvId] = $this->notifyAttached($srvId);
        }
        return ['ok' => true, 'request_id' => $requestId, 'attached' => $attached, 'sent' => $sent];
    }

    /**
     * "Add special request" on a visit (web schedule, Otto's card): Tim typed it himself, so it is
     * attached straight away (his own action is the confirmation).
     * $in: visit_ids[], client_words, included[], extra[], contact_id?
     */
    public function createManual(array $in, int $actorId): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'Special requests are switched off (special_requests_enabled).'];
        }
        $visitIds = array_values(array_filter(array_map('intval', (array)($in['visit_ids'] ?? []))));
        $words = trim((string)($in['client_words'] ?? ''));
        if (!$visitIds || $words === '') return ['ok' => false, 'error' => 'A visit and the request text are needed.'];
        $included = self::lines($in['included'] ?? []);
        $extra = self::lines($in['extra'] ?? []);
        if (!$included && !$extra) {
            // Split the text the same way an inbound message is split.
            $plans = [];
            foreach ($visitIds as $vid) {
                $i = $this->visitInfo($vid);
                if ($i) $plans[] = ['service_type' => $i['service_type'], 'title' => $i['plan_title'], 'description' => ''];
            }
            $items = SpecialRequestMatcher::items($words) ?: [$words];
            $c = SpecialRequestMatcher::classify($items, SpecialRequestMatcher::scopeOf($plans));
            [$included, $extra] = [$c['included'], $c['extra']];
        }
        $visits = array_map(fn($v) => ['visit_id' => $v], $visitIds);
        $this->db->prepare('INSERT INTO special_requests
              (status, head, source, contact_id, summary, client_words, included_items, extra_items, proposal_json,
               received_at, created_by, created_at)
              VALUES (\'pending\', \'otto\', \'manual\', ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                !empty($in['contact_id']) ? (int)$in['contact_id'] : null,
                mb_substr(SpecialRequestMatcher::summary($included, $extra), 0, 255), $words,
                implode("\n", $included), implode("\n", $extra), json_encode(['visits' => $visits]),
                $this->now(), $actorId, $this->now(),
            ]);
        $id = (int)$this->db->lastInsertId();
        return $this->confirm($id, $actorId, ['visit_ids' => $visitIds, 'included' => $included, 'extra' => $extra]);
    }

    public function dismiss(int $requestId, int $actorId): array
    {
        $req = $this->request($requestId);
        if (!$req) return ['ok' => false, 'error' => 'Request not found.'];
        if ($req['status'] !== 'pending') return ['ok' => false, 'error' => 'Already ' . $req['status'] . '.'];
        $this->db->prepare("UPDATE special_requests SET status = 'dismissed', decided_by = ?, decided_at = ? WHERE id = ?")
            ->execute([$actorId, $this->now(), $requestId]);
        $this->event($requestId, null, null, $actorId, 'dismiss', true, null);
        return ['ok' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Crew
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Everyone on a visit and the leader: visit_crew_assignments (role lead) → the stop's crew_id
     * (the lead on the schedule) → the visit's assigned_crew_id; everyone = all of those plus
     * calendar_stop_crew.
     * @return array{leader:?int, all:list<int>}
     */
    public function crewForVisit(int $visitId): array
    {
        $all = [];
        $leader = null;
        try {
            $st = $this->db->prepare('SELECT jv.assigned_crew_id, jv.stop_id, cs.crew_id
                                      FROM job_visits jv LEFT JOIN calendar_stops cs ON cs.id = jv.stop_id WHERE jv.id = ?');
            $st->execute([$visitId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $r = [];
        }
        try {
            $st = $this->db->prepare("SELECT user_id, role FROM visit_crew_assignments WHERE visit_id = ? ORDER BY CASE WHEN role = 'lead' THEN 0 ELSE 1 END, id");
            $st->execute([$visitId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                if ($leader === null && $a['role'] === 'lead') $leader = (int)$a['user_id'];
                $all[] = (int)$a['user_id'];
            }
        } catch (Throwable $e) { /* table not on this install */ }
        if (!empty($r['crew_id'])) { $leader = $leader ?? (int)$r['crew_id']; $all[] = (int)$r['crew_id']; }
        if (!empty($r['assigned_crew_id'])) { $leader = $leader ?? (int)$r['assigned_crew_id']; $all[] = (int)$r['assigned_crew_id']; }
        if (!empty($r['stop_id'])) {
            try {
                $st = $this->db->prepare('SELECT user_id FROM calendar_stop_crew WHERE stop_id = ? ORDER BY id');
                $st->execute([(int)$r['stop_id']]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $all[] = (int)$u;
            } catch (Throwable $e) { /* table not on this install */ }
        }
        $all = array_values(array_unique(array_filter($all)));
        if ($leader === null && $all) $leader = $all[0];
        return ['leader' => $leader, 'all' => $all];
    }

    /** @return list<string> */
    private function crewNames(array $userIds): array
    {
        if (!$userIds) return [];
        $in = implode(',', array_fill(0, count($userIds), '?'));
        try {
            $st = $this->db->prepare("SELECT full_name FROM users WHERE id IN ($in)");
            $st->execute(array_values($userIds));
            return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Special requests on these visits, as the crew see them. Empty when the feature doesn't
     * apply to $userId (the client code is then inert).
     * @return array<int, list<array>> visit_id => payloads
     */
    public function forVisits(array $visitIds, int $userId): array
    {
        $visitIds = array_values(array_unique(array_filter(array_map('intval', $visitIds))));
        if (!$visitIds || !$this->appliesToUser($userId)) return [];
        $in = implode(',', array_fill(0, count($visitIds), '?'));
        $st = $this->db->prepare("SELECT srv.id, srv.visit_id FROM special_request_visits srv
                                  JOIN special_requests r ON r.id = srv.request_id
                                  WHERE srv.visit_id IN ($in) AND srv.status <> 'cancelled' AND r.status IN ('attached', 'closed')
                                  ORDER BY srv.id");
        $st->execute($visitIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['visit_id']][] = $this->payload((int)$row['id'], $userId);
        }
        return $out;
    }

    /**
     * Full crew payload for one attached visit. Crew names are shown (in-house record).
     */
    public function payload(int $srvId, int $userId): array
    {
        $st = $this->db->prepare('SELECT srv.*, r.head, r.source, r.contact_id, r.client_words, r.included_items AS r_included,
                                         r.extra_items AS r_extra, r.received_at, r.status AS request_status
                                  FROM special_request_visits srv JOIN special_requests r ON r.id = srv.request_id
                                  WHERE srv.id = ?');
        $st->execute([$srvId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return [];
        $included = self::lines($s['included_items'] ?? $s['r_included'] ?? '');
        $extra = self::lines($s['extra_items'] ?? $s['r_extra'] ?? '');
        $info = $this->visitInfo((int)$s['visit_id']) ?: [];
        $from = $this->contactLabel($s['contact_id'] ? (int)$s['contact_id'] : null);

        $acks = [];
        $mine = false;
        $st = $this->db->prepare('SELECT a.user_id, a.acknowledged_at, u.full_name FROM special_request_acks a
                                  LEFT JOIN users u ON u.id = a.user_id WHERE a.request_visit_id = ? ORDER BY a.acknowledged_at');
        $st->execute([$srvId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $acks[] = ['user_id' => (int)$a['user_id'], 'name' => (string)($a['full_name'] ?? ''), 'at' => $a['acknowledged_at']];
            if ((int)$a['user_id'] === $userId) $mine = true;
        }
        $head = self::HEADS[$s['head']] ?? self::HEADS['otto'];
        $outcomeBy = $s['outcome_by'] ? ($this->crewNames([(int)$s['outcome_by']])[0] ?? '') : '';

        return [
            'request_visit_id' => (int)$s['id'],
            'request_id'       => (int)$s['request_id'],
            'visit_id'         => (int)$s['visit_id'],
            'stop_id'          => isset($info['stop_id']) ? (int)$info['stop_id'] : null,
            'head'             => (string)$s['head'],
            'head_name'        => $head['name'],
            'head_role'        => $head['role'],
            'head_photo'       => self::headPhoto((string)$s['head']),
            'source'           => (string)$s['source'],
            'from_name'        => $from['name'],
            'company_name'     => $from['company'],
            'received_at'      => $s['received_at'],
            'client_words'     => (string)($s['client_words'] ?? ''),
            'included'         => $included,
            'extra'            => $extra,
            'summary'          => SpecialRequestMatcher::summary($included, $extra),
            'address'          => trim((string)($info['address'] ?? '')),
            'property_name'    => $info['property_name'] ?? null,
            'scheduled_date'   => $info['scheduled_date'] ?? null,
            'status'           => (string)$s['status'],
            'attached_at'      => $s['attached_at'],
            'acked_by_me'      => $mine,
            'acks'             => $acks,
            'outcome'          => $s['status'] === 'attached' ? null : [
                'status'            => (string)$s['status'],
                'reason'            => $s['outcome_reason'],
                'extra_description' => $s['extra_description'],
                'extra_minutes'     => $s['extra_minutes'] !== null ? (int)$s['extra_minutes'] : null,
                'extra_amount'      => $s['extra_amount'] !== null ? (float)$s['extra_amount'] : null,
                'billing'           => $s['extra_ref'],
                'by_name'           => $outcomeBy,
                'at'                => $s['outcome_at'],
            ],
        ];
    }

    /**
     * Open requests this user has NOT acknowledged on the visit, with each one's attached_at.
     * @return list<array{request_visit_id:int, attached_at:string, acked:bool}>
     */
    public function openForGate(int $visitId, int $userId): array
    {
        $st = $this->db->prepare("SELECT srv.id, srv.attached_at,
                                         (SELECT COUNT(*) FROM special_request_acks a WHERE a.request_visit_id = srv.id AND a.user_id = ?) AS acked
                                  FROM special_request_visits srv JOIN special_requests r ON r.id = srv.request_id
                                  WHERE srv.visit_id = ? AND srv.status = 'attached' AND r.status = 'attached'");
        $st->execute([$userId, $visitId]);
        return array_map(fn($r) => [
            'request_visit_id' => (int)$r['id'],
            'attached_at'      => (string)$r['attached_at'],
            'acked'            => (int)$r['acked'] > 0,
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** "Got it" — this crew member has read it. Idempotent. */
    public function ack(int $srvId, int $userId, string $client = 'web'): array
    {
        if (!$this->appliesToUser($userId)) return ['ok' => false, 'error' => 'Special requests are switched off.'];
        $p = $this->payload($srvId, $userId);
        if (!$p) return ['ok' => false, 'error' => 'Request not found.'];
        if (!$p['acked_by_me']) {
            try {
                $this->db->prepare('INSERT INTO special_request_acks (request_visit_id, user_id, acknowledged_at, client) VALUES (?, ?, ?, ?)')
                    ->execute([$srvId, $userId, $this->now(), in_array($client, ['web', 'ios'], true) ? $client : 'web']);
                $this->event($p['request_id'], $srvId, $p['visit_id'], $userId, 'ack', true, $client);
            } catch (Throwable $e) { /* a double tap — already recorded */ }
        }
        return ['ok' => true, 'request' => $this->payload($srvId, $userId)];
    }

    /**
     * The crew's answer: done / not_done (reason) / extra_done (what + minutes → the visit's extras,
     * added once). Writes a visit note. A later answer replaces an earlier one, but extra minutes
     * are never added to the visit twice.
     */
    public function outcome(int $srvId, int $userId, string $outcome, string $reason = '', string $extraDesc = '', int $extraMinutes = 0): array
    {
        if (!$this->appliesToUser($userId)) return ['ok' => false, 'error' => 'Special requests are switched off.'];
        if (!in_array($outcome, self::OUTCOMES, true)) return ['ok' => false, 'error' => 'Unknown answer.'];
        $p = $this->payload($srvId, $userId);
        if (!$p) return ['ok' => false, 'error' => 'Request not found.'];
        $reason = trim($reason);
        $extraDesc = trim($extraDesc);
        if ($outcome === 'not_done' && $reason === '') return ['ok' => false, 'error' => 'Say why it wasn\'t done.'];
        if ($outcome === 'extra_done' && $extraMinutes <= 0) return ['ok' => false, 'error' => 'How many minutes did the extra work take?'];
        if ($outcome === 'extra_done' && $extraDesc === '') {
            $extraDesc = $p['extra'] ? implode('; ', $p['extra']) : 'Special request extra work';
        }

        $st = $this->db->prepare('SELECT extra_folded_at FROM special_request_visits WHERE id = ?');
        $st->execute([$srvId]);
        $folded = $st->fetchColumn();
        if ($folded && $outcome !== 'extra_done') {
            return ['ok' => false, 'error' => 'The extra work is already on the visit\'s bill — ask the office to change it.'];
        }

        $amount = null;
        if ($outcome === 'extra_done') {
            $amount = $this->extrasAmount($extraMinutes);
        }
        if ($folded) {
            // Already billed once — keep the minutes that went on the bill.
            $this->db->prepare('UPDATE special_request_visits SET outcome_reason = ?, outcome_by = ?, outcome_at = ? WHERE id = ?')
                ->execute([$reason ?: null, $userId, $this->now(), $srvId]);
        } else {
            $this->db->prepare('UPDATE special_request_visits SET status = ?, outcome_reason = ?, extra_description = ?, extra_minutes = ?,
                                       extra_amount = ?, outcome_by = ?, outcome_at = ? WHERE id = ?')
                ->execute([$outcome, $reason ?: null, $outcome === 'extra_done' ? mb_substr($extraDesc, 0, 255) : null,
                    $outcome === 'extra_done' ? $extraMinutes : null, $amount, $userId, $this->now(), $srvId]);
        }

        $label = ['done' => 'Done', 'not_done' => 'Not done', 'extra_done' => 'Extra work done'][$outcome];
        $note = 'Special request — ' . $label . ': ' . $p['summary']
            . ($reason !== '' ? ' — ' . $reason : '')
            . ($outcome === 'extra_done' ? ' — extra: ' . $extraDesc . ' (' . $extraMinutes . ' min)' : '');
        $this->visitNote($p['visit_id'], $userId, $note);
        $this->event($p['request_id'], $srvId, $p['visit_id'], $userId, 'outcome', true, $label, mb_substr($note, 0, 500));

        if ($outcome === 'extra_done') {
            $this->foldExtras($p['visit_id']);
        }
        $this->maybeClose($p['request_id']);
        return ['ok' => true, 'request' => $this->payload($srvId, $userId)];
    }

    private function extrasAmount(int $minutes): float
    {
        $file = defined('APP_ROOT') ? APP_ROOT . '/Modules/Invoices/Services/InvoiceFromVisitService.php'
            : __DIR__ . '/../../Invoices/Services/InvoiceFromVisitService.php';
        if (is_file($file)) {
            require_once $file;
            return (float)(new InvoiceFromVisitService($this->db))->computeExtrasAmount($minutes)['amount'];
        }
        $rate = (float)($this->setting('extras_rate_per_5min', '5.00') ?: 5.00);
        return round((int)ceil($minutes / 5) * $rate, 2);
    }

    /**
     * Put extra-work minutes on the visit's extras (the columns every invoice path reads) — ONCE
     * per answer (extra_folded_at). Only once the visit is completed: the completion paths
     * (timer stop, pow-actions end_visit) overwrite extras with the completion sheet, so they
     * call this right after they write. A visit that already has an invoice is never changed;
     * the answer is marked needs_billing for the office instead.
     */
    public function foldExtras(int $visitId): int
    {
        try {
            $st = $this->db->prepare("SELECT id, extra_minutes, extra_description FROM special_request_visits
                                      WHERE visit_id = ? AND status = 'extra_done' AND extra_folded_at IS NULL AND extra_minutes > 0");
            $st->execute([$visitId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return 0; // migration not run — nothing to fold
        }
        if (!$rows) return 0;
        $st = $this->db->prepare('SELECT status, invoice_id, extras_minutes, extras_note FROM job_visits WHERE id = ?');
        $st->execute([$visitId]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v || $v['status'] !== 'completed') return 0;
        if (!empty($v['invoice_id'])) {
            foreach ($rows as $r) {
                $this->db->prepare("UPDATE special_request_visits SET extra_ref = 'needs_billing' WHERE id = ?")->execute([(int)$r['id']]);
            }
            return 0;
        }
        $minutes = (int)($v['extras_minutes'] ?? 0);
        $note = trim((string)($v['extras_note'] ?? ''));
        foreach ($rows as $r) {
            $minutes += (int)$r['extra_minutes'];
            $note = trim($note . ($note !== '' ? '; ' : '') . 'Special request: ' . $r['extra_description'] . ' (' . (int)$r['extra_minutes'] . ' min)');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE job_visits SET extras_minutes = ?, extras_amount = ?, extras_note = ? WHERE id = ?')
                ->execute([$minutes, $this->extrasAmount($minutes), mb_substr($note, 0, 1000), $visitId]);
            foreach ($rows as $r) {
                $this->db->prepare("UPDATE special_request_visits SET extra_folded_at = ?, extra_ref = 'visit_extras' WHERE id = ?")
                    ->execute([$this->now(), (int)$r['id']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('SpecialRequestService::foldExtras: ' . $e->getMessage());
            return 0;
        }
        return count($rows);
    }

    /** All attached visits answered → the request is closed. */
    private function maybeClose(int $requestId): void
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM special_request_visits WHERE request_id = ? AND status = 'attached'");
        $st->execute([$requestId]);
        if ((int)$st->fetchColumn() === 0) {
            $this->db->prepare("UPDATE special_requests SET status = 'closed' WHERE id = ? AND status = 'attached'")->execute([$requestId]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Telling the crew
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * SMS text for the crew leader. Carrier gateway rules: plain ASCII, ≤160, NO links or
     * anything that looks like a domain, tell them to open the app; office number only if room.
     */
    public static function smsText(string $address, string $when, array $included, array $extra): string
    {
        $addr = self::shortAddress($address);
        $head = 'Mowology: special request at ' . $addr . ($when !== '' ? ' ' . $when : '');
        $tail = ' Open the app.';
        $phone = ' ' . self::OFFICE_PHONE;
        $bits = [];
        if ($included) $bits[] = self::gist($included[0]);
        if ($extra) $bits[] = self::gist($extra[0]) . ' (extra, decide on site)';
        $body = $bits ? ' - ' . implode(' + ', $bits) . '.' : '.';
        $text = self::smsSafe($head . $body . $tail);
        if (mb_strlen($text) > self::SMS_MAX) {
            // Shorten the items until it fits.
            foreach ([40, 28, 18] as $n) {
                $bits = [];
                if ($included) $bits[] = self::gist($included[0], $n);
                if ($extra) $bits[] = self::gist($extra[0], $n) . ' (extra)';
                $text = self::smsSafe($head . ' - ' . implode(' + ', $bits) . '.' . $tail);
                if (mb_strlen($text) <= self::SMS_MAX) break;
            }
        }
        if (mb_strlen($text) > self::SMS_MAX) {
            $text = self::smsSafe($head . '. Read it in the app before you start.');
        }
        if (mb_strlen($text) > self::SMS_MAX) {
            $text = self::smsSafe('Mowology: special request on a visit today. Read it in the app before you start.');
        }
        if (mb_strlen($text . $phone) <= self::SMS_MAX) $text .= $phone;
        return $text;
    }

    /** "2205 W 45th Ave, Vancouver" → "2205 W 45th". */
    public static function shortAddress(string $a): string
    {
        $a = trim(explode(',', $a)[0]);
        $a = preg_replace('/\s+(avenue|ave|street|st|road|rd|drive|dr|boulevard|blvd|crescent|cres|place|pl|court|crt|ct|lane|ln|way)\.?$/i', '', $a);
        $a = preg_replace('/\bwest\b/i', 'W', $a);
        $a = preg_replace('/\beast\b/i', 'E', $a);
        return trim($a);
    }

    /** First words of an item, lower-cased, no trailing filler. */
    public static function gist(string $item, int $max = 60): string
    {
        $item = trim(preg_replace('/\s+/', ' ', $item));
        $item = preg_replace('/^(please\s+)?/i', '', $item);
        $item = mb_strtolower(mb_substr($item, 0, 1)) . mb_substr($item, 1);
        if (mb_strlen($item) <= $max) return rtrim($item, ' .');
        $cut = mb_substr($item, 0, $max);
        $sp = mb_strrpos($cut, ' ');
        return rtrim($sp > 10 ? mb_substr($cut, 0, $sp) : $cut, ' ,.;') . '...';
    }

    /** Plain ASCII, no URLs / domains / emails, one line. */
    public static function smsSafe(string $t): string
    {
        $t = strtr($t, ['—' => '-', '–' => '-', '…' => '...', '‘' => "'", '’' => "'", '“' => '"', '”' => '"', '&' => 'and', '·' => '-']);
        $t = preg_replace('~\b(?:https?://|www\.)\S*~i', '', $t);
        $t = preg_replace('/\S+@\S+/', '', $t);
        $t = preg_replace('/\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|ca|net|org|io|co|ly|me|app|info|biz|us|gl|gle)\b\S*/i', '', $t);
        $t = preg_replace('/[^\x20-\x7E]/', '', $t);
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /** today / tomorrow / "Mon Oct 12". */
    public function whenLabel(?string $date): string
    {
        if (!$date) return '';
        if ($date === $this->today()) return 'today';
        if ($date === date('Y-m-d', strtotime($this->today() . ' +1 day'))) return 'tomorrow';
        return date('D M j', strtotime($date));
    }

    /** After Tim attaches it: SMS the leader, push everyone on the visit. Logged; never twice. */
    public function notifyAttached(int $srvId): array
    {
        $p = $this->payload($srvId, 0);
        if (!$p) return [];
        $crew = $this->crewForVisit($p['visit_id']);
        $crew['all'] = array_values(array_filter($crew['all'], fn($u) => $this->appliesToUser($u)));
        $leader = $crew['leader'] !== null && $this->appliesToUser($crew['leader']) ? $crew['leader'] : null;
        $out = ['sms' => null, 'push' => []];

        if ($leader !== null && !$this->alreadySent($srvId, $leader, 'sms', 'attach')) {
            $text = self::smsText($p['address'], $this->whenLabel($p['scheduled_date']), $p['included'], $p['extra']);
            $phone = '';
            try {
                $st = $this->db->prepare('SELECT phone FROM users WHERE id = ?');
                $st->execute([$leader]);
                $phone = (string)$st->fetchColumn();
            } catch (Throwable $e) { /* no phone */ }
            if (preg_replace('/\D/', '', $phone) === '') {
                $this->event($p['request_id'], $srvId, $p['visit_id'], $leader, 'sms', false, 'attach: crew leader has no phone on file', $text);
                $out['sms'] = ['ok' => false, 'error' => 'no phone'];
            } else {
                $r = $this->sendSms($phone, $text);
                $this->event($p['request_id'], $srvId, $p['visit_id'], $leader, 'sms', !empty($r['success']),
                    'attach' . (!empty($r['success']) ? '' : ': ' . implode('; ', (array)($r['errors'] ?? []))), $text);
                $out['sms'] = ['ok' => !empty($r['success']), 'user_id' => $leader, 'text' => $text];
            }
        }
        $out['push'] = $this->pushCrew($p, $crew['all'], 'attach');
        return $out;
    }

    /**
     * The visit is starting (timer start / photo / auto-arrival attempt) — push the request again
     * to everyone on it, once per attached visit.
     */
    public function notifyArrival(int $visitId): void
    {
        try {
            $st = $this->db->prepare("SELECT srv.id FROM special_request_visits srv JOIN special_requests r ON r.id = srv.request_id
                                      WHERE srv.visit_id = ? AND srv.status = 'attached' AND r.status = 'attached' AND srv.arrival_notified_at IS NULL");
            $st->execute([$visitId]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return;
        }
        foreach ($ids as $srvId) {
            $srvId = (int)$srvId;
            $this->db->prepare('UPDATE special_request_visits SET arrival_notified_at = ? WHERE id = ? AND arrival_notified_at IS NULL')
                ->execute([$this->now(), $srvId]);
            $p = $this->payload($srvId, 0);
            $crew = array_values(array_filter($this->crewForVisit($visitId)['all'], fn($u) => $this->appliesToUser($u)));
            $this->pushCrew($p, $crew, 'arrival');
        }
    }

    private function pushCrew(array $p, array $userIds, string $kind): array
    {
        $userIds = array_values(array_filter($userIds, fn($u) => !$this->alreadySent($p['request_visit_id'], $u, 'push', $kind)));
        if (!$userIds) return [];
        $who = trim($p['from_name'] . ($p['company_name'] ? ' (' . $p['company_name'] . ')' : ''));
        $title = $p['head_name'] . ': ' . ($kind === 'arrival' ? 'before you start — ' : 'special request — ') . self::shortAddress($p['address']);
        $body = ($who !== '' ? $who . ': ' : '') . $p['summary'] . ' Read it and tap Got it before you start.';
        $data = [
            'screen' => 'schedule', 'type' => 'special_request', 'visit_id' => $p['visit_id'],
            'stop_id' => $p['stop_id'], 'date' => $p['scheduled_date'], 'request_visit_id' => $p['request_visit_id'],
            'head' => $p['head'], 'head_name' => $p['head_name'],
            // Android (FCM) shows the head's face on the notification; iOS opens the visit.
            'image_url' => self::FACE_BASE . (isset(self::HEADS[$p['head']]) ? $p['head'] : 'otto') . '.jpg',
        ];
        $ok = true; $err = null;
        try {
            if ($this->push) {
                ($this->push)($userIds, $title, $body, $data);
            } else {
                $base = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
                require_once $base . '/Services/Push/ApnsService.php';
                require_once $base . '/Services/Push/PushDispatcher.php';
                PushDispatcher::notifyUsers($userIds, $title, mb_substr($body, 0, 230), $data);
            }
        } catch (Throwable $e) {
            $ok = false; $err = $e->getMessage();
        }
        foreach ($userIds as $u) {
            $this->event($p['request_id'], $p['request_visit_id'], $p['visit_id'], $u, 'push', $ok, $kind . ($err ? ': ' . $err : ''), mb_substr($title . ' | ' . $body, 0, 500));
        }
        return $userIds;
    }

    private function sendSms(string $phone, string $text): array
    {
        if ($this->sms) return ($this->sms)($phone, $text);
        $base = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        require_once $base . '/Services/Messaging/MessagingService.php';
        // Native mail() to the carrier gateways — never PHPMailer (CLAUDE.md rule 8).
        return sendSms($phone, $text);
    }

    /** Has this (attached visit, user) already got an event of this kind (any outcome)? */
    public function hasEvent(int $srvId, int $userId, string $kind, string $detailPrefix): bool
    {
        try {
            $st = $this->db->prepare('SELECT COUNT(*) FROM special_request_events WHERE request_visit_id = ? AND user_id = ? AND kind = ? AND detail LIKE ?');
            $st->execute([$srvId, $userId, $kind, $detailPrefix . '%']);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function alreadySent(int $srvId, int $userId, string $kind, string $what): bool
    {
        try {
            $st = $this->db->prepare('SELECT COUNT(*) FROM special_request_events WHERE request_visit_id = ? AND user_id = ? AND kind = ? AND ok = 1 AND detail LIKE ?');
            $st->execute([$srvId, $userId, $kind, $what . '%']);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Office (Yui / Otto cards)
    // ─────────────────────────────────────────────────────────────────────────

    /** Pending to confirm (with proposals) + the last week's answers + still-open attached ones. */
    public function office(?string $head = null): array
    {
        $since = date('Y-m-d H:i:s', strtotime($this->now()) - 7 * 86400);
        $args = [];
        $headSql = '';
        if ($head && isset(self::HEADS[$head])) { $headSql = ' AND r.head = ?'; $args[] = $head; }

        $st = $this->db->prepare("SELECT r.* FROM special_requests r WHERE r.status = 'pending'{$headSql} ORDER BY r.created_at DESC LIMIT 20");
        $st->execute($args);
        $pending = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $from = $this->contactLabel($r['contact_id'] ? (int)$r['contact_id'] : null);
            $prop = json_decode((string)$r['proposal_json'], true) ?: [];
            $pending[] = [
                'id' => (int)$r['id'], 'head' => $r['head'], 'source' => $r['source'],
                'head_name' => (self::HEADS[$r['head']] ?? self::HEADS['otto'])['name'],
                'head_role' => (self::HEADS[$r['head']] ?? self::HEADS['otto'])['role'],
                'head_photo' => self::headPhoto((string)$r['head']),
                'from_name' => $from['name'], 'company_name' => $from['company'], 'received_at' => $r['received_at'],
                'client_words' => $r['client_words'], 'summary' => $r['summary'],
                'included' => self::lines($r['included_items']), 'extra' => self::lines($r['extra_items']),
                'visits' => array_map(fn($v) => [
                    'visit_id' => (int)$v['visit_id'], 'date' => $v['scheduled_date'] ?? null,
                    'address' => $v['address'] ?? '', 'plan_number' => $v['plan_number'] ?? null,
                    'crew' => $v['crew'] ?? [], 'reasons' => $v['reasons'] ?? [],
                    'included' => $v['included'] ?? [], 'extra' => $v['extra'] ?? [],
                ], $prop['visits'] ?? []),
                'unmatched' => $prop['unmatched'] ?? [],
            ];
        }

        $st = $this->db->prepare("SELECT srv.id FROM special_request_visits srv JOIN special_requests r ON r.id = srv.request_id
                                  WHERE (srv.status = 'attached' OR srv.outcome_at >= ?){$headSql}
                                  ORDER BY srv.status = 'attached' DESC, srv.outcome_at DESC, srv.id DESC LIMIT 30");
        $st->execute(array_merge([$since], $args));
        $visits = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $p = $this->payload((int)$id, 0);
            if ($p) $visits[] = $p;
        }
        return ['enabled' => $this->enabled(), 'pending' => $pending, 'visits' => $visits];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    public function request(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM special_requests WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function visitInfo(int $visitId): ?array
    {
        $st = $this->db->prepare('SELECT jv.id, jv.visit_number, jv.scheduled_date, jv.status, jv.stop_id, jv.plan_id,
                                         jp.property_id, jp.title AS plan_title, jp.service_type, jp.plan_number,
                                         p.address, p.city, p.property_name
                                  FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                                  LEFT JOIN properties p ON p.id = jp.property_id WHERE jv.id = ?');
        $st->execute([$visitId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array{name:string, company:?string} */
    private function contactLabel(?int $contactId): array
    {
        if (!$contactId) return ['name' => '', 'company' => null];
        try {
            $st = $this->db->prepare('SELECT c.first_name, c.last_name, co.company_name FROM contacts c
                                      LEFT JOIN companies co ON co.id = c.company_id WHERE c.id = ?');
            $st->execute([$contactId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), 'company' => $r['company_name'] ?? null];
        } catch (Throwable $e) {
            return ['name' => '', 'company' => null];
        }
    }

    private function visitNote(int $visitId, int $userId, string $text): void
    {
        try {
            $this->db->prepare("INSERT INTO visit_notes (visit_id, note_type, content, is_visible_to_customer, created_by, created_at)
                                VALUES (?, 'customer_request', ?, 0, ?, ?)")
                ->execute([$visitId, mb_substr($text, 0, 2000), $userId ?: null, $this->now()]);
        } catch (Throwable $e) {
            error_log('SpecialRequestService visit note: ' . $e->getMessage());
        }
    }

    public function event(?int $requestId, ?int $srvId, ?int $visitId, ?int $userId, string $kind, bool $ok, ?string $detail, ?string $body = null): void
    {
        try {
            $this->db->prepare('INSERT INTO special_request_events (request_id, request_visit_id, visit_id, user_id, kind, ok, detail, body, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$requestId, $srvId, $visitId, $userId, $kind, $ok ? 1 : 0,
                    $detail === null ? null : mb_substr($detail, 0, 255), $body === null ? null : mb_substr($body, 0, 500), $this->now()]);
        } catch (Throwable $e) {
            error_log('SpecialRequestService event: ' . $e->getMessage());
        }
    }

    /** @return list<string> */
    public static function lines($v): array
    {
        if (is_array($v)) $list = $v;
        else $list = preg_split('/\r?\n/', (string)$v) ?: [];
        return array_values(array_filter(array_map(fn($s) => trim((string)$s), $list), fn($s) => $s !== ''));
    }
}
