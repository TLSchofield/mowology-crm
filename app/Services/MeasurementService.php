<?php
/**
 * MeasurementService — shared measurement save logic
 *
 * Extracted from area-measurement.php and quote-workflow.php to
 * eliminate duplication. Both pages now call this service.
 */

require_once dirname(__DIR__) . '/Core/paths.php';

/**
 * Build a lookup map: measurement_type → group_key
 * Reads from measurement_groups table, caches in static variable.
 *
 * @return array ['lawn' => 'lawn_area', 'garden' => 'lawn_area', ...]
 */
function getMeasurementTypeToGroupMap() {
    static $map = null;
    if ($map !== null) return $map;

    $db = getDB();
    $map = [];

    try {
        $rows = $db->query("SELECT group_key, measurement_types FROM measurement_groups WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $types = array_map('trim', explode(',', $row['measurement_types']));
            foreach ($types as $type) {
                if ($type !== '') {
                    $map[$type] = $row['group_key'];
                }
            }
        }
    } catch (Exception $e) {
        // Fallback if table doesn't exist yet
        $map = [
            'lawn' => 'lawn_area', 'garden' => 'lawn_area',
            'driveway' => 'hard_surface', 'walkway' => 'hard_surface',
            'patio' => 'hard_surface', 'parking' => 'hard_surface',
            'hedge' => 'hedge_linear',
            'other' => 'other_area',
        ];
    }

    return $map;
}

/**
 * Save measurements for a property.
 *
 * Replaces all existing measurements (delete + re-insert).
 * Updates property summary totals.
 * Logs activity.
 *
 * @param int   $propertyId
 * @param array $measurements Array of measurement objects from the JS frontend:
 *   [
 *     'name'    => 'Front Lawn',
 *     'type'    => 'lawn',        // measurement_type enum
 *     'sqFt'    => 4300.50,
 *     'perimeter' => 280.0,
 *     'coords'  => [{lat, lng}, ...],
 *     'shape'   => 'polygon',     // polygon|rectangle|polyline (optional, defaults to 'polygon')
 *     'linearFt' => null,         // set for polyline shapes
 *   ]
 * @param int   $userId
 * @return array ['success' => bool, 'message' => string, 'totals' => [...]]
 */
