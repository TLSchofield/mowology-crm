<?php
/**
 * /app/Services/Receipts/TesseractPreScreen.php
 * Tesseract OCR Pre-Screen
 *
 * Runs a fast local Tesseract pass on the uploaded receipt image BEFORE
 * calling Google Cloud Vision. If Tesseract gets enough text with price
 * patterns we use it directly (free, no API call). If image quality is
 * too poor, we still fall through to Vision with a "low_quality" flag.
 *
 * Decision matrix:
 *   score >= 70  → use Tesseract result, skip Vision
 *   score 30-69  → use Vision (possibly blurry/dark image), flag for QC
 *   score < 30   → likely not a receipt — skip Vision, return ocr_available:false
 *
 * Scoring:
 *   +30  Has at least one dollar-amount pattern ($X.XX or X.XX)
 *   +25  Has 3+ distinct lines of text
 *   +15  Has a date pattern
 *   +15  Has 4+ words that look like vendor/item names (alpha strings >= 3 chars)
 *   +10  Has GST / TAX / TOTAL keyword
 *   -20  Fewer than 20 chars total (nearly blank image)
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';
 *   $pre = tesseractPreScreen($filePath);
 *   // Returns:
 *   //   ['available' => bool, 'text' => string, 'score' => int,
 *   //    'decision' => 'use_tesseract'|'use_vision'|'skip',
 *   //    'low_quality' => bool]
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Run Tesseract pre-screen on an image and decide whether to call Vision.
 *
 * @param string $filePath  Absolute path to the image file
 * @return array
 */
function tesseractPreScreen(string $filePath): array
{
    $empty = [
        'available'   => false,
        'text'        => '',
        'score'       => 0,
        'decision'    => 'use_vision',  // Default: always fall through to Vision
        'low_quality' => false,
        'error'       => null,
    ];

    // Check if Tesseract is installed
    $tesseractBin = _findTesseractBin();
    if (!$tesseractBin) {
        $empty['error'] = 'tesseract not installed';
        return $empty;
    }

    // Validate file
    if (!file_exists($filePath) || !is_readable($filePath)) {
        $empty['error'] = 'file not found';
        return $empty;
    }

    // Run Tesseract — stdout mode with quiet flag, English language
    // Timeout: 15s (Tesseract on shared hosting can be slow)
    $cmd = escapeshellcmd($tesseractBin)
         . ' ' . escapeshellarg($filePath)
         . ' stdout -l eng --psm 6 --oem 1 2>/dev/null';

    $output = '';
    $exitCode = 0;

    // Use proc_open for timeout control
    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    if (!function_exists('proc_open')) {
        $empty['error'] = 'proc_open disabled';
        return $empty;
    }

    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        $empty['error'] = 'proc_open failed';
        return $empty;
    }

    fclose($pipes[0]);  // No stdin needed

    // Read stdout with timeout (stream_select)
    $startTime = time();
    $timeout = 15;
    $buf = '';

    while (!feof($pipes[1])) {
        if ((time() - $startTime) > $timeout) {
            proc_terminate($proc);
            $empty['error'] = 'tesseract timeout';
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return $empty;
        }
        $chunk = fread($pipes[1], 8192);
        if ($chunk !== false) $buf .= $chunk;
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $text = trim($buf);

    // Score the extracted text
    $score = _scoreTesseractText($text);
    $decision = _makeDecision($score);

    return [
        'available'   => ($decision === 'use_tesseract'),
        'text'        => $text,
        'score'       => $score,
        'decision'    => $decision,
        'low_quality' => ($decision === 'skip'),
        'error'       => null,
    ];
}


/**
 * Score the quality and receipt-likeness of OCR text (0-100).
 */
function _scoreTesseractText(string $text): int
{
    if (strlen($text) < 20) return 0;

    $score = 0;

    // Dollar amounts ($X.XX or just X.XX after common labels)
    if (preg_match('/\$\s?\d+\.\d{2}|\b\d{1,4}\.\d{2}\b/', $text)) {
        $score += 30;
    }

    // Multiple lines of text
    $lines = array_filter(explode("\n", $text), fn($l) => strlen(trim($l)) > 2);
    if (count($lines) >= 3) $score += 25;
    elseif (count($lines) >= 1) $score += 10;

    // Date pattern
    if (preg_match('/\b\d{4}[-\/]\d{2}[-\/]\d{2}\b|\b\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}\b/', $text)) {
        $score += 15;
    }

    // Keyword: GST, HST, TAX, TOTAL, SUBTOTAL, CHANGE, CASH, VISA
    if (preg_match('/\b(GST|HST|PST|TAX|TOTAL|SUBTOTAL|SUB-TOTAL|CHANGE|CASH|VISA|DEBIT|CREDIT)\b/i', $text)) {
        $score += 10;
    }

    // Vendor/item words — alpha strings >= 3 chars
    $words = preg_split('/\s+/', $text);
    $alphaWords = array_filter($words, fn($w) => strlen(preg_replace('/[^a-zA-Z]/', '', $w)) >= 3);
    if (count($alphaWords) >= 4) $score += 15;
    elseif (count($alphaWords) >= 2) $score += 5;

    return min(100, $score);
}


/**
 * Turn a score into an action decision.
 */
function _makeDecision(int $score): string
{
    if ($score >= 70) return 'use_tesseract';
    if ($score >= 30) return 'use_vision';
    return 'skip';
}


/**
 * Find the Tesseract binary. Returns null if not found.
 */
function _findTesseractBin(): ?string
{
    // Bail immediately if shell functions are disabled (shared hosting)
    $disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
    if (in_array('shell_exec', $disabled, true) || in_array('proc_open', $disabled, true)) {
        return null;
    }

    // Check common paths on shared hosting (cPanel) and Linux servers
    $candidates = [
        '/usr/bin/tesseract',
        '/usr/local/bin/tesseract',
        '/opt/cpanel/ea-php82/root/usr/bin/tesseract',
        'tesseract',  // PATH lookup last
    ];

    foreach ($candidates as $path) {
        if ($path === 'tesseract') {
            $which = @shell_exec('which tesseract 2>/dev/null');
            if ($which && strlen(trim($which)) > 0) return 'tesseract';
        } elseif (is_executable($path)) {
            return $path;
        }
    }

    return null;
}


/**
 * Check whether Tesseract is available on this server.
 * Cheap check — just test for the binary.
 *
 * @return bool
 */
function isTesseractAvailable(): bool
{
    return _findTesseractBin() !== null;
}
