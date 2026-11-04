<?php
/**
 * scripts/receipt-replay.php — score the receipt parser against receipts whose true values are known (2026-11-04).
 *
 * Why: a parser fix used to be proven on the one receipt that broke, so a Chevron fix could quietly break
 * Home Depot. This runs the CURRENT parser over every approved receipt's stored OCR text and scores each
 * header field against what was approved. Compare two runs and it lists exactly what a change fixed and broke.
 *
 * The corpus is business data: it lives in ~/.mowology-corpus/ (outside git) and is never given to Codex.
 * Make it with:  scripts/prod-run.sh <export snippet> > ~/.mowology-corpus/receipts-YYYY-MM-DD.json
 * (see scripts/receipt-corpus-export.php for the snippet).
 *
 * Usage:
 *   php scripts/receipt-replay.php <corpus.json> [--out run.json] [--baseline earlier-run.json] [--misses total]
 * Exit: 0 ok · 1 usage · 3 a --baseline comparison found receipts made worse (regressions)
 *
 * Scope: the pure parser only (parseReceiptText with Vision's raw response when stored). Vendor line-item
 * profiles, the header ladder's AI read and the phone's text are not replayed, so these numbers are the
 * deterministic floor, not what a user sees after every fallback.
 */
declare(strict_types=1);

$args = $argv;
array_shift($args);
$corpus = null; $outFile = null; $baseFile = null; $showMisses = null;
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--out')            { $outFile = $args[++$i] ?? null; }
    elseif ($a === '--baseline')   { $baseFile = $args[++$i] ?? null; }
    elseif ($a === '--misses')     { $showMisses = $args[++$i] ?? null; }
    elseif ($corpus === null)      { $corpus = $a; }
}
if ($corpus === null || !is_file($corpus)) {
    fwrite(STDERR, "usage: php scripts/receipt-replay.php <corpus.json> [--out run.json] [--baseline run.json] [--misses field]\n");
    exit(1);
}

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) define('APP_ROOT', $root . '/app');
require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';

$data = json_decode((string)file_get_contents($corpus), true);
$rows = $data['rows'] ?? (is_array($data) ? $data : []);
if (!$rows) { fwrite(STDERR, "no rows in $corpus\n"); exit(1); }

/** The text and raw Vision response a stored raw_ocr_json held, in any of the three shapes on file. */
function replayInput(string $stored): array
{
    $s = ltrim($stored);
    if ($s !== '' && ($s[0] === '{' || $s[0] === '[')) {
        $j = json_decode($s, true);
        if (is_array($j)) {
            if (isset($j['responses'][0])) {               // full Google Vision response
                $r = $j['responses'][0];
                $text = (string)($r['fullTextAnnotation']['text'] ?? ($r['textAnnotations'][0]['description'] ?? ''));
                return [$text, $j, 'vision_json'];
            }
            if (isset($j['text'])) {                        // emailed receipt: {text, parsed, source}
                return [(string)$j['text'], null, 'inbox_json'];
            }
        }
    }
    return [$stored, null, 'text'];
}

/** Approved value for a field; a stored 0.00 tax means "none printed", same as the parser's null. */
function truthOf(array $row, string $field): ?string
{
    $map = ['total' => 'total', 'subtotal' => 'amount', 'gst' => 'gst_amount', 'pst' => 'pst_amount', 'date' => 'expense_date'];
    $v = $row[$map[$field]] ?? null;
    if ($v === null || $v === '') return null;
    if ($field === 'date') return substr((string)$v, 0, 10);
    // A 0.00 pre-tax amount on a receipt with a total means nobody filled it in, not that it was zero.
    if ($field === 'subtotal' && (float)$v == 0.0 && (float)($row['total'] ?? 0) > 0) return null;
    return number_format((float)$v, 2, '.', '');
}

function sameValue(string $field, ?string $truth, $got): bool
{
    if ($field === 'date') return $truth !== null && (string)$got === $truth;
    $t = $truth === null ? 0.0 : (float)$truth;
    $g = ($got === null || $got === '') ? 0.0 : (float)$got;
    return abs($t - $g) < 0.011;
}

/** Why a total was wrong, in words a pattern can be grouped by. */
function totalMissKind(?string $truth, $got): string
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

$fields = ['total', 'subtotal', 'gst', 'pst', 'date'];
$results = [];
$summary = array_fill_keys($fields, ['right' => 0, 'of' => 0]);
$byVendor = [];
$missKinds = [];
$shapes = [];
$deviceSummary = [];

