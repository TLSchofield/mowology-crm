<?php
/**
 * /app/Services/Receipts/ReceiptLearning.php
 * Self-Improving Receipt Parser — Learning Engine
 *
 * Records user corrections to OCR results and builds per-vendor parsing profiles
 * that improve accuracy over time.
 *
 * Two-phase system:
 *   1. Recording — line-item lessons at every save (recordLineItemLessons(), identity-
 *      keyed); header/category lessons once per receipt when it is confirmed
 *      (learnFromConfirmedExpense(), capture baseline vs approved values).
 *   2. applyLearnedPatterns() — called during parsing, applies vendor-specific rules
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
 *   storeCaptureBaseline($db, $expenseId, $input['ocr_parsed']);   // on create
 *   recordLineItemLessons($db, $vendorId, $vendorName, $input);      // on every save
 *   learnFromConfirmedExpense($db, $expenseId);                      // on approve/send
 *   $enhanced = applyLearnedPatterns($vendorId, $parsed, $ocrText);
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Record corrections the user made to OCR-parsed values — header fields, category
 * and line items together. Kept for callers that already hold a trustworthy
 * original; the save/approve paths now use the two halves below separately.
 *
 * @param int|null    $vendorId   Matched vendor ID (null if unknown)
 * @param string|null $vendorName Vendor name (for unmatched vendors)
 * @param array       $ocrParsed  What the user was shown at capture
 * @param array       $userSaved  What the user actually saved
 * @param string      $ocrText    Raw OCR text (for context extraction)
 */
function recordCorrections(?int $vendorId, ?string $vendorName, array $ocrParsed, array $userSaved, string $ocrText): void
{
    $db = getDB();
    $hadLineItemCorrections = recordLineItemLessons($db, $vendorId, $vendorName, $userSaved);
    recordHeaderLessons($db, $vendorId, $vendorName, $ocrParsed, $userSaved, $ocrText, $hadLineItemCorrections);
}

/**
 * Line-item lessons from a save payload. Each item carries the parser's original
 * name (`ocr_name`) plus `removed` / `manual` flags from the review card, so these
 * are identity-keyed and safe to record at every save — unlike header lessons.
 *
 * @return bool Whether any line-item correction was recorded
 */
function recordLineItemLessons(PDO $db, ?int $vendorId, ?string $vendorName, array $userSaved, ?int $expenseId = null): bool
{
    // A vendor's earliest corrections are often recorded before it has a vendor_id (the
    // receipt wasn't matched yet), landing in the vendor_id-IS-NULL bucket keyed by name.
    // Once this receipt resolves to a real vendor, fold any prior orphaned lessons for that
    // name in — otherwise they're stranded forever and the vendor's learning restarts at
    // zero the moment it gets linked, which defeats the point for exactly the vendors that
    // most need the early lessons.
    if ($vendorId && !empty($vendorName)) {
        adoptOrphanedLessons($db, $vendorId, $vendorName);
    }

    if (!$vendorId) {
        return false;
    }
    $userItems = $userSaved['line_items'] ?? [];
    if (is_string($userItems)) {
        $userItems = json_decode($userItems, true) ?: [];
    }
    if (!is_array($userItems)) {
        return false;
    }
    // A receipt with a capture baseline learns its line items once, at confirmation
    // (learnFromConfirmedExpense) — here only the SKU memory is updated, so re-saves
    // don't re-count the same rename.
    $lessons = !($expenseId && expenseHasCaptureBaseline($db, $expenseId));
    return recordLineItemCorrectionsFromPayload($db, $vendorId, $vendorName, $userItems, $lessons) > 0;
}

/**
 * Which header fields differ between what the user was shown and what was kept.
 * Pure — the decision half of recordHeaderLessons(), unit tested.
 *
 * Category is compared only when the original carries a suggestion: with nothing
 * suggested there is nothing to correct, and logging null→X lessons (as the old
 * re-parse baseline did on every save) teaches nothing.
 *
 * @param array $original Capture-time extraction (parsed keys: total, gst, subtotal, date, vendor_hint, accounting_category)
 * @param array $final    Saved values (expense keys: total, gst_amount, amount, expense_date, accounting_category)
 * @return array{fields: array<string, array{ocr: mixed, user: mixed, corrected: bool}>, category: ?array{ocr: string, user: string}}
 */
