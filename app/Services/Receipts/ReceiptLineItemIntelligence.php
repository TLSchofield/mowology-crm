<?php
/**
 * /app/Services/Receipts/ReceiptLineItemIntelligence.php
 * Line-item quality gate shared by all three OCR pipelines (initial intake,
 * pre-save rescan, saved-expense rescan).
 *
 * After the regex parser runs, this decides whether the line items are good enough
 * and, if not, escalates in two bounded steps:
 *   1. Tesseract → Google Vision re-OCR (brings bounding boxes, so the position-aware
 *      line reconstruction in ReceiptParser finally runs) — only when the cheap pass
 *      demonstrably failed on items, never on a hunch.
 *   2. LLM extraction for line items only (ReceiptLlmExtractor.php) — only if step 1
 *      still doesn't add up, and only accepted if it sums and every name is in the OCR.
 *
 * Header fields are never replaced by the LLM. Every result carries
 * `line_items_source` ('ocr' | 'vision' | 'llm') and `line_items_quality`.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

require_once __DIR__ . '/ReceiptParser.php';
require_once __DIR__ . '/ReceiptLearning.php';
require_once __DIR__ . '/ReceiptLlmExtractor.php';

/**
 * Is a second look warranted? True when items don't sum to the subtotal, or when
 * nothing was parsed against a real subtotal.
 */
function lineItemsNeedBetterExtraction(array $parsed): bool
{
    $quality  = $parsed['line_items_quality'] ?? 'none';
    $items    = $parsed['line_items'] ?? [];
    $subtotal = (float)($parsed['subtotal'] ?? $parsed['total'] ?? 0);
    if ($quality === 'mismatch') return true;
    if (empty($items) && $subtotal > 0) return true;
    return false;
}

/**
 * Are the header fields (total, date, tax maths) good enough? Returns the reasons they are not
 * (empty = fine). Pure, no DB. Arithmetic is only tested on values the receipt printed: anything the
 * parser worked out itself (total_derived, subtotal_derived, gst_estimated) is skipped, because a
 * derived figure agrees with its inputs by construction.
 *
 * @return list<string> total_missing | date_missing | math_fails | gst_unknown
 */
function headersNeedBetterExtraction(array $parsed): array
{
    $has = static function ($v): bool {
        return $v !== null && $v !== '' && is_numeric($v);
    };
    $reasons = [];
    if (!$has($parsed['total'] ?? null)) $reasons[] = 'total_missing';
    if (empty($parsed['date'])) $reasons[] = 'date_missing';

    $derived = !empty($parsed['total_derived']) || !empty($parsed['subtotal_derived']) || !empty($parsed['gst_estimated']);
    if (!$derived && $has($parsed['total'] ?? null) && $has($parsed['subtotal'] ?? null)
        && $has($parsed['gst'] ?? null)
        && abs((float)$parsed['subtotal'] + (float)$parsed['gst'] + (float)($has($parsed['pst'] ?? null) ? $parsed['pst'] : 0) - (float)$parsed['total']) > 0.02) {
        $reasons[] = 'math_fails';
    }

    $noPrintedGst = !$has($parsed['gst'] ?? null) || !empty($parsed['gst_estimated']);
    if ($noPrintedGst && !receiptLooksTaxFree($parsed)) $reasons[] = 'gst_unknown';
    return $reasons;
}

/** A receipt that says it carries no tax (or prints a zero tax line) is not a missing-GST case. Pure. */
function receiptLooksTaxFree(array $parsed): bool
{
    if (isset($parsed['gst']) && is_numeric($parsed['gst']) && empty($parsed['gst_estimated']) && (float)$parsed['gst'] == 0.0) {
        return true;
    }
    $text = (string)($parsed['ocr_text'] ?? '');
    return $text !== '' && preg_match('/\b(TAX[\s-]*(FREE|EXEMPT)|ZERO[\s-]*RATED|NO\s+TAX|GST\s+EXEMPT|NON[\s-]*TAXABLE)\b/i', $text) === 1;
}

/**
 * @param array $ctx {
 *   file_path:      string      image on disk (for Vision escalation)
 *   ocr_source:     string      'tesseract' | 'vision' | 'none'
 *   ocr_text:       string
 *   raw_response:   ?array      Vision response when available
 *   parsed:         array       parseReceiptText() output
 *   vendor_id:      ?int
 *   vendor_profile: ?array      getVendorLineItemProfile() output (dictionary for the LLM)
 * }
 * @return array Same keys, updated, plus 'escalated' (bool) and 'llm_used' (bool).
 */
