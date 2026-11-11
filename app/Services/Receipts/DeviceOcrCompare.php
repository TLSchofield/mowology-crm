<?php
/**
 * Text the phone read from the receipt itself (Apple Vision on iPhone, ML Kit on Android), and the
 * measurement that decides whether it can stand in for the server's Google Vision read.
 *
 *   off     the text is ignored
 *   shadow  Vision reads as always; the device text is parsed too and both results are logged
 *           (receipt_ocr_compare, migration 1407) — the default, no change to what anyone sees
 *   on      a device text that parses to a complete header is used and Vision is skipped; anything
 *           less falls back to Vision exactly as before
 *
 * Pure functions first (unit tested), the log write last. Nothing here may stop a receipt upload.
 */

declare(strict_types=1);

const DEVICE_OCR_ENGINES = ['apple_vision', 'mlkit'];
const DEVICE_OCR_MAX_CHARS = 20000;
const DEVICE_OCR_FIELDS = ['total', 'gst', 'subtotal', 'date', 'vendor_hint'];
/** Most line boxes kept from one phone read; a receipt has far fewer. */
const DEVICE_OCR_MAX_LINES = 800;
/** In 'on' mode this share of receipts is also read by Vision, so text that doesn't match its photo is caught. */
const DEVICE_OCR_SPOT_CHECK_PCT = 25;

/** @return 'off'|'shadow'|'on' — anything unknown is 'shadow': measuring is harmless, replacing is not. */
function deviceOcrMode(?string $setting): string
{
    $v = strtolower(trim((string)$setting));
    if ($v === 'off' || $v === '0') return 'off';
    if ($v === 'on' || $v === '1') return 'on';
    return 'shadow';
}

/** The ops_settings value, 'shadow' when unset or unreadable. */
function deviceOcrSetting(PDO $db): string
{
    try {
        $stmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'receipt_device_ocr' LIMIT 1");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        return deviceOcrMode($v === false ? null : (string)$v);
    } catch (Throwable $e) {
        return 'shadow';
    }
}

/**
 * The phone's fields from a POST, or null when it sent none worth keeping. Unknown engines and
 * oversized text are dropped, not trusted.
 * @return array{text: string, engine: string, version: string}|null
 */
function cleanDeviceOcr(array $post): ?array
{
    $text = str_replace("\r", "\n", (string)($post['client_ocr_text'] ?? ''));
    $engine = strtolower(trim((string)($post['client_ocr_engine'] ?? '')));
    if ($text === '' || !in_array($engine, DEVICE_OCR_ENGINES, true)) return null;
    $text = trim((string)preg_replace('/[^\P{C}\n\t]+/u', '', $text));
    if (function_exists('mb_substr')) $text = mb_substr($text, 0, DEVICE_OCR_MAX_CHARS);
    if ($text === '') return null;
    $version = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string)($post['client_ocr_version'] ?? '')), 0, 20);
    $out = ['text' => $text, 'engine' => $engine, 'version' => $version];
    $boxes = cleanDeviceOcrLines($post['client_ocr_lines'] ?? null);
    if ($boxes !== null) {
        $out['lines'] = $boxes['lines'];
        $out['image'] = $boxes['image'];
    }
    $capture = cleanDeviceCapture($post['client_capture'] ?? null);
    if ($capture !== null) $out['capture'] = $capture;
    return $out;
}

/**
 * Pure: how the phone judged the shot — characters read, sharpness, and whether the person chose "Use this photo" over the
 * hint. The readability thresholds rest on nine reads; these let real captures tune them. Unknown fields are dropped.
 * @return array{chars?: int, sharpness?: float, used_anyway?: bool}|null
 */
function cleanDeviceCapture($raw): ?array
{
    if (!is_string($raw) || $raw === '' || strlen($raw) > 500) return null;
    $j = json_decode($raw, true);
    if (!is_array($j)) return null;
    $out = [];
    if (isset($j['chars']) && is_numeric($j['chars']) && (int)$j['chars'] >= 0) $out['chars'] = (int)$j['chars'];
    if (isset($j['sharpness']) && is_numeric($j['sharpness']) && is_finite((float)$j['sharpness']) && (float)$j['sharpness'] >= 0) $out['sharpness'] = round((float)$j['sharpness'], 1);
    if (isset($j['used_anyway'])) $out['used_anyway'] = (bool)$j['used_anyway'];
    return $out ?: null;
}