function headerCorrections(array $original, array $final, ?string $vendorName): array
{
    $pairs = [
        'total'    => [$original['total'] ?? null,       $final['total'] ?? null],
        'gst'      => [$original['gst'] ?? null,         $final['gst_amount'] ?? null],
        'subtotal' => [$original['subtotal'] ?? null,    $final['amount'] ?? null],
        'date'     => [$original['date'] ?? null,        $final['expense_date'] ?? null],
        'vendor'   => [$original['vendor_hint'] ?? null, $vendorName],
    ];

    $fields = [];
    foreach ($pairs as $field => [$ocrVal, $userVal]) {
        if (empty($ocrVal) && empty($userVal)) continue;
        $corrected = $field === 'vendor'
            ? !sameVendorName($ocrVal === null ? null : (string)$ocrVal, $userVal === null ? null : (string)$userVal)
            : normalizeFieldValue($field, $ocrVal === null ? null : (string)$ocrVal)
              !== normalizeFieldValue($field, $userVal === null ? null : (string)$userVal);
        $fields[$field] = ['ocr' => $ocrVal, 'user' => $userVal, 'corrected' => $corrected];
    }

    $suggested = trim((string)($original['accounting_category'] ?? $original['suggested_accounting_category'] ?? ''));
    $kept      = trim((string)($final['accounting_category'] ?? ''));
    $category  = ($suggested !== '' && $kept !== '' && $suggested !== $kept)
        ? ['ocr' => $suggested, 'user' => $kept]
        : null;

    return ['fields' => $fields, 'category' => $category];
}

/**
 * Does the receipt's printed vendor name refer to the vendor the user kept?
 * The header reads "HOME DEPOT #7012" where the CRM says "Home Depot", so a plain
 * string compare made nearly every approval a vendor "correction" — which dragged
 * the vendor's accuracy rate down and forced needless Vision re-scans.
 */
function sameVendorName(?string $printed, ?string $kept): bool
{
    $norm = static function (?string $v): string {
        $v = strtoupper((string)$v);
        $v = preg_replace('/(#|NO\.?|STORE)\s*\d+/', ' ', $v);          // store numbers
        $v = preg_replace('/\b(INC|LTD|LIMITED|CORP|CO|THE)\b\.?/', ' ', $v);
        return preg_replace('/[^A-Z0-9]/', '', $v);
    };
    $a = $norm($printed);
    $b = $norm($kept);
    if ($a === '' || $b === '') {
        return $a === $b;
    }
    if ($a === $b) {
        return true;
    }
    // Containment only for real names — "CO" must not match "COSTCO".
    return min(strlen($a), strlen($b)) >= 4 && (str_contains($a, $b) || str_contains($b, $a));
}

/**
 * Header + category lessons: original (what the user was shown at capture) vs
 * final (what was confirmed). Called once per receipt by learnFromConfirmedExpense().
 */
function recordHeaderLessons(PDO $db, ?int $vendorId, ?string $vendorName, array $original, array $final, string $ocrText, bool $hadCorrections = false): void
{
    $diff = headerCorrections($original, $final, $vendorName);

    $fieldMap = [];
    foreach ($diff['fields'] as $fieldName => $d) {
        // Strings throughout: JSON baselines decode amounts as floats, and this file is
        // strict_types, so a float reaching normalizeFieldValue() would throw.
        $ocrVal  = $d['ocr'] === null ? null : (string)$d['ocr'];
        $userVal = $d['user'] === null ? null : (string)$d['user'];
        $fieldMap[$fieldName] = ['ocr' => $ocrVal, 'user' => $userVal];
        if (!$d['corrected']) continue;
        $hadCorrections = true;

        $existing = findExistingLesson($db, $vendorId, $fieldName, $ocrVal, $userVal);
        if ($existing) {
            $db->prepare("UPDATE receipt_parse_lessons SET times_seen = times_seen + 1, updated_at = NOW() WHERE id = ?")
               ->execute([$existing['id']]);
        } else {
            $db->prepare("
                INSERT INTO receipt_parse_lessons
                    (vendor_id, vendor_name, field_name, ocr_value, corrected_value, ocr_context)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $vendorId, $vendorName, $fieldName, $ocrVal, $userVal,
                extractOcrContext($ocrText, $fieldName, $ocrVal, $userVal),
            ]);
        }
    }

    // ── Accounting category corrections ────────────────────────────────
    // After 2+ identical corrections, the learned category overrides vendor default.
    if ($vendorId && $diff['category']) {
        [$ocrCategory, $userCategory] = [$diff['category']['ocr'], $diff['category']['user']];
        $hadCorrections = true;
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

    // Update vendor parse profile stats
    if ($vendorId) {
        updateVendorProfile($db, $vendorId, $fieldMap, $hadCorrections);
    }
}

