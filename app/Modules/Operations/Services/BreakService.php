<?php
/**
 * BreakService — crew breaks: the break button (manual) and the phone's GPS (backup).
 *
 * MANUAL (2026-10-13, Tim): a person taps Start break / End break on the Time Clock screen
 * (iPhone TimeClockView, web/Android time-clock-widget.js). The row is source 'manual' with an open
 * break_end until End break, the clock-out, or the office closes it. While a manual break is open
 * the phone stops sharing location and the server ignores fixes (TrackingIngestService::classify).
 *
 * GPS (owner, 2026-10-10): a stop of BREAK_MIN_MINUTES+ during a shift that isn't at a client's
 * property or a work place (dump, supplier, yard) — the coffee shop, the lunch pull-in. Stops come
 * from each person's phone (crew_location_history), found and labelled the same way Otto reads the
 * truck's day (TripSegmentService::detectStops / label). Recorded as source 'gps' by run().
 * A GPS break that overlaps a tapped one is dropped — the tap wins, nothing is counted twice
 * (merge()).
 *
 * PAY: setting breaks_paid (ops_settings; Mowology = 1, pays breaks). Paid → break records never
 * change pay. Unpaid → TAPPED break minutes are taken off paid time before the BC overtime split
 * (unpaidMinutes()). A GPS guess never takes money off anyone's pay; it only counts for the BC flag.
 *
 * BC rule: no more than 5 hours without a 30-minute meal break. Otto flags a shift over 5 hours
 * with no 30-minute break (tapped or found) — complianceGap(), written at clock-out and nightly.
 * Truck logins (users.device_type 'truck') are vehicles: no breaks, no button, no flag.
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class BreakService
{
    public const BREAK_MIN_MINUTES = 20;
    public const BC_SHIFT_MINUTES  = 300;   // 5 hours
    public const BC_BREAK_MINUTES  = 30;
    /** Ignore stops this close to clock in / out (loading up, the drive home). */
    public const EDGE_MINUTES      = 15;
    /** A shift this long is a forgotten clock-out (Otto flags those already), not a long day. */
    public const FORGOT_CLOCK_OUT_MINUTES = 690;
    /** Places that are work, not a break. */
    public const WORK_PLACE_KINDS  = ['dump', 'supplier', 'yard', 'shop', 'fuel'];
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_GPS    = 'gps';
    /** "Still on break?" after this many minutes unless the setting says otherwise. */
    public const DEFAULT_REMINDER_MINUTES = 60;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Which labelled stops in a shift are breaks.
     * $stops: [start (unix), end (unix), label => [type, kind, name]]
     * @return array<int, array{start:int, end:int, minutes:int, where:string}>
     */
    public static function candidates(array $stops, int $clockIn, int $clockOut): array
    {
        $out = [];
        foreach ($stops as $s) {
            $min = (int)floor(($s['end'] - $s['start']) / 60);
            if ($min < self::BREAK_MIN_MINUTES) continue;
            if ($s['start'] < $clockIn + self::EDGE_MINUTES * 60 || $s['end'] > $clockOut - self::EDGE_MINUTES * 60) continue;
            $type = (string)($s['label']['type'] ?? 'unnamed');
            if ($type === 'property') continue;                                            // working on a job
            if ($type === 'place' && in_array((string)($s['label']['kind'] ?? ''), self::WORK_PLACE_KINDS, true)) continue;
            $out[] = ['start' => (int)$s['start'], 'end' => (int)$s['end'], 'minutes' => $min,
                      'where' => $type === 'place' ? (string)$s['label']['name'] : 'unnamed stop'];
        }
        return $out;
    }

    /** BC: a shift over 5 hours needs a 30-minute meal break. Null when fine, else the reason. */
    public static function complianceGap(int $shiftMinutes, array $breakMinutes): ?string
    {
        if ($shiftMinutes <= self::BC_SHIFT_MINUTES || $shiftMinutes >= self::FORGOT_CLOCK_OUT_MINUTES) return null;
        if ($breakMinutes && max($breakMinutes) >= self::BC_BREAK_MINUTES) return null;
        $h = floor($shiftMinutes / 60) . ' h ' . ($shiftMinutes % 60) . ' min';
        return $breakMinutes
            ? "{$h} shift; longest break was " . max($breakMinutes) . ' min (BC needs 30 min before 5 h)'
            : "{$h} shift with no break taken (BC needs a 30-min meal break before 5 h)";
    }

    /**
     * One shift's breaks as they count: tapped (manual) breaks always; a GPS-found break only when
     * it overlaps no tapped one (the tap wins, nothing counted twice). Overlapping taps (a replay)
     * are joined. Every break is clipped to the shift; an open break runs to the clock-out or $now.
     *
     * @param array<int, array{start:int, end:?int, source:string}> $breaks (extra keys kept)
     * @return array<int, array{start:int, end:int, minutes:int, source:string, open:bool}> by start
     */
    public static function merge(array $breaks, int $clockIn, ?int $clockOut, int $now): array
    {
        $limit = $clockOut ?? $now;
        $clip = static function (array $b) use ($clockIn, $limit): ?array {
            $open = $b['end'] === null;
            $s = max((int)$b['start'], $clockIn);
            $e = min($open ? $limit : (int)$b['end'], $limit);
            if ($e <= $s) return null;
            return ['start' => $s, 'end' => $e, 'source' => (string)$b['source'], 'open' => $open] + $b;
        };
        $manual = [];
        $gps = [];
        foreach ($breaks as $b) {
            $c = $clip($b);
            if ($c === null) continue;
            if ($c['source'] === self::SOURCE_MANUAL) $manual[] = $c; else $gps[] = $c;
        }
        usort($manual, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $joined = [];
        foreach ($manual as $m) {
            $n = count($joined);
            if ($n && $m['start'] <= $joined[$n - 1]['end']) {
                $joined[$n - 1]['end'] = max($joined[$n - 1]['end'], $m['end']);
                $joined[$n - 1]['open'] = $joined[$n - 1]['open'] || $m['open'];
                continue;
            }
            $joined[] = $m;
        }
        $out = $joined;
        foreach ($gps as $g) {
            foreach ($joined as $m) {
                if ($g['start'] < $m['end'] && $m['start'] < $g['end']) continue 2;   // the tap wins
            }
            $out[] = $g;
        }
        usort($out, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        foreach ($out as &$b) $b['minutes'] = (int)floor(($b['end'] - $b['start']) / 60);
        unset($b);
        return $out;
    }

    /**
     * Minutes taken off paid time for one shift. Paid breaks (Mowology): 0. Unpaid: the TAPPED
     * breaks only — a GPS guess never takes money off anyone's pay.
     */
    public static function unpaidMinutes(array $merged, bool $breaksPaid): int
    {
        if ($breaksPaid) return 0;
        $n = 0;
        foreach ($merged as $b) {
            if (($b['source'] ?? '') === self::SOURCE_MANUAL) $n += (int)$b['minutes'];
        }
        return $n;
    }

    /** Paid minutes of a shift: its clocked minutes less unpaid break minutes, never below zero. */
    public static function paidMinutes(int $shiftMinutes, int $unpaidMinutes): int
    {
        return max(0, $shiftMinutes - max(0, $unpaidMinutes));
    }

    /** "Still on break?" interval from the setting, kept between 5 minutes and 4 hours. */
    public static function reminderMinutesFrom(?string $raw): int
    {
        $n = (int)($raw ?? self::DEFAULT_REMINDER_MINUTES);
        return $n <= 0 ? self::DEFAULT_REMINDER_MINUTES : max(5, min(240, $n));
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    private function setting(string $key, string $default): string
    {
        try {
            $st = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM time_clock_breaks LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Settings ────────────────────────────────────────────────────────────

    /** Mowology pays breaks (1). Another business may not (0): tapped breaks come off paid time. */
    public function breaksPaid(): bool
    {
        return $this->setting('breaks_paid', '1') !== '0';
    }

    public function reminderMinutes(): int
    {
        return self::reminderMinutesFrom($this->setting('break_reminder_minutes', (string)self::DEFAULT_REMINDER_MINUTES));
    }

    /** Kill switch for the crew break button (ops_settings break_button_enabled, migration 1358). */
    public function buttonEnabled(): bool
    {
        return $this->setting('break_button_enabled', '1') === '1';
    }

    /** People take breaks; a truck login (device_type 'truck') is a vehicle (560e8e21). */
    public function isPerson(int $userId): bool
    {
        try {
            $st = $this->db->prepare("SELECT COALESCE(device_type, 'personal') FROM users WHERE id = ?");
            $st->execute([$userId]);
            $t = $st->fetchColumn();
            return $t !== false && (string)$t !== 'truck';
        } catch (Throwable $e) {
            return true;
        }
    }

    /** What the Time Clock screens need (status payloads of both clock APIs). */
    public function clientConfig(int $userId): array
    {
        $open = $this->ready() ? $this->openBreak($userId) : null;
        return [
            'breaks'   => [
                'allowed'          => $this->ready() && $this->buttonEnabled() && $this->isPerson($userId),
                'paid'             => $this->breaksPaid(),
                'reminder_minutes' => $this->reminderMinutes(),
            ],
            'on_break' => $open ? self::publicBreak($open) : null,
        ];
    }

    public static function publicBreak(array $row): array
    {
        return ['id' => (int)$row['id'], 'started_at' => (string)$row['break_start'], 'entry_id' => (int)$row['entry_id']];
    }

    // ── The break button ────────────────────────────────────────────────────

    /** The person's running tapped break on their OPEN shift, or null. */
    public function openBreak(int $userId): ?array
    {
        try {
            $st = $this->db->prepare("
                SELECT b.* FROM time_clock_breaks b
                JOIN time_clock_entries e ON e.id = b.entry_id AND e.clock_out IS NULL AND e.status = 'active'
                WHERE b.user_id = ? AND b.source = 'manual' AND b.break_end IS NULL
                ORDER BY b.break_start DESC LIMIT 1
            ");
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** The shift a tap at $at belongs to: the open shift, else a closed one that contains it (a replay). */
    private function entryAt(int $userId, string $at): ?array
    {
        $st = $this->db->prepare("
            SELECT id, clock_in, clock_out FROM time_clock_entries
            WHERE user_id = ? AND status IN ('active', 'completed', 'edited') AND clock_in <= ?
              AND (clock_out IS NULL OR clock_out > ?)
            ORDER BY (clock_out IS NULL) DESC, clock_in DESC LIMIT 1
        ");
        $st->execute([$userId, $at, $at]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Start break. Server-first; a phone that had no signal sends the tap time as client_time
     * (OfflineTime decides whether to trust it). Idempotent: already on break → the same break.
     *
     * @return array{ok:bool, status:int, error:?string, break:?array, already:bool, recorded:bool, time_flag:?string, note:?string}
     */
    public function start(int $userId, ?string $clientTime, ?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $res = ['ok' => false, 'status' => 200, 'error' => null, 'break' => null, 'already' => false,
                'recorded' => true, 'time_flag' => null, 'note' => null];
        if (!$this->ready()) return ['status' => 503, 'error' => 'Breaks are not set up yet (migration 1358).'] + $res;
        if (!$this->isPerson($userId)) return ['status' => 403, 'error' => 'Breaks are for people — the truck login has no break.'] + $res;
        $this->closeOrphans($userId);
        if ($open = $this->openBreak($userId)) {
            return ['ok' => true, 'already' => true, 'break' => self::publicBreak($open)] + $res;
        }
        if (!class_exists('OfflineTime')) require_once dirname(__DIR__, 2) . '/Team/Services/OfflineTime.php';
        $active = $this->entryAt($userId, $now);
        $when = OfflineTime::accept($clientTime, $now, $active ? (string)$active['clock_in'] : null);
        if ($when['reject']) {
            return ['ok' => true, 'recorded' => false, 'time_flag' => $when['flag'], 'note' => $when['note']] + $res;
        }
        $entry = $this->entryAt($userId, $when['at']);
        if (!$entry) return ['status' => 409, 'error' => 'You are not clocked in.'] + $res;
        // The same tap replayed after it was filed (lost response): same start, same shift → same break.
        $dup = $this->db->prepare("SELECT * FROM time_clock_breaks WHERE user_id = ? AND entry_id = ? AND source = 'manual' AND break_start = ? LIMIT 1");
        $dup->execute([$userId, (int)$entry['id'], $when['at']]);
        if ($row = $dup->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => true, 'already' => true, 'break' => self::publicBreak($row)] + $res;
        }
        $this->db->prepare("
            INSERT INTO time_clock_breaks (entry_id, user_id, break_start, break_end, minutes, source, place, time_note)
            VALUES (?, ?, ?, NULL, 0, 'manual', NULL, ?)
        ")->execute([(int)$entry['id'], $userId, $when['at'], $when['note'] ? mb_substr((string)$when['note'], 0, 255) : null]);
        $id = (int)$this->db->lastInsertId();
        return ['ok' => true, 'break' => ['id' => $id, 'started_at' => $when['at'], 'entry_id' => (int)$entry['id']],
                'time_flag' => $when['flag'], 'note' => $when['note']] + $res;
    }

    /**
     * End break (the person's tap). No running break → a no-op success (a replay, or the clock-out
     * already ended it). Never ends before it started, never after the shift's clock-out.
     */
    public function end(int $userId, ?string $clientTime, string $endedBy = 'tap', ?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $res = ['ok' => true, 'status' => 200, 'error' => null, 'break' => null, 'already' => false,
                'recorded' => true, 'time_flag' => null, 'note' => null, 'minutes' => 0];
        if (!$this->ready()) return ['ok' => false, 'status' => 503, 'error' => 'Breaks are not set up yet (migration 1358).'] + $res;
        $open = $this->openBreak($userId);
        if (!$open) {
            // A queued End break that reaches the server after the shift closed: end it at the tap.
            $st = $this->db->prepare("SELECT * FROM time_clock_breaks WHERE user_id = ? AND source = 'manual' AND break_end IS NULL ORDER BY break_start DESC LIMIT 1");
            $st->execute([$userId]);
            $open = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$open) return ['already' => true] + $res;
        }
        if (!class_exists('OfflineTime')) require_once dirname(__DIR__, 2) . '/Team/Services/OfflineTime.php';
        $when = OfflineTime::accept($clientTime, $now, (string)$open['break_start']);
        if ($when['reject']) {
            return ['recorded' => false, 'time_flag' => $when['flag'], 'note' => $when['note']] + $res;
        }
        $minutes = $this->close((int)$open['id'], $when['at'], $endedBy, $when['note']);
        return ['break' => self::publicBreak($open), 'minutes' => $minutes, 'time_flag' => $when['flag'], 'note' => $when['note']] + $res;
    }

    /** Close one break at $at (clipped to its start and its shift's clock-out). Returns minutes. */
    private function close(int $breakId, string $at, string $endedBy, ?string $note = null): int
    {
        $st = $this->db->prepare("
            SELECT b.break_start, e.clock_out FROM time_clock_breaks b
            JOIN time_clock_entries e ON e.id = b.entry_id WHERE b.id = ?
        ");
        $st->execute([$breakId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return 0;
        $end = $at;
        if ($r['clock_out'] !== null && strtotime((string)$r['clock_out']) < strtotime($end)) $end = (string)$r['clock_out'];
        if (strtotime($end) < strtotime((string)$r['break_start'])) $end = (string)$r['break_start'];
        $min = (int)floor((strtotime($end) - strtotime((string)$r['break_start'])) / 60);
        $this->db->prepare("
            UPDATE time_clock_breaks SET break_end = ?, minutes = ?, ended_by = ?,
                   time_note = NULLIF(TRIM(CONCAT(COALESCE(time_note, ''), ' ', COALESCE(?, ''))), '')
            WHERE id = ? AND break_end IS NULL
        ")->execute([$end, $min, mb_substr($endedBy, 0, 20), $note, $breakId]);
        return $min;
    }

    /**
     * A tapped break still open on a shift that has ended (clock-out, auto clock-out, office void)
     * ends at that clock-out. clockOut() calls this; start() calls it so an old one never shows.
     */
    public function closeOrphans(?int $userId = null): int
    {
        if (!$this->ready()) return 0;
        $sql = "SELECT b.id, e.clock_out FROM time_clock_breaks b JOIN time_clock_entries e ON e.id = b.entry_id
                WHERE b.source = 'manual' AND b.break_end IS NULL AND e.clock_out IS NOT NULL";
        $args = [];
        if ($userId !== null) { $sql .= ' AND b.user_id = ?'; $args[] = $userId; }
        $st = $this->db->prepare($sql);
        $st->execute($args);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $this->close((int)$r['id'], (string)$r['clock_out'], 'clock_out');
            $n++;
        }
        return $n;
    }

    /**
     * Clock-out during a break: the break ends first (at the clock-out), then the shift's BC flag
     * is worked out from the tapped + found breaks. Called by clockOut(); never throws.
     */
    public function afterClockOut(int $userId, int $entryId): void
    {
        try {
            if (!$this->ready()) return;
            $this->closeOrphans($userId);
            $this->refreshFlag($entryId);
        } catch (Throwable $e) {
            error_log('[BreakService] afterClockOut: ' . $e->getMessage());
        }
    }

    // ── Reading breaks (timesheets, payroll) ───────────────────────────────

    /** @return array<int, array<int, array>> entry id → raw break rows ({start,end,source,...} unix) */
    public function rowsForEntries(array $entryIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
        if (!$ids || !$this->ready()) return [];
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $st = $this->db->prepare("
                SELECT id, entry_id, break_start, break_end, source, place, ended_by
                FROM time_clock_breaks WHERE entry_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
            ");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['entry_id']][] = [
                    'id' => (int)$r['id'], 'start' => (int)strtotime((string)$r['break_start']),
                    'end' => $r['break_end'] !== null ? (int)strtotime((string)$r['break_end']) : null,
                    'source' => (string)$r['source'], 'place' => $r['place'], 'ended_by' => $r['ended_by'] ?? null,
                ];
            }
        }
        return $out;
    }

    /**
     * Each shift's breaks as they count, with the minutes that come off pay.
     * $entries: rows with id, clock_in, clock_out.
     * @return array<int, array{breaks: array, break_minutes:int, unpaid_minutes:int}>
     */
    public function forEntries(array $entries, ?int $now = null): array
    {
        $now = $now ?? time();
        $rows = $this->rowsForEntries(array_column($entries, 'id'));
        $paid = $this->breaksPaid();
        $out = [];
        foreach ($entries as $e) {
            $id = (int)$e['id'];
            $merged = self::merge($rows[$id] ?? [], (int)strtotime((string)$e['clock_in']),
                !empty($e['clock_out']) ? (int)strtotime((string)$e['clock_out']) : null, $now);
            $out[$id] = ['breaks' => $merged, 'break_minutes' => array_sum(array_column($merged, 'minutes')),
                         'unpaid_minutes' => self::unpaidMinutes($merged, $paid)];
        }
        return $out;
    }

    /**
     * Payroll: per day (of clock-in), the break minutes and the minutes that come off pay, for one
     * person's completed/edited shifts in [from, to]. Paid breaks → unpaid is 0 every day.
     * @return array{days: array<string, array{break_minutes:int, unpaid_minutes:int}>, break_minutes:int, unpaid_minutes:int, count:int}
     */
    public function periodForUser(int $userId, string $from, string $to): array
    {
        $out = ['days' => [], 'break_minutes' => 0, 'unpaid_minutes' => 0, 'count' => 0];
        if (!$this->ready()) return $out;
        $st = $this->db->prepare("
            SELECT id, clock_in, clock_out FROM time_clock_entries
            WHERE user_id = ? AND clock_in >= ? AND clock_in < ?
              AND status IN ('completed', 'edited')
        ");
        $st->execute([$userId, $from, date('Y-m-d', strtotime($to . ' +1 day'))]);
        $entries = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($this->forEntries($entries) as $id => $sum) {
            foreach ($entries as $e) {
                if ((int)$e['id'] !== $id) continue;
                $d = date('Y-m-d', strtotime((string)$e['clock_in']));
                $out['days'][$d]['break_minutes'] = ($out['days'][$d]['break_minutes'] ?? 0) + $sum['break_minutes'];
                $out['days'][$d]['unpaid_minutes'] = ($out['days'][$d]['unpaid_minutes'] ?? 0) + $sum['unpaid_minutes'];
            }
            $out['break_minutes'] += $sum['break_minutes'];
            $out['unpaid_minutes'] += $sum['unpaid_minutes'];
            $out['count'] += count($sum['breaks']);
        }
        return $out;
    }

    /** Work out one closed shift's BC flag from its tapped + found breaks (people only). */
    public function refreshFlag(int $entryId): ?string
    {
        $st = $this->db->prepare("
            SELECT e.id, e.clock_in, e.clock_out, COALESCE(u.device_type, 'personal') AS device_type
            FROM time_clock_entries e JOIN users u ON u.id = e.user_id WHERE e.id = ?
        ");
        $st->execute([$entryId]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e || $e['clock_out'] === null) return null;
        $gap = null;
        if ($e['device_type'] !== 'truck') {
            $sum = $this->forEntries([$e])[$entryId];
            $gap = self::complianceGap(
                self::shiftMinutes((string)$e['clock_in'], (string)$e['clock_out']),
                array_column($sum['breaks'], 'minutes')
            );
        }
        $this->db->prepare("UPDATE time_clock_entries SET break_note = ? WHERE id = ?")->execute([$gap, $entryId]);
        return $gap;
    }

    /** DST-safe shift minutes (TimeclockRules) without making every caller load it first. */
    private static function shiftMinutes(string $start, string $end): int
    {
        if (!class_exists('TimeclockRules')) {
            $f = dirname(__DIR__, 2) . '/Team/Services/TimeclockRules.php';
            if (is_file($f)) require_once $f;
        }
        return class_exists('TimeclockRules')
            ? TimeclockRules::minutesBetween($start, $end)
            : (int)floor((strtotime($end) - strtotime($start)) / 60);
    }

    // ── GPS breaks (backup) ─────────────────────────────────────────────────

    /** Find and record the day's GPS breaks. Re-running a day replaces its GPS rows (taps stay). */
    public function run(string $date): array
    {
        $out = ['shifts' => 0, 'breaks' => 0, 'gaps' => 0];
        if (!$this->ready()) return $out + ['skipped' => 'migration 1318'];
        if (!class_exists('TripSegmentService')) require_once __DIR__ . '/TripSegmentService.php';
        $trip = new TripSegmentService($this->db);
        $places = $trip->places();
        // People only — the truck login (device_type 'truck') is a vehicle, not someone who takes breaks.
        $st = $this->db->prepare("
            SELECT e.id, e.user_id, e.clock_in, e.clock_out FROM time_clock_entries e
            JOIN users u ON u.id = e.user_id AND COALESCE(u.device_type, 'personal') <> 'truck'
            WHERE DATE(e.clock_in) = ? AND e.clock_out IS NOT NULL AND e.status IN ('completed', 'edited', 'active')
        ");
        $st->execute([$date]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $out['shifts']++;
            $in = (int)strtotime((string)$e['clock_in']);
            $outT = (int)strtotime((string)$e['clock_out']);
            $pings = TripSegmentService::normalisePings(
                $trip->phonePings([(int)$e['user_id']], (string)$e['clock_in'], (string)$e['clock_out'])[(int)$e['user_id']] ?? []
            );
            $props = $trip->propertiesNear($pings);
            $stops = [];
            foreach (TripSegmentService::detectStops($pings) as $s) {
                $s['label'] = TripSegmentService::label($s['lat'], $s['lng'], $props, $places);
                $stops[] = $s;
            }
            $breaks = self::candidates($stops, $in, $outT);
            $this->db->prepare("DELETE FROM time_clock_breaks WHERE entry_id = ? AND source = 'gps'")->execute([(int)$e['id']]);
            $ins = $this->db->prepare("INSERT INTO time_clock_breaks (entry_id, user_id, break_start, break_end, minutes, source, place) VALUES (?, ?, ?, ?, ?, 'gps', ?)");
            foreach ($breaks as $b) {
                $ins->execute([(int)$e['id'], (int)$e['user_id'], date('Y-m-d H:i:s', $b['start']), date('Y-m-d H:i:s', $b['end']), $b['minutes'], mb_substr($b['where'], 0, 120)]);
                $out['breaks']++;
            }
            // Since the break button (2026-10-13) a shift with no tap and no GPS break is a real
            // finding, not a GPS blind spot — the flag is worked out from taps + GPS together.
            $this->closeOrphans((int)$e['user_id']);
            if ($this->refreshFlag((int)$e['id'])) $out['gaps']++;
        }
        return $out;
    }

    /** Otto's items: shifts in the last 7 days over 5 hours with no 30-minute break. */
    public function briefItems(string $today): array
    {
        // ON since the break button exists (2026-10-13; migration 1358 sets break_flags_enabled = 1).
        // Before it, GPS couldn't see a lunch taken on the job site (first run: 19 of 24 flagged).
        if (!$this->ready() || $this->setting('break_flags_enabled', '1') !== '1') return [];
        try {
            $rows = $this->db->query("
                SELECT e.id, e.user_id, e.clock_in, e.break_note, ts.id AS timesheet_id,
                       TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS who
                FROM time_clock_entries e
                JOIN users u ON u.id = e.user_id AND COALESCE(u.device_type, 'personal') <> 'truck'
                LEFT JOIN timesheets ts ON ts.user_id = e.user_id
                     AND ts.week_start = DATE_SUB(DATE(e.clock_in), INTERVAL WEEKDAY(e.clock_in) DAY)
                WHERE e.break_note IS NOT NULL AND e.status IN ('completed', 'edited')
                  AND e.clock_in >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                ORDER BY e.clock_in DESC LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $who = ucwords(strtolower(trim((string)$r['who']))) ?: 'Crew';
            $out[] = ['key' => 'otto:break:' . (int)$r['id'], 'kind' => 'break', 'priority' => 3,
                      'text' => $who . ', ' . date('D M j', strtotime((string)$r['clock_in'])) . ': ' . $r['break_note'] . '.',
                      'url' => $r['timesheet_id'] ? '/crm/timeclock/timesheet-detail.php?id=' . (int)$r['timesheet_id'] : '/crm/timeclock/timesheets.php',
                      'value' => null, 'since' => $today];
        }
        return $out;
    }
}

