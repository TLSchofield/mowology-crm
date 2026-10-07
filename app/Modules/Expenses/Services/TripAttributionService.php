<?php
/**
 * TripAttributionService — Penny tags Otto's dump / supply runs to the job that caused them.
 *
 * Each ops_trip_runs row (one overhead stop) started at a client property (from_property_id, or
 * the property the truck returned to). The job is the job_plans visit at that property that day.
 * Penny writes ops_trip_job_costs (migration 1218) — an additive record, never a journal entry:
 *
 *   trip          the run's own time + km (labour_cost + truck_cost)
 *   receipt       a whole receipt with a single purpose (a dump fee; a supplier receipt whose every
 *                 line is a material on that job's quote)
 *   receipt_line  one line of a MIXED supplier receipt: job lines (match a material on the job's
 *                 quote) go to the job; everything else is shop stock, job-less (is_stock = 1)
 *
 * Rules (Tim, 2026-10-07 — the Lawn Boy pickup carried Oakridge's mulch AND shop grass seed):
 *  - Never attribute a whole supplier receipt to a job unless it has a single purpose.
 *    expenses.job_id is set ONLY on single-purpose receipts and ONLY when it is NULL — never
 *    overwritten. Mixed receipts stay job-less on the expense; the job share lives here, per line.
 *  - A supplier receipt with no line items can't be split: it is left alone (not tagged).
 *  - The trip's own cost goes to the job when at least one job line was bought (or, when no
 *    receipt has line items, when the job's quote has a material on it). Otherwise it is a stock run.
 *  - Stock stays on 5200 Materials & Supplies with no job — no inventory account is created.
 *
 * Readers: AccountingService job profitability (trip_overhead, via overheadForJobs) and Charlie's
 * brief (briefItems: a job whose runs cost more than its quote allowed for trips).
 * No namespace / no autoloader in production: require_once and `new`.
 */
class TripAttributionService
{
    /** Words that make a receipt line or a quote line a material. Substring match, lower case. */
    public const MATERIAL_WORDS = ['mulch', 'soil', 'compost', 'bark', 'gravel', 'sand', 'rock', 'stone', 'sod',
                                   'seed', 'fertili', 'lime', 'garden mix', 'wood chip', 'peat', 'manure'];
    /** Quote lines that pay for trips (what Sam suggests). */
    public const TRIP_LINE_WORDS = ['material pickup', 'disposal run'];
    public const KINDS = ['dump', 'supplier'];

