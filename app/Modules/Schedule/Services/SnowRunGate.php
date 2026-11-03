<?php
/**
 * SnowRunGate — keeps scheduled snow route visits hidden and unstartable until
 * an operations run is open.
 */
declare(strict_types=1);

final class SnowRunGate
{
    public const CODE = 'SNOW_RUN_REQUIRED';
    public const KINDS = ['salt', 'snow'];
    public const ACTIVE_HOURS = 20;
    public const MESSAGE = 'Start the salt/snow run first (Schedule → Start run).';

    private PDO $db;
    private string $now;
    private ?bool $isReady = null;

    public function __construct(PDO $db, ?string $now = null)
    {
        $this->db = $db;
        $this->now = $now ?? date('Y-m-d H:i:s');
    }

    public function ready(): bool
    {
        if ($this->isReady !== null) return $this->isReady;
        try {
            foreach (['snow_runs', 'snow_route_rates'] as $table) {
                $stmt = $this->db->prepare("SELECT 1 FROM $table LIMIT 0");
                $stmt->execute();
            }
            return $this->isReady = true;
        } catch (Throwable $e) {
            $this->log('ready', $e);
            return $this->isReady = false;
        }
    }

    public function activeRun(): ?array
    {
        if (!$this->ready()) return null;
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime($this->now . ' -' . self::ACTIVE_HOURS . ' hours'));
            $stmt = $this->db->prepare(
                "SELECT sr.id, sr.kind, sr.started_at, sr.started_by, COALESCE(u.full_name, '') AS started_by_name
                 FROM snow_runs sr
                 LEFT JOIN users u ON u.id = sr.started_by
                 WHERE sr.ended_at IS NULL AND sr.started_at >= ?
                 ORDER BY sr.started_at DESC, sr.id DESC LIMIT 1"
            );
            $stmt->execute([$cutoff]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $row['id'] = (int)$row['id'];
            $row['started_by'] = (int)$row['started_by'];
            return $row;
        } catch (Throwable $e) {
            $this->log('activeRun', $e);
            return null;
        }
    }

    public function unlockedRange(): ?array
    {
        $run = $this->activeRun();
        return $run ? [substr((string)$run['started_at'], 0, 10), substr($this->now, 0, 10)] : null;
    }

    public function routePlanIds(): array
    {
        if (!$this->ready()) return [];
        try {
            $stmt = $this->db->prepare('SELECT DISTINCT plan_id FROM snow_route_rates ORDER BY plan_id');
            $stmt->execute();
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            $this->log('routePlanIds', $e);
            return [];
        }
    }

    public static function filter(array $byDate, array $routePlanIds, ?array $range): array
    {
        $routePlans = array_fill_keys(array_map('intval', $routePlanIds), true);
        $hiddenVisits = 0;
        $hiddenStops = 0;
        $filtered = [];

        foreach ($byDate as $date => $stops) {
            $keptStops = [];
            foreach ($stops as $stopId => $stop) {
                $visits = [];
                foreach (($stop['visits'] ?? []) as $visit) {
                    $isRoute = isset($routePlans[(int)($visit['plan_id'] ?? 0)]);
                    $isScheduled = ($visit['visit_status'] ?? null) === 'scheduled';
                    $inRange = $range !== null && (string)$date >= (string)$range[0] && (string)$date <= (string)$range[1];
                    if ($isRoute && $isScheduled && !$inRange) {
                        $hiddenVisits++;
                        continue;
                    }
                    $visits[] = $visit;
                }
                if (!$visits) {
                    $hiddenStops++;
                    continue;
                }
                $stop['visits'] = $visits;
                $keptStops[$stopId] = $stop;
            }
            if ($keptStops) $filtered[$date] = $keptStops;
        }

        return ['stops' => $filtered, 'hidden_visits' => $hiddenVisits, 'hidden_stops' => $hiddenStops];
    }

    public function hideScheduledRouteVisits(array $byDate): array
    {
        if (!$this->ready()) return $byDate;
        return self::filter($byDate, $this->routePlanIds(), $this->unlockedRange())['stops'];
    }

    public function visitVisibleSql(string $alias = 'jv'): array
    {
        if (!$this->ready()) return ['sql' => '1=1', 'params' => []];
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) $alias = 'jv';
        $range = $this->unlockedRange() ?? ['1000-01-01', '1000-01-01'];
        return [
            'sql' => "($alias.status <> 'scheduled' OR NOT EXISTS (SELECT 1 FROM snow_route_rates r WHERE r.plan_id = $alias.plan_id) OR $alias.scheduled_date BETWEEN ? AND ?)",
            'params' => [$range[0], $range[1]],
        ];
    }

    public function refuseStart(int $visitId): ?array
    {
        if (!$this->ready() || $this->activeRun() !== null) return null;
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM job_visits jv
                 WHERE jv.id = ? AND jv.status = 'scheduled'
                   AND EXISTS (SELECT 1 FROM snow_route_rates r WHERE r.plan_id = jv.plan_id)
                 LIMIT 1"
            );
            $stmt->execute([$visitId]);
            return $stmt->fetchColumn() === false ? null : ['code' => self::CODE, 'message' => self::MESSAGE];
        } catch (Throwable $e) {
            $this->log('refuseStart', $e);
            return null;
        }
    }

    public function mayControl(string $role, int $userId): bool
    {
        if (in_array(strtolower($role), ['admin', 'manager'], true)) return true;
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute(['snow_route_crew_user_ids']);
            $value = $stmt->fetchColumn();
            if ($value === false) return false;
            if (!class_exists('SnowContractService', false) && defined('APP_ROOT')) {
                require_once APP_ROOT . '/Modules/Contracts/Services/SnowContractService.php';
            }
            if (!class_exists('SnowContractService', false)) return false;
            return in_array($userId, SnowContractService::parseCrewIds((string)$value), true);
        } catch (Throwable $e) {
            $this->log('mayControl', $e);
            return false;
        }
    }

    public function start(string $kind, int $userId): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return ['success' => false, 'error' => 'Invalid run kind.'];
        }
        if (!$this->ready()) return ['success' => false, 'error' => 'Snow runs are not available.'];

        $ownsTransaction = !$this->db->inTransaction();
        try {
            if ($ownsTransaction) $this->db->beginTransaction();
            $stmt = $this->db->prepare("UPDATE snow_runs SET ended_at = ?, ended_by = ?, note = ? WHERE ended_at IS NULL");
            $stmt->execute([$this->now, $userId, 'replaced']);
            $stmt = $this->db->prepare('INSERT INTO snow_runs (kind, started_at, started_by) VALUES (?, ?, ?)');
            $stmt->execute([$kind, $this->now, $userId]);
            if ($ownsTransaction) $this->db->commit();
            return ['success' => true, 'run' => $this->activeRun()];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            $this->log('start', $e);
            return ['success' => false, 'error' => 'Unable to start the run.'];
        }
    }

    public function end(int $userId): array
    {
        if (!$this->ready()) return ['success' => true, 'ended' => false];
        try {
            $stmt = $this->db->prepare('UPDATE snow_runs SET ended_at = ?, ended_by = ? WHERE ended_at IS NULL');
            $stmt->execute([$this->now, $userId]);
            return ['success' => true, 'ended' => $stmt->rowCount() > 0];
        } catch (Throwable $e) {
            $this->log('end', $e);
            return ['success' => true, 'ended' => false];
        }
    }

    public function routeStopCount(string $date): int
    {
        if (!$this->ready()) return 0;
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT jv.stop_id) FROM job_visits jv
                 WHERE jv.status = 'scheduled' AND jv.scheduled_date = ? AND jv.stop_id IS NOT NULL
                   AND EXISTS (SELECT 1 FROM snow_route_rates r WHERE r.plan_id = jv.plan_id)"
            );
            $stmt->execute([$date]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $this->log('routeStopCount', $e);
            return 0;
        }
    }

    public function hasRoutePlans(): bool
    {
        if (!$this->ready()) return false;
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM job_plans jp WHERE jp.status = 'active'
                 AND EXISTS (SELECT 1 FROM snow_route_rates r WHERE r.plan_id = jp.id) LIMIT 1"
            );
            $stmt->execute();
            return $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            $this->log('hasRoutePlans', $e);
            return false;
        }
    }

    public function status(string $date, string $role, int $userId): array
    {
        $ready = $this->ready();
        $run = $ready ? $this->activeRun() : null;
        return [
            'ready' => $ready,
            'has_routes' => $ready ? $this->hasRoutePlans() : false,
            'active' => $run !== null,
            'kind' => $run['kind'] ?? null,
            'started_at' => $run['started_at'] ?? null,
            'started_by_name' => $run['started_by_name'] ?? null,
            'hidden_route_stops' => $ready && $run === null ? $this->routeStopCount($date) : 0,
            'may_control' => $this->mayControl($role, $userId),
        ];
    }

    private function log(string $method, Throwable $e): void
    {
        error_log('SnowRunGate ' . $method . ': ' . $e->getMessage());
    }
}
