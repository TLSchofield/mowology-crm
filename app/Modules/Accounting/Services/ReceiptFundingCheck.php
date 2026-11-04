<?php
/**
 * ReceiptFundingCheck — the bank line is the truth about where a receipt's money came from
 * (2026-10-12, Vital Auto #384: the receipt said credit card, so it posted Cr 2400, but it was
 * linked to the chequing line tx 43028 — chequing was never credited and a phantom card
 * liability sat on 2400).
 *
 * EVERY receipt ↔ statement-line link goes through onLink():
 *   - BankImportService::attachExpenseMatch()  (receipts page "attach", Penny's bank desk
 *     BankDeskService::linkReceipt, BankReceiptSweep)
 *   - BankImportService::commitImport()        (auto-match at import)
 *   - sweep()                                   (existing links, scripts/prod-run.sh)
 *
 * The line's account (chequing / savings vs the credit-card statement) is compared with what the
 * receipt's payment_method posts to (LedgerSyncService::fundingCodeFor):
 *   - clear mismatch → payment_method set to match through ExpenseGate (source 'bank_line'),
 *     re-posted (ExpenseGateHooks::repost), activity logged, and a Penny notice
 *     ("Receipt #384 said credit card, but it was paid from chequing on Sep 16 — corrected");
 *   - ambiguous (company_card on a chequing line; cash / cheque / e-Transfer on a card line) →
 *     nothing changes, Penny asks (penny_questions kind 'payment_source').
 *
 * Best-effort: a failure is logged and never breaks the link or the import.
 * No namespace / no autoloader in production: require_once and `new`.
 */
declare(strict_types=1);

require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';

class ReceiptFundingCheck
{
    public const KIND = 'payment_source';
    public const SOURCE = 'bank_line';
    /** Methods that clearly mean "chequing" and can be corrected to the card when a card line carries them. */
    private const DEBIT_LIKE = ['', 'debit', 'debit_card'];

    private PDO $db;
    private ?ExpenseGate $gate;
    private ?ExpenseGateHooks $hooks;

    public function __construct(PDO $db, ?ExpenseGate $gate = null, ?ExpenseGateHooks $hooks = null)
    {
        $this->db = $db;
        $this->gate = $gate;
        $this->hooks = $hooks;
    }

    // ── Pure ──────────────────────────────────────────────────────────────────

    /** 'card' | 'bank' | 'other' from the line's bank account (chart code, sub_type, statement kind). */
    public static function lineKind(?string $code, ?string $subType, ?string $statementKind, bool $hasAccount): string
    {
        if (!$hasAccount) return 'bank';     // no bank account on the line = the default chequing (LedgerSyncService)
        if ($statementKind === 'card' || $subType === 'credit_card' || $code === LedgerService::ACC_CREDIT_CARD) return 'card';
        if ($statementKind === 'bank' || $subType === 'bank' || $code === LedgerService::ACC_BANK) return 'bank';
        return 'other';
    }

    public static function normaliseMethod(?string $m): string
    {
        return trim((string)preg_replace('/[\s\-]+/', '_', strtolower(trim((string)$m))), '_');
    }

    /**
     * What to do with a receipt linked to a line of this kind.
     * @return array{action: 'none'|'correct'|'ask', to: ?string}  to = the method the line implies
     */
    public static function decide(string $lineKind, ?string $method): array
    {
        $pm = self::normaliseMethod($method);
        $posts = LedgerSyncService::fundingCodeFor($pm);
        if ($lineKind === 'bank') {
            if ($posts === LedgerService::ACC_BANK) return ['action' => 'none', 'to' => null];
            // company_card could be a company debit card or the Visa — can't tell: ask.
            $clear = $pm === 'credit_card' || $pm === 'credit' || in_array($pm, ['visa', 'mastercard', 'amex'], true);
            return ['action' => $clear ? 'correct' : 'ask', 'to' => 'debit'];
        }
        if ($lineKind === 'card') {
            if ($posts === LedgerService::ACC_CREDIT_CARD) return ['action' => 'none', 'to' => null];
            // A debit receipt (or one with no method) on the card statement was the card. Cash, a
            // cheque or an e-Transfer can't be on a card statement — one of the two is wrong: ask.
            return ['action' => in_array($pm, self::DEBIT_LIKE, true) ? 'correct' : 'ask', 'to' => 'credit_card'];
        }
        return ['action' => 'none', 'to' => null];
    }

    public static function methodWords(?string $m): string
    {
        $pm = self::normaliseMethod($m);
        $words = ['credit_card' => 'credit card', 'company_card' => 'company card', 'debit' => 'debit', 'debit_card' => 'debit card',
                  'cash' => 'cash', 'etransfer' => 'e-Transfer', 'e_transfer' => 'e-Transfer', 'cheque' => 'cheque', '' => 'no payment method'];
        return $words[$pm] ?? str_replace('_', ' ', $pm);
    }

