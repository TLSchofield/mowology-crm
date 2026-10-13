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
 * Adding a machine (migration 1350, 2026-10-13): readPlate() keeps the plate photo + an optional
 * machine photo (LabelCaptureService, no card proposal), reads it (EquipmentPlateParser) and offers
 * matching purchase receipts; addFromPlate() saves the unit and links its MODEL to the shared
 * catalogue (EquipmentCatalogService). detail() is the machine's page: intervals due, receipts,
 * cost this year. EquipmentImportService brings a whole list in from CSV/xlsx through save().
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/DispatchRules.php';
require_once __DIR__ . '/OttoRules.php';
require_once __DIR__ . '/OpsDeskService.php';

class EquipmentService
{
    public const CLASSES = ['mower', 'trimmer', 'blower', 'hedge_trimmer', 'saw', 'aerator', 'battery_pack', 'truck', 'trailer', 'other'];
    /** The one list of class labels (pages, iPhone, import). */
    public const CLASS_LABELS = [
        'mower' => 'Mower', 'trimmer' => 'Trimmer', 'blower' => 'Blower', 'hedge_trimmer' => 'Hedge trimmer', 'saw' => 'Saw',
        'aerator' => 'Aerator', 'battery_pack' => 'Battery pack', 'truck' => 'Truck', 'trailer' => 'Trailer', 'other' => 'Other',
    ];
    public const POWER_LABELS = ['gas' => 'Gas', 'battery' => 'Battery', 'electric' => 'Electric', 'diesel' => 'Diesel'];
    /** Classes whose hours come from job timers. */
    public const TIMED = ['mower', 'trimmer', 'blower', 'hedge_trimmer', 'saw', 'aerator'];
    public const POWER = ['gas', 'battery', 'electric', 'diesel'];
    public const TRUCK_DAYS = 7;

    private PDO $db;
    private string $today;
    private array $colCache = [];
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
        $truck = $this->hasCol('equipment', 'truck_id');
        $rows = $this->db->query("
            SELECT e.*, u.full_name AS assigned_name,
                   x.total AS receipt_total, x.amount AS receipt_amount, x.expense_date AS receipt_date,
                   COALESCE(x.vendor_name_raw, '') AS receipt_vendor" . ($truck ? ", t.name AS truck_name" : ", NULL AS truck_name") . "
            FROM equipment e
            LEFT JOIN users u ON u.id = e.assigned_user_id
            LEFT JOIN expenses x ON x.id = e.expense_id
            " . ($truck ? "LEFT JOIN equipment t ON t.id = e.truck_id" : '') . "
            " . ($activeOnly ? "WHERE e.status = 'active'" : '') . "
            ORDER BY e.status = 'retired', FIELD(e.equipment_class, 'truck', 'trailer', 'mower', 'trimmer', 'blower', 'hedge_trimmer', 'saw', 'aerator', 'battery_pack', 'other'), e.name
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
        // Migration 1350 columns: written only when the caller sends them (the older edit form does not,
        // and must not wipe a model link or a photo).
        foreach (['truck_id', 'plate_media_id', 'photo_media_id'] as $c) {
            if (array_key_exists($c, $in) && $this->hasCol('equipment', $c)) { $cols .= ', ' . $c; $vals[] = $i($c); }
        }
        if ($this->hasCol('equipment', 'catalog_product_id')) {
            $model = $s('model');
            $link = array_key_exists('catalog_product_id', $in) ? $i('catalog_product_id') : null;
            if ($link === null && $model !== null) {
                try {
                    require_once __DIR__ . '/EquipmentCatalogService.php';
                    $link = (new EquipmentCatalogService($this->db))->linkModel($s('make'), $model, $class, $vals[5], $s('model_name', 120));
                } catch (Throwable $e) {
                    error_log('Equipment catalogue link: ' . $e->getMessage());   // the unit still saves
                }
            }
            if ($link !== null || array_key_exists('catalog_product_id', $in) || array_key_exists('model', $in)) { $cols .= ', catalog_product_id'; $vals[] = $link; }
        }
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

    /** Portable column check (MySQL and SQLite), cached per request. */
    public function hasCol(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!array_key_exists($k, $this->colCache)) {
            try { $this->db->query("SELECT {$col} FROM {$table} LIMIT 0"); $this->colCache[$k] = true; }
            catch (Throwable $e) { $this->colCache[$k] = false; }
        }
        return $this->colCache[$k];
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
        $model = !$item && !empty($in['catalog_product_id']) && $this->hasCol('equipment_service_intervals', 'catalog_product_id') ? (int)$in['catalog_product_id'] : null;
        $h = isset($in['every_hours']) && $in['every_hours'] !== '' ? (float)$in['every_hours'] : null;
        $d = isset($in['every_days']) && $in['every_days'] !== '' ? (int)$in['every_days'] : null;
        if ($task === '' || (!$class && !$item && !$model) || (!$h && !$d)) return ['ok' => false, 'message' => 'Give a task, a type or item, and hours or days.'];
        if ($model) {
            $this->db->prepare("INSERT INTO equipment_service_intervals (equipment_class, equipment_id, catalog_product_id, task, every_hours, every_days) VALUES (NULL, NULL, ?, ?, ?, ?)")
                ->execute([$model, mb_substr($task, 0, 60), $h, $d]);
            return ['ok' => true, 'message' => 'Saved for every unit of this model.'];
        }
        $this->db->prepare("INSERT INTO equipment_service_intervals (equipment_class, equipment_id, task, every_hours, every_days) VALUES (?, ?, ?, ?, ?)")
            ->execute([$item ? null : $class, $item, mb_substr($task, 0, 60), $h, $d]);
        return ['ok' => true, 'message' => 'Saved.'];
    }