function saveMeasurementsForProperty(int $propertyId, array $measurements, int $userId): array {
    $db = getDB();
    $typeToGroup = getMeasurementTypeToGroupMap();

    try {
        $db->beginTransaction();

        // Delete existing measurements (replace-all strategy)
        $db->prepare("DELETE FROM property_measurements WHERE property_id = ?")->execute([$propertyId]);

        // Detect available columns to handle pre-migration databases gracefully
        $hasNewCols = true;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM property_measurements LIKE 'measurement_shape'");
            $hasNewCols = ($colCheck->rowCount() > 0);
        } catch (Exception $e) {
            $hasNewCols = false;
        }

        if ($hasNewCols) {
            $insertSql = "
                INSERT INTO property_measurements
                    (property_id, measurement_name, measurement_type, measurement_shape, measurement_group_key,
                     area_sqft, area_sqm, perimeter_ft, linear_ft, polygon_coords, measured_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
        } else {
            $insertSql = "
                INSERT INTO property_measurements
                    (property_id, measurement_name, measurement_type, area_sqft, area_sqm, perimeter_ft, polygon_coords, measured_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ";
        }
        $stmt = $db->prepare($insertSql);

        // Tally totals by group — data-driven from typeToGroup map
        $totals = [];
        // Legacy totals for backward compatibility with properties table columns
        $totalLawn     = 0;
        $totalDriveway = 0;

        // Build group unit lookup from DB
        $groupUnits = [];
        try {
            $guRows = $db->query("SELECT group_key, unit FROM measurement_groups WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($guRows as $gu) { $groupUnits[$gu['group_key']] = $gu['unit']; }
        } catch (Exception $e) {
            $groupUnits = ['lawn_area' => 'sqft', 'hard_surface' => 'sqft', 'hedge_linear' => 'linear_ft', 'other_area' => 'sqft'];
        }

        foreach ($measurements as $m) {
            $sqft      = (float)($m['sqFt'] ?? 0);
            $sqm       = $sqft > 0 ? round($sqft / 10.764, 2) : null;
            $type      = $m['type'] ?? 'other';
            $shape     = $m['shape'] ?? 'polygon';
            $linearFt  = isset($m['linearFt']) ? (float)$m['linearFt'] : null;
            $perimeter = isset($m['perimeter']) ? (float)$m['perimeter'] : null;
            $groupKey  = $typeToGroup[$type] ?? 'other_area';

            // For polyline shapes, sqft is typically 0
            if ($shape === 'polyline' && $sqft == 0 && $linearFt > 0) {
                $sqm = null;
            }

            if ($hasNewCols) {
                $stmt->execute([
                    $propertyId,
                    $m['name'] ?? 'Unnamed Area',
                    $type,
                    $shape,
                    $groupKey,
                    $sqft,
                    $sqm,
                    $perimeter,
                    $linearFt,
                    isset($m['coords']) ? json_encode($m['coords']) : null,
                    $userId,
                ]);
            } else {
                $stmt->execute([
                    $propertyId,
                    $m['name'] ?? 'Unnamed Area',
                    $type,
                    $sqft,
                    $sqm,
                    $perimeter,
                    isset($m['coords']) ? json_encode($m['coords']) : null,
                    $userId,
                ]);
            }

            // Accumulate totals dynamically by group
            if (!isset($totals[$groupKey])) $totals[$groupKey] = 0;
            $groupUnit = $groupUnits[$groupKey] ?? 'sqft';
            if ($groupUnit === 'linear_ft') {
                $totals[$groupKey] += ($linearFt > 0 ? $linearFt : $perimeter ?? 0);
            } else {
                $totals[$groupKey] += $sqft;
            }

            // Legacy totals for properties table columns
            if (in_array($type, ['lawn', 'garden'])) {
                $totalLawn += $sqft;
            } elseif (in_array($type, ['driveway', 'walkway', 'parking', 'patio'])) {
                $totalDriveway += $sqft;
            }
        }

        // Update property summary fields
        $hasExtendedTotals = true;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM properties LIKE 'total_hard_surface_sqft'");
            $hasExtendedTotals = ($colCheck->rowCount() > 0);
        } catch (Exception $e) {
            $hasExtendedTotals = false;
        }

        if ($hasExtendedTotals) {
            $db->prepare("
                UPDATE properties SET
                    total_lawn_sqft = ?,
                    total_driveway_sqft = ?,
                    total_hard_surface_sqft = ?,
                    total_hedge_linear_ft = ?,
                    total_other_sqft = ?,
                    measurements_updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $totalLawn,
                $totalDriveway,
                $totals['hard_surface'] ?? 0,
                $totals['hedge_linear'] ?? 0,
                $totals['other_area'] ?? 0,
                $propertyId,
            ]);
        } else {
            $db->prepare("
                UPDATE properties SET
                    total_lawn_sqft = ?,
                    total_driveway_sqft = ?,
                    measurements_updated_at = NOW()
                WHERE id = ?
            ")->execute([$totalLawn, $totalDriveway, $propertyId]);
        }

        // Log activity
        $totalAll = array_sum($totals);
        try {
            $db->prepare("
                INSERT INTO activity_log (user_id, property_id, action, details, ip_address)
                VALUES (?, ?, 'Measurements updated', ?, ?)
            ")->execute([
                $userId,
                $propertyId,
                count($measurements) . ' areas measured. Total: ' . number_format($totalAll) . ' sq ft / lin ft',
                $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        } catch (Exception $e) {
            // activity_log insert is non-critical
            error_log("Activity log error on measurement save: " . $e->getMessage());
        }

        $db->commit();

        return [
            'success' => true,
            'message' => 'Measurements saved successfully',
            'totals'  => array_merge([
                'lawn_sqft'         => $totalLawn,
                'driveway_sqft'     => $totalDriveway,
                'hard_surface_sqft' => $totals['hard_surface'] ?? 0,
                'hedge_linear_ft'   => $totals['hedge_linear'] ?? 0,
                'other_sqft'        => $totals['other_area'] ?? 0,
            ], $totals),
        ];
    } catch (Exception $e) {
        $db->rollBack();
        error_log("saveMeasurementsForProperty error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Get measurement totals for a property, grouped by measurement_group_key.
 *
 * @param int $propertyId
 * @return array ['lawn_area' => ['sqft' => 4300, 'linear_ft' => 0, 'count' => 2, 'names' => ['Front Lawn', 'Back Lawn']], ...]
 */
function getMeasurementTotalsForProperty(int $propertyId): array {
    $db = getDB();
    $result = [];

    try {
        // Try with new columns first
        $stmt = $db->prepare("
            SELECT measurement_name, measurement_type, measurement_group_key,
                   area_sqft, linear_ft, perimeter_ft
            FROM property_measurements
            WHERE property_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$propertyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fallback without new columns
        $stmt = $db->prepare("
            SELECT measurement_name, measurement_type,
                   area_sqft, perimeter_ft
            FROM property_measurements
            WHERE property_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$propertyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $typeToGroup = getMeasurementTypeToGroupMap();

    foreach ($rows as $row) {
        $groupKey = $row['measurement_group_key'] ?? ($typeToGroup[$row['measurement_type']] ?? 'other_area');

        if (!isset($result[$groupKey])) {
            $result[$groupKey] = [
                'sqft'      => 0,
                'linear_ft' => 0,
                'count'     => 0,
                'names'     => [],
            ];
        }

        $result[$groupKey]['sqft'] += (float)($row['area_sqft'] ?? 0);
        $result[$groupKey]['linear_ft'] += (float)($row['linear_ft'] ?? $row['perimeter_ft'] ?? 0);
        $result[$groupKey]['count']++;
        $result[$groupKey]['names'][] = $row['measurement_name'];
    }

    return $result;
}
