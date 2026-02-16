<?php
/**
 * /app/Services/Receipts/ReceiptParser.php
 * Receipt Text Parser
 *
 * Extracts structured fields from raw OCR text using regex patterns.
 * Designed for Canadian receipts (GST 5%, CAD dollar format).
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
 *   $parsed = parseReceiptText($ocrText);
 *   // Returns: [
 *   //   'total' => '145.67', 'gst' => '6.93', 'subtotal' => '138.74',
 *   //   'date' => '2026-02-13', 'vendor_hint' => 'HOME DEPOT',
 *   //   'card_last4' => '1234', 'payment_method' => 'credit_card',
 *   // ]
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Parse raw OCR text and extract structured receipt fields.
 *
 * @param string     $ocrText     Raw text from OCR
 * @param array|null $rawResponse Full Vision API response (for position-aware line items)
 * @return array Parsed fields with values (null if not found)
 */
function parseReceiptText(string $ocrText, ?array $rawResponse = null): array
{
    $result = [
        'total'          => null,
        'gst'            => null,
        'subtotal'       => null,
        'date'           => null,
        'vendor_hint'    => null,
        'card_last4'     => null,
        'payment_method' => null,
        'line_items'     => [],
    ];

    $lines = preg_split('/\r?\n/', $ocrText);
    if (empty($lines)) {
        return $result;
    }

    // Vendor hint: typically first non-empty line or first line with letters
    $result['vendor_hint'] = extractVendorHint($lines);

    // Total
    $result['total'] = extractTotal($ocrText, $lines);

    // GST (5% — BC standard; also catches generic "TAX" labels)
    $result['gst'] = extractGST($ocrText);

    // Subtotal
    $result['subtotal'] = extractSubtotal($ocrText);

    // Date
    $result['date'] = extractDate($ocrText);

    // Payment method + card last 4
    $paymentInfo = extractPaymentInfo($ocrText);
    $result['card_last4']     = $paymentInfo['card_last4'];
    $result['payment_method'] = $paymentInfo['payment_method'];

    // Line items — try position-aware extraction first, fall back to raw text lines
    $positionLines = null;
    if ($rawResponse !== null) {
        $positionLines = reconstructLinesFromVisionResponse($rawResponse);
    }
    if (!empty($positionLines)) {
        $result['line_items'] = extractLineItems($positionLines);
    }
    // Fallback: if position-aware extraction found no items, try raw text lines
    if (empty($result['line_items'])) {
        $result['line_items'] = extractLineItems($lines);
    }

    // If we found subtotal and GST but no total, calculate it
    if ($result['total'] === null && $result['subtotal'] !== null && $result['gst'] !== null) {
        $result['total'] = number_format(
            (float)$result['subtotal'] + (float)$result['gst'],
            2,
            '.',
            ''
        );
    }

    // If we have total but no GST, estimate GST at 5% (BC standard)
    if ($result['gst'] === null && $result['total'] !== null) {
        $total = (float)$result['total'];
        $result['gst'] = number_format($total / 1.05 * 0.05, 2, '.', '');
        $result['gst_estimated'] = true;
    }

    return $result;
}


/**
 * Reconstruct visual lines from Vision API bounding box data.
 *
 * The Vision API DOCUMENT_TEXT_DETECTION returns words with x/y coordinates.
 * This function groups words that share the same vertical position into lines,
 * then sorts words left-to-right within each line. This preserves the physical
 * receipt layout where item names and prices are on the same horizontal row.
 *
 * @param array $rawResponse Full Vision API response
 * @return array Array of line strings (sorted top-to-bottom)
 */
