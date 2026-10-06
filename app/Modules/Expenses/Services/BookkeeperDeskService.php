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
 * receipt reader (learnFromConfirmedExpense). Line items are shown, not changed, in
 * this version.
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

    public function __construct(PDO $db, ?ReceiptBookkeeperService $bookkeeper = null)
    {
        $this->db = $db;
        $this->bookkeeper = $bookkeeper;
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

        $tokens = $this->db->prepare("SELECT COALESCE(SUM(input_tokens),0), COALESCE(SUM(output_tokens),0) FROM expense_suggestions WHERE created_at >= ?");
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

        // GST: the last 60 days — confirmed (claimable now) vs stuck in unapproved receipts.
        $since = date('Y-m-d', strtotime('-60 days'));
        $gst = $this->db->prepare("
            SELECT COALESCE(SUM(CASE WHEN status IN ('approved','forwarded') THEN gst_amount END),0),
                   COALESCE(SUM(CASE WHEN status IN ('draft','pending_approval') THEN gst_amount END),0)
            FROM expenses WHERE expense_date >= ?
        ");
        $gst->execute([$since]);
        [$gstConfirmed, $gstStuck] = array_map('floatval', $gst->fetch(PDO::FETCH_NUM) ?: [0, 0]);

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
                   e.id AS expense_id, e.status, e.expense_date, e.total, e.receipt_media_id,
                   COALESCE(v.name, e.vendor_name_raw) AS vendor, e.vendor_id, u.full_name AS submitted_by
            FROM expense_suggestions s
            JOIN expenses e ON e.id = s.expense_id
            LEFT JOIN vendors v ON v.id = e.vendor_id
            LEFT JOIN users u ON u.id = e.created_by
            WHERE s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval'){$holdSql}
            ORDER BY (s.outcome_json IS NOT NULL) ASC, (e.status = 'pending_approval') DESC, e.expense_date ASC, e.id ASC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);

        $jobTitles = $this->jobTitles($rows);
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
                'current'       => json_decode((string)$r['current_json'], true) ?: [],
                // The owner's saved-but-not-approved edits, if any — the form reopens with them.
                'saved_draft'   => (json_decode((string)($r['outcome_json'] ?? ''), true) ?: [])['draft'] ?? null,
            ];
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
        return signReceiptUrl($mediaId, 3600);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Decisions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Final values: Penny's suggestion, with the owner's edits laid over it. Pure.
     * @return array{final: array, outcome: array<string, array{suggested: mixed, final: mixed, accepted: bool}>}
     */
    public static function resolveFinal(array $suggestion, array $overrides): array
    {
        $fields = ['vendor', 'accounting_category', 'asset_tag', 'job', 'subtotal', 'gst', 'pst', 'total'];
        $final = [];
        $outcome = [];
        foreach ($fields as $f) {
            $suggested = $suggestion[$f]['value'] ?? null;
            $value = array_key_exists($f, $overrides) ? $overrides[$f] : $suggested;
            if (in_array($f, ['subtotal', 'gst', 'pst', 'total'], true)) {
                $value = $value === null || $value === '' ? null : round((float)$value, 2);
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

        $resolved = self::resolveFinal(json_decode((string)$row['suggestion_json'], true) ?: [], $overrides);
        $f = $resolved['final'];
        if ($f['accounting_category'] !== null && !in_array($f['accounting_category'], EXPENSE_ACCOUNTING_CATEGORIES, true)) {
            return ['ok' => false, 'message' => 'Unknown category'];
        }
        if ($f['asset_tag'] !== null && !in_array($f['asset_tag'], ReceiptBookkeeperRules::TAGS, true)) {
            return ['ok' => false, 'message' => 'Unknown tag'];
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
        $this->db->beginTransaction();
        try {
            if ($date !== null) {
                $this->db->prepare("UPDATE expenses SET expense_date = ? WHERE id = ?")->execute([$date, (int)$row['expense_id']]);
            }
            if ($vendorChanged) {
                $vendorId = $this->vendorFor($f['vendor'], $pickedVendor, $approve);
                $this->db->prepare("UPDATE expenses SET vendor_id = ?, vendor_name_raw = ? WHERE id = ?")
                   ->execute([$vendorId, $f['vendor'], (int)$row['expense_id']]);
            }
            $this->db->prepare("
                UPDATE expenses SET
                    accounting_category = COALESCE(?, accounting_category),
                    asset_tag = ?,
                    job_id = ?,
                    property_id = COALESCE(?, property_id),
                    amount = COALESCE(?, amount),
                    gst_amount = COALESCE(?, gst_amount),
                    pst_amount = COALESCE(?, pst_amount),
                    total = COALESCE(?, total),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $f['accounting_category'], $f['asset_tag'], $f['job'], $propertyId,
                $f['subtotal'], $f['gst'], $f['pst'], $f['total'], (int)$row['expense_id'],
            ]);
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

        if ($blocked) {
            return ['ok' => true, 'approved' => false, 'saved_draft' => true, 'blocked' => true,
                    'message' => "Not approved — you submitted this receipt, and the self-approval rule stops you approving your own. Your edits are saved. Turn on \"Allow approving own submitted expenses\" for yourself in Team, or have someone else approve it."];
        }
        if (!$approve) {
            return ['ok' => true, 'approved' => false, 'saved_draft' => true, 'message' => 'Saved as a draft — it stays here until you approve it'];
        }

        // Approval is separate: the self-approval rule still applies, and approval is
        // what teaches the receipt reader.
        try {
            (new ExpenseApprovalService($this->db))->approve((int)$row['expense_id'], $user);
            return ['ok' => true, 'approved' => true, 'message' => $allAccepted ? 'Approved' : 'Approved with your changes'];
        } catch (Throwable $e) {
            // Not approved after all: put it back on the desk as a saved draft, undecided.
            $this->db->prepare("UPDATE expense_suggestions SET status = 'pending', decided_by = NULL, decided_at = NULL, outcome_json = ? WHERE id = ?")
               ->execute([json_encode(['draft' => $f, 'saved_by' => (int)$user['id'], 'saved_at' => date('c')]), $suggestionId]);
            return ['ok' => true, 'approved' => false, 'saved_draft' => true, 'blocked' => true, 'message' => 'Not approved: ' . $e->getMessage() . ' — your edits are saved'];
        }
    }

    /**
     * The vendor the owner chose: the picked id, else a known vendor whose name or alias
     * is the same business ("HOME DEPOT #7054" is Home Depot), else — on approval — a new
     * vendor, so the next receipt from them is recognised. A saved draft never creates one.
     */
    private function vendorFor(string $name, ?int $pickedId, bool $create): ?int
    {
        $vendors = $this->db->query("SELECT id, name, aliases FROM vendors WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        if ($pickedId && in_array($pickedId, array_map('intval', array_column($vendors, 'id')), true)) {
            return $pickedId;
        }
        $id = self::pickVendor($vendors, $name);
        if ($id || !$create || mb_strlen($name) < 3) {
            return $id;
        }
        $this->db->prepare("INSERT INTO vendors (name) VALUES (?)")->execute([$name]);
        return (int)$this->db->lastInsertId();
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
        $max = max(1, min(5, $max));
        $cap = self::DEFAULT_DAILY_CAP;
        try {
            $c = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'bookkeeper_daily_cap'")->fetchColumn();
            if ($c !== false && $c !== '') $cap = (int)$c;
        } catch (Throwable $e) { /* default */ }
        $today = (int)$this->db->query("SELECT COUNT(*) FROM expense_suggestions WHERE source = 'live' AND created_at >= CURDATE()")->fetchColumn();
        $room = max(0, $cap - $today);
        if ($room === 0) {
            return ['prepared' => [], 'capped' => true];
        }

        $ids = $this->db->query("
            SELECT e.id FROM expenses e
            WHERE e.status IN ('pending_approval', 'draft')
              AND e.raw_ocr_json IS NOT NULL AND e.raw_ocr_json <> ''
              AND NOT EXISTS (SELECT 1 FROM expense_suggestions s
                              WHERE s.expense_id = e.id AND s.source = 'live' AND s.status IN ('pending', 'error'))
              " . ($hold ? 'AND e.id NOT IN (' . implode(',', array_map('intval', $hold)) . ')' : '') . "
            ORDER BY (e.status = 'pending_approval') DESC, e.expense_date ASC, e.id ASC
            LIMIT " . min($max, $room)
        )->fetchAll(PDO::FETCH_COLUMN);

        $done = [];
        foreach ($ids as $id) {
            $r = $this->bookkeeper()->suggest((int)$id, 'live', false);
            if (empty($r['error']) && self::needsPhoto($r['checks'] ?? [])) {
                $retry = $this->bookkeeper()->suggest((int)$id, 'live', true);
                if (empty($retry['error'])) {
                    $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE id = ?")->execute([$r['id']]);
                    $r = $retry;
                } else {
                    $this->db->prepare("UPDATE expense_suggestions SET status = 'superseded' WHERE id = ?")->execute([$retry['id'] ?? 0]);
                }
            }
            $done[] = ['expense_id' => (int)$id, 'error' => $r['error'] ?? null];
        }
        return ['prepared' => $done, 'capped' => false];
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
