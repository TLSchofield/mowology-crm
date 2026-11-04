// Run: scripts/prod-run.sh scripts/ocr-compare-report.snippet.php   (read-only; no opening php tag, prod-run adds it)
// Per engine and per vendor: how often the phone's own text read agreed with the server's Google Vision read.
$db = getDB();
$out = ['by_engine' => [], 'worst_vendors' => [], 'by_field' => []];
$rows = $db->query("SELECT engine, COUNT(*) n, SUM(fields_agree) agree, SUM(fields_compared) cmp,
                           SUM(fields_agree = fields_compared) perfect
                    FROM receipt_ocr_compare GROUP BY engine")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $out['by_engine'][$r['engine']] = ['receipts' => (int)$r['n'], 'field_agreement_pct' => $r['cmp'] ? round(100 * $r['agree'] / $r['cmp'], 1) : null,
                                       'fully_matching_receipts_pct' => round(100 * $r['perfect'] / max(1, $r['n']), 1)];
}
$vend = $db->query("SELECT COALESCE(v.name, CONCAT('vendor #', c.vendor_id), 'unmatched') name, c.engine, COUNT(*) n,
                           ROUND(100 * SUM(c.fields_agree) / NULLIF(SUM(c.fields_compared), 0), 1) pct
                    FROM receipt_ocr_compare c LEFT JOIN vendors v ON v.id = c.vendor_id
                    GROUP BY c.vendor_id, c.engine HAVING n >= 2 ORDER BY pct ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$out['worst_vendors'] = $vend;
$fields = [];
foreach ($db->query("SELECT engine, detail_json FROM receipt_ocr_compare")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    foreach ((json_decode($r['detail_json'], true) ?: []) as $f => $c) {
        $k = $r['engine'] . '.' . $f;
        $fields[$k][0] = ($fields[$k][0] ?? 0) + 1;
        $fields[$k][1] = ($fields[$k][1] ?? 0) + (!empty($c['match']) ? 1 : 0);
    }
}
foreach ($fields as $k => [$n, $ok]) $out['by_field'][$k] = round(100 * $ok / $n, 1) . '% of ' . $n;
echo json_encode($out, JSON_PRETTY_PRINT);
