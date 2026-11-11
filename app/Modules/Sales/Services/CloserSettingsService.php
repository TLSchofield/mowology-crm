<?php
/**
 * CloserSettingsService — Tim's edits to the Closer's rate card. Admin only, Tim's click only.
 * Nothing automatic calls this: the Closer reads rates (CloserRateCard) and never writes them.
 *
 * Saves: hourly cost, margin floor, minimum visit, depot (yard) pin, and the per-service
 * minutes Tim types for services that do not yet have 15 timed visits.
 * The target margin stays where it already lives (overhead_settings.profit_margin, edited on
 * Cost Factors) — it is not duplicated here.
 */
require_once __DIR__ . '/CloserPricing.php';

class CloserSettingsService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Pure: validate a posted form into [ops settings to write, manual minutes to write, errors].
     * Blank fields are left alone.
     */
    public static function validate(array $in): array
    {
        $ops = [];
        $errors = [];
        $limits = [
            'hourly_cost'      => ['closer_hourly_cost', 5, 500, 'Hourly cost'],
            'margin_floor_pct' => ['closer_margin_floor_pct', 0, 90, 'Margin floor'],
            'min_visit'        => ['closer_min_visit', 0, 2000, 'Minimum visit'],
            'depot_lat'        => ['closer_depot_lat', -90, 90, 'Depot latitude'],
            'depot_lng'        => ['closer_depot_lng', -180, 180, 'Depot longitude'],
        ];
        foreach ($limits as $field => [$key, $lo, $hi, $label]) {
            if (!array_key_exists($field, $in) || $in[$field] === '' || $in[$field] === null) continue;
            if (!is_numeric($in[$field]) || (float)$in[$field] < $lo || (float)$in[$field] > $hi) {
                $errors[] = "{$label} must be between {$lo} and {$hi}";
                continue;
            }
            $ops[$key] = (string)round((float)$in[$field], 6);
        }
        $minutes = [];
        foreach ((array)($in['minutes'] ?? []) as $svc => $m) {
            if (!isset(CloserPricing::SERVICES[$svc]) || !is_array($m)) continue;
            $fixed = $m['fixed'] ?? '';
            $per   = $m['per_unit'] ?? '';
            if ($fixed === '' && $per === '') continue;
            if (($fixed !== '' && (!is_numeric($fixed) || $fixed < 0 || $fixed > 600))
                || ($per !== '' && (!is_numeric($per) || $per < 0 || $per > 600))) {
                $errors[] = CloserPricing::SERVICES[$svc]['label'] . ' minutes must be between 0 and 600';
                continue;
            }
            $minutes[$svc] = ['fixed' => (float)($fixed ?: 0), 'per_unit' => (float)($per ?: 0)];
        }
        return ['ops' => $ops, 'minutes' => $minutes, 'errors' => $errors];
    }

    public function save(array $in, int $userId): array
    {
        $v = self::validate($in);
        if ($v['errors']) return ['ok' => false, 'errors' => $v['errors']];
        $st = $this->db->prepare("
            INSERT INTO ops_settings (setting_key, setting_value, description, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_by = VALUES(updated_by)
        ");
        foreach ($v['ops'] as $k => $val) {
            $st->execute([$k, $val, 'Set by Tim on the Closer rate card ' . date('Y-m-d'), $userId]);
        }
        $mm = $this->db->prepare("
            INSERT INTO closer_site_models (service_key, source, fixed_minutes, per_unit_minutes, per_obstacle_minutes, n, updated_by)
            VALUES (?, 'manual', ?, ?, 0, 0, ?)
            ON DUPLICATE KEY UPDATE fixed_minutes = VALUES(fixed_minutes), per_unit_minutes = VALUES(per_unit_minutes), updated_by = VALUES(updated_by)
        ");
        foreach ($v['minutes'] as $svc => $m) {
            $mm->execute([$svc, $m['fixed'], $m['per_unit'], $userId]);
        }
        return ['ok' => true, 'saved' => count($v['ops']) + count($v['minutes'])];
    }
}
