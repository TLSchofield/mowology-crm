<?php
declare(strict_types=1);

/** Nightly, deterministic score of the receipt parser against approved receipts. */
class ReceiptParserScoreService
{
    private PDO $db;
    private static array $logged = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Text, raw Vision response, and stored shape. */
    public static function replayInput(string $stored): array
    {
        $s = ltrim($stored);
        if ($s !== '' && ($s[0] === '{' || $s[0] === '[')) {
            $j = json_decode($s, true);
            if (is_array($j)) {
                if (isset($j['responses'][0])) {
                    $r = $j['responses'][0];
                    $text = (string)($r['fullTextAnnotation']['text'] ?? ($r['textAnnotations'][0]['description'] ?? ''));
                    return [$text, $j, 'vision_json'];
                }
                if (isset($j['text'])) return [(string)$j['text'], null, 'inbox_json'];
            }
        }
        return [$stored, null, 'text'];
    }

    public static function truthOf(array $row, string $field): ?string
    {
        $map = ['total' => 'total', 'subtotal' => 'amount', 'gst' => 'gst_amount', 'pst' => 'pst_amount', 'date' => 'expense_date'];
        $v = $row[$map[$field]] ?? null;
        if ($v === null || $v === '') return null;
        if ($field === 'date') return substr((string)$v, 0, 10);
        if ($field === 'subtotal' && (float)$v == 0.0 && (float)($row['total'] ?? 0) > 0) return null;
        return number_format((float)$v, 2, '.', '');
    }

    public static function sameValue(string $field, ?string $truth, $got): bool
    {
        if ($field === 'date') return $truth !== null && (string)$got === $truth;
        $t = $truth === null ? 0.0 : (float)$truth;
        $g = ($got === null || $got === '') ? 0.0 : (float)$got;
        return abs($t - $g) < 0.011;
    }

    public static function totalMissKind(?string $truth, $got): string
    {
        if ($got === null || $got === '') return 'no total read';
        $t = (float)$truth; $g = (float)$got;
        if ($t <= 0) return 'approved total is zero';
        foreach (['1.05' => 'read the pre-tax amount (GST 5%)', '1.12' => 'read the pre-tax amount (GST+PST 12%)', '1.07' => 'read the pre-tax amount (PST 7%)'] as $f => $label) {
            if (abs($t / (float)$f - $g) < 0.03) return $label;
        }
        if ($g > $t) return 'read a larger number (payment, tendered, balance?)';
        return 'read a smaller number (an item or tax line?)';
    }

    /** Pure corpus scorer. The callable has parseReceiptText's three arguments. */
    public static function score(array $rows, callable $parse): array
    {
        $fields = ['total', 'subtotal', 'gst', 'pst', 'date'];
        $results = [];
        $summary = array_fill_keys($fields, ['right' => 0, 'of' => 0]);
        $deviceSummary = []; $misses = []; $shapes = []; $deviceReceipts = 0; $byVendor = [];
        foreach ($rows as $row) {
            [$text, $raw, $shape] = self::replayInput((string)($row['raw_ocr_json'] ?? ''));
            $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
            try { $p = $parse($text, $raw, null); $err = null; }
            catch (Throwable $e) { $p = []; $err = get_class($e) . ': ' . $e->getMessage(); }
            $vendor = (string)($row['vendor_name'] ?? $row['vendor_name_raw'] ?? '?');
            $id = (int)($row['id'] ?? 0);
            $res = ['id' => $id, 'vendor' => $vendor, 'shape' => $shape, 'error' => $err, 'fields' => []];
            foreach ($fields as $field) {
                $truth = self::truthOf($row, $field);
                if ($truth === null && $field !== 'gst' && $field !== 'pst') continue;
                $got = $p[$field] ?? null;
                $ok = self::sameValue($field, $truth, $got);
                $res['fields'][$field] = ['truth' => $truth, 'got' => $got, 'ok' => $ok];
                $summary[$field]['of']++;
                if ($ok) $summary[$field]['right']++;
                $byVendor[$vendor][$field]['of'] = ($byVendor[$vendor][$field]['of'] ?? 0) + 1;
                $byVendor[$vendor][$field]['right'] = ($byVendor[$vendor][$field]['right'] ?? 0) + ($ok ? 1 : 0);
                if ($field === 'total' && !$ok) {
                    $kind = self::totalMissKind($truth, $got);
                    $misses[$kind]['n'] = ($misses[$kind]['n'] ?? 0) + 1;
                    $misses[$kind]['vendors'][$vendor] = true;
                    if (count($misses[$kind]['examples'] ?? []) < 5) $misses[$kind]['examples'][] = $id;
                }
            }
            if (!empty($row['device_text'])) {
                $deviceReceipts++;
                try { $dp = $parse((string)$row['device_text'], null, null); }
                catch (Throwable $e) { $dp = []; }
                foreach ($fields as $field) {
                    $truth = self::truthOf($row, $field);
                    if ($truth === null && $field !== 'gst' && $field !== 'pst') continue;
                    $ok = self::sameValue($field, $truth, $dp[$field] ?? null);
                    $res['device'][$field] = ['got' => $dp[$field] ?? null, 'ok' => $ok];
                    $deviceSummary[$field]['of'] = ($deviceSummary[$field]['of'] ?? 0) + 1;
                    $deviceSummary[$field]['right'] = ($deviceSummary[$field]['right'] ?? 0) + ($ok ? 1 : 0);
                }
            }
            $results[$id] = $res;
        }
        foreach ($misses as &$miss) {
            $miss['count'] = $miss['n'];
            $miss['vendor_count'] = count($miss['vendors']);
            $miss['distinct_vendor_count'] = $miss['vendor_count'];
        }
        unset($miss);
        foreach ($deviceSummary as $field => $counts) {
            $deviceSummary[$field] = ['right' => (int)($counts['right'] ?? 0), 'of' => (int)($counts['of'] ?? 0)];
        }
        uasort($misses, function ($a, $b) { return $b['n'] <=> $a['n']; });
        return ['receipts' => count($results), 'summary' => $summary, 'fields' => $summary,
                'device_summary' => $deviceSummary, 'device' => $deviceSummary['total'] ?? ['right' => 0, 'of' => 0],
                'device_receipts' => $deviceReceipts, 'misses' => $misses, 'results' => $results,
                'shapes' => $shapes, 'by_vendor' => $byVendor];
    }