    public function deleteInterval(int $id): void
    {
        $this->db->prepare("DELETE FROM equipment_service_intervals WHERE id = ?")->execute([$id]);
    }

    /** A service done (and, optionally, its parts/labour receipt — that receipt counts in the machine's cost). */
    public function logService(int $equipmentId, string $task, ?string $doneOn, int $actorId, ?int $taskId = null, ?string $note = null, ?int $expenseId = null): array
    {
        $task = trim($task);
        if ($equipmentId <= 0 || $task === '') return ['ok' => false, 'message' => 'Which item and which task?'];
        $doneOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$doneOn) ? $doneOn : $this->today;
        if ($expenseId) {
            $s = $this->db->prepare("SELECT id FROM expenses WHERE id = ?");
            $s->execute([$expenseId]);
            if (!$s->fetchColumn()) return ['ok' => false, 'message' => 'Receipt #' . $expenseId . ' is not in Expenses.'];
        }
        $this->db->prepare("INSERT INTO equipment_service_log (equipment_id, task, done_on, task_id, expense_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$equipmentId, mb_substr($task, 0, 60), $doneOn, $taskId, $expenseId ?: null, $note ? mb_substr($note, 0, 255) : null, $actorId ?: null]);
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

    // ── Adding a machine: snap the plate ────────────────────────────────────

    /**
     * Step 1 of Add equipment (web, Android, iPhone): keep the plate photo (+ optional machine photo),
     * read it, and say what Otto already knows. Nothing is added to the register here.
     * @param ?callable $capture  tests inject fn(): array (LabelCaptureService::capture() shape)
     */
    public function readPlate(int $userId, array $plateFile, array $photoFile = [], ?callable $capture = null): array
    {
        require_once __DIR__ . '/EquipmentPlateParser.php';
        require_once dirname(__DIR__, 2) . '/Products/Services/LabelCaptureService.php';
        $lc = new LabelCaptureService($this->db);
        $r = $capture ? $capture() : $lc->capture($userId, $plateFile, null, null, false);
        if (empty($r['success'])) return ['ok' => false, 'http_code' => $r['http_code'] ?? 400, 'message' => $r['error'] ?? 'The plate photo did not upload.'];
        $photoId = null;
        if (!$capture && !empty($photoFile) && ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $p = $lc->storePhoto($userId, $photoFile, 'equipment', 'Equipment photo', 'equipment');
            $photoId = !empty($p['success']) ? (int)$p['media_id'] : null;
        }
        $text = (string)($r['text'] ?? '');
        $plate = EquipmentPlateParser::parse($text);
        return $this->plateAnswer($plate, $text) + [
            'ok' => true, 'plate_media_id' => $r['media_id'] ?? null, 'photo_media_id' => $photoId, 'label_capture_id' => $r['capture_id'] ?? null,
        ];
    }

    /** What the plate said + what Otto knows (same serial already registered, model already in the catalogue, receipts). */
    public function plateAnswer(array $plate, string $text): array
    {
        $existing = null;
        if ($plate['serial']) {
            $s = $this->db->prepare("SELECT id, name FROM equipment WHERE UPPER(serial_no) = ? LIMIT 1");
            $s->execute([strtoupper((string)$plate['serial'])]);
            $existing = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $known = null;
        try {
            require_once __DIR__ . '/EquipmentCatalogService.php';
            $ec = new EquipmentCatalogService($this->db);
            if ($plate['model'] && ($p = $ec->findModel($plate['make'], $plate['model']))) {
                $known = ['catalog_product_id' => (int)$p['id'], 'name' => (string)$p['canonical_name'],
                          'intervals' => count($ec->sharedIntervals((int)$p['id'])), 'units' => $ec->unitsOfModel((int)$p['id']),
                          'attributes' => $ec->attributes((int)$p['id'])];
            }
        } catch (Throwable $e) { /* catalogue not migrated — the form still works */ }
        $found = [];
        if ($plate['make']) $found[] = 'make ' . $plate['make'];
        if ($plate['model']) $found[] = 'model ' . $plate['model'];
        if ($plate['serial']) $found[] = 'serial ' . $plate['serial'];
        $msg = $text === '' ? 'I could not read any text on that photo. Try again closer, in good light — or type the details in.'
             : ($found ? 'I read ' . implode(', ', $found) . '. Check it and add the rest.' : 'I read the photo but found no model or serial. Type them in from the plate.');
        if ($existing) $msg = 'That serial is already in equipment: ' . $existing['name'] . '.';
        return [
            'plate' => $plate, 'text' => mb_substr($text, 0, 2000), 'existing' => $existing, 'known_model' => $known, 'message' => $msg,
            'suggested' => [
                'name' => $plate['display_name'] ?? '', 'make' => $plate['make'] ?? '', 'model' => $plate['model'] ?? '', 'serial_no' => $plate['serial'] ?? '',
                'equipment_class' => $plate['equipment_class'] ?? ($known['attributes']['equipment_class'] ?? 'other'),
                'power_source' => $plate['power_source'] ?? ($known['attributes']['power_source'] ?? 'gas'),
                'model_name' => $plate['model_name'] ?? '',
            ],
            'receipts' => $this->receiptCandidates(['make' => $plate['make'], 'model' => $plate['model']]),
        ];
    }

    /**
     * Step 2: the confirmed form → the register. The model links to the shared catalogue inside save().
     * @return array{ok: bool, message: string, id?: int, catalog_product_id?: ?int, shared_intervals?: int}
     */
    public function addFromPlate(array $in, int $userId): array
    {
        $in['status'] = 'active';
        if (!empty($in['serial_no'])) {
            $s = $this->db->prepare("SELECT id, name FROM equipment WHERE UPPER(serial_no) = ? LIMIT 1");
            $s->execute([strtoupper(trim((string)$in['serial_no']))]);
            if ($dup = $s->fetch(PDO::FETCH_ASSOC)) return ['ok' => false, 'id' => (int)$dup['id'], 'message' => 'That serial is already in equipment: ' . $dup['name'] . '.'];
        }
        if (isset($in['cost']) && !isset($in['cost_manual']) && empty($in['expense_id'])) $in['cost_manual'] = $in['cost'];
        if (!empty($in['expense_id'])) {
            $s = $this->db->prepare("SELECT id FROM expenses WHERE id = ?");
            $s->execute([(int)$in['expense_id']]);
            if (!$s->fetchColumn()) return ['ok' => false, 'message' => 'Receipt #' . (int)$in['expense_id'] . ' is not in Expenses.'];
        }
        unset($in['id']);
        $r = $this->save($in);
        if (!$r['ok']) return $r;
        $id = (int)$r['id'];
        if (!empty($in['label_capture_id'])) {
            try {
                $this->db->prepare("UPDATE label_captures SET status = 'done', result_type = 'equipment', result_id = ? WHERE id = ? AND captured_by = ?")
                         ->execute([$id, (int)$in['label_capture_id'], $userId]);
            } catch (Throwable $e) { /* 1225 not run */ }
        }
        $row = $this->row($id);
        $model = $row && !empty($row['catalog_product_id']) ? (int)$row['catalog_product_id'] : null;
        $shared = 0;
        if ($model) {
            require_once __DIR__ . '/EquipmentCatalogService.php';
            $shared = count((new EquipmentCatalogService($this->db))->notAdopted($model));
        }
        return ['ok' => true, 'id' => $id, 'catalog_product_id' => $model, 'shared_intervals' => $shared,
                'message' => 'Added ' . ($row['name'] ?? 'it') . ' to equipment.' . ($shared ? " The manual's {$shared} service interval" . ($shared === 1 ? ' is' : 's are') . ' known for this model — use them on its page.' : ''),
                'url' => '/crm/ops/equipment-item.php?id=' . $id];
    }

    /** The purchase receipt for a machine (its cost then comes from the receipt). Null unlinks. */
    public function linkPurchase(int $id, ?int $expenseId): array
    {
        if (!$this->row($id)) return ['ok' => false, 'message' => 'Machine not found.'];
        if ($expenseId) {
            $s = $this->db->prepare("SELECT id FROM expenses WHERE id = ?");
            $s->execute([$expenseId]);
            if (!$s->fetchColumn()) return ['ok' => false, 'message' => 'Receipt #' . $expenseId . ' is not in Expenses.'];
        }
        $this->db->prepare("UPDATE equipment SET expense_id = ? WHERE id = ?")->execute([$expenseId ?: null, $id]);
        return ['ok' => true, 'message' => $expenseId ? 'Receipt #' . $expenseId . ' is the purchase now.' : 'Receipt unlinked.'];
    }

    public function row(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM equipment WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Receipts that could be this machine's purchase: the model or make on the receipt (vendor, notes, line
     * items), a nearby date, a close amount. Scored, best first; at most 6. Rejected receipts never.
     * @param array $q make, model, date (Y-m-d), cost, text
     */
    public function receiptCandidates(array $q): array
    {
        $make = trim((string)($q['make'] ?? ''));
        $model = trim((string)($q['model'] ?? ''));
        $text = trim((string)($q['text'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['date'] ?? '')) ? (string)$q['date'] : null;
        $cost = isset($q['cost']) && is_numeric($q['cost']) && (float)$q['cost'] > 0 ? (float)$q['cost'] : null;
        $words = array_values(array_filter([$model, $make, $text], fn($w) => mb_strlen($w) >= 3));
        if (!$words && !$date && !$cost) return [];
        $where = [];
        $args = [];
        foreach ($words as $w) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $w) . '%';
            $where[] = "(e.vendor_name_raw LIKE ? OR e.description LIKE ? OR e.notes LIKE ? OR EXISTS (SELECT 1 FROM expense_line_items li WHERE li.expense_id = e.id AND li.name LIKE ?))";
            array_push($args, $like, $like, $like, $like);
        }
        if ($date) { $where[] = "(e.expense_date BETWEEN ? AND ?)"; array_push($args, date('Y-m-d', strtotime($date . ' -21 days')), date('Y-m-d', strtotime($date . ' +21 days'))); }
        if ($cost) { $where[] = "(COALESCE(e.total, e.amount) BETWEEN ? AND ?)"; array_push($args, round($cost * 0.9, 2), round($cost * 1.1, 2)); }
        try {
            $st = $this->db->prepare("SELECT e.id, e.expense_date, COALESCE(e.total, e.amount) AS total, e.vendor_name_raw, e.description, e.accounting_category, e.status
                                      FROM expenses e WHERE (" . implode(' OR ', $where) . ") AND COALESCE(e.status, '') <> 'rejected'
                                      ORDER BY e.expense_date DESC LIMIT 200");
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $taken = [];
            foreach ($this->db->query("SELECT expense_id FROM equipment WHERE expense_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $x) $taken[(int)$x] = true;
        } catch (Throwable $e) {
            error_log('Equipment receipts: ' . $e->getMessage());
            return [];
        }
        $lines = [];
        if ($rows && ($model !== '' || $make !== '')) {
            $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
            foreach ($this->db->query("SELECT expense_id, name FROM expense_line_items WHERE expense_id IN ({$ids})")->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $lines[(int)$l['expense_id']][] = (string)$l['name'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $hay = mb_strtolower(implode(' ', [$r['vendor_name_raw'], $r['description'], implode(' ', $lines[(int)$r['id']] ?? [])]));
            $why = [];
            $score = 0;
            if ($model !== '' && strpos(preg_replace('/[^a-z0-9]/', '', $hay), preg_replace('/[^a-z0-9]/', '', mb_strtolower($model))) !== false) { $score += 4; $why[] = 'model on the receipt'; }
            if ($make !== '' && strpos($hay, mb_strtolower($make)) !== false) { $score += 2; $why[] = $make . ' on the receipt'; }
            if ($date && abs(strtotime((string)$r['expense_date']) - strtotime($date)) <= 3 * 86400) { $score += 2; $why[] = 'same week'; }
            elseif ($date && abs(strtotime((string)$r['expense_date']) - strtotime($date)) <= 21 * 86400) { $score += 1; $why[] = 'within 3 weeks'; }
            if ($cost && abs((float)$r['total'] - $cost) <= max(1.0, $cost * 0.02)) { $score += 3; $why[] = 'same amount'; }
            elseif ($cost && abs((float)$r['total'] - $cost) <= $cost * 0.1) { $score += 1; $why[] = 'close amount'; }
            if ($score < 2) continue;
            if (isset($taken[(int)$r['id']])) { $score -= 3; $why[] = 'already a machine\'s purchase'; }
            $out[] = ['id' => (int)$r['id'], 'date' => (string)$r['expense_date'], 'total' => $r['total'] !== null ? round((float)$r['total'], 2) : null,
                      'vendor' => (string)($r['vendor_name_raw'] ?: $r['description']), 'category' => $r['accounting_category'], 'score' => $score, 'why' => implode(', ', $why)];
        }
        usort($out, fn($a, $b) => [$b['score'], $b['date']] <=> [$a['score'], $a['date']]);
        return array_slice($out, 0, 6);
    }

    /** People and trucks/trailers for the form (web and iPhone). */
    public function formOptions(): array
    {
        $people = [];
        try {
            $people = $this->db->query("SELECT id, full_name AS name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* none */ }
        $trucks = [];
        try {
            $trucks = $this->db->query("SELECT id, name FROM equipment WHERE equipment_class IN ('truck', 'trailer') AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* none */ }
        $pairs = fn(array $a) => array_map(fn($k, $v) => ['value' => $k, 'label' => $v], array_keys($a), array_values($a));
        return ['classes' => $pairs(self::CLASS_LABELS), 'power' => $pairs(self::POWER_LABELS),
                'people' => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => (string)$p['name']], $people),
                'trucks' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name']], $trucks)];
    }

    // ── The machine's page ──────────────────────────────────────────────────

    /**
     * Every interval that applies to one item and where it stands (job-timer hours since the last logged
     * service, or since purchase). Shared by the machine page and Otto's care view.
     */
    public function intervalRows(array $item, ?array $intervals = null, ?array $lastByTask = null): array
    {
        $intervals = $intervals ?? $this->intervals();
        if ($lastByTask === null) {
            $s = $this->db->prepare("SELECT task, MAX(done_on) AS d FROM equipment_service_log WHERE equipment_id = ? GROUP BY task");
            $s->execute([(int)$item['id']]);
            $lastByTask = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $lastByTask[strtolower((string)$r['task'])] = (string)$r['d'];
        }
        $start = $item['purchase_date'] ?: substr((string)($item['created_at'] ?? $this->today), 0, 10);
        $rows = [];
        foreach (DispatchRules::intervalsFor($item, $intervals) as $iv) {
            $h = $iv['every_hours'] !== null ? (float)$iv['every_hours'] : null;
            $d = $iv['every_days'] !== null ? (int)$iv['every_days'] : null;
            $l = $lastByTask[strtolower(trim((string)$iv['task']))] ?? null;
            $from = $l ?? $start;
            $hours = $this->hoursSince($item, $from) + ($l === null ? (float)$item['hours_baseline'] : 0.0);
            $days = (int)floor((strtotime($this->today) - strtotime($from)) / 86400);
            $state = DispatchRules::intervalState($h, $d, $hours, $days);
            $every = [];
            if ($h) $every[] = rtrim(rtrim(number_format($h, 1, '.', ''), '0'), '.') . ' h';
            if ($d) $every[] = $d === 365 ? 'a year' : ($d === 1 ? 'day' : $d . ' days');
            $rows[] = ['id' => (int)$iv['id'], 'task' => (string)$iv['task'], 'every_hours' => $h, 'every_days' => $d,
                       'every' => $every ? 'every ' . implode(' or ', $every) : '', 'scope' => $iv['scope'] ?? 'class',
                       'hours' => round($hours, 1), 'days' => $days, 'last' => $l, 'state' => $state];
        }
        usort($rows, fn($a, $b) => [['due' => 0, 'soon' => 1, 'ok' => 2][$a['state']], $a['task']] <=> [['due' => 0, 'soon' => 1, 'ok' => 2][$b['state']], $b['task']]);
        return $rows;
    }

    /**
     * Money on one machine this year.
     *   own       receipts that are THIS machine's: its purchase (if bought this year, receipt or typed cost)
     *             and the receipts on its service log (repairs, parts)
     *   tagged    receipts tagged for the asset kind (asset_tag 'truck' or 'equipment' — fuel, repairs,
     *             tools) are shared by every active machine of that kind; this machine's share is the pool
     *             divided by that count. An estimate, and labelled so.
     */
    public function yearCost(array $item, ?int $year = null): array
    {
        $year = $year ?? (int)substr($this->today, 0, 4);
        $from = $year . '-01-01';
        $to = $year . '-12-31';
        $own = [];
        if ($item['purchase_date'] && substr((string)$item['purchase_date'], 0, 4) === (string)$year) {
            if (!empty($item['expense_id'])) {
                $s = $this->db->prepare("SELECT id, expense_date, COALESCE(total, amount) AS total, vendor_name_raw FROM expenses WHERE id = ?");
                $s->execute([(int)$item['expense_id']]);
                if ($x = $s->fetch(PDO::FETCH_ASSOC)) $own[] = ['kind' => 'Purchase', 'expense_id' => (int)$x['id'], 'date' => (string)$x['expense_date'], 'total' => (float)$x['total'], 'what' => (string)$x['vendor_name_raw']];
            } elseif ($item['cost_manual'] !== null) {
                $own[] = ['kind' => 'Purchase', 'expense_id' => null, 'date' => (string)$item['purchase_date'], 'total' => (float)$item['cost_manual'], 'what' => 'typed cost (no receipt)'];
            }
        }
        $s = $this->db->prepare("SELECT l.task, l.done_on, x.id, x.expense_date, COALESCE(x.total, x.amount) AS total, x.vendor_name_raw, x.accounting_category
                                 FROM equipment_service_log l JOIN expenses x ON x.id = l.expense_id
                                 WHERE l.equipment_id = ? AND x.expense_date BETWEEN ? AND ? AND COALESCE(x.status, '') <> 'rejected'
                                 ORDER BY x.expense_date");
        $s->execute([(int)$item['id'], $from, $to]);
        $seen = [(int)($item['expense_id'] ?? 0) => true];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $x) {
            if (isset($seen[(int)$x['id']])) continue;
            $seen[(int)$x['id']] = true;
            $own[] = ['kind' => stripos((string)$x['accounting_category'], 'repair') !== false ? 'Repair' : 'Parts / service', 'expense_id' => (int)$x['id'],
                      'date' => (string)$x['expense_date'], 'total' => (float)$x['total'], 'what' => $x['task'] . ' — ' . $x['vendor_name_raw']];
        }
        $tag = in_array($item['equipment_class'], ['truck', 'trailer'], true) ? 'truck' : 'equipment';
        $units = 1;
        $pool = [];
        $tagged = [];
        // Battery and electric machines burn no fuel; they still share the tag's repairs and tools.
        try {
            $classes = $tag === 'truck' ? "('truck')" : "('mower','trimmer','blower','hedge_trimmer','saw','aerator','battery_pack','other')";
            $units = max(1, (int)$this->db->query("SELECT COUNT(*) FROM equipment WHERE status = 'active' AND equipment_class IN {$classes}")->fetchColumn());
            $st = $this->db->prepare("SELECT id, expense_date, COALESCE(total, amount) AS total, vendor_name_raw, accounting_category FROM expenses
                                      WHERE asset_tag = ? AND expense_date BETWEEN ? AND ? AND COALESCE(status, '') <> 'rejected' ORDER BY expense_date DESC");
            $st->execute([$tag, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
                if (isset($seen[(int)$x['id']])) continue;
                $cat = (string)($x['accounting_category'] ?: 'Other');
                $isFuel = stripos($cat, 'fuel') !== false;
                if ($isFuel && in_array($item['power_source'], ['battery', 'electric'], true)) continue;
                $pool[$cat] = round(($pool[$cat] ?? 0) + (float)$x['total'], 2);
                $tagged[] = ['expense_id' => (int)$x['id'], 'date' => (string)$x['expense_date'], 'total' => round((float)$x['total'], 2),
                             'vendor' => (string)$x['vendor_name_raw'], 'category' => $cat];
            }
        } catch (Throwable $e) { /* asset_tag not migrated (1125) */ }
        $ownTotal = round(array_sum(array_column($own, 'total')), 2);
        $poolTotal = round(array_sum($pool), 2);
        $share = round($poolTotal / $units, 2);
        return ['year' => $year, 'own' => $own, 'own_total' => $ownTotal, 'tag' => $tag, 'pool' => $pool, 'pool_total' => $poolTotal,
                'units' => $units, 'share' => $share, 'tagged' => array_slice($tagged, 0, 25), 'total' => round($ownTotal + $share, 2)];
    }

    /** Everything the machine's page shows (web) — the iPhone uses the same through equipment-mobile. */
    public function detail(int $id): ?array
    {
        $items = array_values(array_filter($this->items(), fn($r) => (int)$r['id'] === $id));
        if (!$items) return null;
        $item = $items[0];
        $s = $this->db->prepare("SELECT l.*, x.total AS receipt_total, x.vendor_name_raw FROM equipment_service_log l LEFT JOIN expenses x ON x.id = l.expense_id
                                 WHERE l.equipment_id = ? ORDER BY l.done_on DESC, l.id DESC LIMIT 50");
        $s->execute([$id]);
        $log = $s->fetchAll(PDO::FETCH_ASSOC);
        $model = null;
        if (!empty($item['catalog_product_id'])) {
            require_once __DIR__ . '/EquipmentCatalogService.php';
            $ec = new EquipmentCatalogService($this->db);
            $pid = (int)$item['catalog_product_id'];
            if ($p = $ec->product($pid)) {
                $model = ['product' => $p, 'attributes' => $ec->attributes($pid), 'parts' => $ec->parts($pid),
                          'shared_intervals' => $ec->sharedIntervals($pid), 'not_adopted' => $ec->notAdopted($pid), 'units' => $ec->unitsOfModel($pid)];
            }
        }
        $manual = [];
        try {
            $m = $this->db->prepare("SELECT id, file_name, error, created_at FROM equipment_manual_reads WHERE equipment_id = ? ORDER BY id DESC LIMIT 5");
            $m->execute([$id]);
            $manual = $m->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* 1225 not run */ }
        return ['item' => $item, 'intervals' => $this->intervalRows($item), 'log' => $log, 'model' => $model, 'cost' => $this->yearCost($item),
                'hours' => in_array($item['equipment_class'], self::TIMED, true)
                           ? round((float)$item['hours_baseline'] + $this->hoursSince($item, $item['purchase_date'] ?: substr((string)$item['created_at'], 0, 10)), 1) : null,
                'manual_reads' => $manual];
    }

    // ── Hours ───────────────────────────────────────────────────────────────

    /** Hours of use since a date: the crew lead's job-timer minutes on visits that use this class. */
    public function hoursSince(array $item, string $since): float
    {
        if (empty($item['assigned_user_id']) || !in_array($item['equipment_class'], self::TIMED, true)) return 0.0;
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
