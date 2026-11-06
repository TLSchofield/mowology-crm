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
function parseReceiptText(string $ocrText, ?array $rawResponse = null, ?array $vendorProfile = null): array
{
    $result = [
        'total'          => null,
        'gst'            => null,
        'pst'            => null,
        'subtotal'       => null,
        'date'           => null,
        'date_order'     => null,   // 'dmy'|'mdy' when the receipt itself proves it
        'date_order_source' => null, // 'receipt'|'vendor'|'default' when the date was ambiguous
        'time'           => null,
        'vendor_hint'    => null,
        'card_last4'     => null,
        'payment_method' => null,
        'line_items'     => [],
    ];

    // "G.S.T." / "P.S.T." / "H.S.T." (supplier invoices) read as GST / PST / HST, or the tax rules miss them and the 5% guess
    // runs instead (Northwest Landscape #537, 2026-11-06: printed GST 4.53 + PST 6.34 read as GST 4.83, no PST).
    $ocrText = (string)preg_replace('/\b([GPH])\.\s?S\.\s?T\.?(?![A-Za-z])/i', '$1ST', $ocrText);
    $lines = preg_split('/\r?\n/', $ocrText);
    if (empty($lines)) {
        return $result;
    }

    // Vendor hint: typically first non-empty line or first line with letters
    $result['vendor_hint'] = extractVendorHint($lines);

    // Purchase time of day — matches the receipt to the visit it was bought for
    $result['time'] = extractPurchaseTime($lines);

    // Total
    $result['total'] = extractTotal($ocrText, $lines);
    if ($result['total'] === null) {
        $result['total'] = extractPaymentLineAmount($lines);
    } else {
        $result['total'] = preferConfirmedPaymentTotal($lines, $result['total']);
    }

    // GST (5% — BC standard; also catches generic "TAX" labels)
    $result['gst'] = extractGST($ocrText);

    // PST (also matches its provincial alias RST)
    $result['pst'] = extractPST($ocrText);

    // Subtotal
    $result['subtotal'] = extractSubtotal($ocrText);

    // Date
    $vendorOrder = $vendorProfile['date_order'] ?? null;
    $result['date'] = extractDate($ocrText, $vendorOrder);
    $result['date_order'] = receiptDateOrder($ocrText);
    if ($result['date'] !== null && receiptAmbiguousDate($ocrText) !== null) {
        $result['date_order_source'] = $result['date_order'] !== null ? 'receipt'
            : (in_array($vendorOrder, ['dmy', 'mdy'], true) ? 'vendor' : 'default');
    }

    // Payment method + card last 4
    $paymentInfo = extractPaymentInfo($ocrText);
    $result['card_last4']     = $paymentInfo['card_last4'];
    $result['payment_method'] = $paymentInfo['payment_method'];

    // Pump receipt: one deterministic fuel line (litres x price per litre = amount) beats generic item parsing.
    $fuelItem = extractFuelPumpItem($lines);

    // Line items — try position-aware extraction first (groups words by Y-coordinate)
    $positionLines = null;
    if (isset($rawResponse['device_lines']) && is_array($rawResponse['device_lines'])) {
        // The phone's own read sent each line with its box (DeviceOcrCompare.php cleanDeviceOcr()).
        $positionLines = reconstructLinesFromDeviceBoxes($rawResponse['device_lines'], $rawResponse['device_image'] ?? []);
    } elseif ($rawResponse !== null) {
        $positionLines = reconstructLinesFromVisionResponse($rawResponse);
    }
    if (!empty($positionLines)) {
        $result['line_items'] = extractLineItems($positionLines, $vendorProfile);
    }
    // Always also run plain-text extraction (fullTextAnnotation reads left column then right,
    // so qty@unit and total appear on separate lines — different items than position-aware).
    // Merge in any items found by plain text that position-aware missed.
    $plainItems = extractLineItems($lines, $vendorProfile);
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

    if ($fuelItem !== null) {
        $result['_fuel_item'] = $fuelItem;     // resolved below, once the totals are known
    }

    // Fallback: handle column-separated OCR where labels and amounts are in separate blocks
    // e.g. "SUBTOTAL\nPST\nGST\n...\nTOTAL\n...\n41.96\n2.94\n2.10\n...\n47.00"
    if ($result['total'] === null || $result['gst'] === null || $result['subtotal'] === null || $result['pst'] === null) {
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
        if ($result['pst'] === null && $columnParsed['pst'] !== null) {
            $result['pst'] = $columnParsed['pst'];
        }
    }

    // If we found subtotal and GST but no total, calculate it
    if ($result['total'] === null && $result['subtotal'] !== null && $result['gst'] !== null) {
        $result['total'] = number_format(
            (float)$result['subtotal'] + (float)$result['gst'] + (float)($result['pst'] ?? 0),
            2,
            '.',
            ''
        );
        $result['total_derived'] = true;
    }

    // If we have total and GST but no subtotal, calculate subtotal = total - GST - PST
    if ($result['subtotal'] === null && $result['total'] !== null && $result['gst'] !== null) {
        $result['subtotal'] = number_format(
            (float)$result['total'] - (float)$result['gst'] - (float)($result['pst'] ?? 0),
            2,
            '.',
            ''
        );
        $result['subtotal_derived'] = true;
    }

    // A store that itemises tax (a "See GST #" / "P - GST" legend) prints a GST line whenever GST is charged:
    // no GST line there means none was charged (water, deposits), not a 5% guess. Replay 2026-11-04.
    if ($result['gst'] === null && $result['total'] !== null && receiptItemisesTaxWithoutGstLine($lines)) {
        $result['gst'] = '0.00';
    }

    // A receipt that names no tax anywhere charged none (City of Vancouver landfill, Tim 2026-11-06: #533 read
    // $39.05 + $1.95 GST for a $41.00 ticket). Guessing 5% here booked GST that was never paid.
    if ($result['gst'] === null && $result['total'] !== null && receiptPrintsNoTax($lines)) {
        $result['gst'] = '0.00';
        $result['gst_source'] = 'exempt';   // the label intake already uses for a GST-free receipt
        if ($result['subtotal'] === null) {
            $result['subtotal'] = $result['total'];
            $result['subtotal_derived'] = true;
        }
    }

    // If we have total but no GST, estimate GST at 5% (BC standard)
    if ($result['gst'] === null && $result['total'] !== null) {
        $total = (float)$result['total'];
        $result['gst'] = number_format($total / 1.05 * 0.05, 2, '.', '');
        $result['gst_estimated'] = true;
        // Also calculate subtotal from the estimated GST
        if ($result['subtotal'] === null) {
            $result['subtotal'] = number_format($total / 1.05, 2, '.', '');
            $result['subtotal_derived'] = true;
        }
    }

    // Impossible amounts (negative subtotal, tax ≥ total) are a failed read — fall back.
    $result = sanitizeReceiptAmounts($result);

    // Where the GST number came from, so a client never shows an estimate as if it were printed.
    $result = tagGstSource($result);

    // This vendor's learned layout (items an owner accepted from Penny's read, with where each
    // amount sits relative to its printed name): used when it adds up better than the generic
    // pass. Before the quality signal, so a receipt it reads right is not escalated to Vision/LLM.
    if (!empty($vendorProfile['layout'])) {
        $result = mergeLayoutItems($result, applyLineLayout($ocrText, $vendorProfile['layout']));
    }

    // A phone text reader can emit the product names and numeric columns as separate blocks.
    // Use that interpretation only to repair a read that misses the printed/derived pre-tax subtotal.
    $subtotal = isset($result['subtotal']) && is_numeric($result['subtotal']) ? (float)$result['subtotal'] : null;
    if ($subtotal === null && isset($result['total']) && is_numeric($result['total'])) {
        $subtotal = (float)$result['total'] - (float)($result['gst'] ?? 0) - (float)($result['pst'] ?? 0);
    }
    if ($subtotal === null) $subtotal = separatedColumnsSubtotal($lines);
    if ($subtotal !== null && $subtotal > 0 && !receiptItemsAddUp($result['line_items'] ?? [], $subtotal)) {
        $columnItems = pairSeparatedColumns($lines);
        if (receiptItemsAddUp($columnItems, $subtotal)) {
            $result['line_items'] = $columnItems;
            $result['line_items_source'] = 'columns';
        }
    }

    // Text-layer PDFs often preserve each invoice row on one line. Use those rows only as
    // a repair: an existing read that already accounts for the pre-tax subtotal always wins.
    $invoiceTableSubtotal = invoiceTableSubtotal($lines, $result);
    if ($invoiceTableSubtotal !== null && $invoiceTableSubtotal > 0
        && !receiptItemsAddUp($result['line_items'] ?? [], $invoiceTableSubtotal)) {
        $invoiceTableItems = extractInvoiceTableItems($lines);
        if (receiptItemsAddUp($invoiceTableItems, $invoiceTableSubtotal)) {
            $result['line_items'] = $invoiceTableItems;
            $result['line_items_source'] = 'invoice_table';
        }
    }

    // The fuel line stands in for the generic items when it accounts for the whole purchase
    // (its amount is the total, the subtotal, or the total less tax — pump prices include GST).
    if (isset($result['_fuel_item'])) {
        $fuel = $result['_fuel_item'];
        unset($result['_fuel_item']);
        if (fuelItemFitsReceipt($fuel, $result)) {
            $result['line_items']        = [$fuel];
            $result['line_items_source'] = 'fuel_pump';
        }
    }

    // Line-item quality signal: do the parsed items add up to the subtotal?
    // 'match' | 'mismatch' | 'none'. Drives the Tesseract→Vision escalation and the
    // LLM tier (ReceiptLineItemIntelligence.php) and is shown on the review card.
    $result['line_items'] = dropTaxLineItems($result['line_items'] ?? []);
    $result = array_merge($result, assessLineItemQuality($result));

    return $result;
}


/**
 * gst_source: 'printed' (read off the receipt) | 'estimated' (5% of the total, the parser's own guess).
 * The estimated number itself is unchanged — existing callers still receive it. Pure.
 */
function tagGstSource(array $r): array
{
    if (isset($r['gst']) && $r['gst'] !== null && $r['gst'] !== '') {
        if (($r['gst_source'] ?? '') === 'exempt') return $r;   // no tax named on the receipt (receiptPrintsNoTax)
        $r['gst_source'] = !empty($r['gst_estimated']) ? 'estimated' : 'printed';
    }
    return $r;
}


/**
 * Sum the non-adjustment line items and compare to the subtotal (or, failing that,
 * the total). Tolerance is the larger of $0.05 and 1% — OCR rounding, not a missed line.
 *
 * @return array{items_sum: ?string, line_items_quality: string}
 */
function assessLineItemQuality(array $parsed): array
{
    $items = $parsed['line_items'] ?? [];
    if (empty($items)) {
        return ['items_sum' => null, 'line_items_quality' => 'none'];
    }
    $sum = 0.0;
    foreach ($items as $it) {
        $sum += (float)($it['line_total'] ?? $it['amount'] ?? 0);
    }
    if (($parsed['line_items_source'] ?? '') === 'fuel_pump' && count($items) === 1 && fuelItemFitsReceipt($items[0], $parsed)) {
        return ['items_sum' => number_format($sum, 2, '.', ''), 'line_items_quality' => 'match'];
    }
    $reference = $parsed['subtotal'] !== null && $parsed['subtotal'] !== ''
        ? (float)$parsed['subtotal']
        : (($parsed['total'] ?? null) !== null ? (float)$parsed['total'] : null);
    $quality = 'none';
    if ($reference !== null && $reference > 0) {
        $tolerance = max(0.05, $reference * 0.01);
        $quality   = abs($sum - $reference) <= $tolerance ? 'match' : 'mismatch';
    }
    return ['items_sum' => number_format($sum, 2, '.', ''), 'line_items_quality' => $quality];
}


