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
require_once __DIR__ . '/ReceiptParser.php';   // receiptDateOrder() for the date-order lesson

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
    // PST: a stored 0.00 means "none", the same as nothing printed — not a correction.
    $pstVal = static function ($v) {
        return ($v === null || $v === '' || (is_numeric($v) && (float)$v == 0.0)) ? null : $v;
    };
    $pairs = [
        'total'    => [$original['total'] ?? null,       $final['total'] ?? null],
        'gst'      => [$original['gst'] ?? null,         $final['gst_amount'] ?? null],
        'pst'      => [$pstVal($original['pst'] ?? null), $pstVal($final['pst_amount'] ?? null)],
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
    return min(strlen($a), strlen($b)) >= 4 && (strpos($a, $b) !== false || strpos($b, $a) !== false);
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

        addFieldLesson($db, $vendorId, $vendorName, $fieldName, $ocrVal, $userVal, $ocrText);
    }

    // The store's day/month order, from confirmed facts only (see dateOrderLesson()).
    $dateOrder = dateOrderLesson($ocrText, isset($final['expense_date']) ? (string)$final['expense_date'] : null);
    if ($vendorId && $dateOrder !== null) {
        addFieldLesson($db, $vendorId, $vendorName, 'date_order', null, $dateOrder, $ocrText);
    }

    // ── Accounting category corrections ────────────────────────────────
    // After 2+ identical corrections, the learned category overrides vendor default.
    if ($vendorId && $diff['category']) {
        $hadCorrections = true;
        addCategoryLesson($db, $vendorId, $vendorName, $diff['category']['ocr'], $diff['category']['user']);
    }

    // Update vendor parse profile stats
    if ($vendorId) {
        updateVendorProfile($db, $vendorId, $fieldMap, $hadCorrections);
    }
}

/**
 * The day/month order a store prints dates in, when this confirmed receipt shows it:
 * the receipt itself proves it (a 13-31 in either slot), or its ambiguous printed date was
 * approved as exactly one of its two readings. Pure — unit tested.
 *
 * @return string|null 'dmy' | 'mdy', or null when nothing was shown
 */
function dateOrderLesson(string $ocrText, ?string $approvedDate): ?string
{
    $proven = receiptDateOrder($ocrText);
    if ($proven !== null) return $proven;
    $printed = receiptAmbiguousDate($ocrText);
    if ($printed === null || $approvedDate === null) return null;
    [$a, $b, $y] = $printed;
    $approved = substr(trim($approvedDate), 0, 10);
    $mdy = sprintf('%04d-%02d-%02d', $y, $a, $b);
    $dmy = sprintf('%04d-%02d-%02d', $y, $b, $a);
    if ($approved === $dmy) return 'dmy';
    if ($approved === $mdy) return 'mdy';
    return null;
}

/** One header correction (parser read -> person kept): seen once more, or recorded the first time. */
function addFieldLesson(PDO $db, ?int $vendorId, ?string $vendorName, string $fieldName, ?string $ocrVal, ?string $userVal, string $ocrText): void
{
    $existing = findExistingLesson($db, $vendorId, $fieldName, $ocrVal, $userVal);
    if ($existing) {
        $db->prepare("UPDATE receipt_parse_lessons SET times_seen = times_seen + 1, updated_at = NOW() WHERE id = ?")
           ->execute([$existing['id']]);
        return;
    }
    $db->prepare("
        INSERT INTO receipt_parse_lessons
            (vendor_id, vendor_name, field_name, ocr_value, corrected_value, ocr_context)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $vendorId, $vendorName, $fieldName, $ocrVal, $userVal,
        extractOcrContext($ocrText, $fieldName, $ocrVal, $userVal),
    ]);
}

/** A category correction: recorded, and promoted to the vendor's default after 2+ identical ones. */
function addCategoryLesson(PDO $db, int $vendorId, ?string $vendorName, string $ocrCategory, string $userCategory): void
{
    $existing = findExistingLesson($db, $vendorId, 'accounting_category', $ocrCategory, $userCategory);
    if ($existing) {
        $db->prepare("UPDATE receipt_parse_lessons SET times_seen = times_seen + 1, updated_at = NOW() WHERE id = ?")
           ->execute([$existing['id']]);
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
        return;
    }
    $db->prepare("INSERT INTO receipt_parse_lessons (vendor_id, vendor_name, field_name, ocr_value, corrected_value, ocr_context) VALUES (?, ?, 'accounting_category', ?, ?, '')")
       ->execute([$vendorId, $vendorName, $ocrCategory, $userCategory]);
}

