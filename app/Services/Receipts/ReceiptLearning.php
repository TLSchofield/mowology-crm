<?php
/**
 * /app/Services/Receipts/ReceiptLearning.php
 * Self-Improving Receipt Parser — Learning Engine
 *
 * Records user corrections to OCR results and builds per-vendor parsing profiles
 * that improve accuracy over time.
 *
 * Two-phase system:
 *   1. recordCorrections() — called on expense save, diffs OCR vs user values
 *   2. applyLearnedPatterns() — called during parsing, applies vendor-specific rules
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
 *   recordCorrections($vendorId, $ocrParsed, $userSaved, $ocrText);
 *   $enhanced = applyLearnedPatterns($vendorId, $parsed, $ocrText);
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Record corrections the user made to OCR-parsed values.
 * Called when an expense is saved/updated after OCR intake.
 *
 * @param int|null   $vendorId   Matched vendor ID (null if unknown)
 * @param string|null $vendorName Vendor name (for unmatched vendors)
 * @param array      $ocrParsed  What the parser extracted (from parseReceiptText)
 * @param array      $userSaved  What the user actually saved
 * @param string     $ocrText    Raw OCR text (for context extraction)
 */
function recordCorrections(?int $vendorId, ?string $vendorName, array $ocrParsed, array $userSaved, string $ocrText): void
{
    $db = getDB();

    // Fields to compare
    $fieldMap = [
        'total'    => ['ocr' => $ocrParsed['total'] ?? null,    'user' => $userSaved['total'] ?? null],
        'gst'      => ['ocr' => $ocrParsed['gst'] ?? null,      'user' => $userSaved['gst_amount'] ?? null],
        'subtotal' => ['ocr' => $ocrParsed['subtotal'] ?? null,  'user' => $userSaved['amount'] ?? null],
        'date'     => ['ocr' => $ocrParsed['date'] ?? null,      'user' => $userSaved['expense_date'] ?? null],
        'vendor'   => ['ocr' => $ocrParsed['vendor_hint'] ?? null, 'user' => $vendorName],
    ];

    $hadCorrections = false;

    foreach ($fieldMap as $fieldName => $values) {
        $ocrVal = $values['ocr'];
        $userVal = $values['user'];

        // Skip if both null/empty
        if (empty($ocrVal) && empty($userVal)) continue;

        // Normalize for comparison
        $ocrNorm = normalizeFieldValue($fieldName, $ocrVal);
        $userNorm = normalizeFieldValue($fieldName, $userVal);

        // If they match, no correction needed — but still track for accuracy stats
        $isCorrection = ($ocrNorm !== $userNorm);

        if ($isCorrection) {
            $hadCorrections = true;

            // Extract context: find the OCR text around where this field's value appears
            $context = extractOcrContext($ocrText, $fieldName, $ocrVal, $userVal);

            // Check if we've seen this exact correction pattern before
            $existing = findExistingLesson($db, $vendorId, $fieldName, $ocrVal, $userVal);

            if ($existing) {
                // Increment times_seen
                $stmt = $db->prepare("
                    UPDATE receipt_parse_lessons
                    SET times_seen = times_seen + 1, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$existing['id']]);
            } else {
                // Insert new lesson
                $stmt = $db->prepare("
                    INSERT INTO receipt_parse_lessons
                        (vendor_id, vendor_name, field_name, ocr_value, corrected_value, ocr_context)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $vendorId,
                    $vendorName,
                    $fieldName,
                    $ocrVal,
                    $userVal,
                    $context,
                ]);
            }
        }
    }

    // ── Line item name corrections ─────────────────────────────────────
    // Compare OCR-extracted item names with user-saved names (by position).
    // Corrections feed into receipt_parse_lessons and eventually into vendor_products.ocr_aliases.
    if ($vendorId) {
        $ocrItems  = $ocrParsed['line_items']  ?? [];
        $userItems = $userSaved['line_items']   ?? [];
        if (is_string($userItems)) {
            $userItems = json_decode($userItems, true) ?: [];
        }
        $pairCount = min(count($ocrItems), count($userItems));
        for ($i = 0; $i < $pairCount; $i++) {
            $ocrName  = strtoupper(trim($ocrItems[$i]['name'] ?? ''));
            $userName = strtoupper(trim($userItems[$i]['name'] ?? ''));
            if (empty($ocrName) || empty($userName) || $ocrName === $userName) continue;

            $existing = findExistingLesson($db, $vendorId, 'line_item_name', $ocrName, $userName);
            if ($existing) {
                $db->prepare("UPDATE receipt_parse_lessons SET times_seen = times_seen + 1, updated_at = NOW() WHERE id = ?")
                   ->execute([$existing['id']]);
            } else {
                $db->prepare("INSERT INTO receipt_parse_lessons (vendor_id, vendor_name, field_name, ocr_value, corrected_value, ocr_context) VALUES (?, ?, 'line_item_name', ?, ?, ?)")
                   ->execute([$vendorId, $vendorName, $ocrName, $userName, $ocrName . ' → ' . $userName]);
            }
            $hadCorrections = true;
        }
    }

    // ── Accounting category corrections ────────────────────────────────
    // If the user changed the auto-suggested category, record it.
    // After 2+ identical corrections, the learned category overrides vendor default.
    if ($vendorId) {
        $ocrCategory  = $ocrParsed['accounting_category']  ?? null;
        $userCategory = $userSaved['accounting_category']  ?? null;
        if (!empty($userCategory) && $ocrCategory !== $userCategory) {
            $existing = findExistingLesson($db, $vendorId, 'accounting_category', $ocrCategory, $userCategory);
            if ($existing) {
                $db->prepare("UPDATE receipt_parse_lessons SET times_seen = times_seen + 1, updated_at = NOW() WHERE id = ?")
                   ->execute([$existing['id']]);
                // Promote to vendor profile after 2+ consistent corrections
                if ((int)$existing['times_seen'] >= 2) {
                    $db->prepare("
                        INSERT INTO vendor_parse_profiles (vendor_id, learned_accounting_category, category_correction_count)
                        VALUES (?, ?, 1)
                        ON DUPLICATE KEY UPDATE
                            learned_accounting_category = VALUES(learned_accounting_category),
                            category_correction_count = category_correction_count + 1,
                            updated_at = NOW()
                    ")->execute([$vendorId, $userCategory]);
                }
            } else {
                $db->prepare("INSERT INTO receipt_parse_lessons (vendor_id, vendor_name, field_name, ocr_value, corrected_value, ocr_context) VALUES (?, ?, 'accounting_category', ?, ?, '')")
                   ->execute([$vendorId, $vendorName, $ocrCategory, $userCategory]);
            }
        }
    }

    // Update vendor parse profile stats
    if ($vendorId) {
        updateVendorProfile($db, $vendorId, $fieldMap, $hadCorrections);
    }
}