/**
 * Does a fuel line account for the receipt? Its amount equals the total, the subtotal, or the total
 * less GST/PST (within $0.05) — pump prices include GST, so any of the three is the same purchase. Pure.
 */
function fuelItemFitsReceipt(array $item, array $parsed): bool
{
    $amt = (float)($item['line_total'] ?? $item['amount'] ?? 0);
    if ($amt <= 0) return false;
    $refs = [];
    foreach (['total', 'subtotal'] as $f) {
        if (isset($parsed[$f]) && is_numeric($parsed[$f])) $refs[] = (float)$parsed[$f];
    }
    if (isset($parsed['total']) && is_numeric($parsed['total'])) {
        $refs[] = (float)$parsed['total'] - (float)($parsed['gst'] ?? 0) - (float)($parsed['pst'] ?? 0);
    }
    foreach ($refs as $r) {
        if (abs($amt - $r) <= 0.05) return true;
    }
    return false;
}

/**
 * Recognise a fuel-pump block: grade, litres, price per litre and the printed fuel amount.
 * One item when litres x price agrees with the amount (within 2% or $0.10), else null. Pure.
 *
 *   Supreme (91) / 10.124L AT $2.299/L / Fuel sales $ 23.28  →  "Supreme (91) fuel", 10.124 @ 2.299 = 23.28
 *
 * @param list<string> $lines
 * @return ?array parser-shaped item (name, amount, line_total, quantity, unit_price, sku_raw, name_source 'fuel_pump')
 */
function extractFuelPumpItem(array $lines): ?array
{
    $text = implode("\n", $lines);
    if (!preg_match('/\b(?:FUEL\s+SALES?|FUEL\s+AMOUNT|GAS\s+SALES?|TOTAL\s+FUEL|FUEL\s+TOTAL)\b\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})(?!\d)/i', $text, $am)) {
        return null;
    }
    $amount = (float)str_replace(',', '.', $am[1]);

    $litres = null;
    if (preg_match('/(?<![\d.,])(\d{1,4}[.,]\d{2,3})\s*L(?:ITRES?|TRS?)?\b(?!\s*\/)/i', $text, $m)
        || preg_match('/\b(?:VOLUME|LITRES?|LTRS?|QTY)\s*:?\s*(\d{1,4}[.,]\d{2,3})\b/i', $text, $m)) {
        $litres = (float)str_replace(',', '.', $m[1]);
    }
    $price = null;
    if (preg_match('/\$?\s*(\d[.,]\d{3,4})\s*\/\s*L\b/i', $text, $m)
        || preg_match('/\bPRICE\s*\/?\s*(?:PER\s*)?L(?:ITRE)?\s*:?\s*\$?\s*(\d[.,]\d{3,4})\b/i', $text, $m)) {
        $price = (float)str_replace(',', '.', $m[1]);
    }
    if ($litres === null || $price === null || $litres <= 0 || $price <= 0) {
        return null;
    }
    if (abs($litres * $price - $amount) > max(0.10, $amount * 0.02)) {
        return null;
    }

    $grade = null;
    if (preg_match('/\b(REGULAR|PREMIUM|SUPREME|SUPER|PLUS|MID-?GRADE|MIDGRADE|DIESEL|UNLEADED|ULSD)\b([^\S\n]*\(?[^\S\n]*(\d{2})[^\S\n]*\)?(?![\d.,]))?/i', $text, $g)) {
        $grade = ucfirst(strtolower($g[1])) . (!empty($g[3]) ? ' (' . $g[3] . ')' : '');
    } elseif (preg_match('/\b(?:REGULAR|UNL|DSL)\b/i', $text)) {
        $grade = 'Fuel';
    }
    if ($grade === null) {
        require_once dirname(__DIR__, 2) . '/Modules/Expenses/Services/ReceiptBookkeeperRules.php';
        $type = ReceiptBookkeeperRules::fuelType(strtoupper($text));
        if ($type === null) return null;
        $grade = $type === 'diesel' ? 'Diesel' : 'Gasoline';
    }
    $name = $grade === 'Fuel' ? 'Fuel' : $grade . ' fuel';

    return [
        'name'        => $name,
        'amount'      => number_format($amount, 2, '.', ''),
        'line_total'  => number_format($amount, 2, '.', ''),
        'quantity'    => $litres,
        'unit_price'  => $price,
        'unit'        => 'L',
        'sku_raw'     => null,
        'ocr_name'    => $name,
        'name_source' => 'fuel_pump',
    ];
}


// ============================================================================
//  Learned line layout (2026-10-13) — the reader taught by Penny's accepted read.
//
//  When the owner accepts Penny's line items over the reader's, each accepted item is
//  located on the receipt text: its printed name line (the anchor) and where its amount
//  sits from there (line offset + which amount on that line, counted from the right).
//  The next receipt with the same layout from that vendor reads those items the same way,
//  whatever the prices are. Stored as receipt_parse_lessons rows (field 'line_item_layout',
//  ReceiptLearning::recordLineLayoutLessons); read by getVendorLineItemProfile()['layout'].
// ============================================================================

/**
 * A printed line (or an item name) as a layout key: money amounts, "@" prices and bare
 * numbers (quantities) dropped, letters+digits words kept, upper case, no spaces.
 * "2 Deposit ($0.20)" → "DEPOSIT"; "CRF 0.07" → "CRF"; "BISTRO 24OZ HOT BE" → "BISTRO24OZHOTBE". Pure.
 */
function lineLayoutKey(string $s): string
{
    $s = strtoupper($s);
    $s = preg_replace('/\$?\s*\d{1,6}[.,]\d{2}(?!\d)/', ' ', $s);      // money
    $s = preg_replace('/(?<![A-Z0-9])\d+(?![A-Z0-9])/', ' ', $s);        // bare numbers (qty, "x2")
    $s = preg_replace('/(?<![A-Z0-9])X(?![A-Z0-9])/', ' ', $s);           // a lone "x" left by "x2"
    return (string)preg_replace('/[^A-Z0-9]/', '', $s);
}

/** Every money amount on a line, left to right ("2.49 2.49P" → [2.49, 2.49]). Pure. */
function lineMoneyAmounts(string $line): array
{
    preg_match_all('/(?<![\d.,])(\d{1,6})[.,](\d{2})(?!\d)/', $line, $m, PREG_SET_ORDER);
    return array_map(fn($x) => round((float)($x[1] . '.' . $x[2]), 2), $m);
}

/** An item name without the prices printed in it ("BP COKE (2 @ $3.00)" → "BP COKE (2)"). Pure. */
function layoutItemName(string $name): string
{
    $n = preg_replace('/@?\s*\$?\s*\d{1,6}[.,]\d{2}(?!\d)/', '', $name);
    $n = preg_replace('/\(\s*\)/', '', (string)$n);
    $n = preg_replace('/\s+\)/', ')', (string)$n);
    $n = trim((string)preg_replace('/\s{2,}/', ' ', (string)$n));
    return $n !== '' ? $n : trim($name);
}

/**
 * Where each accepted item is printed on this receipt. Items whose name or amount can't be
 * found are left out (nothing is guessed). Pure.
 *
 * @param array $items [{name, amount|line_total}]
 * @return list<array{key: string, name: string, off: int, idx: int}>
 *   key = the anchor line's layoutKey; off = amount line − anchor line; idx = the amount's
 *   position on that line counted from the right (0 = last).
 */
function learnLineLayout(string $ocrText, array $items): array
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $ocrText)), fn($l) => $l !== ''));
    $keys = array_map('lineLayoutKey', $lines);
    $used = [];
    $out = [];
    foreach ($items as $it) {
        $name = trim((string)($it['name'] ?? ''));
        $amount = $it['amount'] ?? $it['line_total'] ?? null;
        if ($name === '' || $amount === null || !is_numeric($amount)) continue;
        $amount = round((float)$amount, 2);
        if ($amount == 0.0) continue;
        $nameKey = lineLayoutKey((string)preg_replace('/\([^)]*\)/', ' ', $name));
        if (strlen($nameKey) < 3) continue;
        $anchor = null;
        foreach ($keys as $i => $k) {
            if (isset($used[$i]) || strlen($k) < 3) continue;
            $short = min(strlen($k), strlen($nameKey));
            if ($k === $nameKey || ($short >= 5 && (strpos($nameKey, $k) === 0 || strpos($k, $nameKey) === 0))) {
                $anchor = $i;
                break;
            }
        }
        if ($anchor === null) continue;
        $found = null;
        foreach ([0, -1, 1, -2, 2, -3, 3] as $off) {
            $j = $anchor + $off;
            if (!isset($lines[$j])) continue;
            $amts = lineMoneyAmounts($lines[$j]);
            for ($a = count($amts) - 1; $a >= 0; $a--) {
                if (abs($amts[$a] - $amount) < 0.005) { $found = [$off, count($amts) - 1 - $a]; break 2; }
            }
        }
        if ($found === null) continue;
        $used[$anchor] = true;
        $out[] = ['key' => $keys[$anchor], 'name' => layoutItemName($name), 'off' => $found[0], 'idx' => $found[1]];
    }
    return $out;
}

/**
 * Read items off a receipt with a learned layout. Each anchor line found yields one item:
 * the name it was taught, the amount at its offset / position. Receipt order. Pure.
 *
 * @param list<array{key: string, name: string, off: int, idx: int}> $layout
 * @return list<array> parser-shaped items (name, amount, quantity, unit_price, sku_raw, ocr_name, name_source 'layout')
 */
function applyLineLayout(string $ocrText, array $layout): array
{
    if (!$layout) return [];
    $byKey = [];
    foreach ($layout as $l) {
        $k = (string)($l['key'] ?? '');
        if (strlen($k) >= 3 && !isset($byKey[$k])) $byKey[$k] = $l;       // first = newest (caller's order)
    }
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $ocrText)), fn($l) => $l !== ''));
    $found = [];
    foreach ($lines as $i => $line) {
        $k = lineLayoutKey($line);
        if (!isset($byKey[$k])) continue;
        $l = $byKey[$k];
        $j = $i + (int)$l['off'];
        if (!isset($lines[$j])) continue;
        $amts = lineMoneyAmounts($lines[$j]);
        $pos = count($amts) - 1 - (int)$l['idx'];
        if ($pos < 0 || !isset($amts[$pos]) || $amts[$pos] <= 0) continue;
        $found[] = ['name' => (string)$l['name'], 'amount' => number_format($amts[$pos], 2, '.', ''), 'quantity' => 1,
                    'unit_price' => null, 'sku_raw' => null, 'ocr_name' => $line, 'name_source' => 'layout'];
    }
    return $found;
}

/**
 * Use the layout's items when they add up to the receipt better than the generic pass's
 * (subtotal, else total). Pure.
 */
