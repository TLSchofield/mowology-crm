<?php
/**
 * VendorKnowledgeService — Penny's door to the commons (shared, cross-tenant vendor knowledge).
 *
 *   resolve(identity)         who is this vendor, from what the receipt prints?
 *   hintsFor(localVendorRow)  universal hints for a vendor we already know locally
 *   publishLesson(expenseId)  after the owner approved a receipt: contribute the universal
 *                             part (identity, layout, SKUs, neutral category, tax treatment)
 *   vendorStreak(vendorId)    how many suggestions in a row the owner accepted unchanged
 *   codeSuggestion(ctx)       the gate + builder for a code-made suggestion (no API call)
 *
 * Mode (secrets.php): KB_MODE = 'hub' (default — this install holds the tables),
 *   'client' (KB_HUB_URL + KB_API_KEY), or 'off'. Everything fails soft: the receipt pipeline
 *   never waits on, or breaks because of, the commons.
 *
 * Privacy whitelist (what ever leaves a tenant): identity fields, tax labels/positions/date
 * format/suffix patterns, SKU → item name, a neutral category code, gst_exempt. Never amounts,
 * jobs, cards, notes, frequency, or the tenant's name (the hub sees a hash / an API key).
 *
 * No namespace / no autoloader: require_once this file and `new VendorKnowledgeService($db)`.
 */
declare(strict_types=1);

require_once __DIR__ . '/VendorIdentity.php';
require_once __DIR__ . '/CategoryTaxonomy.php';
require_once __DIR__ . '/VendorKnowledgeStore.php';
require_once __DIR__ . '/LocalVendorKnowledgeStore.php';
require_once __DIR__ . '/RemoteVendorKnowledgeStore.php';
require_once __DIR__ . '/CodeSuggestionGate.php';

