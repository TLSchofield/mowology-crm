<?php
/**
 * VendorDetailsService — a vendor's details filled in from the receipts it prints (Tim, 2026-10-15:
 * "isn't Penny completing their details from the receipts? gst, website, address etc").
 * Plan: docs/plans/2026-10-15-vendor-details-from-receipts.md.
 *
 * On every approval (ExpenseGateHooks::learnConfirmed — web, iPhone, email-in, Penny) the receipt's
 * printed header gives:
 *   the vendor   phone, website, GST number — written only when the vendor's field is EMPTY, so nothing a
 *                person typed is ever overwritten (Penny learns only from confirmed receipts);
 *   its store    store number + name, street address, city, phone — matched to a known store by store
 *                number, then street, then phone (seen count + empty fields filled), else added as a new
 *                store (source 'receipt'; SupplierStopService::geocodePending places it on the map).
 * The vendor's identity in the shared commons is VendorKnowledgeService's job; this is the tenant's own record.
 * No namespace / no autoloader in production: require_once and `new`.
 */
if (!function_exists('extractVendorLocationFromOcr')) require_once dirname(__DIR__, 3) . '/Services/Receipts/ReceiptParser.php';
if (!class_exists('VendorIdentity')) require_once dirname(__DIR__, 2) . '/Knowledge/Services/VendorIdentity.php';
if (!class_exists('ReceiptFactsService')) require_once __DIR__ . '/ReceiptFactsService.php';

class VendorDetailsService
{
    public const SOURCE = 'receipt';
    private const HEADER_LINES = 12;     // store details sit in the first lines, never among the items

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure ──────────────────────────────────────────────────────────────────

    /**
     * What a receipt prints about its store.
     * @return array{phone: ?string, website: ?string, gst_number: ?string, store_number: ?string, store_name: ?string,
     *               address: ?string, city: ?string}
     */
    public static function read(string $ocrText): array
    {
        $out = ['phone' => null, 'website' => null, 'gst_number' => null, 'store_number' => null, 'store_name' => null,
                'address' => null, 'city' => null];
        if (trim($ocrText) === '') return $out;
        $loc = extractVendorLocationFromOcr($ocrText);
        $out['website'] = $loc['website'];
        $out['address'] = $loc['address'] !== null ? self::tidy($loc['address']) : null;
        $out['city'] = $loc['city'];
        $out['gst_number'] = VendorIdentity::gstNumber($ocrText);

        $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', $ocrText)), 'strlen')), 0, self::HEADER_LINES);
        // The store's phone: from the header only (a phone further down is a hotline, a customer, a promotion).
        $phones = extractPhoneNumbers(implode("\n", $lines));
        if ($phones) {
            $p = $phones[0];
            $out['phone'] = sprintf('(%s) %s-%s', substr($p, 0, 3), substr($p, 3, 3), substr($p, 6));
        }
        foreach ($lines as $l) {
            // "STORE #605 - MARINE & ONTARIO", "Store 7128", "THE HOME DEPOT #7043"
            // Only when the receipt says "Store" — a bare "#22594" is a receipt or register number (2026-10-15 preview).
            if (preg_match('/\bstore\s*(?:#|no\.?|num(?:ber)?)?\s*(\d{2,6})\b(?:\s*[-–:]\s*(.{3,40}))?/i', $l, $m)) {
                $out['store_number'] = $m[1];
                $name = !empty($m[2]) ? self::tidy($m[2]) : '';
                if ($name !== '' && preg_match('/[A-Za-z]{3,}/', $name) && !preg_match('/\b(?:phone|tel|fax)\b/i', $name)) $out['store_name'] = $name;
                break;
            }
        }
        return $out;
    }