function mergeLayoutItems(array $parsed, array $layoutItems): array
{
    if (!$layoutItems) return $parsed;
    $ref = null;
    foreach (['subtotal', 'total'] as $f) {
        if (isset($parsed[$f]) && is_numeric($parsed[$f]) && (float)$parsed[$f] > 0) { $ref = (float)$parsed[$f]; break; }
    }
    if ($ref === null) return $parsed;
    $sum = fn(array $items) => array_sum(array_map(fn($i) => (float)($i['line_total'] ?? $i['amount'] ?? 0), $items));
    $layoutGap = abs($sum($layoutItems) - $ref);
    $parserGap = abs($sum($parsed['line_items'] ?? []) - $ref);
    $tolerance = max(0.05, $ref * 0.01);
    if ($layoutGap <= $tolerance || ($layoutGap < $parserGap && empty($parsed['line_items']))) {
        $parsed['line_items'] = $layoutItems;
        $parsed['line_items_layout'] = count($layoutItems);
    }
    return $parsed;
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
 * Rebuild receipt rows from the phone's own text read (Apple Vision on iPhone, ML Kit on Android).
 *
 * The phone reads in blocks: on a Chevron slip every item name comes first, then every price, so its
 * plain text cannot pair them. Each recognised line arrives with its box (x, y, w, h as 0–1 fractions of
 * the photo, top-left origin) and, when the phone measured it, its tilt in degrees. Boxes whose vertical
 * centres sit within half a line height of each other — after taking out the photo's median tilt — form
 * one row, read left to right and joined by two spaces; two boxes that overlap side by side are never the same row.
 *
 * @param array $boxes [['text' => string, 'x' => float, 'y' => float, 'w' => float, 'h' => float, 'deg' => ?float], ...]
 * @param array $image ['w' => pixels, 'h' => pixels] of the photo the boxes were measured on, for the tilt
 * @return string[] Row strings, top to bottom
 */
function reconstructLinesFromDeviceBoxes(array $boxes, array $image = []): array
{
    // Work in pixels when the size is known, so a tilt angle means the same thing across and down the photo.
    $iw = (float)($image['w'] ?? 0) > 0 ? (float)$image['w'] : 1000.0;
    $ih = (float)($image['h'] ?? 0) > 0 ? (float)$image['h'] : 1000.0;

    $items = [];
    $angles = [];
    foreach ($boxes as $b) {
        if (!is_array($b)) continue;
        $text = trim((string)($b['text'] ?? ''));
        $x = $b['x'] ?? null; $y = $b['y'] ?? null; $w = $b['w'] ?? null; $h = $b['h'] ?? null;
        if ($text === '' || !is_numeric($x) || !is_numeric($y) || !is_numeric($w) || !is_numeric($h)) continue;
        if ((float)$w <= 0 || (float)$h <= 0) continue;
        $items[] = [
            'text'  => $text,
            'left'  => (float)$x * $iw,
            'right' => ((float)$x + (float)$w) * $iw,
            'cx'    => ((float)$x + (float)$w / 2) * $iw,
            'cy'    => ((float)$y + (float)$h / 2) * $ih,
            'h'     => (float)$h * $ih,
        ];
        // Only wide lines give a trustworthy angle; a price box is too short to measure one.
        if (isset($b['deg']) && is_numeric($b['deg']) && abs((float)$b['deg']) < 20 && (float)$w >= 0.15) {
            $angles[] = (float)$b['deg'];
        }
    }
    if (empty($items)) return [];

    $tan = 0.0;
    if (count($angles) >= 3) {
        sort($angles);
        $median = $angles[(int)(count($angles) / 2)];
        if (abs($median) >= 0.3) $tan = tan(deg2rad($median));
    }
    foreach ($items as &$it) {
        $it['ry'] = $it['cy'] - $it['cx'] * $tan;   // the row position with the tilt taken out
    }
    unset($it);

    usort($items, static function ($a, $b) {
        return $a['ry'] <=> $b['ry'];
    });
    $heights = array_column($items, 'h');
    sort($heights);
    $threshold = max($heights[(int)(count($heights) / 2)] * 0.5, 0.001);

    $rows = [];
    foreach ($items as $it) {
        $placed = false;
        // Only the last couple of rows can still take this box (they are sorted top to bottom).
        for ($r = count($rows) - 1; $r >= 0 && $r >= count($rows) - 2; $r--) {
            if (abs($it['ry'] - $rows[$r]['ry']) > $threshold) continue;
            $clash = false;
            foreach ($rows[$r]['items'] as $o) {
                $overlap = min($it['right'], $o['right']) - max($it['left'], $o['left']);
                if ($overlap > 0.3 * min($it['right'] - $it['left'], $o['right'] - $o['left'])) { $clash = true; break; }
            }
            if ($clash) continue;
            $rows[$r]['items'][] = $it;
            $n = count($rows[$r]['items']);
            $rows[$r]['ry'] += ($it['ry'] - $rows[$r]['ry']) / $n;
            $placed = true;
            break;
        }
        if (!$placed) $rows[] = ['ry' => $it['ry'], 'items' => [$it]];
    }

    usort($rows, static function ($a, $b) {
        return $a['ry'] <=> $b['ry'];
    });
    $result = [];
    foreach ($rows as $row) {
        usort($row['items'], static function ($a, $b) {
            return $a['left'] <=> $b['left'];
        });
        // Two spaces between pieces: the item reader takes "name  price" as one item (as the phone's own rows do).
        $line = trim(implode('  ', array_column($row['items'], 'text')));
        if ($line !== '') $result[] = $line;
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
function extractLineItems(array $lines, ?array $vendorProfile = null): array
{
    $items = [];
    $inItemZone = false;

    // Per-vendor knowledge from ReceiptLearning::getVendorLineItemProfile():
    //   noise         — lines the user has repeatedly marked "not an item" (skipped)
    //   known_names   — names from this vendor's purchase history; a bare line that
    //                   matches one is queued as a pending item even with no SKU or
    //                   same-line price (the case the fixed cascade always dropped)
    //   discount_prefix / barcode_length — extend the discount and barcode patterns
    $noiseKeys      = array_map('strtoupper', $vendorProfile['noise'] ?? []);
    $knownNameKeys  = [];
    foreach ($vendorProfile['known_names'] ?? [] as $kn) {
        $knownNameKeys[strtoupper(trim($kn))] = $kn;
    }
    $discountPrefix = !empty($vendorProfile['discount_prefix']) ? preg_quote($vendorProfile['discount_prefix'], '/') : null;
    $barcodeLen     = !empty($vendorProfile['barcode_length']) ? (int)$vendorProfile['barcode_length'] : null;
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

        // Learned noise for this vendor — skip outright
        if ($noiseKeys && in_array(strtoupper($line), $noiseKeys, true)) continue;

        // Count + bottle deposit total. The parenthesised value is the entire line, not a unit price.
        if (preg_match('/^(\d+)\s+Deposit\s*\(\s*\$?\s*(\d{1,6}\.\d{2})\s*\)\s*$/i', $line, $m)) {
            $items[] = ['name' => 'Deposit', 'amount' => $m[2], 'quantity' => (int)$m[1],
                        'unit_price' => null, 'sku_raw' => null, 'is_bottle_deposit' => true];
            $inItemZone = true;
            continue;
        }

        // Plain-text Chevron rows: name, then "qty price", then the printed amount. Consume all
        // three lines together so the backward price fallback cannot read the same block twice.
        $qtyPrice = trim((string)($lines[$i + 1] ?? ''));
        $printedAmount = trim((string)($lines[$i + 2] ?? ''));
        if (preg_match('/[A-Za-z]{3,}/', $line) && !isTaxOrSummaryLabel($line) && !isColumnHeading($line)
            && preg_match('/^(\d{1,3})\s+\$?(\d{1,6}\.\d{2})$/', $qtyPrice, $qm)
            && preg_match('/^\$?(\d{1,6}\.\d{2})\s*[A-Z]{0,2}$/', $printedAmount, $am)
            && abs((int)$qm[1] * (float)$qm[2] - (float)$am[1]) < 0.015) {
            $items[] = ['name' => $line, 'amount' => $am[1], 'quantity' => (int)$qm[1],
                        'unit_price' => (float)$qm[2], 'sku_raw' => null];
            $inItemZone = true;
            $i += 2;
            continue;
        }
        $qtyOnly = trim((string)($lines[$i + 1] ?? ''));
        $unitOnly = trim((string)($lines[$i + 2] ?? ''));
        $amountOnly = trim((string)($lines[$i + 3] ?? ''));
        if (preg_match('/[A-Za-z]{3,}/', $line) && !isTaxOrSummaryLabel($line) && !isColumnHeading($line)
            && preg_match('/^(\d{1,3})$/', $qtyOnly, $qm)
            && preg_match('/^\$?(\d{1,6}\.\d{2})$/', $unitOnly, $um)
            && preg_match('/^\$?(\d{1,6}\.\d{2})\s*[A-Z]{0,2}$/', $amountOnly, $am)
            && abs((int)$qm[1] * (float)$um[1] - (float)$am[1]) < 0.015) {
            $items[] = ['name' => $line, 'amount' => $am[1], 'quantity' => (int)$qm[1],
                        'unit_price' => (float)$um[1], 'sku_raw' => null];
            $inItemZone = true;
            $i += 3;
            continue;
        }

        // Multi-buy promotion with an amount — Home Depot prints "Buy 10 Get 10% Bagged Lim -14.98"
        // (OCR often "Bu 10 Get…") under the item it discounts: net it into that item, so the lime
        // reads 10 x $13.48 = $134.82, not $149.80 (2026-10-13). The "----Buy 10 Get 10%----" banner
        // has no amount and is skipped above.
        if (preg_match('/\bget\s*\d{1,3}\s*%|\b\d{1,3}\s*%\s*off\b|\bbuy\s*\d+\s*get\b/i', $line)
            && preg_match('/-\s*\$?(\d{1,6}\.\d{2})\s*[A-Z]?\s*$|\$?(\d{1,6}\.\d{2})\s*-\s*$/', $line, $dm)) {
            $items = netDiscountIntoLastItem($items, '-' . (($dm[1] ?? '') !== '' ? $dm[1] : $dm[2]));
            $inItemZone = true;
            continue;
        }

        // Learned discount prefix (e.g. "PROMO" at a vendor that doesn't say DISCOUNT)
        if ($discountPrefix && preg_match('/^' . $discountPrefix . '/i', $line)
            && preg_match('/(-?\d{1,6}\.\d{2})/', $line, $dm)) {
            $items = netDiscountIntoLastItem($items, '-' . ltrim($dm[1], '-'));
            $inItemZone = true;
            continue;
        }

        // Learned barcode length: "<N digits> <name>" — same shape as the generic
        // barcode pattern below but exact-length, so it wins over looser patterns
        // (e.g. a 12-digit UPC at a vendor whose SKUs are always 12 digits).
        if ($barcodeLen && preg_match('/^(\d{' . $barcodeLen . '})\s+(.{3,}?)\s*$/', $line, $bm)) {
            $itemName = trim($bm[2]);
            if (preg_match('/^(.+?)\s+\$?(\d{1,6}\.\d{2})\s*$/', $itemName, $pm)) {
                $items[] = ['name' => trim($pm[1]), 'amount' => $pm[2], 'quantity' => 1, 'unit_price' => null, 'sku_raw' => $bm[1]];
            } else {
                $pendingItems[] = $itemName;
                $pendingContext[] = ['sku_raw' => $bm[1], 'quantity' => 1, 'unit_price' => null];
            }
            $inItemZone = true;
            continue;
        }

        // Bare product-name line that matches this vendor's purchase history — queue it
        // so the next standalone price line attaches to it. Scoped to known names only,
        // so header/address lines can't be mistaken for products.
        if ($knownNameKeys && isset($knownNameKeys[strtoupper($line)]) && !preg_match('/\d{1,6}\.\d{2}/', $line)) {
            $pendingItems[] = $knownNameKeys[strtoupper($line)];
            $pendingContext[] = ['sku_raw' => null, 'quantity' => 1, 'unit_price' => null];
            $inItemZone = true;
            continue;
        }

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
            $materialAmount = null;
            $totalFee = null;
            $quantity = 1;
            $unit = null;
            $unitPrice = null;
            $consumedThrough = $i;
            for ($j = $i + 1; $j < min($lineCount, $i + 9); $j++) {
                $look = trim($lines[$j]);
                if ($materialAmount === null && preg_match('/^\$?(\d{1,6}\.\d{2})$/', $look, $mm)) {
                    $materialAmount = (float)$mm[1];
                    $consumedThrough = max($consumedThrough, $j);
                }
                if (preg_match('/^(\d+\.?\d*)\s*(tonne|kg|yard|cu\.?\s*yd|m3|litre|gal)s?\s*@\s*\$?(\d+\.?\d*)\s*\/\s*\w+/i', $look, $tm)) {
                    $quantity = (float)$tm[1]; $unit = strtolower($tm[2]); $unitPrice = (float)$tm[3];
                    $consumedThrough = max($consumedThrough, $j);
                }
                if (preg_match('/^Total\s+Fee\s*:?\s*\$?\s*(\d{1,6}\.\d{2})$/i', $look, $fm)) {
                    $totalFee = (float)$fm[1];
                    $consumedThrough = max($consumedThrough, $j);
                }
                if (preg_match('/^Total\s+Fee\s*:?\s*$/i', $look)
                    && preg_match('/^\$?(\d{1,6}\.\d{2})$/', trim((string)($lines[$j + 1] ?? '')), $fm)) {
                    $totalFee = (float)$fm[1];
                    $consumedThrough = max($consumedThrough, $j + 1);
                }
            }
            if ($materialAmount !== null && $totalFee !== null && abs($materialAmount - $totalFee) <= 1.00) {
                $items[] = ['name' => $itemName, 'amount' => number_format($totalFee, 2, '.', ''),
                            'line_total' => number_format($totalFee, 2, '.', ''), 'quantity' => $quantity,
                            'unit_price' => $unitPrice, 'unit' => $unit, 'sku_raw' => null];
                $inItemZone = true;
                $i = min($lineCount - 1, $consumedThrough);
                continue;
            }
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

        // "4@12.98" alone, its total on the next line (OCR splits Home Depot's two columns): the
        // quantity and unit price of the queued item; the next price line is its total (2026-10-13).
        if (preg_match('/^(\d+(?:\.\d+)?)\s*@\s*\$?(\d{1,6}\.\d{2})\s*$/', $line, $m)) {
            if (!empty($pendingContext)) {
                $k = count($pendingContext) - 1;
                $pendingContext[$k]['quantity']   = $m[1] + 0;
                $pendingContext[$k]['unit_price'] = round((float)$m[2], 2);
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

        // Pattern: Rona — "BARCODE QTY UNIT_PRICE EA", the description on the NEXT line and the total after it
        // ("3295249290177 2 32.49EA" / "EARMUFF GREY 28 DECIBEL" / "64.98P"). OCR reads EA as ER.
        // Before the Home Depot rule below, which took "2 32.49EA" as the item's name (2026-10-15).
        if (preg_match('/^(\d{8,15})\s+(\d{1,3})\s+(\d{1,6}\.\d{2})\s*E[AR4]\b\.?\s*$/i', $line, $m)) {
            $qty = max(1, (int)$m[2]);
            $unit = round((float)$m[3], 2);
            $desc = trim((string)($lines[$i + 1] ?? ''));
            $next = trim((string)($lines[$i + 2] ?? ''));
            $amount = number_format($qty * $unit, 2, '.', '');
            $isDesc = $desc !== '' && preg_match('/[A-Za-z]{3,}/', $desc) && !preg_match('/\d+\.\d{2}/', $desc);
            if ($isDesc) {
                $i++;                                                    // the description line is used
                if (preg_match('/^\$?(\d{1,6}\.\d{2})\s*[A-Z]{0,2}\s*$/', $next, $pm)) {
                    $amount = $pm[1];                                    // the printed line total wins
                    $i++;
                }
                $items[] = ['name' => $desc, 'amount' => $amount, 'quantity' => $qty, 'unit_price' => $unit, 'sku_raw' => $m[1]];
                $inItemZone = true;
                continue;
            }
            $pendingItems[] = '__sku_context__';
            $pendingContext[] = ['sku_raw' => $m[1], 'quantity' => $qty, 'unit_price' => $unit];
            $inItemZone = true;
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
                    $items[] = ['name' => 'Deposit', 'amount' => $pm[1], 'quantity' => 1, 'unit_price' => null,
                                'sku_raw' => null, 'is_adjustment' => true, 'is_deposit_adjustment' => true];
                    $i++;
                }
            }
            continue;
        }

        // Pattern: Markdown/discount line — net against the most recently
        // committed product line when one exists, so "Topsoil x4 $59.96 /
        // Discount: Landscapers (25%) -$14.99" becomes ONE net-$44.97 line
        // instead of a bogus standalone "Discount" item that silently
        // replaces the actual product. Falls back to a standalone adjustment
        // row (tagged is_adjustment) only when there's genuinely no
        // preceding product to attach to (e.g. an order-level coupon).
        if (preg_match('/^(?:RSN:|DISCOUNT|MARKDOWN|MKDN)/i', $line)) {
            if (preg_match('/(-?\d{1,6}\.\d{2})/', $line, $m)) {
                $amount = '-' . ltrim($m[1], '-');
                $items = netDiscountIntoLastItem($items, $amount);
                $inItemZone = true;
            }
            continue;
        }

        // Pattern: Standalone price line — assign to next pending item, or
        // (if nothing is queued) look back for a bare product-name line that
        // no more specific pattern captured — e.g. "Topsoil x4 / 30L Bag /
        // Original Price / $59.96" where the name has no barcode/SKU and no
        // price on its own line. Mirrors the backward-lookback already used
        // by the "Qty: Price:" pattern above: a name is only ever pulled in
        // once we have a confirmed price to attach it to, never queued
        // speculatively — so header/vendor/address lines earlier in the
        // receipt (which never reach a bare price line of their own) can't
        // get mistaken for a product.
        if (preg_match('/^-?\$?(\d{1,6}\.\d{2})\s*[A-Z]?\s*$/', $line, $pm)) {
            $amount = $pm[1];
            if (strpos($line, '-') === 0) {
                $items = netDiscountIntoLastItem($items, '-' . ltrim($amount, '-'));
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
            } else {
                $bareName = findBareItemNameBackward($lines, $i);
                if ($bareName !== null) {
                    $items[] = ['name' => $bareName, 'amount' => $amount, 'quantity' => 1, 'unit_price' => null, 'sku_raw' => null];
                }
            }
            $inItemZone = true;
            continue;
        }

        // Pattern: "NAME QTY UNIT TOTAL[flag]" on one line, single-spaced — Chevron's
        // "BISTRO 24OZ HOT BE 1 2.49 2.49P" (P = GST flag). Only with the tax flag glued to the
        // total and qty × unit = total: a tilted photo's reconstructed rows ("BISTRO 24OZ 2 0.07
        // 0.14", Chevron #424) pass the arithmetic by chance but carry no flag.
        if (preg_match('/^(.{3,}?)\s+(\d{1,3})\s+\$?(\d{1,6}\.\d{2})\s+\$?(\d{1,6}\.\d{2})[A-Z]{1,2}\s*$/', $line, $m)
            && preg_match('/[A-Za-z]{3,}/', $m[1]) && !isTaxOrSummaryLabel($m[1])
            && (int)$m[2] > 0 && abs((int)$m[2] * (float)$m[3] - (float)$m[4]) < 0.015) {
            $items[] = ['name' => trim($m[1]), 'amount' => $m[4], 'quantity' => (int)$m[2],
                        'unit_price' => round((float)$m[3], 2), 'sku_raw' => null];
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

        if (!empty($item['is_bottle_deposit'])) continue;

        if ($up !== null && $up > 0 && $qty == 1 && $total > $up * 1.01) {
            // unit_price known but qty=1 seems wrong — derive qty
            $item['quantity'] = round($total / $up, 3);
        } elseif ($qty > 1 && $up === null && $total > 0) {
            // qty known but no unit_price — derive it
            $item['unit_price'] = round($total / $qty, 2);
        }
    }
    unset($item);

    return dropTaxLineItems($items);
}


/**
 * A tax or total label with nothing but a rate / amount after it: "GST 5.0000 %", "PST 7%",
 * "GST/HST", "TAX", "SUBTOTAL", "TOTAL CAD $". Never an item name. Pure.
 */
function isTaxOrSummaryLabel(string $s): bool
{
    $s = trim($s);
    return (bool)preg_match(
        '/^(?:(?:GST|PST|HST|QST|RST)(?:\s*[\/&+]\s*(?:GST|PST|HST|QST|RST))?|(?:SALES\s+)?TAX(?:ES)?|SUB[\s\-]*TOTAL|TOTAL(?:\s+CAD)?|TOTAL\s+(?:FEE|FUEL|SALES?)|EXTRA\s+FEE|TOTAL\s+TENDERED|DEPOSIT(?=\s*:)|AMOUNT\s+DUE|BALANCE\s+DUE)\b\s*:?\s*\$?\s*[\d.,%\s]*$/i',
        $s
    );
}

/** Whether parser-shaped items total a target within the strict receipt fit tolerance. Pure. */
function receiptItemsAddUp(array $items, float $target, float $tol = 0.05): bool
{
    if (!$items) return false;
    $sum = 0.0;
    foreach ($items as $item) $sum += (float)($item['line_total'] ?? $item['amount'] ?? 0);
    return abs($sum - $target) <= $tol + 0.000000001;
}

/**
 * Read same-line item rows from a text-layer invoice table. Pure.
 *
 * @param list<string> $lines
 * @return array<int,array<string,mixed>>
 */
function extractInvoiceTableItems(array $lines): array
{
    $items = [];
    $inTable = false;
    $money = '(?:(?:CA|C|US)\$|\$)?\s*([0-9]{1,3}(?:,[0-9]{3})*|[0-9]+)\.([0-9]{2})';

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') continue;

        if (!$inTable) {
            $hasDescription = (bool)preg_match('/\b(?:DESCRIPTION|ITEMS?|ACTIVITY|PRODUCTS?)\b/i', $line);
            $hasQuantity = (bool)preg_match('/\b(?:QTY|QUANTITY)\b/i', $line);
            $hasAmount = (bool)preg_match('/\b(?:AMOUNT|TOTAL|AMT)\b/i', $line);
            if ($hasDescription && $hasQuantity && $hasAmount) $inTable = true;
            continue;
        }

        if (preg_match('/^(?:SUB\s*TOTAL|TOTAL|TAX|GST|PST)\b/i', $line)) break;

        $pattern = '/^(.+?)\s+(\d+(?:\.\d+)?)\s+' . $money
            . '(?:\s+\d+(?:\.\d+)?%)?\s+' . $money . '\s*$/i';
        if (!preg_match($pattern, $line, $m)) continue;

        $name = trim($m[1]);
        $quantity = (float)$m[2];
        $unitPrice = (float)str_replace(',', '', $m[3] . '.' . $m[4]);
        $amount = (float)str_replace(',', '', $m[5] . '.' . $m[6]);
        if ($name === '' || $quantity <= 0 || $unitPrice < 0 || $amount < 0) continue;
        if (abs($quantity * $unitPrice - $amount) > 0.02 + 0.000000001
            && !($quantity == 1.0 && abs($unitPrice - $amount) < 0.000000001)) {
            continue;
        }

        $items[] = [
            'name' => $name,
            'amount' => number_format($amount, 2, '.', ''),
            'line_total' => number_format($amount, 2, '.', ''),
            'quantity' => $quantity == (int)$quantity ? (int)$quantity : $quantity,
            'unit_price' => round($unitPrice, 2),
            'sku_raw' => null,
            'name_source' => 'invoice_table',
        ];
    }

    return $items;
}

/** Printed invoice subtotal, else printed/result total less GST and PST. Pure. */
function invoiceTableSubtotal(array $lines, array $parsed): ?float
{
    $printedTotal = null;
    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if (!preg_match('/^(SUB\s*TOTAL|TOTAL)\b.*?(?:(?:CA|C|US)\$|\$)?\s*([0-9]{1,3}(?:,[0-9]{3})*|[0-9]+)\.([0-9]{2})\s*$/i', $line, $m)) {
            continue;
        }
        $amount = (float)str_replace(',', '', $m[2] . '.' . $m[3]);
        if (preg_match('/^SUB\s*TOTAL\b/i', $line)) return $amount;
        if ($printedTotal === null) $printedTotal = $amount;
    }

    $total = $printedTotal;
    if ($total === null && isset($parsed['total']) && is_numeric($parsed['total'])) {
        $total = (float)$parsed['total'];
    }
    if ($total === null) return null;

    return $total - (float)($parsed['gst'] ?? 0) - (float)($parsed['pst'] ?? 0);
}

/**
 * Pair a phone reader's separated product-name and amount columns. Pure.
 * @param list<string> $lines
 * @return array<int,array<string,mixed>>
 */
function pairSeparatedColumns(array $lines): array
{
    $start = null;
    $end = count($lines);
    foreach ($lines as $i => $raw) {
        $line = trim($raw);
        if ($start === null && preg_match('/^PRODUCTS?$/i', $line)) { $start = $i + 1; continue; }
        if ($start !== null && preg_match('/^TOTAL(?:\s+CAD)?\s*\$?\s*$/i', $line)) { $end = $i; break; }
    }
    if ($start === null) return [];

    $names = [];
    $amounts = [];
    $pendingQty = null;
    $skipTaxAmount = false;
    for ($i = $start; $i < $end; $i++) {
        $line = trim($lines[$i]);
        if ($line === '' || isColumnHeading($line) || preg_match('/^(?:QTY|PRICE|AMOUNT)(?:\s+(?:QTY|PRICE|AMOUNT))*$/i', $line)) continue;
        if (preg_match('/^(?:GST|PST|HST|QST|RST)\b/i', $line)) { $pendingQty = null; $skipTaxAmount = true; continue; }
        if (preg_match('/^(\d+)\s+Deposit\s*\(\s*\$?\s*(\d{1,6}\.\d{2})\s*\)$/i', $line, $m)) {
            $names[] = ['name' => 'Deposit', 'quantity' => (int)$m[1], 'unit_price' => null, 'is_bottle_deposit' => true];
            $amounts[] = $m[2];
            continue;
        }
        if (preg_match('/^(\d{1,3})\s+\$?(\d{1,6}\.\d{2})$/', $line, $m)) {
            $pendingQty = ['quantity' => (int)$m[1], 'unit_price' => (float)$m[2]];
            continue;
        }
        if (preg_match('/^\$?(\d{1,6}\.\d{2})\s+\$?(\d{1,6}\.\d{2})\s*[A-Z]{0,2}$/', $line, $m)) {
            $amounts[] = $m[2];
            $pendingQty = null;
            continue;
        }
        if (preg_match('/^\$?(\d{1,6}\.\d{2})\s*[A-Z]{0,2}$/', $line, $m)) {
            if ($skipTaxAmount) { $skipTaxAmount = false; continue; }
            $amounts[] = $m[1];
            if ($pendingQty !== null && isset($names[count($amounts) - 1])) $names[count($amounts) - 1] = array_replace($names[count($amounts) - 1], $pendingQty);
            $pendingQty = null;
            continue;
        }
        if (preg_match('/^\d+\s+\$?(\d{1,6}\.\d{2})\s+\$?(\d{1,6}\.\d{2})\s*[A-Z]{0,2}$/', $line, $m)) {
            $amounts[] = $m[2];
            continue;
        }
        if (preg_match('/^@\s*\$?\s*\d{1,6}\.\d{2}/', $line)
            || preg_match('/^\d+\s+[A-Za-z]/', $line)) continue;
        if (preg_match('/[A-Za-z]{3,}/', $line) && !isTaxOrSummaryLabel($line)
            && !isSeparatedColumnsNonItemLine($line)) {
            $names[] = ['name' => $line, 'quantity' => 1, 'unit_price' => null];
        }
    }
    if (count($amounts) === count($names) + 1) {
        $largest = array_search(max(array_map('floatval', $amounts)), array_map('floatval', $amounts), true);
        if ($largest !== false) array_splice($amounts, (int)$largest, 1);
    }
    if (count($names) !== count($amounts) || !$names) return [];
    $items = [];
    foreach ($names as $i => $name) {
        $items[] = ['name' => $name['name'], 'amount' => $amounts[$i], 'line_total' => $amounts[$i],
                    'quantity' => $name['quantity'], 'unit_price' => $name['unit_price'], 'sku_raw' => null,
                    'name_source' => 'columns', 'is_bottle_deposit' => !empty($name['is_bottle_deposit'])];
    }
    return $items;
}

/** Store, payment, and footer metadata cannot be product names in a separated OCR column. Pure. */
function isSeparatedColumnsNonItemLine(string $line): bool
{
    $line = trim($line);
    return (bool)preg_match(
        '/^(?:'
        . '(?:STORE|LOCATION)\s*(?:#|NO\.?|NUMBER|ID)?\s*[A-Z0-9-]*'
        . '|(?:VISA|MASTERCARD|MASTER\s*CARD|AMEX|AMERICAN\s+EXPRESS|INTERAC|DEBIT|CREDIT|CASH)'
        . '|APPROVED|DECLINED|AUTH(?:ORI[ZS]ATION)?(?:\s*(?:#|NO\.?|CODE))?\s*[A-Z0-9-]*'
        . '|TRANSACTION\s+(?:RECORD|APPROVED)|CUSTOMER\s+COPY|MERCHANT\s+COPY'
        . '|THANK(?:\s+YOU)?(?:\s+FOR\s+(?:SHOPPING|YOUR\s+PURCHASE))?[!.]*'
        . ')$/i',
        $line
    );
}

/** Printed total less separately printed GST/PST in a separated-column receipt. Pure. */
function separatedColumnsSubtotal(array $lines): ?float
{
    $tax = 0.0;
    $hasTax = false;
    $total = null;
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        $line = trim($lines[$i]);
        if (preg_match('/^(?:GST|PST|HST|QST|RST)\b/i', $line)
            && preg_match('/^\$?(\d{1,6}\.\d{2})$/', trim((string)($lines[$i + 1] ?? '')), $m)) {
            $tax += (float)$m[1]; $hasTax = true; $i++; continue;
        }
        if ($hasTax && preg_match('/^\$?(\d{1,6}\.\d{2})$/', $line, $m)) $total = (float)$m[1];
    }
    return $hasTax && $total !== null && $total > $tax ? round($total - $tax, 2) : null;
}