function enhanceLineItemExtraction(array $ctx): array
{
    $ctx['escalated'] = false;
    $ctx['llm_used']  = false;
    $parsed = $ctx['parsed'] ?? [];
    if (empty($parsed) || empty($ctx['ocr_text'])) {
        return $ctx;
    }
    $profile = $ctx['vendor_profile'] ?? getVendorLineItemProfile($ctx['vendor_id'] ?? null);

    // ── Step 1: Tesseract → Vision when items failed ─────────────────
    if (($ctx['ocr_source'] ?? '') === 'tesseract'
        && lineItemsNeedBetterExtraction($parsed)
        && !empty($ctx['file_path'])
        && function_exists('extractTextFromImage')) {
        try {
            $vision = extractTextFromImage($ctx['file_path']);
            if (!empty($vision['success']) && !empty($vision['text'])) {
                $reparsed = parseReceiptText($vision['text'], $vision['raw_response'] ?? null, $profile);
                // Keep Vision's parse — it has bounding boxes and usually better header
                // reads too. Only fall back to the Tesseract parse if Vision found less.
                $before = count($parsed['line_items'] ?? []);
                $after  = count($reparsed['line_items'] ?? []);
                if ($after >= $before || ($reparsed['line_items_quality'] ?? '') === 'match') {
                    $parsed              = $reparsed;
                    $ctx['ocr_text']     = $vision['text'];
                    $ctx['raw_response'] = $vision['raw_response'] ?? null;
                    $ctx['ocr_source']   = 'vision';
                    $ctx['escalated']    = true;
                    $parsed['escalation_reason'] = 'items_mismatch';
                }
            }
        } catch (Throwable $e) {
            error_log('Line-item Vision escalation failed: ' . $e->getMessage());
        }
    }

    // ── Step 2: LLM tier, gated on still-bad items ───────────────────
    if (lineItemsNeedBetterExtraction($parsed) && receiptLlmEnabled()) {
        try {
            $subtotal = isset($parsed['subtotal']) && $parsed['subtotal'] !== null && $parsed['subtotal'] !== ''
                ? (float)$parsed['subtotal'] : null;
            $llm = extractLineItemsWithLlm($ctx['ocr_text'], $profile['known_names'] ?? [], $subtotal, $parsed['line_items'] ?? []);
            $parsed['llm_attempted'] = true;
            if ($llm['success']) {
                $parsed['line_items']        = $llm['items'];
                $parsed['line_items_source'] = 'llm';
                $parsed = array_merge($parsed, assessLineItemQuality($parsed));
                $ctx['llm_used'] = true;
            } else {
                $parsed['llm_rejected_reason'] = $llm['rejected_reason'] ?? $llm['error'];
            }
        } catch (Throwable $e) {
            error_log('Line-item LLM tier failed: ' . $e->getMessage());
        }
    }

    if (empty($parsed['line_items_source'])) {
        $parsed['line_items_source'] = ($ctx['ocr_source'] ?? '') === 'vision' ? 'vision' : 'ocr';
    }

    $parsed['header_flags'] = headersNeedBetterExtraction($parsed + ['ocr_text' => (string)$ctx['ocr_text']]);

    $ctx['parsed'] = $parsed;
    return $ctx;
}


/**
 * The whole header ladder for a surface that already holds parsed text (rescan, inbox, queue):
 * the vendor's learned layout where the rules came up empty, then the AI for what is still
 * missing. Never throws; a read that cannot be improved comes back unchanged.
 */
function runHeaderLadder(PDO $db, array $parsed, string $ocrText, ?int $vendorId): array
{
    if (!$parsed || $ocrText === '' || !function_exists('applyHeaderLayouts') || !function_exists('receiptReadMode')) {
        return $parsed;
    }
    try {
        $profile = $vendorId ? getVendorParseProfile($db, $vendorId) : null;
        $parsed = applyHeaderLayouts($parsed, $ocrText, headerLayoutsFromProfile($profile));
        $mode = receiptReadMode($profile);
        if ($mode === 'strong') $parsed['read_mode'] = 'strong';
        $out = enhanceHeaders(['parsed' => $parsed, 'ocr_text' => $ocrText, 'read_mode' => $mode]);
        return $out['parsed'];
    } catch (Throwable $e) {
        error_log('Header ladder: ' . $e->getMessage());
        return $parsed;
    }
}

