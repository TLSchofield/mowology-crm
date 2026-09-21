<?php
declare(strict_types=1);

/**
 * FieldSearchService — "find anything" for crew in the field.
 *
 * One box over clients, companies, building names, street addresses, phone numbers and
 * plan / visit numbers. Every hit resolves to a PROPERTY — that is what a crew member acts on
 * (navigate, call, open today's visit, add a visit) — and the ranking is a field ranking, not
 * an office one: on today's schedule first, then nearest, then alphabetical.
 *
 * Crew-safe by construction: no prices, no invoices, no crew names.
 *
 * Requires plan-functions.php (findNearbyProperties) and ServiceHistoryService to be loaded by
 * the caller — there is no autoloader.
 */
class FieldSearchService
{
    public const MIN_QUERY  = 2;
    public const MAX_RESULTS = 25;
    public const NEARBY_RADIUS_M = 400;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure rules ───────────────────────────────────────────────────────────

    /** Collapse whitespace; a query is only worth running from MIN_QUERY characters. */
    public static function normalize(?string $q): string
    {
        return trim((string)preg_replace('/\s+/', ' ', (string)$q));
    }

    /** "(778) 846-9273" and "778 846" are phone searches: compare digits to digits. */
    public static function phoneDigits(string $q): ?string
    {
        if (preg_match('/[a-z]/i', $q)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $q);
        // Under 5 digits is a street number, not a phone number.
        return strlen((string)$digits) >= 5 ? (string)$digits : null;
    }

    /** Escape LIKE wildcards so "100%" or "a_b" match literally. */
    public static function likeTerm(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }

    /**
     * Field ranking. Lower sorts first.
     * @param array{on_today:bool, distance_m:?int, label:string} $row
     */
    public static function rankKey(array $row): array
    {
        return [
            $row['on_today'] ? 0 : 1,
            $row['distance_m'] === null ? 1 : 0,          // somewhere we can measure beats nowhere
            $row['distance_m'] ?? PHP_INT_MAX,
            strtolower($row['label']),
        ];
    }