/**
 * A receipt's column headings read as an item: "QTY PRICE AMOUNT", "DESCRIPTION QTY AMT",
 * "ITEM EACH TOTAL". Only heading words, nothing else. Chevron #114 (2026-10-15). Pure.
 */
function isColumnHeading(string $s): bool
{
    // Three or more heading words and nothing else, one of them a quantity / amount column — so a real
    // two-word item like "UNIT PRICE" or "ITEM TOTAL" is never dropped (Codex review 2026-10-15).
    $words = preg_split('/[\s\/|:.]+/', strtoupper(trim($s)), -1, PREG_SPLIT_NO_EMPTY);
    // A lone column heading read as an item name ("PRODUCT" carrying the first price, Chevron #163, replay 2026-11-06).
    // Not AMOUNT: a taxi receipt's fare line is "Amount 21.75".
    if (count($words) === 1 && in_array($words[0], ['PRODUCT', 'PRODUCTS', 'DESCRIPTION', 'QTY', 'QUANTITY'], true)) return true;
    if (count($words) < 3) return false;
    $heading = ['QTY', 'QUANTITY', 'PRICE', 'AMOUNT', 'AMT', 'DESCRIPTION', 'DESC', 'ITEM', 'ITEMS', 'EACH', 'EA', 'UNIT', 'EXT', 'EXTENDED', 'TOTAL', 'SKU', 'PRODUCT', '$'];
    foreach ($words as $w) if (!in_array($w, $heading, true)) return false;
    return (bool)array_intersect($words, ['QTY', 'QUANTITY', 'AMOUNT', 'AMT']);
}

