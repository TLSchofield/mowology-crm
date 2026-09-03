<?php
/**
 * /app/Services/Receipts/ReceiptLlmExtractor.php
 * LLM line-item extraction tier — Claude, raw HTTP (same pattern as
 * app/Modules/Marketing/Services/ArticleGeneratorService.php; no PHP SDK in this repo).
 *
 * Deliberately narrow: it only ever extracts LINE ITEMS, only runs when the regex
 * parser demonstrably failed (items don't sum to the subtotal, or none were found
 * against a real subtotal — see ReceiptLineItemIntelligence.php), and its output is
 * accepted only if (a) the rows sum to the regex-parsed subtotal within tolerance and
 * (b) every item name is actually present in the OCR text. Header fields (total,
 * GST, date, vendor) always stay with the regex path. Rejected output is discarded
 * silently and the receipt falls back to whatever the parser had.
 *
 * SETUP: define('ANTHROPIC_API_KEY', 'sk-ant-...') in /app_config/secrets.php.
 *        Optional: define('RECEIPT_LLM_MODEL', 'claude-opus-5') to override the model.
 *        Optional kill switch: ops_settings.receipt_llm_extraction = '0'.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

const RECEIPT_LLM_API_URL       = 'https://api.anthropic.com/v1/messages';
const RECEIPT_LLM_DEFAULT_MODEL = 'claude-opus-5';
const RECEIPT_LLM_MAX_TOKENS    = 4096;
const RECEIPT_LLM_TIMEOUT       = 60;

/**
 * Is the LLM tier available and switched on?
 */
function receiptLlmEnabled(): bool
{
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
        return false;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'receipt_llm_extraction' LIMIT 1");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        if ($v !== false && (string)$v === '0') {
            return false;
        }
    } catch (Throwable $e) {
        // No ops_settings row / table → default on when a key exists
    }
    return true;
}

/**
 * Ask Claude for the line items of one receipt.
 *
 * @param string   $ocrText     Full OCR text
 * @param array    $dictionary  Vendor's known item names (ReceiptLearning::getVendorLineItemProfile()['known_names'])
 * @param float|null $subtotal  Regex-parsed subtotal (pre-tax); the acceptance gate
 * @param array    $parserItems What the regex parser produced (shown to the model as a hint)
 * @return array{success: bool, items: array, error: ?string, rejected_reason: ?string, usage: ?array}
 */