    /**
     * Is this web address the vendor's own? It must carry a word of the vendor's name — receipts also print
     * loyalty programmes (triangle.com on Canadian Tire), fuel apps (journie.ca) and till makers. Pure.
     */
    public static function ownSite(?string $website, string $vendorName): bool
    {
        $domain = VendorIdentity::domainOf($website);
        if ($domain === null) return false;
        $host = str_replace(['-', '.'], '', strtolower(preg_replace('/\.[a-z]{2,}$/', '', $domain)));
        foreach (preg_split('/[^a-z0-9]+/', strtolower($vendorName)) as $w) {
            if (strlen($w) >= 4 && !in_array($w, ['store', 'shop', 'company', 'supply', 'supplies', 'centre', 'center', 'the'], true)
                && strpos($host, $w) !== false) return true;
        }
        $squashed = preg_replace('/[^a-z0-9]+/', '', strtolower($vendorName));
        return strlen($squashed) >= 4 && strpos($host, $squashed) !== false;
    }

    /** "MARINE & ONTARIO" → "Marine & Ontario"; trims stray punctuation. Pure. */
    public static function tidy(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s), " \t-–,.");
        return $s === mb_strtoupper($s) ? ucwords(mb_strtolower($s)) : $s;
    }

    /** "8277 Ontario Street" and "8277 ONTARIO ST." → "8277 ontario st". Pure. */
    public static function streetKey(?string $address): ?string
    {
        if ($address === null || trim($address) === '') return null;
        $s = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $address));
        $s = preg_replace(['/\bstreet\b/', '/\bavenue\b/', '/\broad\b/', '/\bboulevard\b/', '/\bdrive\b/', '/\bhighway\b/'],
                          ['st', 'ave', 'rd', 'blvd', 'dr', 'hwy'], $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    // ── Fill ──────────────────────────────────────────────────────────────────

    /**
     * Fill the receipt's vendor (and store) from what the receipt prints. Approved / sent receipts only.
     * @return array{vendor: list<string>, store: ?string}  what was filled ('phone', 'website', 'gst_number'; 'added' | 'updated' | null)
     */
    public function fillFromReceipt(int $expenseId, bool $apply = true): array
    {
        $done = ['vendor' => [], 'store' => null];
        $s = $this->db->prepare("SELECT id, vendor_id, status, raw_ocr_json FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e || empty($e['vendor_id']) || !in_array($e['status'], ['approved', 'forwarded'], true)) return $done;
        $info = self::read(ReceiptFactsService::ocrText($e['raw_ocr_json'] ?? null));
        $vendorId = (int)$e['vendor_id'];

        $cols = $this->vendorColumns();
        $v = $this->db->prepare("SELECT * FROM vendors WHERE id = ?");
        $v->execute([$vendorId]);
        $vendor = $v->fetch(PDO::FETCH_ASSOC);
        if (!$vendor) return $done;
        if ($info['website'] !== null && !self::ownSite($info['website'], (string)$vendor['name'])) $info['website'] = null;
        $set = [];
        foreach (['phone', 'website', 'gst_number'] as $f) {
            if ($info[$f] !== null && isset($cols[$f]) && trim((string)($vendor[$f] ?? '')) === '') $set[$f] = $info[$f];
        }
        if ($set && $apply) {
            $sql = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
            $this->db->prepare("UPDATE vendors SET $sql WHERE id = ?")->execute(array_merge(array_values($set), [$vendorId]));
        }
        $done['vendor'] = array_keys($set);
        if ($info['address'] !== null || $info['store_number'] !== null) $done['store'] = $this->store($vendorId, $info, $apply);
        return $done;
    }

    /** Match or add the store. @return 'added' | 'updated' | null */
    private function store(int $vendorId, array $info, bool $apply): ?string
    {
        $q = $this->db->prepare("SELECT * FROM vendor_locations WHERE vendor_id = ?");
        $q->execute([$vendorId]);
        $stores = $q->fetchAll(PDO::FETCH_ASSOC);
        $key = self::streetKey($info['address']);
        $phone = VendorIdentity::phoneDigits($info['phone']);
        $match = null;
        foreach ($stores as $st) {
            if ($info['store_number'] !== null && preg_match('/#\s*' . preg_quote($info['store_number'], '/') . '\b/', (string)$st['label'])) { $match = $st; break; }
        }
        if (!$match && $key !== null) foreach ($stores as $st) if (self::streetKey($st['address']) === $key) { $match = $st; break; }
        if (!$match && $phone !== null) foreach ($stores as $st) if (VendorIdentity::phoneDigits($st['phone']) === $phone) { $match = $st; break; }

        $label = $info['store_number'] !== null ? 'Store #' . $info['store_number'] . ($info['store_name'] ? ' – ' . $info['store_name'] : '')
                                                 : ($info['address'] ?? 'Store');
        $learned = $this->hasColumn('vendor_locations', 'receipts_seen');
        if ($match) {
            if (!$apply) return 'updated';
            $fill = [];
            foreach (['address' => $info['address'], 'city' => $info['city'], 'phone' => $info['phone']] as $f => $val) {
                if ($val !== null && trim((string)($match[$f] ?? '')) === '') $fill[$f] = $val;
            }
            if (in_array(strtolower((string)$match['label']), ['', 'main', 'store'], true)) $fill['label'] = $label;
            $sql = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fill)));
            if ($learned) $sql = ltrim($sql . ', receipts_seen = receipts_seen + 1, last_seen_at = NOW()', ', ');
            if ($sql !== '') $this->db->prepare("UPDATE vendor_locations SET $sql WHERE id = ?")->execute(array_merge(array_values($fill), [(int)$match['id']]));
            return 'updated';
        }
        if ($info['address'] === null) return null;           // a store number alone isn't somewhere to go
        if (!$apply) return 'added';
        $cols = 'vendor_id, label, address, city, phone, is_preferred' . ($learned ? ', source, receipts_seen, last_seen_at' : '');
        $vals = [$vendorId, mb_substr($label, 0, 100), $info['address'], $info['city'], $info['phone'], $stores ? 0 : 1];
        if ($learned) array_push($vals, self::SOURCE, 1, date('Y-m-d H:i:s'));
        $this->db->prepare("INSERT INTO vendor_locations ($cols) VALUES (" . implode(', ', array_fill(0, count($vals), '?')) . ")")->execute($vals);
        return 'added';
    }

    /** Every approved receipt with its OCR text (the one-off backfill). @return array{receipts: int, vendors: int, stores_added: int, stores_updated: int, fields: array} */
    public function backfill(bool $apply): array
    {
        $ids = $this->db->query("SELECT id FROM expenses WHERE status IN ('approved', 'forwarded') AND vendor_id IS NOT NULL
                                 AND raw_ocr_json IS NOT NULL AND raw_ocr_json <> '' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $out = ['receipts' => count($ids), 'vendors' => 0, 'stores_added' => 0, 'stores_updated' => 0, 'fields' => []];
        foreach ($ids as $id) {
            $r = $this->fillFromReceipt((int)$id, $apply);
            if ($r['vendor']) $out['vendors']++;
            foreach ($r['vendor'] as $f) $out['fields'][$f] = ($out['fields'][$f] ?? 0) + 1;
            if ($r['store'] === 'added') $out['stores_added']++;
            if ($r['store'] === 'updated') $out['stores_updated']++;
        }
        return $out;
    }

    private function vendorColumns(): array
    {
        static $c = null;
        if ($c === null) {
            $c = [];
            try { foreach ($this->db->query("SHOW COLUMNS FROM vendors")->fetchAll(PDO::FETCH_ASSOC) as $r) $c[$r['Field']] = true; }
            catch (Throwable $e) { $c = ['phone' => true, 'website' => true]; }
        }
        return $c;
    }

    private function hasColumn(string $table, string $col): bool
    {
        try { return $this->db->query("SHOW COLUMNS FROM `$table` LIKE " . $this->db->quote($col))->rowCount() > 0; }
        catch (Throwable $e) { return false; }
    }
}