function reconstructLinesFromVisionResponse(array $rawResponse): array
{
    $textAnnotations = $rawResponse['responses'][0]['textAnnotations'] ?? [];

    // First element is the full text block — skip it; elements 1+ are individual words
    if (count($textAnnotations) < 2) {
        return [];
    }

    // Collect words with their Y-center position
    $words = [];
    for ($i = 1; $i < count($textAnnotations); $i++) {
        $ann = $textAnnotations[$i];
        $text = $ann['description'] ?? '';
        $vertices = $ann['boundingPoly']['vertices'] ?? [];

        if (empty($text) || count($vertices) < 4) continue;

        // Use the average Y of top-left and top-right as the word's vertical position
        $topY = (($vertices[0]['y'] ?? 0) + ($vertices[1]['y'] ?? 0)) / 2;
        // Use the average Y of all 4 corners for more stability
        $centerY = (($vertices[0]['y'] ?? 0) + ($vertices[1]['y'] ?? 0)
                  + ($vertices[2]['y'] ?? 0) + ($vertices[3]['y'] ?? 0)) / 4;
        // X position for left-to-right sorting
        $leftX = $vertices[0]['x'] ?? 0;
        // Word height for line grouping threshold
        $height = abs(($vertices[2]['y'] ?? 0) - ($vertices[0]['y'] ?? 0));

        $words[] = [
            'text'    => $text,
            'centerY' => $centerY,
            'topY'    => $topY,
            'leftX'   => $leftX,
            'height'  => max($height, 1),
        ];
    }

    if (empty($words)) {
        return [];
    }

    // Sort words by Y position (top to bottom)
    usort($words, function ($a, $b) {
        return $a['centerY'] <=> $b['centerY'];
    });

    // Group words into lines: words within half-height of each other are on the same line
    $lines = [];
    $currentLine = [$words[0]];
    $lineY = $words[0]['centerY'];
    // Use median word height as the grouping threshold
    $heights = array_column($words, 'height');
    sort($heights);
    $medianHeight = $heights[(int)(count($heights) / 2)] ?? 10;
    $threshold = $medianHeight * 0.5;

    for ($i = 1; $i < count($words); $i++) {
        $word = $words[$i];
        if (abs($word['centerY'] - $lineY) <= $threshold) {
            // Same line
            $currentLine[] = $word;
        } else {
            // New line — flush current
            $lines[] = $currentLine;
            $currentLine = [$word];
            $lineY = $word['centerY'];
        }
    }
    $lines[] = $currentLine; // Don't forget last line

    // Sort words within each line left-to-right, then join into strings
    $result = [];
    foreach ($lines as $lineWords) {
        usort($lineWords, function ($a, $b) {
            return $a['leftX'] <=> $b['leftX'];
        });
        $lineText = implode(' ', array_column($lineWords, 'text'));
        $trimmed = trim($lineText);
        if ($trimmed !== '') {
            $result[] = $trimmed;
        }
    }

    return $result;
}


/**
 * Extract line items from receipt OCR text.
 *
 * Handles multiple receipt formats:
 *  - Home Depot batched: multiple "{barcode} {name} <A>" lines, then prices in order
 *  - Home Depot paired: "{barcode} {name} <A>\n{price}"
 *  - Generic inline: "{name}    $XX.XX" or "{name}  XX.XX"
 *  - Tabular: "{qty} x {name}  XX.XX"
 *
 * Stops parsing at SUBTOTAL/TOTAL/TAX lines.
 *
 * @param array $lines OCR text split into lines
 * @return array Array of ['name', 'amount', 'quantity', 'unit_price', 'sku_raw']
 */
