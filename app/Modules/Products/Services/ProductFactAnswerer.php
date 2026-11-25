<?php
/**
 * ProductFactAnswerer — Ask Charlie about stock, product costs and the kit, from data only.
 *
 * Called first by CharlieFactAnswerer::answer(). Intent is matched with plain rules and every
 * read is a prepared statement; answer() returns null whenever it isn't sure (no product or
 * machine named, two equally good matches…) so Charlie's other answers and Claude still run.
 *
 *   stock        "how much seed do we have", "how many bags of mulch left"     Penny's products
 *   cost         "what does CBM cost us", "price of the lawn seed"               Penny's products
 *   last_bought  "when did we last buy seed"                                     Penny's products
 *   service_due  "what's due for service", "anything need servicing"           Otto's equipment
 *   last_service "when was the EGO mower last serviced"                        Otto's equipment
 *   equipment    "what equipment do we have"                                    Otto's equipment
 *
 * Stock is what the CRM holds: it rises when receipt lines are linked to a product and is never
 * deducted automatically, and the answer says so.
 *
 * No namespace / no autoloader: require_once and `new`.
 */
require_once __DIR__ . '/LabelReaderService.php';
require_once __DIR__ . '/ProductCareService.php';

class ProductFactAnswerer
{
    public const FROM_PRODUCTS = "\n— from Penny's products";
    public const FROM_EQUIPMENT = "\n— from Otto's equipment";
    /** Words that never name a product or machine. */
    public const NOISE = ['how', 'much', 'many', 'what', 'whats', 'when', 'was', 'were', 'did', 'does', 'do', 'have', 'has', 'had', 'got', 'left',
        'stock', 'hand', 'store', 'shop', 'cost', 'costs', 'price', 'pay', 'paid', 'buy', 'bought', 'purchase', 'purchased', 'order', 'ordered',
        'last', 'lately', 'recently', 'time', 'serviced', 'service', 'servicing', 'maintenance', 'due', 'need', 'needs', 'equipment', 'machines',
        'machine', 'kit', 'gear', 'list', 'our', 'the', 'we', 'us', 'any', 'anything', 'there', 'are', 'is', 'in', 'of', 'for', 'bags', 'bag',
        'yards', 'yard', 'units', 'charlie', 'please', 'tell', 'me', 'now', 'currently', 'still', 'get', 'per', 'each', 'one', 'today'];