/**
 * Apply learned patterns to enhance parser output for a specific vendor.
 * Called after initial parsing, before returning results to the client.
 *
 * @param int|null $vendorId Matched vendor ID
 * @param array    $parsed   Output from parseReceiptText()
 * @param string   $ocrText  Raw OCR text
 * @return array Enhanced parsed results
 */
function applyLearnedPatterns(?int $vendorId, array $parsed, string $ocrText): array
{
    if (!$vendorId) {
        return $parsed;
    }

    $db = getDB();

    // Get the vendor's parse profile
    $profile = getVendorParseProfile($db, $vendorId);
    if (!$profile) {
        return $parsed;
    }

    // Apply GST label learning
    if (empty($parsed['gst']) && !empty($profile['gst_label'])) {
        $gstLabel = preg_quote($profile['gst_label'], '/');
        $lines = preg_split('/\r?\n/', $ocrText);

        if ($profile['gst_position'] === 'next_line') {
            // Look for the label on one line and amount on the next
            for ($i = 0; $i < count($lines) - 1; $i++) {
                if (preg_match('/^' . $gstLabel . '\s*:?\s*$/i', trim($lines[$i]))) {
                    $nextLine = trim($lines[$i + 1]);
                    if (preg_match('/^\$?(\d{1,6}[.,]\d{2})/', $nextLine, $m)) {
                        $parsed['gst'] = str_replace(',', '.', $m[1]);
                        $parsed['gst_source'] = 'learned';
                        break;
                    }
                }
            }
        } elseif ($profile['gst_position'] === 'same_line') {
            if (preg_match('/' . $gstLabel . '\s*:?\s*\$?\s*(\d{1,6}[.,]\d{2})/i', $ocrText, $m)) {
                $parsed['gst'] = str_replace(',', '.', $m[1]);
                $parsed['gst_source'] = 'learned';
            }
        }
    }

    // Apply date format learning
    if (empty($parsed['date']) && !empty($profile['date_format'])) {
        $parsed['date'] = extractDateWithFormat($ocrText, $profile['date_format']);
        if ($parsed['date']) {
            $parsed['date_source'] = 'learned';
        }
    }

    // Apply common corrections — if the parser consistently gets a field wrong
    // for this vendor, apply the most common correction
    $frequentFixes = getFrequentCorrections($db, $vendorId, 3);
    foreach ($frequentFixes as $fix) {
        $field = $fix['field_name'];
        // Only apply if the parser returned null/empty and the fix is consistent
        if (empty($parsed[$field]) && !empty($fix['corrected_value']) && $fix['times_seen'] >= 3) {
            // Don't auto-fill amounts — those change per receipt
            // Only auto-fill structural patterns (labels, formats)
            if (!in_array($field, ['total', 'gst', 'subtotal', 'line_item_name', 'accounting_category'])) {
                $parsed[$field] = $fix['corrected_value'];
                $parsed[$field . '_source'] = 'learned';
            }
        }
    }

    // ── Apply learned line item name corrections ───────────────────────
    // After 2+ corrections of an OCR misread → correct name, apply automatically.
    if (!empty($parsed['line_items'])) {
        try {
            $stmt = $db->prepare("
                SELECT ocr_value, corrected_value FROM receipt_parse_lessons
                WHERE vendor_id = ? AND field_name = 'line_item_name' AND times_seen >= 2
            ");
            $stmt->execute([$vendorId]);
            $nameMap = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $nameMap[strtoupper(trim($row['ocr_value']))] = $row['corrected_value'];
            }
            if (!empty($nameMap)) {
                foreach ($parsed['line_items'] as &$item) {
                    $key = strtoupper(trim($item['name'] ?? ''));
                    if (isset($nameMap[$key])) {
                        $item['name']        = $nameMap[$key];
                        $item['name_source'] = 'learned';
                    }
                }
                unset($item);
            }
        } catch (Throwable $e) { /* non-fatal */ }
    }

    // ── Apply learned accounting category ─────────────────────────────
    // Override suggested category if the user has consistently corrected it (2+).
    if (!empty($profile['learned_accounting_category']) && (int)($profile['category_correction_count'] ?? 0) >= 2) {
        $parsed['accounting_category']        = $profile['learned_accounting_category'];
        $parsed['accounting_category_source'] = 'learned';
    }

    return $parsed;
}


