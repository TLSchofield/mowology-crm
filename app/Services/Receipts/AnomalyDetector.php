<?php
/**
 * /app/Services/Receipts/AnomalyDetector.php
 * Expense Anomaly Detection Engine
 *
 * Runs 10 rules against each expense on create/update and returns
 * triggered rule codes with a composite risk score (0-100).
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/AnomalyDetector.php';
 *   $result = detectAnomalies($expenseData, $db);
 *   // Returns: ['flags' => 'ROUND_NUMBER,GST_MISMATCH', 'score' => 20, 'details' => [...]]
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/**
 * Run all anomaly detection rules against an expense.
 *
 * @param array $expense Expense data (from create/update payload + computed fields)
 *   Required keys: total, gst_amount, pst_amount, amount, expense_date, vendor_id,
 *                  vendor_name_raw, accounting_category, receipt_media_id, receipt_lat,
 *                  receipt_lng, created_by, job_id
 * @param PDO   $db Database connection
 * @return array ['flags' => string, 'score' => int, 'details' => array]
 */
function detectAnomalies(array $expense, PDO $db): array
{
    $triggered = [];

    $rules = [
        'checkRoundNumber',
        'checkHighAmount',
        'checkDuplicateDay',
        'checkNoReceipt',
        'checkCategoryMismatch',
        'checkRapidSubmission',
        'checkSplitSuspicion',
        'checkGstMismatch',
        'checkWeekendExpense',
        'checkLocationMismatch',
        'checkFooting',          // line items add up to MORE than the receipt (2026-11-03)
        'checkCapitalAsset',     // equipment over the capitalization threshold: asset, not expense (2026-11-03)
    ];

    foreach ($rules as $ruleFn) {
        try {
            $result = $ruleFn($expense, $db);
            if ($result !== null) {
                $triggered[] = $result;
            }
        } catch (\Throwable $e) {
            error_log("AnomalyDetector {$ruleFn} error: " . $e->getMessage());
        }
    }

    if (empty($triggered)) {
        return ['flags' => '', 'score' => 0, 'details' => []];
    }

    // Score = max of all triggered rules
    $maxScore = 0;
    $codes = [];
    $details = [];

    foreach ($triggered as $t) {
        $codes[] = $t['code'];
        $maxScore = max($maxScore, $t['score']);
        $details[] = $t;
    }

    return [
        'flags'   => implode(',', $codes),
        'score'   => $maxScore,
        'details' => $details,
    ];
}


// ── Rule 1: Round Number ─────────────────────────────────────────────

function checkRoundNumber(array $e, PDO $db): ?array
{
    $total = (float)($e['total'] ?? 0);
    if ($total < 50) return null;

    // Check if total is a round number (ends in .00 and is divisible by 10)
    if (fmod($total, 10.0) === 0.0) {
        return [
            'code'   => 'ROUND_NUMBER',
            'score'  => 20,
            'detail' => 'Total is exactly $' . number_format($total, 2) . ' (round number)',
        ];
    }
    return null;
}


// ── Rule 2: High Amount vs Category Baseline ─────────────────────────

function checkHighAmount(array $e, PDO $db): ?array
{
    $total = (float)($e['total'] ?? 0);
    $category = $e['accounting_category'] ?? '';
    if ($total <= 0 || empty($category)) return null;

    $monthNum = (int)date('n');

    try {
        $stmt = $db->prepare("
            SELECT avg_total, stddev_total FROM expense_monthly_baselines
            WHERE accounting_category = ? AND month_num = ?
        ");
        $stmt->execute([$category, $monthNum]);
        $baseline = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$baseline || (float)$baseline['avg_total'] <= 0) return null;

        $avg = (float)$baseline['avg_total'];
        if ($total > $avg * 2) {
            return [
                'code'   => 'HIGH_AMOUNT',
                'score'  => 30,
                'detail' => sprintf(
                    '$%.2f is %.0f%% above the %s monthly average of $%.2f',
                    $total, (($total / $avg) - 1) * 100, $category, $avg
                ),
            ];
        }
    } catch (\Throwable $ex) {
        // Baseline table may not exist yet — silently skip
    }

    return null;
}