/** Have migration 1123's columns been added? Cached per request. */
function receiptLearningBaselineReady(PDO $db): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = $db->query("SHOW COLUMNS FROM expenses LIKE 'learning_recorded_at'")->rowCount() > 0;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * The capture-time extraction as the learning baseline: the parser output the
 * client echoed back, with the suggested category folded in when the client sent
 * it separately (only the desktop review panel merged it before).
 */
function captureBaseline($ocrParsed, ?string $suggestedCategory = null): ?array
{
    $parsed = is_string($ocrParsed) ? json_decode($ocrParsed, true) : $ocrParsed;
    if (!is_array($parsed) || $parsed === []) {
        return null;
    }
    if (empty($parsed['accounting_category']) && !empty($parsed['suggested_accounting_category'])) {
        $parsed['accounting_category'] = $parsed['suggested_accounting_category'];
    }
    if (empty($parsed['accounting_category']) && $suggestedCategory) {
        $parsed['accounting_category'] = $suggestedCategory;
    }
    return $parsed;
}

/**
 * The intake response's `parsed`, carrying the suggested category as
 * `suggested_accounting_category`. Clients echo `parsed` back verbatim as
 * `ocr_parsed` on save; only the desktop panel used to merge the suggestion in, so
 * mobile and offline receipts never had a category baseline to learn from.
 */
function withSuggestedCategory(array $parsed, array $suggestions): array
{
    if (!empty($suggestions['accounting_category']) && empty($parsed['suggested_accounting_category'])) {
        $parsed['suggested_accounting_category'] = (string)$suggestions['accounting_category'];
    }
    return $parsed;
}

/**
 * Store what the user was shown at capture, once. Never overwrites — a later
 * rescan or edit must not move the baseline the receipt is judged against.
 */
function storeCaptureBaseline(PDO $db, int $expenseId, $ocrParsed): void
{
    $baseline = captureBaseline($ocrParsed);
    if (!$baseline || !receiptLearningBaselineReady($db)) {
        return;
    }
    $db->prepare("UPDATE expenses SET ocr_parsed_json = ? WHERE id = ? AND ocr_parsed_json IS NULL")
       ->execute([json_encode($baseline), $expenseId]);
}

/**
 * Map an expense row's own values onto the parsed-key shape — the baseline for
 * receipts whose extraction was written straight into the row (emailed receipts),
 * captured before an approver's edits are applied.
 */
function baselineFromExpenseRow(array $row): array
{
    return [
        'total'               => $row['total'] ?? null,
        'gst'                 => $row['gst_amount'] ?? null,
        'subtotal'            => $row['amount'] ?? null,
        'date'                => $row['expense_date'] ?? null,
        'vendor_hint'         => $row['vendor_name_raw'] ?? null,
        'accounting_category' => $row['accounting_category'] ?? null,
    ];
}