/** Items without tax / total lines or column headings (whatever pass produced them). Pure. */
function dropTaxLineItems(array $items): array
{
    return array_values(array_filter($items, fn($it) => !empty($it['is_bottle_deposit'])
        || !empty($it['is_deposit_adjustment']) || (!isTaxOrSummaryLabel((string)($it['name'] ?? ''))
        && !isTaxOrSummaryLabel((string)($it['ocr_name'] ?? ''))
        && !isColumnHeading((string)(($it['name'] ?? '') !== '' ? $it['name'] : ($it['ocr_name'] ?? ''))))));
}

/**
 * Amounts that cannot be true are a parse failure, not a value: a negative or zero subtotal,
 * GST/PST at or above the total, or more tax than any Canadian rate allows (15% HST is 13% of the total; 14% + rounding). Those are
 * dropped and the usual fallback runs (subtotal = total − tax, else GST estimated at 5%).
 * Chevron #355 (2026-10-13): GST read as 5.00 on a $2.61 receipt → subtotal −2.39. Pure.
 */
function sanitizeReceiptAmounts(array $r): array
{
    $total = isset($r['total']) && is_numeric($r['total']) ? (float)$r['total'] : null;
    if ($total === null || $total <= 0) return $r;
    $bad = false;
    foreach (['gst', 'pst'] as $f) {
        if (isset($r[$f]) && is_numeric($r[$f]) && ((float)$r[$f] < 0 || (float)$r[$f] > $total * 0.14 + 0.02)) {
            $r[$f] = null; $bad = true;
        }
    }
    $tax = (float)($r['gst'] ?? 0) + (float)($r['pst'] ?? 0);
    if ($tax >= $total) { $r['gst'] = null; $r['pst'] = null; $bad = true; $tax = 0.0; }
    if (isset($r['subtotal']) && is_numeric($r['subtotal'])) {
        $sub = (float)$r['subtotal'];
        $fits = $r['gst'] === null || abs($sub + $tax - $total) <= max(0.05, $total * 0.01) || $sub + $tax < $total;
        if ($sub <= 0 || $sub > $total + 0.005 || !$fits) { $r['subtotal'] = null; $bad = true; }
    }
    if (!$bad) return $r;
    $r['amounts_repaired'] = true;
    unset($r['gst_estimated']);
    if ($r['subtotal'] === null && $r['gst'] !== null) {
        $sub = round($total - $tax, 2);
        $r['subtotal'] = $sub > 0 ? number_format($sub, 2, '.', '') : null;
        if ($r['subtotal'] !== null) $r['subtotal_derived'] = true;
    }
    if ($r['gst'] === null) {
        $r['gst'] = number_format($total / 1.05 * 0.05, 2, '.', '');
        $r['gst_estimated'] = true;
        $r['gst_source'] = 'estimated';
        if ($r['subtotal'] === null) {
            $r['subtotal'] = number_format($total - (float)$r['gst'] - (float)($r['pst'] ?? 0), 2, '.', '');
            $r['subtotal_derived'] = true;
        }
    }
    return $r;
}