function extractLineItems(array $lines): array
{
    $items = [];
    $inItemZone = false;
    $stopKeywords = ['subtotal', 'sub total', 'gst', 'pst', 'hst', 'tax',
                     'amount due', 'balance due', 'change', 'tender', 'visa', 'mastercard',
                     'debit', 'interac', 'cash', 'approved', 'contactless', 'aid ',
                     'auth code', 'seq:', 'return policy', 'survey', 'scan me',
                     'pro xtra', 'receipt po'];

    $lineCount = count($lines);

    // Collect item names and context in order; prices are matched later
    $pendingItems = [];    // Queue of item names waiting for prices
    $pendingContext = [];   // Parallel queue: ['sku_raw' => ..., 'quantity' => ..., 'unit_price' => ...]

    for ($i = 0; $i < $lineCount; $i++) {
        $line = trim($lines[$i]);
        if ($line === '') continue;

        $lineLower = strtolower($line);

        // Stop at summary section
        $hitStop = false;
        foreach ($stopKeywords as $kw) {
            if (strpos($lineLower, $kw) !== false) {
                if ($inItemZone || !empty($pendingItems)) { $hitStop = true; break; }
                continue 2;
            }
        }
        if (preg_match('/^total\s*:?\s*$/i', $line)) {
            if ($inItemZone || !empty($pendingItems)) { $hitStop = true; }
        }
        if ($hitStop) break;

        // Skip header lines
        if (preg_match('/(?:store\s*mgr|cashier|sale\s|how\s+doers|get\s+more)/i', $line)) continue;
        if (preg_match('/^\(\d{3}\)\s*\d{3}[\-\.]\d{4}/', $line)) continue;
        if (preg_match('/^\d+\s+(st|ave|blvd|rd|dr|way|street|avenue)\b/i', $line)) continue;
        if (preg_match('/^[\d\s]+\d{2}\/\d{2}\/\d{2,4}/', $line)) continue;
        if (preg_match('/^(?:EACH|MAX REFUND|REFUND VALUE)/i', $line)) continue;

        // Pattern: "0.55 tonne @ $118.00/tonne" — qty + unit + unit_price on one line
        if (preg_match('/^(\d+\.?\d*)\s*(tonne|kg|yard|cu\.?\s*yd|m3|litre|gal)s?\s*@\s*\$?(\d+\.?\d*)\s*\/\s*\w+/i', $line, $m)) {
            // This is context for the most recent pending item or last added item
            $qty = (float)$m[1];
            $unitPrice = (float)$m[3];
            if (!empty($items)) {
                $last = count($items) - 1;
                $items[$last]['quantity'] = $qty;
                $items[$last]['unit_price'] = round($unitPrice, 2);
            } elseif (!empty($pendingContext)) {
                $lastCtx = count($pendingContext) - 1;
                $pendingContext[$lastCtx]['quantity'] = $qty;
                $pendingContext[$lastCtx]['unit_price'] = round($unitPrice, 2);
            }
            continue;
        }

        // Pattern: Material code + description (landfill/waste style: "10 - Green Waste")
        if (preg_match('/^\d{1,4}\s+[-–]\s+(.+)$/i', $line, $m)) {
            $itemName = trim($m[1]);
            if (preg_match('/^(.+?)\s+\$?(\d{1,6}\.\d{2})\s*$/', $itemName, $pm)) {
                $items[] = ['name' => trim($pm[1]), 'amount' => $pm[2], 'quantity' => 1, 'unit_price' => null, 'sku_raw' => null];
                $inItemZone = true;
            } else {
                $pendingItems[] = $itemName;
                $pendingContext[] = ['sku_raw' => null, 'quantity' => 1, 'unit_price' => null];
                $inItemZone = true;
            }
            continue;
        }

        // Pattern: Canadian Tire SKU line with qty prefix — "20X059-6986-0"
        // Extract quantity from "NNX" prefix and store SKU
        if (preg_match('/^(\d+)[A-Z](\d{2,4}-\d{3,4}-\d)\s*$/', $line, $m)) {
            $skuQty = (int)$m[1];
            $skuRaw = $line;
            // Store context for the next pending item
            $pendingItems[] = '__sku_context__';
            $pendingContext[] = ['sku_raw' => $skuRaw, 'quantity' => $skuQty, 'unit_price' => null];
            continue;
        }

        // Pattern: Canadian Tire style — "SKU ITEM NAME $" (trailing $)
        if (preg_match('/^(?:(\d{2,3}-\d{3,4}-\d)\s+)?(.{3,}?)\s+\$\s*$/', $line, $m)) {
            $skuRaw = !empty($m[1]) ? $m[1] : null;
            $itemName = trim($m[2]);
            if (!preg_match('/^[@#]/', $itemName) && strlen($itemName) >= 3) {
                // Check if we have a pending SKU context to merge into
                if (!empty($pendingItems) && end($pendingItems) === '__sku_context__') {
                    $ctxIdx = count($pendingContext) - 1;
                    $pendingItems[$ctxIdx] = $itemName;
                    if ($skuRaw && !$pendingContext[$ctxIdx]['sku_raw']) {
                        $pendingContext[$ctxIdx]['sku_raw'] = $skuRaw;
                    }
                } else {
                    $pendingItems[] = $itemName;
                    $pendingContext[] = ['sku_raw' => $skuRaw, 'quantity' => 1, 'unit_price' => null];
                }
                $inItemZone = true;
            }
            continue;
        }

        // Pattern: Standalone Canadian Tire SKU line (no qty prefix) — "042-0169-0"
        if (preg_match('/^(\d{2,3}-\d{3,4}-\d)\s*$/', $line, $m)) {
            // Just store SKU context for next item
            $pendingItems[] = '__sku_context__';
            $pendingContext[] = ['sku_raw' => $m[1], 'quantity' => 1, 'unit_price' => null];
            continue;
        }

        // Pattern: "@ $N.NN" or "N.NNN ea." — unit price context for most recent pending item
        if (preg_match('/^@\s*\$?\s*$/i', $line)) {
            // Standalone "@ $" — skip, unit price is on next line
            continue;
        }
        if (preg_match('/^(\d+\.?\d*)\s*ea\.?\s*$/i', $line, $m)) {
            $unitPrice = (float)$m[1];
            if (!empty($pendingContext)) {
                $lastCtx = count($pendingContext) - 1;
                $pendingContext[$lastCtx]['unit_price'] = round($unitPrice, 2);
            }
            continue;
        }

        // Pattern: Barcode + item name (Home Depot style)
        if (preg_match('/^(\d{6,15})\s+(.+?)(?:\s*<[A-Z,]+>)?\s*$/', $line, $m)) {
            $skuRaw = $m[1];
            $itemName = trim($m[2]);
            if (preg_match('/^(.+?)\s+\$?(\d{1,6}\.\d{2})\s*$/', $itemName, $pm)) {
                $items[] = ['name' => trim($pm[1]), 'amount' => $pm[2], 'quantity' => 1, 'unit_price' => null, 'sku_raw' => $skuRaw];
                $inItemZone = true;
            } else {
                $pendingItems[] = $itemName;
                $pendingContext[] = ['sku_raw' => $skuRaw, 'quantity' => 1, 'unit_price' => null];
                $inItemZone = true;
            }
            continue;
        }

        // Pattern: DEPOSIT line
        if (preg_match('/^DEPOSIT/i', $line)) {
            if ($i + 1 < $lineCount) {
                $nextLine = trim($lines[$i + 1]);
                if (preg_match('/^(\d{1,6}\.\d{2})\s*[A-Z]?\s*$/', $nextLine, $pm)) {
                    $items[] = ['name' => 'Deposit', 'amount' => $pm[1], 'quantity' => 1, 'unit_price' => null, 'sku_raw' => null];
                    $i++;
                }
            }
            continue;
        }

        // Pattern: Markdown/discount line
        if (preg_match('/^(?:RSN:|DISCOUNT|MARKDOWN|MKDN)/i', $line)) {
            if (preg_match('/(-?\d{1,6}\.\d{2})/', $line, $m)) {
                $amount = $m[1];
                $items[] = ['name' => 'Discount', 'amount' => '-' . ltrim($amount, '-'), 'quantity' => 1, 'unit_price' => null, 'sku_raw' => null];
                $inItemZone = true;
            }
            continue;
        }

        // Pattern: Standalone price line — assign to next pending item
        if (preg_match('/^-?\$?(\d{1,6}\.\d{2})\s*[A-Z]?\s*$/', $line, $pm)) {
            $amount = $pm[1];
            if (strpos($line, '-') === 0) {
                $items[] = ['name' => 'Discount', 'amount' => '-' . ltrim($amount, '-'), 'quantity' => 1, 'unit_price' => null, 'sku_raw' => null];
            } elseif (!empty($pendingItems)) {
                $itemName = array_shift($pendingItems);
                $ctx = array_shift($pendingContext);
                // Skip __sku_context__ placeholders that never got an item name
                while ($itemName === '__sku_context__' && !empty($pendingItems)) {
                    $itemName = array_shift($pendingItems);
                    $ctx = array_shift($pendingContext);
                }
                if ($itemName !== '__sku_context__') {
                    $items[] = [
                        'name' => $itemName,
                        'amount' => $amount,
                        'quantity' => $ctx['quantity'] ?? 1,
                        'unit_price' => $ctx['unit_price'] ?? null,
                        'sku_raw' => $ctx['sku_raw'] ?? null,
                    ];
                }
            }
            $inItemZone = true;
            continue;
        }

        // Pattern: Generic inline — "Item Name   $XX.XX"
        if (preg_match('/^(.{3,}?)\s{2,}\$?(\d{1,6}\.\d{2})\s*$/', $line, $m)) {
            $name = trim($m[1]);
            $nameLower = strtolower($name);
            $isSummary = false;
            foreach (['subtotal', 'total', 'gst', 'pst', 'hst', 'tax', 'deposit', 'change', 'tender'] as $kw) {
                if (strpos($nameLower, $kw) !== false) { $isSummary = true; break; }
            }
            if (!$isSummary) {
                $items[] = ['name' => $name, 'amount' => $m[2], 'quantity' => 1, 'unit_price' => null, 'sku_raw' => null];
                $inItemZone = true;
                continue;
            }
        }

        // Pattern: Qty x Name  Price
        if (preg_match('/^(\d+)\s*[xX\x{00D7}]\s*(.{3,}?)\s{2,}\$?(\d{1,6}\.\d{2})\s*$/u', $line, $m)) {
            $qty = (int)$m[1];
            $name = trim($m[2]);
            $amount = $m[3];
            $unitPrice = $qty > 0 ? round((float)$amount / $qty, 2) : null;
            $items[] = ['name' => $name, 'amount' => $amount, 'quantity' => $qty, 'unit_price' => $unitPrice, 'sku_raw' => null];
            $inItemZone = true;
            continue;
        }
    }

    // Post-process: derive missing quantity or unit_price from the other
    foreach ($items as &$item) {
        $total = (float)($item['amount'] ?? 0);
        $qty = (float)($item['quantity'] ?? 1);
        $up = $item['unit_price'];

        if ($up !== null && $up > 0 && $qty == 1 && $total > $up * 1.01) {
            // unit_price known but qty=1 seems wrong — derive qty
            $item['quantity'] = round($total / $up, 3);
        } elseif ($qty > 1 && $up === null && $total > 0) {
            // qty known but no unit_price — derive it
            $item['unit_price'] = round($total / $qty, 2);
        }
    }
    unset($item);

    return $items;
}


