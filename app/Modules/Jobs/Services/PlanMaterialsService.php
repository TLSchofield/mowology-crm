<?php
/**
 * PlanMaterialsService — fertilizer bundle visits, materials calc, purchase-task
 * schedule integration.
 *
 * Extracted (2026-06-18, refactor Phase 2) from the procedural Plan function
 * library. The global functions in Plan/PlanMaterials.php delegate here, so all
 * callers and the legacy shim are unchanged.
 *
 * Design: DB-backed methods (generateFertilizerVisits, calculateMaterialsForVisit,
 * getPurchaseTasksForSchedule) gather rows, then delegate to PURE methods
 * (computeMaterialQuantity — the application-rate parser; bucketTasksByDate — the
 * purchase-task appearance/distribution rules). The pure methods are unit-tested;
 * logic is byte-for-byte the original inline code.
 */
class PlanMaterialsService
{
    // =========================================================================
    // DB-backed methods (facade targets)
    // =========================================================================

    /**
     * Generate visits for a pre-sold fertilizer bundle using explicit dates.
     * Called by createJobPlan() when is_prepaid_bundle=1 and fertilizer_dates[] provided.
     *
     * Each visit is created with actual_amount=0.00 (pre-sold, no charge) and
     * materials_json auto-calculated from the plan's product application_rate × property area.
     *
     * @param int   $planId  The plan id
     * @param array $dates   Ordered array of 'Y-m-d' date strings (one per application)
     */
    public static function generateFertilizerVisits(int $planId, array $dates): void {
        $db = getDB();

        // Load plan + property
        $planStmt = $db->prepare("
            SELECT jp.*, p.id AS prop_id,
                   p.address AS property_address, p.city AS property_city
            FROM job_plans jp
            JOIN properties p ON jp.property_id = p.id
            WHERE jp.id = ?
        ");
        $planStmt->execute([$planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) return;

        // Auto-calculate materials once (shared across all visits in this plan)
        $materialsJson = self::calculateMaterialsForVisit($planId);

        $visitNumber = 1;
        foreach ($dates as $dateStr) {
            $dateStr = trim($dateStr);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) continue;

            $visitNum = generateVisitNumber($plan['plan_number'], $visitNumber);

            try {
                $insertStmt = $db->prepare("
                    INSERT IGNORE INTO job_visits (
                        visit_number, plan_id, scheduled_date, sequence_index,
                        status, actual_amount, materials_json,
                        assigned_crew_id,
                        scheduled_time_start, scheduled_time_end
                    ) VALUES (
                        ?, ?, ?, ?,
                        'scheduled', 0.00, ?,
                        ?,
                        ?, ?
                    )
                ");
                $insertStmt->execute([
                    $visitNum,
                    $planId,
                    $dateStr,
                    $visitNumber,
                    $materialsJson ?: null,
                    $plan['default_crew_id'] ?? null,
                    $plan['default_time_start'] ?? null,
                    $plan['default_time_end'] ?? null,
                ]);

                // Ensure calendar stop for routing
                ensureCalendarStop(
                    (int)$plan['property_id'],
                    $dateStr,
                    $plan['default_crew_id'] ? (int)$plan['default_crew_id'] : null,
                    $plan['default_time_start'] ?? null,
                    $plan['default_time_end'] ?? null
                );
            } catch (Throwable $e) {
                error_log("generateFertilizerVisits: visit {$visitNum} on {$dateStr} failed: " . $e->getMessage());
            }

            $visitNumber++;
        }

        // Update visits_generated_through watermark
        if (!empty($dates)) {
            $lastDate = max($dates);
            try {
                $db->prepare("UPDATE job_plans SET visits_generated_through = ? WHERE id = ?")
                   ->execute([$lastDate, $planId]);
            } catch (Throwable $e) { /* non-critical */ }
        }
    }

    /**
     * Auto-calculate materials JSON for a fertilizer visit using the plan's
     * product application_rate and the property's measured lawn area.
     *
     * Returns a JSON string for job_visits.materials_json, or null if no product data.
     *
     * @param int $planId
     * @return string|null JSON array: [{"product_id":N,"product_name":"...","qty":2,"unit":"bags",...}]
     */
    public static function calculateMaterialsForVisit(int $planId): ?string {
        $db = getDB();

        // Get plan's primary product via plan_line_items (first item with a product_id)
        $productStmt = $db->prepare("
            SELECT pli.product_id, p.name AS product_name,
                   p.application_rate, p.sku
            FROM plan_line_items pli
            JOIN products p ON pli.product_id = p.id
            WHERE pli.plan_id = ?
              AND pli.product_id IS NOT NULL
            ORDER BY pli.sort_order ASC
            LIMIT 1
        ");
        $productStmt->execute([$planId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || empty($product['application_rate'])) return null;

        // Get property's lawn area from measurements
        $planRow = $db->prepare("SELECT property_id FROM job_plans WHERE id = ?");
        $planRow->execute([$planId]);
        $propertyId = (int)$planRow->fetchColumn();

        $areaStmt = $db->prepare("
            SELECT SUM(area_sqft) AS total_sqft
            FROM property_measurements
            WHERE property_id = ?
              AND measurement_group_key IN ('lawn_area', 'lawn', 'garden')
            GROUP BY property_id
        ");
        $areaStmt->execute([$propertyId]);
        $areaSqft = (float)($areaStmt->fetchColumn() ?: 0);

        // Parse application_rate → quantity + unit (pure)
        $calc = self::computeMaterialQuantity($product['application_rate'], $areaSqft);

        $materials = [[
            'product_id'       => (int)$product['product_id'],
            'product_name'     => $product['product_name'],
            'sku'              => $product['sku'] ?? '',
            'qty'              => $calc['qty'] ?? 1,
            'unit'             => $calc['unit'],
            'area_sqft'        => (int)$areaSqft,
            'application_rate' => $product['application_rate'],
        ]];

        return json_encode($materials, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Fetch purchase tasks that should appear on the schedule for a date range.
     *
     * Appearance rules (any one is sufficient):
     *   - procurement_mode = 'asap' and not yet delivered/verified
     *   - scheduled_date <= endDate and not yet delivered/verified
     *   - No scheduled_date and DATE(created_at) <= endDate and not delivered/verified
     *
     * Returns a flat array of task rows, each keyed by the date(s) they should
     * appear on within [startDate, endDate]. Tasks with no fixed date (asap or
     * no scheduled_date) are duplicated across every date in the range so they
     * show up as persistent reminders.
     *
     * @param PDO    $db
     * @param string $startDate  Y-m-d
     * @param string $endDate    Y-m-d
     * @param int|null $crewId   Filter by assigned_to, or null for all
     * @return array  [dateStr => [task, ...], ...]
     */
    public static function getPurchaseTasksForSchedule(PDO $db, string $startDate, string $endDate, ?int $crewId = null): array
    {
        // Check task_type column exists before querying (migration 970 guard)
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM tasks LIKE 'task_type'")->fetch();
            if (!$colCheck) return [];
        } catch (Exception $e) {
            return [];
        }

        try {
            $sql = "
                SELECT t.*,
                       v.name  AS vendor_name,
                       vl.label   AS location_label,
                       vl.address AS location_address,
                       vl.lat,
                       vl.lng,
                       vl.city           AS location_city,
                       vl.phone          AS location_phone,
                       vl.hours_weekday,
                       vl.hours_saturday,
                       vl.hours_sunday,
                       vl.notes          AS location_notes,
                       vl.is_preferred,
                       (SELECT COUNT(*) FROM task_items WHERE task_id = t.id) AS items_count,
                       u.full_name AS assigned_to_name,
                       jp.plan_number,
                       jp.title          AS plan_title,
                       CONCAT(c.first_name, ' ', c.last_name) AS contact_name
                FROM tasks t
                LEFT JOIN vendors v  ON t.vendor_id = v.id
                LEFT JOIN vendor_locations vl ON t.vendor_location_id = vl.id
                LEFT JOIN users u ON t.assigned_to = u.id
                LEFT JOIN job_plans jp ON t.plan_id = jp.id
                LEFT JOIN contacts c   ON t.contact_id = c.id
                WHERE t.task_type = 'purchase'
                  AND (t.purchase_status IS NULL OR t.purchase_status NOT IN ('delivered', 'verified'))
                  AND (
                      (t.scheduled_date IS NOT NULL AND t.scheduled_date <= ?)
                      OR (t.scheduled_date IS NULL AND DATE(t.created_at) <= ?)
                      OR t.procurement_mode = 'asap'
                  )
            ";
            $params = [$endDate, $endDate];

            if ($crewId !== null) {
                $sql .= " AND t.assigned_to = ?";
                $params[] = $crewId;
            }

            $sql .= " ORDER BY
                CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
                COALESCE(t.scheduled_date, DATE(t.created_at)) ASC,
                t.id ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('[getPurchaseTasksForSchedule] ' . $e->getMessage());
            return [];
        }

        // Batch-fetch task_items to avoid N+1 queries
        $taskIds = array_column($tasks, 'id');
        $itemsByTask = [];
        if (!empty($taskIds)) {
            try {
                $ph = implode(',', array_fill(0, count($taskIds), '?'));
                $iStmt = $db->prepare(
                    "SELECT id, task_id, name, quantity, unit, estimated_unit_price, is_purchased, sort_order
                     FROM task_items
                     WHERE task_id IN ($ph)
                     ORDER BY task_id, sort_order, id"
                );
                $iStmt->execute($taskIds);
                foreach ($iStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $itemsByTask[(int)$item['task_id']][] = $item;
                }
            } catch (Exception $e) {
                // task_items table may not exist pre-migration 970
            }
        }
        foreach ($tasks as &$task) {
            $task['items'] = $itemsByTask[(int)$task['id']] ?? [];
        }
        unset($task);

        // Distribute tasks across the date range (pure)
        return self::bucketTasksByDate($tasks, $startDate, $endDate);
    }

    // =========================================================================
    // PURE calculation methods (unit-tested — no DB, no clock)
    // =========================================================================

    /**
     * Parse a product application_rate string and compute the quantity needed
     * for a given lawn area.
     *
     * Supports formats like: "2 bags per 4000 sqft", "1 bag per 5000 sq ft",
     * "3 kg per 1000 sqft". Unit is taken from the rate string (lower-cased);
     * defaults to 'bags'. qty is null when the rate can't be parsed or the area
     * is unknown (callers default that to 1).
     *
     * @return array ['qty' => float|null, 'unit' => string]
     */
    public static function computeMaterialQuantity(string $applicationRate, float $areaSqft): array {
        $qty = null;
        $unit = 'bags';
        $rateStr = strtolower(trim($applicationRate));

        if (preg_match('/^(\d+\.?\d*)\s+(\w+)\s+per\s+(\d+\.?\d*)\s+sq/i', $rateStr, $m)) {
            $rateQty   = (float)$m[1];
            $unit      = $m[2];
            $rateArea  = (float)$m[3];
            if ($rateArea > 0 && $areaSqft > 0) {
                $qty = round(($areaSqft / $rateArea) * $rateQty, 1);
            }
        }

        return ['qty' => $qty, 'unit' => $unit];
    }

    /**
     * Distribute purchase-task rows across a date range per the appearance rules.
     *
     * - asap / no scheduled_date → persistent: appears on every date >= its
     *   earliest applicable date (scheduled_date, else created_at date).
     * - fixed scheduled_date → appears on that date and every day after (overdue).
     *
     * @param array  $tasks      task rows (with scheduled_date, procurement_mode, created_at)
     * @param string $startDate  Y-m-d
     * @param string $endDate    Y-m-d
     * @return array [dateStr => [task, ...], ...] — every date in range is a key
     */
    public static function bucketTasksByDate(array $tasks, string $startDate, string $endDate): array {
        // Build date range array
        $dates = [];
        $cur = new DateTime($startDate);
        $end = new DateTime($endDate);
        while ($cur <= $end) {
            $dates[] = $cur->format('Y-m-d');
            $cur->modify('+1 day');
        }

        $byDate = [];
        foreach ($dates as $d) {
            $byDate[$d] = [];
        }

        foreach ($tasks as $task) {
            $scheduledDate = $task['scheduled_date'] ?? null;
            $procMode      = $task['procurement_mode'] ?? null;
            $createdDate   = $task['created_at'] ? substr($task['created_at'], 0, 10) : $startDate;

            // Determine which dates this task appears on
            if ($procMode === 'asap' || $scheduledDate === null) {
                // Persistent — show on every date in range from earliest applicable date
                $earliestDate = ($scheduledDate !== null) ? $scheduledDate : $createdDate;
                foreach ($dates as $d) {
                    if ($d >= $earliestDate) {
                        $byDate[$d][] = $task;
                    }
                }
            } else {
                // Fixed-date — show on scheduled_date and every day after (overdue reminder)
                foreach ($dates as $d) {
                    if ($d >= $scheduledDate) {
                        $byDate[$d][] = $task;
                    }
                }
            }
        }

        return $byDate;
    }
}
