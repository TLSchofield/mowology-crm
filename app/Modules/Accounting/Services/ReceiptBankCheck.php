<?php
/**
 * ReceiptBankCheck — an approved receipt paid by debit or card must have its line on the statement
 * (Tim, 2026-10-15: "if a bank line doesn't exist for it it should return to draft … with a note to
 * check other methods"; "if the bank statement for its date has not been imported yet it waits";
 * "if we find the transaction though this would mean a code error or import error"; "where two
 * receipts appear with only one bank line then duplication should be addressed").
 * Plan: docs/plans/2026-10-15-receipt-bank-line-check.md.
 *
 * For each approved receipt (2026 on, not sent to the accountant) whose payment method spends from
 * chequing (debit / none) or the credit card, with no statement line linked:
 *   wait       the statement for its date (+ AFTER days) isn't imported for that account yet → nothing;
 *   duplicate  another receipt has the same photo → Penny says so (rejecting one is Tim's call);
 *   link       one clear free line (exact amount, −BEFORE/+AFTER days, vendor on the statement, no other
 *              receipt that could be it) → linked through BankImportService::attachExpenseMatch() (funding
 *              check follows) and the line's own posting retired — logged as a matcher miss;
 *   ask        a line exists but not clearly (name differs, two candidates, or it's already another
 *              receipt's — two receipts, one line) → Penny says which, nothing moves;
 *   not_found  nothing on any statement → back to draft through ExpenseGate (its entry reversed), with a
 *              note to check card, cash, cheque or a personal payment, and a Penny notice.
 * Penny's notes reuse her payment-source card (penny_questions kind 'payment_source', category 'notice').
 *
 * Runs nightly (sync-ledger cron) — previewed and committed by hand the first time.
 * Penny never moves money: linking and re-opening change records, never a payment.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';

class ReceiptBankCheck
{
    public const BEFORE = 3;           // a card line can post a few days before the receipt's date (pre-auth)
    public const AFTER = 5;            // …and usually posts up to a few days after
    public const SOURCE = 'bank_check';

    private PDO $db;
    private LedgerService $ledger;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ledger = new LedgerService($db);
    }

    // ── Pure ──────────────────────────────────────────────────────────────────

    /** Which statement account a payment method spends from: '1010', '2400', or null (cash, cheque, e-Transfer, personal). */
    public static function statementCodeFor(?string $method): ?string
    {
        $pm = trim((string)preg_replace('/[\s\-]+/', '_', strtolower(trim((string)$method))), '_');
        if (in_array($pm, ['owner_paid', 'paid_personally', 'personal_card'], true)) return null;
        $code = LedgerSyncService::fundingCodeFor($pm);
        if ($code === LedgerService::ACC_CREDIT_CARD) return $code;
        if ($pm === '' || str_contains($pm, 'debit')) return LedgerService::ACC_BANK;
        return null;                                        // cash, cheque, e-Transfer: no card or debit line to find
    }

    /**
     * The outcome for one receipt.
     * @param bool  $covered   the statement for its paying account covers date .. date + AFTER
     * @param array $lines     exact-amount statement lines in the window: {id, free: bool, vendor: bool, other_receipt: ?int, same_photo: bool,
     *                         own: bool (on the account the receipt says it was paid from), purchase: bool (type 'expense' — linkable)}
     * @param int   $rivals    other unlinked, non-rejected receipts with the same total in the window
     * @param ?int  $twin      another receipt with the same photo (or null)
     * @return array{outcome: string, line?: int, receipt?: int}
     */
    public static function decide(bool $covered, array $lines, int $rivals, ?int $twin): array
    {
        if ($twin !== null) return ['outcome' => 'duplicate', 'receipt' => $twin];
        foreach ($lines as $l) {
            if (!$l['free'] && !empty($l['same_photo'])) return ['outcome' => 'duplicate', 'receipt' => (int)$l['other_receipt']];
        }
        if (!$covered) return ['outcome' => 'wait'];
        $free = array_values(array_filter($lines, fn($l) => $l['free']));
        // Linked on its own only when it is the one free purchase line, on the account the receipt says, with the vendor on it
        // (Codex review 2026-10-15: a line on another account, or a transfer, is a question).
        if (count($free) === 1 && $free[0]['vendor'] && ($free[0]['own'] ?? true) && ($free[0]['purchase'] ?? true) && $rivals === 0) {
            return ['outcome' => 'link', 'line' => (int)$free[0]['id']];
        }
        if ($lines) {
            $first = $free[0] ?? $lines[0];
            return ['outcome' => 'ask', 'line' => (int)$first['id']] + (!$first['free'] ? ['receipt' => (int)$first['other_receipt']] : [])
                 + (!($first['own'] ?? true) ? ['elsewhere' => true] : []);
        }
        return ['outcome' => 'not_found'];
    }

    // ── Run ───────────────────────────────────────────────────────────────────

    /**
     * Check every receipt that should have a statement line.
     * @return array{counts: array<string,int>, items: list<array>}
     */
    public function run(bool $apply, ?int $userId = null): array
    {
        $rows = $this->db->query("
            SELECT e.id FROM expenses e
            WHERE e.status = 'approved' AND COALESCE(e.forwarded_to_accounting, 0) = 0 AND e.total > 0
              AND e.expense_date >= '" . LedgerService::FY_START . "'
              AND NOT EXISTS (SELECT 1 FROM accounting_transactions t WHERE t.matched_expense_id = e.id)
            ORDER BY e.expense_date, e.id")->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];
        $items = [];
        foreach ($rows as $id) {
            $r = $this->check((int)$id, $apply, $userId);
            if ($r === null) continue;
            $counts[$r['outcome']] = ($counts[$r['outcome']] ?? 0) + 1;
            if ($r['outcome'] !== 'wait') $items[] = $r;
        }
        return ['counts' => $counts, 'items' => $items];
    }

    /** One receipt. Null when it isn't one this check looks at. */
    public function check(int $expenseId, bool $apply, ?int $userId = null): ?array
    {
        $s = $this->db->prepare("SELECT id, expense_date, total, payment_method, vendor_name_raw, receipt_media_id, status,
                                        COALESCE(forwarded_to_accounting, 0) AS fwd
                                 FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e || $e['status'] !== 'approved' || (int)$e['fwd'] === 1 || (string)$e['expense_date'] < LedgerService::FY_START) return null;
        $code = self::statementCodeFor($e['payment_method'] ?? null);
        if ($code === null) return null;
        $linked = $this->db->prepare("SELECT 1 FROM accounting_transactions WHERE matched_expense_id = ? LIMIT 1");
        $linked->execute([$expenseId]);
        if ($linked->fetchColumn()) return null;

        $accountId = $this->ledger->accountId($code);
        $date = (string)$e['expense_date'];
        $lines = $this->lines($e, $accountId);
        $decision = self::decide($this->covered($accountId, $date), $lines, $this->rivals($e), $this->twin($e));
        $out = ['expense_id' => $expenseId, 'date' => $date, 'vendor' => (string)$e['vendor_name_raw'], 'total' => round((float)$e['total'], 2),
                'method' => (string)$e['payment_method'], 'account' => $code] + $decision;
        if ($apply) $out['done'] = $this->act($e, $decision, $code, $userId);
        return $out;
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    private function act(array $e, array $d, string $code, ?int $userId): string
    {
        $id = (int)$e['id'];
        $what = 'Receipt #' . $id . ' (' . trim((string)$e['vendor_name_raw']) . ', $' . number_format((float)$e['total'], 2) . ', '
              . date('M j', strtotime((string)$e['expense_date'])) . ')';
        $where = $code === LedgerService::ACC_CREDIT_CARD ? 'credit card' : 'chequing';
        switch ($d['outcome']) {
            case 'link':
                require_once __DIR__ . '/BankImportService.php';
                // Link, retire the line's own posting and log it — all or nothing (Codex review 2026-10-15).
                $this->atomically(function () use ($d, $id, $userId, $what) {
                    (new BankImportService($this->db))->attachExpenseMatch((int)$d['line'], $id, (int)($userId ?? 0));
                    $this->ledger->retireLinkedBankEntries($id, $userId);
                    $this->db->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)")
                       ->execute([$userId, 'Receipt linked to its bank line late', $what . ' had a clear statement line (#' . (int)$d['line']
                           . ') that was never linked — linked now. A matcher miss: the receipt was approved after the line was imported.']);
                });
                return 'linked';
            case 'duplicate':
                $this->note($id, (float)$e['total'], (string)$e['vendor_name_raw'],
                    $what . ' looks like a copy of receipt #' . (int)$d['receipt'] . ' — the same photo. If so, reject one of them; both are in the books.');
                return 'noted';
            case 'ask':
                $this->note($id, (float)$e['total'], (string)$e['vendor_name_raw'], $what . ' has no statement line linked. Line #' . (int)$d['line']
                    . ' could be it' . (!empty($d['elsewhere']) ? ' — on ' . ($where === 'chequing' ? 'the credit card' : 'chequing') . ', so was it paid that way?' : '')
                    . (isset($d['receipt']) ? ', but it is already linked to receipt #' . (int)$d['receipt'] . ' — one may be a duplicate' : '')
                    . '. Have a look side by side; I haven\'t changed anything.');
                return 'noted';
            case 'not_found':
                require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseGate.php';
                $note = 'Back to draft (' . date('Y-m-d') . '): no ' . $where . ' line for this on the statement. Check whether it was paid by '
                      . ($code === LedgerService::ACC_CREDIT_CARD ? 'debit' : 'credit card') . ', cash, cheque or personally, then approve it again.';
                $n = $this->db->prepare("SELECT notes FROM expenses WHERE id = ?");
                $n->execute([$id]);
                $notes = trim((string)$n->fetchColumn());
                // Inside one transaction the gate reverses the entry strictly: a failed reversal undoes the re-open (Codex review 2026-10-15).
                $this->atomically(function () use ($id, $notes, $note, $userId) {
                    (new ExpenseGate($this->db))->apply($id, ['status' => 'draft', 'notes' => trim($notes . "\n" . $note)],
                        ['id' => $userId, 'kind' => 'penny'], self::SOURCE, ['transition' => 'reopen']);
                    if ((new LedgerService($this->db))->findEntryIdBySource('expense', $id) !== null) {
                        throw new RuntimeException('Receipt #' . $id . ': its journal entry is still live after re-opening');
                    }
                });
                $this->note($id, (float)$e['total'], (string)$e['vendor_name_raw'],
                    $what . ' was approved as ' . $where . ', but the statement has no line for it. I\'ve put it back to draft — was it paid another way?');
                return 'reopened';
        }
        return 'none';
    }

    private function note(int $expenseId, float $total, string $vendor, string $text): void
    {
        try {
            $s = $this->db->prepare("SELECT id, question, status FROM penny_questions WHERE kind = 'payment_source' AND expense_id = ?");
            $s->execute([$expenseId]);
            $q = $s->fetch(PDO::FETCH_ASSOC);
            $qid = $q ? (int)$q['id'] : null;
            if ($q && (string)$q['question'] === $text) return;   // already said (and maybe seen) — never nag nightly
            if ($qid) {
                $this->db->prepare("UPDATE penny_questions SET category = 'notice', subject = NULL, amount = ?, vendor_name = ?, question = ?, status = 'open',
                                    answer = NULL, answered_by = NULL, answered_at = NULL WHERE id = ?")->execute([$total, $vendor, $text, (int)$qid]);
            } else {
                $this->db->prepare("INSERT INTO penny_questions (kind, subject, expense_id, vendor_name, category, amount, question, status)
                                    VALUES ('payment_source', NULL, ?, ?, 'notice', ?, ?, 'open')")->execute([$expenseId, $vendor, $total, $text]);
            }
        } catch (Throwable $e) {
            error_log('Receipt bank check note #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /** Run inside the caller's transaction, or one of our own. */
    private function atomically(callable $fn): void
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $fn();
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // ── Reads ─────────────────────────────────────────────────────────────────

    /**
     * Is the statement for this account in, from the date through date + AFTER? A PDF statement section
     * covering it, or an imported statement (CSV) whose range covers it and that carried lines for the account.
     */
    public function covered(int $accountId, string $date): bool
    {
        $to = date('Y-m-d', strtotime($date . ' +' . self::AFTER . ' days'));
        $ranges = [];
        try {
            $s = $this->db->prepare("SELECT x.period_from, x.period_to FROM bank_statement_sections x
                                     JOIN bank_import_sessions s ON s.id = x.session_id AND s.status = 'imported'
                                     WHERE x.bank_account_id = ? AND x.period_to >= ? AND x.period_from <= ?");
            $s->execute([$accountId, $date, $to]);
            $ranges = $s->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable $e) { /* before migration 1360 */ }
        $s = $this->db->prepare("SELECT s.date_from, s.date_to FROM bank_import_sessions s
                                 WHERE s.status = 'imported' AND s.date_to >= ? AND s.date_from <= ?
                                   AND EXISTS (SELECT 1 FROM bank_import_rows r JOIN accounting_transactions t ON t.id = r.transaction_id
                                               WHERE r.session_id = s.id AND t.bank_account_id = ?)");
        $s->execute([$date, $to, $accountId]);
        return self::spans(array_merge($ranges, $s->fetchAll(PDO::FETCH_NUM)), $date, $to);
    }

    /**
     * Do these statement periods, joined end to start (a gap of a day at most), cover $from..$to? Pure.
     * A month-end receipt needs two statements (Codex review 2026-10-15).
     * @param list<array{0:string,1:string}> $ranges
     */
    public static function spans(array $ranges, string $from, string $to): bool
    {
        usort($ranges, fn($a, $b) => strcmp((string)$a[0], (string)$b[0]));
        $reach = $from;                                   // covered through the day before $reach
        foreach ($ranges as [$a, $b]) {
            if ($a > $reach) break;                       // a gap before $reach
            $next = date('Y-m-d', strtotime($b . ' +1 day'));
            if ($next > $reach) $reach = $next;
            if ($reach > $to) return true;
        }
        return $reach > $to;
    }

    /** Exact-amount spending lines on any statement in the window, with who has them. */
    private function lines(array $e, int $accountId): array
    {
        $s = $this->db->prepare("
            SELECT t.id, t.description, t.type, t.bank_account_id, t.matched_expense_id, x.receipt_media_id AS other_media
            FROM accounting_transactions t LEFT JOIN expenses x ON x.id = t.matched_expense_id
            WHERE t.reference_type = 'bank_import' AND t.type IN ('expense', 'transfer') AND ABS(t.amount - ?) < 0.01
              AND t.transaction_date BETWEEN DATE_SUB(?, INTERVAL " . self::BEFORE . " DAY) AND DATE_ADD(?, INTERVAL " . self::AFTER . " DAY)
              AND COALESCE(t.status, '') NOT IN ('void', 'deleted')
            ORDER BY ABS(DATEDIFF(t.transaction_date, ?)), t.id");
        $s->execute([(float)$e['total'], $e['expense_date'], $e['expense_date'], $e['expense_date']]);
        require_once __DIR__ . '/BankImportService.php';
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $free = empty($t['matched_expense_id']);
            $out[] = ['id' => (int)$t['id'], 'free' => $free,
                      'vendor' => BankImportService::vendorInDescription((string)$e['vendor_name_raw'], (string)$t['description']),
                      'other_receipt' => $free ? null : (int)$t['matched_expense_id'],
                      'own' => (int)($t['bank_account_id'] ?: $this->ledger->accountId(LedgerService::ACC_BANK)) === $accountId,
                      'purchase' => $t['type'] === 'expense',
                      'same_photo' => !$free && !empty($e['receipt_media_id']) && (int)$t['other_media'] === (int)$e['receipt_media_id']];
        }
        return $out;
    }

    /** Other receipts that could equally be the line: same total, in the window, not rejected, not linked. */
    private function rivals(array $e): int
    {
        $s = $this->db->prepare("
            SELECT COUNT(*) FROM expenses x
            WHERE x.id <> ? AND ABS(x.total - ?) < 0.01 AND x.status NOT IN ('rejected', 'cancelled')
              AND x.expense_date BETWEEN DATE_SUB(?, INTERVAL " . (self::BEFORE + self::AFTER) . " DAY) AND DATE_ADD(?, INTERVAL " . (self::BEFORE + self::AFTER) . " DAY)
              AND NOT EXISTS (SELECT 1 FROM accounting_transactions t WHERE t.matched_expense_id = x.id)");
        $s->execute([(int)$e['id'], (float)$e['total'], $e['expense_date'], $e['expense_date']]);
        return (int)$s->fetchColumn();
    }

    /** Another live receipt saved from the same photo. */
    private function twin(array $e): ?int
    {
        if (empty($e['receipt_media_id'])) return null;
        $s = $this->db->prepare("SELECT id FROM expenses WHERE receipt_media_id = ? AND id <> ? AND status NOT IN ('rejected', 'cancelled') ORDER BY id LIMIT 1");
        $s->execute([(int)$e['receipt_media_id'], (int)$e['id']]);
        $id = $s->fetchColumn();
        return $id === false ? null : (int)$id;
    }
}