    public function run(?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        try {
            $columns = [];
            foreach ($this->db->query('SHOW COLUMNS FROM expenses')->fetchAll(PDO::FETCH_ASSOC) as $column) $columns[$column['Field']] = true;
            $wanted = ['id','expense_date','vendor_id','vendor_name_raw','amount','gst_amount','pst_amount','total','raw_ocr_json','receipt_media_id'];
            foreach (['id','expense_date','amount','total','status','raw_ocr_json'] as $required) if (!isset($columns[$required])) return $this->skip('expenses.' . $required . ' is missing');
            $selected = array_values(array_filter($wanted, function ($column) use ($columns) { return isset($columns[$column]); }));
            $vendor = isset($columns['vendor_id']) ? ' LEFT JOIN vendors v ON v.id = e.vendor_id' : '';
            $vendorName = isset($columns['vendor_id']) ? ', v.name AS vendor_name' : '';
            $sql = 'SELECT e.' . implode(', e.', $selected) . $vendorName . ' FROM expenses e' . $vendor
                 . " WHERE e.status IN ('approved','forwarded') AND e.raw_ocr_json IS NOT NULL AND e.raw_ocr_json <> '' ORDER BY e.id";
            $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if (isset($columns['receipt_media_id'])) $this->addDeviceText($rows);
            require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
            $score = self::score($rows, 'parseReceiptText');
            $parserFile = APP_ROOT . '/Services/Receipts/ReceiptParser.php';
            $score['parser_hash'] = md5_file($parserFile) ?: str_repeat('0', 32);
            $score['run_date'] = $today;
            $s = $score['summary']; $d = $score['device_summary']['total'] ?? ['right' => 0, 'of' => 0];
            $storedMisses = [];
            foreach ($score['misses'] as $kind => $miss) $storedMisses[$kind] = ['count' => $miss['n'], 'vendors' => $miss['vendor_count'], 'examples' => $miss['examples']];
            $values = [$today, $score['parser_hash'], $score['receipts']];
            foreach (['total','subtotal','gst','pst','date'] as $field) { $values[] = $s[$field]['right']; $values[] = $s[$field]['of']; }
            array_push($values, $score['device_receipts'], $d['right'], $d['of'], json_encode($storedMisses));
            $sql = 'INSERT INTO receipt_parser_runs (run_date, parser_hash, receipts, total_right, total_of, subtotal_right, subtotal_of, gst_right, gst_of, pst_right, pst_of, date_right, date_of, device_receipts, device_total_right, device_total_of, misses_json)'
                 . ' VALUES (' . implode(',', array_fill(0, 17, '?')) . ') ON DUPLICATE KEY UPDATE parser_hash=VALUES(parser_hash), receipts=VALUES(receipts), total_right=VALUES(total_right), total_of=VALUES(total_of), subtotal_right=VALUES(subtotal_right), subtotal_of=VALUES(subtotal_of), gst_right=VALUES(gst_right), gst_of=VALUES(gst_of), pst_right=VALUES(pst_right), pst_of=VALUES(pst_of), date_right=VALUES(date_right), date_of=VALUES(date_of), device_receipts=VALUES(device_receipts), device_total_right=VALUES(device_total_right), device_total_of=VALUES(device_total_of), misses_json=VALUES(misses_json)';
            $this->db->prepare($sql)->execute($values);
            return $score;
        } catch (Throwable $e) { return $this->skip($e->getMessage()); }
    }

    public function latestTwo(): array
    {
        try { return $this->db->query('SELECT * FROM receipt_parser_runs ORDER BY run_date DESC, id DESC LIMIT 2')->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { $this->logOnce($e->getMessage()); return []; }
    }

    private function addDeviceText(array &$rows): void
    {
        try {
            $has = false;
            foreach ($this->db->query('SHOW COLUMNS FROM receipt_ocr_compare')->fetchAll(PDO::FETCH_ASSOC) as $column) if ($column['Field'] === 'device_text') $has = true;
            if (!$has) return;
            $device = $this->db->query("SELECT media_id, device_text FROM receipt_ocr_compare WHERE device_text IS NOT NULL AND device_text <> '' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $byMedia = [];
            foreach ($device as $item) $byMedia[(int)$item['media_id']] = $item['device_text'];
            foreach ($rows as &$row) if (isset($byMedia[(int)($row['receipt_media_id'] ?? 0)])) $row['device_text'] = $byMedia[(int)$row['receipt_media_id']];
            unset($row);
        } catch (Throwable $e) { $this->logOnce($e->getMessage()); }
    }

    private function skip(string $message): array
    {
        $this->logOnce($message);
        return ['skipped' => true, 'reason' => $message];
    }

    private function logOnce(string $message): void
    {
        $key = md5($message);
        if (isset(self::$logged[$key])) return;
        self::$logged[$key] = true;
        error_log('Receipt parser score skipped: ' . $message);
    }
}
