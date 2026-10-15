<?php
/**
 * BookkeeperDeskService — Penny's desk on the dashboard.
 *
 * The bookkeeper card's numbers (is she working, is she getting smarter, is she
 * saving money), the receipt carousel's queue, the owner's approve / edit decisions,
 * and preparing the next receipts in the background.
 *
 * A decision writes the approved values to the expense, records per field whether the
 * owner accepted Penny's value (her scorecard, and the worked examples for her next
 * receipt), then approves through ExpenseApprovalService — which also teaches the
 * receipt reader (learnFromConfirmedExpense). Line items are shown, not changed, unless
 * the decision says overrides.line_items = 'penny' ("Use Penny's version" on the receipt
 * screen, PennyReadService): then her items replace the reader's, and approval teaches the
 * reader that layout (teachReaderFromPenny).
 *
 * Preparation is text-only by default — the backtest (2026-10-05, 57 receipts) had
 * text-only ahead on category (91% vs 84%) and line items (84% vs 71%) at 3.5¢ vs 5¢ —
 * and adds the photo only when the text's amounts don't add up.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */

require_once __DIR__ . '/ReceiptBookkeeperService.php';
require_once __DIR__ . '/ExpenseApprovalService.php';
if (!function_exists('sameVendorName')) {
    require_once (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/Services/Receipts/ReceiptLearning.php';
}

class BookkeeperDeskService
{
    /** Minutes of owner time a prepared receipt saves (typing, checking tax, looking up the job). */
    public const MINUTES_SAVED_PER_RECEIPT = 2.0;
    /** Claude Opus 5.5 list prices, $ per million tokens. */
    public const PRICE_IN  = 4.0;
    public const PRICE_OUT = 20.0;
    /** Default ceiling on receipts prepared per day (ops_settings bookkeeper_daily_cap). */
    public const DEFAULT_DAILY_CAP = 40;

    private PDO $db;
    private ?ReceiptBookkeeperService $bookkeeper;

    /** @var ExpenseGate|null the one door for expense changes (migration 1233) — tests pass a spy */
    private $gate;

    public function __construct(PDO $db, ?ReceiptBookkeeperService $bookkeeper = null, $gate = null)
    {
        $this->db = $db;
        $this->bookkeeper = $bookkeeper;
        $this->gate = $gate;
    }

    private function gate(): ExpenseGate
    {
        return $this->gate ?? ($this->gate = new ExpenseGate($this->db));
    }

    /**
     * The card's "Split by line" value: null = not sent (leave the split as it is), [] = split
     * off, else the per-line choices. Accepts the array or its JSON (the card's hidden field). Pure.
     */
    public static function splitChoices(array $overrides): ?array
    {
        return array_key_exists('split', $overrides) ? ExpenseSplitService::choices($overrides['split']) : null;
    }

    private function bookkeeper(): ReceiptBookkeeperService
    {
        return $this->bookkeeper ??= new ReceiptBookkeeperService($this->db);
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'expense_suggestions'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Card numbers
    // ─────────────────────────────────────────────────────────────────────────

    public function stats(?float $ownerRate = null): array
    {
        $one = function (string $sql, array $p = []) {
            $s = $this->db->prepare($sql);
            $s->execute($p);
            return $s->fetchColumn();
        };
        $monthStart = date('Y-m-01');

        $waiting = (int)$one("SELECT COUNT(*) FROM expenses WHERE status = 'pending_approval'");
        $drafts  = (int)$one("SELECT COUNT(*) FROM expenses WHERE status = 'draft'");
        $ready   = (int)$one("
            SELECT COUNT(DISTINCT s.expense_id) FROM expense_suggestions s
            JOIN expenses e ON e.id = s.expense_id
            WHERE s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval')
        ");

        // Only decisions whose receipt really was approved (a refused approval once left
        // suggestions marked decided on receipts still waiting).
        $approved = "AND expense_id IN (SELECT id FROM expenses WHERE status IN ('approved', 'forwarded'))";
        $accepted = (int)$one("SELECT COUNT(*) FROM expense_suggestions WHERE source = 'live' AND status = 'accepted' AND decided_at >= ? {$approved}", [$monthStart]);
        $edited   = (int)$one("SELECT COUNT(*) FROM expense_suggestions WHERE source = 'live' AND status = 'edited' AND decided_at >= ? {$approved}", [$monthStart]);
        $codeOnly = (int)$one("SELECT COUNT(*) FROM expense_suggestions WHERE source = 'live' AND model = 'code' AND created_at >= ?", [$monthStart]);
        $made     = (int)$one("SELECT COUNT(*) FROM expense_suggestions WHERE source = 'live' AND created_at >= ?", [$monthStart]);

        // Live receipts only: backtests are Tim's experiments, not what Penny's work costs (review round 2).
        $tokens = $this->db->prepare("SELECT COALESCE(SUM(input_tokens),0), COALESCE(SUM(output_tokens),0) FROM expense_suggestions WHERE source = 'live' AND created_at >= ?");
        $tokens->execute([$monthStart]);
        [$tin, $tout] = array_map('intval', $tokens->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        $aiCost = round($tin / 1e6 * self::PRICE_IN + $tout / 1e6 * self::PRICE_OUT, 2);

        $decided = $accepted + $edited;
        $rightFirstTime = self::pct($accepted, $decided);
        $rightSource = 'your decisions';
        if ($decided < 5) {
            $rightFirstTime = $this->backtestCategoryPct();
            $rightSource = 'past receipts';
        }

        $hoursSaved = round($decided * self::MINUTES_SAVED_PER_RECEIPT / 60, 1);
        $netSaving = ($decided > 0 && $ownerRate) ? round($hoursSaved * $ownerRate - $aiCost, 2) : null;

        // GST: the last 60 days — confirmed (claimable now) vs stuck in unapproved receipts. Claimable
        // the way the GST return counts it (GstReportService: meals at the ITC rate), and a waiting
        // receipt held as a possible duplicate isn't counted twice (review round 2, 2026-10-12).
        $since = date('Y-m-d', strtotime('-60 days'));
        $gst = $this->db->prepare("
            SELECT id, status, gst_amount, accounting_category AS category
            FROM expenses WHERE expense_date >= ? AND status IN ('approved','forwarded','draft','pending_approval')
              AND gst_amount <> 0
        ");
        $gst->execute([$since]);
        $gstRows = $gst->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $held = [];
        try {
            if (!class_exists('DuplicateReceiptService')) require_once __DIR__ . '/DuplicateReceiptService.php';
            $waitingIds = array_map(fn($r) => (int)$r['id'], array_filter($gstRows, fn($r) => in_array($r['status'], ['draft', 'pending_approval'], true)));
            $held = DuplicateReceiptService::heldIds((new DuplicateReceiptService($this->db))->pairsFor($waitingIds));
        } catch (Throwable $e) { /* no duplicate check: count them all */ }
        if (!class_exists('GstReportService')) require_once dirname(__DIR__, 2) . '/Accounting/Services/GstReportService.php';
        [$gstConfirmed, $gstStuck] = self::gstClaimBack($gstRows, $held, (new GstReportService($this->db))->settings());

        $jobs = $this->db->prepare("SELECT COUNT(*), SUM(job_id IS NOT NULL) FROM expenses WHERE expense_date >= ? AND status <> 'rejected'");
        $jobs->execute([date('Y-m-d', strtotime('-90 days'))]);
        [$jobAll, $jobWith] = array_map('intval', $jobs->fetch(PDO::FETCH_NUM) ?: [0, 0]);

        return [
            'waiting'           => $waiting,
            'drafts'            => $drafts,
            'ready'             => $ready,
            'decided_month'     => $decided,
            'right_first_time'  => $rightFirstTime,
            'right_source'      => $rightSource,
            'code_alone_pct'    => self::pct($codeOnly, $made) ?? 0,
            'ai_cost_month'     => $aiCost,
            'hours_saved_month' => $hoursSaved,
            'net_saving_month'  => $netSaving,
            'gst_confirmed'     => round($gstConfirmed, 2),
            'gst_stuck'         => round($gstStuck, 2),
            'job_costing_pct'   => self::pct($jobWith, $jobAll) ?? 0,
        ];
    }

    private function backtestCategoryPct(): ?int
    {
        $rows = $this->db->query("
            SELECT s.suggestion_json, e.accounting_category
            FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
            WHERE s.source = 'backtest' AND s.status = 'scored' AND s.used_image = 0
        ")->fetchAll(PDO::FETCH_ASSOC);
        $right = 0;
        $of = 0;
        foreach ($rows as $r) {
            if (empty($r['accounting_category'])) continue;
            $s = json_decode((string)$r['suggestion_json'], true) ?: [];
            $of++;
            if (($s['accounting_category']['value'] ?? null) === $r['accounting_category']) $right++;
        }
        return self::pct($right, $of);
    }

    public static function pct(int $part, int $of): ?int
    {
        return $of > 0 ? (int)round($part / $of * 100) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Carousel
    // ─────────────────────────────────────────────────────────────────────────

    /** Prepared receipts, waiting-for-approval first, then the oldest drafts. */
    /** @param int[] $hold expense ids held back (possible duplicates, sorted first) */
    public function queue(int $limit = 10, array $hold = []): array
    {
        $limit = max(1, min(25, $limit));
        $holdSql = $hold ? ' AND e.id NOT IN (' . implode(',', array_map('intval', $hold)) . ')' : '';
        $rows = $this->db->query("
            SELECT s.id AS suggestion_id, s.suggestion_json, s.checks_json, s.current_json, s.used_image, s.outcome_json,
                   e.id AS expense_id, e.status, e.expense_date, e.total, e.amount, e.receipt_media_id,
                   COALESCE(v.name, e.vendor_name_raw) AS vendor, e.vendor_id, u.full_name AS submitted_by
            FROM expense_suggestions s
            JOIN expenses e ON e.id = s.expense_id
            LEFT JOIN vendors v ON v.id = e.vendor_id
            LEFT JOIN users u ON u.id = e.created_by
            LEFT JOIN (
                SELECT LOWER(TRIM(COALESCE(v2.name, e2.vendor_name_raw, ''))) AS vk, COUNT(*) AS n
                FROM expense_suggestions s2 JOIN expenses e2 ON e2.id = s2.expense_id LEFT JOIN vendors v2 ON v2.id = e2.vendor_id
                WHERE s2.source = 'live' AND s2.status = 'pending' AND e2.status IN ('draft', 'pending_approval')
                GROUP BY vk
            ) g ON g.vk = LOWER(TRIM(COALESCE(v.name, e.vendor_name_raw, '')))
            WHERE s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval'){$holdSql}
            -- Saved drafts first, then one vendor at a time, biggest group first (2026-10-14: corrections
            -- made twice for a vendor redo the rest of its group), oldest first within it.
            ORDER BY (s.outcome_json IS NOT NULL) ASC, g.n DESC, g.vk ASC, e.expense_date ASC, e.id ASC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);

        $jobTitles = $this->jobTitles($rows);
        $items = $this->lineItems(array_map(fn($r) => (int)$r['expense_id'], $rows));
        $bank = $this->bankMatches(array_map(fn($r) => (int)$r['expense_id'], $rows));
        $facts = $this->factsLines(array_map(fn($r) => (int)$r['expense_id'], $rows));
        $strength = [];
        try {
            require_once __DIR__ . '/PennyBadgeService.php';
            foreach ((new PennyBadgeService($this->db))->vendors(500) as $v) $strength[strtolower($v['vendor'])] = $v;
        } catch (Throwable $e) { /* strength is a bonus */ }
        $out = [];
        foreach ($rows as $r) {
            $s = json_decode((string)$r['suggestion_json'], true) ?: [];
            $jobId = $s['job']['value'] ?? null;
            // Penny read "HOME DEPOT #7054" and the receipt is already Home Depot: show the
            // vendor's own name, so the field only looks different when she disagrees.
            if (isset($s['vendor']['value']) && $r['vendor'] !== null && sameVendorName((string)$s['vendor']['value'], (string)$r['vendor'])) {
                $s['vendor']['value'] = $r['vendor'];
            }
            $out[] = [
                'suggestion_id' => (int)$r['suggestion_id'],
                'expense_id'    => (int)$r['expense_id'],
                'status'        => $r['status'],
                'vendor'        => $r['vendor'],
                'vendor_id'     => $r['vendor_id'] !== null ? (int)$r['vendor_id'] : null,
                'date'          => $r['expense_date'],
                'submitted_by'  => $r['submitted_by'],
                'image_url'     => $r['receipt_media_id'] ? self::imageUrl((int)$r['receipt_media_id']) : null,
                'suggestion'    => $s,
                'job_title'     => $jobId ? ($jobTitles[(int)$jobId] ?? null) : null,
                'checks'        => json_decode((string)$r['checks_json'], true) ?: [],
                // The receipt's own items (editable on the card via expenses.php *_line_item).
                'items'         => $items[(int)$r['expense_id']] ?? [],
                // How well she knows this vendor, from your past approvals.
                'vendor_strength' => $strength[strtolower(trim((string)$r['vendor']))] ?? null,
                // The receipts system's own warnings: anomaly rules, and the bank line it matched.
                'anomalies'     => $this->anomalies((int)$r['expense_id']),
                'bank'          => $bank[(int)$r['expense_id']] ?? null,
                // Not linked yet: the bank charge that looks like this receipt — the truth
                // when the photo is cut off or unclear (amount within 2%, ±14 days, vendor).
                'bank_candidate' => isset($bank[(int)$r['expense_id']]) ? null : $this->bankCandidate((int)$r['expense_id']),
                // What is printed on it: "12:22–12:39 · ticket 43176009 · ••1234" (receipt_facts, 1227).
                'facts_line'    => $facts[(int)$r['expense_id']] ?? '',
                'subtotal'      => $r['amount'] !== null ? (float)$r['amount'] : null,
                'current'       => json_decode((string)$r['current_json'], true) ?: [],
                // The owner's saved-but-not-approved edits, if any — the form reopens with them.
                'saved_draft'   => (json_decode((string)($r['outcome_json'] ?? ''), true) ?: [])['draft'] ?? null,
                // Split by line (migration 1233): Penny's per-line job / category / stock, and the
                // split already saved. Only for receipts with two or more lines.
                'split'         => count($items[(int)$r['expense_id']] ?? []) >= 2 ? $this->splitFor((int)$r['expense_id'], $jobId ? (int)$jobId : null) : null,
            ];
        }
        return $out;
    }

    /** Penny's split proposal for the card, or null before migration 1233. */
    private function splitFor(int $expenseId, ?int $suggestedJob): ?array
    {
        try {
            require_once __DIR__ . '/ExpenseSplitService.php';
            $svc = new ExpenseSplitService($this->db);
            return $svc->ready() ? $svc->propose($expenseId, $suggestedJob) : null;
        } catch (Throwable $e) {
            error_log('Penny split proposal #' . $expenseId . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Anomaly rules (AnomalyDetector) run on the receipt as it stands now: [{code, score, detail}]. */
    private function anomalies(int $expenseId): array
    {
        try {
            if (!function_exists('detectAnomalies')) {
                require_once APP_ROOT . '/Services/Receipts/AnomalyDetector.php';
            }
            $s = $this->db->prepare("SELECT * FROM expenses WHERE id = ?");
            $s->execute([$expenseId]);
            $e = $s->fetch(PDO::FETCH_ASSOC);
            if (!$e) return [];
            $res = detectAnomalies($e, $this->db);
            return array_map(fn($d) => ['code' => $d['code'], 'score' => (int)$d['score'], 'detail' => (string)($d['detail'] ?? $d['code'])],
                             $res['details'] ?? []);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** expense id => ReceiptFactsService::line() ('' when nothing printed is known). */
    private function factsLines(array $expenseIds): array
    {
        try {
            require_once __DIR__ . '/ReceiptFactsService.php';
            $out = [];
            foreach ((new ReceiptFactsService($this->db))->forExpenses($expenseIds) as $id => $f) $out[$id] = ReceiptFactsService::line($f);
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** expense id => the bank line it was matched to (bank import reconciliation). */
    private function bankCandidate(int $expenseId): ?array
    {
        try {
            require_once APP_ROOT . '/Modules/Accounting/Services/BankImportService.php';
            $c = (new BankImportService($this->db))->candidateTransactionsForExpense($expenseId, 1)[0] ?? null;
            return $c && $c['confidence'] >= 60 ? ['date' => $c['date'], 'amount' => $c['amount'], 'description' => $c['description']] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function bankMatches(array $expenseIds): array
    {
        if (!$expenseIds) return [];
        try {
            $in = implode(',', array_fill(0, count($expenseIds), '?'));
            $s = $this->db->prepare("SELECT matched_expense_id, transaction_date, description, amount FROM accounting_transactions
                                     WHERE matched_expense_id IN ({$in})");
            $s->execute($expenseIds);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['matched_expense_id']] = ['date' => $r['transaction_date'], 'description' => $r['description'], 'amount' => (float)$r['amount']];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** expense id => [{id, name, quantity, unit_price, line_total}] */
    private function lineItems(array $expenseIds): array
    {
        if (!$expenseIds) return [];
        $in = implode(',', array_fill(0, count($expenseIds), '?'));
        $s = $this->db->prepare("SELECT li.id, li.expense_id, li.name, li.quantity, li.unit_price, li.line_total, li.product_id, p.name AS product_name
                                 FROM expense_line_items li LEFT JOIN products p ON p.id = li.product_id
                                 WHERE li.expense_id IN ({$in}) ORDER BY li.sort_order, li.id");
        $s->execute($expenseIds);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['expense_id']][] = ['id' => (int)$r['id'], 'name' => $r['name'], 'quantity' => (float)$r['quantity'],
                'unit_price' => $r['unit_price'] !== null ? (float)$r['unit_price'] : null, 'line_total' => (float)$r['line_total'],
                'product_id' => $r['product_id'] !== null ? (int)$r['product_id'] : null, 'product_name' => $r['product_name']];
        }
        return $out;
    }

    private function jobTitles(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $s = json_decode((string)$r['suggestion_json'], true) ?: [];
            if (!empty($s['job']['value'])) $ids[] = (int)$s['job']['value'];
        }
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT jp.id, CONCAT(COALESCE(jp.title, ''), ' — ', COALESCE(p.address, '')) AS t
            FROM job_plans jp LEFT JOIN properties p ON p.id = jp.property_id
            WHERE jp.id IN ({$in})
        ");
        $stmt->execute($ids);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 't', 'id');
    }

    private static function imageUrl(int $mediaId): string
    {
        if (!function_exists('signReceiptUrl')) {
            require_once APP_ROOT . '/Services/Receipts/ReceiptUrlSigner.php';
        }
        return signReceiptUrl($mediaId, 900);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Decisions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Final values: Penny's suggestion, with the owner's edits laid over it. Pure.
     * @return array{final: array, outcome: array<string, array{suggested: mixed, final: mixed, accepted: bool}>}
     */
    /**
     * A typed amount that isn't a number (Codex review 2026-10-14: "oops" was saved as $0.00).
     * Blank keeps the stored value. Pure.
     */
    public static function amountProblem(array $overrides): ?string
    {
        $names = ['subtotal' => 'Subtotal', 'gst' => 'GST', 'pst' => 'PST', 'total' => 'Total'];
        foreach ($names as $k => $label) {
            if (!array_key_exists($k, $overrides)) continue;
            $v = $overrides[$k];
            if ($v === null || (is_string($v) && trim($v) === '')) continue;
            if (!is_numeric(is_string($v) ? str_replace([',', '$'], '', trim($v)) : $v)) return $label . ' isn\'t a number';
        }
        return null;
    }

    public static function resolveFinal(array $suggestion, array $overrides): array
    {
        $fields = ['vendor', 'accounting_category', 'asset_tag', 'job', 'subtotal', 'gst', 'pst', 'total'];
        $final = [];
        $outcome = [];
        foreach ($fields as $f) {
            $suggested = $suggestion[$f]['value'] ?? null;
            $value = array_key_exists($f, $overrides) ? $overrides[$f] : $suggested;
            if (in_array($f, ['subtotal', 'gst', 'pst', 'total'], true)) {
                $value = $value === null || $value === '' ? null : round((float)(is_string($value) ? str_replace([',', '$'], '', trim($value)) : $value), 2);
                $same = $suggested !== null && $value !== null && abs((float)$suggested - $value) < 0.005;
            } elseif ($f === 'job') {
                $value = $value === null || $value === '' || (int)$value === 0 ? null : (int)$value;
                $same = (int)($suggested ?? 0) === (int)($value ?? 0);
            } elseif ($f === 'vendor') {
                $value = $value === null ? null : trim((string)$value);
                $value = $value === '' ? null : $value;
                $same = sameVendorName((string)($suggested ?? ''), (string)($value ?? ''));
            } else {
                $value = $value === null || $value === '' ? null : (string)$value;
                $same = (string)($suggested ?? '') === (string)($value ?? '');
            }
            $final[$f] = $value;
            $outcome[$f] = ['suggested' => $suggested, 'final' => $value, 'accepted' => $same];
        }
        if (($final['asset_tag'] ?? null) === 'none') {
            $final['asset_tag'] = null;
        }
        return ['final' => $final, 'outcome' => $outcome];
    }

    /**
     * Approve a prepared receipt, optionally with the owner's edits — or, with
     * $approve false, save the edits as a draft to come back to: the values are written
     * to the expense and kept on the suggestion (outcome_json.draft), the suggestion
     * stays pending, and the expense keeps its status (draft stays draft, submitted
     * stays submitted). Nothing is approved and nothing is learned until approval.
     * @return array{ok: bool, message: string, approved?: bool, saved_draft?: bool}
     */
    public function decide(int $suggestionId, array $overrides, array $user, bool $approve = true): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, e.status AS expense_status, e.forwarded_to_accounting, e.vendor_id, e.created_by AS expense_created_by,
                   COALESCE(v.name, e.vendor_name_raw) AS current_vendor
            FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
            LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE s.id = ? AND s.source = 'live'
        ");
        $stmt->execute([$suggestionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Suggestion not found'];
        }
        if ($row['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'Already decided'];
        }
        if (!in_array($row['expense_status'], ['draft', 'pending_approval'], true) || (int)$row['forwarded_to_accounting'] === 1) {
            return ['ok' => false, 'message' => 'This receipt has already been handled'];
        }

        // The self-approval rule, checked before anything is written: a refused approval
        // must not mark her suggestion decided (it came straight back, re-prepared, and
        // counted as a win). Keep the edits as a draft and say how to unblock it.
        $blocked = $approve && (new ExpenseApprovalService($this->db))
            ->selfApprovalBlocked((int)$row['expense_created_by'], (int)$user['id']);
        if ($blocked) {
            $approve = false;
        }

        if ($why = self::amountProblem($overrides)) {
            return ['ok' => false, 'message' => $why];
        }
        $resolved = self::resolveFinal(json_decode((string)$row['suggestion_json'], true) ?: [], $overrides);
        $f = $resolved['final'];
        if ($f['accounting_category'] !== null && !in_array($f['accounting_category'], EXPENSE_ACCOUNTING_CATEGORIES, true)) {
            return ['ok' => false, 'message' => 'Unknown category'];
        }
        if ($f['asset_tag'] !== null && !in_array($f['asset_tag'], ReceiptBookkeeperRules::TAGS, true)) {
            return ['ok' => false, 'message' => 'Unknown tag'];
        }
        foreach ((array)self::splitChoices($overrides) as $sl) {
            if ($sl['accounting_category'] !== null && !in_array($sl['accounting_category'], EXPENSE_ACCOUNTING_CATEGORIES, true)) {
                return ['ok' => false, 'message' => 'Unknown category on a split line'];
            }
        }
        // The receipt date: the owner's correction only (Penny doesn't suggest one).
        $date = self::validDate($overrides['expense_date'] ?? null);
        if (($overrides['expense_date'] ?? '') !== '' && $date === null) {
            return ['ok' => false, 'message' => 'That date isn\'t valid'];
        }

        $propertyId = null;
        if ($f['job']) {
            $p = $this->db->prepare("SELECT property_id FROM job_plans WHERE id = ?");
            $p->execute([$f['job']]);
            $propertyId = $p->fetchColumn() ?: null;
        }

        // Only when the vendor really changed: "HOME DEPOT #7054" read off a Home Depot
        // receipt must not move it to a new vendor.
        $pickedVendor = isset($overrides['vendor_id']) && (int)$overrides['vendor_id'] > 0 ? (int)$overrides['vendor_id'] : null;
        $vendorChanged = $f['vendor'] !== null && ($pickedVendor
            ? $pickedVendor !== (int)($row['vendor_id'] ?? 0)
            : !sameVendorName($f['vendor'], (string)($row['current_vendor'] ?? '')));
        // A suggestion made before Penny read the vendor has nothing to score: an
        // unchanged vendor is not an edit of hers.
        if (!array_key_exists('vendor', json_decode((string)$row['suggestion_json'], true) ?: []) && !$vendorChanged) {
            unset($resolved['outcome']['vendor']);
        }
        $allAccepted = !in_array(false, array_column($resolved['outcome'], 'accepted'), true);
        // Typed by the owner (differs from Penny's read) vs Penny's read accepted as is —
        // only Penny's read goes through the fuzzy vendor gate the receipt reader uses.
        $ownerTypedVendor = !isset($resolved['outcome']['vendor']) || empty($resolved['outcome']['vendor']['accepted']);
        $before = $this->db->prepare("SELECT * FROM expenses WHERE id = ?");
        $before->execute([(int)$row['expense_id']]);
        $before = $before->fetch(PDO::FETCH_ASSOC) ?: [];
        // The owner's split by line, when the card sent one (true = keep / set it, false = end it).
        $split = self::splitChoices($overrides);
        $this->db->beginTransaction();
        try {
            // One decision per suggestion (Codex review 2026-10-14): two tabs both saw 'pending'.
            $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $st = $this->db->prepare("SELECT status FROM expense_suggestions WHERE id = ?" . $lock);
            $st->execute([$suggestionId]);
            if ($st->fetchColumn() !== 'pending') {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'Already decided'];
            }
            // Through the expense gate (migration 1233): only what changed is written, audited
            // as the owner's change from Penny's card; the split's shares are worked out there.
            $changes = [
                'asset_tag' => $f['asset_tag'],
                'job_id'    => $f['job'],
            ];
            if ($date !== null) $changes['expense_date'] = $date;
            if ($vendorChanged) {
                $v = $this->vendorFor($f['vendor'], $pickedVendor, $approve, $ownerTypedVendor);
                if (!$v['keep']) { $changes['vendor_id'] = $v['id']; $changes['vendor_name_raw'] = $f['vendor']; }
            }
            foreach (['accounting_category' => 'accounting_category', 'property_id' => null, 'amount' => 'subtotal',
                      'gst_amount' => 'gst', 'pst_amount' => 'pst', 'total' => 'total'] as $col => $key) {
                $val = $key === null ? $propertyId : $f[$key];
                if ($val !== null) $changes[$col] = $val;          // COALESCE: an empty field keeps what is stored
            }
            if ($split !== null) $changes['allocations'] = $split;
            // "Use Penny's version" (receipt screen, 2026-10-13): her line items replace the reader's.
            // On approval the gate's learnConfirmed → teachReaderFromPenny() teaches the reader.
            if (($overrides['line_items'] ?? null) === 'penny') {
                $items = self::pennyItems(json_decode((string)$row['suggestion_json'], true) ?: []);
                if ($items) {
                    $changes['line_items'] = $items;
                    $changes['line_items_source'] = 'penny';
                }
            }
            $this->gate()->apply((int)$row['expense_id'], $changes, ['id' => (int)$user['id'], 'kind' => 'user'], 'penny_card');
            if ($approve) {
                $this->db->prepare("
                    UPDATE expense_suggestions
                    SET status = ?, outcome_json = ?, decided_by = ?, decided_at = NOW()
                    WHERE id = ?
                ")->execute([$allAccepted ? 'accepted' : 'edited', json_encode($resolved['outcome']), (int)$user['id'], $suggestionId]);
            } else {
                $this->db->prepare("UPDATE expense_suggestions SET outcome_json = ? WHERE id = ?")
                   ->execute([json_encode(['draft' => $f, 'saved_by' => (int)$user['id'], 'saved_at' => date('c')]), $suggestionId]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($approve) {
            $this->recordLessons((int)$row['expense_id'], $resolved['outcome'], (int)$user['id']);
        }
        if ($blocked) {
            return ['ok' => true, 'approved' => false, 'saved_draft' => true, 'blocked' => true,
                    'message' => "Not approved — you submitted this receipt, and the self-approval rule stops you approving your own. Your edits are saved. Turn on \"Allow approving own submitted expenses\" for yourself in Team."];
        }
        if (!$approve) {
            return ['ok' => true, 'approved' => false, 'saved_draft' => true, 'message' => 'Saved as a draft — it stays here until you approve it'];
        }

        // Approval is separate: the self-approval rule still applies, and approval is
        // what teaches the receipt reader.
        try {
            // An emailed-in receipt has no capture baseline: the row as it was before these
            // edits is what the approver's corrections are learned from (learnFromConfirmedExpense).
            (new ExpenseApprovalService($this->db, $this->gate()))->approve((int)$row['expense_id'], $user, [
                'source' => 'penny_card',
                'learn_baseline' => ($before['source'] ?? '') === 'email_inbox' ? baselineFromExpenseRow($before) : null,
            ]);
            $this->afterInboxApproval((int)$row['expense_id'], $before);
            $res = ['ok' => true, 'approved' => true, 'message' => $allAccepted ? 'Approved' : 'Approved with your changes'];
            // A correction made the same way twice for this vendor: her other waiting reads for it are redone
            // with that lesson (the API does it after replying — rereadVendor). Said on the card.
            $learned = $allAccepted ? null : $this->learnedTwice((int)$row['expense_id'], $resolved['outcome']);
            if ($learned && $learned['others']) {
                $res['reread'] = $learned;
                $n = count($learned['others']);
                $res['message'] .= ' — I\'ll redo my other ' . $n . ' ' . $learned['vendor'] . ' receipt' . ($n === 1 ? '' : 's') . ' with that'
                                 . ($n > self::REREAD_MAX ? ' (' . self::REREAD_MAX . ' now, the rest in the background)' : '');
            }
            return $res;
        } catch (Throwable $e) {
            // Not approved after all: put it back on the desk as a saved draft, undecided.
            $this->db->prepare("UPDATE expense_suggestions SET status = 'pending', decided_by = NULL, decided_at = NULL, outcome_json = ? WHERE id = ?")
               ->execute([json_encode(['draft' => $f, 'saved_by' => (int)$user['id'], 'saved_at' => date('c')]), $suggestionId]);
            return ['ok' => true, 'approved' => false, 'saved_draft' => true, 'blocked' => true, 'message' => 'Not approved: ' . $e->getMessage() . ' — your edits are saved'];
        }
    }

    /**
     * "Use Penny's items" on the card, in one write (Codex review 2026-10-14): the card used to delete
     * each line and add each of hers in separate requests, so a failure partway left half a list.
     * Through the expense gate, replacing every line at once; nothing is approved.
     * @return array{ok: bool, message: string, items?: list<array>}
     */
    public function usePennyItems(int $suggestionId, array $user): array
    {
        $st = $this->db->prepare("SELECT s.expense_id, s.status, s.suggestion_json, e.status AS expense_status, e.forwarded_to_accounting
                                  FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id WHERE s.id = ?");
        $st->execute([$suggestionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'message' => 'Suggestion not found'];
        if ($row['status'] !== 'pending' || !in_array($row['expense_status'], ['draft', 'pending_approval'], true) || (int)$row['forwarded_to_accounting'] === 1) {
            return ['ok' => false, 'message' => 'This receipt has already been handled'];
        }
        $items = self::pennyItems(json_decode((string)$row['suggestion_json'], true) ?: []);
        if (!$items) return ['ok' => false, 'message' => 'Penny has no line items for this receipt'];
        $this->gate()->apply((int)$row['expense_id'], ['line_items' => $items, 'line_items_source' => 'penny'],
                             ['id' => (int)$user['id'], 'kind' => 'user'], 'penny_card');
        return ['ok' => true, 'message' => 'Using Penny\'s items', 'items' => $this->lineItems([(int)$row['expense_id']])[(int)$row['expense_id']] ?? []];
    }

    /** Penny's line items as expense lines (name, quantity 1, line total). Pure. */
    public static function pennyItems(array $suggestion): array
    {
        $out = [];
        foreach ((array)($suggestion['line_items'] ?? []) as $it) {
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '' || !isset($it['amount']) || !is_numeric($it['amount'])) continue;
            $out[] = ['name' => mb_substr($name, 0, 255), 'quantity' => 1, 'unit_price' => null,
                      'line_total' => round((float)$it['amount'], 2)];
        }
        return $out;
    }

    /**
     * The owner changed what Penny suggested on her card: one head_lessons row per field she got
     * wrong (migration 1348). Recording only; never blocks the decision.
     */
    // ─────────────────────────────────────────────────────────────────────────
    // Approving on the receipts page (2026-10-14): Penny's approve window says what it teaches her
    // ─────────────────────────────────────────────────────────────────────────

    /** Her latest waiting read of a receipt, or null. */
    private function pendingRead(int $expenseId): ?array
    {
        $s = $this->db->prepare("SELECT id, suggestion_json FROM expense_suggestions WHERE expense_id = ? AND source = 'live' AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $s->execute([$expenseId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Her read vs the receipt as it stands: field => [suggested, final, accepted] (the shape resolveFinal()
     * gives on her card). Pure.
     */
    public static function readVsReceipt(array $suggestion, array $e): array
    {
        $val = fn($f) => $suggestion[$f]['value'] ?? null;
        // A field still empty on the receipt is not the owner's answer — it was never filled in (her read
        // wasn't applied). Left out, so approving can't teach "you set nothing" (2026-10-14, Chevron #36).
        $blank = fn($v) => $v === null || trim((string)$v) === '' || (string)$v === '0';
        $out = [];
        if (array_key_exists('vendor', $suggestion)) {
            $now = (string)($e['vendor'] ?? '');
            $out['vendor'] = ['suggested' => $val('vendor'), 'final' => $now, 'accepted' => sameVendorName((string)$val('vendor'), $now)];
        }
        if (array_key_exists('accounting_category', $suggestion) && !$blank($e['accounting_category'] ?? null)) {
            $out['accounting_category'] = ['suggested' => $val('accounting_category'), 'final' => $e['accounting_category'] ?? null,
                                           'accepted' => (string)$val('accounting_category') === (string)($e['accounting_category'] ?? '')];
        }
        if (array_key_exists('asset_tag', $suggestion) && (!$blank($e['asset_tag'] ?? null) || in_array($val('asset_tag'), [null, 'none'], true))) {
            $said = $val('asset_tag') === 'none' ? '' : (string)$val('asset_tag');
            $out['asset_tag'] = ['suggested' => $val('asset_tag'), 'final' => ($e['asset_tag'] ?? '') ?: 'none', 'accepted' => $said === (string)($e['asset_tag'] ?? '')];
        }
        if (array_key_exists('job', $suggestion) && (!$blank($e['job_id'] ?? null) || $blank($val('job')))) {
            $out['job'] = ['suggested' => $val('job'), 'final' => $e['job_id'] ?? null, 'accepted' => (int)$val('job') === (int)($e['job_id'] ?? 0)];
        }
        if (array_key_exists('total', $suggestion) && $val('total') !== null) {
            $out['total'] = ['suggested' => $val('total'), 'final' => $e['total'] ?? null, 'accepted' => abs((float)$val('total') - (float)($e['total'] ?? 0)) <= 0.02];
        }
        return $out;
    }

    /**
     * What approving this receipt teaches Penny, for her approve window: one line per field she read,
     * her track record for the vendor, and how many of its other waiting receipts she'd redo.
     * @return array{vendor: string, read: bool, lessons: list<array{field:string,label:string,said:?string,set:?string,right:bool}>,
     *               track: array<string,string>, redo: int, units: int, parts: array, since: string}
     */
    public function approvalPreview(int $expenseId): array
    {
        $s = $this->db->prepare("SELECT e.*, COALESCE(v.name, e.vendor_name_raw) AS vendor FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $vendor = trim((string)($e['vendor'] ?? ''));
        $out = ['vendor' => $vendor, 'read' => false, 'lessons' => [], 'track' => [], 'redo' => 0, 'units' => 0, 'parts' => [], 'since' => ''];
        if (!$e) return $out;
        $read = $this->pendingRead($expenseId);
        if ($read) {
            $out['read'] = true;
            $labels = ['vendor' => 'Vendor', 'accounting_category' => 'Category', 'asset_tag' => 'For', 'job' => 'Job', 'total' => 'Total'];
            foreach (self::readVsReceipt(json_decode((string)$read['suggestion_json'], true) ?: [], $e) as $f => $o) {
                $out['lessons'][] = ['field' => $f, 'label' => $labels[$f] ?? $f,
                                     'said' => $o['suggested'] === null ? null : (string)$o['suggested'],
                                     'set' => $o['final'] === null ? null : (string)$o['final'], 'right' => (bool)$o['accepted']];
            }
            $outcome = self::readVsReceipt(json_decode((string)$read['suggestion_json'], true) ?: [], $e);
            $learned = $this->learnedTwice($expenseId, $outcome, false);
            $out['redo'] = $learned ? count($learned['others']) : 0;
        }
        require_once APP_ROOT . '/Modules/Accounting/Services/PennyLessons.php';
        if (!empty($e['vendor_id'])) $out['track'] = PennyLessons::trackRecord($this->db, (int)$e['vendor_id'], $expenseId);
        try {
            require_once __DIR__ . '/PennyBrainService.php';
            $b = (new PennyBrainService($this->db))->learned();
            $out['units'] = (int)($b['units'] ?? 0);
            $out['parts'] = $b['parts'] ?? [];
            $out['since'] = !empty($b['since']) ? date('M j, Y', strtotime((string)$b['since'])) : '';
        } catch (Throwable $ex) { /* the brain is a bonus */ }
        return $out;
    }

    /**
     * Approved on the receipts page, not her card: her waiting read is decided too (accepted / edited, with
     * the field outcome — her track record counts it), and a corrected field redoes the vendor's group,
     * as on her card. The corrections themselves were recorded as lessons when the receipt was edited.
     * @return int[] suggestion ids to redo (the API does it after replying)
     */
    public function decideOffCard(int $expenseId, int $userId): array
    {
        $read = $this->pendingRead($expenseId);
        if (!$read) return [];
        $s = $this->db->prepare("SELECT e.*, COALESCE(v.name, e.vendor_name_raw) AS vendor FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e) return [];
        $outcome = self::readVsReceipt(json_decode((string)$read['suggestion_json'], true) ?: [], $e);
        $all = !in_array(false, array_column($outcome, 'accepted'), true);
        $u = $this->db->prepare("UPDATE expense_suggestions SET status = ?, outcome_json = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'pending'");
        $u->execute([$all ? 'accepted' : 'edited', json_encode($outcome), $userId, (int)$read['id']]);
        if ($u->rowCount() !== 1 || $all) return [];
        $learned = $this->learnedTwice($expenseId, $outcome);
        return $learned['others'] ?? [];
    }

    /** Most waiting reads of one vendor redone at once after a lesson (each is one AI call). */
    public const REREAD_MAX = 10;

    /**
     * Did this approval correct a learnable field (category, For tag, job) for this vendor? Then: the vendor
     * and its OTHER waiting suggestion ids, oldest first. (Was "twice"; one is enough since the middle path.)
     * @return ?array{vendor: string, fields: string[], others: int[]}
     */
    public function learnedTwice(int $expenseId, array $outcome, bool $mark = true): ?array
    {
        $corrected = [];
        foreach (['accounting_category' => 'accounting_category', 'asset_tag' => 'asset_tag', 'job' => 'job_id'] as $f => $lf) {
            if (isset($outcome[$f]) && empty($outcome[$f]['accepted']) && ($outcome[$f]['final'] ?? null) !== null) $corrected[$lf] = (string)$outcome[$f]['final'];
        }
        if (!$corrected) return null;
        $v = $this->db->prepare("SELECT COALESCE(v.name, e.vendor_name_raw) FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $v->execute([$expenseId]);
        $vendor = trim((string)$v->fetchColumn());
        if ($vendor === '') return null;
        require_once APP_ROOT . '/Modules/Accounting/Services/PennyLessons.php';
        $lessons = PennyLessons::forVendor($this->db, $vendor, 0, null, 40);
        // Middle path (Tim 2026-10-14): one correction is enough to redo the group — her prompt applies a
        // single correction only to receipts like the one it was made on (on_receipt), twice = the rule.
        $twice = [];
        foreach ($corrected as $f => $val) {
            $n = count(array_filter($lessons, fn($l) => $l['field'] === $f && (string)$l['owner_set'] === $val));
            if ($n >= 1) $twice[] = $f;
        }
        if (!$twice) return null;
        $o = $this->db->prepare("
            SELECT s.id FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval') AND e.id <> ?
              AND LOWER(TRIM(COALESCE(v.name, e.vendor_name_raw))) = LOWER(?)
            ORDER BY e.expense_date, e.id LIMIT 1000");
        $o->execute([$expenseId, $vendor]);
        $others = array_map('intval', $o->fetchAll(PDO::FETCH_COLUMN));
        // The first REREAD_MAX are redone now; the rest by the background preparer, first in line (relearn()).
        if ($mark && count($others) > self::REREAD_MAX) $this->markRelearn($vendor);
        return ['vendor' => $vendor, 'fields' => $twice, 'others' => $others];
    }

    private const RELEARN_KEY = 'penny_relearn';

    /** Remember: this vendor's reads made before now are out of date (ops_settings JSON vendor-key => time). */
    public function markRelearn(string $vendor): void
    {
        $lock = false;
        try { $lock = (int)$this->db->query("SELECT GET_LOCK(CONCAT(DATABASE(), '.penny_relearn'), 5)")->fetchColumn() === 1; }
        catch (Throwable $e) { /* no named locks */ }
        try {
            $map = $this->relearnMap();
            $map[mb_strtolower(trim($vendor))] = date('Y-m-d H:i:s');
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, 'Penny: vendors whose waiting reads are redone after a lesson')
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([self::RELEARN_KEY, json_encode($map)]);
        } catch (Throwable $e) { error_log('Penny relearn mark: ' . $e->getMessage()); }
        finally {
            if ($lock) { try { $this->db->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '.penny_relearn'))"); } catch (Throwable $e) { /* ends with the connection */ } }
        }
    }

    private function relearnMap(): array
    {
        try {
            $v = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = '" . self::RELEARN_KEY . "'")->fetchColumn();
            $m = $v ? json_decode((string)$v, true) : [];
            return is_array($m) ? $m : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Waiting reads made before a lesson about their vendor (markRelearn), oldest first, at most $n — the
     * background preparer redoes these before new receipts. A vendor with none left drops off the list.
     */
    public function staleReads(int $n): array
    {
        $map = $this->relearnMap();
        if (!$map || $n <= 0) return [];
        $out = []; $done = [];
        foreach ($map as $vk => $at) {
            $s = $this->db->prepare("
                SELECT s.id FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval') AND s.created_at < ?
                  AND LOWER(TRIM(COALESCE(v.name, e.vendor_name_raw))) = ?
                ORDER BY e.expense_date, e.id LIMIT " . (int)$n);
            $s->execute([$at, $vk]);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) { $done[] = $vk; continue; }
            foreach ($ids as $id) { if (count($out) < $n) $out[] = $id; }
            if (count($out) >= $n) break;
        }
        if ($done) {
            foreach ($done as $vk) unset($map[$vk]);
            try {
                $this->db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?")->execute([json_encode($map), self::RELEARN_KEY]);
            } catch (Throwable $e) { /* next run */ }
        }
        return $out;
    }

    /**
     * Redo these waiting reads now (after a lesson). Owner-triggered like a re-check, so the receipt
     * daily cap doesn't apply; the AI-call ceiling (LlmDailyCap) inside suggest() still does. A read
     * already decided meanwhile is skipped. The old read is kept as 'superseded' only once the new one
     * is in. @return int redone
     */
    public function rereadVendor(array $suggestionIds): int
    {
        // Under the preparer's lock (Codex review 2026-10-14): the background run and an approval never
        // re-read the same receipt twice. Waits for a running batch to finish.
        $got = true;
        try { $got = (int)$this->db->query("SELECT GET_LOCK(CONCAT(DATABASE(), '.penny_prepare'), 120)")->fetchColumn() === 1; }
        catch (Throwable $e) { /* no named locks here: carry on */ }
        if (!$got) return 0;
        try {
            return $this->rereadVendorLocked($suggestionIds);
        } finally {
            try { $this->db->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '.penny_prepare'))"); } catch (Throwable $e) { /* ends with the connection */ }
        }
    }

    private function rereadVendorLocked(array $suggestionIds): int
    {
        $done = 0;
        foreach (array_slice(array_map('intval', $suggestionIds), 0, self::REREAD_MAX) as $sid) {
            $s = $this->db->prepare("SELECT s.expense_id FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
                                     WHERE s.id = ? AND s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval')");
            $s->execute([$sid]);
            $eid = (int)$s->fetchColumn();
            if (!$eid) continue;
            $r = $this->suggestOne($eid);
            if (!empty($r['error'])) {
                if (!empty($r['id'])) $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE id = ?")->execute([$r['id']]);
                continue;   // the old read stays on the desk
            }
            $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE id = ? AND status = 'pending'")->execute([$sid]);
            $done++;
        }
        return $done;
    }

    private function recordLessons(int $expenseId, array $outcome, int $userId): void
    {
        try {
            require_once APP_ROOT . '/Modules/Accounting/Services/PennyLessons.php';
            foreach ($outcome as $field => $o) {
                if (!empty($o['accepted'])) continue;
                if ($field === 'vendor' && trim((string)($o['suggested'] ?? '')) !== '') {
                    // Subject = the vendor as Penny read it (the receipt now carries the owner's).
                    PennyLessons::subject($this->db, 'vendor', (string)$o['suggested'], 'vendor', $o['suggested'], $o['final'] ?? null,
                                          'penny_card', $userId, 'expense', $expenseId);
                    continue;
                }
                PennyLessons::expense($this->db, $expenseId, $field === 'job' ? 'job_id' : $field, $o['suggested'] ?? null, $o['final'] ?? null,
                                      'penny_card', $userId);
            }
        } catch (Throwable $e) {
            error_log('Penny card lessons #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /**
     * Which vendor the receipt should carry. The picked id wins; then a known vendor that
     * is the same business ("HOME DEPOT #7054" is Home Depot). After that:
     *  - a name the owner typed becomes a new vendor on approval (he knows who he paid);
     *  - Penny's read goes through the receipt reader's own gate (gateVendorAutoCreation):
     *    ≥ 70% similar → that vendor; 50–70% → unsure, keep the receipt's current vendor;
     *    otherwise a new vendor on approval.
     * A saved draft never creates a vendor.
     * @return array{id: ?int, keep: bool}  keep = leave the receipt's vendor as it is
     */
    private function vendorFor(string $name, ?int $pickedId, bool $create, bool $ownerTyped = true): array
    {
        $vendors = $this->db->query("SELECT id, name, aliases FROM vendors WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        if ($pickedId && in_array($pickedId, array_map('intval', array_column($vendors, 'id')), true)) {
            return ['id' => $pickedId, 'keep' => false];
        }
        $id = self::pickVendor($vendors, $name);
        if ($id) return ['id' => $id, 'keep' => false];
        if (!$ownerTyped) {
            if (!function_exists('gateVendorAutoCreation')) {
                require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
            }
            $gate = gateVendorAutoCreation($name, $this->db);
            if ($gate['action'] === 'match' && !empty($gate['vendor']['id'])) return ['id' => (int)$gate['vendor']['id'], 'keep' => false];
            if ($gate['action'] !== 'create') return ['id' => null, 'keep' => true];
        }
        if (!$create || mb_strlen($name) < 3) return ['id' => null, 'keep' => false];
        $this->db->prepare("INSERT INTO vendors (name) VALUES (?)")->execute([$name]);
        return ['id' => (int)$this->db->lastInsertId(), 'keep' => false];
    }

    /**
     * An emailed-in receipt approved here: close it in the inbox (as the inbox's own
     * approve does) and learn from what the email reader wrote into the row, when the
     * receipt has no capture baseline of its own. Never blocks the approval.
     */
    private function afterInboxApproval(int $expenseId, array $before): void
    {
        if (($before['source'] ?? '') !== 'email_inbox') return;
        try {
            // (Its lessons were taught by the gate on approval, from this same baseline.)
            $this->db->prepare("UPDATE receipt_inbox_messages SET outcome = 'auto_posted' WHERE expense_id = ? AND outcome = 'pending'")
               ->execute([$expenseId]);
        } catch (Throwable $e) {
            error_log('Penny inbox close-out failed for expense ' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /**
     * Reject a receipt from the card, with a reason (the same reject as the receipts page).
     * Her suggestion is closed as 'rejected' so it never comes back.
     * @return array{ok: bool, message: string}
     */
    public function reject(int $suggestionId, string $reason, array $user): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'message' => 'Say why — a reason is needed to reject'];
        $s = $this->db->prepare("
            SELECT s.expense_id FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
            WHERE s.id = ? AND s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval')
        ");
        $s->execute([$suggestionId]);
        $expenseId = (int)$s->fetchColumn();
        if (!$expenseId) return ['ok' => false, 'message' => 'This receipt has already been handled'];
        (new ExpenseApprovalService($this->db))->reject($expenseId, $user, $reason);
        $this->db->prepare("UPDATE expense_suggestions SET status = 'rejected', decided_by = ?, decided_at = NOW() WHERE id = ?")
           ->execute([(int)$user['id'], $suggestionId]);
        // The reason is a lesson she reads next time for this vendor (PennyLessons::forVendor).
        require_once APP_ROOT . '/Modules/Accounting/Services/PennyLessons.php';
        PennyLessons::expense($this->db, $expenseId, 'rejected', 'prepared', 'rejected', 'penny_reject', (int)$user['id'], $reason);
        return ['ok' => true, 'message' => 'Rejected — ' . $reason];
    }

    /** A known vendor that is the same business as $name: exact name first, then name or alias. */
    public static function pickVendor(array $vendors, string $name): ?int
    {
        foreach ($vendors as $v) {
            if (strcasecmp(trim((string)$v['name']), trim($name)) === 0) return (int)$v['id'];
        }
        foreach ($vendors as $v) {
            $names = array_merge([(string)$v['name']], array_map('trim', explode(',', (string)($v['aliases'] ?? ''))));
            foreach ($names as $n) {
                if ($n !== '' && sameVendorName($name, $n)) return (int)$v['id'];
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Background preparation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Prepare up to $max more receipts (waiting-for-approval first, then the oldest
     * drafts), within the daily cap. Text first; the photo only when the text's
     * amounts don't add up.
     */
    /** @param int[] $hold expense ids not to read yet (possible duplicates) */
    public function prepare(int $max = 2, array $hold = []): array
    {
        // One round at a time: the penny_prepare cron and the card's top-up used to pick the same
        // receipts and pay Claude twice for them (review round 2, 2026-10-12). MySQL named lock,
        // released on return or when the connection ends.
        $got = false;
        try {
            $got = (int)$this->db->query("SELECT GET_LOCK(CONCAT(DATABASE(), '.penny_prepare'), 0)")->fetchColumn() === 1;
        } catch (Throwable $e) {
            $got = true;   // no named locks on this server: behave as before
        }
        if (!$got) return ['prepared' => [], 'capped' => false, 'busy' => true];
        try {
            return $this->prepareLocked($max, $hold);
        } finally {
            try { $this->db->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '.penny_prepare'))"); } catch (Throwable $e) { /* ends with the connection */ }
        }
    }

    private function prepareLocked(int $max, array $hold): array
    {
        $next = $this->nextToPrepare($max, $hold);
        if ($next['room'] === 0) {
            return ['prepared' => [], 'capped' => true];
        }
        // Out-of-date reads first (a lesson since — markRelearn), within the same budget and daily cap.
        $budget = count($next['ids']) ?: min($max, $next['room']);
        $stale = $this->staleReads($budget);
        $redone = $stale ? $this->rereadVendorLocked($stale) : 0;
        $ids = array_slice($next['ids'], 0, max(0, count($next['ids']) - count($stale)));

        $done = $redone ? [['reread' => $redone]] : [];
        foreach ($ids as $id) {
            $r = $this->suggestOne((int)$id);
            $done[] = ['expense_id' => (int)$id, 'error' => $r['error'] ?? null];
        }
        return ['prepared' => $done, 'capped' => false];
    }

    /** One live read: the text first, the photo only when the text's amounts don't add up. */
    private function suggestOne(int $id): array
    {
        $r = $this->bookkeeper()->suggest($id, 'live', false);
        if (empty($r['error']) && self::needsPhoto($r['checks'] ?? [])) {
            $retry = $this->bookkeeper()->suggest($id, 'live', true);
            if (empty($retry['error'])) {
                $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE id = ?")->execute([$r['id']]);
                $r = $retry;
            } else {
                $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE id = ?")->execute([$retry['id'] ?? 0]);
            }
        }
        return $r;
    }

    /** Penny can read a receipt on request (API key + suggestions table). */
    public function canAsk(): bool
    {
        return $this->bookkeeper()->ready();
    }

    /**
     * "Ask Penny" on one receipt (the expense modal, Chevron #355 2026-10-13): Penny prepares only
     * some receipts each day, so a receipt can be open with no read of hers. This reads it now —
     * within the same daily cap as prepare() (bookkeeper_daily_cap) and, inside suggest(), the
     * AI-call ceiling (LlmDailyCap). A receipt that already has a pending read is left alone.
     * @return array{ok: bool, message: string}
     */
    public function askOne(int $expenseId): array
    {
        if (!$this->canAsk()) return ['ok' => false, 'message' => 'Penny isn\'t switched on here'];
        $s = $this->db->prepare("SELECT status, COALESCE(forwarded_to_accounting, 0) AS fwd FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e || !in_array($e['status'], ['draft', 'pending_approval'], true) || (int)$e['fwd'] === 1) {
            return ['ok' => false, 'message' => 'Penny reads open receipts only'];
        }
        $p = $this->db->prepare("SELECT COUNT(*) FROM expense_suggestions WHERE expense_id = ? AND source = 'live' AND status = 'pending'");
        $p->execute([$expenseId]);
        if ((int)$p->fetchColumn() > 0) return ['ok' => true, 'message' => 'Penny has already read this receipt'];
        $today = (int)$this->db->query("SELECT COUNT(*) FROM expense_suggestions WHERE source = 'live' AND created_at >= CURDATE()")->fetchColumn();
        if ($today >= $this->dailyCap()) {
            return ['ok' => false, 'message' => 'Penny has read today\'s limit of receipts — she carries on tomorrow'];
        }
        $r = $this->suggestOne($expenseId);
        if (!empty($r['error'])) {
            if (!empty($r['id'])) {
                $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE id = ?")->execute([$r['id']]);
            }
            return ['ok' => false, 'message' => 'Penny couldn\'t read it: ' . $r['error']];
        }
        return ['ok' => true, 'message' => 'Penny has read the receipt'];
    }

    /**
     * GST to claim back from receipts: [confirmed (approved/forwarded), stuck (waiting)], each the
     * input-tax credit the return would claim — meals at the ITC rate (GstReportService::isMeals),
     * held duplicates left out. Pure (tested).
     * @param array $rows [{id, status, gst_amount, category}]
     * @param int[] $held waiting receipts held back as possible duplicates
     * @param array $settings GstReportService::settings()
     */
    public static function gstClaimBack(array $rows, array $held, array $settings): array
    {
        $held = array_flip(array_map('intval', $held));
        $rate = (float)($settings['meals_rate'] ?? 0.5);
        $cats = $settings['meals_categories'] ?? [];
        $confirmed = 0.0; $stuck = 0.0;
        foreach ($rows as $r) {
            if (isset($held[(int)($r['id'] ?? 0)])) continue;
            $g = round((float)($r['gst_amount'] ?? 0), 2);
            if (GstReportService::isMeals($r['category'] ?? null, $cats)) $g = round($g * $rate, 2);
            if (in_array($r['status'] ?? '', ['approved', 'forwarded'], true)) $confirmed += $g;
            elseif (in_array($r['status'] ?? '', ['draft', 'pending_approval'], true)) $stuck += $g;
        }
        return [round($confirmed, 2), round($stuck, 2)];
    }

    /**
     * How many receipts one round may prepare: the request clamped to 1..5, then to
     * what is left of the daily cap. Pure.
     */
    public static function batchSize(int $requested, int $cap, int $preparedToday): int
    {
        return min(max(1, min(5, $requested)), max(0, $cap - $preparedToday));
    }

    /** The daily cap: ops_settings bookkeeper_daily_cap, else DEFAULT_DAILY_CAP. */
    public function dailyCap(): int
    {
        $cap = self::DEFAULT_DAILY_CAP;
        try {
            $c = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'bookkeeper_daily_cap'")->fetchColumn();
            if ($c !== false && $c !== '') $cap = (int)$c;
        } catch (Throwable $e) { /* default */ }
        return $cap;
    }

    /**
     * Which receipts the next round would prepare (waiting-for-approval first, then the
     * newest arrivals, then the oldest drafts) — no API call, nothing written. Used by
     * prepare() and by the penny_prepare cron's --dry-run.
     * @param int[] $hold expense ids not to read yet (possible duplicates)
     * @return array{ids:int[], cap:int, today:int, room:int}
     */
    public function nextToPrepare(int $max = 2, array $hold = []): array
    {
        $cap = $this->dailyCap();
        $today = (int)$this->db->query("SELECT COUNT(*) FROM expense_suggestions WHERE source = 'live' AND created_at >= CURDATE()")->fetchColumn();
        $room = max(0, $cap - $today);
        $n = self::batchSize($max, $cap, $today);
        if ($n === 0) {
            return ['ids' => [], 'cap' => $cap, 'today' => $today, 'room' => $room];
        }

        $ids = $this->db->query("
            SELECT e.id FROM expenses e
            WHERE e.status IN ('pending_approval', 'draft')
              AND e.raw_ocr_json IS NOT NULL AND e.raw_ocr_json <> ''
              AND NOT EXISTS (SELECT 1 FROM expense_suggestions s
                              WHERE s.expense_id = e.id AND s.source = 'live' AND s.status IN ('pending', 'error', 'kept'))
              " . ($hold ? 'AND e.id NOT IN (' . implode(',', array_map('intval', $hold)) . ')' : '') . "
            ORDER BY (e.status = 'pending_approval') DESC,
                     (e.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)) DESC,   -- just arrived (emailed) before the old backlog
                     e.expense_date ASC, e.id ASC
            LIMIT " . $n
        )->fetchAll(PDO::FETCH_COLUMN);
        return ['ids' => array_map('intval', $ids), 'cap' => $cap, 'today' => $today, 'room' => $room];
    }

    /** YYYY-MM-DD that is a real date, not in the future and not absurdly old; else null. Pure. */
    public static function validDate($v): ?string
    {
        $v = trim((string)$v);
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) return null;
        if ($v > date('Y-m-d', strtotime('+1 day')) || $v < date('Y-m-d', strtotime('-7 years'))) return null;
        return $v;
    }

    /**
     * Re-check: Penny reads one receipt again, with the photo (~5¢), and her new read
     * replaces the old one on the desk. The old one is kept as 'superseded'. Owner-asked,
     * so the daily cap doesn't apply.
     * @return array{ok: bool, message: string}
     */
    public function recheck(int $suggestionId): array
    {
        $s = $this->db->prepare("
            SELECT s.expense_id FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
            WHERE s.id = ? AND s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval')
        ");
        $s->execute([$suggestionId]);
        $expenseId = (int)$s->fetchColumn();
        if (!$expenseId) {
            return ['ok' => false, 'message' => 'This receipt has already been handled'];
        }
        $r = $this->bookkeeper()->suggest($expenseId, 'live', true);
        if (!empty($r['error'])) {
            if (!empty($r['id'])) {
                $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE id = ?")->execute([$r['id']]);
            }
            return ['ok' => false, 'message' => 'Penny couldn\'t re-read it: ' . $r['error']];
        }
        $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE id = ?")->execute([$suggestionId]);
        return ['ok' => true, 'message' => 'Penny took another look', 'expense_id' => $expenseId];
    }

    /** The text read didn't add up — worth a second look with the photo. Pure. */
    public static function needsPhoto(array $checks): bool
    {
        foreach ($checks as $c) {
            if (($c['check'] ?? '') === 'sum' && empty($c['ok'])) return true;
        }
        return !in_array('sum', array_column($checks, 'check'), true);   // amounts missing entirely
    }
}
