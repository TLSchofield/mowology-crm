<?php
/**
 * BreakService — Otto finds crew breaks from the phone's GPS (owner, 2026-10-10).
 *
 * A break is a stop of BREAK_MIN_MINUTES+ during a shift that isn't at a client's property or a
 * work place (dump, supplier, yard) — the coffee shop, the lunch pull-in. Stops come from each
 * person's phone (crew_location_history), found and labelled the same way Otto reads the
 * truck's day (TripSegmentService::detectStops / label). Breaks are recorded automatically
 * (time_clock_breaks, source 'gps'); nobody has to tap anything.
 *
 * Mowology pays breaks (setting breaks_paid = 1), so pay never changes — the records are for
 * honest job times and for the BC rule: no more than 5 hours without a 30-minute meal break.
 * Otto flags a shift over 5 hours with no 30-minute break found. With unpaid breaks (another
 * business, answered at onboarding) the same records are what the timesheet would deduct.
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
            ? "{$h} shift; longest break found was " . max($breakMinutes) . ' min (BC needs 30 min before 5 h)'
            : "{$h} shift with no break found (BC needs a 30-min meal break before 5 h)";
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

    /** Find and record the day's GPS breaks. Re-running a day replaces its GPS rows. */
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
            $gap = $pings ? self::complianceGap((int)round(($outT - $in) / 60), array_column($breaks, 'minutes')) : null;
            $this->db->prepare("UPDATE time_clock_entries SET break_note = ? WHERE id = ?")->execute([$gap, (int)$e['id']]);
            if ($gap) $out['gaps']++;
        }
        return $out;
    }

    /** Otto's items: shifts in the last 7 days over 5 hours with no 30-minute break found. */
    public function briefItems(string $today): array
    {
        // Off until breaks can be tapped in the crew app: GPS can't see a lunch taken on the job
        // site, so most shifts would be flagged wrongly (first run 2026-10-10: 19 of 24).
        if (!$this->ready() || $this->setting('break_flags_enabled', '0') !== '1') return [];
        try {
            $rows = $this->db->query("
                SELECT e.id, e.clock_in, e.break_note, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS who
                FROM time_clock_entries e JOIN users u ON u.id = e.user_id
                WHERE e.break_note IS NOT NULL AND e.clock_in >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
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
                      'url' => '/crm/timeclock/timesheet-detail.php', 'value' => null, 'since' => $today];
        }
        return $out;
    }
}