/**
 * Net a discount/markdown amount (always negative) into the most recently
 * committed line item, preserving the pre-discount price in
 * original_unit_price for display. Falls back to a standalone adjustment
 * row when there's no eligible preceding item (empty list, a non-positive
 * last item, or the last item is itself already an adjustment row — you
 * don't discount a discount).
 *
 * @param array  $items    Items committed so far
 * @param string $discount Negative decimal string, e.g. '-14.99'
 * @return array Updated items array
 */
function netDiscountIntoLastItem(array $items, string $discount): array
{
    $discount = -abs((float)$discount);
    $lastIdx = count($items) - 1;

    if ($lastIdx >= 0 && (float)$items[$lastIdx]['amount'] > 0 && empty($items[$lastIdx]['is_adjustment'])) {
        $prevTotal = (float)$items[$lastIdx]['amount'];
        $qty = (float)($items[$lastIdx]['quantity'] ?? 1);
        if (!isset($items[$lastIdx]['original_unit_price'])) {
            $items[$lastIdx]['original_unit_price'] = $items[$lastIdx]['unit_price']
                ?? ($qty > 0 ? round($prevTotal / $qty, 2) : null);
        }
        $newTotal = round($prevTotal + $discount, 2);
        $items[$lastIdx]['amount'] = number_format($newTotal, 2, '.', '');
        if ($items[$lastIdx]['unit_price'] !== null && $qty > 0) {
            $items[$lastIdx]['unit_price'] = round($newTotal / $qty, 2);
        }
    } else {
        $items[] = [
            'name' => 'Discount', 'amount' => number_format($discount, 2, '.', ''),
            'quantity' => 1, 'unit_price' => null, 'sku_raw' => null, 'is_adjustment' => true,
        ];
    }

    return $items;
}


/**
 * Look backward (up to 4 lines) from a confirmed price line for a plausible
 * bare product-name line — one with no barcode/SKU prefix and no price on
 * its own line, so nothing else in extractLineItems() ever captured it.
 * Only ever called once a price has actually been found (see the "Standalone
 * price line" pattern), never speculatively — so vendor/address/header text
 * earlier in the receipt (which never sits directly above a bare price line)
 * can't get mistaken for a product name.
 *
 * Skips (without stopping the search) common non-name lines that can
 * legitimately sit between a name and its price: attribute lines
 * ("Clr: Red"), generic price labels ("Original Price"), city/province
 * lines, Canadian postal codes, and website domains. Returns the single
 * closest qualifying line — a name split across multiple lines (e.g.
 * "Topsoil x4" + "30L Bag") only recovers the line closest to the price,
 * which is still a real improvement over the item being dropped entirely.
 *
 * @param array $lines Full OCR line array
 * @param int   $priceLineIdx Index of the line containing the confirmed price
 * @return string|null The recovered name, or null if nothing qualified
 */
function findBareItemNameBackward(array $lines, int $priceLineIdx): ?string
{
    for ($j = $priceLineIdx - 1; $j >= max(0, $priceLineIdx - 4); $j--) {
        $prevLine = trim($lines[$j]);
        if ($prevLine === '') continue;

        // Skip attribute/continuation and generic price-label lines — keep
        // searching further back rather than treating them as a boundary.
        if (preg_match('/^(?:Clr|Colour|Color|Sz|Size|Style)\s*:/i', $prevLine)) continue;
        if (preg_match('/^(?:Original|List|Regular|Retail|Reg\.?)\s*Price\s*:?\s*$/i', $prevLine)) continue;
        if (preg_match('/^Price\s*:?\s*$/i', $prevLine)) continue;
        if (preg_match('/^\d+\.?\d*\s*%?\s*$/', $prevLine)) continue; // a bare number/rate, not a name
        // A tax / total label directly above the price owns it — never an item (Chevron #355:
        // "GST 5.0000 %" then "0.12" became a line item).
        if (isTaxOrSummaryLabel($prevLine)) return null;

        // Skip known receipt-noise shapes — also just keep searching further back.
        if (preg_match('/^(?:Receipt|Authorization|Auth|Order|Invoice|Ref|Transaction)\s*#?\s*:/i', $prevLine)) continue;
        if (preg_match('/^[A-Za-z][A-Za-z\s]+,\s*[A-Z]{2}\s*$/', $prevLine)) continue; // "VANCOUVER, BC"
        if (preg_match('/^[A-Za-z]\d[A-Za-z]\s?\d[A-Za-z]\d\s*$/', $prevLine)) continue; // Canadian postal code
        if (preg_match('/\.(com|ca|net|org)\b/i', $prevLine)) continue; // website domain
        if (preg_match('/^\d+\s+(st|ave|blvd|rd|dr|way|street|avenue)\b/i', $prevLine)) continue; // street address
        if (preg_match('/^\(\d{3}\)\s*\d{3}[\-\.]\d{4}/', $prevLine)) continue; // phone number

        // A plausible name: 3+ alpha chars, not a SALE/Date/Cashier marker.
        if (strlen($prevLine) >= 3 && preg_match('/[A-Za-z]{3,}/', $prevLine)
            && !preg_match('/^(?:SALE|Date:|Cashier:)/i', $prevLine)) {
            return $prevLine;
        }
    }

    return null;
}


/**
 * Time of day the purchase was rung up, as 24-hour "HH:MM", or null.
 *
 * Receipts print it next to the date ("10/04/26 14:32", "OCT 04 2026 2:32 PM") or on
 * its own line. Store-hours lines ("HOURS 7:00-21:00", "OPEN 8AM - 9PM") are skipped:
 * a time inside a range is never the purchase time. A time on a line that also
 * carries a date wins over a lone time.
 */
function extractPurchaseTime(array $lines): ?string
{
    $timeRe = '/(?<![\d:])([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\s*([AaPp]\.?\s?[Mm]\.?)?(?![\d:])/';
    $dateRe = '/\b(\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{1,4}|(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*\.?\s+\d{1,2})/i';
    $lone = null;

    foreach ($lines as $line) {
        $line = (string)$line;
        if (preg_match('/\bHOURS?\b|\bOPEN\b|\bCLOSE[DS]?\b/i', $line)) continue;
        if (!preg_match_all($timeRe, $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;

        foreach ($m as $hit) {
            $start = $hit[0][1];
            $end   = $start + strlen($hit[0][0]);
            // Part of a range ("7:00-21:00", "8:00 to 5:00")? Not a purchase time.
            $before = substr($line, max(0, $start - 4), min(4, $start));
            $after  = substr($line, $end, 5);
            if (preg_match('/[\-–~]\s*$|\bto\s*$/i', $before) || preg_match('/^\s*([\-–~]|to\b)/i', $after)) continue;

            $h = (int)$hit[1][0];
            $min = $hit[2][0];
            $ampm = isset($hit[3]) && $hit[3][1] >= 0 ? strtoupper($hit[3][0][0]) : '';
            if ($ampm !== '' && ($h < 1 || $h > 12)) continue;
            if ($ampm === 'P' && $h < 12) $h += 12;
            if ($ampm === 'A' && $h === 12) $h = 0;
            $hhmm = sprintf('%02d:%s', $h, $min);

            if (preg_match($dateRe, $line)) {
                return $hhmm;
            }
            $lone = $lone ?? $hhmm;
        }
    }
    return $lone;
}

/**
 * Phone numbers printed on a receipt, as 10-digit NANP strings (leading 1 dropped).
 * Receipts print "(604) 555-1234", "604-555-1234", "604.555.1234", "1-800-…"; the
 * vendor match compares digits, so the separators have to go first.
 *
 * @return string[] Unique, in order of appearance
 */
function extractPhoneNumbers(string $ocrText): array
{
    $found = [];
    if (preg_match_all('/(?<![\d$.,])(?:\+?1[\s.\-]?)?\(?([2-9]\d{2})\)?[\s.\-]?(\d{3})[\s.\-]?(\d{4})(?![\d.,]\d)/', $ocrText, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $found[$hit[1] . $hit[2] . $hit[3]] = true;
        }
    }
    // array_keys() turns numeric-string keys into ints; callers compare strings.
    return array_map('strval', array_keys($found));
}

/**
 * Store contact details printed in a receipt header — used to back-fill a vendor's
 * phone/website/location the first time it's seen, which is what makes the phone
 * match (the strongest vendor signal) work for vendors nobody typed in by hand.
 * Called from intake since the 2026-09 parity pass but never defined until 2026-10-05,
 * so every call threw into a silent catch.
 *
 * @return array{phone: ?string, website: ?string, address: ?string, city: ?string}
 */
function extractVendorLocationFromOcr(string $ocrText): array
{
    $out = ['phone' => null, 'website' => null, 'address' => null, 'city' => null];
    if (trim($ocrText) === '') {
        return $out;
    }

    $phones = extractPhoneNumbers($ocrText);
    if ($phones) {
        $p = $phones[0];
        $out['phone'] = sprintf('(%s) %s-%s', substr($p, 0, 3), substr($p, 3, 3), substr($p, 6));
    }

    if (preg_match('/(?<![@\w.\-])((?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9\-]{1,62}\.(?:ca|com|net|org|shop))\b/i', $ocrText, $w)) {
        $out['website'] = strtolower(preg_replace('#^(https?://)?(www\.)?#i', '', $w[1]));
    }

    // Header only: store details sit in the first lines, not among the items.
    $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', $ocrText)), 'strlen')), 0, 12);
    $suffix = 'ST|STREET|AVE|AVENUE|RD|ROAD|BLVD|BOULEVARD|DR|DRIVE|HWY|HIGHWAY|WAY|CRES|CRESCENT|PL|PLACE|LANE|LN|CT|COURT|PKWY|MALL|SQ|SQUARE';
    foreach ($lines as $idx => $line) {
        if ($out['address'] === null && preg_match('/^\d{1,6}[A-Z]?(?:[\s\-]+\d+)?\s+[A-Za-z0-9 .\'\-]{2,40}\b(?:' . $suffix . ')\b\.?(?:\s+[NSEW]{1,2})?/i', $line, $a)) {
            $out['address'] = trim($a[0]);
            // City is usually on the same line after a comma, or the next line ("VANCOUVER, BC V6K 1A1").
            $cityLine = preg_match('/,\s*([A-Za-z .\'\-]{3,30})\s*,?\s*BC\b/i', $line, $c) ? $c[1] : ($lines[$idx + 1] ?? '');
            if ($out['city'] === null && preg_match('/^([A-Za-z .\'\-]{3,30}?)\s*,?\s*(?:BC|B\.C\.|BRITISH COLUMBIA)\b/i', trim($cityLine), $c2)) {
                $out['city'] = ucwords(strtolower(trim($c2[1])));
            } elseif ($out['city'] === null && !empty($c[1] ?? null)) {
                $out['city'] = ucwords(strtolower(trim($c[1])));
            }
        }
        if ($out['city'] === null && preg_match('/^([A-Za-z .\'\-]{3,30}?)\s*,?\s*(?:BC|B\.C\.)\s+[A-Z]\d[A-Z]\s?\d[A-Z]\d\b/i', $line, $c3)) {
            $out['city'] = ucwords(strtolower(trim($c3[1])));
        }
    }

    return $out;
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
            if (preg_match('/^(?:CAD|CDN|C)?\s*\$?\s*(\d{1,6}[.,]\d{2})\s*$/i', $nextLine, $m)) {
                return str_replace(',', '.', $m[1]);
            }
            // Handle three-line: "TOTAL\nCAD $\n202.46" (including common OCR variants).
            if (preg_match('/^(?:\$|CAD|CAD\s*\$|CAD\s+P|CDN\s*\$)$/i', $nextLine) && $i + 2 < $lineCount) {
                $amountLine = trim($lines[$i + 2]);
                if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $amountLine, $m)) {
                    return str_replace(',', '.', $m[1]);
                }
            }
        }
    }

    // Some column-oriented receipts print the total amount immediately above its label.
    // Accept it only when the payment line below independently confirms the same amount.
    for ($i = 0; $i < $lineCount; $i++) {
        if (!preg_match('/^TOTAL(?:\s+CAD)?(?:\s*\$)?\s*$/i', trim($lines[$i]))) {
            continue;
        }
        $paymentAmount = extractPaymentLineAmount(array_slice($lines, $i + 1));
        if ($paymentAmount !== null) {
            for ($j = $i - 1; $j >= 0; $j--) {
                $previousLine = trim($lines[$j]);
                if ($previousLine === '') {
                    continue;
                }
                if (preg_match('/^\$?\s*(\d{1,6}[.,]\d{2})\s*$/', $previousLine, $m)) {
                    $candidate = str_replace(',', '.', $m[1]);
                    if ((float)$candidate === (float)$paymentAmount) {
                        return $candidate;
                    }
                }
                break;
            }
        }
    }

    return null;
}

