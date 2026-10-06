<?php
/**
 * BankDeskService — Penny reviews imported bank lines (Penny's bank work, step 4).
 *
 * Every imported bank line that landed on the default account (Miscellaneous / Other
 * Services) or none comes to her card. She proposes a category from code alone — no AI:
 *   1. the receipt the line is matched to (its category → account via
 *      chart_of_accounts.expense_category_alias);
 *   2. a vendor named in the description (vendors.name / aliases → its usual category);
 *   3. an active categorization rule (RulesEngine::previewMatch);
 *   4. a few plain facts: fuel brands, bank fees, credit-card payments;
 * or says she doesn't know yet. The owner approves, picks another account, or keeps it
 * as it is. Approving changes the line's account and teaches the import
 * (BankRuleLearning — default accounts never teach; 2 confirmations switch a rule on).
 * Every decision is kept in bank_line_reviews (migration 1129): the line never comes
 * back, and her suggestions get a scorecard.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/BankRuleLearning.php';
require_once __DIR__ . '/BankImportService.php';

class BankDeskService
{
    public const DEFAULT_CODES = ['4900', '6900'];
    /** Plain facts that need no learning. code => [regex on the description, reason]. */
    public const FACTS = [
        'fuel'  => ['/\b(SHELL|CHEVRON|ESSO|PETRO[\s-]?CAN(ADA)?|HUSKY|MOBIL|PIONEER|CO-?OP GAS|COSTCO GAS|SUPER SAVE GAS|7-?ELEVEN FUEL)\b/i', 'is a gas station'],
        '6800'  => ['/\b(SERVICE CHARGE|MONTHLY (ACCOUNT )?FEE|ACCOUNT FEE|NSF|OVERDRAFT|INTERAC FEE|E-?TRANSFER FEE|ANNUAL FEE)\b/i', 'is a bank fee'],
        '2400'  => ['/\b(PAYMENT - THANK YOU|PAYMENT RECEIVED|PAYMENT THANK YOU|MASTERCARD PAYMENT|VISA PAYMENT)\b/i', 'is a credit-card payment, not spending'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'bank_line_reviews'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** How many lines are waiting for review. */
    public function waiting(): int
    {
        if (!$this->ready()) return 0;
        return (int)$this->db->query("SELECT COUNT(*) " . $this->waitingSql())->fetchColumn();
    }

    private function waitingSql(): string
    {
        $codes = "'" . implode("','", self::DEFAULT_CODES) . "'";
        return "FROM accounting_transactions t
                LEFT JOIN chart_of_accounts a ON a.id = t.account_id
                WHERE t.reference_type = 'bank_import' AND t.type IN ('expense', 'income')
                  AND COALESCE(t.status, '') NOT IN ('void', 'deleted')
                  AND (t.account_id IS NULL OR a.code IN ({$codes}))
                  AND NOT EXISTS (SELECT 1 FROM bank_line_reviews r WHERE r.transaction_id = t.id)";
    }

    /** The next lines for the card, newest first, each with her suggestion. */
    public function queue(int $limit = 10): array
    {
        if (!$this->ready()) return [];
        $limit = max(1, min(30, $limit));
        $rows = $this->db->query("
            SELECT t.id, t.transaction_date, t.type, t.amount, t.description, t.account_id, t.matched_expense_id,
                   t.bank_account, a.code AS account_code, a.name AS account_name
            " . $this->waitingSql() . "
            ORDER BY t.transaction_date DESC, t.id DESC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];

        $ctx = $this->context($rows);
        $out = [];
        foreach ($rows as $r) {
            $s = self::advise($r, $ctx);
            $out[] = [
                'id'          => (int)$r['id'],
                'date'        => $r['transaction_date'],
                'type'        => $r['type'],
                'amount'      => (float)$r['amount'],
                'description' => $r['description'],
                'bank'        => $r['bank_account'],
                'current'     => $r['account_id'] ? ['id' => (int)$r['account_id'], 'code' => $r['account_code'], 'name' => $r['account_name']] : null,
                'suggestion'  => $s,
            ];
        }
        return $out;
    }

    /** Everything advise() needs, loaded once per queue. */
    private function context(array $rows): array
    {
        $accounts = $this->db->query("SELECT id, code, name, type, expense_category_alias FROM chart_of_accounts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $byCode = [];
        $byAlias = [];
        foreach ($accounts as $a) {
            $byCode[$a['code']] = $a;
            if (!empty($a['expense_category_alias'])) $byAlias[strtolower($a['expense_category_alias'])] = $a;
        }
        $vendors = $this->db->query("SELECT name, aliases, default_accounting_category FROM vendors
                                     WHERE is_active = 1 AND default_accounting_category IS NOT NULL AND default_accounting_category <> ''")->fetchAll(PDO::FETCH_ASSOC);
        $expenseIds = array_filter(array_map(fn($r) => (int)$r['matched_expense_id'], $rows));
        $expenses = [];
        if ($expenseIds) {
            $in = implode(',', array_fill(0, count($expenseIds), '?'));
            $s = $this->db->prepare("SELECT e.id, e.accounting_category, COALESCE(v.name, e.vendor_name_raw) AS vendor
                                     FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id IN ({$in})");
            $s->execute(array_values($expenseIds));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $e) $expenses[(int)$e['id']] = $e;
        }
        $rules = [];
        try {
            require_once __DIR__ . '/RulesEngine.php';
            $engine = new RulesEngine($this->db);
            foreach ($rows as $r) {
                $m = $engine->previewMatch((string)$r['description'], '', (string)$r['type']);
                if ($m) $rules[(int)$r['id']] = $m;
            }
        } catch (Throwable $e) { /* rules are one source of four */ }
        return ['byCode' => $byCode, 'byAlias' => $byAlias, 'vendors' => $vendors, 'expenses' => $expenses, 'rules' => $rules];
    }

    /**
     * Approve a line on an account (her suggestion or the owner's pick), or keep it.
     * @param string $action 'approve' | 'keep'
     */
    public function decide(int $transactionId, string $action, ?int $accountId, ?int $suggestedId, array $user): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Needs migration 1129'];
        $s = $this->db->prepare("SELECT id, account_id FROM accounting_transactions WHERE id = ? AND reference_type = 'bank_import'");
        $s->execute([$transactionId]);
        $tx = $s->fetch(PDO::FETCH_ASSOC);
        if (!$tx) return ['ok' => false, 'message' => 'Bank line not found'];

        $outcome = 'kept';
        $learned = null;
        if ($action === 'approve') {
            if (!$accountId) return ['ok' => false, 'message' => 'Pick an account'];
            $a = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE id = ? AND is_active = 1");
            $a->execute([$accountId]);
            if (!$a->fetchColumn()) return ['ok' => false, 'message' => 'Unknown account'];
            $this->db->prepare("UPDATE accounting_transactions SET account_id = ?, is_auto_categorized = 0 WHERE id = ?")
               ->execute([$accountId, $transactionId]);
            $learned = (new BankRuleLearning($this->db))->learnFromCorrection($transactionId, $accountId, (int)$user['id']);
            $outcome = $suggestedId && $suggestedId === $accountId ? 'accepted' : 'edited';
        }
        $this->db->prepare("
            INSERT INTO bank_line_reviews (transaction_id, suggested_account_id, final_account_id, outcome, decided_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE suggested_account_id = VALUES(suggested_account_id), final_account_id = VALUES(final_account_id),
                                    outcome = VALUES(outcome), decided_by = VALUES(decided_by), decided_at = NOW()
        ")->execute([$transactionId, $suggestedId ?: null, $action === 'approve' ? $accountId : ($tx['account_id'] ?: null), $outcome, (int)$user['id']]);

        $msg = $action === 'approve'
            ? ($learned && ($learned['action'] ?? '') !== 'skipped'
                ? ($learned['active'] ? 'Done — and the import will now do this one by itself.' : 'Done — once more and the import does this one by itself.')
                : 'Done.')
            : 'Kept as it is.';
        return ['ok' => true, 'message' => $msg];
    }

    /** Accounts to choose from, grouped for the picker. */
    public function accounts(): array
    {
        return $this->db->query("SELECT id, code, name, type FROM chart_of_accounts WHERE is_active = 1 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Her suggestion for one line, from code alone.
     * @return array{account_id: int, code: string, name: string, reason: string, source: string}|null
     */
    public static function advise(array $line, array $ctx): ?array
    {
        $pick = function (?array $acct, string $reason, string $source) use ($line): ?array {
            if (!$acct) return null;
            if ((int)$acct['id'] === (int)($line['account_id'] ?? 0)) return null;     // nothing to change
            if (in_array($acct['code'], self::DEFAULT_CODES, true)) return null;       // never suggest a default
            return ['account_id' => (int)$acct['id'], 'code' => $acct['code'], 'name' => $acct['name'], 'reason' => $reason, 'source' => $source];
        };
        $desc = (string)($line['description'] ?? '');

        // 1. The receipt it's matched to.
        $e = $ctx['expenses'][(int)($line['matched_expense_id'] ?? 0)] ?? null;
        if ($e && ($a = $ctx['byAlias'][strtolower((string)$e['accounting_category'])] ?? null)) {
            if ($r = $pick($a, 'Matched to your ' . ($e['vendor'] ?: 'receipt') . ' receipt, booked as ' . $e['accounting_category'], 'receipt')) return $r;
        }
        // 2. A vendor named in the description.
        $hay = self::plain($desc);
        foreach ($ctx['vendors'] as $v) {
            $names = array_merge([(string)$v['name']], array_map('trim', explode(',', (string)($v['aliases'] ?? ''))));
            foreach ($names as $n) {
                $n = self::plain($n);
                if (strlen($n) < 4 || !preg_match('/\b' . preg_quote($n, '/') . '\b/', $hay)) continue;
                $a = $ctx['byAlias'][strtolower((string)$v['default_accounting_category'])] ?? null;
                if ($r = $pick($a, $v['name'] . ' is a vendor you book as ' . $v['default_accounting_category'], 'vendor')) return $r;
            }
        }
        // 3. A rule.
        $m = $ctx['rules'][(int)($line['id'] ?? 0)] ?? null;
        if ($m && ($a = $ctx['byCode'][$m['account_code']] ?? null)) {
            if ($r = $pick($a, 'Your rule "' . ($m['rule_name'] ?? 'rule') . '"', 'rule')) return $r;
        }
        // 4. Plain facts.
        foreach (self::FACTS as $key => [$re, $why]) {
            if (!preg_match($re, $desc, $hit)) continue;
            $a = $key === 'fuel' ? ($ctx['byAlias']['fuel'] ?? null) : ($ctx['byCode'][$key] ?? null);
            if ($r = $pick($a, ucfirst(strtolower(trim($hit[0]))) . ' ' . $why, 'fact')) return $r;
        }
        return null;
    }

    /** Upper-case letters and digits only, single-spaced — for name-in-description checks. */
    public static function plain(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($s))));
    }
}