final class VendorKnowledgeService
{
    private PDO $db;
    private ?VendorKnowledgeStore $store = null;
    private CategoryTaxonomy $taxonomy;
    private string $mode;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->taxonomy = new CategoryTaxonomy($db);
        $this->mode = defined('KB_MODE') ? (string)KB_MODE : 'hub';
    }

    public static function tenantKey(): string
    {
        if (defined('KB_TENANT_KEY') && KB_TENANT_KEY !== '') return (string)KB_TENANT_KEY;
        return defined('SITE_URL') ? (string)SITE_URL : (string)($_SERVER['HTTP_HOST'] ?? 'local');
    }

    public function mode(): string { return $this->mode; }

    public function store(): ?VendorKnowledgeStore
    {
        if ($this->store !== null) return $this->store;
        if ($this->mode === 'off') return null;
        if ($this->mode === 'client' && defined('KB_HUB_URL') && defined('KB_API_KEY')) {
            $this->store = new RemoteVendorKnowledgeStore($this->db, (string)KB_HUB_URL, (string)KB_API_KEY);
        } else {
            $this->store = new LocalVendorKnowledgeStore($this->db, true);
        }
        return $this->store->ready() ? $this->store : null;
    }

    public function ready(): bool { return $this->store() !== null; }

    // ── Resolve ───────────────────────────────────────────────────────────────

    /** @param array $identity VendorIdentity::fromReceipt() */
    public function resolve(array $identity): ?array
    {
        try { return ($s = $this->store()) ? $s->resolve($identity) : null; }
        catch (Throwable $e) { error_log('kb resolve: ' . $e->getMessage()); return null; }
    }

    /** The local vendors.id linked to a commons vendor, if any. */
    public function localVendorFor(int $kbVendorId): ?int
    {
        try {
            if (!$this->hasColumn('vendors', 'kb_vendor_id')) return null;
            $s = $this->db->prepare("SELECT id FROM vendors WHERE kb_vendor_id = ? AND is_active = 1 LIMIT 1");
            $s->execute([$kbVendorId]);
            $id = $s->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable $e) { return null; }
    }

    /** Link a local vendor to the commons and carry the identity fields locally. */
    public function linkLocalVendor(int $vendorId, ?int $kbVendorId, array $identity): void
    {
        try {
            if (!$this->hasColumn('vendors', 'kb_vendor_id')) return;
            $this->db->prepare("UPDATE vendors SET kb_vendor_id = COALESCE(?, kb_vendor_id), gst_number = COALESCE(gst_number, ?), name_key = COALESCE(name_key, ?), domain = COALESCE(domain, ?) WHERE id = ?")
                ->execute([$kbVendorId, $identity['gst_number'] ?? null, $identity['name_key'] ?? null, $identity['domain'] ?? null, $vendorId]);
        } catch (Throwable $e) { error_log('kb link: ' . $e->getMessage()); }
    }

    /**
     * Universal hints for a local vendor row (vendors.*): the commons' category default bridged
     * to this tenant's categories, tax treatment, kind. Soft — firm owner rules still win.
     * @return array{category_code:?string, category_local:?string, gst_exempt:?bool, kind:?string, source:?string, kb_vendor_id:?int}
     */
    public function hintsFor(array $vendorRow): array
    {
        $none = ['category_code' => null, 'category_local' => null, 'gst_exempt' => null, 'kind' => null, 'source' => null, 'kb_vendor_id' => null];
        try {
            $kb = null;
            if (!empty($vendorRow['kb_vendor_id']) && $this->store() instanceof LocalVendorKnowledgeStore) {
                $kb = $this->store()->load((int)$vendorRow['kb_vendor_id']);
            }
            if ($kb === null) {
                $kb = $this->resolve([
                    'gst_number'   => $vendorRow['gst_number'] ?? null,
                    'phone_digits' => VendorIdentity::phoneDigits($vendorRow['phone'] ?? null),
                    'domain'       => $vendorRow['domain'] ?? VendorIdentity::domainOf($vendorRow['website'] ?? null),
                    'name_key'     => $vendorRow['name_key'] ?? VendorIdentity::nameKey($vendorRow['name'] ?? null),
                ]);
            }
            if (!$kb) return $none;
            $cat = $kb['facts']['category_default'][0]['value']['code'] ?? null;
            $tax = $kb['facts']['tax_treatment'][0]['value'] ?? null;
            return [
                'category_code'  => CategoryTaxonomy::isCode($cat) ? $cat : null,
                'category_local' => $this->taxonomy->toLocal($cat),
                'gst_exempt'     => isset($tax['gst_exempt']) ? (bool)$tax['gst_exempt'] : null,
                'kind'           => $kb['kind'] ?? null,
                'source'         => 'commons',
                'kb_vendor_id'   => (int)$kb['id'],
            ];
        } catch (Throwable $e) {
            error_log('kb hints: ' . $e->getMessage());
            return $none;
        }
    }

    // ── Contribute ────────────────────────────────────────────────────────────

    /**
     * After an approved receipt: publish the universal part. Idempotent per call (the commons
     * counts confirmations; the caller — learnFromConfirmedExpense — already runs once per receipt).
     */
    public function publishLesson(int $expenseId): bool
    {
        try {
            $store = $this->store();
            if (!$store) return false;
            $s = $this->db->prepare("SELECT e.vendor_id, e.vendor_name_raw, e.accounting_category, e.gst_amount, e.amount, e.raw_ocr_json,
                                            v.name, v.phone, v.website, v.gst_exempt, v.default_accounting_category
                                     FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
            $s->execute([$expenseId]);
            $e = $s->fetch(PDO::FETCH_ASSOC);
            if (!$e || empty($e['vendor_id'])) return false;
            $vendorId = (int)$e['vendor_id'];

            $ocr = '';
            if (!empty($e['raw_ocr_json']) && function_exists('ocrTextFromStored')) $ocr = ocrTextFromStored($e['raw_ocr_json']);
            $identity = VendorIdentity::fromReceipt($ocr !== '' ? $ocr : null, $e['name'] ?: $e['vendor_name_raw'], ['phone' => $e['phone'], 'website' => $e['website']]);
            if (!empty($e['gst_number'])) $identity['gst_number'] = $e['gst_number'];

            $facts = [];
            // Layout: the vendor's paper, from the local parse profile.
            $p = $this->db->prepare("SELECT gst_label, gst_position, has_pst, pst_label, date_format, barcode_length, item_suffix_pattern, discount_prefix, card_mask_style FROM vendor_parse_profiles WHERE vendor_id = ?");
            $p->execute([$vendorId]);
            if ($prof = $p->fetch(PDO::FETCH_ASSOC)) {
                $layout = array_filter($prof, fn($v) => $v !== null && $v !== '' && $v !== 'unknown');
                if ($layout) $facts[] = ['fact' => 'receipt_layout', 'value' => $layout];
            }
            // SKUs: code → item name (never price, never product id).
            $k = $this->db->prepare("SELECT sku_raw, item_name FROM vendor_product_skus WHERE vendor_id = ? AND item_name IS NOT NULL ORDER BY times_seen DESC LIMIT 40");
            $k->execute([$vendorId]);
            foreach ($k->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $facts[] = ['fact' => 'sku', 'value' => ['sku' => (string)$row['sku_raw'], 'name' => mb_substr((string)$row['item_name'], 0, 120)]];
            }
            // Category as a neutral code (this tenant's name never travels).
            $code = $this->taxonomy->fromLocal($e['accounting_category'] ?: $e['default_accounting_category']);
            if ($code) $facts[] = ['fact' => 'category_default', 'value' => ['code' => $code]];
            // Tax treatment.
            $gstExempt = !empty($e['gst_exempt']) || ((float)$e['amount'] > 0 && (float)$e['gst_amount'] == 0.0 && !empty($e['gst_exempt']));
            $facts[] = ['fact' => 'tax_treatment', 'value' => ['gst_exempt' => (bool)$gstExempt]];

            $res = $store->contribute(['identity' => $identity, 'display_name' => $e['name'] ?: $e['vendor_name_raw'], 'facts' => $facts], self::tenantKey());
            if (is_int($res)) $this->linkLocalVendor($vendorId, $res, $identity);
            elseif ($res === true) $this->linkLocalVendor($vendorId, null, $identity);
            return (bool)$res;
        } catch (Throwable $e) {
            error_log('kb publish #' . $expenseId . ': ' . $e->getMessage());
            return false;
        }
    }

    // ── Learned vendors + code suggestions ────────────────────────────────────

    /** Suggestions in a row the owner accepted unchanged for this vendor (0 if never). */
    public function vendorStreak(int $vendorId): int
    {
        try {
            $s = $this->db->prepare("SELECT s.status FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
                                     WHERE e.vendor_id = ? AND s.source = 'live' AND s.status IN ('accepted','edited','rejected')
                                       AND e.status IN ('approved','forwarded')
                                     ORDER BY s.decided_at DESC, s.id DESC LIMIT 25");
            $s->execute([$vendorId]);
            $n = 0;
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $st) { if ($st !== 'accepted') break; $n++; }
            return $n;
        } catch (Throwable $e) { return 0; }
    }

    /** The asset tag the owner used on the vendor's recent approved receipts, when consistent. */
    public function vendorAssetTag(int $vendorId): ?string
    {
        try {
            if (!$this->hasColumn('expenses', 'asset_tag')) return null;
            $s = $this->db->prepare("SELECT asset_tag, COUNT(*) c FROM expenses WHERE vendor_id = ? AND status IN ('approved','forwarded') AND asset_tag IS NOT NULL GROUP BY asset_tag ORDER BY c DESC LIMIT 2");
            $s->execute([$vendorId]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1 && (int)$rows[0]['c'] >= 3) return (string)$rows[0]['asset_tag'];
            return null;
        } catch (Throwable $e) { return null; }
    }

    /** off | shadow | on — from ops_settings, default shadow. */
    public function codeMode(): string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'penny_code_suggestions'");
            $s->execute();
            $v = (string)($s->fetchColumn() ?: 'shadow');
            return in_array($v, ['off', 'shadow', 'on'], true) ? $v : 'shadow';
        } catch (Throwable $e) { return 'off'; }
    }

    /**
     * Gate + build a code-made suggestion for a buildContext() result.
     * @return array{ok:bool, reasons:string[], suggestion:?array}
     */
    public function codeSuggestion(array $ctx): array
    {
        $e = $ctx['expense'] ?? [];
        $vendorId = !empty($e['vendor_id']) ? (int)$e['vendor_id'] : null;
        if (!$vendorId) return ['ok' => false, 'reasons' => ['no vendor'], 'suggestion' => null];
        try {
            $s = $this->db->prepare("SELECT * FROM vendors WHERE id = ?");
            $s->execute([$vendorId]);
            $v = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $ex) { $v = []; }
        $v['streak']    = $this->vendorStreak($vendorId);
        $v['asset_tag'] = $this->vendorAssetTag($vendorId);
        $hints = $v ? $this->hintsFor($v) : [];
        $d = CodeSuggestionGate::decide($ctx, $v, $hints);
        return ['ok' => $d['ok'], 'reasons' => $d['reasons'], 'suggestion' => $d['ok'] ? CodeSuggestionGate::build($ctx, $v, $d) : null];
    }

    // ── Card numbers ──────────────────────────────────────────────────────────

    /** For Penny's card: commons size and the shadow agreement rate (code vs model, this month). */
    public function cardStats(): array
    {
        $out = ['commons_vendors' => 0, 'commons_shared' => 0, 'shadow_n' => 0, 'shadow_agree_pct' => null, 'code_mode' => $this->codeMode()];
        try {
            if ($this->store() instanceof LocalVendorKnowledgeStore) {
                $st = $this->store()->stats();
                $out['commons_vendors'] = $st['vendors']; $out['commons_shared'] = $st['shared'];
            }
            if (!$this->hasColumn('expense_suggestions', 'made_by')) return $out;
            // Pair each shadow row with the model's live row on the same receipt, same month.
            $q = $this->db->query("
                SELECT sh.suggestion_json AS code_json, lv.suggestion_json AS model_json
                FROM expense_suggestions sh
                JOIN expense_suggestions lv ON lv.expense_id = sh.expense_id AND lv.source = 'live' AND lv.made_by = 'model'
                WHERE sh.source = 'shadow' AND sh.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
                ORDER BY sh.id DESC LIMIT 400");
            $n = 0; $agree = 0;
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $c = json_decode((string)$r['code_json'], true); $m = json_decode((string)$r['model_json'], true);
                if (!is_array($c) || !is_array($m)) continue;
                $n++;
                if (($c['accounting_category']['value'] ?? null) === ($m['accounting_category']['value'] ?? null)) $agree++;
            }
            $out['shadow_n'] = $n;
            $out['shadow_agree_pct'] = $n ? (int)round($agree / $n * 100) : null;
        } catch (Throwable $e) { /* card is a bonus */ }
        return $out;
    }

    private function hasColumn(string $table, string $col): bool
    {
        static $cache = [];
        $k = $table . '.' . $col;
        if (isset($cache[$k])) return $cache[$k];
        try {
            $s = $this->db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $s->execute([$col]);
            return $cache[$k] = $s->rowCount() > 0;
        } catch (Throwable $e) { return $cache[$k] = false; }
    }
}
