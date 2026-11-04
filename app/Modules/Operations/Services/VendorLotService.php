<?php
/** Nightly store placement, lot lookup, and vendor-commons linking. */
declare(strict_types=1);

require_once __DIR__ . '/ParcelLotService.php';
require_once __DIR__ . '/VendorPlacementRules.php';
require_once __DIR__ . '/OttoAutoPlaceService.php';
require_once __DIR__ . '/SupplierDirectoryService.php';
require_once dirname(__DIR__, 2) . '/Knowledge/Services/LocalVendorKnowledgeStore.php';
require_once dirname(__DIR__, 2) . '/Knowledge/Services/VendorIdentity.php';

final class VendorLotService
{
    public const STORE_MAX_AREA_SQM = 600000;
    private PDO $db;
    private ParcelLotService $lots;

    public function __construct(PDO $db, ?ParcelLotService $lots = null)
    {
        $this->db = $db;
        $this->lots = $lots ?? new ParcelLotService();
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
            $s = $this->db->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
            $s->execute([$column]);
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Look up due exact BC store lots. Missing migration columns make this a quiet no-op. */
    public function run(int $limit = 10): array
    {
        $summary = ['checked' => 0, 'stored' => 0, 'refused' => 0, 'skipped' => false];
        foreach (['lot_json', 'lot_source', 'lot_reason', 'lot_checked_at'] as $c) {
            if (!$this->hasColumn('vendor_locations', $c)) return $summary + ['skipped' => true];
        }
        $limit = max(1, min(50, $limit));
        $rows = $this->db->query("SELECT id, address, city, lat, lng FROM vendor_locations
            WHERE geo_exact = 1 AND lat IS NOT NULL AND lng IS NOT NULL
              AND (lot_checked_at IS NULL OR lot_checked_at < DATE_SUB(NOW(), INTERVAL 180 DAY))
            ORDER BY COALESCE(lot_checked_at, '1970-01-01'), id LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        $save = $this->db->prepare('UPDATE vendor_locations SET lot_json = ?, lot_source = ?, lot_reason = ?, lot_checked_at = NOW() WHERE id = ?');
        foreach ($rows as $row) {
            $summary['checked']++;
            $region = self::regionOf($row);
            if ($region !== 'BC' || !isset(OttoAutoPlaceService::LOT_MAPS[$region])) {
                $save->execute([null, null, 'no_source:' . ($region ?: 'unknown'), (int)$row['id']]);
                $summary['refused']++;
                continue;
            }
            try {
                $lot = $this->lots->lotAt((float)$row['lat'], (float)$row['lng'], self::STORE_MAX_AREA_SQM);
            } catch (Throwable $e) {
                $lot = ['ok' => false, 'reason' => 'unavailable'];
            }
            if (!empty($lot['ok'])) {
                $save->execute([json_encode($lot['ring']), 'parcelmap_bc', null, (int)$row['id']]);
                $summary['stored']++;
            } else {
                $reason = self::lotReason((string)($lot['reason'] ?? 'unavailable'));
                $save->execute([null, null, $reason, (int)$row['id']]);
                $summary['refused']++;
            }
        }
        return $summary;
    }

    public static function lotReason(string $reason): string
    {
        if ($reason === 'too_big') return 'too_big';
        if ($reason === 'unavailable') return 'unavailable';
        return 'not_a_lot';
    }

    public static function regionOf(array $row): ?string
    {
        $address = strtoupper((string)($row['address'] ?? '') . ' ' . (string)($row['city'] ?? ''));
        if (preg_match('/\b(B\.?\s*C\.?|BRITISH COLUMBIA)\b/', $address)) return 'BC';
        return OttoAutoPlaceService::regionOf(['province' => '', 'latitude' => $row['lat'] ?? null, 'longitude' => $row['lng'] ?? null]);
    }

    /** Place failed-geocode locations from repeat approved receipt captures. */
    public function placeFromReceipts(): array
    {
        $out = ['vendors' => 0, 'clusters' => 0, 'placed' => 0, 'unmatched' => 0, 'skipped' => false];
        foreach (['receipt_lat', 'receipt_lng'] as $c) if (!$this->hasColumn('expenses', $c)) return $out + ['skipped' => true];
        if (!$this->hasColumn('vendor_locations', 'geo_exact')) return $out + ['skipped' => true];
        try {
            $rows = $this->db->prepare("SELECT vendor_id, receipt_lat, receipt_lng FROM expenses
                WHERE vendor_id IS NOT NULL AND status = 'approved' AND receipt_lat IS NOT NULL AND receipt_lng IS NOT NULL
                  AND created_at >= ? ORDER BY vendor_id, id");
            $rows->execute([date('Y-m-d H:i:s', strtotime('-365 days'))]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $out + ['skipped' => true];
        }
        $byVendor = [];
        foreach ($rows as $r) $byVendor[(int)$r['vendor_id']][] = [(float)$r['receipt_lat'], (float)$r['receipt_lng']];
        $locations = $this->db->prepare("SELECT id, lat, lng, geo_note FROM vendor_locations
            WHERE vendor_id = ? AND COALESCE(geo_exact, 0) = 0 AND (lat IS NULL OR lng IS NULL)");
        $update = $this->db->prepare("UPDATE vendor_locations SET lat = ?, lng = ?, geo_source = 'receipt_gps', geo_exact = 0 WHERE id = ? AND COALESCE(geo_exact, 0) = 0");
        foreach ($byVendor as $vendorId => $points) {
            $clusters = VendorPlacementRules::cluster($points);
            if (!$clusters) continue;
            $out['vendors']++;
            $locations->execute([$vendorId]);
            $available = $locations->fetchAll(PDO::FETCH_ASSOC);
            foreach ($clusters as $cluster) {
                $out['clusters']++;
                $best = null;
                foreach ($available as $i => $location) {
                    $candidate = self::candidatePoint($location);
                    if ($candidate === null) continue;
                    $m = PropertyBorderRules::meters($cluster['center'][0], $cluster['center'][1], $candidate[0], $candidate[1]);
                    if ($m <= 150 && ($best === null || $m < $best[1])) $best = [$i, $m];
                }
                if ($best === null) { $out['unmatched']++; continue; }
                $location = $available[$best[0]];
                $update->execute([$cluster['center'][0], $cluster['center'][1], (int)$location['id']]);
                unset($available[$best[0]]);
                $out['placed']++;
            }
        }
        return $out;
    }

    /** Inexact geocoders leave their candidate coordinates in geo_note instead of saving a pin. */
    public static function candidatePoint(array $location): ?array
    {
        if ($location['lat'] !== null && $location['lng'] !== null) return [(float)$location['lat'], (float)$location['lng']];
        if (preg_match('/\([^:()]*:\s*(-?\d{1,3}\.\d+),\s*(-?\d{1,3}\.\d+)\)/', (string)($location['geo_note'] ?? ''), $m)) {
            return [(float)$m[1], (float)$m[2]];
        }
        return null;
    }

    /** GST needs name agreement; phone or domain is sufficient by itself. */
    public static function commonsMatch(array $local, array $shared): bool
    {
        $localName = VendorIdentity::nameKey($local['name'] ?? null);
        $sharedName = VendorIdentity::nameKey($shared['display_name'] ?? null);
        $gst = !empty($local['gst_number']) && !empty($shared['gst_number']) && $local['gst_number'] === $shared['gst_number'];
        if ($gst && (!empty($local['name_agrees']) || ($localName !== null && $localName === $sharedName))) return true;
        foreach (['phone_digits', 'domain'] as $key) {
            if (!empty($local[$key]) && !empty($shared[$key]) && $local[$key] === $shared[$key]) return true;
        }
        return false;
    }

    /** Link only identities already present in the commons; no facts or private values are shared. */
    public function linkCommons(int $limit = 20): array
    {
        $out = ['checked' => 0, 'linked' => 0, 'skipped' => false];
        if (!$this->hasColumn('vendors', 'kb_vendor_id')) return $out + ['skipped' => true];
        $store = new LocalVendorKnowledgeStore($this->db);
        if (!$store->ready()) return $out + ['skipped' => true];
        $columns = ['gst_number' => $this->hasColumn('vendors', 'gst_number'), 'phone' => $this->hasColumn('vendors', 'phone'),
                    'website' => $this->hasColumn('vendors', 'website'), 'domain' => $this->hasColumn('vendors', 'domain')];
        $select = 'id, name' . ($columns['gst_number'] ? ', gst_number' : ', NULL gst_number')
            . ($columns['phone'] ? ', phone' : ', NULL phone') . ($columns['website'] ? ', website' : ', NULL website')
            . ($columns['domain'] ? ', domain' : ', NULL domain');
        $rows = $this->db->query("SELECT {$select} FROM vendors WHERE kb_vendor_id IS NULL ORDER BY id LIMIT " . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC);
        $update = $this->db->prepare('UPDATE vendors SET kb_vendor_id = ? WHERE id = ? AND kb_vendor_id IS NULL');
        foreach ($rows as $row) {
            $out['checked']++;
            $identity = ['gst_number' => $row['gst_number'], 'phone_digits' => VendorIdentity::phoneDigits($row['phone']),
                         'domain' => $row['domain'] ?: SupplierDirectoryService::domainOf((string)$row['website']),
                         'name_key' => VendorIdentity::nameKey((string)$row['name'])];
            $candidateIds = [];
            $nameAgrees = [];
            foreach (['phone_digits', 'domain', 'name_key', 'gst_number'] as $key) {
                if (empty($identity[$key])) continue;
                $s = $this->db->prepare("SELECT id FROM kb_vendors WHERE {$key} = ? AND status <> 'blocked' LIMIT 5");
                $s->execute([$identity[$key]]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $candidateIds[(int)$id] = true;
                    if ($key === 'name_key') $nameAgrees[(int)$id] = true;
                }
            }
            if (!empty($identity['name_key'])) {
                $s = $this->db->prepare("SELECT a.kb_vendor_id FROM kb_vendor_aliases a JOIN kb_vendors v ON v.id = a.kb_vendor_id WHERE a.alias_key = ? AND v.status <> 'blocked' LIMIT 5");
                $s->execute([$identity['name_key']]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $candidateIds[(int)$id] = true;
                    $nameAgrees[(int)$id] = true;
                }
            }
            foreach (array_keys($candidateIds) as $id) {
                $shared = $store->load($id);
                if ($shared && self::commonsMatch(['name' => $row['name'], 'name_agrees' => !empty($nameAgrees[$id])] + $identity, $shared)) {
                    $update->execute([$id, (int)$row['id']]);
                    $out['linked']++;
                    break;
                }
            }
        }
        return $out;
    }
}
