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

    // Line items — try position-aware extraction first (groups words by Y-coordinate)
    $positionLines = null;
    if ($rawResponse !== null) {
        $positionLines = reconstructLinesFromVisionResponse($rawResponse);
    }
    if (!empty($positionLines)) {
        $result['line_items'] = extractLineItems($positionLines);
    }
    // Always also run plain-text extraction (fullTextAnnotation reads left column then right,
    // so qty@unit and total appear on separate lines — different items than position-aware).
    // Merge in any items found by plain text that position-aware missed.
    $plainItems = extractLineItems($lines);
    if (!empty($plainItems)) {
        if (empty($result['line_items'])) {
            $result['line_items'] = $plainItems;
        } else {
            // Merge: add plain-text items not already found by position-aware (dedup by name)
            $existingNames = array_map(function ($it) {
                return strtoupper(trim($it['name']));
            }, $result['line_items']);
            foreach ($plainItems as $pi) {
                $piName = strtoupper(trim($pi['name']));
                if (!in_array($piName, $existingNames, true)) {
                    $result['line_items'][] = $pi;
                    $existingNames[]        = $piName;
                }
            }
        }
    }

    // Fallback: handle column-separated OCR where labels and amounts are in separate blocks
    // e.g. "SUBTOTAL\nPST\nGST\n...\nTOTAL\n...\n41.96\n2.94\n2.10\n...\n47.00"
    if ($result['total'] === null || $result['gst'] === null || $result['subtotal'] === null) {
        $columnParsed = extractColumnSeparatedAmounts($lines);
        if ($result['subtotal'] === null && $columnParsed['subtotal'] !== null) {
            $result['subtotal'] = $columnParsed['subtotal'];
        }
        if ($result['gst'] === null && $columnParsed['gst'] !== null) {
            $result['gst'] = $columnParsed['gst'];
        }
        if ($result['total'] === null && $columnParsed['total'] !== null) {
            $result['total'] = $columnParsed['total'];
        }
        if ($columnParsed['pst'] !== null) {
            $result['pst'] = $columnParsed['pst'];
        }
    }

    // ── Province tax-model detection ──────────────────────────────────────────
    // Must run after all extraction so the raw keyword scan has full OCR text.
    // Sets tax_model, and for HST provinces moves the amount from the gst slot
    // (where extractGST may have placed it via the GST/HST pattern) to hst.
    $result['pst']       = $result['pst'] ?? null;
    $result['hst']       = null;
    $result['tax_model'] = detectTaxModel($ocrText);

    if ($result['tax_model'] === 'hst') {
        // Try dedicated HST extractor first — catches "HST (13%) $13.00" formats
        // that extractGST() does not handle (it requires a GST prefix).
        $hstAmount = extractHST($ocrText);
        if ($hstAmount !== null) {
            $result['hst'] = $hstAmount;
            $result['gst'] = null;   // clear any GST/HST-pattern match in gst slot
        } elseif ($result['gst'] !== null && !($result['gst_estimated'] ?? false)) {
            // extractGST captured the HST value via the "GST/HST" or "HST INCLUDED"
            // pattern — promote it to the hst slot.
            $result['hst'] = $result['gst'];
            $result['gst'] = null;
        }
        $result['pst'] = null;   // blended HST replaces separate PST
    }

    // ── Back-calculations (tax-model-aware) ───────────────────────────────────
    if ($result['tax_model'] === 'hst') {
        // HST model: subtotal + HST = total (no PST component)
        if ($result['total'] === null && $result['subtotal'] !== null && $result['hst'] !== null) {
            $result['total'] = number_format(
                (float)$result['subtotal'] + (float)$result['hst'],
                2, '.', ''
            );
        }
        if ($result['subtotal'] === null && $result['total'] !== null && $result['hst'] !== null) {
            $result['subtotal'] = number_format(
                (float)$result['total'] - (float)$result['hst'],
                2, '.', ''
            );
        }
        // ⚠ Do NOT estimate HST — the rate is province-specific (ON 13%, NS/NB 15%)
        // and cannot be determined without address context. Leave hst null if absent.
    } else {
        // GST+PST model (BC 5%+7%, SK 5%+6%, MB 5%+7%, QC 5%+9.975%)
        if ($result['total'] === null && $result['subtotal'] !== null && $result['gst'] !== null) {
            $result['total'] = number_format(
                (float)$result['subtotal'] + (float)$result['gst'] + (float)($result['pst'] ?? 0),
                2, '.', ''
            );
        }
        if ($result['subtotal'] === null && $result['total'] !== null && $result['gst'] !== null) {
            $result['subtotal'] = number_format(
                (float)$result['total'] - (float)$result['gst'] - (float)($result['pst'] ?? 0),
                2, '.', ''
            );
        }
        // If we have total but no GST, estimate at 5% (BC standard fallback)
        if ($result['gst'] === null && $result['total'] !== null) {
            $total = (float)$result['total'];
            $result['gst']           = number_format($total / 1.05 * 0.05, 2, '.', '');
            $result['gst_estimated'] = true;
            if ($result['subtotal'] === null) {
                $result['subtotal'] = number_format($total / 1.05, 2, '.', '');
            }
        }
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

        // Skip separator / promotional lines (e.g. "--------Buy 10 Get 10% Bagged Lime--------")
        if (preg_match('/^[-=*]{3,}/', $line)) continue;

        // Skip header lines
        if (preg_match('/(?:store\s*mgr|cashier|sale\s|how\s+doers|get\s+more)/i', $line)) continue;
        if (preg_match('/^\(\d{3}\)\s*\d{3}[\-\.]\d{4}/', $line)) continue;
        if (preg_match('/^\d+\s+(st|ave|blvd|rd|dr|way|street|avenue)\b/i', $line)) continue;
        if (preg_match('/^[\d\s]+\d{2}\/\d{2}\/\d{2,4}/', $line)) continue;
        if (preg_match('/^(?:EACH|MAX REFUND|REFUND VALUE)/i', $line)) continue;

        // Pattern: Canadian retail "Qty: 1 Base Price: $42.99" or "Qty: 2 Price: $19.99"
        // Extracts quantity and unit price, assigns to most recent pending item
        if (preg_match('/^Qty:\s*(\d+)\s+(?:Base\s+)?Price:\s*\$?(\d{1,6}\.\d{2})/i', $line, $m)) {
            $qty = (int)$m[1];
            $unitPrice = (float)$m[2];
            $lineTotal = round($qty * $unitPrice, 2);

            $itemName = null;
            $skuRaw = null;

            // Try pending items first
            if (!empty($pendingItems)) {
                $itemName = array_shift($pendingItems);
                $ctx = array_shift($pendingContext);
                while ($itemName === '__sku_context__' && !empty($pendingItems)) {
                    $skuRaw = $ctx['sku_raw'] ?? null;
                    $itemName = array_shift($pendingItems);
                    $ctx = array_shift($pendingContext);
                }
                if ($itemName === '__sku_context__') {
                    $skuRaw = $ctx['sku_raw'] ?? null;
                    $itemName = null;
                } else {
                    $skuRaw = $ctx['sku_raw'] ?? $skuRaw;
                }
            }

            // If no pending item, look backwards for item name and barcode
            if (!$itemName) {
                for ($j = $i - 1; $j >= max(0, $i - 4); $j--) {
                    $prevLine = trim($lines[$j]);
                    // Skip attribute lines like "Clr: ...", "Sz: ..."
                    if (preg_match('/^(?:Clr|Colour|Color|Sz|Size|Style)\s*:/i', $prevLine)) continue;
                    // Standalone barcode
                    if (preg_match('/^\d{8,15}$/', $prevLine)) {
                        $skuRaw = $prevLine;
                        continue;
                    }
                    // Item name: line with 3+ alpha chars, not a header/address
                    if (strlen($prevLine) >= 3 && preg_match('/[A-Za-z]{3,}/', $prevLine)
                        && !preg_match('/^(?:SALE|Date:|Cashier:)/i', $prevLine)
                        && !preg_match('/^\d+\s+(st|ave|blvd|rd|dr)/i', $prevLine)) {
                        $itemName = $prevLine;
                        break;
                    }
                }
            }

            if ($itemName) {
                $items[] = [
                    'name' => $itemName,
                    'amount' => number_format($lineTotal, 2, '.', ''),
                    'quantity' => $qty,
                    'unit_price' => round($unitPrice, 2),
                    'sku_raw' => $skuRaw,
                ];
                $inItemZone = true;
            }
            continue;
        }

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

        // Pattern: Home Depot "QTY@UNIT_PRICE   TOTAL[G]" — qty×unit price line
        // e.g. "4@12.98                    51.92" or "10@14.98               149.80G"
        if (preg_match('/^(\d+(?:\.\d+)?)\s*@\s*(\d{1,6}\.\d{2})\s+(\d{1,6}\.\d{2})\s*[A-Z]?\s*$/', $line, $m)) {
            $qty       = (float)$m[1];
            $unitPrice = (float)$m[2];
            $total     = (float)$m[3];

            if (!empty($pendingItems)) {
                $itemName = array_shift($pendingItems);
                $ctx      = array_shift($pendingContext);
                while ($itemName === '__sku_context__' && !empty($pendingItems)) {
                    $itemName = array_shift($pendingItems);
                    $ctx      = array_shift($pendingContext);
                }
                if ($itemName !== '__sku_context__') {
                    $items[] = [
                        'name'       => $itemName,
                        'amount'     => number_format($total, 2, '.', ''),
                        'quantity'   => $qty,
                        'unit_price' => round($unitPrice, 2),
                        'sku_raw'    => $ctx['sku_raw'] ?? null,
                    ];
                    $inItemZone = true;
                }
            }
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
        // Skip common receipt header boilerplate that isn't vendor names
        if (preg_match('/^(TRANSACTION\s+RECORD|CUSTOMER\s+COPY|MERCHANT\s+COPY|RECEIPT|DUPLICATE|REPRINT|WELCOME|THANK\s+YOU)/i', $candidate)) continue;

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
    // IMPORTANT: negative lookbehind (?<!SUB) prevents matching "SUBTOTAL" as "TOTAL"
    // Use [^\S\n] (horizontal whitespace only) to prevent matching across line boundaries
    $totalPatterns = [
        '/(?<!SUB)TOTAL[^\S\n]*(?:DUE)?[^\S\n]*:?[^\S\n]*\$?[^\S\n]*(\d{1,6}[.,]\d{2})/i',
        '/(?:AMOUNT\s*DUE|BALANCE\s*DUE|GRAND\s*TOTAL|PURCHASE\s*TOTAL)[^\S\n]*:?[^\S\n]*\$?[^\S\n]*(\d{1,6}[.,]\d{2})/i',
        '/^.*(?<!SUB)TOTAL[^$\d\n]*\$?\s*(\d{1,6}[.,]\d{2})\s*$/im',
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
    // Same-line patterns — use [^\S\n] (horizontal whitespace) to prevent cross-line matching
    // e.g., "GST 5.000%" on its own line must NOT match "5.00" as a dollar amount
    $gstPatterns = [
        '/GST[^\S\n]*(?:\/HST)?[^\S\n]*(?:[\d.]+%)?[^\S\n]*:?[^\S\n]*\$[^\S\n]*(\d{1,6}[.,]\d{2})/i',
        '/GST[^\S\n]*(?:\/HST)?[^\S\n]*:?[^\S\n]*\$?[^\S\n]*(\d{1,6}[.,]\d{2})(?!\d*%)/i',
        // Gas station / fuel receipts: "GST INCLUDED $ 0.95", "GST INCL $0.95", "HST INCLUDED $2.60"
        '/(?:GST|HST)[^\S\n]*(?:INCLUDED|INCL\.?)[^\S\n]*:?[^\S\n]*\$?[^\S\n]*(\d{1,6}[.,]\d{2})/i',
        '/(?<!\w)TAX[^\S\n]*(?:[\d.]+%)?[^\S\n]*:?[^\S\n]*\$?[^\S\n]*(\d{1,6}[.,]\d{2})(?!\d*%)/i',
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
    $pattern = '/(?:SUB[\s\-]*TOTAL|SUBTOTAL)\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})/i';
    if (preg_match($pattern, $text, $m)) {
        return str_replace(',', '.', $m[1]);
    }

    // Split-line fallback: "SUBTOTAL\n57.78" or "SUBTOTAL\n$\n180.77"
    $lines = preg_split('/\r?\n/', $text);
    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount - 1; $i++) {
        $line = trim($lines[$i]);
        if (preg_match('/^(?:SUB[\s\-]*TOTAL|SUBTOTAL)\s*:?\s*$/i', $line)) {
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
    // Must NOT match cashier/employee IDs like "Cashier: *****8388 Saziya"
    $cardPatterns = [
        '/(?:\*{2,4}|X{2,4})\s*(\d{4})/i',
        '/ending\s+(?:in\s+)?(\d{4})/i',
        '/card\s*#?\s*\*+(\d{4})/i',
    ];

    // Split into lines and skip cashier/employee lines
    $cardLines = preg_split('/\r?\n/', $text);
    foreach ($cardPatterns as $pattern) {
        foreach ($cardLines as $cardLine) {
            // Skip lines that are cashier/employee IDs
            if (preg_match('/^(?:Cashier|Employee|Clerk|Server|Associate)\s*:/i', trim($cardLine))) {
                continue;
            }
            if (preg_match($pattern, $cardLine, $m)) {
                $result['card_last4'] = $m[1];
                break 2;
            }
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
    // Score = distance * 10 - prefix_match_length (lower is better)
    // This way "Apan" → "apr" (prefix "ap"=2, dist 2, score 18) beats "jan" (prefix ""=0, dist 2, score 20)
    $bestMonth = null;
    $bestScore = PHP_INT_MAX;

    foreach ($months as $num => $names) {
        foreach ($names as $name) {
            // Only compare against names of similar length
            if (abs(strlen($wordLower) - strlen($name)) > 2) continue;

            $dist = levenshtein($wordLower, $name);
            if ($dist >= 3) continue; // Max allowed distance

            // Count matching prefix characters
            $prefixLen = 0;
            $minLen = min(strlen($wordLower), strlen($name));
            for ($c = 0; $c < $minLen; $c++) {
                if ($wordLower[$c] === $name[$c]) $prefixLen++;
                else break;
            }

            $score = $dist * 10 - $prefixLen;
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMonth = $num;
            }
        }
    }

    if ($bestMonth !== null && $day >= 1 && $day <= 31 && checkdate($bestMonth, $day, $year)) {
        return sprintf('%04d-%02d-%02d', $year, $bestMonth, $day);
    }

    return null;
}

/**
 * Extract amounts from column-separated receipt OCR.
 *
 * Some receipts (especially from POS systems like Coe Lumber) produce OCR
 * where labels and amounts are in separate vertical columns. The OCR reads
 * all labels first, then all amounts, producing output like:
 *
 *   SUBTOTAL
 *   PST
 *   GST
 *   GST/HST #834080111
 *   TOTAL
 *   AMOUNT PAID
 *   CHANGE DUE
 *   41.96
 *   2.94
 *   2.10
 *   47.00
 *   47.00
 *   0.00
 *
 * This function finds the block of labels and the corresponding block of
 * dollar amounts, then matches them by position.
 */
function extractColumnSeparatedAmounts(array $lines): array
{
    $result = ['subtotal' => null, 'pst' => null, 'gst' => null, 'total' => null];

    // ── Phase 1: Detect labels and their order ──────────────────────
    $labels = [];
    $labelOrder = [];

    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);

        // Skip lines that already have amounts on them (handled by same-line extractors)
        // But DON'T skip tax labels with percentage rates like "GST 5.000%" or "PST 7.000%"
        if (preg_match('/\d{1,6}[.,]\d{2}/', $line) && preg_match('/[A-Z]{3,}/i', $line)) {
            // Check if the digits are just a tax percentage rate (e.g., "5.000%", "7.000%")
            $lineWithoutPct = preg_replace('/\d+\.?\d*\s*%/', '', $line);
            if (preg_match('/\d{1,6}[.,]\d{2}/', $lineWithoutPct)) {
                // Still has a dollar amount after removing percentages — skip (same-line handler)
                continue;
            }
            // Otherwise the digits were just a percentage — fall through to label detection
        }

        if (preg_match('/^(?:SUB[\s\-]*TOTAL|SUBTOTAL)\s*:?\s*$/i', $line)) {
            $labels[$i] = 'subtotal';
            $labelOrder[] = 'subtotal';
        } elseif (preg_match('/^PST\s*(?:[\d.]+%?)?\s*:?\s*$/i', $line)) {
            $labels[$i] = 'pst';
            $labelOrder[] = 'pst';
        } elseif (preg_match('/^GST\s*(?:\/\s*HST)?\s*(?:[\d.]+%?)?\s*:?\s*$/i', $line)) {
            $labels[$i] = 'gst';
            $labelOrder[] = 'gst';
        } elseif (preg_match('/^(?:TOTAL|TOTAL\s*DUE|GRAND\s*TOTAL)\s*:?\s*$/i', $line) &&
                  stripos($line, 'sub') === false) {
            $labels[$i] = 'total';
            $labelOrder[] = 'total';
        }
    }

    if (empty($labelOrder)) {
        return $result;
    }

    $lastLabelLine = max(array_keys($labels));

    // ── Phase 2: Find payment section boundary ──────────────────────
    // POS receipts often print a TRANSACTION RECORD / debit card section that
    // repeats the same dollar amounts. Detect where it starts so we can
    // collect amounts only from the first (correct) occurrence.
    $paymentStart = findPaymentSectionStart($lines, $lastLabelLine);

    // ── Phase 3: Collect dollar amounts, stopping at payment boundary ─
    // Amount regex allows trailing letter codes like "GP", "CAD" (e.g., "$42.99 GP")
    $amountRegex = '/^\$?\s*(\d{1,6}[.,]\d{2})(?:\s+[A-Z]{1,4})?\s*$/i';
    $amounts = [];
    $scanEnd = $paymentStart ?? count($lines);
    $prevAmount = null;

    for ($i = $lastLabelLine + 1; $i < $scanEnd; $i++) {
        $line = trim($lines[$i]);
        if (preg_match($amountRegex, $line, $m)) {
            $val = str_replace(',', '.', $m[1]);
            // Skip consecutive duplicates (e.g., "$42.99 GP" then "$42.99" on next line)
            if ($val !== $prevAmount) {
                $amounts[] = $val;
            }
            $prevAmount = $val;
        } else {
            $prevAmount = null;
        }
    }

    // If we didn't find enough amounts before the payment section,
    // scan the full text but deduplicate consecutive identical amounts
    if (count($amounts) < count($labelOrder)) {
        $amounts = [];
        $prevAmount = null;
        for ($i = $lastLabelLine + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (preg_match($amountRegex, $line, $m)) {
                $val = str_replace(',', '.', $m[1]);
                // Skip consecutive duplicates (card section repeats)
                if ($val !== $prevAmount) {
                    $amounts[] = $val;
                }
                $prevAmount = $val;
            } else {
                $prevAmount = null; // Reset on non-amount line
            }
        }
    }

    // Last resort: scan backward from end of text
    if (count($amounts) < count($labelOrder)) {
        $amounts = [];
        $prevAmount = null;
        for ($i = count($lines) - 1; $i > $lastLabelLine; $i--) {
            $line = trim($lines[$i]);
            if (preg_match($amountRegex, $line, $m)) {
                $val = str_replace(',', '.', $m[1]);
                if ($val !== $prevAmount) {
                    array_unshift($amounts, $val);
                }
                $prevAmount = $val;
            } else {
                $prevAmount = null;
            }
        }
    }

    // ── Phase 4: Match amounts to labels ─────────────────────────────
    if (count($amounts) < count($labelOrder)) {
        return $result; // Not enough amounts found
    }

    // Try positional matching first
    foreach ($labelOrder as $idx => $type) {
        if (isset($amounts[$idx])) {
            $result[$type] = $amounts[$idx];
        }
    }

    // ── Phase 5: Validate and fall back to semantic matching ─────────
    if (!validateColumnAmounts($result)) {
        // Positional matching failed — try semantic (value-based) matching
        $result = semanticMatchAmounts($amounts, $labelOrder);
    }

    return $result;
}

/**
 * Detect where the payment/debit card section begins on a receipt.
 * Returns the line index of the first payment marker found after $afterLine, or null.
 */
function findPaymentSectionStart(array $lines, int $afterLine): ?int
{
    $markers = [
        '/^DEBIT\s*\/\s*CREDIT/i',
        '/^CREDIT\s*\/\s*DEBIT/i',
        '/^TRANSACTION\s+RECORD/i',
        '/^(?:DEBIT|CREDIT)\s+CARD\s+PURCHASE/i',
        '/^Term\s*[#:]/i',
        '/^Term\s+Id\s*:/i',
        '/^Card\s*[#:]/i',
        '/^Batch\s*:\s*\d/i',
        '/^AID\s*:\s*[A-F0-9]/i',
    ];

    for ($i = $afterLine + 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        foreach ($markers as $pattern) {
            if (preg_match($pattern, $line)) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * Validate that column-separated extracted amounts are reasonable.
 * Returns true if amounts look correct, false if they seem misaligned.
 */
function validateColumnAmounts(array $result): bool
{
    $sub   = $result['subtotal'] !== null ? (float)$result['subtotal'] : null;
    $gst   = $result['gst'] !== null ? (float)$result['gst'] : null;
    $pst   = $result['pst'] !== null ? (float)$result['pst'] : null;
    $total = $result['total'] !== null ? (float)$result['total'] : null;

    // If total exists, it must be the largest amount
    if ($total !== null) {
        if ($sub !== null && $total < $sub) return false;
        if ($gst !== null && $total < $gst) return false;
        if ($pst !== null && $total < $pst) return false;
    }

    // GST should be roughly 5% of subtotal (allow 2%-15% for rounding/provinces)
    if ($sub !== null && $gst !== null && $sub > 0) {
        $pct = $gst / $sub;
        if ($pct < 0.02 || $pct > 0.15) return false;
    }

    // If we have all three, check subtotal + taxes ≈ total
    if ($sub !== null && $gst !== null && $total !== null) {
        $expected = $sub + $gst + ($pst ?? 0);
        if (abs($expected - $total) > 0.10) return false;
    }

    return true;
}

/**
 * Match amounts to labels using semantic (value-based) logic.
 * Used when positional matching fails validation.
 *
 * Strategy:
 *   TOTAL  = largest amount
 *   GST    = amount closest to 5% of (TOTAL - taxes)
 *   PST    = amount closest to 7% of subtotal (if PST label present)
 *   SUBTOTAL = remaining amount, or TOTAL - GST - PST
 */
function semanticMatchAmounts(array $amounts, array $labelOrder): array
{
    $result = ['subtotal' => null, 'pst' => null, 'gst' => null, 'total' => null];
    $hasLabel = array_flip($labelOrder);

    // Take only the first N amounts matching label count
    $useAmounts = array_slice($amounts, 0, max(count($labelOrder), 4));
    $floats = array_map('floatval', $useAmounts);

    if (empty($floats)) return $result;

    // TOTAL = largest value
    $maxIdx = 0;
    $maxVal = $floats[0];
    for ($i = 1; $i < count($floats); $i++) {
        if ($floats[$i] > $maxVal) {
            $maxVal = $floats[$i];
            $maxIdx = $i;
        }
    }

    if (isset($hasLabel['total'])) {
        $result['total'] = $useAmounts[$maxIdx];
    }

    // Remaining amounts (exclude the total)
    $remaining = [];
    for ($i = 0; $i < count($useAmounts); $i++) {
        if ($i !== $maxIdx) {
            $remaining[] = ['val' => $floats[$i], 'str' => $useAmounts[$i]];
        }
    }

    // Find GST: amount closest to 5% of maxVal
    if (isset($hasLabel['gst']) && !empty($remaining)) {
        $bestGstIdx = 0;
        $bestGstDiff = PHP_FLOAT_MAX;
        $expectedGst = $maxVal * 0.05 / 1.12; // rough: 5% of pre-tax base (assuming ~12% total tax)
        // Simpler: GST is usually the smallest tax amount in BC
        foreach ($remaining as $ri => $r) {
            // GST should be between 1% and 8% of the total
            $pctOfTotal = $maxVal > 0 ? $r['val'] / $maxVal : 0;
            if ($pctOfTotal >= 0.01 && $pctOfTotal <= 0.08) {
                $diff = abs($r['val'] - $maxVal * 0.05 / 1.12);
                if ($diff < $bestGstDiff) {
                    $bestGstDiff = $diff;
                    $bestGstIdx = $ri;
                }
            }
        }
        // If we found a reasonable GST candidate
        if ($bestGstDiff < PHP_FLOAT_MAX) {
            $result['gst'] = $remaining[$bestGstIdx]['str'];
            array_splice($remaining, $bestGstIdx, 1);
        }
    }

    // Find PST: amount closest to 7% of estimated subtotal
    if (isset($hasLabel['pst']) && !empty($remaining)) {
        $bestPstIdx = 0;
        $bestPstDiff = PHP_FLOAT_MAX;
        foreach ($remaining as $ri => $r) {
            $pctOfTotal = $maxVal > 0 ? $r['val'] / $maxVal : 0;
            if ($pctOfTotal >= 0.03 && $pctOfTotal <= 0.12) {
                $diff = abs($r['val'] - $maxVal * 0.07 / 1.12);
                if ($diff < $bestPstDiff) {
                    $bestPstDiff = $diff;
                    $bestPstIdx = $ri;
                }
            }
        }
        if ($bestPstDiff < PHP_FLOAT_MAX) {
            $result['pst'] = $remaining[$bestPstIdx]['str'];
            array_splice($remaining, $bestPstIdx, 1);
        }
    }

    // SUBTOTAL = remaining largest value, or calculate from total - taxes
    if (isset($hasLabel['subtotal'])) {
        if (!empty($remaining)) {
            // Take the largest remaining
            $bestSubIdx = 0;
            $bestSubVal = $remaining[0]['val'];
            for ($i = 1; $i < count($remaining); $i++) {
                if ($remaining[$i]['val'] > $bestSubVal) {
                    $bestSubVal = $remaining[$i]['val'];
                    $bestSubIdx = $i;
                }
            }
            $result['subtotal'] = $remaining[$bestSubIdx]['str'];
        } else {
            // Calculate: total - gst - pst
            $calcSub = $maxVal - (float)($result['gst'] ?? 0) - (float)($result['pst'] ?? 0);
            if ($calcSub > 0) {
                $result['subtotal'] = number_format($calcSub, 2, '.', '');
            }
        }
    }

    // Final validation — if the semantic match also fails, return empty
    if (!validateColumnAmounts($result)) {
        return ['subtotal' => null, 'pst' => null, 'gst' => null, 'total' => null];
    }

    return $result;
}


// ═══════════════════════════════════════════════════════════════════════
// GST / Tax Math Validation
// ═══════════════════════════════════════════════════════════════════════

/**
 * Detect the Canadian provincial tax model from receipt OCR text.
 *
 * Two models are supported:
 *   'gst_pst' — Separate federal GST (5%) + provincial PST lines.
 *               Used in BC (7% PST), SK (6%), MB (7%), QC (TVQ 9.975%).
 *   'hst'     — Blended Harmonised Sales Tax: ON (13%), NS/NB/NL/PEI (15%).
 *               Single HST line on the receipt; no separate PST.
 *
 * Detection is heuristic and based on OCR keyword presence:
 *  - Explicit "HST" label with no "PST"/"TVQ" → blended HST province.
 *  - Ambiguous (only "TAX" label, or "GST/HST" combined label) → defaults to
 *    gst_pst, which is the safe fallback for BC.
 *
 * @param string $text Raw OCR text
 * @return string 'gst_pst' | 'hst'
 */
function detectTaxModel(string $text): string
{
    $hasHst = (bool)preg_match('/\bHST\b/i', $text);
    $hasPst = (bool)preg_match('/\b(?:PST|TVQ|QST)\b/i', $text);

    // HST province: receipt has an HST label but no separate PST or QC TVQ label.
    // "GST/HST" (federal combined label) also qualifies — federal receipts in HST
    // provinces use "GST/HST" but carry no PST line.
    if ($hasHst && !$hasPst) {
        return 'hst';
    }

    return 'gst_pst';
}

/**
 * Extract a standalone HST (Harmonised Sales Tax) amount from receipt text.
 *
 * Distinct from extractGST() — targets pure HST labels without the "GST/" prefix:
 *   "HST (13%)  $13.00"   "HST 13%  $13.00"   "HST  $13.00"
 *   "HST\n13.00"          "HST (13%)\n$\n13.00"
 *
 * @param string $text Raw OCR text
 * @return string|null Formatted amount ("13.00") or null if not found
 */
function extractHST(string $text): ?string
{
    // Same-line patterns (horizontal whitespace only — [^\S\n] prevents cross-line grabs)
    $patterns = [
        // "HST (13%) $13.00" or "HST 13% $13.00" — with explicit dollar sign
        '/\bHST\b[^\S\n]*(?:\([\d.]+%\)|[\d.]+%)?[^\S\n]*:?[^\S\n]*\$[^\S\n]*(\d{1,6}[.,]\d{2})/i',
        // "HST $13.00" or "HST: 13.00" — without explicit dollar sign
        '/\bHST\b[^\S\n]*(?:\([\d.]+%\)|[\d.]+%)?[^\S\n]*:?[^\S\n]*(\d{1,6}[.,]\d{2})(?!\d*%)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return number_format((float)str_replace(',', '.', $m[1]), 2, '.', '');
        }
    }

    // Split-line fallback: "HST\n13.00" or "HST (13%)\n13.00" or "HST\n$\n13.00"
    $lines     = preg_split('/\r?\n/', $text);
    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount - 1; $i++) {
        $line = trim($lines[$i]);
        // Match a line that is just an HST label (optional percentage)
        if (preg_match('/^\bHST\b(?:\s*\([\d.]+%\)|\s*[\d.]+%)?\s*:?\s*$/i', $line)) {
            $nextLine = trim($lines[$i + 1]);
            if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $nextLine, $m)) {
                return number_format((float)str_replace(',', '.', $m[1]), 2, '.', '');
            }
            // Three-line format: "HST\n$\n13.00"
            if ($nextLine === '$' && $i + 2 < $lineCount) {
                $amtLine = trim($lines[$i + 2]);
                if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $amtLine, $m)) {
                    return number_format((float)str_replace(',', '.', $m[1]), 2, '.', '');
                }
            }
        }
    }

    return null;
}

