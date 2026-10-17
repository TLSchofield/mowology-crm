<?php
/**
 * BankLineReceipts — one bank line paid by several receipts (2026-10-16).
 *
 * Why: a shop rings two tickets through on one card swipe (Champion, Aug 31: sharpening $134.40 + blades
 * $50.00 = one $184.40 debit). The books linked a line to ONE receipt, so neither receipt could be linked,
 * both were sent back to draft, and the line's own posting stood in for them without the $6 GST credit.
 * QuickBooks "Find match" lets you tick several; this is the same, with one rule QuickBooks lacks: the
 * receipts must add up to the line EXACTLY. A gap means a receipt is wrong (Champion didn't charge tax on
 * the blades) — fix that receipt, never book the gap as a "discount" that keeps GST you never paid.
 *
 * Shape: accounting_transactions.matched_expense_id stays the line's MAIN receipt (every existing reader keeps
 * working); bank_line_receipts (migration 1372) holds the others. LedgerService::retireLinkedBankEntries()
 * retires the line's own posting once every receipt of the group is booked and they add up to the line.
 *
 * Global namespace, no autoloader: require_once + new. Pure rules are static and unit tested.
 */
declare(strict_types=1);

/** A refusal meant for the person (amounts apart, already matched) — safe to show as is. */
class BankLineReceiptsRefused extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 422);   // ApiResponse::status() → 422, message shown (ApiResponse::isSafe)
    }
}

class BankLineReceipts
{
    private PDO $db;
    private static ?WeakMap $hasTable = null;   // per connection (object ids are reused)

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure ──────────────────────────────────────────────────────────────────

    /** The gap between the receipts and the line, to the cent (receipts − line). */
    public static function gap(array $totals, float $lineAmount): float
    {
        return round(array_sum(array_map(fn($t) => round((float)$t, 2), $totals)) - round(abs($lineAmount), 2), 2);
    }

    /** Several receipts (2+) that add up to the line exactly. */
    public static function fits(array $totals, float $lineAmount): bool
    {
        return count($totals) >= 2 && abs(self::gap($totals, $lineAmount)) < 0.005;
    }

    /** The main receipt: the largest, then the oldest id (the others go in bank_line_receipts). */
    public static function mainOf(array $totalsById): int
    {
        $ids = array_keys($totalsById);
        usort($ids, fn($a, $b) => [round((float)$totalsById[$b], 2), $a] <=> [round((float)$totalsById[$a], 2), $b]);
        return (int)$ids[0];
    }

    // ── SQL helpers ───────────────────────────────────────────────────────────

    public static function tableExists(PDO $db): bool
    {
        self::$hasTable ??= new WeakMap();
        if (!isset(self::$hasTable[$db])) {
            try {
                $db->query("SELECT 1 FROM bank_line_receipts LIMIT 1");
                self::$hasTable[$db] = true;
            } catch (Throwable $e) {
                self::$hasTable[$db] = false;
            }
        }
        return self::$hasTable[$db];
    }

    /** SQL condition: the receipt $expr is linked to a bank line, as the main receipt or one of several. */
    public static function linkedSql(PDO $db, string $expr): string
    {
        $main = "EXISTS (SELECT 1 FROM accounting_transactions blt WHERE blt.matched_expense_id = $expr)";
        return self::tableExists($db) ? "($main OR EXISTS (SELECT 1 FROM bank_line_receipts blr WHERE blr.expense_id = $expr))" : $main;
    }

    // ── Reads ─────────────────────────────────────────────────────────────────

    /** The bank line a receipt is linked to (main or extra), or null. */
    public function lineFor(int $expenseId): ?int
    {
        $s = $this->db->prepare("SELECT id FROM accounting_transactions WHERE matched_expense_id = ? LIMIT 1");
        $s->execute([$expenseId]);
        $id = $s->fetchColumn();
        if ($id === false && self::tableExists($this->db)) {
            $s = $this->db->prepare("SELECT transaction_id FROM bank_line_receipts WHERE expense_id = ? LIMIT 1");
            $s->execute([$expenseId]);
            $id = $s->fetchColumn();
        }
        return $id === false || $id === null ? null : (int)$id;
    }