/**
 * Pure: the phone's line boxes (iPhone 1.3.85+ / Android 2.2.9+), or null when absent or unreadable. Older apps
 * send none and nothing changes for them. The field is JSON:
 *   {"v":1, "image":{"w":px,"h":px}, "lines":[{"text":"…","x":0.08,"y":0.31,"w":0.42,"h":0.016,"deg":-0.8}, …]}
 * x/y/w/h are 0–1 fractions of the photo with the origin top-left; deg is the line's tilt, positive when its
 * right end sits lower. A bad line is dropped, never trusted.
 * @return array{lines: array<int, array{text: string, x: float, y: float, w: float, h: float, deg?: float}>, image: array{w?: int, h?: int}}|null
 */
function cleanDeviceOcrLines($raw): ?array
{
    if (!is_string($raw) || $raw === '' || strlen($raw) > 400000) return null;
    $j = json_decode($raw, true);
    if (!is_array($j)) return null;
    $list = isset($j['lines']) && is_array($j['lines']) ? $j['lines'] : (deviceOcrIsList($j) ? $j : []);
    $lines = [];
    foreach ($list as $l) {
        if (!is_array($l) || count($lines) >= DEVICE_OCR_MAX_LINES) continue;
        if (!isset($l['text']) || !is_scalar($l['text'])) continue;
        $text = trim((string)preg_replace('/[^\P{C}\t]+/u', '', (string)$l['text']));
        if ($text === '') continue;
        $box = [];
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (!isset($l[$k]) || !is_numeric($l[$k])) continue 2;
            $box[$k] = (float)$l[$k];
        }
        if ($box['w'] <= 0 || $box['h'] <= 0 || $box['x'] < -0.05 || $box['y'] < -0.05
            || $box['x'] + $box['w'] > 1.05 || $box['y'] + $box['h'] > 1.05) continue;
        $line = ['text' => function_exists('mb_substr') ? mb_substr($text, 0, 200) : substr($text, 0, 200)] + $box;
        if (isset($l['deg']) && is_numeric($l['deg']) && abs((float)$l['deg']) <= 45) $line['deg'] = (float)$l['deg'];
        $lines[] = $line;
    }
    if (count($lines) < 2) return null;
    $image = [];
    $imageIn = isset($j['image']) && is_array($j['image']) ? $j['image'] : [];
    foreach (['w', 'h'] as $k) {
        $v = $imageIn[$k] ?? null;
        if (is_numeric($v) && (int)$v > 0 && (int)$v < 20000) $image[$k] = (int)$v;
    }
    return ['lines' => $lines, 'image' => $image];
}