/** Takes a category correction back; a promoted default that no longer has two sightings behind it goes too. */
function forgetCategoryLesson(PDO $db, int $vendorId, string $ocrCategory, string $userCategory): void
{
    forgetFieldLesson($db, $vendorId, 'accounting_category', $ocrCategory, $userCategory);
    $left = findExistingLesson($db, $vendorId, 'accounting_category', $ocrCategory, $userCategory);
    if (!$left || (int)$left['times_seen'] < 2) {
        $db->prepare("UPDATE vendor_parse_profiles SET learned_accounting_category = NULL,
                          category_correction_count = GREATEST(category_correction_count - 1, 0), updated_at = NOW()
                      WHERE vendor_id = ? AND learned_accounting_category = ?")->execute([$vendorId, $userCategory]);
    }
}

/** Takes one sighting back (a lesson the desk overturned); the last one removes the row. */
function forgetFieldLesson(PDO $db, ?int $vendorId, string $fieldName, ?string $ocrVal, ?string $userVal): void
{
    $existing = findExistingLesson($db, $vendorId, $fieldName, $ocrVal, $userVal);
    if (!$existing) return;
    if ((int)$existing['times_seen'] <= 1) {
        $db->prepare("DELETE FROM receipt_parse_lessons WHERE id = ?")->execute([$existing['id']]);
    } else {
        $db->prepare("UPDATE receipt_parse_lessons SET times_seen = times_seen - 1, updated_at = NOW() WHERE id = ?")
           ->execute([$existing['id']]);
    }
}

/** Does this table have the column? Cached per request (a migration may not have run yet). */
function receiptTableHasColumn(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try {
            $cache[$key] = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column))->rowCount() > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/** The header values a phone save taught, kept so the desk's approval can confirm or overturn them. */
function phoneLessonSnapshot(array $row, ?string $vendorName): array
{
    return [
        'vendor_id' => $row['vendor_id'] !== null ? (int)$row['vendor_id'] : null,
        'total' => $row['total'] ?? null, 'gst_amount' => $row['gst_amount'] ?? null,
        'pst_amount' => $row['pst_amount'] ?? null, 'amount' => $row['amount'] ?? null,
        'expense_date' => $row['expense_date'] ?? null, 'accounting_category' => $row['accounting_category'] ?? null,
        'vendor' => $vendorName,
    ];
}

/**
 * Pure: what the desk's approval changes about the lessons a phone save already taught.
 * Unchanged -> nothing (the approval confirms the read). A field the desk changed -> the phone's
 * lesson for it is taken back and the desk's recorded instead; the net change in corrected fields
 * moves the vendor's accuracy the same way.
 *
 * @return array{forget: array<string, array{ocr: ?string, user: ?string}>, add: array<string, array{ocr: ?string, user: ?string}>, delta: int, old_corrections: int, new_corrections: int}
 */
function amendmentPlan(array $baseline, array $snapshot, array $final, ?string $vendorName, bool $vendorMoved = false): array
{
    $str = static function ($v): ?string { return $v === null ? null : (string)$v; };
    $diffS = headerCorrections($baseline, $snapshot, $snapshot['vendor'] ?? $vendorName);
    $diffD = headerCorrections($baseline, $final, $vendorName);
    $forget = [];
    $add = [];
    $delta = 0;
    $oldN = 0;
    $newN = 0;
    foreach (array_unique(array_merge(array_keys($diffS['fields']), array_keys($diffD['fields']))) as $field) {
        $s = $diffS['fields'][$field] ?? ['ocr' => null, 'user' => null, 'corrected' => false];
        $d = $diffD['fields'][$field] ?? ['ocr' => null, 'user' => null, 'corrected' => false];
        $same = $field === 'vendor'
            ? sameVendorName($str($s['user']), $str($d['user']))
            : normalizeFieldValue($field, $str($s['user'])) === normalizeFieldValue($field, $str($d['user']));
        if ($same && !$vendorMoved) continue;   // a moved vendor takes every lesson with it
        if ($s['corrected']) $forget[$field] = ['ocr' => $str($s['ocr']), 'user' => $str($s['user'])];
        if ($d['corrected']) $add[$field] = ['ocr' => $str($d['ocr']), 'user' => $str($d['user'])];
        $delta += ($d['corrected'] ? 1 : 0) - ($s['corrected'] ? 1 : 0);
        $oldN += $s['corrected'] ? 1 : 0;
        $newN += $d['corrected'] ? 1 : 0;
    }
    $sc = $diffS['category'];
    $dc = $diffD['category'];
    if ($vendorMoved || ($sc['user'] ?? null) !== ($dc['user'] ?? null)) {
        if ($sc) $forget['accounting_category'] = ['ocr' => $sc['ocr'], 'user' => $sc['user']];
        if ($dc) $add['accounting_category'] = ['ocr' => $dc['ocr'], 'user' => $dc['user']];
    }
    return ['forget' => $forget, 'add' => $add, 'delta' => $delta, 'old_corrections' => $oldN, 'new_corrections' => $newN];
}

/**
 * The desk approves a receipt a phone save already taught: once, compare what was taught with what
 * was approved and move the lessons and the vendor's accuracy to match (owner, 2026-10-28:
 * approval confirms the phone's read, a correction goes the other way).
 */
function amendPhoneLessons(PDO $db, array $row, ?array $fallbackBaseline): bool
{
    $own = false;
    try {
        if (!receiptTableHasColumn($db, 'expenses', 'learning_snapshot')) return false;
        if (!$db->inTransaction()) { $db->beginTransaction(); $own = true; }
        // Lock the row: a second approval path waits, then finds the snapshot gone.
        $lock = $db->prepare("SELECT learning_snapshot FROM expenses WHERE id = ? FOR UPDATE");
        $lock->execute([(int)$row['id']]);
        $raw = (string)$lock->fetchColumn();
        if ($raw === '') { if ($own) $db->rollBack(); return false; }

        $snapshot = json_decode($raw, true);
        $baseline = captureBaseline($row['ocr_parsed_json'] ?? null) ?? $fallbackBaseline;
        if (is_array($snapshot) && $baseline) {
            $vendorId   = $row['vendor_id'] !== null ? (int)$row['vendor_id'] : null;
            $oldVendor  = isset($snapshot['vendor_id']) ? (int)$snapshot['vendor_id'] : $vendorId;
            $moved      = $oldVendor !== $vendorId;
            $vendorName = $row['vendor_name_raw'] ?: ($row['vendor_name'] ?? null);
            $plan = amendmentPlan($baseline, $snapshot, $row, $vendorName, $moved);
            $ocrText = ocrTextFromStored($row['raw_ocr_json'] ?? null);
            foreach ($plan['forget'] as $field => $v) {
                if ($field === 'accounting_category') { if ($oldVendor) forgetCategoryLesson($db, $oldVendor, (string)$v['ocr'], (string)$v['user']); }
                else forgetFieldLesson($db, $oldVendor, $field, $v['ocr'], $v['user']);
            }
            foreach ($plan['add'] as $field => $v) {
                if ($field === 'accounting_category') { if ($vendorId) addCategoryLesson($db, $vendorId, $vendorName, (string)$v['ocr'], (string)$v['user']); }
                else addFieldLesson($db, $vendorId, $vendorName, $field, $v['ocr'], $v['user'], $ocrText);
            }
            if ($moved) {
                if ($oldVendor) adjustVendorCorrections($db, $oldVendor, -$plan['old_corrections']);
                if ($vendorId) adjustVendorCorrections($db, $vendorId, $plan['new_corrections']);
            } elseif ($vendorId) {
                adjustVendorCorrections($db, $vendorId, $plan['delta']);
            }
            if ($vendorId && ($plan['forget'] || $plan['add'])) deriveVendorPatterns($db, $vendorId);
            if ($oldVendor && $moved) deriveVendorPatterns($db, $oldVendor);
        }
        // Last: only a finished amendment uses the snapshot up.
        $db->prepare("UPDATE expenses SET learning_snapshot = NULL WHERE id = ?")->execute([(int)$row['id']]);
        if ($own) $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        error_log('Receipt learning amend #' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
        return false;
    }
}

/** Move a vendor's correction count (and accuracy) by what the desk changed, without counting the receipt twice. */
function adjustVendorCorrections(PDO $db, int $vendorId, int $delta): void
{
    if ($delta === 0) return;
    $stmt = $db->prepare("SELECT total_receipts, total_corrections FROM vendor_parse_profiles WHERE vendor_id = ?");
    $stmt->execute([$vendorId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return;
    $corrections = max(0, (int)$p['total_corrections'] + $delta);
    $fields = (int)$p['total_receipts'] * 5;
    $accuracy = $fields > 0 ? round((($fields - $corrections) / $fields) * 100, 2) : null;
    $db->prepare("UPDATE vendor_parse_profiles SET total_corrections = ?, accuracy_rate = ?, updated_at = NOW() WHERE vendor_id = ?")
       ->execute([$corrections, $accuracy, $vendorId]);
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
        'pst'                 => $row['pst_amount'] ?? null,
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
 * @param bool $allowPending An owner/manager's phone save (pending_approval) counts as the confirmation —
 *                           their corrections are the best signal and teach at once (owner, 2026-10-28)
 * @return bool Whether lessons were recorded
 */
/** Once per receipt: true for the caller that set learning_recorded_at. */
function claimReceiptLearning(PDO $db, int $expenseId): bool
{
    $claim = $db->prepare("UPDATE expenses SET learning_recorded_at = NOW() WHERE id = ? AND learning_recorded_at IS NULL");
    $claim->execute([$expenseId]);
    return $claim->rowCount() > 0;
}

/**
 * Pure: the SKU → name pairs an approved receipt confirms. Adjustments (discounts, deposits),
 * blank or over-long SKUs and nameless lines teach nothing.
 * @return array<int, array{sku: string, name: string, product_id: ?int}>
 */
function confirmedSkuPairs(array $items): array
{
    $out = [];
    $seen = [];
    foreach ($items as $it) {
        if (!is_array($it) || !empty($it['is_adjustment'])) continue;
        $sku = trim((string)($it['sku_raw'] ?? ''));
        $name = trim((string)($it['name'] ?? ''));
        if ($sku === '' || strlen($sku) > 64 || $name === '' || isset($seen[$sku])) continue;
        $seen[$sku] = true;
        $out[] = ['sku' => $sku, 'name' => mb_substr($name, 0, 255), 'product_id' => !empty($it['product_id']) ? (int)$it['product_id'] : null];
    }
    return $out;
}

/**
 * Approving a receipt confirms its item names (owner, 2026-10-11): each SKU line's name as
 * approved is remembered with times_confirmed + 1 (migration 1322), and the next receipt from
 * this vendor uses it (getVendorLineItemProfile 'sku_names'). Never fatal.
 */
function recordConfirmedSkuNames(PDO $db, int $expenseId, int $vendorId): int
{
    try {
        $stmt = $db->prepare("SELECT name, sku_raw, product_id, is_adjustment FROM expense_line_items WHERE expense_id = ?");
        $stmt->execute([$expenseId]);
        $pairs = confirmedSkuPairs($stmt->fetchAll(PDO::FETCH_ASSOC));
        $up = $db->prepare("
            INSERT INTO vendor_product_skus (vendor_id, sku_raw, product_id, item_name, times_seen, times_confirmed, last_seen_at)
            VALUES (?, ?, ?, ?, 1, 1, NOW())
            ON DUPLICATE KEY UPDATE
                -- Counts approvals of THIS name: a different name starts again at 1 (Codex review 2026-10-14).
                -- MySQL applies these left to right, so item_name here is still the old one.
                times_confirmed = IF(item_name <=> VALUES(item_name), times_confirmed + 1, 1),
                item_name       = VALUES(item_name),
                product_id      = COALESCE(VALUES(product_id), product_id),
                last_seen_at    = NOW()
        ");
        foreach ($pairs as $p) $up->execute([$vendorId, $p['sku'], $p['product_id'], $p['name']]);
        return count($pairs);
    } catch (Throwable $e) {
        error_log('recordConfirmedSkuNames #' . $expenseId . ': ' . $e->getMessage());   // pre-1322 / pre-1115
        return 0;
    }
}

function learnFromConfirmedExpense(PDO $db, int $expenseId, ?array $fallbackBaseline = null, bool $filedCopy = false, bool $allowPending = false): bool
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
        if (!$row) {
            return false;
        }
        // a filed copy of an already-booked receipt is rejected but its values are confirmed
        $confirmed = in_array($row['status'], $filedCopy ? ['approved', 'forwarded', 'rejected'] : ['approved', 'forwarded'], true);
        if ($row['learning_recorded_at'] !== null) {
            // Taught at the phone save; the desk's approval confirms it or overturns part of it.
            // A filed copy of an already-booked receipt counts too: the desk confirmed its values by filing it (2026-10-28).
            return $confirmed ? amendPhoneLessons($db, $row, $fallbackBaseline) : false;
        }
        if (!$confirmed && !($allowPending && $row['status'] === 'pending_approval')) {
            return false;
        }

        $baseline = captureBaseline($row['ocr_parsed_json'] ?? null) ?? $fallbackBaseline;
        $vendorId   = $row['vendor_id'] !== null ? (int)$row['vendor_id'] : null;
        if (!$baseline) {
            // No first read to compare against — but the approved item names are still right:
            // confirm their SKUs (once per receipt, same claim as the lessons).
            if ($vendorId && claimReceiptLearning($db, $expenseId)) {
                recordConfirmedSkuNames($db, $expenseId, $vendorId);
                return true;
            }
            return false;
        }

        // Claim the receipt first so a concurrent approve/send can't double-count it.
        if (!claimReceiptLearning($db, $expenseId)) {
            return false;
        }

        $vendorName = $row['vendor_name_raw'] ?: ($row['vendor_name'] ?? null);
        $phone = $row['status'] === 'pending_approval' && receiptTableHasColumn($db, 'expenses', 'learning_snapshot');
        try {
            // What the phone taught is kept first, so a claim is never left without its snapshot.
            if ($phone) {
                $db->prepare("UPDATE expenses SET learning_snapshot = ? WHERE id = ?")
                   ->execute([json_encode(phoneLessonSnapshot($row, $vendorName)), $expenseId]);
            }
            recordHeaderLessons($db, $vendorId, $vendorName, $baseline, $row, ocrTextFromStored($row['raw_ocr_json'] ?? null));
        } catch (Throwable $e) {
            // Give the claim back: a failed lesson must not stop the approval from teaching it later.
            $db->prepare("UPDATE expenses SET learning_recorded_at = NULL" . ($phone ? ", learning_snapshot = NULL" : "") . " WHERE id = ?")->execute([$expenseId]);
            throw $e;
        }
        if ($vendorId && !empty($row['ocr_parsed_json']) && is_array($baseline['line_items'] ?? null)) {
            recordLineItemLessonsAtConfirmation($db, $expenseId, $vendorId, $vendorName, $baseline['line_items']);
        }
        if ($vendorId) {
            recordConfirmedSkuNames($db, $expenseId, $vendorId);
        }
        // Where the receipt was photographed teaches where this vendor's store is.
        if ($vendorId && is_numeric($row['receipt_lat'] ?? null) && is_numeric($row['receipt_lng'] ?? null)
            && (float)$row['receipt_lat'] != 0.0) {
            require_once __DIR__ . '/ReceiptSmartMatch.php';
            learnStoreLocation($db, $vendorId, (float)$row['receipt_lat'], (float)$row['receipt_lng']);
        }
        // What is universal about this vendor goes to the commons (shared across tenants):
        // identity, receipt layout, SKU names, neutral category code, tax treatment. Never
        // amounts, jobs or cards. Fails soft — a slow or missing commons never blocks learning.
        if ($vendorId) {
            try {
                $kbFile = APP_ROOT . '/Modules/Knowledge/Services/VendorKnowledgeService.php';
                if (defined('APP_ROOT') && is_file($kbFile)) {
                    require_once $kbFile;
                    (new VendorKnowledgeService($db))->publishLesson($expenseId);
                }
            } catch (Throwable $kbE) {
                error_log('kb publish (confirm #' . $expenseId . '): ' . $kbE->getMessage());
            }
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
            if (!in_array($field, ['total', 'gst', 'pst', 'subtotal', 'line_item_name', 'accounting_category', 'date_order']) && strpos($field, 'line_item') !== 0) {
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
    $parsed = resolveLearnedCategory($parsed, $profile, $ocrText);

    // ── Learned header layouts (rung 2 of the ladder: rules → learned layout → AI) ──
    $parsed = applyHeaderLayouts($parsed, $ocrText, headerLayoutsFromProfile($profile));

    return $parsed;
}

/**
 * The vendor's learned category, unless the receipt itself says otherwise: a pump receipt is Fuel
 * whatever the vendor lesson says (Chevron taught Meals by twenty coffee receipts must not turn a
 * fill-up into Meals). Pure.
 */
function resolveLearnedCategory(array $parsed, array $profile, string $ocrText): array
{
    require_once dirname(__DIR__, 2) . '/Modules/Expenses/Services/ReceiptBookkeeperRules.php';
    if (!empty($profile['learned_accounting_category']) && (int)($profile['category_correction_count'] ?? 0) >= 2) {
        if (ReceiptBookkeeperRules::fuelType(strtoupper($ocrText)) !== null) {
            $parsed['accounting_category']        = 'Fuel';
            $parsed['accounting_category_source'] = 'content';
            return $parsed;
        }
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

        // ── Header layouts + date format, from confirmed corrections (>= 2 consistent) ──
        // Needs migration 1405's header_layouts column; without it nothing is written.
        if (vendorProfileHasColumn($db, 'header_layouts')) {
            $hl = $db->prepare("
                SELECT field_name, ocr_context, corrected_value, times_seen
                FROM receipt_parse_lessons
                WHERE vendor_id = ? AND field_name IN ('total','subtotal','gst','pst','date')
                  AND ocr_context IS NOT NULL AND ocr_context <> '' AND corrected_value IS NOT NULL
            ");
            $hl->execute([$vendorId]);
            $lessons = $hl->fetchAll(PDO::FETCH_ASSOC);
            $layouts = deriveHeaderLayouts($lessons);
            if ($layouts) $updates['header_layouts'] = json_encode($layouts);
            $fmt = deriveDateFormat($lessons);
            if ($fmt !== null) $updates['date_format'] = $fmt;
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
                    // Only a misread that can name a vendor — "date:", "amount", "push" were learned as Chevron's (2026-10-15).
                    if (!class_exists('VendorIdentity')) require_once dirname(__DIR__, 2) . '/Modules/Knowledge/Services/VendorIdentity.php';
                    if (!empty($mis) && VendorIdentity::usableAlias($mis) && !in_array($mis, $existingAliases, true)) {
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

    // Find the value in the OCR text and grab surrounding lines. An amount printed twice
    // ("Fuel sales $ 23.28" and "TOTAL / CAD$ 23.28") is taken where its own label is: the window
    // is what header-layout learning reads the label and position from.
    $lines = preg_split('/\r?\n/', $ocrText);
    $lineCount = count($lines);
    $hint = headerFieldLabelHint($fieldName);
    $hits = [];
    for ($i = 0; $i < $lineCount; $i++) {
        if (stripos($lines[$i], $searchVal) !== false) $hits[] = $i;
    }
    if ($hits && $hint !== null && count($hits) > 1) {
        foreach ($hits as $i) {
            $near = ($lines[max(0, $i - 2)] ?? '') . "\n" . ($lines[max(0, $i - 1)] ?? '') . "\n" . $lines[$i];
            if (preg_match($hint, $near)) {
                $hits = [$i];
                break;
            }
        }
    }
    if ($hits) {
        $i = $hits[0];
        $start = max(0, $i - 2);
        $end = min($lineCount - 1, $i + 2);
        return implode("\n", array_slice($lines, $start, $end - $start + 1));
    }

    // For amounts, search by the numeric value
    if (in_array($fieldName, ['total', 'gst', 'pst', 'subtotal'])) {
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
        case 'MM/DD/YYYY':
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $text, $m)) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[1], (int)$m[2]);
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
    if (in_array($fieldName, ['total', 'gst', 'pst', 'subtotal', ''])) {
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


/** The vendor's date order lesson with the most sightings; null when none, or when the other order ties it. */
function vendorDateOrderFromLessons(PDO $db, int $vendorId): ?string
{
    try {
        $stmt = $db->prepare("SELECT corrected_value, times_seen FROM receipt_parse_lessons WHERE vendor_id = ? AND field_name = 'date_order' AND times_seen >= 1");
        $stmt->execute([$vendorId]);
        $seen = ['dmy' => 0, 'mdy' => 0];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($seen[$row['corrected_value']])) $seen[$row['corrected_value']] += (int)$row['times_seen'];
        }
        return dateOrderFromCounts($seen['dmy'], $seen['mdy']);
    } catch (Throwable $e) {
        return null;
    }
}

/** Pure half of the above: the order with more sightings, null when none or tied. */
function dateOrderFromCounts(int $dmy, int $mdy): ?string
{
    if ($dmy === $mdy) return null;
    return $dmy > $mdy ? 'dmy' : 'mdy';
}

/**
 * Everything the parser and the LLM tier know about a vendor's line items.
 * Cached per request.
 *
 * @return array{noise: string[], name_map: array<string,string>, skus: array<string,int>,
 *               aliases: array<string,int>, barcode_length: ?int, discount_prefix: ?string,
 *               known_names: string[], date_order: ?string}
 */
function getVendorLineItemProfile(?int $vendorId): array
{
    static $cache = [];
    $empty = ['noise' => [], 'name_map' => [], 'skus' => [], 'sku_names' => [], 'aliases' => [], 'barcode_length' => null, 'discount_prefix' => null, 'known_names' => [], 'layout' => [], 'date_order' => null];
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

        // Names an approval confirmed for a SKU (migration 1322) — "FELCO4" → "Felco Pruner #4". Two approvals
        // before a name is trusted (2026-10-14: one approval of a one-off name applied it forever); names
        // last confirmed before then keep working until the row changes.
        try {
            $stmt = $db->prepare("SELECT sku_raw, item_name FROM vendor_product_skus WHERE vendor_id = ?
                                    AND (times_confirmed >= 2 OR (times_confirmed >= 1 AND updated_at < '2026-10-15'))
                                    AND item_name IS NOT NULL AND item_name <> ''");
            $stmt->execute([$vendorId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out['sku_names'][trim($row['sku_raw'])] = (string)$row['item_name'];
            }
        } catch (Throwable $e) { /* pre-1322 */ }

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

        // The store's day/month order: the most-confirmed one, none on a tie.
        $out['date_order'] = vendorDateOrderFromLessons($db, $vendorId);

        // The vendor's learned layout: items an owner accepted from Penny's read, located on the
        // receipt (recordLineLayoutLessons). One owner acceptance teaches it; newest first.
        $out['layout'] = getVendorLineLayout($db, $vendorId);

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

        // An item read by the learned layout is already the name the owner accepted.
        $fromLayout = ($item['name_source'] ?? '') === 'layout';

        if (!$fromLayout && $key !== '' && empty($item['is_adjustment']) && in_array($key, $p['noise'], true)) {
            $parsed['line_items_noise_removed'] = ($parsed['line_items_noise_removed'] ?? 0) + 1;
            continue;
        }

        if ($fromLayout) {
            // keep the taught name
        } elseif ($key !== '' && isset($p['name_map'][$key])) {
            $item['name']        = $p['name_map'][$key];
            $item['name_source'] = 'learned';
        } else {
            // A name the owner approved for this SKU before (vendor_product_skus.times_confirmed).
            $skuKey = trim((string)($item['sku_raw'] ?? ''));
            if ($skuKey !== '' && isset($p['sku_names'][$skuKey]) && strcasecmp($p['sku_names'][$skuKey], $orig) !== 0) {
                $item['name']        = $p['sku_names'][$skuKey];
                $item['name_source'] = 'confirmed';
            }
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


// ============================================================================
//  Reader taught by Penny (2026-10-13). When the owner accepts Penny's line items
//  over the reader's (her "Penny's read" panel on the phone, or her desk card), the
//  accepted items are located on the receipt text and stored as the vendor's layout
//  (receipt_parse_lessons field 'line_item_layout': ocr_value = the printed anchor's
//  key, corrected_value = the item name, learned_pattern = {"off","idx"} — where the
//  amount sits). parseReceiptText() reads the next same-layout receipt with it
//  (getVendorLineItemProfile()['layout']). Each case is also a head_lessons row
//  (source 'reader_corrected_by_penny') so the scorecard can count how often the
//  reader needs her.
// ============================================================================

/** The vendor's learned layout, newest first: [{key, name, off, idx}]. */
function getVendorLineLayout(PDO $db, int $vendorId): array
{
    try {
        $s = $db->prepare("SELECT ocr_value, corrected_value, learned_pattern FROM receipt_parse_lessons
                           WHERE vendor_id = ? AND field_name = 'line_item_layout' AND learned_pattern IS NOT NULL
                           ORDER BY updated_at DESC, id DESC LIMIT 60");
        $s->execute([$vendorId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pos = json_decode((string)$r['learned_pattern'], true);
            if (!is_array($pos) || !isset($pos['off'], $pos['idx']) || trim((string)$r['ocr_value']) === '' || trim((string)$r['corrected_value']) === '') continue;
            $out[] = ['key' => (string)$r['ocr_value'], 'name' => (string)$r['corrected_value'], 'off' => (int)$pos['off'], 'idx' => (int)$pos['idx']];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('getVendorLineLayout: ' . $e->getMessage());
        return [];
    }
}

/**
 * Store accepted items as this vendor's layout (one row per printed anchor, updated in place).
 * @param array $items [{name, line_total|amount}] — the items the owner accepted
 * @return int how many items were located on the receipt and stored
 */
function recordLineLayoutLessons(PDO $db, int $vendorId, ?string $vendorName, string $ocrText, array $items): int
{
    if (!function_exists('learnLineLayout')) {
        require_once __DIR__ . '/ReceiptParser.php';
    }
    if (trim($ocrText) === '' || !$items) return 0;
    $n = 0;
    foreach (learnLineLayout($ocrText, $items) as $l) {
        try {
            $pattern = json_encode(['off' => $l['off'], 'idx' => $l['idx']]);
            $context = 'layout: ' . $l['key'] . ' → ' . $l['name'] . ' (amount ' . ($l['off'] >= 0 ? '+' : '') . $l['off'] . ' line(s), #' . $l['idx'] . ' from the right)';
            $f = $db->prepare("SELECT id FROM receipt_parse_lessons WHERE vendor_id = ? AND field_name = 'line_item_layout' AND ocr_value = ? LIMIT 1");
            $f->execute([$vendorId, $l['key']]);
            $id = $f->fetchColumn();
            if ($id) {
                $db->prepare("UPDATE receipt_parse_lessons SET corrected_value = ?, learned_pattern = ?, pattern_confidence = 100, ocr_context = ?,
                                     times_seen = times_seen + 1, updated_at = NOW() WHERE id = ?")
                   ->execute([$l['name'], $pattern, $context, (int)$id]);
            } else {
                $db->prepare("INSERT INTO receipt_parse_lessons (vendor_id, vendor_name, field_name, ocr_value, corrected_value, ocr_context, learned_pattern, pattern_confidence)
                              VALUES (?, ?, 'line_item_layout', ?, ?, ?, ?, 100)")
                   ->execute([$vendorId, $vendorName, $l['key'], $l['name'], $context, $pattern]);
            }
            $n++;
        } catch (Throwable $e) {
            error_log('recordLineLayoutLessons: ' . $e->getMessage());
        }
    }
    return $n;
}

/**
 * Did the reader's items differ from the items kept? By amounts when the reader's carry
 * them (names are cosmetic), else by name. Pure.
 * @param array $reader [{name, amount|line_total}] or [string name, …]
 * @param array $kept   [{name, line_total|amount}]
 */
function readerItemsDiffer(array $reader, array $kept): bool
{
    $amt = function (array $items): ?array {
        $out = [];
        foreach ($items as $i) {
            if (!is_array($i)) return null;
            $v = $i['line_total'] ?? $i['amount'] ?? null;
            if ($v === null || !is_numeric($v)) return null;
            $out[] = number_format(round((float)$v, 2), 2, '.', '');
        }
        sort($out);
        return $out;
    };
    $a = $amt($reader);
    $b = $amt($kept);
    if ($a !== null && $b !== null) return $a !== $b;
    $names = function (array $items): array {
        $out = array_map(fn($i) => preg_replace('/[^A-Z0-9]/', '', strtoupper(is_array($i) ? (string)($i['name'] ?? '') : (string)$i)), $items);
        sort($out);
        return $out;
    };
    return $names($reader) !== $names($kept);
}

/** "CRF 0.07 $0.07; BISTRO $2.49" — items as one line for a lesson. Pure. */
function lineItemsSummary(array $items): string
{
    $parts = [];
    foreach ($items as $i) {
        if (!is_array($i)) { $parts[] = (string)$i; continue; }
        $v = $i['line_total'] ?? $i['amount'] ?? null;
        $parts[] = trim((string)($i['name'] ?? '')) . ($v !== null && is_numeric($v) ? ' $' . number_format((float)$v, 2, '.', '') : '');
    }
    return implode('; ', $parts);
}

/**
 * A receipt whose line items came from Penny (line_items_source 'penny') has just been approved:
 * when they differ from what the reader read, the owner has confirmed the reader was wrong —
 * teach the reader the layout and record the case (head_lessons). Once per approval (the gate
 * calls it on the move into approved). Never throws.
 * @return array{taught: bool, layout_lines: int}
 */
function teachReaderFromPenny(PDO $db, int $expenseId): array
{
    $none = ['taught' => false, 'layout_lines' => 0];
    try {
        $s = $db->prepare("SELECT e.*, v.name AS vendor_name FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e || ($e['line_items_source'] ?? '') !== 'penny' || !in_array($e['status'], ['approved', 'forwarded'], true) || empty($e['vendor_id'])) {
            return $none;
        }
        $k = $db->prepare("SELECT name, line_total FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");
        $k->execute([$expenseId]);
        $kept = $k->fetchAll(PDO::FETCH_ASSOC);
        if (!$kept) return $none;

        // What the reader read: its capture baseline (with amounts), else the names Penny was shown.
        $reader = null;
        $base = captureBaseline($e['ocr_parsed_json'] ?? null);
        if ($base && is_array($base['line_items'] ?? null)) $reader = $base['line_items'];
        if ($reader === null) {
            $c = $db->prepare("SELECT current_json FROM expense_suggestions WHERE expense_id = ? AND source = 'live' ORDER BY id DESC LIMIT 1");
            $c->execute([$expenseId]);
            $cur = json_decode((string)$c->fetchColumn(), true) ?: [];
            $reader = is_array($cur['line_items'] ?? null) ? $cur['line_items'] : [];
        }
        if (!readerItemsDiffer($reader, $kept)) return $none;

        $vendorId = (int)$e['vendor_id'];
        $vendorName = $e['vendor_name'] ?: ($e['vendor_name_raw'] ?? null);
        $n = recordLineLayoutLessons($db, $vendorId, $vendorName, ocrTextFromStored($e['raw_ocr_json'] ?? null), $kept);

        if (!class_exists('PennyLessons')) {
            require_once (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/Modules/Accounting/Services/PennyLessons.php';
        }
        PennyLessons::expense($db, $expenseId, 'reader_line_items', lineItemsSummary($reader) ?: '(none)', lineItemsSummary($kept),
                              'reader_corrected_by_penny', !empty($e['approved_by']) ? (int)$e['approved_by'] : null,
                              'Reader corrected by Penny, accepted by owner; ' . $n . ' line(s) of the layout learned');
        // The layout helps the vendor's waiting receipts too, not only the next one (Tim, 2026-10-15).
        if ($n > 0) {
            try {
                require_once (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/Modules/Expenses/Services/ReaderRelearnService.php';
                (new ReaderRelearnService($db))->queue($vendorId);
            } catch (Throwable $q) { error_log('Reader relearn queue: ' . $q->getMessage()); }
        }
        return ['taught' => true, 'layout_lines' => $n];
    } catch (Throwable $ex) {
        error_log('teachReaderFromPenny #' . $expenseId . ': ' . $ex->getMessage());
        return $none;
    }
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


// ============================================================================
//  Learned header layouts (2026-10-28) — where this vendor prints each header field.
//
//  A correction teaches STRUCTURE, never values: for total / subtotal / gst / pst the label text
//  (normalised), whether the amount sits on the label's line (same_line), the next line, or the line
//  after next, and the currency prefix ("CAD$"). Stored per vendor as JSON in
//  vendor_parse_profiles.header_layouts (migration 1405):
//      {"total":[{"label":"TOTAL","pos":"next_line","cur":"CAD$","seen":3}], ...}
//  A layout needs >= 2 consistent corrections. It is applied only when the generic extractor
//  returned nothing (or derived/estimated the field itself) or the printed sum fails — and it reads
//  the amount off the NEW receipt; a past amount is never replayed.
//
//  Teaching from a phone save: an owner's or manager's `pending_approval` save counts as the
//  confirmation (ExpenseGate opt `learn_now`, set by expense-save.php for the office roles) and runs
//  learnFromConfirmedExpense(..., allowPending: true) once per receipt (claimReceiptLearning). Crew
//  captures never do — they carry no corrections.
// ============================================================================

/** Does vendor_parse_profiles have this column? Cached per request. */
function vendorProfileHasColumn(PDO $db, string $column): bool
{
    static $cache = [];
    if (!array_key_exists($column, $cache)) {
        try {
            // No placeholder: MySQL rejects parameter markers in SHOW with native prepares, so header-layout
            // lessons were never stored from 2026-10-28 until this fix (2026-11-04).
            $cache[$column] = (bool)$db->query("SHOW COLUMNS FROM vendor_parse_profiles LIKE " . $db->quote($column))->fetch();
        } catch (Throwable $e) {
            $cache[$column] = false;
        }
    }
    return $cache[$column];
}

/** Regex of the labels a header field is printed under (null for fields without one). Pure. */
function headerFieldLabelHint(string $field): ?string
{
    switch ($field) {
        case 'total':    return '/(?<!SUB)TOTAL|AMOUNT|BALANCE|\bDUE\b|\bPAY\b/i';
        case 'subtotal': return '/SUB[\s\-]*TOTAL/i';
        case 'gst':      return '/GST|HST|TAX/i';
        case 'pst':      return '/PST|RST/i';
    }
    return null;
}

/** A line's label: letters and single spaces, upper case, currency words dropped ("GST INCLUDED $ 1.11" → "GST INCLUDED"). Pure. */
function headerLineLabel(string $line): string
{
    $l = strtoupper(preg_replace('/\$?\s*\d{1,6}[.,]\d{2}(?!\d)/', ' ', $line));
    $l = preg_replace('/\b(CAD|CDN|USD)\b/', ' ', (string)$l);
    $l = preg_replace('/[^A-Z]+/', ' ', (string)$l);
    return trim((string)preg_replace('/\s{2,}/', ' ', (string)$l));
}

/** The currency prefix printed just before the amount on a line: 'CAD$' | 'CDN$' | '$' | ''. Pure. */
function headerLineCurrency(string $line): string
{
    if (preg_match('/(CAD|CDN)?\s*(\$)?\s*\d{1,6}[.,]\d{2}(?!\d)/i', $line, $m)) {
        return strtoupper((string)($m[1] ?? '')) . (!empty($m[2]) ? '$' : '');
    }
    return '';
}

/**
 * Where does `$value` sit in a window of receipt lines, relative to its label? Pure.
 *
 * @return ?array{label: string, pos: string, cur: string}
 */
function observeHeaderLayout(string $field, string $contextText, string $value): ?array
{
    require_once __DIR__ . '/ReceiptParser.php';
    if (!is_numeric($value)) return null;
    $want  = round((float)$value, 2);
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $contextText)), static function ($l) { return $l !== ''; }));
    $hint  = headerFieldLabelHint($field);
    $found = [];
    foreach ($lines as $i => $line) {
        foreach (lineMoneyAmounts($line) as $a) {
            if (abs($a - $want) < 0.005) { $found[] = $i; break; }
        }
    }
    $candidates = [];
    foreach ($found as $i) {
        $own = headerLineLabel($lines[$i]);
        if ($own !== '') {
            $candidates[] = ['label' => $own, 'pos' => 'same_line', 'cur' => headerLineCurrency($lines[$i])];
            continue;
        }
        foreach ([1 => 'next_line', 2 => 'line_after_next'] as $back => $pos) {
            if ($i - $back < 0) break;
            $above = $lines[$i - $back];
            if (lineMoneyAmounts($above)) break;
            $lab = headerLineLabel($above);
            if ($lab !== '') {
                $candidates[] = ['label' => $lab, 'pos' => $pos, 'cur' => headerLineCurrency($lines[$i])];
                break;
            }
        }
    }
    if (!$candidates) return null;
    if ($hint !== null) {
        foreach ($candidates as $c) {
            if (preg_match($hint, $c['label'])) return $c;
        }
        return null;                        // the amount is there but under another label: teach nothing
    }
    return $candidates[0];
}

/**
 * Group lessons into layouts; keep those seen >= 2 times. Pure.
 *
 * @param list<array{field_name: string, ocr_context: ?string, corrected_value: ?string, times_seen?: int|string}> $lessons
 * @return array<string, list<array{label: string, pos: string, cur: string, seen: int}>>
 */
function deriveHeaderLayouts(array $lessons): array
{
    $groups = [];
    foreach ($lessons as $l) {
        $f = (string)($l['field_name'] ?? '');
        if (!in_array($f, ['total', 'subtotal', 'gst', 'pst'], true)) continue;
        $obs = observeHeaderLayout($f, (string)($l['ocr_context'] ?? ''), (string)($l['corrected_value'] ?? ''));
        if ($obs === null) continue;
        $key = $obs['label'] . '|' . $obs['pos'] . '|' . $obs['cur'];
        if (!isset($groups[$f][$key])) $groups[$f][$key] = $obs + ['seen' => 0];
        $groups[$f][$key]['seen'] += max(1, (int)($l['times_seen'] ?? 1));
    }
    $out = [];
    foreach ($groups as $f => $g) {
        $keep = array_values(array_filter($g, static function ($x) { return $x['seen'] >= 2; }));
        usort($keep, static function ($a, $b) { return $b['seen'] <=> $a['seen']; });
        if ($keep) $out[$f] = $keep;
    }
    return $out;
}

/**
 * The date format confirmed by >= 2 date corrections ('DD/MM/YYYY' etc., as extractDateWithFormat reads). Pure.
 */
function deriveDateFormat(array $lessons): ?string
{
    $counts = [];
    foreach ($lessons as $l) {
        if (($l['field_name'] ?? '') !== 'date') continue;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)($l['corrected_value'] ?? ''), $m)) continue;
        [$y, $mo, $d] = [$m[1], $m[2], $m[3]];
        $ctx = (string)($l['ocr_context'] ?? '');
        $y2 = substr($y, 2);
        $cands = [
            'YYYY-MM-DD' => "$y-$mo-$d",
            'DD/MM/YYYY' => "$d/$mo/$y",
            'MM/DD/YYYY' => "$mo/$d/$y",
            'DD/MM/YY'   => "$d/$mo/$y2",
            'MM/DD/YY'   => "$mo/$d/$y2",
        ];
        foreach ($cands as $fmt => $str) {
            if (preg_match('/(?<!\d)' . preg_quote($str, '/') . '(?!\d)/', $ctx) && $d !== $mo) {
                $counts[$fmt] = ($counts[$fmt] ?? 0) + max(1, (int)($l['times_seen'] ?? 1));
                break;
            }
        }
    }
    arsort($counts);
    foreach ($counts as $fmt => $n) {
        return $n >= 2 ? $fmt : null;
    }
    return null;
}

/** The profile's stored layouts as an array (empty when absent or unreadable). Pure. */
function headerLayoutsFromProfile(?array $profile): array
{
    $raw = $profile['header_layouts'] ?? null;
    if (!is_string($raw) || $raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/**
 * Read one amount off the text with a learned layout, or null. Pure.
 * same_line: label and amount on one line; next_line / line_after_next: the label stands alone and
 * the amount-only line follows (the line between may hold only a currency mark).
 */
function readHeaderAmountWithLayout(string $ocrText, array $layout, bool $last): ?string
{
    require_once __DIR__ . '/ReceiptParser.php';
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $ocrText)), static function ($l) { return $l !== ''; }));
    $label = (string)($layout['label'] ?? '');
    $pos   = (string)($layout['pos'] ?? '');
    if ($label === '') return null;
    $hit = null;
    foreach ($lines as $i => $line) {
        if (headerLineLabel($line) !== $label) continue;
        if ($pos === 'same_line') {
            $amts = lineMoneyAmounts($line);
            $j = $i;
        } else {
            if (lineMoneyAmounts($line)) continue;
            $j = $i + ($pos === 'line_after_next' ? 2 : 1);
            if ($pos === 'line_after_next' && (!isset($lines[$i + 1]) || lineMoneyAmounts($lines[$i + 1]) || headerLineLabel($lines[$i + 1]) !== '')) continue;
            $amts = isset($lines[$j]) && headerLineLabel($lines[$j]) === '' ? lineMoneyAmounts($lines[$j]) : [];
        }
        if (!$amts || $amts[count($amts) - 1] <= 0) continue;
        $hit = number_format($amts[count($amts) - 1], 2, '.', '');
        if (!$last) break;
    }
    return $hit;
}

/**
 * Fill header amounts from a vendor's learned layouts, only where the generic extractor failed:
 *  - a field that is empty, or that the parser derived/estimated itself, is read with the layout;
 *  - when the printed sum fails, the layouts' readings replace the four amounts, kept only if the sum then holds.
 * A value that was printed and passes the checks is never touched. Pure.
 *
 * @param array<string, list<array>> $layouts deriveHeaderLayouts() shape
 */
function applyHeaderLayouts(array $parsed, string $ocrText, array $layouts): array
{
    if (!$layouts || trim($ocrText) === '') return $parsed;
    require_once __DIR__ . '/ReceiptParser.php';
    require_once __DIR__ . '/ReceiptLineItemIntelligence.php';
    $empty = static function (array $p, string $f): bool {
        if ($p[$f] ?? null) return false;
        return true;
    };
    $weak = [
        'total'    => !empty($parsed['total_derived']),
        'subtotal' => !empty($parsed['subtotal_derived']),
        'gst'      => !empty($parsed['gst_estimated']),
        'pst'      => false,
    ];
    $read = static function (string $f) use ($layouts, $ocrText): ?string {
        foreach ($layouts[$f] ?? [] as $lay) {
            $v = readHeaderAmountWithLayout($ocrText, $lay, $f === 'total');
            if ($v !== null) return $v;
        }
        return null;
    };
    $changed = [];
    foreach (['total', 'subtotal', 'gst', 'pst'] as $f) {
        if (!($empty($parsed, $f) || $weak[$f])) continue;
        $v = $read($f);
        if ($v === null) continue;
        $parsed[$f] = $v;
        $parsed[$f . '_source'] = 'learned';
        unset($parsed[$f . '_derived']);
        if ($f === 'gst') { $parsed['gst_estimated'] = false; }
        $changed[] = $f;
    }
    // The printed sum fails: try the layouts' readings of all four, keep them only if the sum then holds.
    if (!$changed && in_array('math_fails', headersNeedBetterExtraction($parsed + ['ocr_text' => $ocrText]), true)) {
        $try = $parsed;
        foreach (['total', 'subtotal', 'gst', 'pst'] as $f) {
            $v = $read($f);
            if ($v !== null) $try[$f] = $v;
        }
        if (!in_array('math_fails', headersNeedBetterExtraction($try + ['ocr_text' => $ocrText]), true)) {
            foreach (['total', 'subtotal', 'gst', 'pst'] as $f) {
                if (($try[$f] ?? null) !== ($parsed[$f] ?? null)) { $try[$f . '_source'] = 'learned'; $changed[] = $f; }
            }
            $parsed = $try;
        }
    }
    if ($changed) $parsed['header_layout_fields'] = $changed;
    return $parsed;
}


/**
 * Router: how hard should this vendor's receipts be read? 'cheap' (rules, then AI only when a check
 * fails) or 'strong' (strongest read straight away and the header AI rung enabled up front).
 * Strong when the owner set read_mode = 'strong', or the vendor's header accuracy is poor
 * (accuracy_rate < 60 over 5+ receipts). An explicit owner 'cheap' wins over the accuracy rule.
 * Pure — depends only on the profile row, not on any OCR engine. Default cheap.
 */
function receiptReadMode(?array $profile): string
{
    if (!$profile) return 'cheap';
    $manual = strtolower(trim((string)($profile['read_mode'] ?? '')));
    if ($manual === 'strong' || $manual === 'cheap') return $manual;
    $rate  = $profile['accuracy_rate'] ?? null;
    $count = (int)($profile['total_receipts'] ?? 0);
    if ($rate !== null && $rate !== '' && is_numeric($rate) && (float)$rate < 60 && $count >= 5) {
        return 'strong';
    }
    return 'cheap';
}
