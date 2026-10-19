<?php
/**
 * ParcelLotService — the legal lot under a map pin, from ParcelMap BC (the province's parcel
 * fabric, public WFS on openmaps.gov.bc.ca; no key). Otto uses the lot outline as a property's
 * arrival border (OttoAutoPlaceService), instead of a ±25 m square someone has to redraw.
 *
 * Checked 2026-10-18 against BCS914 (Kerrisdale Landing, Vancouver): a 6 m box round the pin returns
 * one polygon per strata unit, all the same outline (1,211 m²) — so features are de-duplicated, and
 * a strata plan is merged by PLAN_NUMBER (bare-land strata: units + common property → one hull).
 *
 * Licence: Open Government Licence – British Columbia (attribution in ATTRIBUTION). The fabric is
 * not survey-accurate; it is plenty for "has the crew arrived".
 *
 * Rules (pure, tested): the pin must be inside the lot; roads, rights of way, parks and interests are
 * never a client's lot; a lot under MIN_AREA_SQM or over MAX_AREA_SQM is left for a person to draw.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/PropertyBorderRules.php';

class ParcelLotService
{
    public const ATTRIBUTION = 'Contains information licensed under the Open Government Licence – British Columbia (ParcelMap BC).';
    public const WFS = 'https://openmaps.gov.bc.ca/geo/pub/wfs';
    public const LAYER = 'pub:WHSE_CADASTRE.PMBC_PARCEL_FABRIC_POLY_SVW';
    public const MIN_AREA_SQM = 50;
    public const MAX_AREA_SQM = 20000;
    /** Half-size of the box round the pin, in degrees (~3 m). */
    public const PIN_BOX_DEG = 0.00003;
    public const NOT_A_LOT = ['road', 'right of way', 'park', 'interest', 'return to crown', 'closed road'];
    public const STRATA = ['building strata', 'bare land strata', 'common property'];
    /** The pin must be this far inside the lot, or it may be the neighbour's (Codex review 2026-10-18). */
    public const EDGE_MIN_M = 2.0;
    /** Strata pieces further than this from the client's lot are another part of town, not this complex. */
    public const PLAN_RADIUS_M = 150.0;
    public const PLAN_MAX_FEATURES = 500;
    /** A merged hull this much bigger than its pieces bridges gaps that are not the client's: keep the one lot. */
    public const HULL_MAX_GROWTH = 1.3;

    /** @var callable(string): ?string */
    private $fetch;

    private int $pauseMs;

    /**
     * @param callable(string): ?string|null $fetch URL → response body (null on failure).
     * @param int|null $pauseMs wait before each request (default 500 ms live, 0 with an injected fetch).
     */
    public function __construct(?callable $fetch = null, ?int $pauseMs = null)
    {
        $this->pauseMs = $pauseMs ?? ($fetch === null ? 500 : 0);
        $this->fetch = $fetch ?? static function (string $url): ?string {
            $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true,
                'header' => "User-Agent: Mowology-CRM/1.0 (arrival borders; office@mowology.ca)\r\n"]]);
            $body = @file_get_contents($url, false, $ctx);
            return $body === false ? null : $body;
        };
    }

    /**
     * The lot under the pin.
     * @return array{ok: bool, reason?: string, ring?: array, pid?: string, plan?: string, class?: string, area_sqm?: int, municipality?: string}
     */
    public function lotAt(float $lat, float $lng): array
    {
        $box = sprintf('%.6F,%.6F,%.6F,%.6F,urn:ogc:def:crs:EPSG::4326',
            $lat - self::PIN_BOX_DEG, $lng - self::PIN_BOX_DEG, $lat + self::PIN_BOX_DEG, $lng + self::PIN_BOX_DEG);
        $features = $this->get(['bbox' => $box, 'count' => '50']);
        if ($features === null) return ['ok' => false, 'reason' => 'unavailable'];
        $lot = self::chooseLot($features, $lat, $lng);
        // No "nearest lot" guess for a pin that is on no lot: Vancouver's streets are gaps in the fabric,
        // and so are some lots that are missing from it — 8655 Laurel St got the house next door
        // (owner: "border looks off", 2026-10-18). Only the building from the address may fix a street pin.
        if (!$lot['ok']) return $lot;
        if (in_array(strtolower($lot['class']), self::STRATA, true) && $lot['plan'] !== '') {
            $plan = $this->get(['CQL_FILTER' => "PLAN_NUMBER='" . $lot['plan'] . "'", 'count' => (string)self::PLAN_MAX_FEATURES]);
            // A full page may be truncated: an arbitrary outline is worse than the one lot.
            if ($plan && count($plan) < self::PLAN_MAX_FEATURES) $lot = self::mergePlan($lot, $plan);
        }
        return self::checkArea($lot);
    }

    /** @return array<int, array{ring: array, class: string, plan: string, pid: string, municipality: string}>|null */
    private function get(array $params): ?array
    {
        $q = ['service' => 'WFS', 'version' => '2.0.0', 'request' => 'GetFeature', 'typeName' => self::LAYER,
              'outputFormat' => 'application/json', 'srsName' => 'EPSG:4326'] + $params;
        $url = self::WFS . '?' . http_build_query($q);
        // The province's map server turns away quick bursts from a web host (7 of 15 "unavailable"
        // on the first live run, 2026-10-18; every one answered from a desk): pace, and retry once.
        for ($try = 0; $try < 2; $try++) {
            if ($this->pauseMs > 0) usleep(($try === 0 ? $this->pauseMs : 4 * $this->pauseMs) * 1000);
            $body = ($this->fetch)($url);
            $j = $body === null ? null : json_decode($body, true);
            if (is_array($j) && isset($j['features']) && is_array($j['features'])) return self::parseFeatures($j['features']);
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure
    // ─────────────────────────────────────────────────────────────────────────

    /** GeoJSON features ([lng, lat]) → open rings of [lat, lng], outer rings only, duplicates dropped. */
    public static function parseFeatures(array $features): array
    {
        $out = [];
        $seen = [];
        foreach ($features as $f) {
            $g = $f['geometry'] ?? null;
            $p = $f['properties'] ?? [];
            if (!is_array($g)) continue;
            $polys = ($g['type'] ?? '') === 'Polygon' ? [$g['coordinates'] ?? []]
                   : (($g['type'] ?? '') === 'MultiPolygon' ? ($g['coordinates'] ?? []) : []);
            foreach ($polys as $poly) {
                $ring = [];
                foreach ((array)($poly[0] ?? []) as $pt) {
                    if (is_array($pt) && isset($pt[0], $pt[1]) && is_numeric($pt[0]) && is_numeric($pt[1])) {
                        $ring[] = [round((float)$pt[1], 7), round((float)$pt[0], 7)];
                    }
                }
                if (count($ring) > 3 && $ring[0] === $ring[count($ring) - 1]) array_pop($ring);
                if (count($ring) < 3) continue;
                $key = md5(json_encode($ring));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $out[] = ['ring' => $ring, 'class' => (string)($p['PARCEL_CLASS'] ?? ''), 'plan' => (string)($p['PLAN_NUMBER'] ?? ''),
                          'pid' => (string)($p['PID_FORMATTED'] ?? ''), 'municipality' => (string)($p['MUNICIPALITY'] ?? '')];
            }
        }
        return $out;
    }

    /** The smallest real lot containing the pin. */
    public static function chooseLot(array $parsed, float $lat, float $lng): array
    {
        $best = null;
        foreach ($parsed as $f) {
            if (in_array(strtolower(trim($f['class'])), self::NOT_A_LOT, true)) continue;
            if (!PropertyBorderRules::inside([$lat, $lng], $f['ring'])) continue;
            if (self::edgeDistance([$lat, $lng], $f['ring']) < self::EDGE_MIN_M) return ['ok' => false, 'reason' => 'near_edge'];
            $area = PropertyBorderRules::area($f['ring']);
            if ($best === null || $area < $best['area']) $best = $f + ['area' => $area];
        }
        if ($best === null) return ['ok' => false, 'reason' => 'no_lot'];   // the pin is on a road, or off the fabric
        $plan = preg_match('/^[A-Z0-9]{2,20}$/', $best['plan']) ? $best['plan'] : '';
        return ['ok' => true, 'ring' => $best['ring'], 'pid' => $best['pid'], 'plan' => $plan, 'class' => $best['class'],
                'municipality' => $best['municipality'], 'area_sqm' => (int)round($best['area'])];
    }

    /**
     * A strata plan's pieces (units, common property) near the client's lot → one hull — only when
     * the hull is not much bigger than the pieces themselves (it would bridge other people's land).
     */
    public static function mergePlan(array $lot, array $planParsed): array
    {
        $pts = [];
        $piecesArea = 0.0;
        foreach ($planParsed as $f) {
            if (in_array(strtolower(trim($f['class'])), self::NOT_A_LOT, true)) continue;
            if ($lot['municipality'] !== '' && $f['municipality'] !== '' && $f['municipality'] !== $lot['municipality']) continue;
            if (self::ringDistance($f['ring'], $lot['ring']) > self::PLAN_RADIUS_M) continue;
            foreach ($f['ring'] as $p) $pts[] = $p;
            $piecesArea += PropertyBorderRules::area($f['ring']);
        }
        if (count($pts) < 3) return $lot;
        $hull = PropertyBorderRules::convexHull($pts);
        if (count($hull) < 3 || PropertyBorderRules::area($hull) > $piecesArea * self::HULL_MAX_GROWTH) return $lot;
        $lot['ring'] = $hull;
        $lot['area_sqm'] = (int)round(PropertyBorderRules::area($hull));
        return $lot;
    }

    /** Metres from a point to the nearest edge of a ring. */
    public static function edgeDistance(array $p, array $ring): float
    {
        $ref = [(float)$p[0], (float)$p[1]];
        $best = INF;
        $n = count($ring);
        for ($i = 0; $i < $n; $i++) {
            $a = PropertyBorderRules::toXY($ring[$i], $ref);
            $b = PropertyBorderRules::toXY($ring[($i + 1) % $n], $ref);
            $dx = $b[0] - $a[0];
            $dy = $b[1] - $a[1];
            $len = $dx * $dx + $dy * $dy;
            $t = $len > 0 ? max(0.0, min(1.0, -($a[0] * $dx + $a[1] * $dy) / $len)) : 0.0;
            $best = min($best, hypot($a[0] + $t * $dx, $a[1] + $t * $dy));
        }
        return $best;
    }

    /** Smallest vertex-to-vertex distance between two rings, in metres (0 when they share ground). */
    public static function ringDistance(array $a, array $b): float
    {
        if (PropertyBorderRules::overlap($a, $b)) return 0.0;
        $best = INF;
        foreach ($a as $p) foreach ($b as $q) $best = min($best, PropertyBorderRules::meters($p[0], $p[1], $q[0], $q[1]));
        return $best;
    }

    public static function checkArea(array $lot): array
    {
        if (!$lot['ok']) return $lot;
        if ($lot['area_sqm'] < self::MIN_AREA_SQM) return ['ok' => false, 'reason' => 'too_small'];
        if ($lot['area_sqm'] > self::MAX_AREA_SQM) return ['ok' => false, 'reason' => 'too_big'];
        return $lot;
    }
}