    /** Extra receipt ids on a line (not the main one), oldest link first. */
    public function extras(int $transactionId): array
    {
        if (!self::tableExists($this->db)) return [];
        $s = $this->db->prepare("SELECT expense_id FROM bank_line_receipts WHERE transaction_id = ? ORDER BY id");
        $s->execute([$transactionId]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The group a receipt belongs to when its line was paid by several: ['line' => id, 'main' => id, 'receipts' => [main, …]].
     * Null when the receipt is on a line alone, or on none.
     */
    public function group(int $expenseId): ?array
    {
        if (!self::tableExists($this->db)) return null;
        $line = $this->lineFor($expenseId);
        if ($line === null) return null;
        $extras = $this->extras($line);
        if (!$extras) return null;
        $s = $this->db->prepare("SELECT matched_expense_id FROM accounting_transactions WHERE id = ?");
        $s->execute([$line]);
        $main = (int)$s->fetchColumn();
        if ($main <= 0) return null;
        return ['line' => $line, 'main' => $main, 'receipts' => array_values(array_unique(array_merge([$main], $extras)))];
    }

    /**
     * Receipts that could be among several paying this line: not linked anywhere, not rejected, dated within
     * ±7 days, each smaller than the line. Same vendor (a word of it on the statement line) first.
     */
    public function candidates(int $transactionId, int $limit = 12): array
    {
        $t = $this->db->prepare("SELECT id, transaction_date, amount, description, matched_expense_id FROM accounting_transactions
                                 WHERE id = ? AND type = 'expense' AND reference_type = 'bank_import'");
        $t->execute([$transactionId]);
        $line = $t->fetch(PDO::FETCH_ASSOC);
        if (!$line) return [];
        $amount = abs((float)$line['amount']);
        $s = $this->db->prepare("
            SELECT e.id, e.expense_date, e.total, e.gst_amount, e.pst_amount, e.status, e.description, e.accounting_category,
                   COALESCE(v.name, e.vendor_name_raw) AS vendor
            FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.status NOT IN ('rejected') AND e.total > 0 AND e.total < ?
              AND e.expense_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
              AND NOT " . self::linkedSql($this->db, 'e.id') . "
            ORDER BY e.expense_date, e.id
            LIMIT 200");
        $s->execute([$amount + 0.005, $line['transaction_date'], $line['transaction_date']]);
        $desc = strtolower((string)$line['description']);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $words = array_filter(preg_split('/[^a-z0-9]+/', strtolower((string)$r['vendor'])), fn($w) => strlen($w) >= 4);
            $vendor = false;
            foreach ($words as $w) if (str_contains($desc, $w)) { $vendor = true; break; }
            $days = abs((int)round((strtotime((string)$r['expense_date']) - strtotime((string)$line['transaction_date'])) / 86400));
            $out[] = ['expense_id' => (int)$r['id'], 'date' => (string)$r['expense_date'], 'vendor' => (string)$r['vendor'],
                      'total' => round((float)$r['total'], 2), 'gst' => round((float)$r['gst_amount'], 2), 'pst' => round((float)$r['pst_amount'], 2),
                      'status' => (string)$r['status'], 'description' => (string)($r['description'] ?? ''),
                      'same_vendor' => $vendor, 'days' => $days];
        }
        usort($out, fn($a, $b) => [!$a['same_vendor'], $a['days'], $a['expense_id']] <=> [!$b['same_vendor'], $b['days'], $b['expense_id']]);
        return array_slice($out, 0, $limit);
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /**
     * Link several receipts to one bank line. They must add up to it exactly; nothing changes otherwise.
     * The line's own posting is retired once they're all booked (now, or when the last is approved).
     * @param int[] $expenseIds
     */
    public function attach(int $transactionId, array $expenseIds, int $userId): array
    {
        if (!self::tableExists($this->db)) throw new BankLineReceiptsRefused('Several receipts on one line needs migration 1372.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $expenseIds))));
        if (count($ids) < 2) throw new BankLineReceiptsRefused('Pick at least two receipts (one receipt uses Attach).');

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $t = $this->db->prepare("SELECT id, amount, matched_expense_id FROM accounting_transactions
                                     WHERE id = ? AND type = 'expense' AND reference_type = 'bank_import'" . ($this->isMysql() ? ' FOR UPDATE' : ''));
            $t->execute([$transactionId]);
            $line = $t->fetch(PDO::FETCH_ASSOC);
            if (!$line) throw new BankLineReceiptsRefused('Bank line not found.');
            if (!empty($line['matched_expense_id']) || $this->extras($transactionId)) {
                throw new BankLineReceiptsRefused('This bank line already has a receipt. Remove that match first.');
            }
            // The receipts are locked in id order and their links read with locking reads, so two people
            // matching the same receipt to two lines at once can't both win (Codex review 2026-10-16).
            sort($ids);
            $lock = $this->isMysql() ? ' FOR UPDATE' : '';
            $totals = [];
            $e = $this->db->prepare("SELECT id, total, status FROM expenses WHERE id = ?" . $lock);
            $onMain = $this->db->prepare("SELECT id FROM accounting_transactions WHERE matched_expense_id = ? LIMIT 1" . $lock);
            $onExtra = $this->db->prepare("SELECT transaction_id FROM bank_line_receipts WHERE expense_id = ? LIMIT 1" . $lock);
            foreach ($ids as $id) {
                $e->execute([$id]);
                $row = $e->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new BankLineReceiptsRefused('Receipt #' . $id . ' not found.');
                if ($row['status'] === 'rejected') throw new BankLineReceiptsRefused('Receipt #' . $id . ' is rejected.');
                $onMain->execute([$id]);
                $onExtra->execute([$id]);
                if ($onMain->fetchColumn() !== false || $onExtra->fetchColumn() !== false) {
                    throw new BankLineReceiptsRefused('Receipt #' . $id . ' is already matched to a bank line.');
                }
                $totals[$id] = round((float)$row['total'], 2);
            }
            $gap = self::gap(array_values($totals), (float)$line['amount']);
            if (abs($gap) >= 0.005) {
                throw new BankLineReceiptsRefused('The receipts add up to $' . number_format(array_sum($totals), 2) . ' and the bank line is $'
                    . number_format(abs((float)$line['amount']), 2) . ' ($' . number_format(abs($gap), 2) . ' apart). '
                    . 'Correct the receipt that is wrong and save it first — for example tax a shop didn\'t charge.');
            }
            $main = self::mainOf($totals);
            $now = date('Y-m-d H:i:s');
            $u = $this->db->prepare("UPDATE accounting_transactions SET status = 'reconciled', matched_expense_id = ?, match_confidence = 0,
                                            matched_at = ?, matched_by = ? WHERE id = ? AND matched_expense_id IS NULL");
            $u->execute([$main, $now, (string)$userId, $transactionId]);
            if ($u->rowCount() !== 1) throw new BankLineReceiptsRefused('Someone else matched this bank line just now — reload and look again.');
            $ins = $this->db->prepare("INSERT INTO bank_line_receipts (transaction_id, expense_id, created_by, created_at) VALUES (?, ?, ?, ?)");
            foreach ($ids as $id) {
                if ($id !== $main) $ins->execute([$transactionId, $id, $userId ?: null, $now]);
            }
            // The line is the truth about where the money came from — for each receipt (as attachExpenseMatch).
            require_once __DIR__ . '/ReceiptFundingCheck.php';
            $funding = new ReceiptFundingCheck($this->db);
            foreach ($ids as $id) {
                try { $funding->onLink($id, $transactionId, $userId ?: null); } catch (Throwable $x) { error_log('Group funding check #' . $id . ': ' . $x->getMessage()); }
            }
            // The line's own posting becomes whatever the booked receipts don't cover (all of it while they're drafts).
            require_once __DIR__ . '/LedgerService.php';
            $retired = (new LedgerService($this->db))->settleGroupLine($transactionId, $userId ?: null);
            if ($own) $this->db->commit();
        } catch (Throwable $x) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $x;
        }
        return ['transaction_id' => $transactionId, 'main' => $main, 'receipts' => $ids, 'bank_postings_changed' => $retired];
    }

    /** Forget a line's extra receipts (its main match is removed by BankImportService::detachExpenseMatch). */
    public function detachExtras(int $transactionId): array
    {
        $ids = $this->extras($transactionId);
        if ($ids) $this->db->prepare("DELETE FROM bank_line_receipts WHERE transaction_id = ?")->execute([$transactionId]);
        return $ids;
    }

    /**
     * A receipt that is going away (deleted): off its line. An extra is dropped; a main hands the line to the
     * next receipt of the group, or the line is unmatched. Returns the line, so the caller can settle it.
     */
    public function forget(int $expenseId): ?int
    {
        $line = $this->lineFor($expenseId);
        if ($line === null) return null;
        // Line first, as every settlement does (Codex final pass 2026-10-16).
        if ($this->isMysql() && $this->db->inTransaction()) {
            $this->db->prepare("SELECT id FROM accounting_transactions WHERE id = ? FOR UPDATE")->execute([$line]);
        }
        if (self::tableExists($this->db)) {
            $this->db->prepare("DELETE FROM bank_line_receipts WHERE expense_id = ?")->execute([$expenseId]);
        }
        $s = $this->db->prepare("SELECT matched_expense_id FROM accounting_transactions WHERE id = ?");
        $s->execute([$line]);
        if ((int)$s->fetchColumn() === $expenseId) {
            $next = $this->extras($line)[0] ?? null;
            if ($next !== null) {
                $this->db->prepare("DELETE FROM bank_line_receipts WHERE expense_id = ?")->execute([$next]);
                $this->db->prepare("UPDATE accounting_transactions SET matched_expense_id = ? WHERE id = ?")->execute([$next, $line]);
            } else {
                $this->db->prepare("UPDATE accounting_transactions SET matched_expense_id = NULL, status = 'cleared', match_confidence = NULL,
                                           matched_at = NULL, matched_by = NULL WHERE id = ?")->execute([$line]);
            }
        }
        return $line;
    }

    private function isMysql(): bool
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