function extractLineItemsWithLlm(string $ocrText, array $dictionary, ?float $subtotal, array $parserItems = []): array
{
    $fail = static function (string $err, ?string $rejected = null): array {
        return ['success' => false, 'items' => [], 'error' => $err, 'rejected_reason' => $rejected, 'usage' => null];
    };

    if (!receiptLlmEnabled()) {
        return $fail('LLM extraction disabled');
    }
    $ocrText = trim($ocrText);
    if ($ocrText === '' || strlen($ocrText) > 20000) {
        return $fail('OCR text empty or too long');
    }

    $model = defined('RECEIPT_LLM_MODEL') && RECEIPT_LLM_MODEL !== '' ? RECEIPT_LLM_MODEL : RECEIPT_LLM_DEFAULT_MODEL;

    $system = implode("\n", [
        'You extract purchased line items from OCR text of a Canadian landscaping company\'s vendor receipt.',
        'Rules:',
        '- Output ONLY items that appear in the OCR text. Never invent, merge, or "fix" items that are not there.',
        '- Each item: the printed name (clean up obvious OCR garbling only), quantity, unit price if printed, the line total as printed, and the SKU/barcode printed next to it if any.',
        '- A discount/markdown line reduces the preceding item\'s line total; do not output discounts as items.',
        '- Exclude subtotal, tax, total, tender, change, deposits, loyalty and header/footer lines.',
        '- line_total values are pre-tax and must be numbers with two decimals.',
        '- If you cannot identify items with confidence, return an empty list.',
        'The known_item_names list is what this vendor has sold us before — prefer those exact spellings when the OCR clearly refers to one of them.',
    ]);

    $userPayload = [
        'expected_subtotal' => $subtotal !== null ? number_format($subtotal, 2, '.', '') : null,
        'known_item_names'  => array_slice(array_values($dictionary), 0, 80),
        'parser_attempt'    => array_map(static function ($it) {
            return ['name' => $it['name'] ?? '', 'line_total' => $it['line_total'] ?? $it['amount'] ?? null];
        }, array_slice($parserItems, 0, 40)),
        'ocr_text'          => $ocrText,
    ];

    $schema = [
        'type'       => 'object',
        'properties' => [
            'items' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'       => ['type' => 'string'],
                        'quantity'   => ['type' => 'number'],
                        'unit_price' => ['type' => ['number', 'null']],
                        'line_total' => ['type' => 'number'],
                        'sku'        => ['type' => ['string', 'null']],
                    ],
                    'required'             => ['name', 'quantity', 'unit_price', 'line_total', 'sku'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['items'],
        'additionalProperties' => false,
    ];

    $payload = json_encode([
        'model'         => $model,
        'max_tokens'    => RECEIPT_LLM_MAX_TOKENS,
        'output_config' => [
            'effort' => 'low',
            'format' => ['type' => 'json_schema', 'schema' => $schema],
        ],
        'system'   => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
        'messages' => [['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
    ]);

    $ch = curl_init(RECEIPT_LLM_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => RECEIPT_LLM_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return $fail('curl error: ' . $err);
    }
    $data = json_decode((string)$raw, true);
    if ($code !== 200 || !is_array($data)) {
        return $fail('Claude API error: ' . ($data['error']['message'] ?? "HTTP {$code}"));
    }
    if (($data['stop_reason'] ?? '') === 'refusal') {
        return $fail('Claude declined the request', 'refusal');
    }

    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    $decoded = json_decode(trim($text), true);
    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return $fail('Unparseable model output', 'bad_json');
    }

    $items = [];
    foreach ($decoded['items'] as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $lt   = isset($row['line_total']) ? (float)$row['line_total'] : null;
        if ($name === '' || $lt === null) continue;
        $qty = isset($row['quantity']) && (float)$row['quantity'] > 0 ? (float)$row['quantity'] : 1.0;
        $up  = isset($row['unit_price']) && $row['unit_price'] !== null ? round((float)$row['unit_price'], 2) : null;
        $items[] = [
            'name'       => mb_substr($name, 0, 255),
            'amount'     => number_format($lt, 2, '.', ''),
            'line_total' => number_format($lt, 2, '.', ''),
            'quantity'   => $qty,
            'unit_price' => $up,
            'sku_raw'    => isset($row['sku']) && $row['sku'] !== null && trim((string)$row['sku']) !== '' ? mb_substr(trim((string)$row['sku']), 0, 64) : null,
            'source'     => 'llm',
        ];
    }

    $usage = $data['usage'] ?? null;
    if (empty($items)) {
        return ['success' => false, 'items' => [], 'error' => null, 'rejected_reason' => 'no_items', 'usage' => $usage];
    }

    // ── Acceptance gates ────────────────────────────────────────────────
    $reason = validateLlmLineItems($items, $ocrText, $subtotal);
    if ($reason !== null) {
        error_log("ReceiptLlmExtractor: rejected ({$reason})");
        return ['success' => false, 'items' => [], 'error' => null, 'rejected_reason' => $reason, 'usage' => $usage];
    }

    return ['success' => true, 'items' => $items, 'error' => null, 'rejected_reason' => null, 'usage' => $usage];
}

/**
 * Hallucination guards. Returns a rejection reason, or null when the rows pass.
 *  - sum_mismatch: rows don't add up to the regex subtotal (max($0.05, 1%) tolerance)
 *  - name_absent:  an item name has no support in the OCR text (every significant
 *                  word of the name must appear, allowing for OCR-style misspellings)
 */
function validateLlmLineItems(array $items, string $ocrText, ?float $subtotal): ?string
{
    if ($subtotal !== null && $subtotal > 0) {
        $sum = 0.0;
        foreach ($items as $it) $sum += (float)$it['line_total'];
        if (abs($sum - $subtotal) > max(0.05, $subtotal * 0.01)) {
            return 'sum_mismatch';
        }
    }

    $hay = strtoupper(preg_replace('/[^A-Z0-9 ]+/i', ' ', $ocrText));
    $hayWords = array_flip(array_filter(explode(' ', $hay)));
    foreach ($items as $it) {
        $words = array_filter(preg_split('/[^A-Z0-9]+/', strtoupper($it['name'])), static function ($w) {
            return strlen($w) >= 3;
        });
        if (empty($words)) continue;
        $hit = 0;
        foreach ($words as $w) {
            if (isset($hayWords[$w]) || strpos($hay, $w) !== false) { $hit++; continue; }
            // tolerate one-character OCR slips on longer words
            if (strlen($w) >= 5) {
                foreach ($hayWords as $hw => $_) {
                    $hw = (string)$hw; // numeric OCR tokens become int keys via array_flip
                    if (abs(strlen($hw) - strlen($w)) <= 1 && levenshtein($hw, $w) <= 1) { $hit++; continue 2; }
                }
            }
        }
        if ($hit < max(1, (int)ceil(count($words) * 0.6))) {
            return 'name_absent';
        }
    }
    return null;
}
