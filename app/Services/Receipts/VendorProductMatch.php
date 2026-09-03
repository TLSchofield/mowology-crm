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
    // product_id is the CRM products.id a catalog row was trained to (train-as-you-link).
    // It was never selected before, so a recognised catalog item could only ever hint the
    // category — it never pre-linked the line item on the next receipt.
    $stmt = $db->prepare("
        SELECT id, name, category, unit, price_per_unit,
               alt_unit, alt_price, bag_price, ocr_aliases,
               product_id AS crm_product_id
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

    // 1) Fuzzy-match OCR lines against product names (single lines)
    $productMatches = fuzzyMatchProducts($lines, $searchIndex, $products);

    // 1b) If few matches found, try combining adjacent lines
    //     Handwritten receipts often split product names across lines
    //     e.g., "vy" + "1\" noch" → "vy 1 noch" which is closer to "navy jack"
    if (count($productMatches) < 2) {
        $combinedMatches = fuzzyMatchCombinedLines($lines, $searchIndex, $products);
        // Merge, keeping highest confidence per product
        foreach ($combinedMatches as $cm) {
            $found = false;
            foreach ($productMatches as &$pm) {
                if ($pm['product_id'] === $cm['product_id']) {
                    if ($cm['confidence'] > $pm['confidence']) $pm = $cm;
                    $found = true;
                    break;
                }
            }
            unset($pm);
            if (!$found) $productMatches[] = $cm;
        }
    }

    // 1c) Word-level matching: extract individual OCR words and match against aliases
    $wordMatches = fuzzyMatchByWords($lines, $searchIndex, $products);
    foreach ($wordMatches as $wm) {
        $found = false;
        foreach ($productMatches as &$pm) {
            if ($pm['product_id'] === $wm['product_id']) {
                $found = true;
                break;
            }
        }
        unset($pm);
        if (!$found) $productMatches[] = $wm;
    }

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

    // 5) Carry the CRM product id on each match, and pre-link line items whose
    //    name is what a confident (≥80) catalog match matched on. This is what turns
    //    train-as-you-link into next-scan behaviour instead of only a category hint.
    $productsById = [];
    foreach ($products as $p) { $productsById[$p['id']] = $p; }
    foreach ($productMatches as &$pm) {
        $pm['crm_product_id'] = isset($productsById[$pm['product_id']]['crm_product_id']) && $productsById[$pm['product_id']]['crm_product_id'] !== null
            ? (int)$productsById[$pm['product_id']]['crm_product_id'] : null;
    }
    unset($pm);
    if (!empty($parsed['line_items'])) {
        foreach ($parsed['line_items'] as &$li) {
            if (!empty($li['product_id']) || !empty($li['is_adjustment'])) continue;
            $liKey = strtoupper(trim((string)($li['name'] ?? '')));
            if (strlen($liKey) < 3) continue;
            foreach ($productMatches as $pm) {
                if (empty($pm['crm_product_id']) || (int)($pm['confidence'] ?? 0) < 80) continue;
                $mt = strtoupper(trim((string)($pm['matched_text'] ?? '')));
                $pn = strtoupper(trim((string)($pm['product_name'] ?? '')));
                if ($liKey === $mt || $liKey === $pn
                    || (strlen($mt) >= 4 && (strpos($liKey, $mt) !== false || strpos($mt, $liKey) !== false))) {
                    $li['product_id']     = $pm['crm_product_id'];
                    $li['product_source'] = 'catalog';
                    break;
                }
            }
        }
        unset($li);
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
        if ($line === '' || strlen($line) < 2) continue;

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
            if (strlen($term) >= 3 && strpos($lineLower, $term) !== false) {
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
            $priceMatched = false;
            foreach ($knownPrices as $knownPrice) {
                if (abs($amount - $knownPrice) < 0.01) {
                    $score += 100;
                    $priceMatched = true;
                    break;
                }
                // Close to known price (within 25%) — covers price changes over time
                if ($knownPrice > 0 && abs($amount - $knownPrice) / $knownPrice < 0.25) {
                    $score += 50;
                    $priceMatched = true;
                    break;
                }
            }

            // In the general price range for this vendor's products (reasonable amount)
            if (!$priceMatched && !empty($knownPrices)) {
                $minPrice = min($knownPrices) * 0.5;
                $maxPrice = max($knownPrices) * 2.0;
                if ($amount >= $minPrice && $amount <= $maxPrice) {
                    $score += 20;
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

        if ($bestCandidate !== null && $bestScore >= 15) {
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

    $lineCount = count($lines);

    // Strategy 1 (highest priority): Calculate from matched product amounts
    // Product-derived totals are more reliable than garbled bare numbers for handwritten receipts
    if (!empty($productMatches)) {
        $itemTotal = 0;
        $hasAmount = false;
        foreach ($productMatches as $pm) {
            if (isset($pm['amount'])) {
                $itemTotal += (float)$pm['amount'];
                $hasAmount = true;
            }
        }
        if ($hasAmount && $itemTotal > 0) {
            $gst = round($itemTotal * 0.05, 2);
            $parsed['subtotal'] = number_format($itemTotal, 2, '.', '');
            $parsed['gst'] = number_format($gst, 2, '.', '');
            $parsed['total'] = number_format($itemTotal + $gst, 2, '.', '');
            $parsed['total_source'] = 'calculated_from_products';
            return $parsed;
        }
    }

    // Strategy 2: Look for bare numbers near "TOTAL" label
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

                        // Reasonable amount check
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

    // Strategy 3: Look for bare number near "GST" label
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


/**
 * Combine adjacent non-skipped OCR lines and try matching the combined text.
 * Handwritten receipts often split product names across 2 lines.
 * E.g., "vy" + "1\" noch" → "vy 1 noch" which fuzzy-matches "navy jack".
 */
function fuzzyMatchCombinedLines(array $lines, array $searchIndex, array $products): array
{
    $matches = [];
    $productsById = [];
    foreach ($products as $p) {
        $productsById[$p['id']] = $p;
    }

    $skipPatterns = [
        '/^\d{1,2}$/',
        '/^\d{3,4}[\.\-]\d{3}[\.\-]\d{4}/',
        '/^(tel|address|date|no|www\.|http)/i',
        '/^\d+\s+(st|ave|blvd|rd|dr|cambie|broadway)/i',
        '/^(GST|PST|HST|TAX|TOTAL|SUBTOTAL)/i',
        '/^(enterprises|ltd|inc|corp)/i',
    ];

    // Collect non-skipped lines
    $candidateLines = [];
    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '' || strlen($line) < 2) continue;

        $skip = false;
        foreach ($skipPatterns as $pat) {
            if (preg_match($pat, $line)) { $skip = true; break; }
        }
        if ($skip) continue;

        $candidateLines[] = ['text' => $line, 'index' => $i];
    }

    // Try combining consecutive candidate lines (pairs and triples)
    for ($i = 0; $i < count($candidateLines) - 1; $i++) {
        // Pair
        $combined = $candidateLines[$i]['text'] . ' ' . $candidateLines[$i+1]['text'];
        $combinedLower = strtolower($combined);

        // Check alias match
        foreach ($searchIndex as $term => $pid) {
            if (strlen($term) >= 4 && strpos($combinedLower, $term) !== false) {
                $matches[] = [
                    'product_id'   => $pid,
                    'product_name' => $productsById[$pid]['name'],
                    'matched_text' => $combined,
                    'confidence'   => 70,
                    'line_index'   => $candidateLines[$i]['index'],
                    'strategy'     => 'combined_lines',
                ];
                break;
            }
        }

        // Levenshtein on combined
        if (preg_match('/[a-zA-Z]{3,}/', $combined)) {
            $bestMatch = findBestLevenshteinMatch($combinedLower, $searchIndex, $productsById);
            if ($bestMatch !== null && $bestMatch['confidence'] >= 50) {
                $matches[] = array_merge($bestMatch, [
                    'line_index'   => $candidateLines[$i]['index'],
                    'matched_text' => $combined,
                    'strategy'     => 'combined_levenshtein',
                ]);
            }
        }

        // Triple (if available)
        if ($i < count($candidateLines) - 2) {
            $triple = $candidateLines[$i]['text'] . ' ' . $candidateLines[$i+1]['text'] . ' ' . $candidateLines[$i+2]['text'];
            $tripleLower = strtolower($triple);

            foreach ($searchIndex as $term => $pid) {
                if (strlen($term) >= 5 && strpos($tripleLower, $term) !== false) {
                    $matches[] = [
                        'product_id'   => $pid,
                        'product_name' => $productsById[$pid]['name'],
                        'matched_text' => $triple,
                        'confidence'   => 65,
                        'line_index'   => $candidateLines[$i]['index'],
                        'strategy'     => 'combined_triple',
                    ];
                    break;
                }
            }
        }
    }

    // Deduplicate by product_id (keep highest confidence)
    $deduped = [];
    foreach ($matches as $m) {
        $pid = $m['product_id'];
        if (!isset($deduped[$pid]) || $m['confidence'] > $deduped[$pid]['confidence']) {
            $deduped[$pid] = $m;
        }
    }

    return array_values($deduped);
}


/**
 * Word-level matching: extract individual words from OCR and match against
 * short aliases. Useful when OCR captures fragments like "NVY" for "Navy Jack"
 * or "MULC" for "Mulch".
 */
function fuzzyMatchByWords(array $lines, array $searchIndex, array $products): array
{
    $matches = [];
    $productsById = [];
    foreach ($products as $p) {
        $productsById[$p['id']] = $p;
    }

    // Build a word-level index from aliases (3+ chars)
    $wordIndex = [];
    foreach ($products as $p) {
        if (empty($p['ocr_aliases'])) continue;
        $aliases = explode(',', $p['ocr_aliases']);
        foreach ($aliases as $alias) {
            $alias = strtolower(trim($alias));
            // Single-word aliases (good for word-level matching)
            if ($alias !== '' && strpos($alias, ' ') === false && strlen($alias) >= 3) {
                $wordIndex[$alias] = $p['id'];
            }
        }
    }

    if (empty($wordIndex)) return $matches;

    // Extract all words from OCR text
    $allText = implode(' ', $lines);
    $words = preg_split('/[\s\/\-"\',.;:!?()]+/', strtolower($allText));
    $words = array_filter($words, function($w) { return strlen($w) >= 2; });

    foreach ($words as $word) {
        $word = trim($word);
        if (strlen($word) < 2) continue;

        // Exact word match against alias
        if (isset($wordIndex[$word])) {
            $pid = $wordIndex[$word];
            $matches[] = [
                'product_id'   => $pid,
                'product_name' => $productsById[$pid]['name'],
                'matched_text' => $word,
                'confidence'   => 70,
                'line_index'   => 0,
                'strategy'     => 'word_exact',
            ];
            continue;
        }

        // Close Levenshtein match for short words (edit distance 1)
        if (strlen($word) >= 3) {
            foreach ($wordIndex as $alias => $pid) {
                if (abs(strlen($word) - strlen($alias)) > 2) continue;
                $dist = levenshtein($word, $alias);
                if ($dist <= 1 && $dist < strlen($alias) * 0.4) {
                    $matches[] = [
                        'product_id'   => $pid,
                        'product_name' => $productsById[$pid]['name'],
                        'matched_text' => $word . ' (≈' . $alias . ')',
                        'confidence'   => 60,
                        'line_index'   => 0,
                        'strategy'     => 'word_fuzzy',
                    ];
                    break;
                }
            }
        }
    }

    // Deduplicate
    $deduped = [];
    foreach ($matches as $m) {
        $pid = $m['product_id'];
        if (!isset($deduped[$pid]) || $m['confidence'] > $deduped[$pid]['confidence']) {
            $deduped[$pid] = $m;
        }
    }

    return array_values($deduped);
}