    public static function lineWords(array $line): string
    {
        if ($line['kind'] === 'card') return 'the credit card';
        if (($line['code'] ?? null) === LedgerService::ACC_BANK || empty($line['bank_account_id'])) return 'chequing';
        return (string)($line['account_name'] ?: 'the bank');
    }

    public static function shortDate(string $date): string
    {
        $t = strtotime($date);
        return $t ? date('M j', $t) : $date;
    }

    /** Penny's sentence. Pure. */
    public static function wording(string $action, int $expenseId, string $vendor, float $total, ?string $from, string $to, array $line): string
    {
        $what = 'Receipt #' . $expenseId . ($vendor !== '' ? ' (' . $vendor . ', $' . number_format($total, 2) . ')' : ' ($' . number_format($total, 2) . ')');
        $where = self::lineWords($line);
        $when = self::shortDate((string)$line['transaction_date']);
        if ($action === 'correct') {
            return sprintf('%s said %s, but it was paid from %s on %s (bank line #%d) — corrected to %s.',
                $what, self::methodWords($from), $where, $when, (int)$line['id'], self::methodWords($to));
        }
        return sprintf('%s says %s, but bank line #%d shows it paid from %s on %s. Was it %s? I haven\'t changed anything.',
            $what, self::methodWords($from), (int)$line['id'], $where, $when, $to === 'debit' ? 'the debit card (chequing)' : 'the credit card');
    }

    // ── The one function every link goes through ──────────────────────────────

    /**
     * A receipt was linked to a bank / card statement line: make the receipt's payment method
     * agree with the account the money left.
     * @return array{action: string, from?: ?string, to?: ?string, message?: string}
     */
    public function onLink(int $expenseId, int $transactionId, ?int $userId): array
    {
        try {
            $line = $this->line($transactionId);
            $e = $this->expense($expenseId);
            if (!$line || !$e) return ['action' => 'none'];
            $d = self::decide($line['kind'], $e['payment_method'] ?? null);
            if ($d['action'] === 'none') return ['action' => 'none'];
            $from = $e['payment_method'] ?? null;
            $msg = self::wording($d['action'], $expenseId, (string)($e['vendor_name_raw'] ?? ''), (float)$e['total'], $from, (string)$d['to'], $line);
            if ($d['action'] === 'correct') {
                $this->setMethod($expenseId, (string)$d['to'], $userId,
                    'payment source corrected from ' . self::methodWords($from) . ' to ' . self::methodWords($d['to']) . ' by bank line #' . $transactionId);
                // The receipt (Penny's read) was wrong about where the money came from (head_lessons).
                require_once __DIR__ . '/PennyLessons.php';
                PennyLessons::expense($this->db, $expenseId, 'payment_method', $from, (string)$d['to'], 'receipt_funding', $userId,
                                      'bank line #' . $transactionId . ' is ' . self::lineWords($line));
            }
            $this->tell($expenseId, $d['action'], (string)$d['to'], (float)$e['total'], (string)($e['vendor_name_raw'] ?? ''), $msg);
            return ['action' => $d['action'], 'from' => $from, 'to' => $d['to'], 'message' => $msg];
        } catch (Throwable $ex) {
            error_log('Receipt funding check (expense ' . $expenseId . ', line ' . $transactionId . '): ' . $ex->getMessage());
            return ['action' => 'error', 'message' => $ex->getMessage()];
        }
    }

