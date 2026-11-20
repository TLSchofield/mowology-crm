<?php
/**
 * ProductProposalService — label photos and receipt lines become one-tap proposals.
 *
 * Two sources, one set of cards (Penny: products · Otto: machines):
 *   1. A label photo (label_captures, LabelReaderService::read):
 *        product → existing product by SKU, then by name → "Add this photo to <product>"
 *                  (+ "cost changed $41→$40 — update?"), else "New product: …".
 *                  A receipt line from the same day (±1) whose text matches the label
 *                  ("2 B. 5K Seed" ↔ a 5 kg seed bag) gives cost per unit, quantity → stock,
 *                  and the vendor.
 *        machine → existing equipment by serial, then model; else "New machine: …" with the
 *                  purchase receipt when a same-day line matches.
 *   2. Receipt line items with no product (email, receipts@, photo — any source):
 *        a. SKU / item# or name matches a product → "Link these lines to <product>"
 *           (+ cost update when the unit price differs; + qty to stock on tracked products —
 *           ExpenseLineItemService::link() does the stock and teaches the SKU memory);
 *        b. the same item bought again (same vendor + same normalised name/SKU on ≥ 2
 *           receipts) or a line with an explicit SKU → "New product: …".
 *      Never: fuel, food/meals, fees, taxes, deposits, delivery; a cheap one-off tool unless
 *      it is bought again.
 *
 * Nothing is created here. accept() writes, on Tim's click, through explicit column lists
 * (never api-products.php save-product — production's copy of that endpoint is behind the
 * repo). "Not now" is remembered: a dismissed new-product key never comes back, and every
 * receipt line is proposed once (product_proposal_lines.line_ref is unique).
 *
 * Migration 1225. No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LabelReaderService.php';

class ProductProposalService
{
    public const SAME_DAY = 1;              // ± days for "the receipt for this label"
    public const BACKFILL_DAYS = 120;
    public const CHEAP_TOOL = 25.0;         // one-off tools under this are not products…
    public const RECURRING = 2;             // …unless bought on this many receipts

    public const EXCLUDE = '/\b(fuel|gas|gasoline|diesel|propane|petrol|unleaded|regular\s+\d+|premium\s+\d+|coffee|tea|meal|meals|lunch|breakfast|dinner|(?<!lawn\s)(?<!plant\s)(?<!turf\s)(?<!tree\s)food|snack|restaurant|burger|sandwich|pizza|donut|doughnut|muffin|drink|drinks|pop|soda|fee|fees|surcharge|levy|eco\s*fee|enviro|environmental|recycl\w*|deposit|core\s+charge|delivery|freight|shipping|handling|tax|taxes|gst|pst|hst|subtotal|total|change|cash|visa|mastercard|debit|amex|rounding|discount|tip|gratuity|rental|rent|labour|labor|service\s+charge)\b/i';
    public const TOOLISH = '/\b(glove|gloves|tape|screw|screws|bolt|bolts|nut|nuts|washer|washers|bit|bits|blade|blades|nozzle|tool|tools|knife|marker|zip\s*ties?|cable\s*ties?|rope|twine|bucket|brush|rake|shovel|spade|hammer|wrench|socket|plier|pliers|file|sandpaper|glue|caulk)\b/i';
    public const MATERIAL = '/\b(mulch|soil|seed|sand|gravel|rock|rocks|stone|fertili[sz]er|fert|lime|sod|bark|compost|manure|topsoil|turf|grass|blend|mix|chip|chips|pebble|herbicide|weed|moss|lawn|cbm|yd|yard|yards|bag|bags|edging|landscape fabric|fabric|stakes|line|trimmer line|oil|bar oil|2-cycle|mix oil)\b/i';
    public const CHEMICAL = '/\b(fertili[sz]er|herbicide|pesticide|insecticide|fungicide|weed\s*(?:and|&)?\s*feed|killer|moss\s*(?:control|killer)|lime|sulphur|sulfur|iron|round\s*up|roundup|glyphosate|chemical|oil)\b/i';

    private PDO $db;
    /** @var callable|null fn(int $lineItemId, int $productId): void */
    private $linker;
    private string $today;
    private ?array $productCache = null;
    private array $colCache = [];

    public function __construct(PDO $db, ?callable $linker = null, ?string $today = null)
    {
        $this->db = $db;
        $this->linker = $linker;
        $this->today = $today ?? date('Y-m-d');
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM product_proposals LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Pure rules (unit-tested) ────────────────────────────────────────────

    public static function excluded(string $name): bool
    {
        $n = trim($name);
        if ($n === '' || strlen(preg_replace('/[^a-z]/i', '', $n)) < 2) return true;
        return (bool)preg_match(self::EXCLUDE, $n);
    }

    public static function isToolish(string $name): bool
    {
        return (bool)preg_match(self::TOOLISH, $name);
    }

    public static function isMaterial(string $name): bool
    {
        return (bool)preg_match(self::MATERIAL, $name);
    }

    public static function isChemical(string $name): bool
    {
        return (bool)preg_match(self::CHEMICAL, $name);
    }

    /** The word after "$60/": bag for sized bags, yd for bulk, else each. */
    public static function unitWord(string $name): string
    {
        $size = LabelReaderService::sizeOf($name);
        if ($size && preg_match('/yd|cuft$/', $size)) return 'yd';
        if (preg_match('/\b(yd|yds|yard|yards|cbm|bulk)\b/i', $name)) return 'yd';
        if ($size && preg_match('/(kg|lb|g|l|ml|oz|gal)$/', $size)) return 'bag';
        if (!$size && preg_match('/\b(mulch|soil|topsoil|gravel|sand|compost|bark|rock|rocks|chips?|manure|road\s*base)\b/i', $name)) return 'yd';
        if (preg_match('/\b(bag|bags|seed|fertili[sz]er)\b/i', $name)) return 'bag';
        return 'each';
    }

    /** A SKU-ish token printed on a line: "1000123456", "TL02100350" (≥ 6 chars, has a digit). */
    public static function skuInName(string $name): ?string
    {
        foreach (LabelReaderService::tokens($name) as $t) {
            if (strlen($t) >= 6 && preg_match('/\d/', $t) && !preg_match('/^\d{2,4}-\d{1,2}-\d{1,4}$/', $t)) return $t;
        }
        return null;
    }

    /**
     * How well a receipt / label text names a product. 100 = SKU hit; otherwise shared words,
     * with a matching size counting 2 and a different size vetoing.
     */
    public static function matchScore(string $text, ?string $sku, array $product): float
    {
        $psku = strtoupper(trim((string)($product['sku'] ?? '')));
        if ($psku !== '' && strlen($psku) >= 3) {
            if ($sku !== null && strtoupper(trim($sku)) === $psku) return 100.0;
            if (in_array($psku, LabelReaderService::tokens($text), true)) return 100.0;
        }
        $pname = (string)($product['name'] ?? '');
        if ($pname === '') return 0.0;
        $a = LabelReaderService::words($text);
        $b = LabelReaderService::words($pname);
        if (!$a || !$b) return 0.0;
        $shared = count(array_intersect($a, $b));
        $sa = LabelReaderService::sizeOf($text);
        $sb = LabelReaderService::sizeOf($pname);
        if ($sa && $sb && $sa !== $sb) return 0.0;
        $sizeHit = $sa && $sb && $sa === $sb;
        if ($shared === 0) return 0.0;
        $jac = $shared / count(array_unique(array_merge($a, $b)));
        $score = $shared + ($sizeHit ? 2 : 0) + $jac;
        // Enough: a size hit plus a word ("5K Seed" ↔ "… Lawn Seed 5 kg"), or most of the words.
        if ($sizeHit && $shared >= 1 && $shared / count($a) >= 0.5) return round($score, 3);
        if ($shared >= 2 && $jac >= 0.5) return round($score, 3);
        if ($shared >= 1 && $jac >= 0.99) return round($score, 3);
        return 0.0;
    }

    public static function money(?float $v): string
    {
        if ($v === null) return '';
        return '$' . (abs($v - round($v)) < 0.005 ? number_format($v, 0) : number_format($v, 2));
    }

    public static function vendorLabel(?string $v): string
    {
        $v = trim((string)$v);
        if ($v === '') return '';
        if ($v === strtoupper($v)) $v = ucwords(strtolower($v));
        return preg_replace('/\s+(Enterprises|Ltd\.?|Inc\.?|Limited|Corp\.?)$/i', '', $v);
    }

    /** The sentence on the card. $p = queue item (payload decoded, lines attached). */
    public static function say(array $p): string
    {
        $pl = $p['payload'] ?? [];
        $lines = $p['lines'] ?? [];
        $qty = 0.0;
        foreach ($lines as $l) if (!empty($l['line_item_id'])) $qty += (float)$l['quantity'];
        $qtyTxt = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
        switch ($p['kind']) {
            case 'product_new':
                $parts = ['New product: ' . $pl['name']];
                if (isset($pl['unit_cost']) && $pl['unit_cost'] !== null) {
                    $parts[] = self::money((float)$pl['unit_cost']) . '/' . ($pl['unit'] ?? 'each') . (!empty($pl['vendor_name']) ? ' from ' . self::vendorLabel($pl['vendor_name']) : '');
                } elseif (!empty($pl['vendor_name'])) {
                    $parts[] = 'from ' . self::vendorLabel($pl['vendor_name']);
                }
                if (!empty($pl['track_inventory']) && $qty > 0) $parts[] = $qtyTxt . ' in stock';
                if (!empty($pl['photo_url'])) $parts[] = 'photo';
                elseif (!empty($pl['sku'])) $parts[] = 'SKU ' . $pl['sku'];
                $n = count(array_unique(array_filter(array_column($lines, 'expense_id'))));
                if ($n > 1) $parts[] = 'bought on ' . $n . ' receipts';
                return implode(' · ', $parts);
            case 'product_match':
                $bits = [];
                if (!empty($pl['photo_url'])) $bits[] = 'Add this photo to ' . $pl['product_name'];
                $link = array_filter($lines, fn($l) => !empty($l['line_item_id']));
                if ($link) {
                    $bits[] = ($bits ? 'link ' : 'Link ') . count($link) . ' receipt line' . (count($link) === 1 ? '' : 's') . ($bits ? '' : ' to ' . $pl['product_name'])
                            . (!empty($pl['track_inventory']) && $qty > 0 ? ' (+' . $qtyTxt . ' to stock)' : '');
                }
                if (!$bits) $bits[] = $pl['product_name'];
                $s = implode(' · ', $bits);
                if (!empty($pl['cost_change'])) {
                    $s .= ' · cost changed ' . self::money((float)$pl['cost_change']['old']) . '→' . self::money((float)$pl['cost_change']['new']) . ' — update?';
                }
                return $s;
            case 'equipment_new':
                $s = 'New machine: ' . $pl['name'];
                if (!empty($pl['serial_no'])) $s .= ', S/N ' . $pl['serial_no'];
                if (!empty($pl['purchase_date'])) $s .= ', bought ' . date('M j', strtotime($pl['purchase_date']));
                if (!empty($pl['expense_id'])) $s .= ' receipt #' . (int)$pl['expense_id'];
                return $s . ' — Add to equipment';
            case 'equipment_match':
                $f = array_keys(array_filter($pl['fill'] ?? []));
                $words = ['serial_no' => 'serial', 'model' => 'model', 'make' => 'make'];
                return 'This is your ' . $pl['name'] . ' — add the ' . implode(' and ', array_map(fn($k) => $words[$k] ?? $k, $f)) . ' from the label?';
        }
        return (string)($p['title'] ?? '');
    }

    // ── Products, equipment, lines ──────────────────────────────────────────

    private function products(): array
    {
        if ($this->productCache !== null) return $this->productCache;
        $rows = [];
        try {
            foreach ($this->db->query("SELECT * FROM products")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $active = $r['active'] ?? ($r['is_active'] ?? 1);
                if ((int)$active === 0) continue;
                $rows[] = $r;
            }
        } catch (Throwable $e) { /* no products table */ }
        return $this->productCache = $rows;
    }

    /** Best product for a text / SKU: [product, score] or [null, 0]. Ties → none (ambiguous). */
    public function findProduct(string $text, ?string $sku): array
    {
        $best = null; $bestScore = 0.0; $tie = false;
        foreach ($this->products() as $p) {
            $s = self::matchScore($text, $sku, $p);
            if ($s <= 0) continue;
            if ($s > $bestScore) { $best = $p; $bestScore = $s; $tie = false; }
            elseif ($s === $bestScore) $tie = true;
        }
        return $tie && $bestScore < 100 ? [null, 0.0] : [$best, $bestScore];
    }

    private function hasCol(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!isset($this->colCache[$k])) {
            try { $this->db->query("SELECT {$col} FROM {$table} LIMIT 0"); $this->colCache[$k] = true; }
            catch (Throwable $e) { $this->colCache[$k] = false; }
        }
        return $this->colCache[$k];
    }

    /**
     * The lines of one receipt: stored expense_line_items, or — for a receipt whose lines were
     * never saved (email inbox) — the parsed lines kept in raw_ocr_json.
     */
    public function linesFor(int $expenseId): array
    {
        $e = $this->db->prepare("SELECT e.id, e.expense_date, e.vendor_id, e.vendor_name_raw, e.raw_ocr_json, e.created_by, v.name AS vendor_name
                                 FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $e->execute([$expenseId]);
        $x = $e->fetch(PDO::FETCH_ASSOC);
        if (!$x) return [];
        $base = ['expense_id' => (int)$x['id'], 'date' => substr((string)$x['expense_date'], 0, 10),
                 'vendor_id' => $x['vendor_id'] ? (int)$x['vendor_id'] : null,
                 'vendor_name' => (string)($x['vendor_name'] ?: $x['vendor_name_raw']), 'created_by' => $x['created_by'] ? (int)$x['created_by'] : null];
        $s = $this->db->prepare("SELECT id, name, quantity, unit_price, line_total, sku_raw, product_id"
                              . ($this->hasCol('expense_line_items', 'is_adjustment') ? ', is_adjustment' : ', 0 AS is_adjustment')
                              . " FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");
        $s->execute([$expenseId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qty = (float)($r['quantity'] ?: 1);
            $unit = $r['unit_price'] !== null ? (float)$r['unit_price'] : ($qty > 0 ? round((float)$r['line_total'] / $qty, 2) : null);
            $out[] = $base + ['ref' => 'li:' . (int)$r['id'], 'line_item_id' => (int)$r['id'], 'name' => (string)$r['name'],
                              'sku' => $r['sku_raw'] ? (string)$r['sku_raw'] : null, 'quantity' => $qty, 'unit_price' => $unit,
                              'line_total' => (float)$r['line_total'], 'product_id' => $r['product_id'] ? (int)$r['product_id'] : null,
                              'adjustment' => (int)$r['is_adjustment'] === 1];
        }
        if ($out) return $out;
        $raw = json_decode((string)$x['raw_ocr_json'], true);
        foreach (($raw['parsed']['line_items'] ?? []) as $i => $li) {
            if (!is_array($li) || !empty($li['removed'])) continue;
            $qty = (float)($li['quantity'] ?? 1) ?: 1.0;
            $total = (float)($li['line_total'] ?? $li['amount'] ?? 0);
            $unit = isset($li['unit_price']) && $li['unit_price'] !== null && $li['unit_price'] !== '' ? (float)$li['unit_price'] : ($total ? round($total / $qty, 2) : null);
            $out[] = $base + ['ref' => 'ocr:' . (int)$x['id'] . ':' . (int)$i, 'line_item_id' => null, 'name' => (string)($li['name'] ?? ''),
                              'sku' => !empty($li['sku_raw']) ? (string)$li['sku_raw'] : null, 'quantity' => $qty, 'unit_price' => $unit,
                              'line_total' => $total, 'product_id' => !empty($li['product_id']) ? (int)$li['product_id'] : null,
                              'adjustment' => !empty($li['is_adjustment'])];
        }
        return $out;
    }

    /** Unlinked lines of the same vendor over the last year (recurrence). */
    private function vendorLines(array $line): array
    {
        $since = date('Y-m-d', strtotime($this->today . ' -365 days'));
        if ($line['vendor_id']) {
            $s = $this->db->prepare("SELECT id FROM expenses WHERE vendor_id = ? AND expense_date >= ? ORDER BY id DESC LIMIT 200");
            $s->execute([$line['vendor_id'], $since]);
        } elseif ($line['vendor_name'] !== '') {
            $s = $this->db->prepare("SELECT id FROM expenses WHERE vendor_name_raw = ? AND expense_date >= ? ORDER BY id DESC LIMIT 200");
            $s->execute([$line['vendor_name'], $since]);
        } else {
            return [];
        }
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            foreach ($this->linesFor((int)$eid) as $l) if (!$l['product_id']) $out[] = $l;
        }
        return $out;
    }

    private function refsTaken(array $refs): array
    {
        if (!$refs) return [];
        $s = $this->db->prepare("SELECT line_ref, proposal_id FROM product_proposal_lines WHERE line_ref IN (" . implode(',', array_fill(0, count($refs), '?')) . ")");
        $s->execute(array_values($refs));
        return $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // ── Receipts → proposals ────────────────────────────────────────────────

    /** Run after a receipt's line items are saved (any source). Returns proposals made or grown. */
    public function proposeForExpense(int $expenseId): int
    {
        if (!$this->ready() || $expenseId <= 0) return 0;
        $this->markScanned($expenseId);
        $touched = 0;
        foreach ($this->linesFor($expenseId) as $l) {
            if ($l['product_id'] || $l['adjustment'] || $l['line_total'] < 0 || self::excluded($l['name'])) continue;
            if ($this->refsTaken([$l['ref']])) continue;
            $sku = $l['sku'] ?: self::skuInName($l['name']);
            [$p, $score] = $this->findProduct($l['name'], $sku);
            if ($p) {
                $touched += $this->proposeLink($p, [$l]) ? 1 : 0;
                continue;
            }
            $touched += $this->proposeNewFromLine($l, $sku) ? 1 : 0;
        }
        return $touched;
    }

    private function proposeLink(array $p, array $lines): bool
    {
        $cost = (float)($p['base_cost'] ?? 0);
        $latest = null;
        foreach ($lines as $l) if ($l['unit_price'] !== null && ($latest === null || $l['date'] >= $latest['date'])) $latest = $l;
        $change = $latest && $cost > 0 && abs($cost - (float)$latest['unit_price']) >= 0.01
            ? ['old' => $cost, 'new' => (float)$latest['unit_price'], 'date' => $latest['date']] : null;
        $linkable = array_filter($lines, fn($l) => !empty($l['line_item_id']));
        if (!$linkable && !$change) return false;
        $payload = ['product_id' => (int)$p['id'], 'product_name' => (string)$p['name'], 'track_inventory' => (int)($p['track_inventory'] ?? 0),
                    'cost_change' => $change];
        return $this->upsert('penny', 'product_match', 'link:p' . (int)$p['id'], 'Link to ' . $p['name'], $payload, $lines,
                             ['product_id' => (int)$p['id'], 'vendor_id' => $lines[0]['vendor_id'] ?? null], false);
    }

    private function proposeNewFromLine(array $l, ?string $sku): bool
    {
        $norm = LabelReaderService::normName($l['name']);
        if ($norm === '' || !LabelReaderService::words($l['name'])) return false;
        $same = [];
        foreach ($this->vendorLines($l) as $o) {
            $osku = $o['sku'] ?: self::skuInName($o['name']);
            if (($sku && $osku && strtoupper($osku) === strtoupper($sku)) || LabelReaderService::normName($o['name']) === $norm) $same[$o['ref']] = $o;
        }
        $same[$l['ref']] = $l;
        $receipts = count(array_unique(array_column($same, 'expense_id')));
        $repeated = $receipts >= self::RECURRING;
        if (!$repeated && !$sku) return false;
        if (!$repeated && self::isToolish($l['name']) && ($l['unit_price'] === null || $l['unit_price'] < self::CHEAP_TOOL)) return false;

        $vendorKey = $l['vendor_id'] ? 'v' . $l['vendor_id'] : 'vn' . substr(md5(strtolower($l['vendor_name'])), 0, 8);
        $key = $sku ? 'new:sku:' . strtoupper($sku) : 'new:' . $vendorKey . ':' . substr($norm, 0, 100);
        $lines = array_values($same);
        usort($lines, fn($a, $b) => strcmp($a['date'], $b['date']));
        $latest = null;
        foreach ($lines as $x) if ($x['unit_price'] !== null) $latest = $x;
        $name = self::cleanLineName($l['name'], $sku);
        $payload = [
            'name' => $name, 'sku' => $sku ? strtoupper($sku) : null, 'vendor_id' => $l['vendor_id'], 'vendor_name' => $l['vendor_name'],
            'unit_cost' => $latest ? (float)$latest['unit_price'] : null, 'unit' => self::unitWord($l['name']),
            'track_inventory' => self::isMaterial($l['name']) ? 1 : 0, 'source' => 'receipts',
        ];
        $payload['supplier_info'] = self::supplierInfo($payload['vendor_name'], $payload['unit_cost'], $payload['unit'], $latest['date'] ?? null, $sku);
        return $this->upsert('penny', 'product_new', $key, 'New product: ' . $name, $payload, $lines, ['vendor_id' => $l['vendor_id']], true);
    }

    public static function cleanLineName(string $name, ?string $sku): string
    {
        $n = $name;
        if ($sku) $n = str_ireplace($sku, '', $n);
        $n = preg_replace('/^\s*\d+(?:\.\d+)?\s*(?:x|@)?\s*(?:b\.?\s+)?/i', '', $n);   // leading qty "2 B."
        $n = trim(preg_replace('/\s+/', ' ', $n), " -#:.");
        return LabelReaderService::titleCase($n !== '' ? $n : $name);
    }

    public static function supplierInfo(?string $vendor, ?float $cost, string $unit, ?string $date, ?string $sku): ?string
    {
        $v = self::vendorLabel($vendor);
        if ($v === '' && $cost === null) return null;
        return trim($v . ($cost !== null ? ' — ' . self::money($cost) . ' per ' . $unit : '') . ($date ? ' (' . $date . ')' : '') . ($sku ? ' · item ' . $sku : ''));
    }

    /**
     * Make or grow a proposal. New-product and label keys are remembered whatever their
     * status (Not now stays not now); link keys only join a pending proposal.
     */
    private function upsert(string $head, string $kind, string $key, string $title, array $payload, array $lines, array $ids, bool $anyStatus): bool
    {
        $taken = $this->refsTaken(array_column($lines, 'ref'));
        $fresh = array_values(array_filter($lines, fn($l) => !isset($taken[$l['ref']])));
        $s = $this->db->prepare("SELECT id, status, payload_json FROM product_proposals WHERE proposal_key = ?"
                              . ($anyStatus ? '' : " AND status = 'pending'") . " ORDER BY status = 'pending' DESC, id DESC LIMIT 1");
        $s->execute([$key]);
        $ex = $s->fetch(PDO::FETCH_ASSOC);
        $now = $this->today . ' ' . date('H:i:s');
        if ($ex) {
            if (!$fresh) return false;
            $this->addLines((int)$ex['id'], $fresh);
            if ($ex['status'] === 'pending') {
                $old = json_decode((string)$ex['payload_json'], true) ?: [];
                if ($kind === 'product_new' && isset($payload['unit_cost'])) {
                    $old['unit_cost'] = $payload['unit_cost'];
                    $old['supplier_info'] = $payload['supplier_info'] ?? ($old['supplier_info'] ?? null);
                }
                if ($kind === 'product_match' && !empty($payload['cost_change'])) $old['cost_change'] = $payload['cost_change'];
                $this->db->prepare("UPDATE product_proposals SET payload_json = ?, updated_at = ? WHERE id = ?")
                     ->execute([json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, (int)$ex['id']]);
                return true;
            }
            return false;   // dismissed / accepted: remembered, the line is just recorded
        }
        if (!$fresh && strpos($key, 'label:') !== 0) return false;
        $this->db->prepare("INSERT INTO product_proposals (head, kind, proposal_key, status, title, product_id, equipment_id, label_capture_id, vendor_id, payload_json, created_at, updated_at)
                            VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)")
             ->execute([$head, $kind, $key, mb_substr($title, 0, 255), $ids['product_id'] ?? null, $ids['equipment_id'] ?? null,
                        $ids['label_capture_id'] ?? null, $ids['vendor_id'] ?? null,
                        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]);
        $id = (int)$this->db->lastInsertId();
        $this->addLines($id, $fresh);
        return true;
    }

    private function addLines(int $proposalId, array $lines): void
    {
        $ins = $this->db->prepare("INSERT INTO product_proposal_lines (proposal_id, line_ref, expense_id, line_item_id, name, quantity, unit_price, line_date, vendor_name)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($lines as $l) {
            try {
                $ins->execute([$proposalId, $l['ref'], $l['expense_id'] ?? null, $l['line_item_id'] ?? null, mb_substr((string)$l['name'], 0, 255),
                               $l['quantity'] ?? null, $l['unit_price'] ?? null, $l['date'] ?? null, mb_substr((string)($l['vendor_name'] ?? ''), 0, 120) ?: null]);
            } catch (Throwable $e) { /* line_ref already taken (a race) — each line once */ }
        }
    }

    private function markScanned(int $expenseId): void
    {
        try {
            $s = $this->db->prepare("SELECT 1 FROM product_proposal_scans WHERE expense_id = ?");
            $s->execute([$expenseId]);
            if ($s->fetchColumn()) {
                $this->db->prepare("UPDATE product_proposal_scans SET scanned_at = ? WHERE expense_id = ?")->execute([$this->today . ' ' . date('H:i:s'), $expenseId]);
            } else {
                $this->db->prepare("INSERT INTO product_proposal_scans (expense_id, scanned_at) VALUES (?, ?)")->execute([$expenseId, $this->today . ' ' . date('H:i:s')]);
            }
        } catch (Throwable $e) { /* best effort */ }
    }

    /** Read receipts not scanned yet, newest first, over the last N days. */
    public function backfill(int $days = self::BACKFILL_DAYS, int $limit = 10): array
    {
        if (!$this->ready()) return ['scanned' => 0, 'proposals' => 0, 'left' => 0];
        $since = date('Y-m-d', strtotime($this->today . ' -' . max(1, $days) . ' days'));
        $s = $this->db->prepare("SELECT e.id FROM expenses e LEFT JOIN product_proposal_scans s ON s.expense_id = e.id
                                 WHERE s.expense_id IS NULL AND e.expense_date >= ? ORDER BY e.id DESC LIMIT " . max(1, min(200, $limit)));
        $s->execute([$since]);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        $n = 0;
        foreach ($ids as $id) $n += $this->proposeForExpense((int)$id);
        $c = $this->db->prepare("SELECT COUNT(*) FROM expenses e LEFT JOIN product_proposal_scans s ON s.expense_id = e.id WHERE s.expense_id IS NULL AND e.expense_date >= ?");
        $c->execute([$since]);
        return ['scanned' => count($ids), 'proposals' => $n, 'left' => (int)$c->fetchColumn()];
    }

    // ── Label photo → proposal ──────────────────────────────────────────────

    /** Receipt lines from the capture day ±1 whose text names this label, best first. */
    public function sameDayLines(array $read, string $date, ?int $userId): array
    {
        $from = date('Y-m-d', strtotime($date . ' -' . self::SAME_DAY . ' days'));
        $to   = date('Y-m-d', strtotime($date . ' +' . self::SAME_DAY . ' days'));
        $s = $this->db->prepare("SELECT id, created_by FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY id DESC LIMIT 60");
        $s->execute([$from, $to]);
        $target = ['name' => (string)($read['display_name'] ?? $read['name'] ?? ''), 'sku' => $read['sku'] ?? $read['model'] ?? null];
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $e) {
            foreach ($this->linesFor((int)$e['id']) as $l) {
                if (self::excluded($l['name'])) continue;
                $score = self::matchScore($l['name'], $l['sku'], $target);
                if ($score <= 0 && ($read['kind'] ?? '') === 'machine') {
                    // Machines: the model on the line, or brand + class word.
                    $tok = LabelReaderService::tokens($l['name']);
                    if (!empty($read['model']) && in_array(strtoupper($read['model']), $tok, true)) $score = 50;
                    elseif (!empty($read['brand']) && stripos($l['name'], (string)$read['brand']) !== false
                            && LabelReaderService::equipmentClass($l['name']) === ($read['equipment_class'] ?? '')) $score = 3;
                }
                if ($score <= 0) continue;
                $l['score'] = $score + ($userId && $e['created_by'] && (int)$e['created_by'] === $userId ? 0.5 : 0);
                $out[] = $l;
            }
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    public function proposeFromLabel(int $captureId): array
    {
        $s = $this->db->prepare("SELECT * FROM label_captures WHERE id = ?");
        $s->execute([$captureId]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['ok' => false, 'message' => 'Label photo not found'];
        $read = json_decode((string)$c['parsed_json'], true) ?: LabelReaderService::read((string)$c['ocr_text']);
        $date = substr((string)$c['captured_at'], 0, 10) ?: $this->today;
        $photo = $c['media_id'] ? self::photoUrl((int)$c['media_id']) : null;
        $userId = $c['captured_by'] ? (int)$c['captured_by'] : null;
        $key = 'label:' . $captureId;
        $lines = $this->sameDayLines($read, $date, $userId);
        $line = $lines[0] ?? null;

        if (($read['kind'] ?? '') === 'machine') return $this->proposeMachine($c, $read, $line, $key);

        $name = $read['display_name'] ?: ($read['name'] ?: 'Unnamed product');
        [$p] = $this->findProduct($name, $read['sku'] ?? null);
        $care = LabelReaderService::careText($read);
        if ($p) {
            $useLines = $line && (!$line['product_id'] || (int)$line['product_id'] === (int)$p['id']) ? [$line] : [];
            $cost = (float)($p['base_cost'] ?? 0);
            $change = $line && $line['unit_price'] !== null && $cost > 0 && abs($cost - (float)$line['unit_price']) >= 0.01
                ? ['old' => $cost, 'new' => (float)$line['unit_price'], 'date' => $line['date']] : null;
            $linkLines = array_values(array_filter($useLines, fn($l) => !$l['product_id']));
            $payload = ['product_id' => (int)$p['id'], 'product_name' => (string)$p['name'], 'track_inventory' => (int)($p['track_inventory'] ?? 0),
                        'photo_url' => $photo, 'media_id' => $c['media_id'] ? (int)$c['media_id'] : null, 'care_notes' => $care, 'cost_change' => $change,
                        'read' => self::readSummary($read)];
            $this->claimLines($linkLines);
            $this->upsert('penny', 'product_match', $key, 'Add photo to ' . $p['name'], $payload, $linkLines,
                          ['product_id' => (int)$p['id'], 'label_capture_id' => $captureId, 'vendor_id' => $line['vendor_id'] ?? null], true);
            return ['ok' => true, 'kind' => 'product', 'existing' => true, 'title' => (string)$p['name'],
                    'message' => 'That is ' . $p['name'] . '. Penny will ask you to add the photo' . ($change ? ' and update the cost' : '') . '.'];
        }

        $linkLines = $line && !$line['product_id'] ? [$line] : [];
        $sku = $read['sku'] ?? null;
        $desc = self::describe($read);
        $payload = [
            'name' => $name, 'sku' => $sku, 'vendor_id' => $line['vendor_id'] ?? null, 'vendor_name' => $line['vendor_name'] ?? null,
            'unit_cost' => $line['unit_price'] ?? null, 'unit' => self::unitWord($name), 'track_inventory' => 1,
            'description' => $desc, 'care_notes' => $care, 'safety' => implode(' ', $read['safety'] ?? []),
            'photo_url' => $photo, 'media_id' => $c['media_id'] ? (int)$c['media_id'] : null, 'source' => 'label', 'read' => self::readSummary($read),
        ];
        $payload['supplier_info'] = self::supplierInfo($payload['vendor_name'], $payload['unit_cost'], $payload['unit'], $line['date'] ?? null, null);
        if (!empty($read['maker'])) $payload['supplier_info'] = trim(($payload['supplier_info'] ? $payload['supplier_info'] . '. ' : '') . 'Made by ' . ($read['brand'] && stripos($read['maker'], (string)$read['brand']) === false ? $read['brand'] . ' / ' : '') . $read['maker'] . '.');
        $this->claimLines($linkLines);
        $this->upsert('penny', 'product_new', $key, 'New product: ' . $name, $payload, $linkLines,
                      ['label_capture_id' => $captureId, 'vendor_id' => $line['vendor_id'] ?? null], true);
        return ['ok' => true, 'kind' => 'product', 'existing' => false, 'title' => $name,
                'message' => 'New product: ' . $name . ($line ? ' — matched to ' . self::vendorLabel($line['vendor_name']) . ' receipt #' . $line['expense_id'] : '') . '. Penny will ask you to add it.'];
    }

    /** A label is better evidence than a receipt guess: move a line out of a pending receipt proposal. */
    private function claimLines(array $lines): void
    {
        foreach ($lines as $l) {
            $s = $this->db->prepare("SELECT l.proposal_id, p.status FROM product_proposal_lines l JOIN product_proposals p ON p.id = l.proposal_id WHERE l.line_ref = ?");
            $s->execute([$l['ref']]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['status'] !== 'pending') continue;
            $this->db->prepare("DELETE FROM product_proposal_lines WHERE line_ref = ?")->execute([$l['ref']]);
            $left = $this->db->prepare("SELECT COUNT(*) FROM product_proposal_lines WHERE proposal_id = ?");
            $left->execute([(int)$r['proposal_id']]);
            if ((int)$left->fetchColumn() === 0) {
                $this->db->prepare("DELETE FROM product_proposals WHERE id = ? AND status = 'pending' AND label_capture_id IS NULL")->execute([(int)$r['proposal_id']]);
            }
        }
    }

    private function proposeMachine(array $c, array $read, ?array $line, string $key): array
    {
        $eq = null;
        try {
            if (!empty($read['serial'])) {
                $s = $this->db->prepare("SELECT * FROM equipment WHERE UPPER(serial_no) = ? LIMIT 1");
                $s->execute([strtoupper($read['serial'])]);
                $eq = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$eq && !empty($read['model'])) {
                $s = $this->db->prepare("SELECT * FROM equipment WHERE UPPER(model) = ? AND status = 'active' ORDER BY id");
                $s->execute([strtoupper($read['model'])]);
                $rows = $s->fetchAll(PDO::FETCH_ASSOC);
                // Two of the same model: only the one without a serial can be "this one".
                $noSerial = array_values(array_filter($rows, fn($r) => trim((string)$r['serial_no']) === ''));
                $eq = count($rows) === 1 ? $rows[0] : (count($noSerial) === 1 ? $noSerial[0] : null);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'The equipment register is not set up yet (migration 1155).'];
        }
        $captureId = (int)$c['id'];
        $name = $read['display_name'] ?: 'Machine';
        if ($eq) {
            $fill = [];
            if (trim((string)$eq['serial_no']) === '' && !empty($read['serial'])) $fill['serial_no'] = $read['serial'];
            if (trim((string)$eq['model']) === '' && !empty($read['model'])) $fill['model'] = $read['model'];
            if (trim((string)$eq['make']) === '' && !empty($read['brand'])) $fill['make'] = $read['brand'];
            if (!$fill) {
                $this->finishCapture($captureId, 'equipment', (int)$eq['id']);
                return ['ok' => true, 'kind' => 'machine', 'existing' => true, 'title' => (string)$eq['name'],
                        'message' => 'That is your ' . $eq['name'] . ' — already in equipment with this model and serial.'];
            }
            $this->upsert('otto', 'equipment_match', $key, 'Update ' . $eq['name'], ['equipment_id' => (int)$eq['id'], 'name' => (string)$eq['name'], 'fill' => $fill,
                          'media_id' => $c['media_id'] ? (int)$c['media_id'] : null, 'read' => self::readSummary($read)], [],
                          ['equipment_id' => (int)$eq['id'], 'label_capture_id' => $captureId], true);
            return ['ok' => true, 'kind' => 'machine', 'existing' => true, 'title' => (string)$eq['name'],
                    'message' => 'That is your ' . $eq['name'] . '. Otto will ask you to add the details from the label.'];
        }
        $payload = [
            'name' => $name, 'equipment_class' => $read['equipment_class'] ?: 'other', 'make' => $read['brand'], 'model' => $read['model'],
            'serial_no' => $read['serial'], 'power_source' => $read['power_source'] ?: 'battery',
            'purchase_date' => $line['date'] ?? null, 'expense_id' => $line['expense_id'] ?? null,
            'media_id' => $c['media_id'] ? (int)$c['media_id'] : null, 'read' => self::readSummary($read),
        ];
        $this->upsert('otto', 'equipment_new', $key, 'New machine: ' . $name, $payload, [], ['label_capture_id' => $captureId], true);
        return ['ok' => true, 'kind' => 'machine', 'existing' => false, 'title' => $name,
                'message' => 'New machine: ' . $name . ($line ? ' — receipt #' . $line['expense_id'] : '') . '. Otto will ask you to add it to equipment.'];
    }

    public static function photoUrl(int $mediaId): string
    {
        return '/crm/api/serve-receipt.php?id=' . $mediaId;
    }

    /** What the label says, for the product description (label words only). */
    public static function describe(array $read): string
    {
        $bits = [];
        if (!empty($read['grade'])) $bits[] = LabelReaderService::titleCase((string)$read['grade']);
        if (!empty($read['composition'])) {
            $bits[] = implode(', ', array_map(fn($c) => rtrim(rtrim(number_format((float)$c['pct'], 1, '.', ''), '0'), '.') . '% ' . $c['what'], $read['composition']));
        }
        if (!empty($read['size_label'])) $bits[] = $read['size_label'] . ' bag';
        return $bits ? implode(': ', array_slice($bits, 0, 2)) . (count($bits) > 2 ? '. ' . implode('. ', array_slice($bits, 2)) : '') . '.' : '';
    }

    private static function readSummary(array $r): array
    {
        return array_intersect_key($r, array_flip(['kind', 'brand', 'maker', 'name', 'sku', 'model', 'serial', 'size_label', 'batch', 'address', 'care', 'safety', 'composition', 'voltage']));
    }

    private function finishCapture(int $captureId, string $type, int $resultId): void
    {
        try {
            $this->db->prepare("UPDATE label_captures SET status = 'done', result_type = ?, result_id = ? WHERE id = ?")->execute([$type, $resultId, $captureId]);
        } catch (Throwable $e) { /* best effort */ }
    }

    // ── The cards ───────────────────────────────────────────────────────────

    public function queue(string $head, int $limit = 20): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("SELECT * FROM product_proposals WHERE head = ? AND status = 'pending' ORDER BY label_capture_id IS NULL, created_at DESC, id DESC LIMIT " . max(1, min(100, $limit)));
        $s->execute([$head]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $this->item($r);
        return $out;
    }

    public function count(string $head): int
    {
        if (!$this->ready()) return 0;
        $s = $this->db->prepare("SELECT COUNT(*) FROM product_proposals WHERE head = ? AND status = 'pending'");
        $s->execute([$head]);
        return (int)$s->fetchColumn();
    }

    private function item(array $r): array
    {
        $l = $this->db->prepare("SELECT expense_id, line_item_id, name, quantity, unit_price, line_date AS date, vendor_name FROM product_proposal_lines WHERE proposal_id = ? ORDER BY line_date, id");
        $l->execute([(int)$r['id']]);
        $item = ['id' => (int)$r['id'], 'head' => $r['head'], 'kind' => $r['kind'], 'title' => $r['title'],
                 'payload' => json_decode((string)$r['payload_json'], true) ?: [], 'lines' => $l->fetchAll(PDO::FETCH_ASSOC),
                 'created_at' => $r['created_at']];
        $item['say'] = self::say($item);
        $item['photo_url'] = $item['payload']['photo_url'] ?? (!empty($item['payload']['media_id']) ? self::photoUrl((int)$item['payload']['media_id']) : null);
        $item['receipts'] = array_values(array_unique(array_filter(array_map('intval', array_column($item['lines'], 'expense_id')))));
        return $item;
    }

    public function get(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM product_proposals WHERE id = ?");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->item($r) + ['status' => $r['status'], 'label_capture_id' => $r['label_capture_id'] ? (int)$r['label_capture_id'] : null] : null;
    }

    public function dismiss(int $id, int $userId): array
    {
        $p = $this->get($id);
        if (!$p || $p['status'] !== 'pending') return ['ok' => false, 'message' => 'Already decided.'];
        $this->db->prepare("UPDATE product_proposals SET status = 'dismissed', decided_by = ?, decided_at = ? WHERE id = ?")
             ->execute([$userId ?: null, $this->today . ' ' . date('H:i:s'), $id]);
        if ($p['label_capture_id']) {
            try { $this->db->prepare("UPDATE label_captures SET status = 'dismissed' WHERE id = ?")->execute([$p['label_capture_id']]); } catch (Throwable $e) {}
        }
        return ['ok' => true, 'message' => 'Not now — I won\'t ask about this again.'];
    }

    /**
     * Tim's click. $opts: update_cost (default true), name/sku/unit_cost overrides for a new product.
     */
    public function accept(int $id, int $userId, array $opts = []): array
    {
        $p = $this->get($id);
        if (!$p || $p['status'] !== 'pending') return ['ok' => false, 'message' => 'Already decided.'];
        $pl = $p['payload'];
        $result = ['ok' => false, 'message' => 'Unknown proposal'];
        $this->db->beginTransaction();
        try {
            switch ($p['kind']) {
                case 'product_new':
                    $result = $this->acceptNewProduct($p, $pl, $opts);
                    break;
                case 'product_match':
                    $result = $this->acceptMatch($p, $pl, $opts);
                    break;
                case 'equipment_new':
                    $result = $this->acceptEquipment($pl);
                    if ($result['ok'] && $p['label_capture_id']) $this->finishCapture($p['label_capture_id'], 'equipment', (int)$result['id']);
                    break;
                case 'equipment_match':
                    $sets = []; $vals = [];
                    foreach (['serial_no', 'model', 'make'] as $k) if (!empty($pl['fill'][$k])) { $sets[] = "{$k} = ?"; $vals[] = mb_substr((string)$pl['fill'][$k], 0, 80); }
                    if ($sets) $this->db->prepare("UPDATE equipment SET " . implode(', ', $sets) . " WHERE id = ?")->execute(array_merge($vals, [(int)$pl['equipment_id']]));
                    if ($p['label_capture_id']) $this->finishCapture($p['label_capture_id'], 'equipment', (int)$pl['equipment_id']);
                    $result = ['ok' => true, 'id' => (int)$pl['equipment_id'], 'message' => 'Updated ' . $pl['name'] . '.'];
                    break;
            }
            if (!$result['ok']) { $this->db->rollBack(); return $result; }
            $this->db->prepare("UPDATE product_proposals SET status = 'accepted', decided_by = ?, decided_at = ?, product_id = COALESCE(product_id, ?), equipment_id = COALESCE(equipment_id, ?) WHERE id = ?")
                 ->execute([$userId ?: null, $this->today . ' ' . date('H:i:s'),
                            in_array($p['kind'], ['product_new', 'product_match'], true) ? (int)$result['id'] : null,
                            in_array($p['kind'], ['equipment_new', 'equipment_match'], true) ? (int)$result['id'] : null, $id]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('ProductProposal accept #' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save that: ' . $e->getMessage()];
        }
        // Linking teaches the vendor catalog + SKU memory and moves stock — outside the
        // transaction because ExpenseLineItemService runs its own statements.
        if (in_array($p['kind'], ['product_new', 'product_match'], true)) {
            $linked = $this->linkLines($p['lines'], (int)$result['id']);
            if ($linked) $result['message'] .= ' Linked ' . $linked . ' receipt line' . ($linked === 1 ? '' : 's') . '.';
        }
        $this->productCache = null;
        return $result;
    }

    private function acceptNewProduct(array $p, array $pl, array $opts): array
    {
        $name = trim((string)($opts['name'] ?? $pl['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'message' => 'A product needs a name.'];
        $sku = trim((string)($opts['sku'] ?? $pl['sku'] ?? '')) ?: null;
        if ($sku) {
            $s = $this->db->prepare("SELECT id, name FROM products WHERE sku = ? LIMIT 1");
            $s->execute([$sku]);
            if ($ex = $s->fetch(PDO::FETCH_ASSOC)) {
                // Made by hand meanwhile: treat as "add to that product".
                $pl += ['product_id' => (int)$ex['id'], 'product_name' => (string)$ex['name']];
                $pl['product_id'] = (int)$ex['id']; $pl['product_name'] = (string)$ex['name'];
                return $this->acceptMatch($p, $pl, $opts);
            }
        }
        $cost = isset($opts['unit_cost']) && $opts['unit_cost'] !== '' ? (float)$opts['unit_cost'] : (isset($pl['unit_cost']) ? (float)$pl['unit_cost'] : 0.0);
        $cols = ['name' => mb_substr($name, 0, 255), 'sku' => $sku, 'description' => ($pl['description'] ?? '') !== '' ? $pl['description'] : null,
                 'base_cost' => $cost, 'base_price' => 0.0, 'track_inventory' => !empty($pl['track_inventory']) ? 1 : 0, 'current_stock' => 0,
                 'reorder_point' => 0, 'supplier_info' => $pl['supplier_info'] ?? null, 'active' => 1];
        if (!empty($pl['media_id'])) {
            $cols['image_url'] = self::photoUrl((int)$pl['media_id']);
            if ($this->hasCol('products', 'label_media_id')) $cols['label_media_id'] = (int)$pl['media_id'];
            if ($this->hasCol('products', 'photo_marketing_ok')) $cols['photo_marketing_ok'] = 0;
        }
        if (!empty($pl['care_notes']) && $this->hasCol('products', 'care_notes')) $cols['care_notes'] = $pl['care_notes'];
        if (!empty($pl['safety']) && $this->hasCol('products', 'safety_warnings')) $cols['safety_warnings'] = $pl['safety'];
        foreach (['reorder_point', 'supplier_info', 'track_inventory', 'current_stock', 'active', 'image_url'] as $opt) {
            if (array_key_exists($opt, $cols) && !$this->hasCol('products', $opt)) unset($cols[$opt]);
        }
        $this->db->prepare("INSERT INTO products (" . implode(', ', array_keys($cols)) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")
             ->execute(array_values($cols));
        $pid = (int)$this->db->lastInsertId();
        if ($p['label_capture_id']) $this->finishCapture($p['label_capture_id'], 'product', $pid);
        return ['ok' => true, 'id' => $pid, 'message' => 'Added ' . $name . ' to products.'];
    }

    private function acceptMatch(array $p, array $pl, array $opts): array
    {
        $pid = (int)$pl['product_id'];
        $s = $this->db->prepare("SELECT * FROM products WHERE id = ?");
        $s->execute([$pid]);
        $prod = $s->fetch(PDO::FETCH_ASSOC);
        if (!$prod) return ['ok' => false, 'message' => 'That product is gone.'];
        $sets = []; $vals = []; $did = [];
        if (!empty($pl['media_id'])) {
            if ($this->hasCol('products', 'label_media_id')) { $sets[] = 'label_media_id = ?'; $vals[] = (int)$pl['media_id']; }
            if (trim((string)($prod['image_url'] ?? '')) === '') {
                $sets[] = 'image_url = ?'; $vals[] = self::photoUrl((int)$pl['media_id']);
                if ($this->hasCol('products', 'photo_marketing_ok')) { $sets[] = 'photo_marketing_ok = 0'; }
                $did[] = 'added the photo';
            } else {
                $did[] = 'kept its picture and saved the label photo with it';
            }
        }
        if (!empty($pl['care_notes']) && $this->hasCol('products', 'care_notes') && trim((string)($prod['care_notes'] ?? '')) === '') {
            $sets[] = 'care_notes = ?'; $vals[] = $pl['care_notes']; $did[] = 'care notes from the label';
        }
        if (!empty($pl['cost_change']) && ($opts['update_cost'] ?? true)) {
            $sets[] = 'base_cost = ?'; $vals[] = (float)$pl['cost_change']['new'];
            $did[] = 'cost ' . self::money((float)$pl['cost_change']['old']) . '→' . self::money((float)$pl['cost_change']['new']);
        }
        if ($sets) $this->db->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id = ?")->execute(array_merge($vals, [$pid]));
        if ($p['label_capture_id']) $this->finishCapture($p['label_capture_id'], 'product', $pid);
        return ['ok' => true, 'id' => $pid, 'message' => $prod['name'] . ($did ? ': ' . implode(', ', $did) . '.' : '.')];
    }

    private function acceptEquipment(array $pl): array
    {
        require_once dirname(__DIR__, 2) . '/Operations/Services/EquipmentService.php';
        $r = (new EquipmentService($this->db, $this->today))->save([
            'name' => $pl['name'], 'equipment_class' => $pl['equipment_class'] ?? 'other', 'make' => $pl['make'] ?? '', 'model' => $pl['model'] ?? '',
            'serial_no' => $pl['serial_no'] ?? '', 'power_source' => $pl['power_source'] ?? 'battery',
            'purchase_date' => $pl['purchase_date'] ?? '', 'expense_id' => $pl['expense_id'] ?? '',
            'notes' => !empty($pl['media_id']) ? 'Label photo: media #' . (int)$pl['media_id'] : '',
        ]);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'id' => (int)$r['id'], 'message' => 'Added ' . $pl['name'] . ' to equipment.'];
    }

    /** Link still-unlinked receipt lines to the product (stock + learning via the linker). */
    private function linkLines(array $lines, int $productId): int
    {
        $n = 0;
        $chk = $this->db->prepare("SELECT product_id FROM expense_line_items WHERE id = ?");
        foreach ($lines as $l) {
            if (empty($l['line_item_id'])) continue;
            try {
                $chk->execute([(int)$l['line_item_id']]);
                $cur = $chk->fetchColumn();
                if ($cur === false || ($cur !== null && (int)$cur !== 0)) continue;   // gone, or linked meanwhile
                $this->link((int)$l['line_item_id'], $productId);
                $n++;
            } catch (Throwable $e) {
                error_log('ProductProposal link line ' . $l['line_item_id'] . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    private function link(int $lineId, int $productId): void
    {
        if ($this->linker) { ($this->linker)($lineId, $productId); return; }
        require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseLineItemService.php';
        (new ExpenseLineItemService($this->db))->link($lineId, $productId);
    }

    /** Penny's brief item: "N product proposals waiting" (none when there are none). */
    public function briefItems(): array
    {
        $n = $this->count('penny');
        if ($n <= 0) return [];
        return [['key' => 'penny:product_proposals', 'kind' => 'penny:product_proposals', 'priority' => 3, 'value' => $n, 'since' => $this->today,
                 'text' => $n . ' product proposal' . ($n === 1 ? '' : 's') . ' waiting (new products, label photos, costs from receipts)',
                 'url' => '/crm/dashboard_appstack.php#mw-pp']];
    }

    /** Hook for line-item writers: never breaks the save. */
    public static function afterLineItemsSaved(PDO $db, int $expenseId): void
    {
        try {
            // A real connection only (a mocked PDO in a unit test has no driver).
            if (!is_string($db->getAttribute(PDO::ATTR_DRIVER_NAME))) return;
            $svc = new self($db);
            if ($svc->ready()) $svc->proposeForExpense($expenseId);
        } catch (Throwable $e) {
            error_log('Product proposals for expense #' . $expenseId . ': ' . $e->getMessage());
        }
    }
}