// ── Rule 3: Duplicate Day — Same vendor + same date ──────────────────

function checkDuplicateDay(array $e, PDO $db): ?array
{
    $vendorId = $e['vendor_id'] ?? null;
    $vendorName = $e['vendor_name_raw'] ?? '';
    $date = $e['expense_date'] ?? '';
    $excludeId = $e['id'] ?? null;

    if (empty($date)) return null;
    if (empty($vendorId) && empty($vendorName)) return null;

    try {
        $sql = "SELECT COUNT(*) FROM expenses WHERE expense_date = ?";
        $params = [$date];

        if ($vendorId) {
            $sql .= " AND vendor_id = ?";
            $params[] = $vendorId;
        } elseif ($vendorName) {
            $sql .= " AND vendor_name_raw = ?";
            $params[] = $vendorName;
        }

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();

        if ($count > 0) {
            $vName = $vendorName ?: 'this vendor';
            return [
                'code'   => 'DUPLICATE_DAY',
                'score'  => 25,
                'detail' => "Another expense at {$vName} exists on {$date}",
            ];
        }
    } catch (\Throwable $ex) {
        error_log('checkDuplicateDay: ' . $ex->getMessage());
    }

    return null;
}


// ── Rule 4: No Receipt Image ─────────────────────────────────────────

/** Below this total a missing receipt image is not worth a flag (parking, coffee) — plugin floor, Tim 2026-11-03. */
const ANOMALY_NO_RECEIPT_MIN_TOTAL = 25.0;

function checkNoReceipt(array $e, PDO $db): ?array
{
    $mediaId = $e['receipt_media_id'] ?? null;
    if ((float)($e['total'] ?? 0) < ANOMALY_NO_RECEIPT_MIN_TOTAL) return null;
    if (empty($mediaId)) {
        return [
            'code'   => 'NO_RECEIPT',
            'score'  => 15,
            'detail' => 'Expense has no receipt image attached',
        ];
    }
    return null;
}


// ── Rule 5: Category Mismatch — Vendor default ≠ selected ───────────

function checkCategoryMismatch(array $e, PDO $db): ?array
{
    $vendorId = $e['vendor_id'] ?? null;
    $selectedCat = $e['accounting_category'] ?? '';

    if (empty($vendorId) || empty($selectedCat)) return null;

    try {
        $stmt = $db->prepare("SELECT default_accounting_category FROM vendors WHERE id = ?");
        $stmt->execute([$vendorId]);
        $defaultCat = $stmt->fetchColumn();

        if ($defaultCat && strtolower($defaultCat) !== strtolower($selectedCat)) {
            return [
                'code'   => 'CATEGORY_MISMATCH',
                'score'  => 10,
                'detail' => "Selected '{$selectedCat}' but vendor's default is '{$defaultCat}'",
            ];
        }
    } catch (\Throwable $ex) {
        // Vendor table issue — skip
    }

    return null;
}


// ── Rule 6: Rapid Submission — Multiple expenses in 5 minutes ────────

function checkRapidSubmission(array $e, PDO $db): ?array
{
    $createdBy = $e['created_by'] ?? null;
    if (empty($createdBy)) return null;

    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM expenses
            WHERE created_by = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([$createdBy]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= 3) {
            return [
                'code'   => 'RAPID_SUBMISSION',
                'score'  => 20,
                'detail' => "{$count} expenses submitted in the last 5 minutes",
            ];
        }
    } catch (\Throwable $ex) {
        // Skip
    }

    return null;
}


// ── Rule 7: Split Suspicion — Two at same vendor within 1hr, sum >$200 ──

function checkSplitSuspicion(array $e, PDO $db): ?array
{
    $vendorId = $e['vendor_id'] ?? null;
    $total = (float)($e['total'] ?? 0);
    $date = $e['expense_date'] ?? '';
    $excludeId = $e['id'] ?? null;

    if (empty($vendorId) || empty($date)) return null;

    try {
        $sql = "
            SELECT total FROM expenses
            WHERE vendor_id = ?
              AND expense_date = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ";
        $params = [$vendorId, $date];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $otherTotals = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($otherTotals as $otherTotal) {
            $combinedSum = $total + (float)$otherTotal;
            if ($combinedSum > 200) {
                return [
                    'code'   => 'SPLIT_SUSPICION',
                    'score'  => 35,
                    'detail' => sprintf(
                        'Two expenses at same vendor within 1hr totalling $%.2f (possible split)',
                        $combinedSum
                    ),
                ];
            }
        }
    } catch (\Throwable $ex) {
        // Skip
    }

    return null;
}


