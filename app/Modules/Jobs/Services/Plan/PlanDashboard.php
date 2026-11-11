<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * Dashboard/list helpers + tracking requirement resolution
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 * Global functions — names/signatures unchanged from the original monolith.
 */

// ============================================================================
// DASHBOARD & LIST HELPERS
// ============================================================================

/**
 * Get dashboard stats for plans and visits.
 */
function getPlanDashboardStats(): array {
    $db = getDB();

    $stats = [];

    // Plan counts by status
    $planStats = $db->query("SELECT status, COUNT(*) as count FROM job_plans GROUP BY status");
    while ($row = $planStats->fetch(PDO::FETCH_ASSOC)) {
        $stats['plans_' . $row['status']] = (int)$row['count'];
    }

    // Today's visits
    $todayStmt = $db->query("
        SELECT COUNT(*) as count FROM job_visits
        WHERE scheduled_date = CURDATE() AND status IN ('scheduled', 'in_progress')
    ");
    $stats['visits_today'] = (int)$todayStmt->fetch(PDO::FETCH_ASSOC)['count'];

    // This week's visits
    $weekStmt = $db->query("
        SELECT COUNT(*) as count FROM job_visits
        WHERE scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND status IN ('scheduled', 'in_progress')
    ");
    $stats['visits_this_week'] = (int)$weekStmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Active plans count
    $stats['plans_active_total'] = ($stats['plans_active'] ?? 0);

    return $stats;
}

/**
 * Get recent jobs on a property (for property detail views).
 */
function getRecentPlansOnProperty(int $propertyId, int $limit = 5): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT jp.id, jp.plan_number, jp.title, jp.service_type, jp.status,
               jp.plan_start_date, jp.estimated_amount,
               (SELECT COUNT(*) FROM job_visits jv WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS visits_done,
               (SELECT MIN(jv2.scheduled_date) FROM job_visits jv2 WHERE jv2.plan_id = jp.id AND jv2.status = 'scheduled' AND jv2.scheduled_date >= CURDATE()) AS next_visit
        FROM job_plans jp
        WHERE jp.property_id = ?
        ORDER BY jp.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$propertyId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resolve effective tracking requirements for a job plan.
 * Priority: plan overrides (if not NULL) > product defaults (most restrictive) > standard.
 *
 * @return array ['require_clock_in' => bool, 'require_gps' => bool, 'require_photos' => bool,
 *               'tracking_level' => string, 'source' => array]
 */
function resolveTrackingRequirementsForPlan(int $planId): array {
    $db = getDB();
    $defaults = [
        'auto_clock_in' => false,
        'require_clock_in' => false,
        'require_gps' => false,
        'require_photos' => false,
        'tracking_level' => 'standard',
        'source' => ['auto_clock_in' => 'default', 'clock_in' => 'default', 'gps' => 'default', 'photos' => 'default']
    ];

    // Check if tracking columns exist
    try {
        $check = $db->query("SHOW COLUMNS FROM job_plans LIKE 'tracking_level_override'");
        if ($check->rowCount() === 0) {
            return $defaults;
        }
    } catch (Exception $e) {
        return $defaults;
    }

    // Check if auto_clock_in_override column exists on job_plans
    $hasAutoClockInOverride = false;
    try {
        $aciCheck = $db->query("SHOW COLUMNS FROM job_plans LIKE 'auto_clock_in_override'");
        $hasAutoClockInOverride = ($aciCheck->rowCount() > 0);
    } catch (Exception $e) { /* ignore */ }

    // Get plan override columns
    $planCols = "tracking_level_override, require_clock_in_override,
               require_gps_override, require_photos_override";
    if ($hasAutoClockInOverride) {
        $planCols .= ", auto_clock_in_override";
    }
    $stmt = $db->prepare("SELECT {$planCols} FROM job_plans WHERE id = ?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        return $defaults;
    }

    // Get product-level defaults (most restrictive across all linked products)
    $pClockIn = 0;
    $pGps = 0;
    $pPhotos = 0;
    $pAutoClockIn = 0;
    $pLevel = 'standard';

    try {
        $checkProd = $db->query("SHOW COLUMNS FROM products LIKE 'tracking_level'");
        if ($checkProd->rowCount() > 0) {
            // Check if auto_clock_in column exists on products
            $hasAutoClockInProd = false;
            try {
                $aciProdCheck = $db->query("SHOW COLUMNS FROM products LIKE 'auto_clock_in'");
                $hasAutoClockInProd = ($aciProdCheck->rowCount() > 0);
            } catch (Exception $e) { /* ignore */ }

            // Check if plan_line_items has direct product_id column
            $hasDirectProductId = false;
            try {
                $dpCheck = $db->query("SHOW COLUMNS FROM plan_line_items LIKE 'product_id'");
                $hasDirectProductId = ($dpCheck->rowCount() > 0);
            } catch (Exception $e) { /* ignore */ }

            $autoClockInCol = $hasAutoClockInProd ? "MAX(p.auto_clock_in) AS auto_clock_in," : "";

            // Resolve products via two paths:
            //   1. Direct: plan_line_items.product_id → products
            //   2. Via quote: plan_line_items.quote_line_item_id → quote_line_items.product_id → products
            if ($hasDirectProductId) {
                $prodStmt = $db->prepare("
                    SELECT MAX(p.require_clock_in) AS require_clock_in,
                           MAX(p.require_gps) AS require_gps,
                           MAX(p.require_photos) AS require_photos,
                           {$autoClockInCol}
                           MAX(CASE WHEN p.tracking_level = 'heightened' THEN 2
                                    WHEN p.tracking_level = 'custom' THEN 1
                                    ELSE 0 END) AS level_rank
                    FROM plan_line_items pli
                    LEFT JOIN quote_line_items qli ON pli.quote_line_item_id = qli.id
                    JOIN products p ON p.id = COALESCE(pli.product_id, qli.product_id)
                    WHERE pli.plan_id = ?
                ");
            } else {
                $prodStmt = $db->prepare("
                    SELECT MAX(p.require_clock_in) AS require_clock_in,
                           MAX(p.require_gps) AS require_gps,
                           MAX(p.require_photos) AS require_photos,
                           {$autoClockInCol}
                           MAX(CASE WHEN p.tracking_level = 'heightened' THEN 2
                                    WHEN p.tracking_level = 'custom' THEN 1
                                    ELSE 0 END) AS level_rank
                    FROM plan_line_items pli
                    JOIN quote_line_items qli ON pli.quote_line_item_id = qli.id
                    JOIN products p ON qli.product_id = p.id
                    WHERE pli.plan_id = ?
                ");
            }
            $prodStmt->execute([$planId]);
            $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);

            if ($prod && $prod['level_rank'] !== null) {
                $pClockIn = (int)$prod['require_clock_in'];
                $pGps = (int)$prod['require_gps'];
                $pPhotos = (int)$prod['require_photos'];
                $pAutoClockIn = $hasAutoClockInProd ? (int)($prod['auto_clock_in'] ?? 0) : 0;
                $pLevel = $prod['level_rank'] == 2 ? 'heightened' :
                          ($prod['level_rank'] == 1 ? 'custom' : 'standard');
            }
        }
    } catch (Exception $e) {
        // Products table may not have tracking columns yet
    }

    // Resolve auto_clock_in: plan override wins if not NULL, else product default
    $planAutoClockIn = $hasAutoClockInOverride ? ($plan['auto_clock_in_override'] ?? null) : null;

    // Resolve: plan override wins if not NULL, else product default
    return [
        'auto_clock_in' => $planAutoClockIn !== null
            ? (bool)(int)$planAutoClockIn
            : (bool)$pAutoClockIn,
        'require_clock_in' => $plan['require_clock_in_override'] !== null
            ? (bool)(int)$plan['require_clock_in_override']
            : (bool)$pClockIn,
        'require_gps' => $plan['require_gps_override'] !== null
            ? (bool)(int)$plan['require_gps_override']
            : (bool)$pGps,
        'require_photos' => $plan['require_photos_override'] !== null
            ? (bool)(int)$plan['require_photos_override']
            : (bool)$pPhotos,
        'tracking_level' => $plan['tracking_level_override'] !== null
            ? $plan['tracking_level_override']
            : $pLevel,
        'source' => [
            'auto_clock_in' => $planAutoClockIn !== null ? 'plan' : 'product',
            'clock_in' => $plan['require_clock_in_override'] !== null ? 'plan' : 'product',
            'gps' => $plan['require_gps_override'] !== null ? 'plan' : 'product',
            'photos' => $plan['require_photos_override'] !== null ? 'plan' : 'product',
        ]
    ];
}

/**
 * Resolve effective tracking requirements for a specific visit.
 * Looks up the plan from the visit, then delegates to resolveTrackingRequirementsForPlan().
 */
function resolveTrackingRequirements(int $visitId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT plan_id FROM job_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !$row['plan_id']) {
        return [
            'auto_clock_in' => false,
            'require_clock_in' => false,
            'require_gps' => false,
            'require_photos' => false,
            'tracking_level' => 'standard',
            'source' => ['auto_clock_in' => 'default', 'clock_in' => 'default', 'gps' => 'default', 'photos' => 'default']
        ];
    }

    return resolveTrackingRequirementsForPlan((int)$row['plan_id']);
}
