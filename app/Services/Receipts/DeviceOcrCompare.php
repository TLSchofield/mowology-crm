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
    return ['text' => $text, 'engine' => $engine, 'version' => $version];
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

/**
 * Parse the device text and record how it compares with what the server read (shadow mode).
 * Swallows every failure: the upload must never wait on, or fail because of, the measurement.
 */
function logDeviceOcrCompare(PDO $db, int $mediaId, ?int $vendorId, array $device, array $serverParsed, string $serverSource): void
{
    try {
        if (!function_exists('parseReceiptText')) return;
        $deviceParsed = parseReceiptText($device['text'], null);
        $cmp = compareParsedHeaders($serverParsed, $deviceParsed);
        $fields = count($cmp);
        $agree = count(array_filter($cmp, static fn($c) => $c['match']));
        $db->prepare("
            INSERT INTO receipt_ocr_compare
                (media_id, vendor_id, engine, engine_version, server_source, fields_compared, fields_agree, detail_json, device_text_chars)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$mediaId, $vendorId, $device['engine'], $device['version'], $serverSource, $fields, $agree,
                     json_encode($cmp), strlen($device['text'])]);
    } catch (Throwable $e) {
        error_log('Device OCR compare (non-fatal): ' . $e->getMessage());
    }
}
