<?php
declare(strict_types=1);

/**
 * ClusterDetectionService
 *
 * Auto-detects property clusters using two signals:
 *   1. Geographic proximity  — Haversine distance between property coordinates
 *   2. Schedule co-occurrence — properties that appear adjacent on the same crew route
 *
 * Detection never writes clusters automatically. It returns scored candidates
 * for admin review. Approving a candidate calls ClusterService::createCluster().
 */
class ClusterDetectionService
{
    private const PROXIMITY_THRESHOLD_M  = 200.0; // metres — candidate pairs
    private const PROXIMITY_HIGH_M       = 80.0;  // metres — high-confidence proximity
    private const COOCCUR_MIN_COUNT      = 3;     // minimum shared route appearances
    private const COOCCUR_ADJACENT_STOPS = 2;     // max route_order gap to count as adjacent

    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run the full detection pipeline and return scored cluster candidates.
     * Each candidate is an array:
     *   ['property_ids' => int[], 'confidence' => 'high'|'medium'|'low',
     *    'score' => float, 'proximity_m' => float|null, 'cooccur_count' => int,
     *    'suggested_name' => string, 'already_clustered' => bool]
     */
    public function detectCandidates(): array
    {
        $properties  = $this->getActivePropertiesWithCoords();
        $proxPairs   = $this->buildProximityPairs($properties);
        $coocPairs   = $this->buildCooccurrencePairs();
        $scoredPairs = $this->scorePairs($proxPairs, $coocPairs);
        $grouped     = $this->groupIntoClusters($scoredPairs, array_column($properties, null, 'id'));
        $existingIds = $this->getExistingClusteredPropertyIds();

        return $this->formatCandidates($grouped, $properties, $existingIds);
    }

    /**
     * Run proximity-only detection (no schedule history needed).
     * Useful on first install before route history has accumulated.
     */
    public function detectByProximityOnly(): array
    {
        $properties  = $this->getActivePropertiesWithCoords();
        $proxPairs   = $this->buildProximityPairs($properties);
        $scoredPairs = $this->scorePairs($proxPairs, []);
        $grouped     = $this->groupIntoClusters($scoredPairs, array_column($properties, null, 'id'));
        $existingIds = $this->getExistingClusteredPropertyIds();

        return $this->formatCandidates($grouped, $properties, $existingIds);
    }

    /**
     * Check whether a single newly-added property is near any existing property.
     * Returns array of nearby properties with distance, or empty array.
     */
    public function findNearbyProperties(int $propertyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, latitude, longitude FROM properties
             WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
               AND id != ?'
        );
        $stmt->execute([$propertyId]);
        $others = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $target = $this->db->prepare(
            'SELECT id, latitude, longitude FROM properties WHERE id = ?'
        );
        $target->execute([$propertyId]);
        $prop = $target->fetch(\PDO::FETCH_ASSOC);

        if (!$prop || $prop['latitude'] === null) return [];

        $nearby = [];
        foreach ($others as $other) {
            if ($other['latitude'] === null) continue;
            $dist = $this->haversineMetres(
                (float)$prop['latitude'], (float)$prop['longitude'],
                (float)$other['latitude'], (float)$other['longitude']
            );
            if ($dist <= self::PROXIMITY_THRESHOLD_M) {
                $nearby[] = ['property_id' => (int)$other['id'], 'distance_m' => round($dist, 1)];
            }
        }

