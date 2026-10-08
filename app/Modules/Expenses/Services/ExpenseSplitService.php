<?php
/**
 * ExpenseSplitService — a receipt split by line (Penny backlog item 5, migration 1233).
 *
 * The live case (Tim, 2026-10-07): Lawnboy #412, $218.40 — 2 × Richardson Sun & Shade seed
 * ($120, shop STOCK, PST 7% = $8.40) and 2 × Black Composted Bark Mulch ($80, Oakridge
 * Gardens' job), GST $10 on $200. One job and one category per receipt could not say that.
 *
 * A split receipt has one expense_line_allocations row per line. Each carries its job
 * (job_plans.id), category, For tag (truck / equipment), stock flag, and its own share of
 * the receipt's money:
 *   net  — the line total; when the lines don't add up to the receipt's net (a discount,
 *          a fee read as no line) the difference is spread over the lines by size;
 *   GST  — the receipt's GST spread over the lines by net;
 *   PST  — only on the taxable lines when the receipt shows PST: the owner's tick when he
 *          gave one, else the one set of lines whose net × 7% is the printed PST (seed
 *          $120 × 7% = $8.40, the mulch carries none), else spread over every line.
 * Every split sums to the receipt to the cent (largest remainder).
 *
 * Readers: LedgerService::postExpense (one debit per allocation; meals GST at the GST
 * report's ITC rate, the rest is cost), AccountingService::getJobProfitability and
 * TripAttributionService (job cost = net + PST + GST not claimable — GST is an ITC, so
 * Oakridge carries $80.00 of mulch, not $84), GstReportService (the meals share of a mixed
 * receipt is limited, not the whole receipt).
 *
 * Penny pre-fills a split (propose): a line that matches a material on the job's quote →
 * that job; a product that tracks stock → shop stock; diesel → Fuel for the truck, gas
 * (≤ $50) → Fuel for the equipment (Tim's rules, ReceiptBookkeeperRules); food / drink →
 * Meals; else what she learned from the owner's past splits of that vendor's line; else the
 * receipt's own category and job.
 *
 * Writes go through ExpenseGate (the one door for expense changes) — save()/clear() here are
 * what the gate calls. No namespace / no autoloader in production: require_once and `new`.
 */
class ExpenseSplitService
{
    /** BC PST — used only to find which lines the printed PST was charged on. */
    public const PST_RATE = 0.07;
    /** Words that make a line food or drink (Meals, 50% ITC). Whole words, lower case. */
    public const FOOD_WORDS = ['coffee', 'tea', 'latte', 'sandwich', 'burger', 'wrap', 'sub', 'pizza', 'donut', 'doughnut', 'muffin',
                               'timbits', 'bagel', 'cookie', 'chips', 'snack', 'candy', 'chocolate', 'bar', 'pop', 'soda', 'cola', 'coke',
                               'pepsi', 'water', 'juice', 'drink', 'gatorade', 'powerade', 'monster', 'redbull', 'red bull', 'energy',
                               'bistro', 'meal', 'lunch', 'breakfast', 'hot dog', 'food', 'fries', 'jerky', 'nuts', 'banana'];
    /** Food words that are also landscaping words — only food when a food word is there too. */
    private const NOT_FOOD_ALONE = ['bar', 'water', 'sub', 'nuts', 'energy', 'tea'];

    private PDO $db;
    private ?bool $ready = null;
    private ?array $maps = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        if ($this->ready === null) {
            try {
                $this->db->query("SELECT 1 FROM expense_line_allocations LIMIT 1");
                $this->ready = true;
            } catch (Throwable $e) {
                $this->ready = false;
            }
        }
        return $this->ready;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Spread $amount over $weights to the cent (largest remainder). Keys are kept.
     * @param array<int|string, float> $weights
     * @return array<int|string, float>
     */
    public static function spread(float $amount, array $weights): array
    {
        if (!$weights) return [];
        $cents = (int)round($amount * 100);
        $sumW = array_sum(array_map(function ($w) { return max(0.0, (float)$w); }, $weights));
        $out = []; $rem = []; $given = 0;
        foreach ($weights as $k => $w) {
            $share = $sumW > 0 ? $cents * max(0.0, (float)$w) / $sumW : $cents / count($weights);
            $out[$k] = (int)floor($share);
            $rem[$k] = $share - $out[$k];
            $given += $out[$k];
        }
        $left = $cents - $given;
        arsort($rem);
        foreach (array_keys($rem) as $k) {
            if ($left <= 0) break;
            $out[$k]++;
            $left--;
        }
        return array_map(function ($c) { return round($c / 100, 2); }, $out);
    }