    private PDO $db;
    private ?string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today;
    }

    public static function intent(string $q): ?string
    {
        $q = mb_strtolower($q);
        if (preg_match('/\bwhat(\'?s| is| needs?)? (is )?(due|needs?) (for )?(a )?(service|servicing|maintenance)\b|\b(due|needs?) (for )?(a )?(service|servicing|maintenance)\b|\bservice due\b|\bneeds? servicing\b/', $q)) return 'service_due';
        if (preg_match('/\bwhen (was|did|were)\b.*\b(serviced|service|sharpened|oil changed|maintained)\b|\blast (serviced|service)\b/', $q)) return 'last_service';
        if (preg_match('/\bwhat (equipment|machines|kit|gear)\b|\b(equipment|machines) do we (have|own)\b|\blist (the |our )?(equipment|machines)\b/', $q)) return 'equipment';
        if (preg_match('/\bwhen did we (last )?(buy|get|order|purchase)\b|\blast (bought|purchased|ordered)\b|\bwhen was .* (bought|purchased|ordered)\b/', $q)) return 'last_bought';
        if (preg_match('/\bhow (much|many)\b.*\b(have|got|left|in stock|on hand)\b|\b(stock|in stock|on hand) (of|for)\b|\bhow much .* stock\b/', $q)) return 'stock';
        if (preg_match('/\bwhat (does|do|did) .* cost\b|\bcost us\b|\bprice of\b|\bhow much (is|are|does|do) .* (cost|costs)\b|\bhow much is (a|an|the)\b/', $q)) return 'cost';
        return null;
    }

    /** Content words of the question (what names the product / machine). */
    public static function subject(string $q): array
    {
        return array_values(array_filter(LabelReaderService::words($q), fn($w) => !in_array($w, self::NOISE, true)));
    }

    /** Products whose name / SKU carries every subject word (plural-tolerant). @return array[] */
    public static function matchProducts(array $subject, array $products, string $question): array
    {
        $tokens = LabelReaderService::tokens($question);
        $hits = [];
        foreach ($products as $p) {
            $sku = strtoupper(trim((string)($p['sku'] ?? '')));
            if ($sku !== '' && strlen($sku) >= 3 && in_array($sku, $tokens, true)) return [$p];
            if (!$subject) continue;
            $words = LabelReaderService::words((string)$p['name'] . ' ' . (string)($p['description'] ?? ''));
            $all = true;
            foreach ($subject as $s) {
                $stem = rtrim($s, 's');
                $found = false;
                foreach ($words as $w) if ($w === $s || rtrim($w, 's') === $stem) { $found = true; break; }
                if (!$found) { $all = false; break; }
            }
            if ($all) $hits[] = $p;
        }
        return $hits;
    }

    public static function matchEquipment(array $subject, array $items): array
    {
        if (!$subject) return [];
        $hits = [];
        foreach ($items as $e) {
            $text = mb_strtolower(implode(' ', [$e['name'] ?? '', $e['make'] ?? '', $e['model'] ?? '', $e['equipment_class'] ?? '', $e['serial_no'] ?? '']));
            $words = LabelReaderService::words($text);
            $ok = true;
            foreach ($subject as $s) if (!in_array($s, $words, true) && !in_array(rtrim($s, 's'), $words, true)) { $ok = false; break; }
            if ($ok) $hits[] = $e;
        }
        return $hits;
    }

    public static function qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** @return array{answer: string, head: string, about: string}|null */
    public function answer(string $question): ?array
    {
        $intent = self::intent($question);
        if ($intent === null) return null;
        try {
            switch ($intent) {
                case 'stock':       return $this->stock($question);
                case 'cost':        return $this->cost($question);
                case 'last_bought': return $this->lastBought($question);
                case 'service_due': return $this->serviceDue();
                case 'last_service':return $this->lastService($question);
                case 'equipment':   return $this->equipmentList();
            }
        } catch (Throwable $e) {
            error_log('Charlie product facts: ' . $e->getMessage());
        }
        return null;
    }

    private function products(): array
    {
        $rows = $this->db->query("SELECT * FROM products")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, fn($r) => (int)($r['active'] ?? ($r['is_active'] ?? 1)) !== 0));
    }

    private function stock(string $q): ?array
    {
        $hits = self::matchProducts(self::subject($q), $this->products(), $q);
        if (!$hits) return null;
        $tracked = array_values(array_filter($hits, fn($p) => (int)($p['track_inventory'] ?? 0) === 1));
        if (!$tracked) {
            return ['answer' => "I don't keep stock for " . (count($hits) === 1 ? $hits[0]['name'] : 'those') . ' — stock tracking is off.' . self::FROM_PRODUCTS,
                    'head' => 'penny', 'about' => (string)$hits[0]['name']];
        }
        $note = ' Stock counts what receipts brought in; nothing is taken off for jobs.';
        if (count($tracked) === 1) {
            $p = $tracked[0];
            $n = (float)$p['current_stock'];
            $unit = ProductProposalService::unitWord((string)$p['name']);
            $unitTxt = $unit === 'each' ? '' : ' ' . $unit . ((abs($n - 1) < 0.001) ? '' : 's');
            return ['answer' => ($n > 0 ? 'We have ' . self::qty($n) . $unitTxt . ' of ' . $p['name'] . ' in stock.' : 'None of ' . $p['name'] . ' left in stock.') . $note . self::FROM_PRODUCTS,
                    'head' => 'penny', 'about' => (string)$p['name']];
        }
        $parts = array_map(fn($p) => $p['name'] . ': ' . self::qty((float)$p['current_stock']), array_slice($tracked, 0, 5));
        return ['answer' => 'In stock — ' . implode('; ', $parts) . '.' . $note . self::FROM_PRODUCTS, 'head' => 'penny', 'about' => implode(', ', array_column($tracked, 'name'))];
    }

    /** Latest receipt line linked to a product. */
    private function lastLine(int $productId): ?array
    {
        $s = $this->db->prepare("SELECT li.quantity, li.unit_price, li.line_total, e.id AS expense_id, e.expense_date, COALESCE(v.name, e.vendor_name_raw) AS vendor
                                 FROM expense_line_items li JOIN expenses e ON e.id = li.expense_id LEFT JOIN vendors v ON v.id = e.vendor_id
                                 WHERE li.product_id = ? ORDER BY e.expense_date DESC, li.id DESC LIMIT 1");
        $s->execute([$productId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function one(string $q): ?array
    {
        $hits = self::matchProducts(self::subject($q), $this->products(), $q);
        if (count($hits) > 1) {
            // "seed" names the seed we buy and the OVER-SEED service: keep the ones bought on receipts.
            $hits = array_values(array_filter($hits, fn($p) => $this->lastLine((int)$p['id']) !== null));
        }
        return count($hits) === 1 ? $hits[0] : null;
    }

    private function cost(string $q): ?array
    {
        $p = $this->one($q);
        if (!$p) return null;
        $last = $this->lastLine((int)$p['id']);
        $cost = (float)($p['base_cost'] ?? 0);
        $unit = ProductProposalService::unitWord((string)$p['name']);
        $s = $cost > 0 ? $p['name'] . ' costs us ' . ProductProposalService::money($cost) . ' per ' . $unit . '.' : 'No cost is set on ' . $p['name'] . '.';
        if ($last) {
            $qty = (float)$last['quantity'] ?: 1.0;
            $unitPrice = $last['unit_price'] !== null ? (float)$last['unit_price'] : (float)$last['line_total'] / $qty;
            $s .= ' Last bought ' . date('M j, Y', strtotime((string)$last['expense_date'])) . ' at ' . ProductProposalService::vendorLabel((string)$last['vendor'])
                . ' for ' . ProductProposalService::money($unitPrice) . ' (receipt #' . (int)$last['expense_id'] . ').';
        }
        return ['answer' => $s . self::FROM_PRODUCTS, 'head' => 'penny', 'about' => (string)$p['name']];
    }

    private function lastBought(string $q): ?array
    {
        $p = $this->one($q);
        if (!$p) return null;
        $last = $this->lastLine((int)$p['id']);
        if (!$last) return ['answer' => 'No receipt is linked to ' . $p['name'] . ' yet.' . self::FROM_PRODUCTS, 'head' => 'penny', 'about' => (string)$p['name']];
        $qty = (float)$last['quantity'] ?: 1.0;
        $unitPrice = $last['unit_price'] !== null ? (float)$last['unit_price'] : (float)$last['line_total'] / $qty;
        return ['answer' => 'Last bought ' . date('M j, Y', strtotime((string)$last['expense_date'])) . ' at ' . ProductProposalService::vendorLabel((string)$last['vendor'])
                          . ': ' . self::qty($qty) . ' × ' . ProductProposalService::money($unitPrice) . ' (receipt #' . (int)$last['expense_id'] . ').' . self::FROM_PRODUCTS,
                'head' => 'penny', 'about' => (string)$p['name']];
    }

    private function serviceDue(): ?array
    {
        $machines = (new ProductCareService($this->db, $this->today))->machines();
        if (!$machines) return null;
        $due = []; $soon = []; $withIntervals = 0;
        foreach ($machines as $m) {
            if ($m['intervals']) $withIntervals++;
            foreach ($m['intervals'] as $i) {
                if ($i['state'] === 'due') $due[] = $m['name'] . ' — ' . mb_strtolower($i['task']);
                elseif ($i['state'] === 'soon') $soon[] = $m['name'] . ' — ' . mb_strtolower($i['task']);
            }
        }
        if ($withIntervals === 0) {
            return ['answer' => 'No service intervals are set yet, so nothing can be due. Add them on the equipment page, or ask Otto to read a manual.' . self::FROM_EQUIPMENT,
                    'head' => 'otto', 'about' => 'service'];
        }
        if (!$due && !$soon) return ['answer' => 'Nothing is due for service.' . self::FROM_EQUIPMENT, 'head' => 'otto', 'about' => 'service'];
        $s = $due ? 'Due for service: ' . implode('; ', $due) . '.' : '';
        if ($soon) $s .= ($s ? ' ' : '') . 'Nearly due: ' . implode('; ', $soon) . '.';
        return ['answer' => $s . ' Hours are from job timers — an estimate.' . self::FROM_EQUIPMENT, 'head' => 'otto', 'about' => 'service'];
    }

    private function equipmentItems(): array
    {
        return $this->db->query("SELECT * FROM equipment WHERE status = 'active' ORDER BY equipment_class, name")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function lastService(string $q): ?array
    {
        $hits = self::matchEquipment(self::subject($q), $this->equipmentItems());
        if (count($hits) !== 1) return null;
        $e = $hits[0];
        $s = $this->db->prepare("SELECT task, done_on FROM equipment_service_log WHERE equipment_id = ? ORDER BY done_on DESC, id DESC LIMIT 3");
        $s->execute([(int)$e['id']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['answer' => 'No service is logged for the ' . $e['name'] . ' yet.' . self::FROM_EQUIPMENT, 'head' => 'otto', 'about' => (string)$e['name']];
        $last = $rows[0]['done_on'];
        $tasks = array_map(fn($r) => mb_strtolower((string)$r['task']), array_filter($rows, fn($r) => $r['done_on'] === $last));
        return ['answer' => 'The ' . $e['name'] . ' was last serviced ' . date('M j, Y', strtotime((string)$last)) . ': ' . implode(', ', $tasks) . '.' . self::FROM_EQUIPMENT,
                'head' => 'otto', 'about' => (string)$e['name']];
    }

    private function equipmentList(): ?array
    {
        $items = $this->equipmentItems();
        if (!$items) return ['answer' => 'No equipment is registered yet.' . self::FROM_EQUIPMENT, 'head' => 'otto', 'about' => 'equipment'];
        $by = [];
        foreach ($items as $e) $by[$e['equipment_class']][] = $e['name'];
        $label = ['mower' => 'mower', 'trimmer' => 'trimmer', 'blower' => 'blower', 'battery_pack' => 'battery pack', 'truck' => 'truck', 'other' => 'other'];
        $parts = [];
        foreach ($by as $cls => $names) {
            $n = count($names);
            $w = $label[$cls] ?? $cls;
            $parts[] = $n . ' ' . ($cls === 'other' ? 'other' : $w . ($n === 1 ? '' : 's')) . ' (' . implode(', ', array_slice($names, 0, 4)) . ($n > 4 ? '…' : '') . ')';
        }
        return ['answer' => count($items) . ' machine' . (count($items) === 1 ? '' : 's') . ': ' . implode('; ', $parts) . '.' . self::FROM_EQUIPMENT,
                'head' => 'otto', 'about' => 'equipment'];
    }
}