/**
 * Extract vendor hint from first meaningful lines.
 */
function extractVendorHint(array $lines): ?string
{
    // First 3 non-empty lines are candidates — vendor is usually first
    $candidates = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (strlen($trimmed) >= 3 && preg_match('/[A-Za-z]/', $trimmed)) {
            $candidates[] = $trimmed;
            if (count($candidates) >= 3) break;
        }
    }

    if (empty($candidates)) {
        return null;
    }

    // Return the first candidate that looks like a store name
    // Skip lines that are mostly numbers/symbols or look like addresses
    foreach ($candidates as $candidate) {
        // Skip lines with dates, phone numbers, addresses
        if (preg_match('/^\d{3}[\-\.]\d{3}[\-\.]\d{4}/', $candidate)) continue;
        if (preg_match('/^\d+\s+(st|ave|blvd|rd|dr|hwy|street|avenue)/i', $candidate)) continue;

        return $candidate;
    }

    return $candidates[0] ?? null;
}

/**
 * Extract total amount from OCR text.
 * Looks for patterns like "TOTAL $145.67", "TOTAL: 145.67", "AMOUNT DUE: $145.67"
 * Also handles split-line: "TOTAL\n$64.26"
 */
function extractTotal(string $text, array $lines): ?string
{
    // Pattern: TOTAL, AMOUNT DUE, BALANCE DUE, etc. followed by dollar amount (same line)
    $totalPatterns = [
        '/(?:TOTAL\s*(?:DUE)?|AMOUNT\s*DUE|BALANCE\s*DUE|GRAND\s*TOTAL|PURCHASE\s*TOTAL)\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})/i',
        '/^.*TOTAL[^$\d]*\$?\s*(\d{1,6}[.,]\d{2})\s*$/im',
    ];

    foreach ($totalPatterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            $amount = end($matches[1]);
            $amount = str_replace(',', '.', $amount);
            return $amount;
        }
    }

    // Fallback: find the largest dollar amount on a line with "TOTAL"
    $maxAmount = null;
    foreach ($lines as $line) {
        if (stripos($line, 'total') !== false) {
            if (preg_match('/\$?\s*(\d{1,6}[.,]\d{2})/', $line, $m)) {
                $val = (float)str_replace(',', '.', $m[1]);
                if ($maxAmount === null || $val > $maxAmount) {
                    $maxAmount = $val;
                }
            }
        }
    }

    if ($maxAmount !== null) {
        return number_format($maxAmount, 2, '.', '');
    }

    // Split-line fallback: "TOTAL\n$64.26" or "Total Fee:\n$65.00" — look for standalone TOTAL line
    // Must NOT match SUBTOTAL (check exact word boundary)
    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount - 1; $i++) {
        $line = trim($lines[$i]);
        if (preg_match('/^(?:TOTAL(?:\s+\w+)?|AMOUNT\s*DUE|BALANCE\s*DUE|GRAND\s*TOTAL)\s*:?\s*$/i', $line) &&
            stripos($line, 'sub') === false &&
            stripos($line, 'tendered') === false) {
            $nextLine = trim($lines[$i + 1]);
            if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $nextLine, $m)) {
                return str_replace(',', '.', $m[1]);
            }
            // Handle three-line: "TOTAL\n$\n202.46"
            if ($nextLine === '$' && $i + 2 < $lineCount) {
                $amountLine = trim($lines[$i + 2]);
                if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $amountLine, $m)) {
                    return str_replace(',', '.', $m[1]);
                }
            }
        }
    }

    return null;
}