    private PDO $db;
    private ?string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM ops_trip_job_costs LIMIT 1");
            $this->db->query("SELECT 1 FROM ops_trip_runs LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure rules (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Material words in a piece of text. */
    public static function materialWords(string $text): array
    {
        $t = strtolower($text);
        return array_values(array_filter(self::MATERIAL_WORDS, fn($w) => strpos($t, $w) !== false));
    }

    public static function quoteLabel(array $q): string
    {
        return trim((string)($q['service_type'] ?? '') . ' ' . (string)($q['description'] ?? '') . ' ' . (string)($q['product_name'] ?? ''));
    }

    /** Does the quote carry a material at all? */
    public static function quoteHasMaterial(array $quoteLines): bool
    {
        foreach ($quoteLines as $q) {
            if (self::isTripLine($q)) continue;
            if (self::materialWords(self::quoteLabel($q))) return true;
        }
        return false;
    }

    public static function isTripLine(array $q): bool
    {
        $t = strtolower(self::quoteLabel($q));
        foreach (self::TRIP_LINE_WORDS as $w) if (strpos($t, $w) !== false) return true;
        return false;
    }

    /** What the quote allowed for trips: the sum of its Material pickup / Disposal run lines. */
    public static function allowance(array $quoteLines): float
    {
        $sum = 0.0;
        foreach ($quoteLines as $q) {
            if (!self::isTripLine($q)) continue;
            $sum += isset($q['line_total']) ? (float)$q['line_total'] : (float)($q['quantity'] ?? 1) * (float)($q['unit_price'] ?? 0);
        }
        return round($sum, 2);
    }

    /** Is this receipt line for the job? Same product as a quote line, or a material word the quote also has. */
    public static function isJobLine(array $line, array $quoteLines): bool
    {
        $pid = (int)($line['product_id'] ?? 0);
        $words = self::materialWords((string)($line['name'] ?? ''));
        foreach ($quoteLines as $q) {
            if (self::isTripLine($q)) continue;
            if ($pid > 0 && $pid === (int)($q['product_id'] ?? 0)) return true;
            if ($words && array_intersect($words, self::materialWords(self::quoteLabel($q)))) return true;
        }
        return false;
    }

    /** @return array{job: list<array>, stock: list<array>} */
    public static function splitReceipt(array $lines, array $quoteLines): array
    {
        $out = ['job' => [], 'stock' => []];
        foreach ($lines as $l) $out[self::isJobLine($l, $quoteLines) ? 'job' : 'stock'][] = $l;
        return $out;
    }

    /**
     * Attribute one run (leg) — pure.
     * @param array $run      ops_trip_runs row: id, run_date, trip_key, kind, labour_cost, truck_cost, property_id
     * @param ?array $job     ['plan_id', 'visit_id', 'quote_lines' => list] or null when no job that day
     * @param list<array> $receipts [id, total, job_id, lines => list<[id, product_id, name, line_total]>]
     * @return array{rows: list<array>, tag: array<int,int> expense_id => plan_id, trip_to_job: bool, stock: bool, assumed: bool}
     */
    public static function attributeRun(array $run, ?array $job, array $receipts): array
    {
        $plan = $job ? (int)$job['plan_id'] : null;
        $visit = $job && !empty($job['visit_id']) ? (int)$job['visit_id'] : null;
        $quote = $job['quote_lines'] ?? [];
        $base = ['run_id' => (int)$run['id'], 'run_date' => (string)$run['run_date'], 'trip_key' => $run['trip_key'] ?? null,
                 'kind' => (string)$run['kind'], 'property_id' => $run['property_id'] ?? null];
        $row = function (string $source, ?int $planId, float $amount, string $label, array $extra = []) use ($base, $visit): array {
            return array_merge($base, ['job_plan_id' => $planId, 'visit_id' => $planId ? $visit : null, 'source' => $source,
                            'expense_id' => null, 'expense_line_id' => null, 'amount' => round($amount, 2), 'is_stock' => 0, 'label' => $label], $extra);
        };
        $rows = []; $tag = []; $stock = false; $assumed = false;
        $tripCost = (float)($run['labour_cost'] ?? 0) + (float)($run['truck_cost'] ?? 0);

        if ($run['kind'] === 'dump') {
            // A dump fee has one purpose: the job's waste.
            foreach ($receipts as $r) {
                $rows[] = $row('receipt', $plan, (float)$r['total'], 'Dump fee', ['expense_id' => (int)$r['id']]);
                if ($plan && empty($r['job_id'])) $tag[(int)$r['id']] = $plan;
            }
            $rows[] = $row('trip', $plan, $tripCost, 'Dump run — time + km');
            return ['rows' => $rows, 'tag' => $tag, 'trip_to_job' => $plan !== null, 'stock' => false, 'assumed' => false];
        }

        // Supplier: split each receipt into job lines and shop stock.
        $jobLines = 0; $anyLines = false;
        foreach ($receipts as $r) {
            $lines = $r['lines'] ?? [];
            if (!$lines) {
                $rows[] = $row('receipt', null, (float)$r['total'], 'Receipt has no line items — not tagged to a job', ['expense_id' => (int)$r['id']]);
                continue;
            }
            $anyLines = true;
            $split = $plan ? self::splitReceipt($lines, $quote) : ['job' => [], 'stock' => $lines];
            $jobLines += count($split['job']);
            if ($split['stock']) $stock = true;
            if ($split['job'] && !$split['stock']) {
                // Single purpose — the whole receipt is the job's.
                $rows[] = $row('receipt', $plan, (float)$r['total'], 'Job materials', ['expense_id' => (int)$r['id']]);
                if (empty($r['job_id'])) $tag[(int)$r['id']] = $plan;
                continue;
            }
            foreach ($split['job'] as $l) {
                $rows[] = $row('receipt_line', $plan, (float)$l['line_total'], (string)$l['name'], ['expense_id' => (int)$r['id'], 'expense_line_id' => (int)$l['id']]);
            }
            foreach ($split['stock'] as $l) {
                $rows[] = $row('receipt_line', null, (float)$l['line_total'], (string)$l['name'] . ' — shop stock',
                               ['expense_id' => (int)$r['id'], 'expense_line_id' => (int)$l['id'], 'is_stock' => 1]);
            }
        }
        $toJob = $plan !== null && ($jobLines > 0 || (!$anyLines && self::quoteHasMaterial($quote)));
        $assumed = $toJob && $jobLines === 0;
        if ($toJob) {
            $rows[] = $row('trip', $plan, $tripCost, 'Supply run — time + km' . ($assumed ? ' (no line items; quote has materials)' : ''));
        } else {
            $rows[] = $row('trip', null, $tripCost, $plan ? 'Supply run for shop stock — time + km' : 'Supply run, no job that day — time + km',
                           ['is_stock' => $plan || $stock ? 1 : 0]);
        }
        return ['rows' => $rows, 'tag' => $tag, 'trip_to_job' => $toJob, 'stock' => $stock, 'assumed' => $assumed];
    }

    /**
     * Overhead per job from attribution rows: the runs' time + km plus single-purpose dump fees.
     * Job materials (mulch) are billed on the quote, so they are not overhead.
     * @return array<int, array{plan_id: int, overhead: float, runs: int, stock: bool, date: string}>
     */
    public static function overheadByJob(array $rows): array
    {
        $out = []; $runs = []; $stockRuns = [];
        foreach ($rows as $r) {
            if (!empty($r['is_stock'])) $stockRuns[(int)$r['run_id']] = true;
        }
        foreach ($rows as $r) {
            $p = $r['job_plan_id'] ?? null;
            if (!$p) continue;
            $p = (int)$p;
            $out[$p] = $out[$p] ?? ['plan_id' => $p, 'overhead' => 0.0, 'runs' => 0, 'stock' => false, 'date' => (string)$r['run_date']];
            if ($r['source'] === 'trip' || ($r['source'] === 'receipt' && $r['kind'] === 'dump')) $out[$p]['overhead'] += (float)$r['amount'];
            if ($r['source'] === 'trip') {
                $runs[$p][(int)$r['run_id']] = true;
                if (!empty($stockRuns[(int)$r['run_id']])) $out[$p]['stock'] = true;
            }
        }
        foreach ($out as $p => &$o) {
            $o['overhead'] = round($o['overhead'], 2);
            $o['runs'] = count($runs[$p] ?? []);
        }
        unset($o);
        return $out;
    }

    /** "Oakridge Gardens needed 2 runs today ($76), not in the quote — also picked up shop stock." */
    public static function overLine(string $name, int $runs, float $overhead, float $allowance, string $when, bool $stock): string
    {
        $s = $name . ' needed ' . $runs . ' run' . ($runs === 1 ? '' : 's') . ' ' . $when . ' ($' . number_format($overhead, 0) . '), ';
        $s .= $allowance > 0 ? 'the quote allowed $' . number_format($allowance, 0) : 'not in the quote';
        return $s . ($stock ? ' — also picked up shop stock.' : '.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading the database
    // ─────────────────────────────────────────────────────────────────────────

    public function runs(string $date): array
    {
        $s = $this->db->prepare("
            SELECT id, run_date, trip_key, kind, place_id, from_property_id, return_property_id, labour_cost, truck_cost, receipt_ids
            FROM ops_trip_runs WHERE run_date = ? AND kind IN ('dump', 'supplier')
            ORDER BY arrived_at, id
        ");
        $s->execute([$date]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The job visited at a property on a date: plan, visit, name, quote lines. */
    public function jobFor(int $propertyId, string $date): ?array
    {
        $s = $this->db->prepare("
            SELECT jp.id AS plan_id, jv.id AS visit_id, jp.quote_id, COALESCE(p.property_name, p.address, '') AS name
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            LEFT JOIN properties p ON p.id = jp.property_id
            WHERE jp.property_id = ? AND jv.scheduled_date = ? AND jv.status NOT IN ('cancelled', 'skipped')
            ORDER BY CASE jv.status WHEN 'completed' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END, jv.id
            LIMIT 1
        ");
        $s->execute([$propertyId, $date]);
        $j = $s->fetch(PDO::FETCH_ASSOC);
        if (!$j) return null;
        $j['quote_lines'] = $j['quote_id'] ? $this->quoteLines((int)$j['quote_id']) : [];
        return $j;
    }

    public function quoteLines(int $quoteId): array
    {
        try {
            $s = $this->db->prepare("
                SELECT q.product_id, q.service_type, q.description, q.quantity, q.unit_price, q.line_total, COALESCE(pr.name, '') AS product_name
                FROM quote_line_items q LEFT JOIN products pr ON pr.id = q.product_id
                WHERE q.quote_id = ?
            ");
            $s->execute([$quoteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Receipts by id, each with its line items. Rejected receipts are skipped. */
    public function receipts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT id, total, job_id FROM expenses WHERE id IN ({$in}) AND status <> 'rejected' ORDER BY id");
        $s->execute($ids);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r + ['lines' => []];
        if (!$out) return [];
        try {
            $l = $this->db->prepare("SELECT id, expense_id, product_id, name, line_total FROM expense_line_items WHERE expense_id IN ({$in}) ORDER BY expense_id, sort_order, id");
            $l->execute($ids);
            foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $line) {
                if (isset($out[(int)$line['expense_id']])) $out[(int)$line['expense_id']]['lines'][] = $line;
            }
        } catch (Throwable $e) { /* no line items table — receipts can't be split */ }
        return array_values($out);
    }

    /**
     * A date's attribution, computed (no writes).
     * @return array{rows: list<array>, tag: array<int,int>, jobs: array<int, array{name: string, allowance: float}>}
     */
    public function build(string $date): array
    {
        $rows = []; $tag = []; $jobs = []; $cache = [];
        foreach ($this->runs($date) as $run) {
            $prop = (int)($run['from_property_id'] ?: $run['return_property_id']);
            $run['property_id'] = $prop ?: null;
            if ($prop && !array_key_exists($prop, $cache)) $cache[$prop] = $this->jobFor($prop, $date);
            $job = $prop ? $cache[$prop] : null;
            if ($job) $jobs[(int)$job['plan_id']] = ['name' => (string)$job['name'], 'allowance' => self::allowance($job['quote_lines'])];
            $receipts = $run['receipt_ids'] ? $this->receipts(explode(',', (string)$run['receipt_ids'])) : [];
            $res = self::attributeRun($run, $job, $receipts);
            foreach ($res['rows'] as $r) $rows[] = $r;
            $tag += $res['tag'];
        }
        return ['rows' => $rows, 'tag' => $tag, 'jobs' => $jobs];
    }

    /**
     * Store a date's attribution (rebuilt each time) and tag single-purpose receipts whose job_id is NULL.
     * Called by the trip_runs_daily cron after the runs are priced.
     * @return array{rows: int, tagged: int}
     */
    public function attribute(string $date): array
    {
        $b = $this->build($date);
        $this->db->prepare("DELETE FROM ops_trip_job_costs WHERE run_date = ?")->execute([$date]);
        $ins = $this->db->prepare("
            INSERT INTO ops_trip_job_costs (run_id, run_date, trip_key, kind, property_id, job_plan_id, visit_id, source,
                                            expense_id, expense_line_id, amount, is_stock, label)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($b['rows'] as $r) {
            $ins->execute([$r['run_id'], $r['run_date'], $r['trip_key'], $r['kind'], $r['property_id'], $r['job_plan_id'], $r['visit_id'],
                           $r['source'], $r['expense_id'], $r['expense_line_id'], $r['amount'], $r['is_stock'], mb_substr((string)$r['label'], 0, 255)]);
        }
        $tagged = 0;
        $up = $this->db->prepare("UPDATE expenses SET job_id = ? WHERE id = ? AND job_id IS NULL");
        foreach ($b['tag'] as $expenseId => $planId) {
            $up->execute([$planId, $expenseId]);
            $tagged += $up->rowCount();
        }
        return ['rows' => count($b['rows']), 'tagged' => $tagged];
    }

    /**
     * Trip overhead per job (time + km of the runs attributed to it), for job profitability.
     * Receipts are left out: they reach the job through expenses / the ledger as before.
     * @return array<int, array{trip_overhead: float, trip_runs: int}>
     */
    public function overheadForJobs(array $planIds): array
    {
        $planIds = array_values(array_unique(array_filter(array_map('intval', $planIds))));
        if (!$planIds) return [];
        try {
            $s = $this->db->prepare("
                SELECT job_plan_id, SUM(amount) AS overhead, COUNT(DISTINCT run_id) AS runs
                FROM ops_trip_job_costs
                WHERE source = 'trip' AND job_plan_id IN (" . implode(',', array_fill(0, count($planIds), '?')) . ")
                GROUP BY job_plan_id
            ");
            $s->execute($planIds);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['job_plan_id']] = ['trip_overhead' => round((float)$r['overhead'], 2), 'trip_runs' => (int)$r['runs']];
            }
            return $out;
        } catch (Throwable $e) {
            return [];   // migration 1218 not run
        }
    }

    /**
     * Charlie's brief items ({key, kind, value, since, text, url, priority}): a job whose runs today
     * (or yesterday, which the overnight cron has priced) cost more than its quote allowed for trips.
     */
    public function briefItems(?string $today = null): array
    {
        $today = $today ?? $this->today ?? date('Y-m-d');
        if (!$this->ready()) return [];
        $out = [];
        foreach ([date('Y-m-d', strtotime($today . ' -1 day')) => 'yesterday', $today => 'today'] as $date => $when) {
            $b = $this->build($date);
            foreach (self::overheadByJob($b['rows']) as $planId => $o) {
                $job = $b['jobs'][$planId] ?? ['name' => 'A job', 'allowance' => 0.0];
                if ($o['runs'] < 1 || $o['overhead'] <= $job['allowance'] + 0.5) continue;
                $out[] = [
                    'key' => 'penny:trip-overhead:' . $date . ':' . $planId, 'kind' => 'trip_overhead', 'priority' => 3,
                    'value' => (int)round($o['overhead'] - $job['allowance']), 'since' => $date,
                    'text' => self::overLine($job['name'], $o['runs'], $o['overhead'], $job['allowance'], $when, $o['stock']),
                    'url' => '/crm/jobs/view.php?id=' . $planId,
                ];
            }
        }
        return $out;
    }
}