    /** Haversine, metres. */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r    = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return (int)round(2 * $r * asin(min(1.0, sqrt($a))));
    }

    public static function hasCoords($lat, $lng): bool
    {
        return $lat !== null && $lng !== null && (abs((float)$lat) > 0.0001 || abs((float)$lng) > 0.0001);
    }

    // ── Search ───────────────────────────────────────────────────────────────

    /**
     * @return array<int,array> property cards, ranked
     */
    public function search(string $query, ?float $lat, ?float $lng, string $today): array
    {
        $q = self::normalize($query);
        if (mb_strlen($q) < self::MIN_QUERY) {
            return [];
        }
        $like   = self::likeTerm($q);
        $digits = self::phoneDigits($q);

        $where  = [
            "p.address LIKE ?", "p.property_name LIKE ?", "p.city LIKE ?", "p.postal_code LIKE ?",
            "CONCAT_WS(' ', ct.first_name, ct.last_name) LIKE ?",
            // A property's company is linked two ways in this database: the company_properties
            // junction (what the schedule reads) and the older properties.company_id column.
            "EXISTS (SELECT 1 FROM companies co
                      WHERE co.company_name LIKE ?
                        AND (co.id = p.company_id
                             OR co.id IN (SELECT cp.company_id FROM company_properties cp WHERE cp.property_id = p.id)))",
            "EXISTS (SELECT 1 FROM job_plans jp2 WHERE jp2.property_id = p.id AND jp2.plan_number LIKE ?)",
            "EXISTS (SELECT 1 FROM job_visits jv2 JOIN job_plans jp3 ON jp3.id = jv2.plan_id
                      WHERE jp3.property_id = p.id AND jv2.visit_number LIKE ?)",
        ];
        $params = array_fill(0, count($where), $like);

        if ($digits !== null) {
            $strip    = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(%s,''),'-',''),' ',''),'(',''),')',''),'+','')";
            $where[]  = sprintf($strip, 'ct.phone') . " LIKE ?";
            $where[]  = sprintf($strip, 'ct.mobile') . " LIKE ?";
            $params[] = '%' . $digits . '%';
            $params[] = '%' . $digits . '%';
        }

        $ids = $this->db->prepare("
            SELECT p.id
            FROM properties p
            LEFT JOIN contacts ct ON ct.id = p.site_contact_id
            WHERE (p.status IS NULL OR p.status <> 'archived')
              AND (" . implode(' OR ', $where) . ")
            LIMIT 200
        ");
        $ids->execute($params);

        return $this->cards(array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN)), $lat, $lng, $today, self::MAX_RESULTS);
    }

    /** The empty-search state: what is around the crew member right now. */
    public function nearby(float $lat, float $lng, string $today): array
    {
        $rows = findNearbyProperties($lat, $lng, self::NEARBY_RADIUS_M, 12);
        return $this->cards(array_map(static fn (array $r): int => (int)$r['id'], $rows), $lat, $lng, $today, 12);
    }

    /**
     * Build ranked result cards for a set of property ids.
     * @param int[] $propertyIds
     */
    public function cards(array $propertyIds, ?float $lat, ?float $lng, string $today, int $limit): array
    {
        $propertyIds = array_values(array_unique(array_filter($propertyIds)));
        if (!$propertyIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($propertyIds), '?'));

        $stmt = $this->db->prepare("
            SELECT p.id, p.property_name, p.address, p.city, p.latitude, p.longitude,
                   TRIM(CONCAT_WS(' ', ct.first_name, ct.last_name)) AS contact_name,
                   COALESCE(NULLIF(ct.mobile, ''), ct.phone) AS phone,
                   (SELECT co.company_name FROM companies co
                     WHERE co.id = COALESCE(
                         (SELECT MIN(cp.company_id) FROM company_properties cp WHERE cp.property_id = p.id),
                         p.company_id)) AS company_name
            FROM properties p
            LEFT JOIN contacts ct ON ct.id = p.site_contact_id
            WHERE p.id IN ($in)
        ");
        $stmt->execute($propertyIds);
        $props = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $plans  = $this->plansFor($propertyIds, $today);
        $cards  = [];
        foreach ($props as $p) {
            $id     = (int)$p['id'];
            $coords = self::hasCoords($p['latitude'], $p['longitude']);
            $dist   = ($coords && $lat !== null && $lng !== null)
                ? self::distanceM($lat, $lng, (float)$p['latitude'], (float)$p['longitude'])
                : null;

            $propPlans  = $plans[$id] ?? [];
            $todayVisit = null;
            foreach ($propPlans as $pl) {
                if ($pl['today_visit_id'] !== null) { $todayVisit = $pl['today_visit_id']; break; }
            }

            $name  = trim((string)($p['property_name'] ?? ''));
            $label = $name !== '' ? $name
                   : (trim((string)($p['company_name'] ?? '')) ?: (trim((string)$p['contact_name']) ?: (string)$p['address']));

            $cards[] = [
                'id'             => $id,
                'label'          => $label,
                'property_name'  => $name !== '' ? $name : null,
                'company_name'   => $p['company_name'] ?: null,
                'contact_name'   => $p['contact_name'] !== '' ? $p['contact_name'] : null,
                'phone'          => $p['phone'] ?: null,
                'address'        => (string)$p['address'],
                'city'           => $p['city'],
                'latitude'       => $coords ? (float)$p['latitude'] : null,
                'longitude'      => $coords ? (float)$p['longitude'] : null,
                'distance_m'     => $dist,
                'on_today'       => $todayVisit !== null,
                'today_visit_id' => $todayVisit,
                'plans'          => array_map(static function (array $pl): array {
                    unset($pl['today_visit_id']);
                    return $pl;
                }, $propPlans),
                'last_done'      => $this->lastDoneLine($propPlans),
            ];
        }

        usort($cards, static fn (array $a, array $b): int => self::rankKey($a) <=> self::rankKey($b));
        return array_slice($cards, 0, $limit);
    }

    /** Active plans per property, with today's visit and the last completion. */
    private function plansFor(array $propertyIds, string $today): array
    {
        $in   = implode(',', array_fill(0, count($propertyIds), '?'));
        $stmt = $this->db->prepare("
            SELECT jp.id, jp.property_id, jp.title, jp.service_type,
                   (SELECT jv.id FROM job_visits jv
                     WHERE jv.plan_id = jp.id AND jv.scheduled_date = ? AND jv.status IN ('scheduled','in_progress')
                     ORDER BY jv.id LIMIT 1) AS today_visit_id,
                   (SELECT COUNT(*) FROM job_visits jv
                     WHERE jv.plan_id = jp.id AND jv.scheduled_date = ? AND jv.status <> 'cancelled') AS today_count,
                   (SELECT MAX(COALESCE(jv.completed_at, CONCAT(jv.scheduled_date, ' 00:00:00'))) FROM job_visits jv
                     WHERE jv.plan_id = jp.id AND jv.status = 'completed') AS last_done
            FROM job_plans jp
            WHERE jp.status = 'active' AND jp.property_id IN ($in)
            ORDER BY jp.id DESC
        ");
        $stmt->execute(array_merge([$today, $today], $propertyIds));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $winter = ServiceHistoryService::isWinterService($row['service_type'], $row['title']);
            $out[(int)$row['property_id']][] = [
                'id'              => (int)$row['id'],
                'title'           => (string)$row['title'],
                'service_type'    => $row['service_type'],
                'has_visit_today' => (int)$row['today_count'] > 0,
                'today_visit_id'  => $row['today_visit_id'] !== null ? (int)$row['today_visit_id'] : null,
                'last_done'       => $row['last_done'],
                'summary'         => $row['last_done'] !== null
                    ? ServiceHistoryService::summary($row['last_done'], null, $today, $winter)
                    : null,
            ];
        }
        return $out;
    }

    /** One line for the result row: the most recently serviced plan speaks for the property. */
    private function lastDoneLine(array $plans): ?string
    {
        $best = null;
        foreach ($plans as $pl) {
            if ($pl['last_done'] !== null && ($best === null || $pl['last_done'] > $best['last_done'])) {
                $best = $pl;
            }
        }
        if ($best === null) {
            return null;
        }
        return count($plans) > 1 ? $best['title'] . ': ' . lcfirst((string)$best['summary']) : $best['summary'];
    }

    // ── Property page ────────────────────────────────────────────────────────

    /** Everything the property page shows. Null when the property does not exist. */
    public function property(int $propertyId, ?float $lat, ?float $lng, string $today): ?array
    {
        $cards = $this->cards([$propertyId], $lat, $lng, $today, 1);
        if (!$cards) {
            return null;
        }
        $card = $cards[0];

        $notes = $this->db->prepare("SELECT notes, lawn_size_sqft FROM properties WHERE id = ?");
        $notes->execute([$propertyId]);
        $extra = $notes->fetch(PDO::FETCH_ASSOC) ?: [];

        // Two calendar weeks per plan — the same grid the job cards show. The history service is
        // keyed by visit, so hand it each plan's most recent visit.
        $planIds = array_map(static fn (array $p): int => $p['id'], $card['plans']);
        $history = [];
        if ($planIds) {
            $pin = implode(',', array_fill(0, count($planIds), '?'));
            $rep = $this->db->prepare("SELECT plan_id, MAX(id) AS visit_id FROM job_visits WHERE plan_id IN ($pin) GROUP BY plan_id");
            $rep->execute($planIds);
            $repByPlan = [];
            foreach ($rep->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $repByPlan[(int)$r['plan_id']] = (int)$r['visit_id'];
            }
            // Pass NO visit to exclude: on this page every visit counts toward "last done".
            $byVisit = (new ServiceHistoryService($this->db))->forVisits(array_values($repByPlan), $today, false);
            foreach ($repByPlan as $planId => $visitId) {
                $history[$planId] = $byVisit[$visitId] ?? null;
            }
        }
        foreach ($card['plans'] as &$pl) {
            $pl['history'] = $history[$pl['id']] ?? null;
        }
        unset($pl);

        $up = $this->db->prepare("
            SELECT jv.id AS visit_id, jv.visit_number, jv.scheduled_date, jv.status, jp.title AS plan_title
            FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
            WHERE jp.property_id = ? AND jv.scheduled_date >= ? AND jv.status IN ('scheduled','in_progress')
            ORDER BY jv.scheduled_date, jv.id LIMIT 6
        ");
        $up->execute([$propertyId, $today]);

        $card['notes']     = trim((string)($extra['notes'] ?? '')) ?: null;
        $card['lawn_sqft'] = !empty($extra['lawn_size_sqft']) ? (int)$extra['lawn_size_sqft'] : null;
        $card['upcoming']  = array_map(static fn (array $r): array => [
            'visit_id'       => (int)$r['visit_id'],
            'visit_number'   => (string)$r['visit_number'],
            'scheduled_date' => (string)$r['scheduled_date'],
            'status'         => (string)$r['status'],
            'plan_title'     => (string)$r['plan_title'],
        ], $up->fetchAll(PDO::FETCH_ASSOC));

        return $card;
    }
}