/**
 * Extract GST amount from receipt text.
 * Matches GST specifically, plus generic "TAX" labels (assumed GST for BC).
 * Handles both same-line ("GST $3.45") and split-line ("GST/HST\n2.88") formats.
 */
function extractGST(string $text): ?string
{
    // Same-line patterns
    $gstPatterns = [
        '/GST\s*(?:\/HST)?\s*(?:\d+%?)?\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})/i',
        '/(?<!\w)TAX\s*(?:\d+%?)?\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})/i',
    ];

    $totalGst = 0;
    $found = false;

    foreach ($gstPatterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[1] as $amount) {
                $totalGst += (float)str_replace(',', '.', $amount);
                $found = true;
            }
            break;
        }
    }

    // Split-line fallback: "GST/HST\n2.88" or "GST\n$3.45" or "GST 5%\n$\n9.04"
    if (!$found) {
        $lines = preg_split('/\r?\n/', $text);
        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount - 1; $i++) {
            $line = trim($lines[$i]);
            if (preg_match('/^GST(?:\s*\/\s*HST)?(?:\s*\d+%?)?\s*:?\s*$/i', $line) ||
                preg_match('/^TAX\s*:?\s*$/i', $line)) {
                $nextLine = trim($lines[$i + 1]);
                if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $nextLine, $m)) {
                    $totalGst += (float)str_replace(',', '.', $m[1]);
                    $found = true;
                }
                // Handle three-line: "GST 5%\n$\n9.04"
                elseif ($nextLine === '$' && $i + 2 < $lineCount) {
                    $amountLine = trim($lines[$i + 2]);
                    if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $amountLine, $m)) {
                        $totalGst += (float)str_replace(',', '.', $m[1]);
                        $found = true;
                    }
                }
            }
        }
    }

    return $found ? number_format($totalGst, 2, '.', '') : null;
}