// ── Rule 8: GST Mismatch — GST MORE than 5% of the subtotal ─────────
// GST below 5% is normal: zero-rated basic groceries (bottled water, snacks), exempt items and
// bottle deposits sit in the same subtotal (Chevron 2026-09-22: $0.12 GST on a $2.49 hot drink in
// a $15.57 basket was right, and was flagged). GST above 5% of the pre-tax subtotal cannot come
// from GST alone — a misread or PST read as GST — so only that is flagged (owner, 2026-10-11).

function checkGstMismatch(array $e, PDO $db): ?array
{
    $amount = (float)($e['amount'] ?? 0);
    $gst = (float)($e['gst_amount'] ?? 0);
    $total = (float)($e['total'] ?? 0);

    // Skip if no amounts or if vendor is GST-exempt
    if ($amount <= 0 || $total <= 0) return null;

    // Check if vendor is GST exempt
    $vendorId = $e['vendor_id'] ?? null;
    if ($vendorId) {
        try {
            $stmt = $db->prepare("SELECT gst_exempt FROM vendors WHERE id = ?");
            $stmt->execute([$vendorId]);
            if ((int)$stmt->fetchColumn() === 1) {
                // GST-exempt vendor — only flag if GST was charged
                if ($gst > 0) {
                    return [
                        'code'   => 'GST_MISMATCH',
                        'score'  => 20,
                        'detail' => 'GST charged at a GST-exempt vendor',
                    ];
                }
                return null;
            }
        } catch (\Throwable $ex) {
            // Skip vendor check
        }
    }

    // Expected GST = 5% of subtotal
    $expectedGst = round($amount * 0.05, 2);
    $tolerance = max(0.10, $amount * 0.005); // $0.10 or 0.5%, whichever is larger

    if ($gst > $expectedGst + $tolerance) {
        return [
            'code'   => 'GST_MISMATCH',
            'score'  => 20,
            'detail' => sprintf(
                'GST $%.2f is more than 5%% of subtotal $%.2f (at most ~$%.2f)',
                $gst, $amount, $expectedGst
            ),
        ];
    }

    return null;
}


// ── Rule 9: Weekend Expense ──────────────────────────────────────────

function checkWeekendExpense(array $e, PDO $db): ?array
{
    $date = $e['expense_date'] ?? '';
    if (empty($date)) return null;

    $dayOfWeek = (int)date('N', strtotime($date)); // 6=Sat, 7=Sun
    if ($dayOfWeek >= 6) {
        $dayName = $dayOfWeek === 6 ? 'Saturday' : 'Sunday';
        return [
            'code'   => 'WEEKEND_EXPENSE',
            'score'  => 15,
            'detail' => "Expense dated on {$dayName} ({$date})",
        ];
    }
    return null;
}


// ── Rule 10: Location Mismatch — GPS far from vendor + job ───────────