/**
 * Get the vendor's learned parse profile.
 */
function getVendorParseProfile(PDO $db, int $vendorId): ?array
{
    try {
        $stmt = $db->prepare("SELECT * FROM vendor_parse_profiles WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        // Table might not exist yet
        return null;
    }
}


/**
 * Get the most frequent corrections for a vendor.
 */
function getFrequentCorrections(PDO $db, int $vendorId, int $minSeen = 3): array
{
    try {
        $stmt = $db->prepare("
            SELECT field_name, ocr_value, corrected_value, times_seen, ocr_context
            FROM receipt_parse_lessons
            WHERE vendor_id = ? AND times_seen >= ?
            ORDER BY times_seen DESC
            LIMIT 20
        ");
        $stmt->execute([$vendorId, $minSeen]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}


/**
 * Find an existing lesson matching this correction pattern.
 */
function findExistingLesson(PDO $db, ?int $vendorId, string $fieldName, ?string $ocrVal, ?string $userVal): ?array
{
    try {
        if ($vendorId) {
            $stmt = $db->prepare("
                SELECT id, times_seen FROM receipt_parse_lessons
                WHERE vendor_id = ? AND field_name = ?
                  AND (ocr_value = ? OR (ocr_value IS NULL AND ? IS NULL))
                  AND (corrected_value = ? OR (corrected_value IS NULL AND ? IS NULL))
                LIMIT 1
            ");
            $stmt->execute([$vendorId, $fieldName, $ocrVal, $ocrVal, $userVal, $userVal]);
        } else {
            $stmt = $db->prepare("
                SELECT id, times_seen FROM receipt_parse_lessons
                WHERE vendor_id IS NULL AND field_name = ?
                  AND (ocr_value = ? OR (ocr_value IS NULL AND ? IS NULL))
                  AND (corrected_value = ? OR (corrected_value IS NULL AND ? IS NULL))
                LIMIT 1
            ");
            $stmt->execute([$fieldName, $ocrVal, $ocrVal, $userVal, $userVal]);
        }
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}


/**
 * Update or create the vendor's parse profile with stats.
 */
function updateVendorProfile(PDO $db, int $vendorId, array $fieldMap, bool $hadCorrections): void
{
    try {
        // Check if profile exists
        $stmt = $db->prepare("SELECT id, total_receipts, total_corrections FROM vendor_parse_profiles WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        $correctionCount = 0;
        $fieldCount = 0;
        foreach ($fieldMap as $values) {
            if (!empty($values['user'])) {
                $fieldCount++;
                $ocrNorm = normalizeFieldValue('', $values['ocr']);
                $userNorm = normalizeFieldValue('', $values['user']);
                if ($ocrNorm !== $userNorm) {
                    $correctionCount++;
                }
            }
        }

        if ($profile) {
            $newTotal = $profile['total_receipts'] + 1;
            $newCorrections = $profile['total_corrections'] + $correctionCount;
            $totalFields = $newTotal * 5; // ~5 fields per receipt
            $correctFields = $totalFields - $newCorrections;
            $accuracy = $totalFields > 0 ? round(($correctFields / $totalFields) * 100, 2) : null;

            $stmt = $db->prepare("
                UPDATE vendor_parse_profiles
                SET total_receipts = ?, total_corrections = ?, accuracy_rate = ?, updated_at = NOW()
                WHERE vendor_id = ?
            ");
            $stmt->execute([$newTotal, $newCorrections, $accuracy, $vendorId]);
        } else {
            $accuracy = $fieldCount > 0 ? round((($fieldCount - $correctionCount) / $fieldCount) * 100, 2) : null;
            $stmt = $db->prepare("
                INSERT INTO vendor_parse_profiles (vendor_id, total_receipts, total_corrections, accuracy_rate)
                VALUES (?, 1, ?, ?)
            ");
            $stmt->execute([$vendorId, $correctionCount, $accuracy]);
        }

        // After enough data, derive patterns
        if (($profile['total_receipts'] ?? 0) >= 3) {
            deriveVendorPatterns($db, $vendorId);
        }
    } catch (Throwable $e) {
        error_log('updateVendorProfile error: ' . $e->getMessage());
    }
}


/**
 * Analyze accumulated lessons and derive patterns for the vendor profile.
 * Called after enough receipts have been processed.
 */
function deriveVendorPatterns(PDO $db, int $vendorId): void
{
    try {
        // Look at GST corrections to learn the label
        $stmt = $db->prepare("
            SELECT ocr_context, corrected_value, times_seen
            FROM receipt_parse_lessons
            WHERE vendor_id = ? AND field_name = 'gst' AND times_seen >= 2
            ORDER BY times_seen DESC
            LIMIT 5
        ");
        $stmt->execute([$vendorId]);
        $gstLessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updates = [];

        if (!empty($gstLessons)) {
            // Check if the context contains specific GST label patterns
            foreach ($gstLessons as $lesson) {
                $ctx = $lesson['ocr_context'] ?? '';
                if (preg_match('/(GST\s*\/\s*HST)/i', $ctx)) {
                    $updates['gst_label'] = 'GST/HST';
                } elseif (preg_match('/(HST)/i', $ctx)) {
                    $updates['gst_label'] = 'HST';
                } elseif (preg_match('/(GST)/i', $ctx)) {
                    $updates['gst_label'] = 'GST';
                }

                // Determine position (same line or next line)
                if (preg_match('/GST.*\n.*\d+\.\d{2}/i', $ctx)) {
                    $updates['gst_position'] = 'next_line';
                } else {
                    $updates['gst_position'] = 'same_line';
                }
            }
        }

        // Check for PST patterns
        $stmt = $db->prepare("
            SELECT ocr_context FROM receipt_parse_lessons
            WHERE vendor_id = ? AND ocr_context LIKE '%PST%'
            LIMIT 1
        ");
        $stmt->execute([$vendorId]);
        if ($stmt->fetch()) {
            $updates['has_pst'] = 1;
            $updates['pst_label'] = 'PST/QST';
        }

        // ── Auto-derive Tesseract threshold from accuracy history ──────
        $pfStmt = $db->prepare("SELECT accuracy_rate, total_receipts FROM vendor_parse_profiles WHERE vendor_id = ?");
        $pfStmt->execute([$vendorId]);
        $pf = $pfStmt->fetch(PDO::FETCH_ASSOC);
        if ($pf && (int)$pf['total_receipts'] >= 5) {
            $rate = (float)$pf['accuracy_rate'];
            $updates['tesseract_threshold'] = $rate >= 80 ? 55 : ($rate < 50 ? 80 : 70);
        }

        // ── Auto-update vendor aliases from OCR name misreads ──────────
        // When OCR consistently misreads a vendor name (3+ times), add the misread
        // as an alias so future receipts match at higher confidence.
        $vendorNameFixes = $db->prepare("
            SELECT ocr_value FROM receipt_parse_lessons
            WHERE vendor_id = ? AND field_name = 'vendor' AND times_seen >= 3
        ");
        $vendorNameFixes->execute([$vendorId]);
        $ocrMisreads = $vendorNameFixes->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($ocrMisreads)) {
            $vendorRow = $db->prepare("SELECT aliases FROM vendors WHERE id = ?");
            $vendorRow->execute([$vendorId]);
            $vendor = $vendorRow->fetch(PDO::FETCH_ASSOC);
            if ($vendor !== false) {
                $existingAliases = array_map('strtolower', array_filter(explode(',', $vendor['aliases'] ?? '')));
                $toAdd = [];
                foreach ($ocrMisreads as $misread) {
                    $mis = strtolower(trim($misread));
                    if (!empty($mis) && !in_array($mis, $existingAliases, true)) {
                        $toAdd[] = $mis;
                    }
                }
                if (!empty($toAdd)) {
                    $newAliases = trim(($vendor['aliases'] ?? '') . ',' . implode(',', $toAdd), ',');
                    $db->prepare("UPDATE vendors SET aliases = ? WHERE id = ?")->execute([$newAliases, $vendorId]);
                }
            }
        }

        // ── Promote line item name corrections into vendor_products aliases ──
        // After 3+ corrections of the same OCR misread, add it as an OCR alias
        // so VendorProductMatch picks it up automatically going forward.
        $aliasStmt = $db->prepare("
            SELECT ocr_value, corrected_value FROM receipt_parse_lessons
            WHERE vendor_id = ? AND field_name = 'line_item_name' AND times_seen >= 3
        ");
        $aliasStmt->execute([$vendorId]);
        foreach ($aliasStmt->fetchAll(PDO::FETCH_ASSOC) as $correction) {
            $correctedUpper = strtoupper(trim($correction['corrected_value']));
            $ocrLower       = strtolower(trim($correction['ocr_value']));
            $prodStmt = $db->prepare("SELECT id, ocr_aliases FROM vendor_products WHERE vendor_id = ? AND UPPER(name) = ?");
            $prodStmt->execute([$vendorId, $correctedUpper]);
            $product = $prodStmt->fetch(PDO::FETCH_ASSOC);
            if ($product) {
                $existingProdAliases = array_map('strtolower', array_filter(explode(',', $product['ocr_aliases'] ?? '')));
                if (!in_array($ocrLower, $existingProdAliases, true)) {
                    $newAliases = trim(($product['ocr_aliases'] ?? '') . ',' . $ocrLower, ',');
                    $db->prepare("UPDATE vendor_products SET ocr_aliases = ? WHERE id = ?")->execute([$newAliases, $product['id']]);
                }
            }
        }

        // Apply derived patterns to profile
        if (!empty($updates)) {
            $setClauses = [];
            $params = [];
            foreach ($updates as $col => $val) {
                $setClauses[] = "$col = ?";
                $params[] = $val;
            }
            $params[] = $vendorId;

            $sql = "UPDATE vendor_parse_profiles SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE vendor_id = ?";
            $db->prepare($sql)->execute($params);
        }
    } catch (Throwable $e) {
        error_log('deriveVendorPatterns error: ' . $e->getMessage());
    }
}


/**
 * Extract OCR context around a field for pattern learning.
 */
function extractOcrContext(string $ocrText, string $fieldName, ?string $ocrVal, ?string $userVal): ?string
{
    $searchVal = $userVal ?? $ocrVal;
    if (empty($searchVal)) return null;

    // Find the value in the OCR text and grab surrounding lines
    $lines = preg_split('/\r?\n/', $ocrText);
    $lineCount = count($lines);

    for ($i = 0; $i < $lineCount; $i++) {
        if (stripos($lines[$i], $searchVal) !== false) {
            $start = max(0, $i - 2);
            $end = min($lineCount - 1, $i + 2);
            return implode("\n", array_slice($lines, $start, $end - $start + 1));
        }
    }

    // For amounts, search by the numeric value
    if (in_array($fieldName, ['total', 'gst', 'subtotal'])) {
        $amount = preg_quote($searchVal, '/');
        for ($i = 0; $i < $lineCount; $i++) {
            if (preg_match('/' . $amount . '/', $lines[$i])) {
                $start = max(0, $i - 2);
                $end = min($lineCount - 1, $i + 2);
                return implode("\n", array_slice($lines, $start, $end - $start + 1));
            }
        }
    }

    return null;
}


/**
 * Try to extract a date using a known format.
 */
function extractDateWithFormat(string $text, string $format): ?string
{
    switch ($format) {
        case 'DD/MM/YY':
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2})(?!\d)/', $text, $m)) {
                $y = (int)$m[3] + 2000;
                return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
            }
            break;
        case 'MM/DD/YY':
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2})(?!\d)/', $text, $m)) {
                $y = (int)$m[3] + 2000;
                return sprintf('%04d-%02d-%02d', $y, (int)$m[1], (int)$m[2]);
            }
            break;
        case 'DD/MM/YYYY':
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $text, $m)) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            }
            break;
        case 'YYYY-MM-DD':
            if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
                return $m[0];
            }
            break;
    }
    return null;
}


/**
 * Normalize a field value for comparison (trim, lowercase amounts to 2dp, etc.)
 */
function normalizeFieldValue(string $fieldName, ?string $value): ?string
{
    if ($value === null || $value === '') return null;

    $value = trim($value);

    // Normalize amounts to 2 decimal places
    if (in_array($fieldName, ['total', 'gst', 'subtotal', ''])) {
        if (preg_match('/^\d+\.?\d*$/', $value)) {
            return number_format((float)$value, 2, '.', '');
        }
    }

    return $value;
}


// ═══════════════════════════════════════════════════════════════════════
// Temporal Accuracy Tracking
// ═══════════════════════════════════════════════════════════════════════

/**
 * Get accuracy trend for a vendor over time (monthly buckets).
 *
 * Returns an array of monthly accuracy data, showing how parsing accuracy
 * has changed. Useful for detecting vendor receipt format changes.
 *
 * @param int      $vendorId
 * @param PDO|null $db
 * @return array Array of [{month, total_fields, corrections, accuracy_rate}]
 */
function getAccuracyTrend(int $vendorId, ?PDO $db = null): array
{
    if ($db === null) $db = getDB();

    try {
        // Get all lessons for this vendor grouped by month
        $stmt = $db->prepare("
            SELECT
                DATE_FORMAT(created_at, '%Y-%m') AS month,
                COUNT(*) AS total_fields,
                SUM(CASE WHEN ocr_value != corrected_value THEN 1 ELSE 0 END) AS corrections,
                ROUND(
                    (1 - SUM(CASE WHEN ocr_value != corrected_value THEN 1 ELSE 0 END) / COUNT(*)) * 100,
                    1
                ) AS accuracy_rate
            FROM receipt_parse_lessons
            WHERE vendor_id = ?
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([$vendorId]);
        $trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Detect format changes: if accuracy drops >20% between months
        for ($i = 1; $i < count($trend); $i++) {
            $prev = (float)($trend[$i - 1]['accuracy_rate'] ?? 0);
            $curr = (float)($trend[$i]['accuracy_rate'] ?? 0);

            $trend[$i]['accuracy_change'] = round($curr - $prev, 1);
            $trend[$i]['format_change_detected'] = ($prev - $curr) > 20;
        }

        return $trend;
    } catch (\Throwable $e) {
        error_log('getAccuracyTrend error: ' . $e->getMessage());
        return [];
    }
}


/**
 * Check if a vendor's parsing accuracy has recently degraded,
 * which could indicate a receipt format change.
 *
 * @param int      $vendorId
 * @param PDO|null $db
 * @return array|null Warning info or null if stable
 */
function checkAccuracyDegradation(int $vendorId, ?PDO $db = null): ?array
{
    $trend = getAccuracyTrend($vendorId, $db);

    if (count($trend) < 2) return null;

    $latest = end($trend);
    $previous = prev($trend);

    $latestRate = (float)($latest['accuracy_rate'] ?? 0);
    $prevRate = (float)($previous['accuracy_rate'] ?? 0);

    // Flag if accuracy dropped >20 percentage points
    if (($prevRate - $latestRate) > 20) {
        return [
            'vendor_id'       => $vendorId,
            'previous_rate'   => $prevRate,
            'current_rate'    => $latestRate,
            'drop'            => round($prevRate - $latestRate, 1),
            'previous_month'  => $previous['month'],
            'current_month'   => $latest['month'],
            'message'         => sprintf(
                'Parsing accuracy dropped from %.0f%% to %.0f%% — possible receipt format change',
                $prevRate, $latestRate
            ),
        ];
    }

    return null;
}


/**
 * Return the Tesseract confidence threshold for a specific vendor.
 *
 * High-accuracy vendors get a lower threshold (trust Tesseract more → fewer Vision API calls).
 * Low-accuracy vendors get a higher threshold (prefer Vision for better extraction).
 * Uses the manually-set tesseract_threshold from vendor_parse_profiles if present;
 * otherwise auto-derives from accuracy history once 5+ receipts have been processed.
 *
 * @param int|null $vendorId
 * @return int Threshold 0–100; default 70
 */
function getVendorTesseractThreshold(?int $vendorId): int
{
    if (!$vendorId) return 70;

    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT tesseract_threshold, accuracy_rate, total_receipts
            FROM vendor_parse_profiles WHERE vendor_id = ?
        ");
        $stmt->execute([$vendorId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) return 70;

        // Use manually-set threshold if present
        if ($profile['tesseract_threshold'] !== null) {
            return (int)$profile['tesseract_threshold'];
        }

        // Auto-derive from accuracy history — need 5+ receipts for reliable stats
        $rate  = (float)($profile['accuracy_rate']  ?? 0);
        $count = (int)($profile['total_receipts'] ?? 0);

        if ($count >= 5) {
            if ($rate >= 80) return 55; // High accuracy: trust Tesseract more, skip Vision sooner
            if ($rate <  50) return 80; // Low accuracy: force Vision more often
        }
    } catch (Throwable $e) {
        error_log('getVendorTesseractThreshold: ' . $e->getMessage());
    }

    return 70; // Safe default
}