        usort($nearby, fn($a, $b) => $a['distance_m'] <=> $b['distance_m']);
        return $nearby;
    }

    // -------------------------------------------------------------------------
    // Data gathering
    // -------------------------------------------------------------------------

    private function getActivePropertiesWithCoords(): array
    {
        $stmt = $this->db->query(
            'SELECT p.id, p.address, p.city,
                    COALESCE(p.latitude, gc.latitude) AS lat,
                    COALESCE(p.longitude, gc.longitude) AS lng,
                    c.first_name, c.last_name
             FROM properties p
             LEFT JOIN contacts c ON c.id = p.site_contact_id
             LEFT JOIN geocoding_cache gc ON gc.address = CONCAT(p.address, \', \', p.city)
             WHERE p.is_active = 1
               AND (p.latitude IS NOT NULL OR gc.latitude IS NOT NULL)'
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function buildProximityPairs(array $properties): array
    {
        $pairs = [];
        $count = count($properties);

        for ($i = 0; $i < $count; $i++) {
            $a = $properties[$i];
            if ($a['lat'] === null) continue;

            for ($j = $i + 1; $j < $count; $j++) {
                $b = $properties[$j];
                if ($b['lat'] === null) continue;

                $dist = $this->haversineMetres(
                    (float)$a['lat'], (float)$a['lng'],
                    (float)$b['lat'], (float)$b['lng']
                );

                if ($dist <= self::PROXIMITY_THRESHOLD_M) {
                    $pairs["{$a['id']}:{$b['id']}"] = [
                        'prop_a' => (int)$a['id'],
                        'prop_b' => (int)$b['id'],
                        'distance_m' => round($dist, 1),
                    ];
                }
            }
        }

        return $pairs;
    }

    private function buildCooccurrencePairs(): array
    {
        $stmt = $this->db->prepare(
            'SELECT cs1.property_id AS prop_a,
                    cs2.property_id AS prop_b,
                    COUNT(*) AS times_together
             FROM calendar_stops cs1
             JOIN calendar_stops cs2
                 ON  cs1.stop_date   = cs2.stop_date
                 AND cs1.crew_id     = cs2.crew_id
                 AND cs1.property_id < cs2.property_id
                 AND ABS(cs1.route_order - cs2.route_order) <= ?
             GROUP BY cs1.property_id, cs2.property_id
             HAVING COUNT(*) >= ?'
        );
        $stmt->execute([self::COOCCUR_ADJACENT_STOPS, self::COOCCUR_MIN_COUNT]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $pairs = [];
        foreach ($rows as $row) {
            $pairs["{$row['prop_a']}:{$row['prop_b']}"] = [
                'prop_a' => (int)$row['prop_a'],
                'prop_b' => (int)$row['prop_b'],
                'times_together' => (int)$row['times_together'],
            ];
        }
        return $pairs;
    }

    private function getExistingClusteredPropertyIds(): array
    {
        $stmt = $this->db->query(
            'SELECT pcm.property_id
             FROM property_cluster_members pcm
             JOIN property_clusters pc ON pc.id = pcm.cluster_id
             WHERE pc.is_active = 1'
        );
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'property_id');
    }

    // -------------------------------------------------------------------------
    // Scoring & grouping
    // -------------------------------------------------------------------------

    /**
     * Combine proximity and co-occurrence into a single score per pair.
     * Score 0.0–1.0: higher = more confident cluster.
     */
    private function scorePairs(array $proxPairs, array $coocPairs): array
    {
        $allKeys = array_unique(array_merge(array_keys($proxPairs), array_keys($coocPairs)));
        $scored  = [];

        foreach ($allKeys as $key) {
            $prox = $proxPairs[$key] ?? null;
            $cooc = $coocPairs[$key] ?? null;

            [$propA, $propB] = explode(':', $key);

            $proxScore = 0.0;
            $coocScore = 0.0;

            if ($prox) {
                $dist = $prox['distance_m'];
                // Linear scale: 0m = 1.0, THRESHOLD = 0.0
                $proxScore = max(0.0, 1.0 - ($dist / self::PROXIMITY_THRESHOLD_M));
                // Bonus for very close properties
                if ($dist <= self::PROXIMITY_HIGH_M) {
                    $proxScore = min(1.0, $proxScore + 0.3);
                }
            }

            if ($cooc) {
                // Saturates at ~10 co-occurrences
                $coocScore = min(1.0, $cooc['times_together'] / 10.0);
            }

            // Weight: both signals = high confidence; either alone = lower
            if ($prox && $cooc) {
                $score = 0.5 * $proxScore + 0.5 * $coocScore;
            } elseif ($prox) {
                $score = 0.6 * $proxScore; // proximity alone is less certain
            } else {
                $score = 0.6 * $coocScore; // co-occurrence alone (far apart but always together)
            }

            // Skip very weak signals (proximity only, borderline distance, never co-serviced)
            if ($score < 0.15) continue;

            $confidence = match(true) {
                $score >= 0.65 && $prox && $cooc => 'high',
                $score >= 0.40                   => 'medium',
                default                          => 'low',
            };

            $scored[$key] = [
                'prop_a'        => (int)$propA,
                'prop_b'        => (int)$propB,
                'score'         => round($score, 3),
                'confidence'    => $confidence,
                'distance_m'    => $prox ? $prox['distance_m'] : null,
                'cooccur_count' => $cooc ? $cooc['times_together'] : 0,
            ];
        }

        return $scored;
    }

    /**
     * Union-find: group individual pairs into multi-property clusters.
     * If A–B and B–C both score high, A+B+C form one cluster.
     */
    private function groupIntoClusters(array $scoredPairs, array $propertiesById): array
    {
        $parent = [];
        $rank   = [];
        $meta   = []; // store best edge data per pair

        $find = function (int $x) use (&$find, &$parent): int {
            if (!isset($parent[$x])) $parent[$x] = $x;
            if ($parent[$x] !== $x) $parent[$x] = $find($parent[$x]);
            return $parent[$x];
        };

        $union = function (int $x, int $y) use (&$find, &$parent, &$rank): void {
            $rx = $find($x); $ry = $find($y);
            if ($rx === $ry) return;
            $rankX = $rank[$rx] ?? 0;
            $rankY = $rank[$ry] ?? 0;
            if ($rankX < $rankY) { $parent[$rx] = $ry; }
            elseif ($rankX > $rankY) { $parent[$ry] = $rx; }
            else { $parent[$ry] = $rx; $rank[$rx] = ($rank[$rx] ?? 0) + 1; }
        };

        foreach ($scoredPairs as $pair) {
            $union($pair['prop_a'], $pair['prop_b']);
            $key = min($pair['prop_a'], $pair['prop_b']) . ':' . max($pair['prop_a'], $pair['prop_b']);
            $meta[$key] = $pair;
        }

        // Collect components
        $components = [];
        foreach ($scoredPairs as $pair) {
            $root = $find($pair['prop_a']);
            if (!isset($components[$root])) $components[$root] = [];
            $components[$root][$pair['prop_a']] = true;
            $components[$root][$pair['prop_b']] = true;
        }

        // Aggregate scores per component
        $clusters = [];
        foreach ($components as $root => $memberSet) {
            $memberIds = array_keys($memberSet);
            if (count($memberIds) < 2) continue;

            $scores      = [];
            $minDist     = null;
            $maxCooccur  = 0;
            $hasCooccur  = false;
            $hasProx     = false;

            foreach ($scoredPairs as $pair) {
                if (!isset($memberSet[$pair['prop_a']]) || !isset($memberSet[$pair['prop_b']])) continue;
                $scores[] = $pair['score'];
                if ($pair['distance_m'] !== null) { $hasProx = true; $minDist = min($minDist ?? PHP_INT_MAX, $pair['distance_m']); }
                if ($pair['cooccur_count'] > 0)   { $hasCooccur = true; $maxCooccur = max($maxCooccur, $pair['cooccur_count']); }
            }

            $avgScore  = count($scores) ? array_sum($scores) / count($scores) : 0;
            $confidence = match(true) {
                $avgScore >= 0.65 && $hasProx && $hasCooccur => 'high',
                $avgScore >= 0.40                             => 'medium',
                default                                       => 'low',
            };

            $clusters[] = [
                'property_ids'   => $memberIds,
                'score'          => round($avgScore, 3),
                'confidence'     => $confidence,
                'proximity_m'    => $minDist !== null ? round($minDist, 1) : null,
                'cooccur_count'  => $maxCooccur,
            ];
        }

        usort($clusters, fn($a, $b) => $b['score'] <=> $a['score']);
        return $clusters;
    }

    private function formatCandidates(array $grouped, array $properties, array $existingIds): array
    {
        $propMap = array_column($properties, null, 'id');
        $candidates = [];

        foreach ($grouped as $group) {
            $names = [];
            foreach ($group['property_ids'] as $pid) {
                $p = $propMap[$pid] ?? null;
                if ($p) {
                    $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                    $names[] = $name ?: $p['address'];
                }
            }

            $alreadyClustered = count(array_intersect($group['property_ids'], $existingIds)) > 0;

            $candidates[] = array_merge($group, [
                'suggested_name'   => implode(' / ', $names),
                'already_clustered'=> $alreadyClustered,
                'member_details'   => array_map(fn($pid) => [
                    'property_id' => $pid,
                    'name'        => isset($propMap[$pid])
                        ? trim(($propMap[$pid]['first_name'] ?? '') . ' ' . ($propMap[$pid]['last_name'] ?? ''))
                        : '',
                    'address'     => $propMap[$pid]['address'] ?? '',
                    'city'        => $propMap[$pid]['city'] ?? '',
                ], $group['property_ids']),
            ]);
        }

        return $candidates;
    }

    // -------------------------------------------------------------------------
    // Geometry
    // -------------------------------------------------------------------------

    private function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R  = 6371000.0;
        $φ1 = deg2rad($lat1); $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lng2 - $lng1);
        $a  = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
        return $R * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
    }
}
