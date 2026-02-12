<?php
/**
 * Weather Rules Module
 * /crm/modules/weather/weather-rules.php
 *
 * Loads weather policy thresholds from ops_settings and service_packages.
 * Aggregates rules across multiple services for a single visit.
 * Applies defaults when service-level values are NULL.
 */

declare(strict_types=1);

// ============================================================================
// DEFAULT CONSTANTS
// ============================================================================

/**
 * Weather policy hierarchy (strictest wins when aggregating).
 * Lower index = stricter policy.
 */
define('WEATHER_POLICY_STRICTNESS', [
    'DRY_ONLY'      => 1,
    'TEMP_LIMITED'   => 2,
    'WIND_LIMITED'   => 3,
    'LIGHT_RAIN_OK'  => 4,
    'CUSTOM'         => 5,
    'ANY'            => 6,
]);

// ============================================================================
// OPS CONSTRAINTS (global defaults from ops_settings table)
// ============================================================================

/**
 * Get weather ops constraints from ops_settings table.
 * Falls back to hardcoded defaults if not found.
 *
 * @return array Constraint settings
 */
function getWeatherOpsConstraints(): array
{
    $defaults = getDefaultOpsConstraints();

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
        $stmt->execute(['weather_ops_constraints']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['setting_value'])) {
            $stored = json_decode($row['setting_value'], true);
            if (is_array($stored)) {
                return array_merge($defaults, $stored);
            }
        }
    } catch (Throwable $e) {
        error_log("getWeatherOpsConstraints error: " . $e->getMessage());
    }

    return $defaults;
}

/**
 * Save weather ops constraints to ops_settings table.
 *
 * @param array $constraints
 * @param int|null $userId
 * @return bool
 */
