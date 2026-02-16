<?php
/**
 * /app/Services/Receipts/VendorProductMatch.php
 * Vendor Product Matching for Receipt OCR
 *
 * When the OCR identifies a vendor (especially handwritten receipt vendors like Lawnboy),
 * this module pulls that vendor's known product catalog and fuzzy-matches OCR text
 * against it. This dramatically improves parsing for handwritten/carbon-copy receipts
 * where amounts and item names are garbled.
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/VendorProductMatch.php';
 *   $enhanced = matchVendorProducts($vendorId, $ocrText, $parsed);
 *   // Returns enhanced $parsed array with product_matches and improved line_items
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Main entry: enhance parsed receipt data using vendor's known product catalog.
 *
 * @param int    $vendorId  Matched vendor ID
 * @param string $ocrText   Raw OCR text
 * @param array  $parsed    Already-parsed receipt fields from parseReceiptText()
 * @return array Enhanced $parsed with 'product_matches' key added
 */
function matchVendorProducts(int $vendorId, string $ocrText, array $parsed): array
{
    $db = getDB();

    // Load vendor's known products
    $stmt = $db->prepare("
        SELECT id, name, category, unit, price_per_unit,
               alt_unit, alt_price, bag_price, ocr_aliases
        FROM vendor_products
        WHERE vendor_id = ? AND is_active = 1
        ORDER BY name
    ");
    $stmt->execute([$vendorId]);
    $products = $stmt->fetchAll();

    if (empty($products)) {
        return $parsed;
    }

    // Build a search index: product name + aliases → product row
    $searchIndex = buildProductSearchIndex($products);

    $lines = preg_split('/\r?\n/', $ocrText);

    // 1) Fuzzy-match OCR lines against product names
    $productMatches = fuzzyMatchProducts($lines, $searchIndex, $products);

    // 2) If we got product matches, try to pair them with amounts from OCR
    if (!empty($productMatches)) {
        $productMatches = pairMatchesWithAmounts($productMatches, $lines, $products);
    }

    // 3) Enhance the parsed total/GST if they're missing — use bare numbers near labels
    $parsed = enhanceAmountsFromBareNumbers($parsed, $lines, $productMatches, $products);

    // 4) Rebuild line_items from product matches if parser found none
    if (empty($parsed['line_items']) && !empty($productMatches)) {
        $parsed['line_items'] = buildLineItemsFromMatches($productMatches);
    }

    $parsed['product_matches'] = $productMatches;

    return $parsed;
}


/**
 * Build a search index mapping lowercase terms to product IDs.
 * Includes the product name, each word, and each OCR alias.
 */
function buildProductSearchIndex(array $products): array
{
    $index = [];

    foreach ($products as $product) {
        $pid = $product['id'];
        $name = strtolower(trim($product['name']));

        // Full name
        $index[$name] = $pid;

        // Individual significant words (3+ chars)
        $words = preg_split('/[\s\/\-"]+/', $name);
        foreach ($words as $word) {
            $word = trim($word, '.,()');
            if (strlen($word) >= 3) {
                $index[$word] = $pid;
            }
        }

        // OCR aliases
        if (!empty($product['ocr_aliases'])) {
            $aliases = explode(',', $product['ocr_aliases']);
            foreach ($aliases as $alias) {
                $alias = strtolower(trim($alias));
                if ($alias !== '') {
                    $index[$alias] = $pid;
                }
            }
        }
    }

    return $index;
}


/**
 * Fuzzy-match OCR lines against product search index.
 * Uses multiple strategies: exact match, alias match, Levenshtein distance.
 *
 * @return array of ['product_id', 'product_name', 'matched_text', 'confidence', 'line_index']
 */
function fuzzyMatchProducts(array $lines, array $searchIndex, array $products): array
{
    $matches = [];
    $productsById = [];
    foreach ($products as $p) {
        $productsById[$p['id']] = $p;
    }

    // Skip obvious non-product lines (header, address, phone, row numbers)
    $skipPatterns = [
        '/^\d{1,2}$/',                    // Single/double digit (row numbers on invoice pad)
        '/^\d{3,4}[\.\-]\d{3}[\.\-]\d{4}/', // Phone numbers
        '/^(tel|address|date|no|www\.|http)/i',
        '/^\d+\s+(st|ave|blvd|rd|dr|cambie|broadway)/i', // Street addresses
        '/^(GST|PST|HST|TAX|TOTAL|SUBTOTAL)/i',
        '/^(enterprises|ltd|inc|corp)/i',
    ];

    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '' || strlen($line) < 3) continue;

        $lineLower = strtolower($line);

        // Skip non-product lines
        $skip = false;
        foreach ($skipPatterns as $pat) {
            if (preg_match($pat, $line)) { $skip = true; break; }
        }
        if ($skip) continue;

        // Strategy 1: Exact match against full alias
        if (isset($searchIndex[$lineLower])) {
            $pid = $searchIndex[$lineLower];
            $matches[] = [
                'product_id'   => $pid,
                'product_name' => $productsById[$pid]['name'],
                'matched_text' => $line,
                'confidence'   => 95,
                'line_index'   => $i,
            ];
            continue;
        }

        // Strategy 2: Check if line contains an alias as a substring
        foreach ($searchIndex as $term => $pid) {
            if (strlen($term) >= 4 && strpos($lineLower, $term) !== false) {
                $matches[] = [
                    'product_id'   => $pid,
                    'product_name' => $productsById[$pid]['name'],
                    'matched_text' => $line,
                    'confidence'   => 80,
                    'line_index'   => $i,
                ];
                continue 2;
            }
        }

        // Strategy 3: Levenshtein fuzzy match for garbled OCR
        // Only try on lines that look like they could be product names (3+ alpha chars)
        if (preg_match('/[a-zA-Z]{3,}/', $line)) {
            $bestMatch = findBestLevenshteinMatch($lineLower, $searchIndex, $productsById);
            if ($bestMatch !== null) {
                $matches[] = array_merge($bestMatch, ['line_index' => $i, 'matched_text' => $line]);
            }
        }
    }

    // Deduplicate: if same product matched multiple times, keep highest confidence
    $deduped = [];
    foreach ($matches as $match) {
        $pid = $match['product_id'];
        if (!isset($deduped[$pid]) || $match['confidence'] > $deduped[$pid]['confidence']) {
            $deduped[$pid] = $match;
        }
    }

    return array_values($deduped);
}


