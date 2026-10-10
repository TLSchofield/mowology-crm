<?php
/**
 * SupplierDirectoryService — one list of suppliers for Otto and Penny (owner, 2026-10-10).
 *
 * "Otto and Penny can have a directory; if their locations are geocoded both become more
 * efficient." Each supplier_accounts row is the company's relationship with a supplier
 * (asked → applied → open), linked to:
 *   - ops_places (kind 'supplier', lat/lng) — Otto's trip reader labels a stop there as a
 *     supply run (not a break), and can pick the nearest supplier for a job;
 *   - vendors — Penny's books (receipts already match vendors).
 * Mail from the supplier's domain is theirs (supplier_inbox_poll).
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class SupplierDirectoryService
{
    public const STATUSES = ['asked', 'applied', 'open', 'declined', 'none'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** "Jon <jon@CedarRim.com>" / "https://www.cedarrim.com/x" → "cedarrim.com". */
    public static function domainOf(string $s): string
    {
        $s = strtolower(trim($s));
        if (preg_match('/@([a-z0-9.-]+\.[a-z]{2,})/', $s, $m)) $s = $m[1];
        elseif (preg_match('#^(?:https?://)?([a-z0-9.-]+\.[a-z]{2,})#', $s, $m)) $s = $m[1];
        else return '';
        $s = (string)preg_replace('/^www\./', '', $s);
        return $s;
    }

    /** Free mailboxes never identify a supplier (a supplier on gmail/telus is matched by full address). */
    public static function isSharedDomain(string $domain): bool
    {
        return in_array($domain, ['gmail.com', 'telus.net', 'shaw.ca', 'hotmail.com', 'outlook.com', 'yahoo.com', 'yahoo.ca',
                                  'icloud.com', 'me.com', 'live.com', 'live.ca', 'msn.com', 'aol.com', 'protonmail.com'], true);
    }

    /** The key mail is matched on: the domain, or the whole address on a shared mailbox domain. */
    public static function mailKey(string $email): string
    {
        $d = self::domainOf($email);
        if ($d === '') return '';
        if (!self::isSharedDomain($d)) return $d;
        return preg_match('/([a-z0-9._%+-]+@[a-z0-9.-]+)/', strtolower($email), $m) ? $m[1] : '';
    }

    /** Distance in km between two points (haversine). */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM supplier_accounts LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function all(): array
    {
        if (!$this->ready()) return [];
        return $this->db->query("SELECT * FROM supplier_accounts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM supplier_accounts WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The supplier this address belongs to (domain, or exact address on a shared domain). */
    public function forAddress(string $email): ?array
    {
        $k = self::mailKey($email);
        if ($k === '' || !$this->ready()) return null;
        $s = $this->db->prepare("SELECT * FROM supplier_accounts WHERE domain = ? OR LOWER(email) = ? LIMIT 1");
        $s->execute([$k, $k]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Match keys for every supplier (what the inbox poll searches for). */
    public function mailKeys(): array
    {
        $out = [];
        foreach ($this->all() as $r) {
            $k = $r['domain'] ?: self::mailKey((string)$r['email']);
            if ($k !== '') $out[$k] = (int)$r['id'];
        }
        return $out;
    }

    /** Add or update by mail key. Returns the id. */
    public function upsert(array $in): int
    {
        $email = trim((string)($in['email'] ?? ''));
        $key = $email !== '' ? self::mailKey($email) : self::domainOf((string)($in['website'] ?? ''));
        $domain = ($key !== '' && strpos($key, '@') === false) ? $key : null;
        $existing = $email !== '' ? $this->forAddress($email) : null;
        if ($existing) {
            $sets = [];
            $vals = [];
            foreach (['name', 'phone', 'website', 'address', 'sells', 'list_kind', 'notes'] as $c) {
                if (!empty($in[$c]) && empty($existing[$c])) { $sets[] = "{$c} = ?"; $vals[] = $in[$c]; }
            }
            if ($sets) {
                $vals[] = (int)$existing['id'];
                $this->db->prepare('UPDATE supplier_accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            }
            return (int)$existing['id'];
        }
        $this->db->prepare("
            INSERT INTO supplier_accounts (name, email, domain, phone, website, address, sells, status, list_kind, asked_at, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            mb_substr(trim((string)($in['name'] ?? $key)), 0, 160), $email ?: null, $domain,
            $in['phone'] ?? null, $in['website'] ?? null, $in['address'] ?? null, $in['sells'] ?? null,
            in_array($in['status'] ?? '', self::STATUSES, true) ? $in['status'] : 'asked',
            $in['list_kind'] ?? null, $in['asked_at'] ?? null, $in['notes'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) return;
        $col = $status === 'applied' ? ', applied_at = NOW()' : '';
        $this->db->prepare("UPDATE supplier_accounts SET status = ?{$col} WHERE id = ?")->execute([$status, $id]);
    }

    /** They wrote to us at $when (the email's own date — re-reading old mail never moves it forward). */
    public function heardFrom(int $id, ?string $when = null): void
    {
        $when ??= date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE supplier_accounts SET last_heard_at = ? WHERE id = ? AND (last_heard_at IS NULL OR last_heard_at < ?)")
            ->execute([$when, $id, $when]);
    }

    /**
     * Geocode suppliers that have an address but no point; an exact point also becomes a place on
     * Otto's map (ops_places kind 'supplier'); link Penny's vendor of the same name when one exists.
     * Returns how many were placed.
     */
    public function placeAll(?callable $geocode = null): int
    {
        $geocode ??= [self::class, 'googleGeocode'];
        $n = 0;
        foreach ($this->all() as $r) {
            if (empty($r['address'])) continue;
            $lat = $r['lat'] !== null ? (float)$r['lat'] : null;
            $lng = $r['lng'] !== null ? (float)$r['lng'] : null;
            if ($lat === null || $lng === null) {
                $p = $geocode((string)$r['address']);
                if (!$p) continue;
                [$lat, $lng] = [(float)$p['lat'], (float)$p['lng']];
                $exact = !empty($p['exact']);
                $this->db->prepare("UPDATE supplier_accounts SET lat = ?, lng = ?, geo_exact = ? WHERE id = ?")->execute([$lat, $lng, $exact ? 1 : 0, (int)$r['id']]);
            } else {
                $exact = !array_key_exists('geo_exact', $r) || $r['geo_exact'] === null || (int)$r['geo_exact'] === 1;   // NULL = set by hand
            }
            // A street-level point is fine for "nearest supplier" but would mislabel the truck's stops
            // (places match within 150 m) — only an exact point becomes a place on Otto's map.
            if (empty($r['place_id']) && $exact) {
                try {
                    $name = mb_substr((string)$r['name'], 0, 120);
                    $this->db->prepare("
                        INSERT INTO ops_places (name, kind, lat, lng, radius_m, address, vendor_match, active)
                        VALUES (?, 'supplier', ?, ?, 150, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), address = VALUES(address), active = 1
                    ")->execute([$name, $lat, $lng, mb_substr((string)$r['address'], 0, 255), mb_substr(strtolower((string)$r['name']), 0, 255)]);
                    $s = $this->db->prepare("SELECT id FROM ops_places WHERE name = ?");
                    $s->execute([$name]);
                    $pid = (int)$s->fetchColumn();
                    if ($pid) $this->db->prepare("UPDATE supplier_accounts SET place_id = ? WHERE id = ?")->execute([$pid, (int)$r['id']]);
                } catch (Throwable $e) {
                    error_log('[supplier directory] place ' . $r['name'] . ': ' . $e->getMessage());
                }
            }
            if (empty($r['vendor_id'])) {
                try {
                    $s = $this->db->prepare("SELECT id FROM vendors WHERE LOWER(name) = LOWER(?) OR LOWER(name) LIKE LOWER(?) LIMIT 1");
                    $s->execute([(string)$r['name'], '%' . (string)$r['name'] . '%']);
                    $vid = (int)$s->fetchColumn();
                    if ($vid) $this->db->prepare("UPDATE supplier_accounts SET vendor_id = ? WHERE id = ?")->execute([$vid, (int)$r['id']]);
                } catch (Throwable $e) { /* vendors optional */ }
            }
            $n++;
        }
        return $n;
    }

    /** Suppliers that sell $what, nearest first from a point. */
    public function nearest(float $lat, float $lng, ?string $what = null, int $limit = 5): array
    {
        $out = [];
        foreach ($this->all() as $r) {
            if ($r['lat'] === null || $r['lng'] === null) continue;
            if ($what !== null && stripos('|' . (string)$r['sells'] . '|', '|' . $what . '|') === false) continue;
            $r['km'] = round(self::km($lat, $lng, (float)$r['lat'], (float)$r['lng']), 1);
            $out[] = $r;
        }
        usort($out, fn($a, $b) => $a['km'] <=> $b['km']);
        return array_slice($out, 0, $limit);
    }

    /**
     * Address → point. Google first (needs a server key: the CRM's Maps key is browser-only —
     * "API keys with referer restrictions cannot be used with this API", found 2026-10-10), then
     * OpenStreetMap's Nominatim (free; its policy: identify yourself, at most 1 request a second).
     */
    public static function googleGeocode(string $address): ?array
    {
        $key = defined('GOOGLE_GEOCODING_KEY') ? (string)GOOGLE_GEOCODING_KEY : (defined('GOOGLE_MAPS_API_KEY') ? (string)GOOGLE_MAPS_API_KEY : '');
        if ($key !== '') {
            $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . rawurlencode($address) . '&region=ca&key=' . rawurlencode($key);
            $j = json_decode((string)@file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]])), true);
            $loc = $j['results'][0]['geometry']['location'] ?? null;
            if ($loc) return ['lat' => (float)$loc['lat'], 'lng' => (float)$loc['lng'], 'by' => 'google',
                              'exact' => in_array($j['results'][0]['geometry']['location_type'] ?? '', ['ROOFTOP', 'RANGE_INTERPOLATED'], true)];
        }
        return self::nominatim($address);
    }

    public static function nominatim(string $address): ?array
    {
        static $last = 0.0;
        $wait = 1.1 - (microtime(true) - $last);
        if ($wait > 0) usleep((int)($wait * 1e6));
        $last = microtime(true);
        $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ca&q=' . rawurlencode($address);
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true,
                                                 'header' => "User-Agent: Mowology-CRM/1.0 (supplier directory; office@mowology.ca)\r\n"]]);
        $j = json_decode((string)@file_get_contents($url, false, $ctx), true);
        // place_rank 28–30 = a building or house number; lower = a street or area (km off).
        if (!empty($j[0]['lat'])) return ['lat' => (float)$j[0]['lat'], 'lng' => (float)$j[0]['lon'], 'by' => 'osm',
                                          'exact' => (int)($j[0]['place_rank'] ?? 0) >= 28];
        // Unit numbers and postal codes sometimes defeat the search — retry with the street + city only.
        $short = trim((string)preg_replace(['/^\s*#?\d+\s*[-–]\s*(?=\d)/', '/\b[A-Z]\d[A-Z]\s?\d[A-Z]\d\b/i'], '', $address), ' ,');
        if ($short !== $address) return self::nominatim($short);
        return null;
    }
}