/**
 * Header rung of the ladder: runs after the rules and after enhanceLineItemExtraction. When total or
 * date is missing, or the printed maths fails, the AI is asked ONCE for those fields and only values
 * printed in the OCR text are merged. A printed value that passes the checks is never overwritten;
 * replacements offered for a failing sum are kept only if the sum then adds up.
 *
 * @param array $ctx  ['parsed' => array, 'ocr_text' => string, 'read_mode' => 'cheap'|'strong' (optional)]
 * @param callable|null $transport / $capTaker  test seams, passed to extractHeadersWithLlm()
 * @return array $ctx with parsed updated (header_flags, header_source, header_llm_fields)
 */
function enhanceHeaders(array $ctx, ?callable $transport = null, ?callable $capTaker = null): array
{
    $parsed = $ctx['parsed'] ?? [];
    $text   = (string)($ctx['ocr_text'] ?? '');
    if (!$parsed || $text === '') return $ctx;
    $check = static function (array $p) use ($text): array {
        return headersNeedBetterExtraction($p + ['ocr_text' => $text]);
    };
    $reasons = $check($parsed);
    $parsed['header_flags'] = $reasons;
    $ctx['parsed'] = $parsed;

    $missing = [];
    if (in_array('total_missing', $reasons, true)) $missing[] = 'total';
    if (in_array('date_missing', $reasons, true)) $missing[] = 'date';
    $mathFails = in_array('math_fails', $reasons, true);
    if ($mathFails) $missing = array_merge($missing, ['total', 'subtotal', 'gst', 'pst']);
    // Strong read mode (vendor the cheap read keeps getting wrong): an unprinted GST is worth asking about too.
    if (($ctx['read_mode'] ?? '') === 'strong' && in_array('gst_unknown', $reasons, true)) $missing[] = 'gst';
    if (!$missing || ($transport === null && !receiptLlmEnabled())) return $ctx;

    try {
        $llm = extractHeadersWithLlm($text, array_values(array_unique($missing)), $transport, $capTaker);
    } catch (Throwable $e) {
        error_log('Header LLM tier failed: ' . $e->getMessage());
        return $ctx;
    }
    if (!$llm['success']) {
        $parsed['header_llm_rejected'] = $llm['rejected_reason'];
        $ctx['parsed'] = $parsed;
        return $ctx;
    }

    $merged = [];
    $next = $parsed;
    foreach ($llm['fields'] as $f => $v) {
        $isEmpty = $f === 'date' ? empty($parsed['date']) : !isset($parsed[$f]) || $parsed[$f] === '' || $parsed[$f] === null;
        if ($f === 'gst' && !empty($parsed['gst_estimated'])) $isEmpty = true;      // an estimate is not a printed value
        if (!$isEmpty) continue;                       // never overwrite a value the rules read
        $next[$f] = $v;
        $merged[] = $f;
    }
    if ($mathFails) {
        // Replacements for a failing sum: only the fields the rules derived or that disagree; kept only if the sum then holds.
        $try = $next;
        foreach (['total', 'subtotal', 'gst', 'pst'] as $f) {
            if (isset($llm['fields'][$f]) && !in_array($f, $merged, true)) $try[$f] = $llm['fields'][$f];
        }
        if (!in_array('math_fails', $check($try), true)) {
            foreach (['total', 'subtotal', 'gst', 'pst'] as $f) {
                if (isset($llm['fields'][$f]) && ($try[$f] ?? null) !== ($next[$f] ?? null)) $merged[] = $f;
            }
            $next = $try;
        }
    }
    if (!$merged) return $ctx;
    if (in_array('total', $merged, true)) unset($next['total_derived']);
    if (in_array('subtotal', $merged, true)) unset($next['subtotal_derived']);
    if (in_array('gst', $merged, true)) { $next['gst_estimated'] = false; $next['gst_source'] = 'printed'; }
    $next['header_source']     = 'llm';
    $next['header_llm_fields'] = array_values(array_unique($merged));
    $next['header_flags']      = $check($next);
    $ctx['parsed'] = $next;
    return $ctx;
}
