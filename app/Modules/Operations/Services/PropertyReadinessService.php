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