foreach ($rows as $row) {
    [$text, $raw, $shape] = replayInput((string)($row['raw_ocr_json'] ?? ''));
    $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
    try {
        $p = parseReceiptText($text, $raw, null);
        $err = null;
    } catch (Throwable $e) {
        $p = []; $err = get_class($e) . ': ' . $e->getMessage();
    }
    $vendor = (string)($row['vendor_name'] ?? $row['vendor_name_raw'] ?? '?');
    $res = ['id' => (int)$row['id'], 'vendor' => $vendor, 'shape' => $shape, 'error' => $err, 'fields' => []];
    foreach ($fields as $f) {
        $truth = truthOf($row, $f);
        if ($truth === null && $f !== 'gst' && $f !== 'pst') continue;     // nothing approved to compare with
        $got = $p[$f] ?? null;
        $ok = sameValue($f, $truth, $got);
        $res['fields'][$f] = ['truth' => $truth, 'got' => $got, 'ok' => $ok];
        $summary[$f]['of']++;
        if ($ok) $summary[$f]['right']++;
        $byVendor[$vendor][$f]['of'] = ($byVendor[$vendor][$f]['of'] ?? 0) + 1;
        $byVendor[$vendor][$f]['right'] = ($byVendor[$vendor][$f]['right'] ?? 0) + ($ok ? 1 : 0);
        if ($f === 'total' && !$ok) {
            $k = totalMissKind($truth, $got);
            $missKinds[$k]['n'] = ($missKinds[$k]['n'] ?? 0) + 1;
            $missKinds[$k]['vendors'][$vendor] = true;
        }
    }
    // The phone's read of the same photo, when the corpus carries it: scored on its own, never mixed in.
    if (!empty($row['device_text'])) {
        $dp = parseReceiptText((string)$row['device_text'], null, null);
        foreach ($fields as $f) {
            $truth = truthOf($row, $f);
            if ($truth === null && $f !== 'gst' && $f !== 'pst') continue;
            $ok = sameValue($f, $truth, $dp[$f] ?? null);
            $res['device'][$f] = ['got' => $dp[$f] ?? null, 'ok' => $ok];
            $deviceSummary[$f]['of'] = ($deviceSummary[$f]['of'] ?? 0) + 1;
            $deviceSummary[$f]['right'] = ($deviceSummary[$f]['right'] ?? 0) + ($ok ? 1 : 0);
        }
    }
    $results[(int)$row['id']] = $res;
}

// ── Report ────────────────────────────────────────────────────────────────────
printf("Receipts replayed: %d  (%s)\n\n", count($results), implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($shapes), $shapes)));
printf("%-10s %8s %8s %7s\n", 'field', 'right', 'of', 'share');
foreach ($summary as $f => $s) {
    printf("%-10s %8d %8d %6.1f%%\n", $f, $s['right'], $s['of'], $s['of'] ? 100 * $s['right'] / $s['of'] : 0);
}
if ($deviceSummary) {
    echo "\nSame receipts, read by the phone instead:\n";
    foreach ($deviceSummary as $f => $s) printf("%-10s %8d %8d %6.1f%%\n", $f, $s['right'], $s['of'], 100 * $s['right'] / max(1, $s['of']));
}
echo "\nTotal misses by pattern:\n";
uasort($missKinds, fn($a, $b) => $b['n'] <=> $a['n']);
foreach ($missKinds as $k => $m) {
    printf("  %3d  %-48s %d vendors\n", $m['n'], $k, count($m['vendors']));
}
echo "\nWeakest vendors on total (3+ receipts):\n";
$weak = [];
foreach ($byVendor as $v => $fs) {
    if (($fs['total']['of'] ?? 0) >= 3) $weak[$v] = [$fs['total']['right'], $fs['total']['of']];
}
uasort($weak, fn($a, $b) => ($a[0] / $a[1]) <=> ($b[0] / $b[1]));
foreach (array_slice($weak, 0, 10, true) as $v => [$r, $o]) printf("  %-34s %3d / %-3d\n", mb_substr($v, 0, 34), $r, $o);

if ($showMisses !== null) {
    echo "\nMisses on $showMisses:\n";
    foreach ($results as $id => $r) {
        $c = $r['fields'][$showMisses] ?? null;
        if ($c && !$c['ok']) printf("  #%-6d %-28s approved %-10s read %s\n", $id, mb_substr($r['vendor'], 0, 28), $c['truth'] ?? '-', var_export($c['got'], true));
    }
}

$exit = 0;
if ($baseFile !== null) {
    $base = json_decode((string)@file_get_contents($baseFile), true)['results'] ?? null;
    if (!is_array($base)) { fwrite(STDERR, "cannot read baseline $baseFile\n"); exit(1); }
    $fixed = []; $broken = [];
    foreach ($results as $id => $r) {
        foreach ($r['fields'] as $f => $c) {
            $was = $base[$id]['fields'][$f]['ok'] ?? null;
            if ($was === null) continue;
            if (!$was && $c['ok']) $fixed[] = "#$id {$r['vendor']} $f";
            if ($was && !$c['ok']) $broken[] = "#$id {$r['vendor']} $f: approved {$c['truth']}, now " . var_export($c['got'], true);
        }
    }
    printf("\nAgainst baseline: %d fixed, %d made worse\n", count($fixed), count($broken));
    foreach ($fixed as $x)  echo "  + $x\n";
    foreach ($broken as $x) echo "  - $x\n";
    if ($broken) $exit = 3;
}

if ($outFile !== null) {
    file_put_contents($outFile, json_encode(['corpus' => basename($corpus), 'summary' => $summary, 'device_summary' => $deviceSummary, 'misses' => $missKinds, 'results' => $results], JSON_PRETTY_PRINT));
    @chmod($outFile, 0600);
}
exit($exit);