function saveWeatherOpsConstraints(array $constraints, ?int $userId = null): bool
{
    try {
        $db = getDB();
        $json = json_encode($constraints);

        $stmt = $db->prepare("
            INSERT INTO ops_settings (setting_key, setting_value, description, updated_by)
            VALUES (?, ?, 'Weather operations constraints', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
        ");
        return $stmt->execute(['weather_ops_constraints', $json, $userId]);
    } catch (Throwable $e) {
        error_log("saveWeatherOpsConstraints error: " . $e->getMessage());
        return false;
    }
}

/**
 * Hardcoded default ops constraints (used when DB row missing or NULL fields).
 *
 * @return array
 */
function getDefaultOpsConstraints(): array
{
    return [
        'default_max_precip_chance_pct'  => 50,
        'default_max_precip_mm_per_hr'   => 2.5,
        'default_min_temp_c'             => -5.0,
        'default_max_temp_c'             => 40.0,
        'default_max_wind_kph'           => 50.0,
        'borderline_precip_chance_low'   => 30,
        'borderline_precip_chance_high'  => 50,
        'default_move_window_hours'      => 48,
        'default_timeband_start'         => '07:00',
        'default_timeband_end'           => '18:00',
        'auto_reschedule_enabled'        => false,
        'swap_suggestions_enabled'       => true,
        'decision_time'                  => '12:00',
        'lookahead_days'                 => 2,
    ];
}

// ============================================================================
// SERVICE-LEVEL WEATHER RULES
// ============================================================================

/**
 * Get weather rules for a specific service package.
 * Fills NULL fields with ops constraint defaults.
 *
 * @param int $servicePackageId
 * @return array Weather rules with defaults applied
 */
function getServiceWeatherRules(int $servicePackageId): array
{
    $ops = getWeatherOpsConstraints();
    $defaults = [
        'weather_policy'               => 'ANY',
        'max_precip_chance_pct'        => $ops['default_max_precip_chance_pct'],
        'max_precip_mm_per_hr'         => $ops['default_max_precip_mm_per_hr'],
        'min_temp_c'                   => $ops['default_min_temp_c'],
        'max_temp_c'                   => $ops['default_max_temp_c'],
        'max_wind_kph'                 => $ops['default_max_wind_kph'],
        'move_window_hours'            => $ops['default_move_window_hours'],
        'move_timeband_start'          => $ops['default_timeband_start'],
        'move_timeband_end'            => $ops['default_timeband_end'],
        'auto_reschedule'              => $ops['auto_reschedule_enabled'] ? 1 : 0,
        'require_manual_if_uncertain'  => 1,
        'borderline_precip_chance_low' => $ops['borderline_precip_chance_low'],
        'borderline_precip_chance_high'=> $ops['borderline_precip_chance_high'],
    ];

    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT weather_policy, max_precip_chance_pct, max_precip_mm_per_hr,
                   min_temp_c, max_temp_c, max_wind_kph,
                   move_window_hours, move_timeband_start, move_timeband_end,
                   auto_reschedule, require_manual_if_uncertain
            FROM service_packages
            WHERE id = ?
        ");
        $stmt->execute([$servicePackageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Merge: service value wins if not NULL, else default
            foreach ($row as $key => $value) {
                if ($value !== null && $value !== '') {
                    $defaults[$key] = $value;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("getServiceWeatherRules error: " . $e->getMessage());
    }

    // Apply policy presets for common policies
    $defaults = applyPolicyPresets($defaults);

    return $defaults;
}

/**
 * Apply preset thresholds based on weather_policy value.
 * Only fills in NULL thresholds — explicit service overrides are preserved.
 *
 * @param array $rules
 * @return array Rules with presets applied
 */
function applyPolicyPresets(array $rules): array
{
    $policy = $rules['weather_policy'] ?? 'ANY';

    switch ($policy) {
        case 'DRY_ONLY':
            // Strict: no rain at all
            if ($rules['max_precip_chance_pct'] === null || $rules['max_precip_chance_pct'] > 30) {
                $rules['max_precip_chance_pct'] = 30;
            }
            if ($rules['max_precip_mm_per_hr'] === null || $rules['max_precip_mm_per_hr'] > 0.5) {
                $rules['max_precip_mm_per_hr'] = 0.5;
            }
            break;

        case 'LIGHT_RAIN_OK':
            // Moderate: light rain is fine
            if ($rules['max_precip_chance_pct'] === null) {
                $rules['max_precip_chance_pct'] = 70;
            }
            if ($rules['max_precip_mm_per_hr'] === null) {
                $rules['max_precip_mm_per_hr'] = 5.0;
            }
            break;

        case 'TEMP_LIMITED':
            // Temperature is the primary concern
            if ($rules['min_temp_c'] === null) {
                $rules['min_temp_c'] = 0.0;
            }
            break;

        case 'WIND_LIMITED':
            // Wind is the primary concern
            if ($rules['max_wind_kph'] === null) {
                $rules['max_wind_kph'] = 30.0;
            }
            break;

        case 'ANY':
        case 'CUSTOM':
        default:
            // No presets applied
            break;
    }

    return $rules;
}

// ============================================================================
// RULE AGGREGATION (multiple services on one visit)
// ============================================================================

/**
 * Aggregate weather rules across multiple service packages.
 * The most restrictive rule wins for each threshold.
 *
 * @param array $servicePackageIds Array of service_package IDs on the visit
 * @return array Combined visit rule (strictest of all services)
 */
function aggregateVisitRules(array $servicePackageIds): array
{
    if (empty($servicePackageIds)) {
        return getServiceWeatherRules(0); // Returns defaults
    }

    if (count($servicePackageIds) === 1) {
        return getServiceWeatherRules($servicePackageIds[0]);
    }

    $rules = [];
    foreach ($servicePackageIds as $id) {
        $rules[] = getServiceWeatherRules((int)$id);
    }

    // Start with least restrictive, then tighten
    $combined = $rules[0];

    for ($i = 1; $i < count($rules); $i++) {
        $r = $rules[$i];

        // Most restrictive policy wins (lowest strictness number)
        $currentStrictness = WEATHER_POLICY_STRICTNESS[$combined['weather_policy']] ?? 6;
        $newStrictness     = WEATHER_POLICY_STRICTNESS[$r['weather_policy']] ?? 6;
        if ($newStrictness < $currentStrictness) {
            $combined['weather_policy'] = $r['weather_policy'];
        }

        // Lowest thresholds win (most conservative)
        if ($r['max_precip_chance_pct'] !== null) {
            $combined['max_precip_chance_pct'] = min(
                (int)($combined['max_precip_chance_pct'] ?? 100),
                (int)$r['max_precip_chance_pct']
            );
        }
        if ($r['max_precip_mm_per_hr'] !== null) {
            $combined['max_precip_mm_per_hr'] = min(
                (float)($combined['max_precip_mm_per_hr'] ?? 999),
                (float)$r['max_precip_mm_per_hr']
            );
        }
        if ($r['max_wind_kph'] !== null) {
            $combined['max_wind_kph'] = min(
                (float)($combined['max_wind_kph'] ?? 999),
                (float)$r['max_wind_kph']
            );
        }

        // Tightest temperature range
        if ($r['min_temp_c'] !== null) {
            $combined['min_temp_c'] = max(
                (float)($combined['min_temp_c'] ?? -99),
                (float)$r['min_temp_c']
            );
        }
        if ($r['max_temp_c'] !== null) {
            $combined['max_temp_c'] = min(
                (float)($combined['max_temp_c'] ?? 99),
                (float)$r['max_temp_c']
            );
        }

        // Shortest move window
        if ($r['move_window_hours'] !== null) {
            $combined['move_window_hours'] = min(
                (int)($combined['move_window_hours'] ?? 999),
                (int)$r['move_window_hours']
            );
        }

        // If any service requires manual on uncertain, keep it
        if ((int)($r['require_manual_if_uncertain'] ?? 0) === 1) {
            $combined['require_manual_if_uncertain'] = 1;
        }

        // Auto-reschedule only if ALL services allow it
        if ((int)($r['auto_reschedule'] ?? 0) === 0) {
            $combined['auto_reschedule'] = 0;
        }
    }

    // Re-apply presets after aggregation
    $combined = applyPolicyPresets($combined);

    return $combined;
}

/**
 * Get a human-readable summary of weather rules.
 *
 * @param array $rules
 * @return string
 */
function summarizeWeatherRules(array $rules): string
{
    $policy = $rules['weather_policy'] ?? 'ANY';

    if ($policy === 'ANY') {
        return 'Any weather conditions OK';
    }

    $parts = [];
    $parts[] = str_replace('_', ' ', strtolower($policy));

    if (isset($rules['max_precip_chance_pct'])) {
        $parts[] = "max {$rules['max_precip_chance_pct']}% precip chance";
    }
    if (isset($rules['max_precip_mm_per_hr'])) {
        $parts[] = "max {$rules['max_precip_mm_per_hr']}mm/hr rain";
    }
    if (isset($rules['min_temp_c'])) {
        $parts[] = "min {$rules['min_temp_c']}°C";
    }
    if (isset($rules['max_temp_c'])) {
        $parts[] = "max {$rules['max_temp_c']}°C";
    }
    if (isset($rules['max_wind_kph'])) {
        $parts[] = "max {$rules['max_wind_kph']} km/h wind";
    }

    return ucfirst(implode(', ', $parts));
}
