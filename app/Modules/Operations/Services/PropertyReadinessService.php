<?php
/**
 * PropertyReadinessService — what Otto (operations head) still needs before he can route
 * to a property: a map pin, an arrival border, and a lawn measurement.
 *
 *   pin       properties.latitude / longitude both non-zero
 *   border    a job_geofences row with zone_type 'arrival_border' (null = table missing, unknown)
 *   measured  total_lawn_sqft, falling back to lawn_size_sqft, > 0 (as CloserService::lotFromRow)
 *
 * Read-only. A missing table or column is "unknown", never a fatal: the card that uses this
 * sits on quote and property pages and must never break them.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PropertyReadinessService
{
    public const ORDER = ['pin', 'border', 'measured'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{property: ?array, pin: bool, border: ?bool, measured: bool, missing: string[]}
     *         property is null (and missing empty) when the property does not exist.
     */
    public function gaps(int $propertyId): array
    {
        $empty = ['property' => null, 'pin' => false, 'border' => null, 'measured' => false, 'missing' => []];
        if ($propertyId < 1) {
            return $empty;
        }
        // SELECT * so a column absent on an older schema simply reads as empty.
        $st = $this->db->prepare('SELECT * FROM properties WHERE id = ?');
        if (!$st || !$st->execute([$propertyId])) {
            return $empty;
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $empty;
        }

        $pin      = self::hasPin($row);
        $measured = self::isMeasured($row);
        $border   = $this->hasBorder($propertyId);

        return [
            'property' => [
                'id'          => (int)$row['id'],
                'address'     => (string)($row['address'] ?? ''),
                'city'        => (string)($row['city'] ?? ''),
                'province'    => (string)($row['province'] ?? ''),
                'postal_code' => (string)($row['postal_code'] ?? ''),
            ],
            'pin'      => $pin,
            'border'   => $border,
            'measured' => $measured,
            'missing'  => self::missing($pin, $border, $measured),
        ];
    }

    /**
     * Properties with a scheduled visit between $from and $to (Y-m-d) and no map pin —
     * the ones Otto can't route to. Soonest visit first. For the phone's Otto card.
     * @return array<int, array{id: int, address: string, city: string, province: string, postal_code: string, next_visit: string, geocode_address: string}>
     */
    public function unpinnedUpcoming(string $from, string $to, int $limit = 3): array
    {
        try {
            $st = $this->db->prepare("
                SELECT prop.id, prop.address, prop.city, prop.province, prop.postal_code, MIN(v.scheduled_date) AS next_visit
                FROM job_visits v
                JOIN job_plans p ON p.id = v.plan_id
                JOIN properties prop ON prop.id = p.property_id
                WHERE v.scheduled_date BETWEEN ? AND ?
                  AND v.status = 'scheduled'
                  AND (prop.latitude IS NULL OR prop.latitude = 0 OR prop.longitude IS NULL OR prop.longitude = 0)
                GROUP BY prop.id, prop.address, prop.city, prop.province, prop.postal_code
                ORDER BY next_visit, prop.id
                LIMIT " . max(1, min(20, $limit))
            );
            $st->execute([$from, $to]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Otto unpinned: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'              => (int)$r['id'],
                'address'         => (string)($r['address'] ?? ''),
                'city'            => (string)($r['city'] ?? ''),
                'province'        => (string)($r['province'] ?? ''),
                'postal_code'     => (string)($r['postal_code'] ?? ''),
                'next_visit'      => (string)$r['next_visit'],
                'geocode_address' => self::geocodeAddress($r),
            ];
        }
        return $out;
    }

    /**
     * Save a geocoded pin — the same write as /crm/api/geocode-save.php (the web Geocode
     * button's save). Coordinates must be real (non-zero, in range).
     * @return array{ok: bool, message?: string, lat?: float, lng?: float}
     */
    public function savePin(int $propertyId, float $lat, float $lng): array
    {
        if ($propertyId < 1 || !self::validPin($lat, $lng)) {
            return ['ok' => false, 'message' => 'Invalid property or coordinates'];
        }
        $st = $this->db->prepare("UPDATE properties SET latitude = ?, longitude = ?, geocoded_at = NOW() WHERE id = ?");
        $st->execute([$lat, $lng, $propertyId]);
        if ($st->rowCount() < 1) {
            $chk = $this->db->prepare("SELECT 1 FROM properties WHERE id = ?");
            $chk->execute([$propertyId]);
            if ($chk->fetchColumn() === false) return ['ok' => false, 'message' => 'That property no longer exists'];
        }
        return ['ok' => true, 'lat' => $lat, 'lng' => $lng];
    }

    public static function validPin(float $lat, float $lng): bool
    {
        return $lat != 0.0 && $lng != 0.0 && abs($lat) <= 90 && abs($lng) <= 180;
    }

    /** null when job_geofences is absent or unreadable. */
    private function hasBorder(int $propertyId): ?bool
    {
        try {
            $st = $this->db->prepare("SELECT 1 FROM job_geofences WHERE property_id = ? AND zone_type = 'arrival_border' LIMIT 1");
            if (!$st || !$st->execute([$propertyId])) {
                return null;
            }
            return $st->fetchColumn() !== false;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure
    // ─────────────────────────────────────────────────────────────────────────

    public static function hasPin(array $row): bool
    {
        return (float)($row['latitude'] ?? 0) != 0 && (float)($row['longitude'] ?? 0) != 0;
    }

    public static function isMeasured(array $row): bool
    {
        $lawn = (float)($row['total_lawn_sqft'] ?? 0) > 0 ? (float)$row['total_lawn_sqft'] : (float)($row['lawn_size_sqft'] ?? 0);
        return $lawn > 0;
    }

    /** @return string[] subset of ORDER, in ORDER. An unknown border (null) is not a gap. */
    public static function missing(bool $pin, ?bool $border, bool $measured): array
    {
        $out = [];
        if (!$pin) $out[] = 'pin';
        if ($border === false) $out[] = 'border';
        if (!$measured) $out[] = 'measured';
        return $out;
    }

    /** One line in Otto's plain voice. */
    public static function message(array $missing): string
    {
        $missing = array_values(array_intersect(self::ORDER, $missing));
        if (!$missing) {
            return 'All set — I can route here now.';
        }
        $route = [];
        if (in_array('pin', $missing, true)) $route[] = 'no map pin';
        if (in_array('border', $missing, true)) $route[] = 'no arrival border';
        $measure = in_array('measured', $missing, true);

        if (!$route) {
            return "One thing before I can plan this: it hasn't been measured.";
        }
        $msg = "I can't route here yet: " . implode(', ', $route) . '.';
        if (count($route) === 2) {
            $msg .= ' Pin it first, then draw the border.';
        }
        if ($measure) {
            $msg .= " It hasn't been measured either.";
        }
        return $msg;
    }

    /** The address Google geocodes, as quotes/create.php builds it. */
    public static function geocodeAddress(array $property): string
    {
        $parts = [];
        foreach (['address', 'city', 'province', 'postal_code'] as $k) {
            $v = trim((string)($property[$k] ?? ''));
            if ($v !== '') $parts[] = $v;
        }
        if (!$parts) {
            return '';
        }
        $parts[] = 'Canada';
        return implode(', ', $parts);
    }
}