/** PHP 7.4 has no array_is_list(). */
function deviceOcrIsList(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

/**
 * The second argument parseReceiptText() takes for a device read: its line boxes when the phone sent them
 * (rows then pair names with prices — reconstructLinesFromDeviceBoxes()), otherwise null as before.
 */
function deviceOcrRaw(?array $device): ?array
{
    if (empty($device['lines'])) return null;
    return ['device_lines' => $device['lines'], 'device_image' => $device['image'] ?? []];
}

/** Is there enough here to be a receipt: a few lines, a money amount, and a date-like token. */
function deviceTextUsable(string $text): bool
{
    if (strlen($text) < 40 || substr_count($text, "\n") < 3) return false;
    $hasMoney = (bool)preg_match('/\d{1,6}[.,]\d{2}\b/', $text);
    $hasDate  = (bool)preg_match('/\b(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}|(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2})/i', $text);
    return $hasMoney && $hasDate;
}

/**
 * Pure: may the device text replace the server read? The gate passes, and the parser reads a
 * complete header from it on its own (no AI, no layout help) — so a receipt the device text would
 * leave blank still goes to Vision.
 * @param string[] $headerReasons headersNeedBetterExtraction() of the device text's own parse
 */
function deviceTextAcceptable(string $text, array $headerReasons): bool
{
    if (!deviceTextUsable($text)) return false;
    return !array_intersect($headerReasons, ['total_missing', 'date_missing', 'math_fails']);
}

/**
 * Pure: field by field, did the device text and the server's read agree?
 * @return array<string, array{server: ?string, device: ?string, match: bool}>
 */
function compareParsedHeaders(array $server, array $device): array
{
    $out = [];
    foreach (DEVICE_OCR_FIELDS as $f) {
        $a = $server[$f] ?? null;
        $b = $device[$f] ?? null;
        if (($a === null || $a === '') && ($b === null || $b === '')) continue;
        $as = $a === null ? null : (string)$a;
        $bs = $b === null ? null : (string)$b;
        $match = $f === 'vendor_hint'
            ? (function_exists('sameVendorName') ? sameVendorName($as, $bs) : strcasecmp((string)$as, (string)$bs) === 0)
            : (function_exists('normalizeFieldValue') ? normalizeFieldValue($f, $as) === normalizeFieldValue($f, $bs) : $as === $bs);
        $out[$f] = ['server' => $as, 'device' => $bs, 'match' => $match];
    }
    return $out;
}

/** Has migration 1433 added receipt_ocr_compare.device_text? One cheap probe per receipt; any error means no. */
function deviceOcrHasTextColumn(PDO $db): bool
{
    try {
        $db->query("SELECT device_text FROM receipt_ocr_compare WHERE 1 = 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Record how the device parse compares with what the server read (shadow mode). A caller that
 * already parsed the text passes that result so the same device text is never parsed twice.
 * Swallows every failure: the upload must never wait on, or fail because of, the measurement.
 */
function logDeviceOcrCompare(PDO $db, int $mediaId, ?int $vendorId, array $device, array $serverParsed, string $serverSource, ?array $deviceParsed = null): void
{
    try {
        if (!function_exists('parseReceiptText')) return;
        if ($deviceParsed === null) $deviceParsed = parseReceiptText($device['text'], deviceOcrRaw($device));
        $cmp = compareParsedHeaders($serverParsed, $deviceParsed);
        $fields = count($cmp);
        $agree = count(array_filter($cmp, static fn($c) => $c['match']));
        $params = [$mediaId, $vendorId, $device['engine'], $device['version'], $serverSource, $fields, $agree,
                   json_encode($cmp + (isset($device['capture']) ? ['capture' => $device['capture']] : [])), strlen($device['text'])];
        // The text itself feeds the parser replay corpus (migration 1433). Before 1433 runs, store the length only.
        if (deviceOcrHasTextColumn($db)) {
            $db->prepare("
                INSERT INTO receipt_ocr_compare
                    (media_id, vendor_id, engine, engine_version, server_source, fields_compared, fields_agree, detail_json, device_text_chars, device_text)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute(array_merge($params, [$device['text']]));
        } else {
            $db->prepare("
                INSERT INTO receipt_ocr_compare
                    (media_id, vendor_id, engine, engine_version, server_source, fields_compared, fields_agree, detail_json, device_text_chars)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute($params);
        }
    } catch (Throwable $e) {
        error_log('Device OCR compare (non-fatal): ' . $e->getMessage());
    }
}

/**
 * Pure: does the device text agree with an independent server read on the facts that matter (total, date)?
 * The spot-check that stops text which doesn't match its photo from standing in for Vision.
 */
function deviceMatchesServer(array $device, array $server): bool
{
    foreach (['total', 'date'] as $f) {
        $a = $device[$f] ?? null;
        $b = $server[$f] ?? null;
        if ($a === null || $a === '' || $b === null || $b === '') continue;   // one side didn't read it: not a disagreement
        $na = function_exists('normalizeFieldValue') ? normalizeFieldValue($f, (string)$a) : (string)$a;
        $nb = function_exists('normalizeFieldValue') ? normalizeFieldValue($f, (string)$b) : (string)$b;
        if ($na !== $nb) return false;
    }
    return true;
}
