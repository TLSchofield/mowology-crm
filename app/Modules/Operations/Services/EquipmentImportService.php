<?php
/**
 * EquipmentImportService — bring a whole equipment list in from a CSV or .xlsx (Tim 2026-10-13).
 *
 * Columns are found by header, in any order, with the usual spellings:
 *   name · make/brand/manufacturer · model · serial · type/class · power · purchase date · cost/price ·
 *   truck/trailer/vehicle · used by/assigned to · hours · notes
 * preview() reads the file (SheetReader — the same reader as supplier price lists) and says what each row
 * would become, with problems; commit() adds the good rows through EquipmentService::save(), which links
 * each model to the shared catalogue. A serial already registered is skipped, never doubled. Cost is the
 * typed cost (cost_manual); link the purchase receipt later on the machine's page.
 * Dealer PARTS lists are not this: they come in as supplier price lists (SupplierPriceListService) and
 * match a model's parts by part number (EquipmentCatalogService::partProduct()).
 *
 * No namespace: require_once and `new`. SQL is portable (MySQL and SQLite for tests).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Services/Spreadsheet/SheetReader.php';
require_once __DIR__ . '/EquipmentService.php';
require_once __DIR__ . '/EquipmentPlateParser.php';

class EquipmentImportService
{
    public const MAX_ROWS = 500;
    /** field => header spellings (folded: lower case, letters and digits only) */
    public const HEADERS = [
        'name' => ['name', 'nickname', 'item', 'equipment', 'description', 'machine', 'unit'],
        'make' => ['make', 'brand', 'manufacturer', 'mfr', 'maker'],
        'model' => ['model', 'modelno', 'modelnumber', 'mn', 'mod'],
        'serial_no' => ['serial', 'serialno', 'serialnumber', 'sn', 'ser', 'vin'],
        'equipment_class' => ['type', 'class', 'kind', 'category'],
        'power_source' => ['power', 'fuel', 'powersource'],
        'purchase_date' => ['purchasedate', 'purchased', 'bought', 'date', 'dateofpurchase', 'datepurchased', 'boughton'],
        'cost' => ['cost', 'price', 'purchaseprice', 'amount', 'paid', 'value'],
        'truck' => ['truck', 'trailer', 'vehicle', 'rideson', 'assignedtruck'],
        'person' => ['usedby', 'assignedto', 'operator', 'person', 'crew', 'user'],
        'hours' => ['hours', 'hourmeter', 'hrs', 'enginehours'],
        'notes' => ['notes', 'note', 'comments', 'comment'],
    ];

    private PDO $db;
    private EquipmentService $eq;

    public function __construct(PDO $db, ?EquipmentService $eq = null)
    {
        $this->db = $db;
        $this->eq = $eq ?? new EquipmentService($db);
    }

    /** "Serial No." → "serialno". PURE. */
    public static function fold(string $h): string
    {
        return (string)preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($h)));
    }

    /**
     * Header row → [field => column letters]. The header is the first row (within the top 10) that names
     * at least two known fields. PURE.
     * @param array $sheet SheetReader shape [row => [col => value]]
     * @return array{row: ?int, map: array<string, string>}
     */
    public static function headerMap(array $sheet): array
    {
        foreach (array_slice($sheet, 0, 10, true) as $r => $cells) {
            $map = [];
            foreach ($cells as $col => $v) {
                $f = self::fold((string)$v);
                if ($f === '') continue;
                foreach (self::HEADERS as $field => $names) {
                    if (isset($map[$field])) continue;
                    if (in_array($f, $names, true)) { $map[$field] = (string)$col; break; }
                }
            }
            if (count($map) >= 2 && (isset($map['name']) || isset($map['model']) || isset($map['make']))) return ['row' => (int)$r, 'map' => $map];
        }
        return ['row' => null, 'map' => []];
    }

    /** "Stihl", "Weed eater", "battery" → our class / power words. PURE. */
    public static function classOf(string $v, string $context = ''): ?string
    {
        $f = self::fold($v);
        if ($f !== '') {
            foreach (EquipmentService::CLASS_LABELS as $k => $label) {
                if ($f === self::fold($k) || $f === self::fold($label) || $f === self::fold($label) . 's') return $k;
            }
        }
        foreach (EquipmentPlateParser::CLASS_WORDS as $class => $re) {
            if (preg_match($re, $v . ' ' . $context)) return $class;
        }
        return null;
    }

    public static function powerOf(string $v): ?string
    {
        $f = self::fold($v);
        if (in_array($f, ['gas', 'gasoline', 'petrol', '2stroke', '4stroke', 'mixed'], true)) return 'gas';
        if (in_array($f, ['battery', 'cordless', 'lithium', 'electricbattery'], true)) return 'battery';
        if (in_array($f, ['electric', 'corded', 'plugin'], true)) return 'electric';
        if ($f === 'diesel') return 'diesel';
        return null;
    }

    /** "2024-04-15", "15/04/2024", "Apr 15 2024", an Excel serial → Y-m-d, or null. PURE. */
    public static function dateOf($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{5}(\.\d+)?$/', $v))) return XlsxReader::excelDate($v);
        $v = trim((string)$v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $v, $m)) {
            // Canada: day first when the first number can't be a month; month first otherwise (as Excel exports it).
            [$a, $b, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            [$mo, $d] = $a > 12 ? [$b, $a] : [$a, $b];
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
        $t = strtotime($v);
        return $t ? date('Y-m-d', $t) : null;
    }

    public static function moneyOf($v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v)) return round((float)$v, 2);
        $s = (string)preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$v));
        return is_numeric($s) ? round((float)$s, 2) : null;
    }

    /**
     * Read a file and say what each row would become.
     * @return array{ok: bool, message: string, rows: array, ready: int, problems: int, header_row?: ?int, columns?: array}
     */
    public function preview(string $bytes, string $fileName): array
    {
        try {
            $sheet = SheetReader::read($bytes, $fileName);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'I can read .csv or .xlsx files. Save the list as one of those.', 'rows' => [], 'ready' => 0, 'problems' => 0];
        }
        $h = self::headerMap($sheet);
        if ($h['row'] === null) {
            return ['ok' => false, 'message' => 'I could not find a header row. Put column names in the first row: Name, Make, Model, Serial, Purchase date, Cost, Truck.',
                    'rows' => [], 'ready' => 0, 'problems' => 0];
        }
        $trucks = $people = $serials = [];
        try {
            foreach ($this->db->query("SELECT id, name FROM equipment WHERE equipment_class IN ('truck', 'trailer')")->fetchAll(PDO::FETCH_ASSOC) as $t) $trucks[self::fold((string)$t['name'])] = (int)$t['id'];
            foreach ($this->db->query("SELECT UPPER(serial_no) AS s, name FROM equipment WHERE serial_no IS NOT NULL AND serial_no <> ''")->fetchAll(PDO::FETCH_ASSOC) as $x) $serials[(string)$x['s']] = (string)$x['name'];
            foreach ($this->db->query("SELECT id, full_name FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $people[self::fold((string)$u['full_name'])] = (int)$u['id'];
                $first = self::fold((string)strtok((string)$u['full_name'], ' '));
                if ($first !== '' && !isset($people[$first])) $people[$first] = (int)$u['id'];
            }
        } catch (Throwable $e) { /* empty lists */ }

        $rows = [];
        $seenSerial = [];
        foreach ($sheet as $r => $cells) {
            if ($r <= $h['row']) continue;
            $get = fn(string $f) => isset($h['map'][$f]) ? ($cells[$h['map'][$f]] ?? null) : null;
            $str = fn(string $f) => trim((string)($get($f) ?? ''));
            if (implode('', array_map(fn($f) => $str($f), array_keys($h['map']))) === '') continue;
            if (count($rows) >= self::MAX_ROWS) break;
            $make = $str('make');
            $model = $str('model');
            $name = $str('name');
            $classRaw = $str('equipment_class');
            $class = self::classOf($classRaw, $name);
            if ($class === null && $model !== '' && $make !== '') {
                $guess = EquipmentPlateParser::parse("{$make}\nMODEL {$model}");
                $class = $guess['equipment_class'];
            }
            $power = self::powerOf($str('power_source'));
            if ($power === null && $make !== '') $power = EquipmentPlateParser::parse("{$make}\nMODEL {$model}")['power_source'];
            if ($name === '') $name = trim(implode(' ', array_filter([$make, $model, $class ? mb_strtolower(EquipmentService::CLASS_LABELS[$class]) : null])));
            $serial = $str('serial_no');
            $truckRaw = $str('truck');
            $personRaw = $str('person');
            $out = [
                'line' => (int)$r, 'name' => mb_substr($name, 0, 120), 'make' => $make, 'model' => $model, 'serial_no' => $serial,
                'equipment_class' => $class ?? 'other', 'power_source' => $power ?? 'gas',
                'purchase_date' => self::dateOf($get('purchase_date')), 'cost_manual' => self::moneyOf($get('cost')),
                'truck_id' => $truckRaw !== '' ? ($trucks[self::fold($truckRaw)] ?? null) : null,
                'assigned_user_id' => $personRaw !== '' ? ($people[self::fold($personRaw)] ?? null) : null,
                'hours_baseline' => self::moneyOf($get('hours')), 'notes' => $str('notes'),
                'problems' => [], 'status' => 'ready',
            ];
            if ($str('purchase_date') !== '' && $out['purchase_date'] === null) $out['problems'][] = 'date "' . $str('purchase_date') . '" not read';
            if ($str('cost') !== '' && $out['cost_manual'] === null) $out['problems'][] = 'cost "' . $str('cost') . '" not read';
            if ($truckRaw !== '' && $out['truck_id'] === null) {
                $out['problems'][] = 'no truck or trailer called "' . $truckRaw . '" yet — kept in notes';
                $out['notes'] = trim($out['notes'] . ' Truck: ' . $truckRaw);
            }
            if ($personRaw !== '' && $out['assigned_user_id'] === null) $out['problems'][] = 'no person "' . $personRaw . '"';
            if ($classRaw !== '' && self::classOf($classRaw) === null) $out['problems'][] = 'type "' . $classRaw . '" read as ' . mb_strtolower(EquipmentService::CLASS_LABELS[$out['equipment_class']]);
            if ($out['name'] === '') { $out['status'] = 'skip'; $out['problems'][] = 'no name, make or model'; }
            $key = strtoupper($serial);
            if ($serial !== '' && isset($serials[$key])) { $out['status'] = 'skip'; $out['problems'][] = 'serial already in equipment (' . $serials[$key] . ')'; }
            elseif ($serial !== '' && isset($seenSerial[$key])) { $out['status'] = 'skip'; $out['problems'][] = 'same serial as line ' . $seenSerial[$key]; }
            if ($serial !== '') $seenSerial[$key] = $seenSerial[$key] ?? (int)$r;
            $rows[] = $out;
        }
        $ready = count(array_filter($rows, fn($x) => $x['status'] === 'ready'));
        return ['ok' => true, 'rows' => $rows, 'ready' => $ready, 'problems' => count(array_filter($rows, fn($x) => $x['problems'])),
                'header_row' => $h['row'], 'columns' => array_keys($h['map']),
                'message' => $rows ? "{$ready} of " . count($rows) . ' rows ready to add.' : 'The file has a header but no rows.'];
    }

    /**
     * Add the ready rows. Reads the file again (the preview is never trusted from the browser).
     * @return array{ok: bool, message: string, added: int, skipped: int, ids: int[]}
     */
    public function commit(string $bytes, string $fileName): array
    {
        $p = $this->preview($bytes, $fileName);
        if (!$p['ok']) return $p + ['added' => 0, 'skipped' => 0, 'ids' => []];
        $ids = [];
        $skipped = 0;
        foreach ($p['rows'] as $row) {
            if ($row['status'] !== 'ready') { $skipped++; continue; }
            $in = array_intersect_key($row, array_flip(['name', 'make', 'model', 'serial_no', 'equipment_class', 'power_source', 'purchase_date', 'cost_manual',
                                                        'truck_id', 'assigned_user_id', 'hours_baseline', 'notes']));
            $in['status'] = 'active';
            $in['hours_baseline'] = $in['hours_baseline'] ?? 0;
            $r = $this->eq->save($in);
            if ($r['ok']) $ids[] = (int)$r['id']; else $skipped++;
        }
        $n = count($ids);
        return ['ok' => true, 'added' => $n, 'skipped' => $skipped, 'ids' => $ids,
                'message' => 'Added ' . $n . ' machine' . ($n === 1 ? '' : 's') . ' to equipment' . ($skipped ? ", skipped {$skipped}." : '.')];
    }
}