/** Was this receipt captured with a stored baseline (migration 1123)? */
function expenseHasCaptureBaseline(PDO $db, int $expenseId): bool
{
    if (!receiptLearningBaselineReady($db)) {
        return false;
    }
    try {
        $stmt = $db->prepare("SELECT ocr_parsed_json IS NOT NULL FROM expenses WHERE id = ?");
        $stmt->execute([$expenseId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Line-item corrections between the items captured and the items kept.
 * Pure — the decision half of the confirmation-time line-item learning, unit tested.
 *
 * Kept items carry the parser's original name in `ocr_name` (empty = typed by hand).
 * A captured item with no kept row was removed as "not an item".
 *
 * @param array $baselineItems Captured items (each with 'name')
 * @param array $keptItems     Stored rows (each with 'name', 'ocr_name')
 * @return array{parsed: int, lessons: list<array{0: string, 1: ?string, 2: ?string}>}
 */
function lineItemCorrections(array $baselineItems, array $keptItems): array
{
    $unclaimed = [];   // captured names not yet matched to a kept row (multiset)
    $parsed = 0;
    foreach ($baselineItems as $it) {
        $n = is_array($it) ? strtoupper(trim((string)($it['name'] ?? ''))) : '';
        if ($n === '') continue;
        $parsed++;
        $unclaimed[$n] = ($unclaimed[$n] ?? 0) + 1;
    }

    $lessons = [];
    foreach ($keptItems as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $ocr  = trim((string)($row['ocr_name'] ?? ''));
        if ($ocr === '') {
            if ($name !== '') $lessons[] = ['line_item_missed', null, $name];
            continue;
        }
        $key = strtoupper($ocr);
        if (!empty($unclaimed[$key])) $unclaimed[$key]--;
        if ($name !== '' && strtoupper($name) !== $key) {
            $lessons[] = ['line_item_name', $ocr, $name];
        }
    }
    foreach ($unclaimed as $n => $left) {
        for ($i = 0; $i < $left; $i++) {
            $lessons[] = ['line_item_noise', (string)$n, null];
        }
    }
    return ['parsed' => $parsed, 'lessons' => $lessons];
}

/** Confirmation-time line-item lessons: captured items vs the rows the user kept. */
function recordLineItemLessonsAtConfirmation(PDO $db, int $expenseId, int $vendorId, ?string $vendorName, array $baselineItems): void
{
    if (!function_exists('expenseLineItemsHasColumn')) {
        require_once __DIR__ . '/ExpenseLineItems.php';
    }
    // Without ocr_name (migration 1115) every kept row would look hand-typed and every
    // captured item "removed" — skip rather than teach nonsense.
    if (!expenseLineItemsHasColumn($db, 'ocr_name')) {
        return;
    }
    $stmt = $db->prepare("SELECT name, ocr_name FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");
    $stmt->execute([$expenseId]);
    $diff = lineItemCorrections($baselineItems, $stmt->fetchAll(PDO::FETCH_ASSOC));
    foreach ($diff['lessons'] as [$type, $ocr, $corrected]) {
        recordLineItemLesson($db, $vendorId, $vendorName, $type, $ocr, $corrected);
    }
    if ($diff['parsed'] > 0 || $diff['lessons']) {
        updateLineItemProfileStats($db, $vendorId, $diff['parsed'], count($diff['lessons']));
    }
}

/**
 * Record header lessons for a receipt that has just been confirmed (approved, or
 * sent to accounting). Once per receipt: original-vs-confirmed, never re-counted on
 * a re-save. Receipts with no stored baseline (pre-1123, or never OCR'd) are
 * skipped rather than diffed against a re-parse that isn't what the user saw.
 *
 * Non-critical: never throws — learning must not block an approval.
 *
 * @param array|null $fallbackBaseline Used when ocr_parsed_json is empty (emailed receipts)
 * @return bool Whether lessons were recorded
 */
function learnFromConfirmedExpense(PDO $db, int $expenseId, ?array $fallbackBaseline = null): bool
{
    try {
        if (!receiptLearningBaselineReady($db)) {
            return false;
        }
        $stmt = $db->prepare("
            SELECT e.*, v.name AS vendor_name
            FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.id = ?
        ");
        $stmt->execute([$expenseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['learning_recorded_at'] !== null
            || !in_array($row['status'], ['approved', 'forwarded'], true)) {
            return false;
        }

        $baseline = captureBaseline($row['ocr_parsed_json'] ?? null) ?? $fallbackBaseline;
        if (!$baseline) {
            return false;   // nothing trustworthy to compare against; leave unclaimed
        }

        // Claim the receipt first so a concurrent approve/send can't double-count it.
        $claim = $db->prepare("UPDATE expenses SET learning_recorded_at = NOW() WHERE id = ? AND learning_recorded_at IS NULL");
        $claim->execute([$expenseId]);
        if ($claim->rowCount() === 0) {
            return false;
        }

        $vendorId   = $row['vendor_id'] !== null ? (int)$row['vendor_id'] : null;
        $vendorName = $row['vendor_name_raw'] ?: ($row['vendor_name'] ?? null);
        recordHeaderLessons($db, $vendorId, $vendorName, $baseline, $row, ocrTextFromStored($row['raw_ocr_json'] ?? null));
        if ($vendorId && !empty($row['ocr_parsed_json']) && is_array($baseline['line_items'] ?? null)) {
            recordLineItemLessonsAtConfirmation($db, $expenseId, $vendorId, $vendorName, $baseline['line_items']);
        }
        // Where the receipt was photographed teaches where this vendor's store is.
        if ($vendorId && is_numeric($row['receipt_lat'] ?? null) && is_numeric($row['receipt_lng'] ?? null)
            && (float)$row['receipt_lat'] != 0.0) {
            require_once __DIR__ . '/ReceiptSmartMatch.php';
            learnStoreLocation($db, $vendorId, (float)$row['receipt_lat'], (float)$row['receipt_lng']);
        }
        return true;
    } catch (Throwable $e) {
        error_log('Receipt learning error (confirm #' . $expenseId . '): ' . $e->getMessage());
        return false;
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

    // ── Apply learned line-item knowledge ──────────────────────────────
    // Noise removal, learned renames, and SKU → product auto-linking.
    if (!empty($parsed['line_items'])) {
        $parsed = applyLineItemLearning($vendorId, $parsed);
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
 * Reassign vendor_id-IS-NULL lessons whose vendor_name matches this now-resolved vendor,
 * so corrections recorded before the receipt was matched aren't stranded once it is.
 * Matched case/whitespace-insensitively since vendor_name is free-typed at correction time.
 * Idempotent: after the first run there are no more NULL rows left for this name.
 */
function adoptOrphanedLessons(PDO $db, int $vendorId, string $vendorName): void
{
    try {
        $stmt = $db->prepare("
            UPDATE receipt_parse_lessons
            SET vendor_id = ?, updated_at = NOW()
            WHERE vendor_id IS NULL AND UPPER(TRIM(vendor_name)) = UPPER(TRIM(?))
        ");
        $stmt->execute([$vendorId, $vendorName]);
    } catch (Throwable $e) {
        error_log('adoptOrphanedLessons error (non-fatal): ' . $e->getMessage());
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

        // ── Line-item patterns (barcode length, noise lines, accuracy) ──
        $updates = array_merge($updates, deriveLineItemPatterns($db, $vendorId, $pf ?: null));

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
        $stmt = $db->prepare("SELECT * FROM vendor_parse_profiles WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) return 70;

        // Line-item accuracy is a second, independent signal: a vendor whose header
        // fields Tesseract reads fine but whose item lines it mangles still deserves
        // Vision (bounding boxes → position-aware line reconstruction). Applied on
        // top of any manual/derived header threshold.
        $liCount = (int)($profile['line_item_receipts'] ?? 0);
        $liRate  = $profile['line_item_accuracy'] !== null ? (float)$profile['line_item_accuracy'] : null;
        $liFloor = ($liCount >= 5 && $liRate !== null && $liRate < 60) ? 80 : 0;

        // Use manually-set threshold if present
        if ($profile['tesseract_threshold'] !== null) {
            return max((int)$profile['tesseract_threshold'], $liFloor);
        }

        // Auto-derive from accuracy history — need 5+ receipts for reliable stats
        $rate  = (float)($profile['accuracy_rate']  ?? 0);
        $count = (int)($profile['total_receipts'] ?? 0);

        if ($count >= 5) {
            if ($rate >= 80) return max(55, $liFloor); // High accuracy: trust Tesseract more, skip Vision sooner
            if ($rate <  50) return 80;                // Low accuracy: force Vision more often
        }
        if ($liFloor) return $liFloor;
    } catch (Throwable $e) {
        error_log('getVendorTesseractThreshold: ' . $e->getMessage());
    }

    return 70; // Safe default
}


// ============================================================================
//  Line-item learning
//
//  Everything below is what makes the parser's "self-learning" cover line items.
//  Signals come from three places: the review card (rename / "not an item" /
//  manually-added rows, sent as ocr_name / removed / manual flags on each item),
//  the post-save line-item editor (ExpenseLineItemService), and product linking
//  (SKU → product memory). They're applied on the next scan by
//  applyLineItemLearning(), which applyLearnedPatterns() calls.
// ============================================================================

/**
 * Upsert one lesson row (times_seen++ when the same ocr→corrected pair recurs).
 */
function recordLineItemLesson(PDO $db, int $vendorId, ?string $vendorName, string $type, ?string $ocrValue, ?string $correctedValue): void
{
    $ocrValue       = $ocrValue !== null ? strtoupper(trim($ocrValue)) : null;
    $correctedValue = $correctedValue !== null ? trim($correctedValue) : null;
    if ($ocrValue === '' ) $ocrValue = null;
    if ($correctedValue === '') $correctedValue = null;
    if ($ocrValue === null && $correctedValue === null) return;

    try {
        $existing = findExistingLesson($db, $vendorId, $type, $ocrValue, $correctedValue);
        if ($existing) {
            $db->prepare("UPDATE receipt_parse_lessons SET times_seen = times_seen + 1, updated_at = NOW() WHERE id = ?")
               ->execute([$existing['id']]);
        } else {
            $db->prepare("INSERT INTO receipt_parse_lessons (vendor_id, vendor_name, field_name, ocr_value, corrected_value, ocr_context) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$vendorId, $vendorName, $type, $ocrValue, $correctedValue, trim(($ocrValue ?? '∅') . ' → ' . ($correctedValue ?? '∅'))]);
        }
    } catch (Throwable $e) {
        error_log('recordLineItemLesson: ' . $e->getMessage());
    }
}


/**
 * Record corrections from a saved line-item payload (review-card save on any client).
 *
 * Each item may carry: name, ocr_name (parser's original), removed (bool, "not an
 * item"), manual (bool, typed by the user), sku_raw, product_id.
 *
 * @return int Number of corrections recorded.
 */
function recordLineItemCorrectionsFromPayload(PDO $db, int $vendorId, ?string $vendorName, array $items, bool $lessons = true): int
{
    $parsedCount = 0;
    $corrections = 0;

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $name    = trim((string)($item['name'] ?? ''));
        $ocrName = trim((string)($item['ocr_name'] ?? ''));
        $removed = !empty($item['removed']);
        $manual  = !empty($item['manual']);

        if ($ocrName !== '') {
            $parsedCount++;
        }

        if (!$lessons) {
            // SKU memory only (below).
        } elseif ($removed && $ocrName !== '') {
            recordLineItemLesson($db, $vendorId, $vendorName, 'line_item_noise', $ocrName, null);
            $corrections++;
        } elseif ($manual && $name !== '' && $ocrName === '') {
            recordLineItemLesson($db, $vendorId, $vendorName, 'line_item_missed', null, $name);
            $corrections++;
        } elseif ($ocrName !== '' && $name !== '' && strtoupper($ocrName) !== strtoupper($name)) {
            recordLineItemLesson($db, $vendorId, $vendorName, 'line_item_name', $ocrName, $name);
            $corrections++;
        }

        // SKU memory — every save teaches the SKU→name pair; a product link teaches the mapping.
        $sku = trim((string)($item['sku_raw'] ?? ''));
        if (!$removed && $sku !== '') {
            recordSkuLink($db, $vendorId, $sku, !empty($item['product_id']) ? (int)$item['product_id'] : null, null, $name ?: null);
        }
    }

    if ($lessons && ($parsedCount > 0 || $corrections > 0)) {
        updateLineItemProfileStats($db, $vendorId, $parsedCount, $corrections);
    }

    return $corrections;
}


/**
 * Roll one reviewed receipt into the vendor's line-item accuracy stats.
 * Accuracy is a receipt-weighted running mean of (accepted / parsed) per receipt.
 */
function updateLineItemProfileStats(PDO $db, int $vendorId, int $parsedCount, int $corrections): void
{
    try {
        $stmt = $db->prepare("SELECT id, line_item_receipts, line_item_accuracy FROM vendor_parse_profiles WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $pf = $stmt->fetch(PDO::FETCH_ASSOC);

        $thisAccuracy = $parsedCount > 0
            ? max(0, min(100, round((($parsedCount - min($parsedCount, $corrections)) / $parsedCount) * 100, 2)))
            : null;

        if ($pf) {
            $n   = (int)$pf['line_item_receipts'];
            $old = $pf['line_item_accuracy'] !== null ? (float)$pf['line_item_accuracy'] : null;
            $new = $thisAccuracy === null ? $old
                 : ($old === null ? $thisAccuracy : round((($old * $n) + $thisAccuracy) / ($n + 1), 2));
            $db->prepare("
                UPDATE vendor_parse_profiles
                SET line_item_receipts = line_item_receipts + ?,
                    line_item_corrections = line_item_corrections + ?,
                    line_item_accuracy = ?,
                    updated_at = NOW()
                WHERE vendor_id = ?
            ")->execute([$parsedCount > 0 ? 1 : 0, $corrections, $new, $vendorId]);
        } else {
            $db->prepare("
                INSERT INTO vendor_parse_profiles (vendor_id, total_receipts, total_corrections, line_item_receipts, line_item_corrections, line_item_accuracy)
                VALUES (?, 0, 0, ?, ?, ?)
            ")->execute([$vendorId, $parsedCount > 0 ? 1 : 0, $corrections, $thisAccuracy]);
        }
    } catch (Throwable $e) {
        // Columns arrive with migration 1115 — never fatal before it runs.
        error_log('updateLineItemProfileStats: ' . $e->getMessage());
    }
}


/**
 * Remember a SKU/barcode for a vendor. A product_id makes it an auto-link rule for
 * the next receipt; without one it still records the SKU→name pair (so the
 * barcode-length pattern can be derived and a later link has context).
 */
function recordSkuLink(PDO $db, int $vendorId, string $skuRaw, ?int $productId, ?int $vendorProductId, ?string $itemName): void
{
    $skuRaw = trim($skuRaw);
    if ($skuRaw === '' || strlen($skuRaw) > 64) return;
    try {
        $db->prepare("
            INSERT INTO vendor_product_skus (vendor_id, sku_raw, product_id, vendor_product_id, item_name, times_seen, last_seen_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                times_seen        = times_seen + 1,
                product_id        = COALESCE(VALUES(product_id), product_id),
                vendor_product_id = COALESCE(VALUES(vendor_product_id), vendor_product_id),
                item_name         = COALESCE(VALUES(item_name), item_name),
                last_seen_at      = NOW()
        ")->execute([$vendorId, $skuRaw, $productId, $vendorProductId, $itemName !== null ? mb_substr($itemName, 0, 255) : null]);
    } catch (Throwable $e) {
        // Table arrives with migration 1115 — never fatal before it runs.
        error_log('recordSkuLink: ' . $e->getMessage());
    }
}


/**
 * Everything the parser and the LLM tier know about a vendor's line items.
 * Cached per request.
 *
 * @return array{noise: string[], name_map: array<string,string>, skus: array<string,int>,
 *               aliases: array<string,int>, barcode_length: ?int, discount_prefix: ?string,
 *               known_names: string[]}
 */
function getVendorLineItemProfile(?int $vendorId): array
{
    static $cache = [];
    $empty = ['noise' => [], 'name_map' => [], 'skus' => [], 'aliases' => [], 'barcode_length' => null, 'discount_prefix' => null, 'known_names' => []];
    if (!$vendorId) return $empty;
    if (isset($cache[$vendorId])) return $cache[$vendorId];

    $out = $empty;
    try {
        $db = getDB();

        $stmt = $db->prepare("SELECT field_name, ocr_value, corrected_value FROM receipt_parse_lessons WHERE vendor_id = ? AND field_name IN ('line_item_noise','line_item_name') AND times_seen >= 2");
        $stmt->execute([$vendorId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = strtoupper(trim((string)$row['ocr_value']));
            if ($key === '') continue;
            if ($row['field_name'] === 'line_item_noise') {
                $out['noise'][] = $key;
            } elseif (!empty($row['corrected_value'])) {
                $out['name_map'][$key] = $row['corrected_value'];
            }
        }

        try {
            $stmt = $db->prepare("SELECT sku_raw, product_id FROM vendor_product_skus WHERE vendor_id = ? AND product_id IS NOT NULL");
            $stmt->execute([$vendorId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out['skus'][trim($row['sku_raw'])] = (int)$row['product_id'];
            }
        } catch (Throwable $e) { /* pre-migration */ }

        $stmt = $db->prepare("SELECT name, ocr_aliases, product_id FROM vendor_products WHERE vendor_id = ? AND is_active = 1 AND product_id IS NOT NULL");
        $stmt->execute([$vendorId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)$row['product_id'];
            $out['aliases'][strtoupper(trim($row['name']))] = $pid;
            foreach (array_filter(array_map('trim', explode(',', (string)$row['ocr_aliases']))) as $alias) {
                $out['aliases'][strtoupper($alias)] = $pid;
            }
        }

        try {
            $pf = getVendorParseProfile($db, $vendorId);
            if ($pf) {
                $out['barcode_length']  = $pf['barcode_length'] !== null ? (int)$pf['barcode_length'] : null;
                $out['discount_prefix'] = $pf['discount_prefix'] ?: null;
                if (!empty($pf['noise_patterns'])) {
                    foreach (explode('|', $pf['noise_patterns']) as $n) {
                        $n = strtoupper(trim($n));
                        if ($n !== '' && !in_array($n, $out['noise'], true)) $out['noise'][] = $n;
                    }
                }
            }
        } catch (Throwable $e) { /* non-fatal */ }

        // The vendor's own purchase history is the best dictionary of what its receipts say.
        $stmt = $db->prepare("
            SELECT eli.name, COUNT(*) AS c
            FROM expense_line_items eli
            JOIN expenses e ON e.id = eli.expense_id
            WHERE e.vendor_id = ? AND eli.is_adjustment = 0
            GROUP BY eli.name
            ORDER BY c DESC, MAX(eli.id) DESC
            LIMIT 80
        ");
        $stmt->execute([$vendorId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $n = trim((string)$row['name']);
            if ($n !== '' && strlen($n) >= 3) $out['known_names'][] = $n;
        }
    } catch (Throwable $e) {
        error_log('getVendorLineItemProfile: ' . $e->getMessage());
    }

    return $cache[$vendorId] = $out;
}


/**
 * Apply per-vendor line-item knowledge to a parse result:
 *   1. drop lines the user has repeatedly marked "not an item"
 *   2. rename OCR misreads the user has repeatedly corrected
 *   3. auto-link product_id by SKU memory, then by catalog name/alias
 * Every surviving item carries `ocr_name` (the parser's original) so clients can
 * echo it back and later renames become lessons.
 */
function applyLineItemLearning(int $vendorId, array $parsed): array
{
    if (empty($parsed['line_items']) || !is_array($parsed['line_items'])) return $parsed;
    $p = getVendorLineItemProfile($vendorId);

    $kept = [];
    foreach ($parsed['line_items'] as $item) {
        $orig = trim((string)($item['name'] ?? ''));
        $key  = strtoupper($orig);
        if (!isset($item['ocr_name']) || $item['ocr_name'] === null || $item['ocr_name'] === '') {
            $item['ocr_name'] = $orig;
        }

        if ($key !== '' && empty($item['is_adjustment']) && in_array($key, $p['noise'], true)) {
            $parsed['line_items_noise_removed'] = ($parsed['line_items_noise_removed'] ?? 0) + 1;
            continue;
        }

        if ($key !== '' && isset($p['name_map'][$key])) {
            $item['name']        = $p['name_map'][$key];
            $item['name_source'] = 'learned';
        }

        if (empty($item['product_id'])) {
            $sku = trim((string)($item['sku_raw'] ?? ''));
            if ($sku !== '' && isset($p['skus'][$sku])) {
                $item['product_id']     = $p['skus'][$sku];
                $item['product_source'] = 'sku';
            } else {
                $nameKey = strtoupper(trim((string)$item['name']));
                if ($nameKey !== '' && isset($p['aliases'][$nameKey])) {
                    $item['product_id']     = $p['aliases'][$nameKey];
                    $item['product_source'] = 'alias';
                } elseif (strlen($nameKey) >= 4) {
                    foreach ($p['aliases'] as $alias => $pid) {
                        if (strlen($alias) >= 4 && (strpos($nameKey, $alias) !== false || strpos($alias, $nameKey) !== false)) {
                            $item['product_id']     = $pid;
                            $item['product_source'] = 'alias';
                            break;
                        }
                    }
                }
            }
        }

        $kept[] = $item;
    }
    $parsed['line_items'] = $kept;
    return $parsed;
}


/**
 * Derive line-item-shaped profile columns from accumulated history:
 *   barcode_length — modal digit-count of numeric SKUs seen for this vendor (≥3 samples)
 *   noise_patterns — "|"-joined names repeatedly marked "not an item"
 * Returns column => value pairs for deriveVendorPatterns() to persist.
 */
function deriveLineItemPatterns(PDO $db, int $vendorId, ?array $profileRow): array
{
    $updates = [];
    try {
        $stmt = $db->prepare("
            SELECT LENGTH(eli.sku_raw) AS len, COUNT(*) AS c
            FROM expense_line_items eli
            JOIN expenses e ON e.id = eli.expense_id
            WHERE e.vendor_id = ? AND eli.sku_raw REGEXP '^[0-9]+$'
            GROUP BY LENGTH(eli.sku_raw)
            ORDER BY c DESC
            LIMIT 1
        ");
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['c'] >= 3 && (int)$row['len'] >= 6 && (int)$row['len'] <= 15) {
            $updates['barcode_length'] = (int)$row['len'];
        }

        $stmt = $db->prepare("SELECT ocr_value FROM receipt_parse_lessons WHERE vendor_id = ? AND field_name = 'line_item_noise' AND times_seen >= 2 ORDER BY times_seen DESC LIMIT 40");
        $stmt->execute([$vendorId]);
        $noise = array_values(array_unique(array_filter(array_map(function ($v) {
            return strtoupper(trim((string)$v));
        }, $stmt->fetchAll(PDO::FETCH_COLUMN)))));
        if (!empty($noise)) {
            $updates['noise_patterns'] = mb_substr(implode('|', $noise), 0, 2000);
        }
    } catch (Throwable $e) {
        error_log('deriveLineItemPatterns: ' . $e->getMessage());
    }
    return $updates;
}


/**
 * expenses.raw_ocr_json holds plain OCR text — except after a rescan, when it holds
 * the JSON-encoded Vision response. Re-parsing that blob as text produced garbage
 * header lessons. Returns the plain text either way ('' if unrecoverable).
 */
function ocrTextFromStored(?string $raw): string
{
    if ($raw === null) return '';
    $t = ltrim($raw);
    if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) {
        return $raw;
    }
    $decoded = json_decode($t, true);
    if (!is_array($decoded)) return '';
    if (!empty($decoded['fullTextAnnotation']['text'])) {
        return (string)$decoded['fullTextAnnotation']['text'];
    }
    if (!empty($decoded['responses'][0]['fullTextAnnotation']['text'])) {
        return (string)$decoded['responses'][0]['fullTextAnnotation']['text'];
    }
    if (!empty($decoded['text']) && is_string($decoded['text'])) {
        return $decoded['text'];
    }
    return '';
}
