<?php
/**
 * SendTimeService — when each person is most likely to read a campaign email.
 *
 * Learned per contact from what people DO, not from opens:
 *   reply  (an inbound sales_messages row)          weight 3
 *   click  (campaign_events click, or clicked_at)   weight 2
 *   open   (campaign_events open)                   weight 0.5 — and only a believable one:
 *          Apple Mail Privacy Protection opens every email itself through a proxy, so an open
 *          within 2 minutes of the send, or from the proxy's bare "Mozilla/5.0" user agent,
 *          is ignored.
 * A contact needs MIN_WEIGHT of evidence (one reply, or a click and two opens…) before Mia
 * trusts a learned hour; until then the segment default is used:
 *   property managers / stratas  Tue–Thu 9:30      (ops_settings mia_send_default_pm)
 *   homeowners                   Tue/Wed 7:30 pm, Sat 9:00 (ops_settings mia_send_default_home)
 * Format: "2,3,4@09:30" — ISO weekdays (1 Mon … 7 Sun) @ time; ";" separates groups.
 *
 * Results live in mia_send_times (migration 1195), recomputed nightly by mia_email_daily.
 * The campaign sender only sends a row whose scheduled_at has come.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class SendTimeService
{
    public const WEIGHTS = ['reply' => 3.0, 'click' => 2.0, 'open' => 0.5];
    public const MIN_WEIGHT = 3.0;
    public const MPP_SECONDS = 120;
    public const DEFAULT_PM = '2,3,4@09:30';
    public const DEFAULT_HOME = '2,3@19:30;6@09:00';
    public const LOOKBACK_DAYS = 730;

    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** An open Mia should not believe: Apple's proxy prefetch, or too soon after the send. */
    public static function isProxyOpen(?string $userAgent, ?string $openedAt, ?string $sentAt): bool
    {
        $ua = trim((string)$userAgent);
        if ($ua === '' || $ua === 'Mozilla/5.0' || stripos($ua, 'AppleMailProxy') !== false) return true;
        if ($openedAt && $sentAt) {
            $d = strtotime($openedAt) - strtotime($sentAt);
            if ($d >= 0 && $d < self::MPP_SECONDS) return true;
        }
        return false;
    }

    /**
     * Best weekday and hour from one contact's events.
     * @param array $events [['kind' => reply|click|open, 'at' => 'Y-m-d H:i:s', 'ua' => ?string, 'sent_at' => ?string]]
     * @return array{dow: int, hour: int, weight: float, events: int}|null  null = not enough evidence
     */
    public static function learn(array $events): ?array
    {
        $hours = array_fill(0, 24, 0.0);
        $days = array_fill(1, 7, 0.0);
        $total = 0.0;
        $n = 0;
        foreach ($events as $e) {
            $kind = (string)($e['kind'] ?? '');
            if (!isset(self::WEIGHTS[$kind])) continue;
            $t = strtotime((string)($e['at'] ?? ''));
            if ($t === false) continue;
            if ($kind === 'open' && self::isProxyOpen($e['ua'] ?? null, $e['at'] ?? null, $e['sent_at'] ?? null)) continue;
            $w = self::WEIGHTS[$kind];
            $h = (int)date('G', $t);
            $hours[$h] += $w;
            $hours[($h + 23) % 24] += $w / 2; // people read around a habit, not on the minute
            $hours[($h + 1) % 24] += $w / 2;
            $days[(int)date('N', $t)] += $w;
            $total += $w;
            $n++;
        }
        if ($total < self::MIN_WEIGHT) return null;
        $bestH = 0;
        foreach ($hours as $h => $v) if ($v > $hours[$bestH]) $bestH = $h;
        $bestD = 1;
        foreach ($days as $d => $v) if ($v > $days[$bestD]) $bestD = $d;
        return ['dow' => $bestD, 'hour' => $bestH, 'weight' => round($total, 2), 'events' => $n];
    }

    /**
     * "2,3@19:30;6@09:00" → [[2, '19:30'], [3, '19:30'], [6, '09:00']]. Bad parts are skipped.
     * @return array<int, array{0: int, 1: string}>
     */
    public static function parseSlots(string $spec): array
    {
        $out = [];
        foreach (explode(';', $spec) as $group) {
            if (!preg_match('/^\s*([1-7](?:\s*,\s*[1-7])*)\s*@\s*([01]?\d|2[0-3]):([0-5]\d)\s*$/', $group, $m)) continue;
            foreach (preg_split('/\s*,\s*/', trim($m[1])) as $d) {
                $out[] = [(int)$d, sprintf('%02d:%s', (int)$m[2], $m[3])];
            }
        }
        return $out;
    }

    /**
     * The first moment on or after $from that matches a slot, inside the send window.
     * Learned slots win when one fits the window; then the segment defaults; and if neither
     * fits (a short window), the window's start — never later than the window.
     * @param array $learned  [[dow, 'HH:MM'], …] (may be empty)
     * @param array $defaults [[dow, 'HH:MM'], …]
     */
    public static function nextSlot(DateTimeImmutable $from, array $learned, array $defaults, string $windowFrom, string $windowTo): DateTimeImmutable
    {
        $start = new DateTimeImmutable($windowFrom . ' 00:00:00');
        if ($from > $start) $start = $from;
        $end = new DateTimeImmutable($windowTo . ' 23:59:59');
        foreach ([$learned, $defaults] as $slots) {
            $best = null;
            foreach ($slots as [$dow, $hm]) {
                $day = $start->setTime(0, 0);
                for ($i = 0; $i < 14; $i++, $day = $day->modify('+1 day')) {
                    if ((int)$day->format('N') !== (int)$dow) continue;
                    [$h, $mi] = array_map('intval', explode(':', $hm));
                    $at = $day->setTime($h, $mi);
                    if ($at < $start) continue;
                    if ($at > $end) break;
                    if ($best === null || $at < $best) $best = $at;
                    break;
                }
            }
            if ($best) return $best;
        }
        return $start > $end ? $end : $start;
    }

    /** A learned [dow, hour] as a slot list. */
    public static function learnedSlots(?array $row): array
    {
        if (!$row || !isset($row['best_dow'], $row['best_hour'])) return [];
        return [[(int)$row['best_dow'], sprintf('%02d:00', (int)$row['best_hour'])]];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Database
    // ─────────────────────────────────────────────────────────────────────────

    public function defaults(string $segment): array
    {
        $key = $segment === 'pm' ? 'mia_send_default_pm' : 'mia_send_default_home';
        $spec = $segment === 'pm' ? self::DEFAULT_PM : self::DEFAULT_HOME;
        if ($this->db) {
            try {
                $q = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
                $q->execute([$key]);
                $v = trim((string)$q->fetchColumn());
                if ($v !== '' && self::parseSlots($v)) $spec = $v;
            } catch (Throwable $e) {}
        }
        return self::parseSlots($spec);
    }

    /** contact_id => learned row, for the given contacts. */
    public function learnedFor(array $contactIds): array
    {
        $out = [];
        $ids = array_values(array_unique(array_map('intval', $contactIds)));
        if (!$this->db || !$ids) return $out;
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $s = $this->db->prepare("SELECT contact_id, best_dow, best_hour FROM mia_send_times WHERE contact_id IN ($in)");
                $s->execute($chunk);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['contact_id']] = $r;
            }
        } catch (Throwable $e) { /* before migration 1195 */ }
        return $out;
    }

    /**
     * scheduled_at for each recipient: their learned slot, else their segment's default,
     * inside the window.
     * @param array $pmIds contact ids that are property managers / strata contacts
     * @return array<int, string> contact_id => 'Y-m-d H:i:s'
     */
    public function schedule(array $contactIds, array $pmIds, DateTimeImmutable $from, string $windowFrom, string $windowTo): array
    {
        $learned = $this->learnedFor($contactIds);
        $pm = array_flip(array_map('intval', $pmIds));
        $def = ['pm' => $this->defaults('pm'), 'home' => $this->defaults('home')];
        $out = [];
        foreach ($contactIds as $cid) {
            $cid = (int)$cid;
            $seg = isset($pm[$cid]) ? 'pm' : 'home';
            $out[$cid] = self::nextSlot($from, self::learnedSlots($learned[$cid] ?? null), $def[$seg], $windowFrom, $windowTo)->format('Y-m-d H:i:s');
        }
        return $out;
    }

    /** How many contacts have a learned time. */
    public function coverage(): int
    {
        if (!$this->db) return 0;
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM mia_send_times")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Nightly: relearn everyone with evidence. Returns how many have a learned time. */
    public function recompute(?DateTimeImmutable $now = null): int
    {
        if (!$this->db) return 0;
        $now = $now ?? new DateTimeImmutable();
        $since = $now->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d');
        $events = [];
        $add = function (int $cid, string $kind, ?string $at, ?string $ua = null, ?string $sentAt = null) use (&$events) {
            if ($cid > 0 && $at) $events[$cid][] = ['kind' => $kind, 'at' => $at, 'ua' => $ua, 'sent_at' => $sentAt];
        };
        $hasEvents = true;
        try {
            $s = $this->db->prepare("SELECT cs.contact_id, cs.sent_at, ce.kind, ce.at, ce.user_agent
                                     FROM campaign_events ce JOIN campaign_sends cs ON cs.id = ce.send_id
                                     WHERE ce.at >= ?");
            $s->execute([$since]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $add((int)$r['contact_id'], (string)$r['kind'], $r['at'], $r['user_agent'], $r['sent_at']);
        } catch (Throwable $e) {
            $hasEvents = false;
        }
        if (!$hasEvents) {
            // Before campaign_events: first clicks only (opens without a user agent can't be trusted).
            try {
                $s = $this->db->prepare("SELECT contact_id, clicked_at FROM campaign_sends WHERE clicked_at IS NOT NULL AND clicked_at >= ?");
                $s->execute([$since]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $add((int)$r['contact_id'], 'click', $r['clicked_at']);
            } catch (Throwable $e) {}
        }
        try {
            $s = $this->db->prepare("SELECT contact_id, sent_at FROM sales_messages WHERE direction = 'inbound' AND contact_id IS NOT NULL AND sent_at >= ?");
            $s->execute([$since]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $add((int)$r['contact_id'], 'reply', $r['sent_at']);
        } catch (Throwable $e) { /* no office@ log */ }

        $n = 0;
        try {
            $up = $this->db->prepare("INSERT INTO mia_send_times (contact_id, best_dow, best_hour, weight, events, computed_at) VALUES (?, ?, ?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE best_dow = VALUES(best_dow), best_hour = VALUES(best_hour), weight = VALUES(weight),
                                                              events = VALUES(events), computed_at = VALUES(computed_at)");
            foreach ($events as $cid => $list) {
                $l = self::learn($list);
                if (!$l) continue;
                $up->execute([$cid, $l['dow'], $l['hour'], $l['weight'], $l['events'], $now->format('Y-m-d H:i:s')]);
                $n++;
            }
        } catch (Throwable $e) {
            error_log('SendTimeService recompute: ' . $e->getMessage());
        }
        return $n;
    }
}