    /**
     * The one set of lines the printed PST was charged on (net × rate = PST within 2¢),
     * or null when no set — or more than one — fits, or when it is every line.
     * @param array<int|string, float> $nets
     * @return list<int|string>|null keys of the taxable lines
     */
    public static function pstSubset(array $nets, float $pst, float $rate = self::PST_RATE): ?array
    {
        $keys = array_keys($nets);
        $n = count($keys);
        if ($pst <= 0 || $n < 2 || $n > 12) return null;
        $tol = max(0.02, 0.006 * $n);
        $found = [];
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $sum = 0.0; $set = [];
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) { $sum += (float)$nets[$keys[$i]]; $set[] = $keys[$i]; }
            }
            if (abs($sum * $rate - $pst) <= $tol) $found[] = $set;
            if (count($found) > 1) return null;
        }
        if (count($found) !== 1 || count($found[0]) === $n) return null;
        return $found[0];
    }

    /**
     * Split a receipt's money over its lines.
     * @param list<array{key: int|string, net: float, pst_taxable?: ?bool}> $lines
     * @return array<int|string, array{net: float, gst: float, pst: float, pst_rule: string}>
     */
    public static function allocate(array $lines, float $total, float $gst, float $pst): array
    {
        if (!$lines) return [];
        $gst = round($gst, 2); $pst = round($pst, 2);
        $receiptNet = round($total - $gst - $pst, 2);
        $raw = [];
        foreach ($lines as $l) $raw[$l['key']] = round((float)$l['net'], 2);
        $sum = round(array_sum($raw), 2);
        // Lines that don't add up to the receipt (a discount, a fee): spread the receipt's net by size.
        $nets = abs($sum - $receiptNet) > 0.005 ? self::spread($receiptNet, $sum > 0 ? $raw : array_fill_keys(array_keys($raw), 1.0)) : $raw;
        $gsts = self::spread($gst, $nets);

        $taxable = null; $rule = 'spread';
        $flags = [];
        foreach ($lines as $l) {
            if (array_key_exists('pst_taxable', $l) && $l['pst_taxable'] !== null) $flags[$l['key']] = (bool)$l['pst_taxable'];
        }
        if ($pst > 0 && $flags && in_array(true, $flags, true)) {
            $taxable = array_keys(array_filter($flags));
            $rule = 'owner';
        } elseif ($pst > 0 && ($sub = self::pstSubset($nets, $pst)) !== null) {
            $taxable = $sub;
            $rule = 'printed';
        }
        $pstWeights = [];
        foreach ($nets as $k => $v) {
            if ($taxable === null || in_array($k, $taxable, true)) $pstWeights[$k] = $v;
        }
        $psts = $pst > 0 ? self::spread($pst, $pstWeights) : [];

        $out = [];
        foreach ($nets as $k => $v) {
            $out[$k] = ['net' => $v, 'gst' => $gsts[$k] ?? 0.0, 'pst' => $psts[$k] ?? 0.0, 'pst_rule' => $pst > 0 ? $rule : 'none'];
        }
        return $out;
    }

    /** GST that can be claimed back on a share: all of it, or the meals rate on a Meals share. */
    public static function claimableGst(float $gst, bool $isMeals, float $mealsRate): float
    {
        return $isMeals ? round($gst * $mealsRate, 2) : round($gst, 2);
    }

    /** What a share costs the job: net + PST + the GST that can't be claimed back. */
    public static function jobCost(float $net, float $gst, float $pst, bool $isMeals = false, float $mealsRate = 0.5): float
    {
        return round($net + $pst + ($gst - self::claimableGst($gst, $isMeals, $mealsRate)), 2);
    }

    public static function isFood(string $name): bool
    {
        $t = ' ' . preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)) . ' ';
        $hits = [];
        foreach (self::FOOD_WORDS as $w) if (strpos($t, ' ' . $w . ' ') !== false) $hits[] = $w;
        if (!$hits) return false;
        return (bool)array_diff($hits, self::NOT_FOOD_ALONE) || count($hits) >= 2;
    }

    /** "2 B. 5K Seed" and "2 b 5k seed" are one line for lessons. */
    public static function lineKey(string $name): string
    {
        $k = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', strtolower($name))));
        $k = preg_replace('/^\d+\s+/', '', $k);   // a leading quantity
        return mb_substr($k, 0, 120);
    }

    /**
     * Penny's choice for one line. Pure.
     * @param array $line   name, line_total, product_id?, track_inventory?
     * @param list<array{plan_id: int, label: string, quote_lines: array}> $jobs candidate jobs (best first)
     * @param ?array $lesson accounting_category, asset_tag, is_stock, to_job, times_seen
     * @param array $header accounting_category, job_id, asset_tag
     * @return array{job_id: ?int, accounting_category: ?string, asset_tag: ?string, is_stock: bool, reason: string, rule: string}
     */
    public static function proposeLine(array $line, array $jobs, ?array $lesson, array $header): array
    {
        $name = (string)($line['name'] ?? '');
        $total = (float)($line['line_total'] ?? 0);
        $pick = function (?int $job, ?string $cat, ?string $tag, bool $stock, string $reason, string $rule): array {
            return ['job_id' => $job, 'accounting_category' => $cat, 'asset_tag' => $tag, 'is_stock' => $stock, 'reason' => $reason, 'rule' => $rule];
        };

        $quoteJob = null;
        if (class_exists('TripAttributionService')) {
            foreach ($jobs as $j) {
                if (TripAttributionService::isJobLine($line, (array)($j['quote_lines'] ?? []))) { $quoteJob = $j; break; }
            }
        }
        // What the owner did with this vendor's line before wins — he corrected Penny once, she remembers.
        if ($lesson && (int)($lesson['times_seen'] ?? 0) >= 1) {
            $why = 'You split this line this way before (' . (int)$lesson['times_seen'] . '×)';
            if (!empty($lesson['to_job'])) {
                $job = $quoteJob ? (int)$quoteJob['plan_id'] : (!empty($header['job_id']) ? (int)$header['job_id'] : null);
                if ($job) return $pick($job, $lesson['accounting_category'] ?? 'Materials', $lesson['asset_tag'] ?? null, false, $why, 'learned');
            } else {
                return $pick(null, $lesson['accounting_category'] ?? ($header['accounting_category'] ?? null), $lesson['asset_tag'] ?? null,
                             !empty($lesson['is_stock']), $why, 'learned');
            }
        }

        if (class_exists('ReceiptBookkeeperRules')) {
            $fuel = ReceiptBookkeeperRules::fuelType(strtoupper($name));
            if ($fuel === 'diesel') return $pick(null, 'Fuel', 'truck', false, 'Diesel is for the Dodge Ram truck', 'fuel_diesel_truck');
            if ($fuel === 'gasoline') {
                return $total <= ReceiptBookkeeperRules::EQUIPMENT_GAS_MAX
                    ? $pick(null, 'Fuel', 'equipment', false, 'Regular gas under $50 is for the equipment', 'fuel_gas_equipment')
                    : $pick(null, 'Fuel', 'equipment', false, 'Gas — usually the equipment; more than a usual fill-up, check', 'fuel_gas_equipment');
            }
        }
        if (self::isFood($name)) return $pick(null, 'Meals', null, false, 'Food or drink — Meals (half the GST is claimable)', 'meals');

        if ($quoteJob) {
            return $pick((int)$quoteJob['plan_id'], 'Materials', null, false, 'On the quote for ' . $quoteJob['label'], 'job_quote');
        }
        if (!empty($line['track_inventory'])) {
            return $pick(null, 'Materials', null, true, 'A product you keep in stock', 'stock_product');
        }
        return $pick(!empty($header['job_id']) ? (int)$header['job_id'] : null, $header['accounting_category'] ?? null,
                     $header['asset_tag'] ?? null, false, 'Same as the receipt', 'receipt');
    }

    /**
     * A "Split by line" payload from Penny's card or the edit modal ({on, lines: [{line_item_id,
     * job_id, accounting_category, asset_tag, is_stock, pst_taxable}]}, or its JSON) as choices
     * for save(): null = not sent / unreadable (leave the split as it is), [] = split off. Pure.
     */
    public static function choices($payload): ?array
    {
        if (is_string($payload)) {
            if (trim($payload) === '') return null;
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) return null;
        if (empty($payload['on']) || $payload['on'] === 'false') return [];
        $out = [];
        foreach ((array)($payload['lines'] ?? []) as $l) {
            if (!is_array($l) || (int)($l['line_item_id'] ?? 0) <= 0) continue;
            $stock = !empty($l['is_stock']) && $l['is_stock'] !== 'false';
            $out[] = [
                'line_item_id'        => (int)$l['line_item_id'],
                'job_id'              => $stock || empty($l['job_id']) ? null : (int)$l['job_id'],
                'accounting_category' => isset($l['accounting_category']) && $l['accounting_category'] !== '' ? (string)$l['accounting_category'] : null,
                'asset_tag'           => isset($l['asset_tag']) && in_array($l['asset_tag'], ['truck', 'equipment'], true) ? $l['asset_tag'] : null,
                'is_stock'            => $stock,
                'pst_taxable'         => array_key_exists('pst_taxable', $l) && $l['pst_taxable'] !== null && $l['pst_taxable'] !== '' ? (bool)$l['pst_taxable'] : null,
                'reason'              => isset($l['reason']) ? mb_substr((string)$l['reason'], 0, 255) : null,
            ];
        }
        return $out;
    }

    /** Do the choices really split the receipt (two or more different destinations)? Pure. */
    public static function isRealSplit(array $choices): bool
    {
        $keys = [];
        foreach ($choices as $c) {
            $keys[implode('|', [(int)($c['job_id'] ?? 0), strtolower((string)($c['accounting_category'] ?? '')),
                                (string)($c['asset_tag'] ?? ''), !empty($c['is_stock']) ? 1 : 0])] = true;
        }
        return count($keys) > 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading
    // ─────────────────────────────────────────────────────────────────────────

    /** expense id => allocation rows (sorted). */
    public function forExpenses(array $expenseIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $expenseIds))));
        if (!$ids || !$this->ready()) return [];
        $s = $this->db->prepare("SELECT * FROM expense_line_allocations WHERE expense_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                                 ORDER BY expense_id, sort_order, id");
        $s->execute($ids);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['expense_id']][] = self::typed($r);
        return $out;
    }

    public function forExpense(int $expenseId): array
    {
        return $this->forExpenses([$expenseId])[$expenseId] ?? [];
    }

    public function hasSplit(int $expenseId): bool
    {
        return (bool)$this->forExpense($expenseId);
    }

    private static function typed(array $r): array
    {
        foreach (['id', 'expense_id', 'sort_order'] as $k) $r[$k] = (int)$r[$k];
        foreach (['line_item_id', 'job_id', 'created_by'] as $k) $r[$k] = isset($r[$k]) && $r[$k] !== null ? (int)$r[$k] : null;
        foreach (['net_amount', 'gst_amount', 'pst_amount'] as $k) $r[$k] = round((float)$r[$k], 2);
        $r['is_stock'] = (int)$r['is_stock'] === 1;
        $r['pst_taxable'] = isset($r['pst_taxable']) && $r['pst_taxable'] !== null ? (int)$r['pst_taxable'] === 1 : null;
        return $r;
    }

    /** The receipt's header + lines (with the product's stock flag). */
    public function receipt(int $expenseId): ?array
    {
        $s = $this->db->prepare("SELECT id, expense_date, vendor_id, vendor_name_raw, total, gst_amount, pst_amount, accounting_category,
                                        job_id, asset_tag, status FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e) return null;
        $e['lines'] = $this->lines($expenseId);
        return $e;
    }

    private function lines(int $expenseId): array
    {
        try {
            $s = $this->db->prepare("SELECT li.id, li.name, li.quantity, li.unit_price, li.line_total, li.product_id, COALESCE(p.track_inventory, 0) AS track_inventory
                                     FROM expense_line_items li LEFT JOIN products p ON p.id = li.product_id
                                     WHERE li.expense_id = ? ORDER BY li.sort_order, li.id");
            $s->execute([$expenseId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Writing — called by ExpenseGate only
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Store a split: one allocation per line, money worked out from the receipt as it is now.
     * @param list<array> $choices line_item_id, job_id?, accounting_category?, asset_tag?, is_stock?, pst_taxable?, source?, reason?
     * @return list<array> the stored allocations
     * @throws InvalidArgumentException when the receipt has no lines or a choice names a line it doesn't have
     */
    public function save(int $expenseId, array $choices, ?int $userId, string $source = 'owner'): array
    {
        $e = $this->receipt($expenseId);
        if (!$e) throw new InvalidArgumentException('Receipt not found');
        $lines = $e['lines'];
        if (count($lines) < 2) throw new InvalidArgumentException('A receipt needs two or more lines to split');
        $byLine = [];
        foreach ($choices as $c) {
            $lid = (int)($c['line_item_id'] ?? 0);
            if ($lid <= 0) continue;
            $byLine[$lid] = $c;
        }
        $known = array_map('intval', array_column($lines, 'id'));
        foreach (array_keys($byLine) as $lid) {
            if (!in_array($lid, $known, true)) throw new InvalidArgumentException('That line is not on this receipt');
        }
        $header = ['accounting_category' => $e['accounting_category'], 'job_id' => $e['job_id'], 'asset_tag' => $e['asset_tag'] ?? null];
        $rows = [];
        foreach ($lines as $i => $l) {
            $c = $byLine[(int)$l['id']] ?? [];
            $stock = !empty($c['is_stock']);
            $rows[] = [
                'line_item_id' => (int)$l['id'], 'label' => (string)$l['name'], 'sort_order' => $i,
                'job_id' => $stock ? null : (array_key_exists('job_id', $c) ? (((int)$c['job_id']) ?: null) : ($header['job_id'] ? (int)$header['job_id'] : null)),
                'accounting_category' => self::blank($c['accounting_category'] ?? null) ?? ($stock ? 'Materials' : $header['accounting_category']),
                'asset_tag' => $stock ? null : self::blank($c['asset_tag'] ?? null),
                'is_stock' => $stock,
                'pst_taxable' => array_key_exists('pst_taxable', $c) && $c['pst_taxable'] !== null && $c['pst_taxable'] !== '' ? (bool)$c['pst_taxable'] : null,
                'net' => (float)$l['line_total'],
                'source' => self::blank($c['source'] ?? null) ?? $source,
                'reason' => self::blank($c['reason'] ?? null),
            ];
        }
        return $this->write($expenseId, $e, $rows, $userId);
    }

    /**
     * The receipt's lines or taxes changed: keep the owner's choices (by line id, then by
     * name) and work the money out again. A line no longer there drops; a new line takes
     * the receipt's own category and job. Fewer than two lines left ends the split.
     */
    public function rebalance(int $expenseId, ?int $userId = null): array
    {
        $old = $this->forExpense($expenseId);
        if (!$old) return [];
        $e = $this->receipt($expenseId);
        if (!$e || count($e['lines']) < 2) { $this->clear($expenseId); return []; }
        $byId = []; $byName = [];
        foreach ($old as $a) {
            if ($a['line_item_id']) $byId[$a['line_item_id']] = $a;
            $byName[strtolower(trim($a['label']))][] = $a;
        }
        $choices = [];
        foreach ($e['lines'] as $l) {
            $a = $byId[(int)$l['id']] ?? null;
            if (!$a && !empty($byName[strtolower(trim((string)$l['name']))])) $a = array_shift($byName[strtolower(trim((string)$l['name']))]);
            if (!$a) continue;
            $choices[] = ['line_item_id' => (int)$l['id'], 'job_id' => $a['job_id'], 'accounting_category' => $a['accounting_category'],
                          'asset_tag' => $a['asset_tag'], 'is_stock' => $a['is_stock'], 'pst_taxable' => $a['pst_taxable'],
                          'source' => $a['source'], 'reason' => $a['reason']];
        }
        return $this->save($expenseId, $choices, $userId);
    }

    public function clear(int $expenseId): int
    {
        if (!$this->ready()) return 0;
        $d = $this->db->prepare("DELETE FROM expense_line_allocations WHERE expense_id = ?");
        $d->execute([$expenseId]);
        return $d->rowCount();
    }

    private function write(int $expenseId, array $e, array $rows, ?int $userId): array
    {
        $money = self::allocate(array_map(function ($r, $i) {
            return ['key' => $i, 'net' => $r['net'], 'pst_taxable' => $r['pst_taxable']];
        }, $rows, array_keys($rows)), (float)$e['total'], (float)$e['gst_amount'], (float)$e['pst_amount']);
        $now = date('Y-m-d H:i:s');
        $this->clear($expenseId);
        $ins = $this->db->prepare("INSERT INTO expense_line_allocations
            (expense_id, line_item_id, label, sort_order, job_id, accounting_category, asset_tag, is_stock, pst_taxable,
             net_amount, gst_amount, pst_amount, source, reason, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($rows as $i => $r) {
            $m = $money[$i];
            $ins->execute([$expenseId, $r['line_item_id'], mb_substr($r['label'], 0, 255), $r['sort_order'], $r['job_id'],
                           $r['accounting_category'], $r['asset_tag'], $r['is_stock'] ? 1 : 0,
                           $r['pst_taxable'] === null ? null : ($r['pst_taxable'] ? 1 : 0),
                           $m['net'], $m['gst'], $m['pst'], mb_substr($r['source'], 0, 20),
                           $r['reason'] !== null ? mb_substr($r['reason'], 0, 255) : null, $userId, $now, $now]);
        }
        return $this->forExpense($expenseId);
    }

    private static function blank($v): ?string
    {
        if ($v === null) return null;
        $t = trim((string)$v);
        return $t === '' || $t === 'none' ? null : $t;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Penny's pre-fill
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Penny's split for a receipt.
     * @param ?int $suggestedJob the job Penny's suggestion carries, when it differs from the receipt's
     * @return array{lines: list<array>, suggest: bool, jobs: list<array{id: int, label: string}>, current: list<array>}
     */
    public function propose(int $expenseId, ?int $suggestedJob = null): array
    {
        $e = $this->receipt($expenseId);
        if (!$e) return ['lines' => [], 'suggest' => false, 'jobs' => [], 'current' => []];
        $jobs = $this->candidateJobs($e, $suggestedJob);
        $lessons = $this->lessons((int)($e['vendor_id'] ?? 0), array_column($e['lines'], 'name'));
        $header = ['accounting_category' => $e['accounting_category'], 'job_id' => $e['job_id'] ?: $suggestedJob, 'asset_tag' => $e['asset_tag'] ?? null];
        $out = [];
        foreach ($e['lines'] as $l) {
            $p = self::proposeLine($l, $jobs, $lessons[self::lineKey((string)$l['name'])] ?? null, $header);
            $out[] = $p + ['line_item_id' => (int)$l['id'], 'name' => $l['name'], 'line_total' => round((float)$l['line_total'], 2)];
        }
        $preview = $out ? self::allocate(array_map(function ($p, $i) { return ['key' => $i, 'net' => $p['line_total']]; }, $out, array_keys($out)),
                                         (float)$e['total'], (float)$e['gst_amount'], (float)$e['pst_amount']) : [];
        foreach ($out as $i => &$p) {
            $p['gst'] = $preview[$i]['gst'] ?? 0.0;
            $p['pst'] = $preview[$i]['pst'] ?? 0.0;
            $p['net'] = $preview[$i]['net'] ?? $p['line_total'];
        }
        unset($p);
        return [
            'lines'   => $out,
            'suggest' => count($out) >= 2 && self::isRealSplit($out),
            'jobs'    => array_map(function ($j) { return ['id' => (int)$j['plan_id'], 'label' => $j['label']]; }, $jobs),
            'current' => $this->forExpense($expenseId),
        ];
    }

    /** Jobs a line could be for: the receipt's, Penny's, the trip it was bought on, that day's visits. Best first. */
    public function candidateJobs(array $e, ?int $suggestedJob = null): array
    {
        $ids = [];
        foreach ([(int)($e['job_id'] ?? 0), (int)$suggestedJob] as $id) if ($id > 0) $ids[$id] = true;
        $date = substr((string)$e['expense_date'], 0, 10);
        try {
            $r = $this->db->prepare("SELECT from_property_id, return_property_id, receipt_ids FROM ops_trip_runs WHERE run_date = ?");
            $r->execute([$date]);
            foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $run) {
                if (!in_array((int)$e['id'], array_map('intval', explode(',', (string)$run['receipt_ids'])), true)) continue;
                $prop = (int)($run['from_property_id'] ?: $run['return_property_id']);
                if ($prop && ($pid = $this->planAt($prop, $date))) $ids[$pid] = true;
            }
        } catch (Throwable $ex) { /* no trip runs (migration 1216) */ }
        try {
            $v = $this->db->prepare("SELECT DISTINCT jv.plan_id FROM job_visits jv WHERE jv.scheduled_date = ? AND jv.status NOT IN ('cancelled', 'skipped') LIMIT 12");
            $v->execute([$date]);
            foreach ($v->fetchAll(PDO::FETCH_COLUMN) as $pid) $ids[(int)$pid] = true;
        } catch (Throwable $ex) { /* no visits table */ }
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $s = $this->db->prepare("SELECT jp.id, jp.quote_id, jp.title, jp.plan_number, p.property_name, p.address
                                     FROM job_plans jp LEFT JOIN properties p ON p.id = jp.property_id WHERE jp.id IN ({$in})");
            $s->execute(array_keys($ids));
            $rows = array_column($s->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        } catch (Throwable $ex) {
            return [];
        }
        $trip = class_exists('TripAttributionService') ? new TripAttributionService($this->db) : null;
        $out = [];
        foreach (array_keys($ids) as $id) {
            if (!isset($rows[$id])) continue;
            $r = $rows[$id];
            $name = trim((string)($r['property_name'] ?: $r['address'] ?: $r['title'] ?: ('Job #' . $id)));
            $out[] = ['plan_id' => (int)$id, 'label' => $name . ($r['plan_number'] ? ' (' . $r['plan_number'] . ')' : ''),
                      'quote_lines' => $trip && $r['quote_id'] ? $trip->quoteLines((int)$r['quote_id']) : []];
        }
        return $out;
    }

    private function planAt(int $propertyId, string $date): ?int
    {
        $s = $this->db->prepare("SELECT jv.plan_id FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                                 WHERE jp.property_id = ? AND jv.scheduled_date = ? AND jv.status NOT IN ('cancelled', 'skipped') ORDER BY jv.id LIMIT 1");
        $s->execute([$propertyId, $date]);
        $id = $s->fetchColumn();
        return $id ? (int)$id : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Learning — what the owner's split teaches Penny
    // ─────────────────────────────────────────────────────────────────────────

    /** line key => lesson row, for this vendor. */
    public function lessons(int $vendorId, array $names): array
    {
        $keys = array_values(array_unique(array_filter(array_map(function ($n) { return self::lineKey((string)$n); }, $names))));
        if (!$keys || $vendorId <= 0) return [];
        try {
            $s = $this->db->prepare("SELECT * FROM expense_split_lessons WHERE vendor_id = ? AND line_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")");
            $s->execute(array_merge([$vendorId], $keys));
            return array_column($s->fetchAll(PDO::FETCH_ASSOC), null, 'line_key');
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Remember the owner's split, line by line, for this vendor. @return int lessons written */
    public function learn(int $expenseId, array $allocations): int
    {
        $s = $this->db->prepare("SELECT vendor_id FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $vendorId = (int)$s->fetchColumn();
        if ($vendorId <= 0) return 0;
        $n = 0;
        try {
            $find = $this->db->prepare("SELECT id, accounting_category, asset_tag, is_stock, to_job, times_seen FROM expense_split_lessons WHERE vendor_id = ? AND line_key = ?");
            $up = $this->db->prepare("UPDATE expense_split_lessons SET accounting_category = ?, asset_tag = ?, is_stock = ?, to_job = ?, times_seen = ?,
                                             last_expense_id = ?, updated_at = ? WHERE id = ?");
            $ins = $this->db->prepare("INSERT INTO expense_split_lessons (vendor_id, line_key, accounting_category, asset_tag, is_stock, to_job, times_seen, last_expense_id, updated_at)
                                       VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)");
            foreach ($allocations as $a) {
                $key = self::lineKey((string)($a['label'] ?? ''));
                if ($key === '') continue;
                $find->execute([$vendorId, $key]);
                $old = $find->fetch(PDO::FETCH_ASSOC);
                $vals = [$a['accounting_category'] ?? null, $a['asset_tag'] ?? null, !empty($a['is_stock']) ? 1 : 0, !empty($a['job_id']) ? 1 : 0];
                if ($old) {
                    $same = (string)$old['accounting_category'] === (string)$vals[0] && (string)$old['asset_tag'] === (string)$vals[1]
                         && (int)$old['is_stock'] === $vals[2] && (int)$old['to_job'] === $vals[3];
                    // The same choice again strengthens it; a different one starts over at 1.
                    $up->execute(array_merge($vals, [$same ? (int)$old['times_seen'] + 1 : 1, $expenseId, date('Y-m-d H:i:s'), (int)$old['id']]));
                } else {
                    $ins->execute(array_merge([$vendorId, $key], $vals, [$expenseId, date('Y-m-d H:i:s')]));
                }
                $n++;
            }
        } catch (Throwable $e) {
            error_log('Split lessons for expense ' . $expenseId . ': ' . $e->getMessage());
        }
        return $n;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // For the books
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LedgerService::buildExpenseEntry 'allocations' for a split receipt, or null when it isn't split.
     * @return ?array{allocations: list<array>, meals_itc_rate: float}
     */
    public function ledgerArgs(int $expenseId, ?string $vendorName = null): ?array
    {
        $rows = $this->forExpense($expenseId);
        if (!$rows) return null;
        [$codes, $costTypes] = $this->codeMaps();
        [$mealsCats, $rate] = $this->mealsSettings();
        $out = [];
        foreach ($rows as $a) {
            $cat = strtolower(trim((string)$a['accounting_category']));
            $code = $codes[$cat] ?? '6900';
            if (class_exists('LedgerAccountMap')) $code = LedgerAccountMap::refineExpenseCode($code, $a['accounting_category'], $a['asset_tag'], $vendorName);
            $out[] = [
                'account' => $code, 'net' => $a['net_amount'], 'gst' => $a['gst_amount'], 'pst' => $a['pst_amount'],
                'job_id' => $a['job_id'], 'cost_type_id' => $costTypes[$cat] ?? null, 'label' => $a['label'],
                'meals' => self::isMealsCategory($a['accounting_category'], $mealsCats),
            ];
        }
        return ['allocations' => $out, 'meals_itc_rate' => $rate];
    }

    public static function isMealsCategory(?string $category, array $mealsCategories): bool
    {
        return class_exists('GstReportService')
            ? GstReportService::isMeals($category, $mealsCategories)
            : strcasecmp(trim((string)$category), 'Meals') === 0;
    }

    /** [meals categories, meals ITC rate] from the GST report settings. */
    public function mealsSettings(): array
    {
        if (!class_exists('GstReportService') && defined('APP_ROOT') && is_file(APP_ROOT . '/Modules/Accounting/Services/GstReportService.php')) {
            require_once APP_ROOT . '/Modules/Accounting/Services/GstReportService.php';
        }
        try {
            $s = (new GstReportService($this->db))->settings();
            return [$s['meals_categories'], (float)$s['meals_rate']];
        } catch (Throwable $e) {
            return [['Meals'], 0.5];
        }
    }

    /** [category(lower) => chart code, category(lower) => cost_types.id] — the maps the nightly sync uses. */
    private function codeMaps(): array
    {
        if ($this->maps !== null) return $this->maps;
        $codes = [];
        try {
            foreach ($this->db->query("SELECT code, expense_category_alias FROM chart_of_accounts WHERE expense_category_alias IS NOT NULL AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $codes[strtolower($r['expense_category_alias'])] = $r['code'];
            }
        } catch (Throwable $e) { /* no chart */ }
        if (class_exists('LedgerAccountMap')) $codes = (new LedgerAccountMap($this->db))->categoryCodes() + $codes;
        $costs = [];
        try {
            $ids = [];
            foreach ($this->db->query("SELECT id, name FROM cost_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) $ids[strtolower($r['name'])] = (int)$r['id'];
            $alias = ['materials' => 'materials', 'fuel' => 'fuel', 'tools/equipment' => 'equipment', 'subcontractors' => 'subcontractor', 'labour' => 'labour'];
            foreach ($alias as $cat => $name) if (isset($ids[$name])) $costs[$cat] = $ids[$name];
        } catch (Throwable $e) { /* no cost types */ }
        return $this->maps = [$codes, $costs];
    }

    /**
     * Job cost per job from split receipts (approved / posted), for job profitability.
     * @return array<int, float> plan id => cost
     */
    public function jobCosts(array $planIds, string $from, string $to): array
    {
        $planIds = array_values(array_unique(array_filter(array_map('intval', $planIds))));
        if (!$planIds || !$this->ready()) return [];
        [$mealsCats, $rate] = $this->mealsSettings();
        $s = $this->db->prepare("SELECT a.job_id, a.net_amount, a.gst_amount, a.pst_amount, a.accounting_category
                                 FROM expense_line_allocations a JOIN expenses e ON e.id = a.expense_id
                                 WHERE a.job_id IN (" . implode(',', array_fill(0, count($planIds), '?')) . ")
                                   AND e.status IN ('approved', 'forwarded') AND e.expense_date BETWEEN ? AND ?");
        $s->execute(array_merge($planIds, [$from, $to]));
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['job_id']] = round(($out[(int)$r['job_id']] ?? 0) + self::jobCost((float)$r['net_amount'], (float)$r['gst_amount'],
                (float)$r['pst_amount'], self::isMealsCategory($r['accounting_category'], $mealsCats), $rate), 2);
        }
        return $out;
    }

    /** Split receipts' gross per job as the legacy transaction rows carry it (to take back out). */
    public function splitExpenseIds(array $expenseIds): array
    {
        return array_keys($this->forExpenses($expenseIds));
    }
}
