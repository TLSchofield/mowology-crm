<?php
declare(strict_types=1);

/**
 * Changes ONE visit's time window (calendar drag of an edge) — the only writer of
 * job_visits.duration_override_minutes. The plan's estimated_duration_minutes is never touched,
 * so the other visits of a recurring plan keep their length. CalendarStops reads the override as
 * the visit's duration, which is how the day card's revenue, labour, density and the 540-minute
 * capacity check follow a resize without a second calculation.
 */
final class VisitResizeService
{
    public const MIN_MINUTES = 15;
    public const MAX_MINUTES = 720;
    public const SNAP        = 15;

    /** @var PDO */
    private $db;
    /** @var bool|null */
    private static $hasColumn = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** 'HH:MM' or 'HH:MM:SS' to minutes since midnight; null when malformed. */
    public static function toMinutes(?string $t): ?int
    {
        if ($t === null || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($t), $m)) {
            return null;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        return ($h > 23 || $i > 59) ? null : $h * 60 + $i;
    }

    public static function fromMinutes(int $min): string
    {
        return sprintf('%02d:%02d:00', intdiv($min, 60), $min % 60);
    }

    /**
     * Snap both edges to SNAP minutes and check the block.
     * @return array{0:string,1:string,2:int} start, end ('HH:MM:SS'), minutes
     * @throws InvalidArgumentException
     */
    public static function normalise(string $start, string $end): array
    {
        $s = self::toMinutes($start);
        $e = self::toMinutes($end);
        if ($s === null || $e === null) {
            throw new InvalidArgumentException('Start and end must be times like 09:00.');
        }
        $s = (int)(round($s / self::SNAP) * self::SNAP);
        $e = (int)(round($e / self::SNAP) * self::SNAP);
        if ($e >= 1440 || $s < 0) {
            throw new InvalidArgumentException('A visit has to start and end on the same day.');
        }
        $len = $e - $s;
        if ($len < self::MIN_MINUTES) {
            throw new InvalidArgumentException('A visit has to be at least ' . self::MIN_MINUTES . ' minutes.');
        }
        if ($len > self::MAX_MINUTES) {
            throw new InvalidArgumentException('A visit can be at most ' . (self::MAX_MINUTES / 60) . ' hours.');
        }
        return [self::fromMinutes($s), self::fromMinutes($e), $len];
    }

    /** The end a moved visit keeps its length at, never past the end of the day. */
    public static function shiftedEnd(?string $oldStart, ?string $oldEnd, string $newStart): ?string
    {
        $os = self::toMinutes($oldStart);
        $oe = self::toMinutes($oldEnd);
        $ns = self::toMinutes($newStart);
        if ($os === null || $oe === null || $ns === null || $oe <= $os) {
            return $oldEnd;
        }
        return self::fromMinutes(min(1439, $ns + ($oe - $os)));
    }

    public static function columnReady(PDO $db): bool
    {
        if (self::$hasColumn === null) {
            try {
                self::$hasColumn = (bool)$db->query("SHOW COLUMNS FROM job_visits LIKE 'duration_override_minutes'")->fetch();
            } catch (Throwable $e) {
                self::$hasColumn = false;
            }
        }
        return self::$hasColumn;
    }

    /** Off unless ops_settings it_calendar_edit = '1'. */
    public static function enabled(PDO $db): bool
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'it_calendar_edit'");
            $s->execute();
            return (string)$s->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array|null */
    public function visit(int $visitId): ?array
    {
        $s = $this->db->prepare("SELECT id, status, scheduled_date, scheduled_time_start, scheduled_time_end,
                                        assigned_crew_id, stop_id, duration_override_minutes
                                 FROM job_visits WHERE id = ?");
        $s->execute([$visitId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Other visits of the same crew member that overlap the block. */
    public function conflicts(array $visit, string $start, string $end): array
    {
        if (empty($visit['assigned_crew_id'])) {
            return [];
        }
        $s = $this->db->prepare("SELECT id, scheduled_time_start, scheduled_time_end FROM job_visits
                                 WHERE assigned_crew_id = ? AND scheduled_date = ? AND id <> ?
                                   AND status NOT IN ('cancelled','skipped')
                                   AND scheduled_time_start IS NOT NULL AND scheduled_time_end IS NOT NULL");
        $s->execute([(int)$visit['assigned_crew_id'], $visit['scheduled_date'], (int)$visit['id']]);
        $a = self::toMinutes($start);
        $b = self::toMinutes($end);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $os = self::toMinutes($o['scheduled_time_start']);
            $oe = self::toMinutes($o['scheduled_time_end']);
            if ($os !== null && $oe !== null && $os < $b && $oe > $a) {
                $out[] = ['visit_id' => (int)$o['id'], 'start' => substr($o['scheduled_time_start'], 0, 5), 'end' => substr($o['scheduled_time_end'], 0, 5)];
            }
        }
        return $out;
    }

    /**
     * @return array{success:bool, warning?:bool, conflicts?:array, previous?:array, minutes?:int, date?:string}
     * @throws InvalidArgumentException|RuntimeException
     */
    public function resize(int $visitId, string $start, string $end, bool $force = false): array
    {
        if (!self::columnReady($this->db)) {
            throw new RuntimeException('Resizing is not set up yet (database update pending).');
        }
        [$s, $e, $len] = self::normalise($start, $end);
        $v = $this->visit($visitId);
        if (!$v) {
            throw new RuntimeException('Visit not found.');
        }
        if ($v['status'] !== 'scheduled') {
            throw new RuntimeException('Only a visit that has not started can be changed.');
        }
        if (!$force) {
            $c = $this->conflicts($v, $s, $e);
            if ($c) {
                return ['success' => false, 'warning' => true, 'conflicts' => $c, 'date' => (string)$v['scheduled_date']];
            }
        }
        $this->write($visitId, $s, $e, $len);
        return ['success' => true, 'minutes' => $len, 'date' => (string)$v['scheduled_date'], 'previous' => [
            'start' => $v['scheduled_time_start'], 'end' => $v['scheduled_time_end'],
            'override' => $v['duration_override_minutes'] !== null ? (int)$v['duration_override_minutes'] : null,
        ]];
    }

    /** Undo: put back exactly what resize() reported as previous (override included, NULL too). */
    public function restore(int $visitId, ?string $start, ?string $end, ?int $override): void
    {
        $v = $this->visit($visitId);
        if (!$v || $v['status'] !== 'scheduled') {
            throw new RuntimeException('Only a visit that has not started can be changed.');
        }
        $this->db->prepare("UPDATE job_visits SET scheduled_time_start = ?, scheduled_time_end = ?, duration_override_minutes = ?, updated_at = NOW() WHERE id = ? AND status = 'scheduled'")
                 ->execute([$start, $end, $override, $visitId]);
    }

    private function write(int $visitId, string $s, string $e, int $len): void
    {
        $this->db->prepare("UPDATE job_visits SET scheduled_time_start = ?, scheduled_time_end = ?, duration_override_minutes = ?, updated_at = NOW() WHERE id = ? AND status = 'scheduled'")
                 ->execute([$s, $e, $len, $visitId]);
    }
}