    /**
     * Every existing link. $apply = false lists only; true corrects / asks through onLink().
     * @return list<array>
     */
    public function sweep(bool $apply, ?int $userId = null): array
    {
        $rows = $this->db->query("SELECT id, matched_expense_id FROM accounting_transactions WHERE matched_expense_id IS NOT NULL ORDER BY id")
                         ->fetchAll(PDO::FETCH_ASSOC);
        // …and every other receipt on a line paid by several (migration 1372; Codex review 2026-10-16).
        require_once __DIR__ . '/BankLineReceipts.php';
        if (BankLineReceipts::tableExists($this->db)) {
            foreach ($this->db->query("SELECT transaction_id AS id, expense_id AS matched_expense_id FROM bank_line_receipts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;
        }
        $out = [];
        foreach ($rows as $r) {
            $line = $this->line((int)$r['id']);
            $e = $this->expense((int)$r['matched_expense_id']);
            if (!$line || !$e) continue;
            $d = self::decide($line['kind'], $e['payment_method'] ?? null);
            if ($d['action'] === 'none') continue;
            $item = ['expense_id' => (int)$e['id'], 'vendor' => $e['vendor_name_raw'], 'total' => (float)$e['total'],
                     'expense_date' => $e['expense_date'], 'line_id' => (int)$line['id'], 'line_date' => $line['transaction_date'],
                     'line' => self::lineWords($line), 'method' => $e['payment_method'], 'action' => $d['action'], 'to' => $d['to']];
            if ($apply) $item['result'] = $this->onLink((int)$e['id'], (int)$line['id'], $userId);
            $out[] = $item;
        }
        return $out;
    }

    /** The owner answered Penny's question "use what the bank line says". */
    public function applyAnswer(int $expenseId, string $toMethod, ?int $userId): bool
    {
        if (!in_array($toMethod, ['debit', 'credit_card'], true)) return false;
        $e = $this->expense($expenseId);
        if (!$e) return false;
        $this->setMethod($expenseId, $toMethod, $userId,
            'payment source corrected from ' . self::methodWords($e['payment_method'] ?? null) . ' to ' . self::methodWords($toMethod) . ' — owner answered Penny');
        require_once __DIR__ . '/PennyLessons.php';
        PennyLessons::expense($this->db, $expenseId, 'payment_method', $e['payment_method'] ?? null, $toMethod, 'penny_question:payment_source',
                              $userId, 'owner: use what the bank line says');
        return true;
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    private function setMethod(int $expenseId, string $to, ?int $userId, string $why): void
    {
        $gate = $this->gate();
        $res = $gate->apply($expenseId, ['payment_method' => $to], ['id' => $userId, 'kind' => 'penny'], self::SOURCE, ['allow_locked' => true]);
        // The gate defers the re-post when it runs inside a caller's transaction (the import); the
        // books must follow now — the hook joins the outer transaction.
        if (empty($res['noop']) && empty($res['reposted'])) {
            $this->hooks()->repost($expenseId, $userId, $why);
        }
        try {
            $this->db->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)")
               ->execute([$userId, 'Receipt payment source corrected', 'Receipt #' . $expenseId . ': ' . $why]);
        } catch (Throwable $e) {
            error_log('Receipt funding activity log: ' . $e->getMessage());
        }
    }

    /** A Penny notice (corrected) or question (ambiguous) — one per receipt, refreshed. */
    private function tell(int $expenseId, string $action, string $to, float $total, string $vendor, string $text): void
    {
        try {
            $s = $this->db->prepare("SELECT id FROM penny_questions WHERE kind = ? AND expense_id = ?");
            $s->execute([self::KIND, $expenseId]);
            $id = $s->fetchColumn();
            $category = $action === 'correct' ? 'notice' : 'question';
            if ($id) {
                $this->db->prepare("UPDATE penny_questions SET category = ?, subject = ?, amount = ?, vendor_name = ?, question = ?, status = 'open',
                                    answer = NULL, answered_by = NULL, answered_at = NULL WHERE id = ?")
                   ->execute([$category, $to, $total, $vendor, $text, (int)$id]);
            } else {
                $this->db->prepare("INSERT INTO penny_questions (kind, subject, expense_id, vendor_name, category, amount, question, status)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'open')")
                   ->execute([self::KIND, $to, $expenseId, $vendor, $category, $total, $text]);
            }
        } catch (Throwable $e) {
            error_log('Receipt funding Penny note: ' . $e->getMessage());   // before migration 1126/1130
        }
    }

    // ── Reads ─────────────────────────────────────────────────────────────────

    /** The statement line with its account kind. */
    public function line(int $transactionId): ?array
    {
        $sql = "SELECT t.id, t.transaction_date, t.amount, t.description, t.bank_account_id, c.code, c.name AS account_name, c.sub_type%s
                FROM accounting_transactions t LEFT JOIN chart_of_accounts c ON c.id = t.bank_account_id WHERE t.id = ?";
        try {
            $s = $this->db->prepare(sprintf($sql, ", (SELECT b.kind FROM bank_statement_accounts b WHERE b.account_id = t.bank_account_id LIMIT 1) AS stmt_kind"));
            $s->execute([$transactionId]);
        } catch (Throwable $e) {           // no bank_statement_accounts (before migration 1231)
            $s = $this->db->prepare(sprintf($sql, ", NULL AS stmt_kind"));
            $s->execute([$transactionId]);
        }
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['kind'] = self::lineKind($r['code'] ?? null, $r['sub_type'] ?? null, $r['stmt_kind'] ?? null, !empty($r['bank_account_id']));
        return $r;
    }

    private function expense(int $id): ?array
    {
        $s = $this->db->prepare("SELECT id, expense_date, total, payment_method, vendor_name_raw, status FROM expenses WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function gate(): ExpenseGate
    {
        if (!$this->gate) {
            require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseGate.php';
            $this->gate = new ExpenseGate($this->db, $this->hooks());
        }
        return $this->gate;
    }

    private function hooks(): ExpenseGateHooks
    {
        if (!$this->hooks) {
            require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseGateHooks.php';
            $this->hooks = new ExpenseGateHooks($this->db);
        }
        return $this->hooks;
    }
}