function checkLocationMismatch(array $e, PDO $db): ?array
{
    $lat = $e['receipt_lat'] ?? null;
    $lng = $e['receipt_lng'] ?? null;
    $vendorId = $e['vendor_id'] ?? null;
    $jobId = $e['job_id'] ?? null;

    if ($lat === null || $lng === null) return null;
    $lat = (float)$lat;
    $lng = (float)$lng;
    if ($lat == 0 && $lng == 0) return null;

    $farFromVendor = true;
    $farFromJob = true;

    // Check vendor locations
    if ($vendorId) {
        try {
            $stmt = $db->prepare("
                SELECT lat, lng FROM vendor_locations
                WHERE vendor_id = ? AND lat IS NOT NULL AND lng IS NOT NULL
            ");
            $stmt->execute([$vendorId]);
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($locations as $loc) {
                $dist = haversineDistanceAnomaly($lat, $lng, (float)$loc['lat'], (float)$loc['lng']);
                if ($dist <= 20) {
                    $farFromVendor = false;
                    break;
                }
            }
        } catch (\Throwable $ex) {
            $farFromVendor = false; // Can't check, don't flag
        }
    } else {
        $farFromVendor = false; // No vendor to compare
    }

    // Check job property location
    if ($jobId) {
        try {
            $stmt = $db->prepare("
                SELECT p.latitude, p.longitude
                FROM job_plans jp
                JOIN properties p ON jp.property_id = p.id
                WHERE jp.id = ? AND p.latitude IS NOT NULL
            ");
            $stmt->execute([$jobId]);
            $prop = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($prop) {
                $dist = haversineDistanceAnomaly($lat, $lng, (float)$prop['latitude'], (float)$prop['longitude']);
                if ($dist <= 20) {
                    $farFromJob = false;
                }
            } else {
                $farFromJob = false;
            }
        } catch (\Throwable $ex) {
            $farFromJob = false;
        }
    } else {
        $farFromJob = false;
    }

    if ($farFromVendor && $farFromJob) {
        return [
            'code'   => 'LOCATION_MISMATCH',
            'score'  => 25,
            'detail' => 'Receipt GPS is >20km from vendor and linked job',
        ];
    }

    return null;
}


/**
 * Local haversine to avoid dependency on ReceiptSmartMatch.php.
 */
function haversineDistanceAnomaly(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Threshold above which equipment is a fixed asset, not an expense. Asked once, kept in ops_settings (plugin coding rule). */
const ANOMALY_CAPITAL_DEFAULT = 500.0;   // the same $500 the receipt line rule uses for "equipment" (BankDeskService tools/equipment, CapitalItemRule 2026-10-19)

/**
 * Footing: the receipt's own lines must not add up to more than its total (lines + tax ≤ total). Fewer lines than the
 * total is normal (partial capture); MORE means a misread line or a wrong total.
 */
function checkFooting(array $e, PDO $db): ?array
{
    $id = (int)($e['id'] ?? 0);
    $total = (float)($e['total'] ?? $e['amount'] ?? 0);
    if ($id <= 0 || $total <= 0) return null;
    try {
        $s = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(NULLIF(line_total, 0), quantity * COALESCE(unit_price, 0))), 0) AS lines_total
                           FROM expense_line_items WHERE expense_id = ? AND COALESCE(is_adjustment, 0) = 0");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        return null;   // no line items table or column: nothing to foot
    }
    if (!$r || (int)$r['n'] === 0) return null;
    $lines = (float)$r['lines_total'];
    if ($lines > $total + 0.50) {
        return ['code' => 'FOOTING_MISMATCH', 'score' => 20,
                'detail' => 'Line items add up to $' . number_format($lines, 2) . ', more than the receipt total of $' . number_format($total, 2) . ' — a misread line or a wrong total'];
    }
    return null;
}

/** Equipment, tools or machinery at or over the capitalization threshold: a fixed asset changes the P&L and the tax return. */
function checkCapitalAsset(array $e, PDO $db): ?array
{
    $total = (float)($e['total'] ?? $e['amount'] ?? 0);
    $cat = strtolower((string)($e['accounting_category'] ?? $e['category'] ?? ''));
    if ($total <= 0 || !preg_match('/equip|tool|machin|mower|trailer|vehicle|truck|computer|asset/', $cat)) return null;
    $threshold = ANOMALY_CAPITAL_DEFAULT;
    try {
        $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'capitalization_threshold'");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v !== false && (float)$v > 0) $threshold = (float)$v;
    } catch (Throwable $ex) { /* default */ }
    if ($total >= $threshold) {
        return ['code' => 'CAPITAL_ASSET', 'score' => 20,
                'detail' => 'Equipment at $' . number_format($total, 2) . ' is over the $' . number_format($threshold, 0) . ' capitalization threshold — a fixed asset (1500), not an expense? (threshold: ops_settings capitalization_threshold)'];
    }
    return null;
}
