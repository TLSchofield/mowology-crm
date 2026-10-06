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
require_once __DIR__ . '/LedgerAccountMap.php';
if (!defined('EXPENSE_ACCOUNTING_CATEGORIES') && defined('APP_ROOT')) {
    require_once APP_ROOT . '/Modules/Expenses/ExpenseConstants.php';
}

class BankDeskService
{
    public const DEFAULT_CODES = ['4900', '6900'];
    /** Plain facts that need no learning. code => [regex on the description, reason]. */
    public const FACTS = [
        'fuel'  => ['/\b(SHELL|CHEVRON|ESSO|PETRO[\s-]?CAN(ADA)?|HUSKY|MOBIL|PIONEER|CO-?OP GAS|COSTCO GAS|SUPER SAVE GAS|7-?ELEVEN FUEL)\b/i', 'is a gas station'],
        '6800'  => ['/\b(SERVICE CHARGE|MONTHLY (ACCOUNT )?FEE|ACCOUNT FEE|NSF|OVERDRAFT|INTERAC FEE|E-?TRANSFER FEE|ANNUAL FEE)\b/i', 'is a bank fee'],
        'meals' => ['/\b(RESTAURANT|SUSHI|DELI|DONUTS?|STEAKHOUSE|SANDWICH(ES)?|PIZZA|CAFE|COFFEE|STARBUCKS|TIM HORTONS|MCDONALD\'?S|SUBWAY|A&W|WENDY\'?S|BAKERY|BISTRO|GRILL|PUB|TACO|BURGER|NOODLE|RAMEN|PHO)\b/i', 'is a place to eat'],
        '2400'  => ['/\b(PAYMENT - THANK YOU|PAYMENT RECEIVED|PAYMENT THANK YOU|MASTERCARD PAYMENT|VISA PAYMENT)\b/i', 'is a credit-card payment, not spending'],
    ];

