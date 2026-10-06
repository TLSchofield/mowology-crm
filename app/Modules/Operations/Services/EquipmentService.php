<?php
/**
 * EquipmentService — the equipment register and what Otto watches on it.
 *
 *   equipment                    one row per item; cost via the purchase receipt (expense_id)
 *   equipment_service_intervals  Tim's intervals per class or item
 *   equipment_service_log        services done
 *   battery_pack_runs            runs logged by hand
 *
 * Otto's suggestions from here (OpsDeskService gathers them):
 *   maintenance   an item past an interval — Tim's click makes a task (never automatic)
 *   pack_fading   a pack whose last 3 runs are under 70% of new
 *   truck_range   a Might-E day planned past its range less the reserve. Planned km is
 *                 straight-line base → stops (route order) → base × a road factor learned
 *                 from odometer days (vehicle_trip_reports); no route solver.
 * Usage hours are an approximation: the assigned crew lead's job-timer minutes on visits
 * whose service type uses the item's class (service_equipment).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/DispatchRules.php';
require_once __DIR__ . '/OttoRules.php';
require_once __DIR__ . '/OpsDeskService.php';

class EquipmentService
{
    public const CLASSES = ['mower', 'trimmer', 'blower', 'battery_pack', 'truck', 'other'];
    public const POWER = ['gas', 'battery', 'electric', 'diesel'];
    public const TRUCK_DAYS = 7;

    private PDO $db;
    private string $today;
    /** odometerDays() per truck for this request — it walks every day's stops. */
    private array $odoMemo = [];

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'equipment'")->rowCount() > 0
                && $this->db->query("SHOW TABLES LIKE 'battery_pack_runs'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Register ────────────────────────────────────────────────────────────

    /** Items with their cost (receipt first) and assigned person. */
    public function items(bool $activeOnly = false): array
    {
        $rows = $this->db->query("
            SELECT e.*, u.full_name AS assigned_name,
                   x.total AS receipt_total, x.amount AS receipt_amount, x.expense_date AS receipt_date,
                   COALESCE(x.vendor_name_raw, '') AS receipt_vendor
            FROM equipment e
            LEFT JOIN users u ON u.id = e.assigned_user_id
            LEFT JOIN expenses x ON x.id = e.expense_id
            " . ($activeOnly ? "WHERE e.status = 'active'" : '') . "
            ORDER BY e.status = 'retired', FIELD(e.equipment_class, 'truck', 'mower', 'trimmer', 'blower', 'battery_pack', 'other'), e.name
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['cost'] = $r['expense_id'] ? (float)($r['receipt_total'] ?: $r['receipt_amount']) : ($r['cost_manual'] !== null ? (float)$r['cost_manual'] : null);
            $r['cost_source'] = $r['expense_id'] ? 'receipt' : ($r['cost_manual'] !== null ? 'manual' : null);
        }
        return $rows;
    }

    public function save(array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        $class = (string)($in['equipment_class'] ?? '');
        if ($name === '' || !in_array($class, self::CLASSES, true)) return ['ok' => false, 'message' => 'Name and type are required.'];
        $s = static fn($k, $len = 80) => ($v = trim((string)($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $len);
        $i = static fn($k) => isset($in[$k]) && $in[$k] !== '' ? (int)$in[$k] : null;
        $f = static fn($k) => isset($in[$k]) && $in[$k] !== '' ? (float)$in[$k] : null;
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['purchase_date'] ?? '')) ? $in['purchase_date'] : null;
        $vals = [
            mb_substr($name, 0, 120), $class, $s('make'), $s('model'), $s('serial_no'),
            in_array($in['power_source'] ?? '', self::POWER, true) ? $in['power_source'] : 'battery',
            $i('assigned_user_id'), $date, $i('expense_id'), $f('cost_manual'), $s('cca_class', 10),
            (float)($f('hours_baseline') ?? 0), $i('runtime_new_min'), $i('range_km'), $i('reserve_pct'), $i('top_speed_kph'),
            $s('vehicle_id', 30), $f('base_lat'), $f('base_lng'),
            ($in['status'] ?? '') === 'retired' ? 'retired' : 'active', $s('notes', 500),
        ];
        $cols = 'name, equipment_class, make, model, serial_no, power_source, assigned_user_id, purchase_date, expense_id, cost_manual, cca_class,
                 hours_baseline, runtime_new_min, range_km, reserve_pct, top_speed_kph, vehicle_id, base_lat, base_lng, status, notes';
        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            $set = implode(', ', array_map(fn($c) => trim($c) . ' = ?', explode(',', $cols)));
            $this->db->prepare("UPDATE equipment SET {$set} WHERE id = ?")->execute(array_merge($vals, [$id]));
        } else {
            $this->db->prepare("INSERT INTO equipment ({$cols}) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")")->execute($vals);
            $id = (int)$this->db->lastInsertId();
        }
        return ['ok' => true, 'message' => 'Saved.', 'id' => $id];
    }

    public function retire(int $id): void
    {
        $this->db->prepare("UPDATE equipment SET status = 'retired' WHERE id = ?")->execute([$id]);
    }

    public function intervals(): array
    {
        return $this->db->query("SELECT * FROM equipment_service_intervals ORDER BY equipment_class, equipment_id, task")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveInterval(array $in): array
    {
        $task = trim((string)($in['task'] ?? ''));
        $class = in_array($in['equipment_class'] ?? '', self::CLASSES, true) ? $in['equipment_class'] : null;
        $item = !empty($in['equipment_id']) ? (int)$in['equipment_id'] : null;
        $h = isset($in['every_hours']) && $in['every_hours'] !== '' ? (float)$in['every_hours'] : null;
        $d = isset($in['every_days']) && $in['every_days'] !== '' ? (int)$in['every_days'] : null;
        if ($task === '' || (!$class && !$item) || (!$h && !$d)) return ['ok' => false, 'message' => 'Give a task, a type or item, and hours or days.'];
        $this->db->prepare("INSERT INTO equipment_service_intervals (equipment_class, equipment_id, task, every_hours, every_days) VALUES (?, ?, ?, ?, ?)")
            ->execute([$item ? null : $class, $item, mb_substr($task, 0, 60), $h, $d]);
        return ['ok' => true, 'message' => 'Saved.'];
    }

    public function deleteInterval(int $id): void
    {
        $this->db->prepare("DELETE FROM equipment_service_intervals WHERE id = ?")->execute([$id]);
    }

    public function logService(int $equipmentId, string $task, ?string $doneOn, int $actorId, ?int $taskId = null, ?string $note = null): array
    {
        $task = trim($task);
        if ($equipmentId <= 0 || $task === '') return ['ok' => false, 'message' => 'Which item and which task?'];
        $doneOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$doneOn) ? $doneOn : $this->today;
        $this->db->prepare("INSERT INTO equipment_service_log (equipment_id, task, done_on, task_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$equipmentId, mb_substr($task, 0, 60), $doneOn, $taskId, $note ? mb_substr($note, 0, 255) : null, $actorId ?: null]);
        return ['ok' => true, 'message' => 'Logged.'];
    }

    public function logRun(int $equipmentId, int $minutes, ?string $date, bool $ranFlat, int $actorId): array
    {
        if ($equipmentId <= 0 || $minutes < 1 || $minutes > 600) return ['ok' => false, 'message' => 'Give the minutes, 1 to 600.'];
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) ? $date : $this->today;
        $this->db->prepare("INSERT INTO battery_pack_runs (equipment_id, run_date, runtime_min, ran_flat, logged_by) VALUES (?, ?, ?, ?, ?)")
            ->execute([$equipmentId, $date, $minutes, $ranFlat ? 1 : 0, $actorId ?: null]);
        return ['ok' => true, 'message' => 'Logged.'];
    }

    public function serviceLog(int $limit = 50): array
    {
        return $this->db->query("SELECT l.*, e.name FROM equipment_service_log l JOIN equipment e ON e.id = l.equipment_id ORDER BY l.done_on DESC, l.id DESC LIMIT " . max(1, min(500, $limit)))
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** equipment_id => [minutes newest first] for full runs */
    public function runs(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT equipment_id, runtime_min, run_date FROM battery_pack_runs WHERE ran_flat = 1 ORDER BY run_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['equipment_id']][] = ['min' => (int)$r['runtime_min'], 'date' => (string)$r['run_date']];
        }
        return $out;
    }

    /** Make a maintenance task — only ever called from Tim's click. */
    public function createTask(array $item, array $due, int $actorId): int
    {
        $title = mb_substr('Service ' . $item['name'] . ': ' . implode(', ', array_column($due, 'task')), 0, 255);
        $desc = implode("\n", array_map(fn($d) => '• ' . $d['task'] . ' — ' . $d['why'], $due)) . "\n\nFrom Otto (equipment register). Log the service on /crm/ops/equipment.php when done.";
        $hasEq = false;
        try { $hasEq = $this->db->query("SHOW COLUMNS FROM tasks LIKE 'equipment_id'")->rowCount() > 0; } catch (Throwable $e) {}
        $cols = 'title, description, due_date, priority, status, assigned_to, created_by' . ($hasEq ? ', equipment_id' : '');
        $vals = [$title, $desc, date('Y-m-d', strtotime($this->today . ' +3 days')), 'normal', 'pending',
                 $item['assigned_user_id'] ? (int)$item['assigned_user_id'] : null, $actorId];
        if ($hasEq) $vals[] = (int)$item['id'];
        $this->db->prepare("INSERT INTO tasks ({$cols}) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")")->execute($vals);
        return (int)$this->db->lastInsertId();
    }

    // ── Hours ───────────────────────────────────────────────────────────────

    /** Hours of use since a date: the crew lead's job-timer minutes on visits that use this class. */
    public function hoursSince(array $item, string $since): float
    {
        if (empty($item['assigned_user_id']) || !in_array($item['equipment_class'], ['mower', 'trimmer', 'blower'], true)) return 0.0;
        try {
            $s = $this->db->prepare("
                SELECT COALESCE(SUM(jte.duration_minutes), 0)
                FROM job_time_entries jte
                JOIN job_visits v ON v.id = jte.visit_id
                JOIN job_plans p ON p.id = v.plan_id
                JOIN service_equipment se ON se.service_type = p.service_type AND se.equipment_class = ?
                WHERE jte.user_id = ? AND jte.status <> 'void' AND jte.start_time >= ?
            ");
            $s->execute([$item['equipment_class'], (int)$item['assigned_user_id'], $since . ' 00:00:00']);
            return round((float)$s->fetchColumn() / 60, 1);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    // ── Otto's suggestions ──────────────────────────────────────────────────

    /** @return array{0: array, 1: array, 2: array} [maintenance, pack_fading, truck_range] */
    public function suggestionItems(): array
    {
        if (!$this->ready()) return [[], [], []];
        try {
            $items = $this->items(true);
            $intervals = $this->intervals();
            $lastByTask = [];
            foreach ($this->db->query("SELECT equipment_id, task, MAX(done_on) AS d FROM equipment_service_log GROUP BY equipment_id, task")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lastByTask[(int)$r['equipment_id']][strtolower((string)$r['task'])] = (string)$r['d'];
            }
            $runs = $this->runs();
        } catch (Throwable $e) {
            error_log('Otto equipment: ' . $e->getMessage());
            return [[], [], []];
        }
        $maint = $packs = $trucks = [];
        foreach ($items as $it) {
            $start = $it['purchase_date'] ?: substr((string)$it['created_at'], 0, 10);
            $mine = DispatchRules::intervalsFor($it, $intervals);
            if ($mine) {
                $since = [];
                $latest = $start;
                foreach ($mine as $iv) {
                    $t = strtolower(trim((string)$iv['task']));
                    $last = $lastByTask[(int)$it['id']][$t] ?? null;
                    $from = $last ?? $start;
                    $hours = $this->hoursSince($it, $from) + ($last === null ? (float)$it['hours_baseline'] : 0.0);
                    $days = (int)floor((strtotime($this->today) - strtotime($from)) / 86400);
                    $since[$iv['task']] = [$hours, $days];
                    if ($last !== null && $last > $latest) $latest = $last;
                }
                $due = DispatchRules::due($mine, $since);
                if ($due) {
                    $who = $it['assigned_name'] ? ' (' . OpsDeskService::firstName((string)$it['assigned_name']) . ')' : '';
                    $maint[] = [
                        'key' => 'otto:maint:' . (int)$it['id'], 'kind' => 'maintenance', 'subject_type' => 'equipment', 'subject_id' => (int)$it['id'],
                        'for_date' => $latest, 'user_id' => $it['assigned_user_id'] ? (int)$it['assigned_user_id'] : null, 'priority' => 3,
                        'text' => $it['name'] . $who . ' is due: ' . implode('; ', array_map(fn($d) => $d['task'] . ', ' . $d['why'], $due)) . '.',
                        'detail' => 'Hours come from job timers on visits that use a ' . $it['equipment_class'] . ' — an estimate.',
                        'url' => '/crm/ops/equipment.php#eq-' . (int)$it['id'], 'value' => count($due), 'since' => $latest,
                        'propose' => ['due' => $due],
                    ];
                }
            }
            if ($it['equipment_class'] === 'battery_pack' && !empty($runs[(int)$it['id']])) {
                $list = $runs[(int)$it['id']];
                $f = DispatchRules::packFading(array_column($list, 'min'), $it['runtime_new_min'] !== null ? (int)$it['runtime_new_min'] : null);
                if ($f['fading']) {
                    $packs[] = [
                        'key' => 'otto:pack:' . (int)$it['id'], 'kind' => 'pack_fading', 'subject_type' => 'equipment', 'subject_id' => (int)$it['id'],
                        'for_date' => $list[0]['date'], 'user_id' => null, 'priority' => 3,
                        'text' => "Battery pack {$it['name']} is fading. Its last 3 full runs come to about " . (int)$f['median'] . ' min, '
                                . (int)round($f['share'] * 100) . '% of the ' . (int)$it['runtime_new_min'] . ' min it ran when new.',
                        'detail' => 'Below 70% counts as fading.', 'url' => '/crm/ops/equipment.php#eq-' . (int)$it['id'],
                        'value' => (int)round($f['share'] * 100), 'since' => $list[0]['date'], 'propose' => ['share' => $f['share']],
                    ];
                }
            }
            if ($it['equipment_class'] === 'truck' && !empty($it['range_km']) && !empty($it['assigned_user_id'])) {
                foreach ($this->truckDays($it) as $d) {
                    if ($d['planned_km'] <= $d['cap_km']) continue;
                    $date = $d['date'];
                    $trucks[] = [
                        'key' => 'otto:truck:' . (int)$it['id'] . ':' . $date, 'kind' => 'truck_range', 'subject_type' => 'equipment', 'subject_id' => (int)$it['id'],
                        'for_date' => $date, 'user_id' => (int)$it['assigned_user_id'],
                        'priority' => $date <= date('Y-m-d', strtotime($this->today . ' +1 day')) ? 1 : 2,
                        'text' => "The {$it['name']} is planned for about " . (int)round($d['planned_km']) . ' km ' . OttoRules::dayWord($date, $this->today)
                                . ' (' . OttoRules::plural($d['stops'], 'stop') . '). Its cap is ' . (int)round($d['cap_km']) . " km: {$it['range_km']} km range less "
                                . ($it['reserve_pct'] ?? DispatchRules::RESERVE_PCT) . '% reserve.',
                        'detail' => 'Straight-line distance × ' . $d['factor'] . ($d['learned'] ? ' (learned from odometer days)' : ' (starting estimate)') . '. Range is the spec until odometer days show more.',
                        'url' => '/crm/jobs/schedule.php', 'value' => (int)round($d['planned_km']), 'since' => $date,
                        'propose' => ['planned_km' => $d['planned_km'], 'cap_km' => $d['cap_km']],
                    ];
                }
            }
        }
        return [$maint, $packs, $trucks];
    }

    // ── The Might-E ─────────────────────────────────────────────────────────

    /** Stop coordinates for the truck's crew lead on a day, in route order. */
    private function dayPoints(array $truck, string $date, bool $doneOnly = false): array
    {
        $s = $this->db->prepare("
            SELECT p.latitude, p.longitude
            FROM calendar_stops cs JOIN properties p ON p.id = cs.property_id
            WHERE cs.stop_date = ? AND cs.crew_id = ?" . ($doneOnly ? " AND cs.status = 'completed'" : " AND cs.status <> 'skipped'") . "
              AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL AND p.latitude <> 0
            ORDER BY cs.route_order, cs.id
        ");
        $s->execute([$date, (int)$truck['assigned_user_id']]);
        $pts = array_map(fn($r) => [(float)$r['latitude'], (float)$r['longitude']], $s->fetchAll(PDO::FETCH_ASSOC));
        if ($pts && $truck['base_lat'] !== null && $truck['base_lng'] !== null) {
            $base = [(float)$truck['base_lat'], (float)$truck['base_lng']];
            $pts = array_merge([$base], $pts, [$base]);
        }
        return $pts;
    }

    /** Odometer days vs straight-line km — what the road factor and the real range are learned from. */
    public function odometerDays(array $truck, int $days = 60): array
    {
        if (empty($truck['vehicle_id'])) return [];
        $memo = (int)$truck['id'] . ':' . $days;
        if (isset($this->odoMemo[$memo])) return $this->odoMemo[$memo];
        try {
            $s = $this->db->prepare("
                SELECT report_date, SUM(odometer_end - odometer_start) AS km
                FROM vehicle_trip_reports
                WHERE vehicle_id = ? AND odometer_start IS NOT NULL AND odometer_end IS NOT NULL
                  AND odometer_end >= odometer_start AND report_date >= ?
                GROUP BY report_date ORDER BY report_date DESC
            ");
            $s->execute([(string)$truck['vehicle_id'], date('Y-m-d', strtotime($this->today . " -{$days} days"))]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $line = DispatchRules::plannedKm($this->dayPoints($truck, (string)$r['report_date'], true), 1.0);
                $out[] = ['date' => (string)$r['report_date'], 'odometer_km' => (float)$r['km'], 'line_km' => $line];
            }
            return $this->odoMemo[$memo] = $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** The next days' planned km for the truck against its cap. */
    public function truckDays(array $truck): array
    {
        $odo = $this->odometerDays($truck);
        $factor = DispatchRules::roadFactor(array_map(fn($d) => [$d['odometer_km'], $d['line_km']], $odo));
        $learned = $factor !== DispatchRules::ROAD_FACTOR;
        $cap = DispatchRules::dayCapKm((int)$truck['range_km'], $truck['reserve_pct'] !== null ? (int)$truck['reserve_pct'] : null);
        $out = [];
        for ($i = 0; $i < self::TRUCK_DAYS; $i++) {
            $date = date('Y-m-d', strtotime($this->today . " +{$i} days"));
            try {
                $pts = $this->dayPoints($truck, $date);
            } catch (Throwable $e) {
                $pts = [];
            }
            if (count($pts) < 2) continue;
            $stops = count($pts) - ($truck['base_lat'] !== null ? 2 : 0);
            $out[] = ['date' => $date, 'stops' => $stops, 'planned_km' => DispatchRules::plannedKm($pts, $factor), 'cap_km' => $cap,
                      'factor' => $factor, 'learned' => $learned];
        }
        return $out;
    }

    /** Things learned for Otto's brain. */
    public function learnedCounts(): array
    {
        $c = static function (PDO $db, string $sql): int {
            try { return (int)$db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
        };
        $factor = 0;
        try {
            foreach ($this->db->query("SELECT * FROM equipment WHERE equipment_class = 'truck' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $odo = $this->odometerDays($t);
                if (DispatchRules::roadFactor(array_map(fn($d) => [$d['odometer_km'], $d['line_km']], $odo)) !== DispatchRules::ROAD_FACTOR) $factor++;
            }
        } catch (Throwable $e) { /* not migrated */ }
        return [
            'road' => $factor,
            'packs' => $c($this->db, "SELECT COUNT(*) FROM battery_pack_runs"),
            'intervals' => $c($this->db, "SELECT COUNT(*) FROM equipment_service_intervals"),
        ];
    }
}