/**
 * Validate that subtotal + GST + PST ≈ total (within tolerance).
 * Returns a validation result that can be shown to the user.
 *
 * @param array $parsed The parsed receipt fields (total, gst, subtotal, pst)
 * @return array ['valid' => bool, 'expected_total' => string, 'actual_total' => string, 'diff' => string, 'message' => string]
 */
function validateGstMath(array $parsed): array
{
    $taxModel = $parsed['tax_model'] ?? 'gst_pst';
    $total    = (float)($parsed['total'] ?? 0);
    $subtotal = (float)($parsed['subtotal'] ?? 0);

    // ── Short-circuit: no total to validate against ───────────────────────────
    if ($total <= 0) {
        return [
            'valid'            => true,
            'expected_total'   => '0.00',
            'actual_total'     => '0.00',
            'diff'             => '0.00',
            'message'          => '',
            'gst_rate_valid'   => true,
            'gst_rate_message' => '',
            'tax_model'        => $taxModel,
        ];
    }

    // ── HST model: subtotal + HST = total ─────────────────────────────────────
    if ($taxModel === 'hst') {
        $hst = (float)($parsed['hst'] ?? 0);

        if ($subtotal <= 0 && $hst <= 0) {
            return [
                'valid'            => true,
                'expected_total'   => number_format($total, 2, '.', ''),
                'actual_total'     => number_format($total, 2, '.', ''),
                'diff'             => '0.00',
                'message'          => '',
                'gst_rate_valid'   => true,
                'gst_rate_message' => '',
                'tax_model'        => 'hst',
            ];
        }

        $expectedTotal = $subtotal + $hst;
        $diff      = abs($expectedTotal - $total);
        $tolerance = max(0.05, $total * 0.005);
        $valid     = ($diff <= $tolerance);

        $message = '';
        if (!$valid) {
            $message = sprintf(
                'Math check: subtotal ($%.2f) + HST ($%.2f) = $%.2f, but total is $%.2f (off by $%.2f)',
                $subtotal, $hst, $expectedTotal, $total, $diff
            );
        }

        // HST rate check: ON = 13%, NS/NB/NL/PEI = 15%. Accept 12–16% range.
        $hstRateValid   = true;
        $hstRateMessage = '';
        if ($subtotal > 0 && $hst > 0) {
            $rate = $hst / $subtotal;
            if ($rate < 0.12 || $rate > 0.16) {
                $hstRateValid   = false;
                $hstRateMessage = sprintf(
                    'HST rate %.1f%% is outside expected range (13%% ON / 15%% NS·NB·NL·PEI)',
                    $rate * 100
                );
            }
        }

        return [
            'valid'            => $valid,
            'expected_total'   => number_format($expectedTotal, 2, '.', ''),
            'actual_total'     => number_format($total, 2, '.', ''),
            'diff'             => number_format($diff, 2, '.', ''),
            'message'          => $message,
            'gst_rate_valid'   => $hstRateValid,
            'gst_rate_message' => $hstRateMessage,
            'tax_model'        => 'hst',
        ];
    }

    // ── GST+PST model (BC / SK / MB / QC) ────────────────────────────────────
    $gst = (float)($parsed['gst'] ?? 0);
    $pst = (float)($parsed['pst'] ?? 0);

    if ($subtotal <= 0 && $gst <= 0) {
        return [
            'valid'            => true,
            'expected_total'   => number_format($total, 2, '.', ''),
            'actual_total'     => number_format($total, 2, '.', ''),
            'diff'             => '0.00',
            'message'          => '',
            'gst_rate_valid'   => true,
            'gst_rate_message' => '',
            'tax_model'        => 'gst_pst',
        ];
    }

    $expectedTotal = $subtotal + $gst + $pst;
    $diff      = abs($expectedTotal - $total);
    $tolerance = max(0.05, $total * 0.005);
    $valid     = ($diff <= $tolerance);

    $message = '';
    if (!$valid) {
        $message = sprintf(
            'Math check: subtotal ($%.2f) + GST ($%.2f)%s = $%.2f, but total is $%.2f (off by $%.2f)',
            $subtotal, $gst,
            $pst > 0 ? sprintf(' + PST ($%.2f)', $pst) : '',
            $expectedTotal, $total, $diff
        );
    }

    // GST rate check: should be ~5% of subtotal (allow 2–15% for rounding edge cases)
    $gstRateValid   = true;
    $gstRateMessage = '';
    if ($subtotal > 0 && $gst > 0) {
        $expectedGst  = round($subtotal * 0.05, 2);
        $gstDiff      = abs($gst - $expectedGst);
        $gstTolerance = max(0.10, $subtotal * 0.005);
        if ($gstDiff > $gstTolerance) {
            $gstRateValid   = false;
            $gstRateMessage = sprintf(
                'GST $%.2f is not ~5%% of subtotal $%.2f (expected ~$%.2f)',
                $gst, $subtotal, $expectedGst
            );
        }
    }

    return [
        'valid'            => $valid,
        'expected_total'   => number_format($expectedTotal, 2, '.', ''),
        'actual_total'     => number_format($total, 2, '.', ''),
        'diff'             => number_format($diff, 2, '.', ''),
        'message'          => $message,
        'gst_rate_valid'   => $gstRateValid,
        'gst_rate_message' => $gstRateMessage,
        'tax_model'        => 'gst_pst',
    ];
}