/**
 * Extract subtotal amount.
 * Handles both same-line ("SUBTOTAL $57.78") and split-line ("SUBTOTAL\n57.78") formats.
 */
function extractSubtotal(string $text): ?string
{
    // Same-line pattern
    $pattern = '/(?:SUB\s*TOTAL|SUBTOTAL)\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})/i';
    if (preg_match($pattern, $text, $m)) {
        return str_replace(',', '.', $m[1]);
    }

    // Split-line fallback: "SUBTOTAL\n57.78" or "SUBTOTAL\n$\n180.77"
    $lines = preg_split('/\r?\n/', $text);
    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount - 1; $i++) {
        $line = trim($lines[$i]);
        if (preg_match('/^(?:SUB\s*TOTAL|SUBTOTAL)\s*:?\s*$/i', $line)) {
            $nextLine = trim($lines[$i + 1]);
            if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $nextLine, $m)) {
                return str_replace(',', '.', $m[1]);
            }
            // Handle three-line: "SUBTOTAL\n$\n180.77" (standalone $ between label and amount)
            if ($nextLine === '$' && $i + 2 < $lineCount) {
                $amountLine = trim($lines[$i + 2]);
                if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $amountLine, $m)) {
                    return str_replace(',', '.', $m[1]);
                }
            }
        }
    }

    return null;
}