/**
 * The total the payment line confirms, when the split-line rules read a different amount that sits among the
 * bare amounts printed just after a TOTAL label ("PST 7.0000 %" / "TOTAL" / "CAD $" / "0.53" / "11.53" read
 * 0.53). Only ever moves to an amount printed right there, so a stray payment line can't override a real total.
 * Pure. Replay 2026-11-04 (Chevron #331, #334).
 */
function preferConfirmedPaymentTotal(array $lines, string $total): string
{
    $paid = extractPaymentLineAmount($lines);
    if ($paid === null || abs((float)$paid - (float)$total) < 0.005) return $total;
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (!preg_match('/^TOTAL,?(?:\s+CAD)?(?:\s*\$)?\s*$/i', trim($lines[$i]))) continue;
        $seenTotal = false; $seenPaid = false;
        for ($j = $i + 1; $j < min($n, $i + 6); $j++) {
            $l = trim($lines[$j]);
            if (preg_match('/^(?:\$|CAD|CAD\s*\$|CAD\s+P|CDN\s*\$)$/i', $l)) continue;
            if (!preg_match('/^\$?\s*(\d{1,6}[.,]\d{2})\s*$/', $l, $m)) break;
            $v = (float)str_replace(',', '.', $m[1]);
            if (abs($v - (float)$total) < 0.005) $seenTotal = true;
            if (abs($v - (float)$paid) < 0.005) $seenPaid = true;
        }
        if ($seenTotal && $seenPaid) return number_format((float)$paid, 2, '.', '');
    }
    return $total;
}

/** A tax legend is printed ("See GST #", "GSTV", "P - GST", "PG - GST, PST") but no GST line and no rate (%) line at all. Pure. */
function receiptItemisesTaxWithoutGstLine(array $lines): bool
{
    $legend = false;
    $codes = [];
    foreach ($lines as $line) {
        $l = trim($line);
        if (preg_match('/^(?:GST|HST)(?:\/HST)?\s*(?:@\s*)?\d+(?:[.,]\d+)?\s*%/i', $l)
            || preg_match('/^(?:GST|HST)\b[^A-Za-z#]*\d{1,6}[.,]\d{2}\s*$/i', $l)) {
            return false;                       // a GST line is printed: leave GST to the normal rules
        }
        if (strpos($l, '%') !== false) return false;   // a rate line OCR garbled ("9:8888 % 8:18") is still a tax line
        if (preg_match('/See\s+GST\s*#|^GSTV\b/i', $l)) $legend = true;
        if (preg_match('/^([A-Z]{1,2})\s*-\s*GST\b/', $l, $m)) { $legend = true; $codes[] = $m[1]; }
    }
    if (!$legend) return false;
    // An item carrying a code the legend maps to GST ("2.49P" with "P - GST") means GST was charged.
    foreach ($codes as $code) {
        foreach ($lines as $line) {
            if (preg_match('/\d[.,]\d{2}\s*' . preg_quote($code, '/') . '\s*$/', trim($line))) return false;
        }
    }
    return true;
}

/**
 * Pure: does the receipt name no tax at all — no GST / HST / PST / RST / TPS / TVQ / TVH / VAT / IVA / TAX(E) word anywhere?
 * Then nothing was charged and the 5% guess must not run. A GST number or "GST incl." still names GST.
 */
function receiptPrintsNoTax(array $lines): bool
{
    foreach ($lines as $line) {
        if (preg_match('/\b(?:G\.?S\.?T|H\.?S\.?T|P\.?S\.?T|RST|TPS|TVQ|TVH|VAT|IVA|TAX(?:ES?)?)\b/i', (string)$line)) return false;
    }
    return true;
}

/**
 * Extract a confirmed purchase amount from a payment line. Pure.
 * Deliberately excludes cash, tendered, change, and balance lines.
 */
function extractPaymentLineAmount(array $lines): ?string
{
    foreach ($lines as $line) {
        if (preg_match(
            '/^(?:(?:DEBIT|CREDIT|VISA|MASTERCARD|INTERAC)\s+PURCHASE|AMOUNT\s+PAID)\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})\s*$/i',
            trim($line),
            $m
        )) {
            if (receiptHasOtherTender($lines, trim($line))) return null;   // split tender: one card is not the total
            return str_replace(',', '.', $m[1]);
        }
    }

    return null;
}

/** Another tender (gift card, cash, second card, points, cheque) is printed with an amount besides $paymentLine. Pure. */
function receiptHasOtherTender(array $lines, string $paymentLine): bool
{
    foreach ($lines as $line) {
        $l = trim($line);
        if ($l === $paymentLine) continue;
        if (preg_match('/\b(?:GIFT\s*CARD|GIFT\s*CERT|STORE\s*CREDIT|POINTS\s+REDEEMED|REWARDS?\s+REDEEMED|CASH|CHEQUE|CHECK|COUPON\s+TENDER|(?:DEBIT|CREDIT|VISA|MASTERCARD|AMEX|INTERAC)\s+PURCHASE)\b[^\n]*\d{1,6}[.,]\d{2}/i', $l)
            && !preg_match('/\bCHANGE\b|\bCASH\s*BACK\b/i', $l)) {
            return true;
        }
    }
    return false;
}

/**
 * Extract GST amount from receipt text.
 * Matches GST specifically, plus generic "TAX" labels (assumed GST for BC).
 * Handles both same-line ("GST $3.45") and split-line ("GST/HST\n2.88") formats.
 */
function extractGST(string $text): ?string
{
    $gst = extractLabeledTax($text, 'GST(?:\/HST)?', true);
    if ($gst !== null) {
        return $gst;
    }

    // Generic "TAX" label fallback (assumed GST for BC) — only tried when no
    // GST/HST-labeled line was found, since "TAX" is a much broader word and
    // shouldn't preempt a real GST/HST match.
    if (preg_match_all('/(?<!\w)TAX[^\n$]*\$[^\S\n]*(\d{1,6}[.,]\d{2})/i', $text, $matches)
        || preg_match_all('/(?<!\w)TAX[^\n%$]*?(\d{1,6}[.,]\d{2})(?!\d*%)/i', $text, $matches)
    ) {
        $total = 0;
        foreach ($matches[1] as $amount) {
            $total += (float)str_replace(',', '.', $amount);
        }
        return number_format($total, 2, '.', '');
    }

    // Split-line "TAX\n$X.XX" fallback
    $lines = preg_split('/\r?\n/', $text);
    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount - 1; $i++) {
        if (preg_match('/^TAX\s*:?\s*$/i', trim($lines[$i]))) {
            $nextLine = trim($lines[$i + 1]);
            if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $nextLine, $m)) {
                return str_replace(',', '.', $m[1]);
            }
        }
    }

    return null;
}

/**
 * Extract PST amount from receipt text (also matches its provincial alias RST).
 * Same matching strategy as extractGST() — see extractLabeledTax().
 */
function extractPST(string $text): ?string
{
    return extractLabeledTax($text, '(?:PST|RST)', false);
}

/**
 * Shared same-line/split-line tax extraction for a labeled amount (GST/PST/etc).
 *
 * Same-line patterns use [^\n$] / [^\S\n] (never \n) so a match can't cross
 * into the next receipt line. The label-to-amount gap tolerates arbitrary
 * filler text — "Sales Tax", a parenthesized rate like "(5%)", a colon —
 * by matching "any non-$, non-newline character" up to the first literal $
 * on the line, e.g. "GST Sales Tax (5%) $2.25" or "PST Sales Tax (7%) $3.15",
 * not just the terser "GST $3.45" / "GST 5%: $3.45" the older patterns assumed.
 *
 * @param string $labelPattern Regex fragment for the label (no delimiters/flags), e.g. 'GST(?:\/HST)?'
 * @param bool   $withIncludedVariant Also match "<LABEL> INCLUDED $X.XX" (fuel receipts; GST/HST only)
 */