/**
 * Find the best Levenshtein distance match for a line against product terms.
 * Only returns matches with distance <= 30% of the term length (i.e., ~70% similar).
 */
function findBestLevenshteinMatch(string $lineLower, array $searchIndex, array $productsById): ?array
{
    $bestDist = PHP_INT_MAX;
    $bestPid = null;
    $bestTerm = null;

    // Clean the line — strip numbers, punctuation for comparison
    $cleanLine = preg_replace('/[^a-z\s]/', '', $lineLower);
    $cleanLine = preg_replace('/\s+/', ' ', trim($cleanLine));

    if (strlen($cleanLine) < 3) return null;

    foreach ($searchIndex as $term => $pid) {
        // Only compare against multi-word terms (full names/aliases) for Levenshtein
        if (strlen($term) < 5 || strpos($term, ' ') === false) continue;

        $dist = levenshtein($cleanLine, $term);
        $maxLen = max(strlen($cleanLine), strlen($term));
        $threshold = (int)ceil($maxLen * 0.35); // Allow up to 35% difference

        if ($dist <= $threshold && $dist < $bestDist) {
            $bestDist = $dist;
            $bestPid = $pid;
            $bestTerm = $term;
        }
    }

    if ($bestPid === null) return null;

    // Confidence based on similarity ratio
    $maxLen = max(strlen($cleanLine), strlen($bestTerm));
    $similarity = 1 - ($bestDist / $maxLen);
    $confidence = (int)round($similarity * 100);

    // Minimum confidence threshold
    if ($confidence < 55) return null;

    return [
        'product_id'   => $bestPid,
        'product_name' => $productsById[$bestPid]['name'],
        'confidence'   => $confidence,
    ];
}


/**
 * Try to pair product matches with amounts found in surrounding OCR lines.
 * Looks for bare numbers (no decimal) or decimal numbers near the matched line.
 * Cross-references against known product prices.
 */