/**
 * Extract date from receipt text.
 * Supports: MM/DD/YYYY, DD/MM/YYYY, YYYY-MM-DD, MMM DD YYYY, etc.
 *
 * @return string|null Date in YYYY-MM-DD format
 */
function extractDate(string $text): ?string
{
    $patterns = [
        // YYYY-MM-DD
        '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/' => function ($m) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        },
        // MM/DD/YYYY or DD/MM/YYYY
        '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/' => function ($m) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $y = (int)$m[3];
            // If first number > 12, it's DD/MM/YYYY
            if ($a > 12) {
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }
            // Default: MM/DD/YYYY (North American convention)
            return sprintf('%04d-%02d-%02d', $y, $a, $b);
        },
        // MM/DD/YY or DD/MM/YY
        '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})(?!\d)/' => function ($m) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $y = (int)$m[3] + 2000;
            if ($a > 12) {
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }
            return sprintf('%04d-%02d-%02d', $y, $a, $b);
        },
        // Month DD, YYYY (e.g., "Feb 13, 2026", "February 13, 2026")
        '/(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2}),?\s+(\d{4})/i' => function ($m) {
            $parsed = strtotime($m[0]);
            return $parsed ? date('Y-m-d', $parsed) : null;
        },
    ];

    foreach ($patterns as $regex => $formatter) {
        if (preg_match($regex, $text, $matches)) {
            $date = $formatter($matches);
            if ($date) {
                // Basic validation
                $parts = explode('-', $date);
                if (count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                    return $date;
                }
            }
        }
    }

    // Fuzzy month name match for handwritten receipts (e.g., "Apan 21/25" → April 21/25)
    $fuzzyDate = extractDateFuzzyMonth($text);
    if ($fuzzyDate !== null) {
        return $fuzzyDate;
    }

    return null;
}

/**
 * Extract payment method and card last 4 digits.
 */