    /**
     * Receipt category → chart code, used when chart_of_accounts.expense_category_alias
     * isn't filled in (it was empty on production). A filled-in alias wins.
     * 'Other' maps to nothing: Penny never suggests the default account.
     */
    public const CATEGORY_CODES = [
        'materials'           => '5200',   // Materials & Supplies
        'fuel'                => '6100',   // Fuel & Vehicle
        'tools/equipment'     => '1500',   // Equipment & Tools (owner's call)
        'repairs/maintenance' => '6200',   // Equipment Maintenance
        'vehicle'             => '6120',   // Vehicle Maintenance
        'disposal/dump'       => '5000',   // Cost of Services
        'licenses/permits'    => '6500',   // Office & Administration
        'subcontractors'      => '5400',
        'marketing'           => '6400',
        'office/admin'        => '6500',
        'overhead'            => '6000',   // Operating Expenses
        'meals'               => '6850',   // Meals & Entertainment
        'safety'              => '6000',
    ];
    /** A found receipt is offered when the match is at least this strong (exact amount + close date). */
    public const RECEIPT_CONFIDENCE = 60;
    /** Income from a contract invoice (contracts bill without line items). */
    public const CONTRACT_INCOME_CODE = '4050';

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
                  AND NOT EXISTS (SELECT 1 FROM bank_line_reviews r WHERE r.transaction_id = t.id)
                  AND NOT EXISTS (SELECT 1 FROM accounting_periods p WHERE p.status = 'locked'
                                  AND p.year = YEAR(t.transaction_date) AND p.month = MONTH(t.transaction_date))";
    }

    /** The next lines for the card, newest first, each with her suggestion. */
    public function queue(int $limit = 10): array
    {
        if (!$this->ready()) return [];
        $limit = max(1, min(30, $limit));
        $rows = $this->db->query("
            SELECT t.id, t.transaction_date, t.type, t.amount, t.description, t.account_id, t.matched_expense_id,
                   " . ($this->hasInvoiceMatch() ? 't.matched_invoice_id' : 'NULL AS matched_invoice_id') . ",
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
                'note'        => self::note($r),
            ];
        }
        return $out;
    }

    private function hasInvoiceMatch(): bool
    {
        try {
            return $this->db->query("SHOW COLUMNS FROM accounting_transactions LIKE 'matched_invoice_id'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Everything advise() needs, loaded once per queue. */
    private function context(array $rows): array
    {
        $accounts = $this->db->query("SELECT id, code, name, type, expense_category_alias FROM chart_of_accounts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $byCode = [];
        $byAlias = [];
        foreach ($accounts as $a) {
            $byCode[$a['code']] = $a;
        }
        // The owner's category → account map (migration 1130) wins over the built-in list.
        require_once __DIR__ . '/LedgerAccountMap.php';
        foreach ((new LedgerAccountMap($this->db))->categoryCodes() + self::CATEGORY_CODES as $cat => $code) {
            if (isset($byCode[$code])) $byAlias[$cat] = $byCode[$code];
        }
        foreach ($accounts as $a) {
            if (!empty($a['expense_category_alias'])) $byAlias[strtolower($a['expense_category_alias'])] = $a;
        }
        // A vendor's usual category: its default, else what its approved receipts are booked as most (2+).
        $vendors = $this->db->query("
            SELECT v.name, v.aliases,
                   COALESCE(NULLIF(v.default_accounting_category, ''),
                            (SELECT e.accounting_category FROM expenses e
                              WHERE e.vendor_id = v.id AND e.status IN ('approved', 'forwarded') AND e.accounting_category IS NOT NULL AND e.accounting_category <> ''
                              GROUP BY e.accounting_category HAVING COUNT(*) >= 2 ORDER BY COUNT(*) DESC LIMIT 1)) AS default_accounting_category
            FROM vendors v WHERE v.is_active = 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        $vendors = array_values(array_filter($vendors, fn($v) => !empty($v['default_accounting_category'])));
        $expenseIds = array_filter(array_map(fn($r) => (int)$r['matched_expense_id'], $rows));
        $expenses = [];
        if ($expenseIds) {
            $in = implode(',', array_fill(0, count($expenseIds), '?'));
            $s = $this->db->prepare("SELECT e.id, e.accounting_category, COALESCE(v.name, e.vendor_name_raw) AS vendor
                                     FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id IN ({$in})");
            $s->execute(array_values($expenseIds));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $e) $expenses[(int)$e['id']] = $e;
        }
        // The receipt behind each unlinked spending line — found the way the receipts
        // page's "Find Expense Match" finds it (amount, date window, vendor name).
        $found = [];
        try {
            $bis = new BankImportService($this->db);
            foreach ($rows as $r) {
                if ($r['type'] !== 'expense' || !empty($r['matched_expense_id'])) continue;
                $c = $bis->candidateExpensesForTransaction((int)$r['id'], 1)[0] ?? null;
                if ($c && $c['confidence'] >= self::RECEIPT_CONFIDENCE) $found[(int)$r['id']] = $c;
            }
            if ($found) {
                $ids = array_map(fn($c) => (int)$c['expense_id'], $found);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $s = $this->db->prepare("SELECT expense_id, name FROM expense_line_items WHERE expense_id IN ({$in}) ORDER BY sort_order, id");
                $s->execute(array_values($ids));
                $items = [];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $li) $items[(int)$li['expense_id']][] = $li['name'];
                $t = $this->db->prepare("SELECT id, asset_tag FROM expenses WHERE id IN ({$in})");
                $t->execute(array_values($ids));
                $tags = array_column($t->fetchAll(PDO::FETCH_ASSOC), 'asset_tag', 'id');
                foreach ($found as &$c) {
                    $c['items'] = array_slice(self::itemNames($items[(int)$c['expense_id']] ?? []), 0, 3);
                    $c['asset_tag'] = $tags[(int)$c['expense_id']] ?? null;
                }
                unset($c);
            }
        } catch (Throwable $e) { /* finding receipts is one source of several */ }
        $contractInvoices = [];
        $invoiceIds = array_filter(array_map(fn($r) => (int)($r['matched_invoice_id'] ?? 0), $rows));
        if ($invoiceIds) {
            try {
                $in = implode(',', array_fill(0, count($invoiceIds), '?'));
                $s = $this->db->prepare("SELECT id, invoice_number FROM invoices WHERE id IN ({$in}) AND contract_id IS NOT NULL");
                $s->execute(array_values($invoiceIds));
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $i) $contractInvoices[(int)$i['id']] = $i['invoice_number'];
            } catch (Throwable $e) { /* no contracts column → no contract income */ }
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
        // Rules you've confirmed 2+ times but that haven't earned 50 yet: Penny proposes them.
        $learned = [];
        try {
            foreach ($this->db->query("SELECT r.condition_value, r.learned_count, c.id, c.code, c.name FROM transaction_rules r
                                       JOIN chart_of_accounts c ON c.id = r.account_id
                                       WHERE r.source = 'learned' AND r.condition_field = 'description' AND r.learned_count >= 2
                                       ORDER BY r.learned_count DESC")->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $learned[] = $lr;
            }
        } catch (Throwable $e) { /* one source of several */ }
        return ['byCode' => $byCode, 'byAlias' => $byAlias, 'vendors' => $vendors, 'expenses' => $expenses, 'rules' => $rules, 'learned' => $learned,
                'contractInvoices' => $contractInvoices, 'found' => $found];
    }

    /**
     * Approve a line on an account (her suggestion or the owner's pick), or keep it.
     * @param string $action 'approve' | 'keep'
     */
    public function decide(int $transactionId, string $action, ?int $accountId, ?int $suggestedId, array $user, ?int $expenseId = null): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Needs migration 1129'];
        $s = $this->db->prepare("SELECT id, account_id, transaction_date FROM accounting_transactions WHERE id = ? AND reference_type = 'bank_import'");
        $s->execute([$transactionId]);
        $tx = $s->fetch(PDO::FETCH_ASSOC);
        if (!$tx) return ['ok' => false, 'message' => 'Bank line not found'];
        require_once __DIR__ . '/LedgerService.php';
        if ($action === 'approve' && (new LedgerService($this->db))->isLocked((string)$tx['transaction_date'])) {
            return ['ok' => false, 'message' => substr((string)$tx['transaction_date'], 0, 7) . ' is locked — I can\'t change a line in a closed month.'];
        }

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
            if ($expenseId) {
                $linked = $this->linkReceipt($transactionId, $expenseId, (int)$user['id']);
                if ($linked) $this->alignReceiptCategory($expenseId, $accountId);
            }
            $outcome = $suggestedId && $suggestedId === $accountId ? 'accepted' : 'edited';
        }
        $this->db->prepare("
            INSERT INTO bank_line_reviews (transaction_id, suggested_account_id, final_account_id, outcome, decided_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE suggested_account_id = VALUES(suggested_account_id), final_account_id = VALUES(final_account_id),
                                    outcome = VALUES(outcome), decided_by = VALUES(decided_by), decided_at = NOW()
        ")->execute([$transactionId, $suggestedId ?: null, $action === 'approve' ? $accountId : ($tx['account_id'] ?: null), $outcome, (int)$user['id']]);

        if (!empty($linked)) {
            return ['ok' => true, 'message' => "Linked to the receipt — it's counted once in your books now."];
        }
        $msg = $action === 'approve'
            ? ($learned && ($learned['action'] ?? '') !== 'skipped'
                ? ($learned['active'] ? 'Done — that\'s ' . BankRuleLearning::CONFIRMATIONS . ' confirmations, so the import now does this one by itself.'
                                      : 'Done — confirmed ' . (int)($learned['count'] ?? 1) . ' of ' . BankRuleLearning::CONFIRMATIONS . '; at ' . BankRuleLearning::CONFIRMATIONS . ' the import does this one by itself.')
                : 'Done.')
            : 'Kept as it is.';
        return ['ok' => true, 'message' => $msg];
    }

    /**
     * Link a bank line to its receipt (the receipts page's own attachExpenseMatch) and
     * reverse the bank line's separate entry in the books: the receipt's entry already
     * carries that cost, so keeping both counted it twice. Never deletes (append-only).
     */
    public function linkReceipt(int $transactionId, int $expenseId, int $userId): bool
    {
        try {
            (new BankImportService($this->db))->attachExpenseMatch($transactionId, $expenseId, $userId);
            // Append-only: the bank line's own entry is reversed, not deleted.
            require_once __DIR__ . '/LedgerService.php';
            $ledger = new LedgerService($this->db);
            $entryId = $ledger->findEntryIdBySource('bank_import', $transactionId);
            if ($entryId) {
                $ledger->reverseEntry($entryId, $userId, 'linked to receipt #' . $expenseId . ' — the receipt carries this cost', 'penny');
            }
            return true;
        } catch (Throwable $e) {
            error_log('Penny receipt link failed (tx ' . $transactionId . ', expense ' . $expenseId . '): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The owner picked an account for a linked line: put the receipt in the matching
     * category, so the receipt and the books agree (6120 → Vehicle). Only when exactly
     * one category maps to that account, and never on a receipt sent to accounting.
     */
    private function alignReceiptCategory(int $expenseId, int $accountId): void
    {
        try {
            $c = $this->db->prepare("SELECT code FROM chart_of_accounts WHERE id = ?");
            $c->execute([$accountId]);
            $code = (string)$c->fetchColumn();
            $cats = array_keys(array_filter(self::categoryCodeMap($this->db), fn($cc) => (string)$cc === $code));
            if (count($cats) !== 1) return;
            $label = null;
            foreach (EXPENSE_ACCOUNTING_CATEGORIES as $known) if (strtolower($known) === $cats[0]) $label = $known;
            if (!$label) return;
            $this->db->prepare("UPDATE expenses SET accounting_category = ? WHERE id = ? AND COALESCE(forwarded_to_accounting, 0) = 0 AND accounting_category <> ?")
               ->execute([$label, $expenseId, $label]);
        } catch (Throwable $e) {
            error_log('Penny receipt category align failed for expense ' . $expenseId . ': ' . $e->getMessage());
        }
    }

    /** category(lower) => code: the owner's map over the built-in list. */
    private static function categoryCodeMap(PDO $db): array
    {
        require_once __DIR__ . '/LedgerAccountMap.php';
        return (new LedgerAccountMap($db))->categoryCodes() + self::CATEGORY_CODES;
    }

    /** Item names worth showing: real words, not prices or column headings. */
    public static function itemNames(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $n = trim((string)$n);
            if (!preg_match('/[a-z]{3}/i', $n)) continue;
            if (preg_match('/^(amount|qty|quantity|price|total|sub ?total|description|item|gst|pst|tax)\b/i', $n)) continue;
            $out[] = $n;
        }
        return array_values(array_unique($out));
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

        // 0. A receipt that matches but isn't linked yet: use what it says it was for.
        $f = $ctx['found'][(int)($line['id'] ?? 0)] ?? null;
        if ($f) {
            $a = $ctx['byAlias'][strtolower((string)$f['category'])] ?? null;
            if ($a) {
                $code = LedgerAccountMap::refineExpenseCode($a['code'], $f['category'], $f['asset_tag'] ?? null, $f['vendor'] ?? '');
                $a = $ctx['byCode'][$code] ?? $a;
            }
            $what = $f['category'] ?: 'no category yet';
            if (!empty($f['items'])) $what .= ' (' . implode(', ', $f['items']) . ')';
            $reason = 'It matches your ' . $f['vendor'] . ' receipt #' . $f['expense_id'] . ' from ' . $f['date'] . ', $' . number_format($f['amount'], 2) .
                      ', booked as ' . $what . '. I\'ll link them so it\'s counted once';
            $acct = $a && (int)$a['id'] !== (int)($line['account_id'] ?? 0) && !in_array($a['code'], self::DEFAULT_CODES, true) ? $a : null;
            return ['account_id' => $acct ? (int)$acct['id'] : null, 'code' => $acct['code'] ?? null, 'name' => $acct['name'] ?? null,
                    'reason' => $reason, 'source' => 'found_receipt', 'expense_id' => (int)$f['expense_id']];
        }
        // 1. The receipt it's matched to.
        $e = $ctx['expenses'][(int)($line['matched_expense_id'] ?? 0)] ?? null;
        if ($e && ($a = $ctx['byAlias'][strtolower((string)$e['accounting_category'])] ?? null)) {
            if ($r = $pick($a, 'Matched to your ' . ($e['vendor'] ?: 'receipt') . ' receipt, booked as ' . $e['accounting_category'], 'receipt')) return $r;
        }
        // 1b. A deposit matched to a contract invoice → Contract Income.
        $inv = $ctx['contractInvoices'][(int)($line['matched_invoice_id'] ?? 0)] ?? null;
        if ($inv && ($a = $ctx['byCode'][self::CONTRACT_INCOME_CODE] ?? null)) {
            if ($r = $pick($a, 'Paid on contract invoice ' . $inv, 'invoice')) return $r;
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
        // 2b. What you've taught her for this description (not yet trusted to act alone).
        $key = BankImportService::descriptionKey($desc);
        foreach ($ctx['learned'] ?? [] as $lr) {
            if ($key === '' || strpos($key, (string)$lr['condition_value']) === false) continue;
            $a = ['id' => (int)$lr['id'], 'code' => $lr['code'], 'name' => $lr['name']];
            if ($r = $pick($a, 'You\'ve put this on ' . $lr['name'] . ' ' . (int)$lr['learned_count'] . ' times (the import does it alone at ' . BankRuleLearning::CONFIRMATIONS . ')', 'learned')) return $r;
        }
        // 3. A rule.
        $m = $ctx['rules'][(int)($line['id'] ?? 0)] ?? null;
        if ($m && ($a = $ctx['byCode'][$m['account_code']] ?? null)) {
            if ($r = $pick($a, 'Your rule "' . ($m['rule_name'] ?? 'rule') . '"', 'rule')) return $r;
        }
        // 4. Plain facts.
        foreach (self::FACTS as $key => [$re, $why]) {
            if (!preg_match($re, $desc, $hit)) continue;
            $a = in_array($key, ['fuel', 'meals'], true) ? ($ctx['byAlias'][$key] ?? null) : ($ctx['byCode'][$key] ?? null);
            if ($r = $pick($a, ucfirst(strtolower(trim($hit[0]))) . ' ' . $why, 'fact')) return $r;
        }
        return null;
    }

    /** A note for lines she shouldn't categorize here (they belong to invoice matching). */
    public static function note(array $line): ?string
    {
        $d = (string)($line['description'] ?? '');
        if (($line['type'] ?? '') === 'income' && preg_match('/\bSTRIPE\b/i', $d)) {
            return 'This is a Stripe payout — card payments from your customers, already counted on their invoices. I\'ll match payouts to invoices in my next bank step; skip it for now.';
        }
        return null;
    }

    /** Upper-case letters and digits only, single-spaced — for name-in-description checks. */
    public static function plain(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($s))));
    }
}