function pairMatchesWithAmounts(array $matches, array $lines, array $products): array
{
    $productsById = [];
    foreach ($products as $p) {
        $productsById[$p['id']] = $p;
    }

    foreach ($matches as &$match) {
        $pid = $match['product_id'];
        $product = $productsById[$pid] ?? null;
        if (!$product) continue;

        $lineIdx = $match['line_index'];

        // Collect nearby number candidates (within 3 lines in either direction)
        $candidates = [];
        for ($offset = -3; $offset <= 3; $offset++) {
            $checkIdx = $lineIdx + $offset;
            if ($checkIdx < 0 || $checkIdx >= count($lines) || $checkIdx === $lineIdx) continue;

            $checkLine = trim($lines[$checkIdx]);

            // Decimal number: "54.00", "$54.00"
            if (preg_match('/^\$?(\d{1,6}\.\d{2})\s*$/', $checkLine, $m)) {
                $candidates[] = ['amount' => (float)$m[1], 'formatted' => $m[1], 'distance' => abs($offset)];
            }
            // Bare integer that could be a dollar amount: "54", "107"
            elseif (preg_match('/^(\d{2,4})\s*$/', $checkLine, $m)) {
                $val = (int)$m[1];
                // Only consider if it's in a reasonable price range and not a row number (1-12)
                if ($val >= 15 && $val <= 999) {
                    $candidates[] = ['amount' => (float)$val, 'formatted' => number_format($val, 2, '.', ''), 'distance' => abs($offset), 'bare' => true];
                }
            }
        }

        // Also check the matched line itself for an inline amount
        $matchLine = trim($lines[$lineIdx]);
        if (preg_match('/\$?(\d{1,6}\.\d{2})\s*$/', $matchLine, $m)) {
            $candidates[] = ['amount' => (float)$m[1], 'formatted' => $m[1], 'distance' => 0];
        }

        if (empty($candidates)) continue;

        // Score candidates by how well they match known prices
        $knownPrices = array_filter([
            $product['price_per_unit'] ? (float)$product['price_per_unit'] : null,
            $product['alt_price'] ? (float)$product['alt_price'] : null,
            $product['bag_price'] ? (float)$product['bag_price'] : null,
        ]);

        $bestCandidate = null;
        $bestScore = -1;

        foreach ($candidates as $candidate) {
            $score = 0;
            $amount = $candidate['amount'];

            // Exact price match is very strong
            foreach ($knownPrices as $knownPrice) {
                if (abs($amount - $knownPrice) < 0.01) {
                    $score += 100;
                    break;
                }
                // Close to known price (within 10%) — could be price change
                if ($knownPrice > 0 && abs($amount - $knownPrice) / $knownPrice < 0.10) {
                    $score += 60;
                    break;
                }
            }

            // Closer lines score higher
            $score += max(0, 10 - $candidate['distance'] * 3);

            // Decimal numbers are more reliable than bare integers
            if (empty($candidate['bare'])) {
                $score += 15;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $candidate;
            }
        }

        if ($bestCandidate !== null && $bestScore >= 20) {
            $match['amount'] = $bestCandidate['formatted'];
            $match['amount_confidence'] = min(95, $bestScore);

            // Infer quantity from price match
            $amount = $bestCandidate['amount'];
            $yardPrice = $product['price_per_unit'] ? (float)$product['price_per_unit'] : null;
            $halfYardPrice = $product['alt_price'] ? (float)$product['alt_price'] : null;

            if ($yardPrice && abs($amount - $yardPrice) < 0.01) {
                $match['inferred_qty'] = 1;
                $match['inferred_unit'] = $product['unit'] ?? 'yard';
            } elseif ($halfYardPrice && abs($amount - $halfYardPrice) < 0.01) {
                $match['inferred_qty'] = 1;
                $match['inferred_unit'] = $product['alt_unit'] ?? 'half_yard';
            }
        }
    }
    unset($match);

    return $matches;
}


/**
 * Enhance total/GST extraction for handwritten receipts using bare numbers.
 * If the standard parser couldn't find a total (because "56.70" OCR'd as "6043"),
 * try to reconstruct it from product match amounts + known GST rate.
 */
