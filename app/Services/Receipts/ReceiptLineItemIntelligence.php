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

    $ctx['parsed'] = $parsed;
    return $ctx;
}
