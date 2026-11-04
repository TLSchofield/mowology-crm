// scripts/receipt-corpus-export.php — prod-run snippet (no <?php): approved receipts with their stored OCR text and the
// approved header values, for scripts/receipt-replay.php. Read-only. Output is business data: write it OUTSIDE git:
//   mkdir -p ~/.mowology-corpus && scripts/prod-run.sh scripts/receipt-corpus-export.php > ~/.mowology-corpus/receipts-$(date +%F).json
// Never give the output to Codex.
$cols = array_column($db->query("SHOW COLUMNS FROM expenses")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$want = array_values(array_intersect(['id','expense_date','vendor_id','vendor_name_raw','amount','tax_amount','gst_amount','pst_amount','total','status','raw_ocr_json','ocr_parsed_json','line_items_source','receipt_media_id','updated_at'], $cols));
$sql = "SELECT e." . implode(', e.', $want) . ", v.name AS vendor_name FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
        WHERE e.status IN ('approved','forwarded') AND e.raw_ocr_json IS NOT NULL AND e.raw_ocr_json <> '' ORDER BY e.id";
$out['columns'] = $want;
$out['rows'] = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
// The phone's own read of the same photo (migration 1433), latest per photo, so the replay can score both engines.
try {
    $dev = $db->query("SELECT c.media_id, c.engine, c.device_text FROM receipt_ocr_compare c
                       WHERE c.device_text IS NOT NULL AND c.device_text <> '' ORDER BY c.id")->fetchAll(PDO::FETCH_ASSOC);
    $byMedia = [];
    foreach ($dev as $d) $byMedia[(int)$d['media_id']] = $d;
    foreach ($out['rows'] as &$r) {
        $d = $byMedia[(int)($r['receipt_media_id'] ?? 0)] ?? null;
        if ($d) { $r['device_text'] = $d['device_text']; $r['device_engine'] = $d['engine']; }
    }
    unset($r);
    $out['with_device_text'] = count(array_filter($out['rows'], fn($r) => isset($r['device_text'])));
} catch (Throwable $e) {
    $out['with_device_text'] = 'migration 1433 not run';
}
$out['count'] = count($out['rows']);
