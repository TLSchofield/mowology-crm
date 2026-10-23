<?php
/**
 * CityParcelService — a city's own lot map, looked up by street address (Otto's arrival borders).
 *
 * Why: the province's public lot map (ParcelLotService, ParcelMap BC) has gaps. Three Vancouver
 * Management buildings (1535 W 16th, 2025 W 42nd, 825 SW Marine) have no lot in it at all — the nearest
 * lot is a neighbour's, 43 m+ away — so Otto rightly drew nothing (owner, 2026-10-19). The City of
 * Vancouver publishes every lot with its civic address, so for a Vancouver property Otto asks the City
 * first, by ADDRESS (a pin on the sidewalk can't pick the wrong lot), then falls back to the province.
 *
 * Tenant-neutral: cities are entries in SOURCES; another city is another entry (and its own adapter if
 * its API differs). A property whose city has no source gets null and the province map as before.
 *
 * Safety:
 *  - the address must match one street exactly (number + direction + name, the street type only breaks ties);
 *  - every matched lot must lie within PIN_MAX_M of the property's map pin (a wrong address never draws);
 *  - several lots at one address are joined only when they touch and the joined outline isn't much bigger
 *    than the lots themselves (same rule as strata plans); otherwise the lot under the pin, or nothing.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/PropertyBorderRules.php';
require_once __DIR__ . '/ParcelLotService.php';

require_once __DIR__ . '/HttpFetch.php';

class CityParcelService
{
    public const SOURCES = [
        'vancouver' => [
            'url'         => 'https://opendata.vancouver.ca/api/explore/v2.1/catalog/datasets/property-parcel-polygons/records',
            'name'        => 'City of Vancouver lot map',
            'attribution' => 'Contains information licensed under the Open Government Licence – Vancouver.',
        ],
    ];
    public const PIN_MAX_M = 120.0;     // matched lots must be this close to the map pin
    public const TOUCH_M   = 1.5;       // lots this close are neighbours sharing a line
    public const MAX_HITS  = 100;       // one civic number across the whole city

    private const DIRS = ['north' => 'N', 'south' => 'S', 'east' => 'E', 'west' => 'W',
                          'northwest' => 'NW', 'northeast' => 'NE', 'southwest' => 'SW', 'southeast' => 'SE'];
    private const TYPES = ['AV', 'AVE', 'AVENUE', 'ST', 'STREET', 'DR', 'DRIVE', 'RD', 'ROAD', 'BLVD', 'BOULEVARD',
                           'CRES', 'CRESCENT', 'PL', 'PLACE', 'WAY', 'LANE', 'LN', 'CT', 'COURT', 'HWY', 'HIGHWAY',
                           'MALL', 'SQ', 'SQUARE', 'TERR', 'TERRACE', 'GR', 'GREEN', 'WALK', 'ROW', 'PKWY', 'PARKWAY', 'DIVERSION'];
    private const TYPE_CANON = ['AVE' => 'AV', 'AVENUE' => 'AV', 'STREET' => 'ST', 'DR' => 'DRIVE', 'ROAD' => 'RD',
                                'BOULEVARD' => 'BLVD', 'CRESCENT' => 'CRES', 'PLACE' => 'PL', 'LN' => 'LANE', 'COURT' => 'CT',
                                'HIGHWAY' => 'HWY', 'SQUARE' => 'SQ', 'TERRACE' => 'TERR', 'GREEN' => 'GR', 'PARKWAY' => 'PKWY'];

    /** @var callable(string): ?string */
    private $fetch;

    public function __construct(?callable $fetch = null)
    {
        $this->fetch = $fetch ?? static function (string $url): ?string {
            return HttpFetch::get($url, 12, ['User-Agent: Mowology-CRM/1.0 (arrival borders; office@mowology.ca)']);
        };
    }

    /** The source for a property's city, or null when no city map covers it. */
    public static function sourceFor(array $property): ?string
    {
        $city = strtolower(trim(preg_replace('/\s+/', ' ', (string)($property['city'] ?? ''))));
        return isset(self::SOURCES[$city]) ? $city : null;   // "North Vancouver" / "West Vancouver" are other cities
    }

    /**
     * The lot at this property's address, from its city's map.
     * @return array|null null = no city map for this city (use the province); else the same shape as
     *         ParcelLotService::lotAt plus 'source' and 'attribution'.
     */
    public function lotFor(array $property, array $pin): ?array
    {
        $src = self::sourceFor($property);
        if ($src === null) return null;
        $addr = self::parseAddress((string)($property['address'] ?? ''));
        if ($addr === null) return ['ok' => false, 'reason' => 'no_address_match'];

        $where = 'civic_number="' . $addr['number'] . '"';
        $url = self::SOURCES[$src]['url'] . '?' . http_build_query(['limit' => (string)self::MAX_HITS, 'where' => $where]);
        $body = ($this->fetch)($url);
        $j = $body === null ? null : json_decode($body, true);
        if (!is_array($j) || !isset($j['results']) || !is_array($j['results'])) return ['ok' => false, 'reason' => 'unavailable'];

        $lots = self::matchStreet($j['results'], $addr);
        if (!$lots) return ['ok' => false, 'reason' => 'no_address_match'];
        $lot = self::joinLots($lots, $pin);
        if (!$lot['ok']) return $lot;
        $lot += ['class' => 'City lot', 'plan' => '', 'municipality' => ucfirst($src), 'source' => $src,
                 'attribution' => self::SOURCES[$src]['attribution'], 'map_name' => self::SOURCES[$src]['name']];
        return ParcelLotService::checkArea($lot);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * "1535 West 16th Avenue", "#305 - 1535 W 16th Ave", "305-1535 W. 16th Ave." →
     * ['number' => '1535', 'core' => 'W 16', 'type' => 'AV']. Null when there is no civic number.
     */
    public static function parseAddress(string $address): ?array
    {
        $a = strtoupper(trim(preg_replace('/\s+/', ' ', explode(',', $address)[0])));
        $a = preg_replace('/^(UNIT|SUITE|APT|#)\s*[A-Z0-9]+\s*[-,]?\s*/', '', $a);   // "#305 - ", "SUITE 2, "
        $a = preg_replace('/^[A-Z0-9]+\s*-\s*(?=\d)/', '', $a);                     // "305-1535 …"
        $a = str_replace('.', '', $a);
        if (!preg_match('/^(\d+)[A-Z]?\s+(.+)$/', $a, $m)) return null;
        [$core, $type] = self::streetKey($m[2]);
        return $core === '' ? null : ['number' => $m[1], 'core' => $core, 'type' => $type];
    }

    /** Street words → [direction + name with ordinals bare, canonical street type]. */
    public static function streetKey(string $street): array
    {
        $words = preg_split('/\s+/', strtoupper(trim(str_replace('.', '', $street)))) ?: [];
        $type = '';
        $core = [];
        foreach ($words as $w) {
            if ($w === '') continue;
            $lw = strtolower($w);
            if (isset(self::DIRS[$lw])) { $core[] = self::DIRS[$lw]; continue; }
            if (in_array($w, self::TYPES, true)) { $type = self::TYPE_CANON[$w] ?? $w; continue; }
            $core[] = preg_replace('/^(\d+)(ST|ND|RD|TH)$/', '$1', $w);
        }
        return [implode(' ', $core), $type];
    }

    /**
     * The lots on the property's street. One street name only: two streets with the same core
     * (e.g. "Oak St" and "Oak Av") are told apart by the street type, or nothing is returned.
     * @return array<int, array{ring: array, street: string}>
     */
    public static function matchStreet(array $results, array $addr): array
    {
        $byStreet = [];
        foreach ($results as $r) {
            if ((string)($r['civic_number'] ?? '') !== $addr['number']) continue;
            $street = (string)($r['streetname'] ?? '');
            [$core, $type] = self::streetKey($street);
            if ($core !== $addr['core']) continue;
            $ring = self::ringOf($r['geom']['geometry'] ?? ($r['geom'] ?? null));
            if (!$ring) continue;
            $byStreet[$street][] = ['ring' => $ring, 'street' => $street, 'type' => $type, 'site' => (string)($r['site_id'] ?? '')];
        }
        if (count($byStreet) > 1) {
            $same = array_filter($byStreet, static fn($lots) => $addr['type'] !== '' && $lots[0]['type'] === $addr['type']);
            if (count($same) !== 1) return [];
            $byStreet = $same;
        }
        return $byStreet ? array_values($byStreet)[0] : [];
    }

    /** GeoJSON Polygon / MultiPolygon ([lng, lat]) → the outer ring as open [lat, lng] (largest part). */
    public static function ringOf($geometry): ?array
    {
        if (!is_array($geometry)) return null;
        $polys = ($geometry['type'] ?? '') === 'Polygon' ? [$geometry['coordinates'] ?? []]
               : (($geometry['type'] ?? '') === 'MultiPolygon' ? ($geometry['coordinates'] ?? []) : []);
        $best = null;
        $bestArea = 0.0;
        foreach ($polys as $poly) {
            $parsed = ParcelLotService::parseFeatures([['geometry' => ['type' => 'Polygon', 'coordinates' => $poly], 'properties' => []]]);
            if (!$parsed) continue;
            $area = PropertyBorderRules::area($parsed[0]['ring']);
            if ($area > $bestArea) { $best = $parsed[0]['ring']; $bestArea = $area; }
        }
        return $best;
    }

    /**
     * One lot → it. Several touching lots → their outline, when it isn't much bigger than the lots.
     * Otherwise the lot under the pin. Every lot must be near the pin.
     */
    public static function joinLots(array $lots, array $pin): array
    {
        foreach ($lots as $l) {
            if (self::distanceToRing($pin, $l['ring']) > self::PIN_MAX_M) return ['ok' => false, 'reason' => 'pin_far'];
        }
        $site = implode('+', array_filter(array_column($lots, 'site')));
        if (count($lots) === 1) {
            return ['ok' => true, 'ring' => $lots[0]['ring'], 'pid' => $site, 'area_sqm' => (int)round(PropertyBorderRules::area($lots[0]['ring']))];
        }
        if (self::allTouch($lots)) {
            $pts = [];
            $sum = 0.0;
            foreach ($lots as $l) { foreach ($l['ring'] as $p) $pts[] = $p; $sum += PropertyBorderRules::area($l['ring']); }
            $hull = PropertyBorderRules::convexHull($pts);
            if (count($hull) >= 3 && PropertyBorderRules::area($hull) <= $sum * ParcelLotService::HULL_MAX_GROWTH) {
                return ['ok' => true, 'ring' => $hull, 'pid' => $site, 'area_sqm' => (int)round(PropertyBorderRules::area($hull))];
            }
        }
        foreach ($lots as $l) {
            if (PropertyBorderRules::inside($pin, $l['ring'])) {
                return ['ok' => true, 'ring' => $l['ring'], 'pid' => $l['site'], 'area_sqm' => (int)round(PropertyBorderRules::area($l['ring']))];
            }
        }
        return ['ok' => false, 'reason' => 'ambiguous'];
    }

    /** Every lot is linked to the others through shared lines (one connected group). */
    private static function allTouch(array $lots): bool
    {
        $n = count($lots);
        $seen = [0 => true];
        $todo = [0];
        while ($todo) {
            $i = array_pop($todo);
            for ($k = 0; $k < $n; $k++) {
                if (isset($seen[$k])) continue;
                if (ParcelLotService::ringDistance($lots[$i]['ring'], $lots[$k]['ring']) <= self::TOUCH_M) { $seen[$k] = true; $todo[] = $k; }
            }
        }
        return count($seen) === $n;
    }

    /** 0 inside the ring, else metres to its nearest edge. */
    public static function distanceToRing(array $p, array $ring): float
    {
        return PropertyBorderRules::inside($p, $ring) ? 0.0 : ParcelLotService::edgeDistance($p, $ring);
    }
}
