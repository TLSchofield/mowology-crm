<?php
/** Optional nightly Google Places opening-hours lookup for placed vendor locations. */
declare(strict_types=1);

require_once __DIR__ . '/HttpFetch.php';
require_once __DIR__ . '/PropertyBorderRules.php';

final class VendorHoursService
{
    private PDO $db;
    /** @var callable */
    private $fetch;

    public function __construct(PDO $db, ?callable $fetch = null)
    {
        $this->db = $db;
        $this->fetch = $fetch ?? static function (string $url): ?string { return HttpFetch::get($url, 12); };
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                foreach ($this->db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ((string)$row['name'] === $column) return true;
                }
                return false;
            }
            // No placeholder: MySQL rejects parameter markers in SHOW statements with native prepares (emulation off).
            return (bool)$this->db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $this->db->quote($column))->fetch();
        } catch (Throwable $e) { return false; }
    }

    /** @return array{weekday: string, saturday: string, sunday: string} */
    public static function parseWeekdayText(array $lines): array
    {
        $days = [];
        foreach ($lines as $line) {
            if (!is_string($line) || strpos($line, ':') === false) continue;
            [$day, $hours] = array_map('trim', explode(':', $line, 2));
            $days[strtolower($day)] = $hours;
        }
        $week = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) $week[] = $days[$day] ?? '';
        $weekday = count(array_unique($week)) === 1 ? $week[0] : implode('; ', array_map(static function ($day, $hours) {
            return substr(ucfirst($day), 0, 3) . ' ' . $hours;
        }, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $week));
        return ['weekday' => trim($weekday), 'saturday' => $days['saturday'] ?? '', 'sunday' => $days['sunday'] ?? ''];
    }

    public function run(int $limit = 5): array
    {
        $out = ['status' => 'off', 'checked' => 0, 'saved' => 0];
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'vendor_hours_lookup'");
            $s->execute();
            if ((string)$s->fetchColumn() !== '1') return $out;
        } catch (Throwable $e) { return $out; }
        if (!defined('GOOGLE_PLACES_KEY') || trim((string)GOOGLE_PLACES_KEY) === '') return ['status' => 'no_key', 'checked' => 0, 'saved' => 0];
        foreach (['hours_source', 'hours_checked_at'] as $c) if (!$this->hasColumn('vendor_locations', $c)) return ['status' => 'migration_missing', 'checked' => 0, 'saved' => 0];
        $out['status'] = 'on';
        $limit = max(1, min(20, $limit));
        $due = date('Y-m-d H:i:s', strtotime('-90 days'));
        $find = $this->db->prepare("SELECT vl.id, vl.address, vl.city, vl.lat, vl.lng, v.name FROM vendor_locations vl
            JOIN vendors v ON v.id = vl.vendor_id WHERE vl.lat IS NOT NULL AND vl.lng IS NOT NULL
              AND (vl.hours_checked_at IS NULL OR vl.hours_checked_at < ?)
              -- hours a person typed (no source) are theirs: never looked up, never overwritten
              AND NOT (COALESCE(vl.hours_source, '') = '' AND COALESCE(vl.hours_weekday, '') <> '')
            ORDER BY COALESCE(vl.hours_checked_at, '1970-01-01'), vl.id LIMIT {$limit}");
        $find->execute([$due]);
        $rows = $find->fetchAll(PDO::FETCH_ASSOC);
        $save = $this->db->prepare('UPDATE vendor_locations SET hours_weekday = ?, hours_saturday = ?, hours_sunday = ?, hours_source = ?, hours_checked_at = NOW() WHERE id = ?');
        foreach ($rows as $row) {
            $out['checked']++;
            $query = trim((string)$row['name'] . ' ' . (string)$row['address'] . ' ' . (string)$row['city']);
            $search = $this->json('https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query(['query' => $query, 'key' => GOOGLE_PLACES_KEY]));
            if ($search === null || !in_array($search['status'] ?? null, ['OK', 'ZERO_RESULTS'], true)) continue;
            $placeId = null;
            foreach ((array)($search['results'] ?? []) as $candidate) {
                $loc = $candidate['geometry']['location'] ?? [];
                if (!isset($loc['lat'], $loc['lng'])) continue;
                if (PropertyBorderRules::meters((float)$row['lat'], (float)$row['lng'], (float)$loc['lat'], (float)$loc['lng']) <= 150) {
                    $placeId = $candidate['place_id'] ?? null;
                    break;
                }
            }
            $hours = ['weekday' => '', 'saturday' => '', 'sunday' => ''];
            if ($placeId) {
                $details = $this->json('https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query(['place_id' => $placeId, 'fields' => 'opening_hours', 'key' => GOOGLE_PLACES_KEY]));
                if ($details === null || ($details['status'] ?? null) !== 'OK') continue;
                $hours = self::parseWeekdayText((array)($details['result']['opening_hours']['weekday_text'] ?? []));
            }
            if ($placeId && array_filter($hours)) {
                $save->execute([$hours['weekday'] ?: null, $hours['saturday'] ?: null, $hours['sunday'] ?: null, 'google_places', (int)$row['id']]);
                $out['saved']++;
            } else {
                // No listing or no hours published: note the check, keep whatever hours are already there.
                $this->db->prepare('UPDATE vendor_locations SET hours_checked_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
            }
        }
        return $out;
    }

    private function json(string $url): ?array
    {
        try {
            $body = ($this->fetch)($url);
            $json = is_string($body) ? json_decode($body, true) : null;
            return is_array($json) ? $json : null;
        } catch (Throwable $e) { return null; }
    }
}
