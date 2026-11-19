<?php
/**
 * GeofenceSampleFilter — what the polygon tracker's sample sync may store (2026-10-12).
 *
 * `sync_samples` stored any sample, for any visit, at any claimed time, as often as it was
 * re-sent. Now (GeofenceApi): the caller must be crew on the visit (or office), each sample's
 * device time must be within MAX_FUTURE_SECONDS ahead / MAX_AGE_SECONDS behind now (same bounds
 * as TrackingIngestService), and a sample whose second is already stored for the visit — or
 * repeated in the batch — is a duplicate, not a new row.
 *
 * Global-namespace: require_once. PURE (unit-tested in tests/Unit/Geofence/GeofenceSampleFilterTest.php).
 */
declare(strict_types=1);

class GeofenceSampleFilter
{
    public const MAX_FUTURE_SECONDS = 300;
    public const MAX_AGE_SECONDS    = 259200;   // 72 h
    public const MAX_BATCH          = 1000;

    /**
     * @param array    $samples     raw samples; `timestamp` in ms (or s) epoch
     * @param string[] $existing    'Y-m-d H:i:s' sampled_at values already stored for the visit
     * @return array{keep: list<array>, rejected: int, duplicates: int}
     *         kept samples carry `timestamp` normalised to milliseconds
     */
    public static function filter(array $samples, int $nowTs, array $existing = []): array
    {
        $seen = array_fill_keys($existing, true);
        $keep = [];
        $rejected = 0;
        $dupes = 0;
        foreach (array_slice($samples, 0, self::MAX_BATCH) as $s) {
            if (!is_array($s) || !isset($s['timestamp']) || !is_numeric($s['timestamp'])) { $rejected++; continue; }
            $t  = (float)$s['timestamp'];
            $ts = (int)($t > 1.0e11 ? $t / 1000 : $t);
            if ($ts > $nowTs + self::MAX_FUTURE_SECONDS || $ts < $nowTs - self::MAX_AGE_SECONDS) { $rejected++; continue; }
            $key = date('Y-m-d H:i:s', $ts);
            if (isset($seen[$key])) { $dupes++; continue; }
            $seen[$key] = true;
            $s['timestamp'] = $ts * 1000;
            $keep[] = $s;
        }
        return ['keep' => $keep, 'rejected' => $rejected + max(0, count($samples) - self::MAX_BATCH), 'duplicates' => $dupes];
    }
}