function extractLabeledTax(string $text, string $labelPattern, bool $withIncludedVariant): ?string
{
    // Resolve a run of stacked labels as a group before the single-label split-line
    // fallback can pair the second label with the first label's amount.
    $stacked = extractStackedTaxAmounts(preg_split('/\r?\n/', $text));
    $stackedKey = strpos($labelPattern, 'GST') !== false ? 'gst' : 'pst';
    if ($stacked[$stackedKey] !== null) {
        return $stacked[$stackedKey];
    }

    $patterns = [
        // Same-line, tolerant of filler between the label and the $ amount.
        '/' . $labelPattern . '[^\n$]*\$[^\S\n]*(\d{1,6}[.,]\d{2})/i',
        // Same-line, no $ sign — filler tolerated, but must not swallow a rate like "5%".
        // An optional printed rate is skipped: "GST 5.0000 % 0.42" (Chevron, 2026-10-13) used to
        // read 5.00 — "5.00" + "00 %" slipped past a lookahead that allowed no space before "%".
        '/' . $labelPattern . '(?:[^\n%$]*?\d+(?:[.,]\d+)?[^\S\n]*%)?[^\n%$]*?(?<![\d.,])(\d{1,6}[.,]\d{2})(?!\d*[^\S\n]*%)(?!\d)/i',
    ];
    if ($withIncludedVariant) {
        // Gas station / fuel receipts: "GST INCLUDED $ 0.95", "HST INCL $0.95"
        $patterns[] = '/' . $labelPattern . '[^\S\n]*(?:INCLUDED|INCL\.?)[^\S\n]*:?[^\S\n]*\$?[^\S\n]*(\d{1,6}[.,]\d{2})/i';
    }

    $totalTax = 0;
    $found = false;

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[1] as $amount) {
                $totalTax += (float)str_replace(',', '.', $amount);
                $found = true;
            }
            break;
        }
    }

    // Split-line fallback: "GST/HST\n2.88", "PST\n$3.15", or "GST 5%\n$\n9.04" —
    // the label (plus optional "Sales Tax" wording / parenthesized rate) is
    // alone on its own line, and the amount is on the next line (or two).
    if (!$found) {
        $lines = preg_split('/\r?\n/', $text);
        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount - 1; $i++) {
            $line = trim($lines[$i]);
            // A spaced rate is allowed: "GST 5.0000 %" then "0.12" on the next line (Chevron #355,
            // 2026-10-13 — the space before "%" made the label line miss, and the column fallback
            // then read GST 2.61 / subtotal 0.00).
            if (preg_match('/^' . $labelPattern . '(?:\s*Sales\s*Tax)?\s*\(?[\d.]*\s*%?\)?\s*:?\s*$/i', $line)) {
                $nextLine = trim($lines[$i + 1]);
                if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $nextLine, $m)) {
                    $totalTax += (float)str_replace(',', '.', $m[1]);
                    $found = true;
                }
                // Handle three-line: "GST 5%\n$\n9.04"
                elseif ($nextLine === '$' && $i + 2 < $lineCount) {
                    $amountLine = trim($lines[$i + 2]);
                    if (preg_match('/^\$?(\d{1,6}[.,]\d{2})\s*$/', $amountLine, $m)) {
                        $totalTax += (float)str_replace(',', '.', $m[1]);
                        $found = true;
                    }
                }
            }
        }
    }

    return $found ? number_format($totalTax, 2, '.', '') : null;
}

/**
 * Pair two or more consecutive bare tax labels with the bare amounts that follow.
 * Returns no pairing unless there are at least as many consecutive amounts as labels. Pure.
 */
function extractStackedTaxAmounts(array $lines): array
{
    $result = ['gst' => null, 'pst' => null];
    $lineCount = count($lines);
    $labelRegex = '/^(GST(?:\s*\/\s*HST)?|PST|RST)(?:\s+[\d.]+\s*%)?\s*:?\s*$/i';
    $amountRegex = '/^\$?\s*(\d{1,6}[.,]\d{2})\s*$/';

    for ($i = 0; $i < $lineCount; $i++) {
        $labels = [];
        $j = $i;
        while ($j < $lineCount && preg_match($labelRegex, trim($lines[$j]), $labelMatch)) {
            $labels[] = stripos($labelMatch[1], 'GST') === 0 ? 'gst' : 'pst';
            $j++;
        }
        if (count($labels) < 2) {
            continue;
        }

        $amounts = [];
        $k = $j;
        while ($k < $lineCount && preg_match($amountRegex, trim($lines[$k]), $amountMatch)) {
            $amounts[] = str_replace(',', '.', $amountMatch[1]);
            $k++;
        }
        if (count($amounts) < count($labels)) {
            $i = $j - 1;
            continue;
        }

        foreach ($labels as $idx => $type) {
            if ($result[$type] === null) {
                $result[$type] = $amounts[$idx];
            }
        }
        return $result;
    }

    return $result;
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
 * Day/month order proven by the receipt's own numeric dates: a first number 13-31 means
 * day first, a second number 13-31 means month first (a return-by date often gives it away).
 *
 * @return string|null 'dmy' | 'mdy' when every proof agrees, null when none or they conflict
 */
function receiptDateOrder(string $text): ?string
{
    // Only real dates vote (code review 2026-11-06): a product code like "10-20-10" or "16-24-12" is not one —
    // never inside a longer number chain, the year plausible for a receipt, and a valid calendar date that way round.
    $votes = [];
    $maxYear = (int)date('Y') + 1;
    if (preg_match_all('/(?<![\d\/\-.])(\d{1,2})([\/\-.])(\d{1,2})\2(\d{4}|\d{2})(?![\d\/\-]|\.\d)/', $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $a = (int)$m[1]; $b = (int)$m[3]; $y = (int)$m[4]; if (strlen($m[4]) === 2) $y += 2000;
            if ($y < 2015 || $y > $maxYear) continue;
            if ($a >= 13 && $b <= 12 && checkdate($b, $a, $y)) $votes['dmy'] = true;
            if ($b >= 13 && $a <= 12 && checkdate($a, $b, $y)) $votes['mdy'] = true;
        }
    }
    return count($votes) === 1 ? key($votes) : null;
}

/**
 * The numeric a/b/yyyy (or a/b/yy) date extractDate() would pick, when both a and b are
 * 1-12 and differ — i.e. when its day/month order is a guess. Null when the date is
 * unambiguous, YYYY-MM-DD, or not numeric.
 *
 * @return int[]|null [a, b, year]
 */
function receiptAmbiguousDate(string $text): ?array
{
    foreach (['/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})(?!\d)/'] as $i => $re) {
        if (!preg_match($re, $text, $m)) continue;
        if ($i === 0) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
            continue;
        }
        $a = (int)$m[1];
        $b = (int)$m[2];
        $y = $i === 2 ? (int)$m[3] + 2000 : (int)$m[3];
        if ($a >= 1 && $a <= 12 && $b >= 1 && $b <= 12 && $a !== $b) return [$a, $b, $y];
        if (checkdate($b, $a, $y) || checkdate($a, $b, $y)) return null;
    }
    return null;
}

/**
 * Extract date from receipt text.
 * Supports: MM/DD/YYYY, DD/MM/YYYY, YYYY-MM-DD, MMM DD YYYY, etc.
 * An ambiguous a/b/yy date (both 1-12) is read by the order the receipt itself proves,
 * else the vendor's learned habit ($order: 'dmy'|'mdy'), else month first.
 *
 * @return string|null Date in YYYY-MM-DD format
 */
function extractDate(string $text, ?string $order = null): ?string
{
    $order = receiptDateOrder($text) ?? (in_array($order, ['dmy', 'mdy'], true) ? $order : null);
    $patterns = [
        // YYYY-MM-DD
        '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/' => function ($m) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        },
        // MM/DD/YYYY or DD/MM/YYYY
        '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/' => function ($m) use ($order) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $y = (int)$m[3];
            // If first number > 12, it's DD/MM/YYYY
            if ($a > 12) {
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }
            // Both 1-12: day first only when proven; default MM/DD/YYYY (North American convention)
            if ($order === 'dmy' && $b <= 12) {
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }
            return sprintf('%04d-%02d-%02d', $y, $a, $b);
        },
        // MM/DD/YY or DD/MM/YY
        '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})(?!\d)/' => function ($m) use ($order) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $y = (int)$m[3] + 2000;
            if ($a > 12) {
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }
            if ($order === 'dmy' && $b <= 12) {
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
    // "Visa Debit" / "Debit Mastercard" spend from chequing — checked before the brand
    // (2026-10-12: brand-first read them as credit cards → 2400).
    if (preg_match('/\b(?:visa|master\s?card|mc)\s+debit\b|\bdebit\s+(?:visa|master\s?card|mc)\b/', $textLower)) {
        $result['payment_method'] = 'debit';
    } elseif (strpos($textLower, 'visa') !== false) {
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

    // A column guess must never promote an item amount to GST. Collection begins
    // after all labels, and an implausible GST is rejected when a total is known.
    $knownTotal = $result['total'];
    if ($knownTotal === null) {
        $knownTotal = extractTotal(implode("\n", $lines), $lines);
    }
    if ($knownTotal === null) {
        $knownTotal = extractPaymentLineAmount($lines);
    }
    if ($result['gst'] !== null && $knownTotal !== null && (float)$result['gst'] > (float)$knownTotal * 0.135) {   // 15% HST is 13.04% of the total
        $result['gst'] = null;
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
 * Validate that subtotal + GST + PST ≈ total (within tolerance).
 * Returns a validation result that can be shown to the user.
 *
 * @param array $parsed The parsed receipt fields (total, gst, subtotal, pst)
 * @return array ['valid' => bool, 'expected_total' => string, 'actual_total' => string, 'diff' => string, 'message' => string]
 */
function validateGstMath(array $parsed): array
{
    $subtotal = (float)($parsed['subtotal'] ?? 0);
    $gst      = (float)($parsed['gst'] ?? 0);
    $pst      = (float)($parsed['pst'] ?? 0);
    $total    = (float)($parsed['total'] ?? 0);

    // Need at least total and one other value
    if ($total <= 0) {
        return ['valid' => true, 'expected_total' => '0.00', 'actual_total' => '0.00', 'diff' => '0.00', 'message' => ''];
    }

    if ($subtotal <= 0 && $gst <= 0) {
        return ['valid' => true, 'expected_total' => number_format($total, 2, '.', ''), 'actual_total' => number_format($total, 2, '.', ''), 'diff' => '0.00', 'message' => ''];
    }

    $expectedTotal = $subtotal + $gst + $pst;
    $diff = abs($expectedTotal - $total);

    // Tolerance: $0.05 or 0.5% of total, whichever is larger
    $tolerance = max(0.05, $total * 0.005);
    $valid = ($diff <= $tolerance);

    $message = '';
    if (!$valid) {
        $message = sprintf(
            'Math check: subtotal ($%.2f) + GST ($%.2f)%s = $%.2f, but total is $%.2f (off by $%.2f)',
            $subtotal, $gst,
            $pst > 0 ? sprintf(' + PST ($%.2f)', $pst) : '',
            $expectedTotal, $total, $diff
        );
    }

    // Also check if GST is approximately 5% of subtotal
    $gstValid = true;
    $gstMessage = '';
    if ($subtotal > 0 && $gst > 0) {
        $expectedGst = round($subtotal * 0.05, 2);
        $gstDiff = abs($gst - $expectedGst);
        $gstTolerance = max(0.10, $subtotal * 0.005);

        if ($gstDiff > $gstTolerance) {
            $gstValid = false;
            $gstMessage = sprintf(
                'GST $%.2f is not ~5%% of subtotal $%.2f (expected ~$%.2f)',
                $gst, $subtotal, $expectedGst
            );
        }
    }

    return [
        'valid'          => $valid,
        'expected_total'  => number_format($expectedTotal, 2, '.', ''),
        'actual_total'    => number_format($total, 2, '.', ''),
        'diff'            => number_format($diff, 2, '.', ''),
        'message'         => $message,
        'gst_rate_valid'  => $gstValid,
        'gst_rate_message' => $gstMessage,
    ];
}