function extractPaymentInfo(string $text): array
{
    $result = ['card_last4' => null, 'payment_method' => null];

    // Card last 4 digits: ****1234, XXXX1234, *1234, ending in 1234
    $cardPatterns = [
        '/(?:\*{2,4}|X{2,4})\s*(\d{4})/i',
        '/ending\s+(?:in\s+)?(\d{4})/i',
        '/card\s*#?\s*\*+(\d{4})/i',
    ];

    foreach ($cardPatterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $result['card_last4'] = $m[1];
            break;
        }
    }

    // Payment method detection
    $textLower = strtolower($text);
    if (strpos($textLower, 'visa') !== false) {
        $result['payment_method'] = 'credit_card';
    } elseif (strpos($textLower, 'mastercard') !== false || strpos($textLower, 'master card') !== false) {
        $result['payment_method'] = 'credit_card';
    } elseif (strpos($textLower, 'amex') !== false || strpos($textLower, 'american express') !== false) {
        $result['payment_method'] = 'credit_card';
    } elseif (strpos($textLower, 'debit') !== false || strpos($textLower, 'interac') !== false) {
        $result['payment_method'] = 'debit';
    } elseif (strpos($textLower, 'cash') !== false) {
        $result['payment_method'] = 'cash';
    } elseif (strpos($textLower, 'e-transfer') !== false || strpos($textLower, 'etransfer') !== false) {
        $result['payment_method'] = 'etransfer';
    } elseif ($result['card_last4']) {
        // If we found card digits but no specific brand
        $result['payment_method'] = 'credit_card';
    }

    return $result;
}


/**
 * Fuzzy month name extraction for handwritten dates.
 *
 * Handwritten dates often OCR with garbled month names:
 *   "Apan 21/25" → April 21/25
 *   "Jne 15/25"  → June 15/25
 *   "Fab 3/26"   → Feb 3/26
 *
 * Uses Levenshtein distance against known month names.
 *
 * @param string $text Raw OCR text
 * @return string|null Date in YYYY-MM-DD format, or null
 */
function extractDateFuzzyMonth(string $text): ?string
{
    $months = [
        1  => ['january', 'jan'],
        2  => ['february', 'feb'],
        3  => ['march', 'mar'],
        4  => ['april', 'apr'],
        5  => ['may'],
        6  => ['june', 'jun'],
        7  => ['july', 'jul'],
        8  => ['august', 'aug'],
        9  => ['september', 'sep', 'sept'],
        10 => ['october', 'oct'],
        11 => ['november', 'nov'],
        12 => ['december', 'dec'],
    ];

    // Look for pattern: "word DD/YY" or "word DD, YY" or "word DD/YYYY"
    if (!preg_match('/([A-Za-z]{3,9})\s+(\d{1,2})\s*[\/,\-]\s*(\d{2,4})/i', $text, $m)) {
        return null;
    }

    $wordLower = strtolower($m[1]);
    $day = (int)$m[2];
    $year = (int)$m[3];
    if ($year < 100) $year += 2000;

    // Try exact match first
    foreach ($months as $num => $names) {
        foreach ($names as $name) {
            if ($wordLower === $name) {
                if ($day >= 1 && $day <= 31 && checkdate($num, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $num, $day);
                }
            }
        }
    }

    // Fuzzy match: Levenshtein distance <= 2
    $bestMonth = null;
    $bestDist = 3; // Max allowed distance

    foreach ($months as $num => $names) {
        foreach ($names as $name) {
            // Only compare against names of similar length
            if (abs(strlen($wordLower) - strlen($name)) > 2) continue;

            $dist = levenshtein($wordLower, $name);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestMonth = $num;
            }
        }
    }

    if ($bestMonth !== null && $day >= 1 && $day <= 31 && checkdate($bestMonth, $day, $year)) {
        return sprintf('%04d-%02d-%02d', $year, $bestMonth, $day);
    }

    return null;
}