function enhanceAmountsFromBareNumbers(array $parsed, array $lines, array $productMatches, array $products): array
{
    // If total already found with decimal, trust it
    if ($parsed['total'] !== null) {
        return $parsed;
    }

    // Strategy 1: Look for bare numbers near "TOTAL" label
    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount; $i++) {
        $line = trim($lines[$i]);
        if (preg_match('/^TOTAL\s*:?\s*$/i', $line) && stripos($line, 'sub') === false) {
            // Check next few lines for bare numbers
            for ($j = $i + 1; $j < min($i + 3, $lineCount); $j++) {
                $nextLine = trim($lines[$j]);
                // Bare number with no decimal: try inserting decimal
                if (preg_match('/^(\d{3,6})\s*$/', $nextLine, $m)) {
                    $bare = $m[1];
                    // Try inserting decimal 2 places from end: "5670" → "56.70"
                    if (strlen($bare) >= 3) {
                        $withDecimal = substr($bare, 0, -2) . '.' . substr($bare, -2);
                        $candidate = (float)$withDecimal;

                        // Validate: does it match sum of product amounts + 5% GST?
                        if (!empty($productMatches)) {
                            $itemTotal = 0;
                            foreach ($productMatches as $pm) {
                                if (isset($pm['amount'])) {
                                    $itemTotal += (float)$pm['amount'];
                                }
                            }
                            if ($itemTotal > 0) {
                                $expectedTotal = $itemTotal * 1.05;
                                if (abs($candidate - $expectedTotal) < 1.00) {
                                    $parsed['total'] = number_format($candidate, 2, '.', '');
                                    $parsed['total_source'] = 'vendor_product_match';
                                    break 2;
                                }
                            }
                        }

                        // Fallback: if it's a reasonable amount (> $10), use it
                        if ($candidate >= 10.00 && $candidate <= 5000.00) {
                            $parsed['total'] = number_format($candidate, 2, '.', '');
                            $parsed['total_source'] = 'bare_number_inferred';
                            break 2;
                        }
                    }
                }
            }
        }
    }

    // Strategy 2: Look for bare number near "GST" label
    if ($parsed['gst'] === null) {
        for ($i = 0; $i < $lineCount; $i++) {
            $line = trim($lines[$i]);
            if (preg_match('/^GST(?:\s*\/?\s*HST)?(?:\s*\d+%?)?\s*:?\s*$/i', $line)) {
                for ($j = $i + 1; $j < min($i + 3, $lineCount); $j++) {
                    $nextLine = trim($lines[$j]);
                    if (preg_match('/^(\d{2,5})\s*$/', $nextLine, $m)) {
                        $bare = $m[1];
                        if (strlen($bare) >= 2) {
                            $withDecimal = substr($bare, 0, -2) . '.' . substr($bare, -2);
                            if (strlen($bare) === 2) {
                                // "27" → could be "2.70"
                                $withDecimal = $bare[0] . '.' . $bare[1] . '0';
                            }
                            $candidate = (float)$withDecimal;
                            // Validate against 5% of known item total
                            if (!empty($productMatches)) {
                                $itemTotal = 0;
                                foreach ($productMatches as $pm) {
                                    if (isset($pm['amount'])) {
                                        $itemTotal += (float)$pm['amount'];
                                    }
                                }
                                if ($itemTotal > 0) {
                                    $expectedGST = $itemTotal * 0.05;
                                    if (abs($candidate - $expectedGST) < 1.00) {
                                        $parsed['gst'] = number_format($candidate, 2, '.', '');
                                        $parsed['gst_source'] = 'vendor_product_match';
                                        break 2;
                                    }
                                }
                            }
                            // Reasonable GST amount
                            if ($candidate >= 0.25 && $candidate <= 500.00) {
                                $parsed['gst'] = number_format($candidate, 2, '.', '');
                                $parsed['gst_source'] = 'bare_number_inferred';
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }

    // Strategy 3: If we matched products with amounts but still no total,
    // calculate total = sum of items * 1.05
    if ($parsed['total'] === null && !empty($productMatches)) {
        $itemTotal = 0;
        $hasAmount = false;
        foreach ($productMatches as $pm) {
            if (isset($pm['amount'])) {
                $itemTotal += (float)$pm['amount'];
                $hasAmount = true;
            }
        }
        if ($hasAmount && $itemTotal > 0) {
            $gst = $itemTotal * 0.05;
            $parsed['subtotal'] = number_format($itemTotal, 2, '.', '');
            $parsed['gst'] = number_format($gst, 2, '.', '');
            $parsed['total'] = number_format($itemTotal + $gst, 2, '.', '');
            $parsed['total_source'] = 'calculated_from_products';
        }
    }

    return $parsed;
}


/**
 * Build line_items array from product matches (used when parser found no items).
 */
function buildLineItemsFromMatches(array $productMatches): array
{
    $items = [];
    foreach ($productMatches as $match) {
        if (empty($match['amount'])) continue;

        $item = [
            'name'       => $match['product_name'],
            'amount'     => $match['amount'],
            'quantity'   => $match['inferred_qty'] ?? 1,
            'unit_price' => null,
            'sku_raw'    => null,
        ];

        if (isset($match['inferred_unit'])) {
            $item['unit'] = $match['inferred_unit'];
        }

        $items[] = $item;
    }

    return $items;
}
