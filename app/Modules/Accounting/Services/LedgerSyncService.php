<?php
/**
 * LedgerSyncService — posts the double-entry journal from invoices & expenses
 * (Phase 1, Step 3). ADDITIVE: runs alongside the legacy single-entry sync;
 * every record is wrapped so a journal failure can never break the legacy
 * accounting_transactions write or abort the batch. Idempotent via the
 * journal_entries UNIQUE(source_type, source_id) constraint, so re-running
 * (or a one-time back-fill of history) is safe.
 *
 * Accrual basis:
 *   - invoice raised   -> DR AR / CR Revenue (+ GST collected)   [date = issue_date]
 *   - customer payment -> DR Bank / CR AR                        [date = paid_at]
 *   - expense          -> DR Expense (+PST) + DR GST ITC / CR funding
 */
declare(strict_types=1);

require_once __DIR__ . '/LedgerAccountMap.php';

require_once __DIR__ . '/GstReportService.php';

class LedgerSyncService
{
    private PDO $db;
    private LedgerService $ledger;

    public function __construct(PDO $db, LedgerService $ledger)
    {
        $this->db = $db;
        $this->ledger = $ledger;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PURE MAPPERS (no DB) — unit-tested
    // ══════════════════════════════════════════════════════════════════════════

    /** Map an invoice row to buildInvoiceEntry() args (accrual: full subtotal + tax). */
    public function mapInvoiceToInvoiceArgs(array $row): array
    {
        return [
            'id'           => (int)$row['id'],
            'date'         => substr((string)(($row['issue_date'] ?? null) ?: ($row['created_at'] ?? '')), 0, 10),
            'net'          => round((float)($row['subtotal'] ?? 0), 2),
            'gst'          => round((float)($row['tax_amount'] ?? 0), 2),
            'contact_id'   => !empty($row['contact_id']) ? (int)$row['contact_id'] : null,
            'job_id'       => !empty($row['plan_id']) ? (int)$row['plan_id'] : null,
            'service_type' => $row['service_type'] ?? null,
        ];
    }

    /** Map an invoice row to buildPaymentEntry() args, or null if nothing paid. */
    public function mapInvoiceToPaymentArgs(array $row): ?array
    {
        $paid = round((float)($row['amount_paid'] ?? 0), 2);
        if ($paid <= 0) {
            return null;
        }
        return [
            'id'         => (int)$row['id'],            // one payment per invoice in this model
            'invoice_id' => (int)$row['id'],
            'date'       => substr((string)(($row['paid_at'] ?? null) ?: ($row['created_at'] ?? '')), 0, 10),
            'amount'     => $paid,
            'contact_id' => !empty($row['contact_id']) ? (int)$row['contact_id'] : null,
        ];
    }

    /**
     * Map an expense row to buildExpenseEntry() args.
     * expense.total is the GROSS (tax-inclusive) amount; GST is recoverable (ITC),
     * PST is a non-recoverable cost. net = total − gst − pst.
     *
     * @param array $categoryToCode    accounting_category(lower) => chart code
     * @param array $categoryToCostType accounting_category(lower) => cost_type_id
     */
    public function mapExpenseToExpenseArgs(array $row, array $categoryToCode, array $categoryToCostType): array
    {
        $total = round((float)$row['total'], 2);
        $gst   = round((float)($row['gst_amount'] ?? 0), 2);
        $pst   = round((float)($row['pst_amount'] ?? 0), 2);
        $cat   = strtolower(trim((string)($row['accounting_category'] ?? '')));

        return [
            'id'              => (int)$row['id'],
            'date'            => substr((string)$row['expense_date'], 0, 10),
            'net'             => round($total - $gst - $pst, 2),
            'gst'             => $gst,
            'pst'             => $pst,
            'expense_account' => self::personalAccount($row, $cat) ?? LedgerAccountMap::refineExpenseCode($categoryToCode[$cat] ?? '6900', $cat,
                                     $row['asset_tag'] ?? null, (string)($row['vendor_name'] ?? $row['vendor_name_raw'] ?? '')),
            'funding'         => $this->fundingAccountFor((string)($row['payment_method'] ?? '')),
            'cost_type_id'    => $categoryToCostType[$cat] ?? null,
            'service_type'    => $row['service_type'] ?? null,
            'job_id'          => !empty($row['job_id']) ? (int)$row['job_id'] : null,
            'vendor_id'       => !empty($row['vendor_id']) ? (int)$row['vendor_id'] : null,
            'meals'           => GstReportService::isMeals($row['accounting_category'] ?? null, $this->gstSettings()['meals_categories']),
            'meals_itc_rate'  => $this->gstSettings()['meals_rate'],
        ] + (in_array($cat, self::CAPITAL_RULE_CATEGORIES, true) ? ['capital_rule' => ['threshold' => $this->capitalThreshold()]] : []);
    }

    /** Receipt categories whose items of the capital threshold or more each are equipment (CapitalItemRule). */
    public const CAPITAL_RULE_CATEGORIES = ['tools/equipment'];

    private ?float $capitalThresholdCache = null;

    /** ops_settings capital_threshold (dollars, before tax), default $500 — the standard capitalisation threshold. */
    public function capitalThreshold(): float
    {
        if ($this->capitalThresholdCache === null) {
            $this->capitalThresholdCache = 500.0;
            try {
                $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'capital_threshold'");
                $s->execute();
                $v = $s->fetchColumn();
                if ($v !== false && is_numeric($v) && (float)$v > 0) $this->capitalThresholdCache = (float)$v;
            } catch (\Throwable $e) { /* default */ }
        }
        return $this->capitalThresholdCache;
    }

    /**
     * A Personal receipt always posts to a shareholder: the one marked on it (expenses.shareholder_account,
     * migration 1376 — Katrenna's 1302), else 1300 Due from Shareholder — never the category map, so a
     * missing or changed map can't turn a personal purchase into a cost that claims GST (review 2026-10-19). PURE.
     */
    public static function personalAccount(array $row, string $cat): ?string
    {
        if ($cat !== 'personal') return null;
        $code = trim((string)($row['shareholder_account'] ?? ''));
        return LedgerService::isShareholderAccount($code) ? $code : LedgerService::ACC_DUE_FROM_SH;
    }

    private ?array $gstSettingsCache = null;

    /** The meals categories and ITC rate, read once (GstReportService owns them). */
    private function gstSettings(): array
    {
        if ($this->gstSettingsCache === null) {
            try {
                $this->gstSettingsCache = (new GstReportService($this->db))->settings();
            } catch (\Throwable $e) {
                $this->gstSettingsCache = GstReportService::parseSettings(null, null);
            }
        }
        return $this->gstSettingsCache;
    }

    /** Funding account code from a payment method (see fundingCodeFor). */
    public function fundingAccountFor(string $paymentMethod): string
    {
        return self::fundingCodeFor($paymentMethod);
    }

    /** Payment methods that are a credit card (2400). company_card = the Vancity Visa. */
    public const CARD_METHODS = ['credit_card', 'credit', 'company_card', 'visa', 'mastercard', 'amex', 'card'];

    /**
     * Funding account code from a payment method — explicit, not "contains card" (2026-10-12:
     * "debit_card" went to 2400 Credit Card Payable). credit_card / company_card → 2400;
     * debit / debit_card / interac / etransfer / cheque / cash / unknown → 1010 Chequing (the
     * chart has no petty-cash account). Pure.
     */
    public static function fundingCodeFor(?string $paymentMethod): string
    {
        $pm = trim((string)preg_replace('/[\s\-]+/', '_', strtolower(trim((string)$paymentMethod))), '_');
        // Paid with the owner's own card or cash for the business (2026-10-13): the company owes
        // him, so it comes off what he owes on the shareholder loan — 1300, not a business account.
        if (in_array($pm, ['owner_paid', 'paid_personally', 'personal_card'], true)) {
            return LedgerService::ACC_DUE_FROM_SH;
        }
        if ($pm === '' || str_contains($pm, 'debit')) {
            return LedgerService::ACC_BANK;        // 1010 — a debit card spends from chequing
        }
        if (in_array($pm, self::CARD_METHODS, true) || str_contains($pm, 'credit')) {
            return LedgerService::ACC_CREDIT_CARD; // 2400
        }
        return LedgerService::ACC_BANK;            // 1010 — interac, etransfer, cheque, cash, anything else
    }

    /**
     * Map a bank-import ledger row (accounting_transactions, reference_type='bank_import')
     * to a balanced journal entry — using account_id directly (already chart ids).
     * Returns null when the row should be SKIPPED to avoid double-counting:
     * an income deposit categorised to a revenue account is a customer payment
     * already recognised on the invoice side.
     *
     * A 'transfer' row can be money OUT (card payoff, loan payment, owner's draw) or money
     * IN (a deposit reconciliation flipped to 'transfer' once its invoices carry it). The
     * row's type cannot tell them apart, so the caller passes 'direction' (see
     * transferDirection(); missing = 'out', the old behaviour) and 'settled':
     *   OUT                  -> DR category / CR bank
     *   IN, settled by invoice payments (allocations, "already recorded", matched)
     *                        -> null: the invoice's payment entry already debits the bank
     *                           (DR Bank / CR AR) — posting the deposit too doubles the cash
     *   IN to a revenue account -> null (revenue is recognised on invoices)
     *   IN otherwise         -> DR bank / CR category (money in from a loan, savings, owner)
     *
     * @param array $row id, transaction_date, type, account_id, account_type (category's
     *                   chart type), account_code, bank_account_id, amount, gst_amount,
     *                   pst_amount, description, job_id, contact_id, vendor_id,
     *                   direction? ('in'|'out'), settled? (bool)
     * @param int   $itcId            chart id of 2210 GST ITC
     * @param int   $gstCollectedId   chart id of 2200 GST Collected
     * @param int   $defaultBankId    chart id of 1010 (fallback when bank_account_id is null)
     * @param array $codeToCostTypeId account code => cost_types.id (for GGOB drill-down)
     */
    public function bankRowToEntryArgs(array $row, int $itcId, int $gstCollectedId, int $defaultBankId, array $codeToCostTypeId = []): ?array
    {
        $type    = $row['type'];
        $catId   = (int)$row['account_id'];
        $bankId  = !empty($row['bank_account_id']) ? (int)$row['bank_account_id'] : $defaultBankId;
        $amount  = round((float)$row['amount'], 2);
        // Holding accounts (migration 1364, plan 2026-10-14): on/after the switch date a customer deposit
        // moves money from 1015/1016 into the bank, and Stripe's fee comes out of 1015 — see holdingEntry().
        $held = $this->holdingEntry($row, $bankId, $amount);
        if ($held !== false) return $held;
        $gst     = round((float)($row['gst_amount'] ?? 0), 2);
        $pst     = round((float)($row['pst_amount'] ?? 0), 2);
        // A card refund / statement credit is staged as a NEGATIVE expense: money back onto the card,
        // the cost reduced. It used to be skipped here, so the P&L netted it and the journal didn't
        // (review 2026-10-12). Reverse of an expense: DR the card/bank, CR the cost (and the ITC).
        if ($amount < 0 && $type === 'expense' && $catId !== $bankId) {
            $mag = -$amount; $g = abs($gst);
            $lines = [['account_id' => $bankId, 'debit' => $mag, 'credit' => 0]];
            $lines[] = ['account_id' => $catId, 'debit' => 0, 'credit' => round($mag - $g, 2),
                        'gst_amount' => -$g, 'description' => $row['description'] ?? null];
            if ($g > 0) $lines[] = ['account_id' => $itcId, 'debit' => 0, 'credit' => $g, 'gst_amount' => -$g];
            return ['entry_date' => substr((string)$row['transaction_date'], 0, 10),
                    'memo' => $row['description'] ?? 'Refund', 'source_type' => 'bank_import',
                    'source_id' => (int)$row['id'], 'lines' => $lines];
        }
        if ($amount <= 0) {
            return null;
        }
        // A line filed on its own bank account moves nothing: the savings side of a chequing ↔
        // savings transfer (its chequing line carries the transfer — BankImportService /
        // BankAccountSplitService, 2026-10-07).
        if ($catId === $bankId) {
            return null;
        }

        // Revenue is recognised on invoices — skip deposit rows hitting a revenue account.
        if ($type === 'income' && ($row['account_type'] ?? '') === 'revenue') {
            return null;
        }

        $costTypeId = $codeToCostTypeId[$row['account_code'] ?? ''] ?? null;
        $dims = [
            'job_id'       => !empty($row['job_id'])     ? (int)$row['job_id']     : null,
            'contact_id'   => !empty($row['contact_id']) ? (int)$row['contact_id'] : null,
            'vendor_id'    => !empty($row['vendor_id'])  ? (int)$row['vendor_id']  : null,
            'cost_type_id' => $costTypeId,
        ];

        $lines = [];
        if ($type === 'expense') {
            $lines[] = array_merge(['account_id' => $catId, 'debit' => round($amount - $gst, 2), 'credit' => 0,
                'gst_amount' => $gst, 'pst_amount' => $pst, 'description' => $row['description'] ?? null], $dims);
            if ($gst > 0) {
                $lines[] = ['account_id' => $itcId, 'debit' => $gst, 'credit' => 0, 'gst_amount' => $gst];
            }
            $lines[] = ['account_id' => $bankId, 'debit' => 0, 'credit' => $amount];
        } elseif ($type === 'transfer' && ($row['direction'] ?? 'out') === 'in') {
            if (!empty($row['settled']) || ($row['account_type'] ?? '') === 'revenue') {
                return null;
            }
            $lines[] = ['account_id' => $bankId, 'debit' => $amount, 'credit' => 0];
            $lines[] = array_merge(['account_id' => $catId, 'debit' => 0, 'credit' => $amount,
                'description' => $row['description'] ?? null], $dims);
        } elseif ($type === 'transfer') {
            $lines[] = array_merge(['account_id' => $catId, 'debit' => $amount, 'credit' => 0,
                'description' => $row['description'] ?? null], $dims);
            $lines[] = ['account_id' => $bankId, 'debit' => 0, 'credit' => $amount];
        } else { // income to a non-revenue account (loan proceeds, interest, owner contribution)
            $lines[] = ['account_id' => $bankId, 'debit' => $amount, 'credit' => 0];
            $lines[] = array_merge(['account_id' => $catId, 'debit' => 0, 'credit' => round($amount - $gst, 2),
                'gst_amount' => $gst, 'description' => $row['description'] ?? null], $dims);
            if ($gst > 0) {
                $lines[] = ['account_id' => $gstCollectedId, 'debit' => 0, 'credit' => $gst, 'gst_amount' => $gst];
            }
        }

        return [
            'entry_date'  => substr((string)$row['transaction_date'], 0, 10),
            'memo'        => $row['description'] ?? 'Bank transaction',
            'source_type' => 'bank_import',
            'source_id'   => (int)$row['id'],
            'lines'       => $lines,
        ];
    }

    /**
     * The holding-account posting for a bank line, or false when the line posts as before (switch off,
     * dated before the switch, or not a customer deposit / Stripe fee). Rules:
     *   - Stripe's fee line (StripePayoutService / the import's single-charge match): DR fee / CR 1015.
     *   - a customer deposit into the bank — settled by payments (allocations, "already recorded",
     *     matched_invoice_id) or left as income on a revenue account — DR bank / CR 1015 when it is a
     *     Stripe payout, else CR 1016 (cheques, e-Transfers, EFT, Jobber Payments payouts).
     * @return array|null|false entry args, null (posts nothing), false (not ours: the old rules apply)
     */
    public function holdingEntry(array $row, int $bankId, float $amount)
    {
        $from = $this->ledger->holdingFrom();
        $date = substr((string)($row['transaction_date'] ?? ''), 0, 10);
        if ($from === null || $date < $from || $amount <= 0) return false;
        $desc = (string)($row['description'] ?? '');
        $type = (string)($row['type'] ?? '');
        $isStripe = self::isStripeText($desc);

        if ($type === 'expense' && self::isStripeFeeLine($desc)) {
            return ['entry_date' => $date, 'memo' => $desc, 'source_type' => 'bank_import', 'source_id' => (int)$row['id'],
                    'lines' => [
                        ['account_id' => (int)$row['account_id'], 'debit' => $amount, 'credit' => 0, 'description' => $desc],
                        ['account_id' => $this->ledger->accountId(LedgerService::ACC_STRIPE_CLEARING), 'debit' => 0, 'credit' => $amount],
                    ]];
        }
        $customerIn =
            ($type === 'transfer' && ($row['direction'] ?? 'out') === 'in' && (!empty($row['settled']) || ($row['account_type'] ?? '') === 'revenue'))
            || ($type === 'income' && ($row['account_type'] ?? '') === 'revenue')
            || !empty($row['matched_invoice_id']);
        if (!$customerIn) return false;
        if ((int)($row['account_id'] ?? 0) === $bankId) return null;    // the self-filed side of a pair
        $hold = $this->ledger->accountId($isStripe ? LedgerService::ACC_STRIPE_CLEARING : LedgerService::ACC_UNDEPOSITED);
        return ['entry_date' => $date, 'memo' => $desc !== '' ? $desc : 'Customer deposit', 'source_type' => 'bank_import', 'source_id' => (int)$row['id'],
                'lines' => [
                    ['account_id' => $bankId, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $hold, 'debit' => 0, 'credit' => $amount, 'description' => $desc,
                     'contact_id' => !empty($row['contact_id']) ? (int)$row['contact_id'] : null],
                ]];
    }

    /** A deposit from OUR Stripe account (not Jobber Payments, which also says "by Stripe"). Pure. */
    public static function isStripeText(string $desc): bool
    {
        return stripos($desc, 'stripe') !== false && stripos($desc, 'jobber') === false;
    }

    /** A Stripe fee line booked against a payout (StripePayoutService, BankImportService). Pure. */
    public static function isStripeFeeLine(string $desc): bool
    {
        return stripos($desc, 'Stripe fees') === 0
            || (stripos($desc, 'Payment processing fee') === 0 && self::isStripeText($desc));
    }

    /**
     * Which way a bank line moved money, from what the statement said — never from
     * accounting_transactions.type, which reconciliation overwrites ('income' -> 'transfer').
     *   - tied to invoice payments (allocation rows point at it, "Marked as already recorded",
     *     matched_invoice_id): a customer deposit -> 'in';
     *   - the staged statement line (bank_import_rows.type, set by the parser from the
     *     credit / debit column) says 'income' -> 'in';
     *   - anything else -> 'out' (card payoffs and loan payments are staged 'expense').
     * bank_import_rows.raw_amount is NOT used: commit() stages a line already promoted to
     * 'transfer' (a card payoff) with a positive raw_amount although the money went out.
     * Pure.
     * @param array $facts staged_type ?string, settled bool
     */
    public static function transferDirection(array $facts): string
    {
        if (!empty($facts['settled'])) return 'in';
        return ($facts['staged_type'] ?? null) === 'income' ? 'in' : 'out';
    }

    /**
     * Direction facts for bank lines: txId => [staged_type, settled]. Only rows with a fact
     * are returned. Each source is optional (older schemas / tests) — a missing table
     * just contributes nothing.
     * @param int|null $txId one line, or null for every bank line
     */
    public function directionFacts(?int $txId = null): array
    {
        $facts = [];
        $one = $txId !== null ? ' AND transaction_id = ' . (int)$txId : '';
        try {
            foreach ($this->db->query("SELECT transaction_id, type FROM bank_import_rows WHERE transaction_id IS NOT NULL{$one}")
                         ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int)$r['transaction_id'];
                // one statement line per transaction; if two ever point at it, 'income' wins (it names a deposit)
                if (($facts[$id]['staged_type'] ?? null) !== 'income') $facts[$id]['staged_type'] = (string)$r['type'];
            }
        } catch (\Throwable $e) { /* no staging table */ }
        try {
            foreach ($this->db->query("SELECT DISTINCT transaction_id FROM invoice_payment_allocations WHERE transaction_id IS NOT NULL{$one}")
                         ->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $facts[(int)$id]['settled'] = true;
            }
        } catch (\Throwable $e) { /* no allocations table */ }
        try {
            $oneAt = $txId !== null ? ' AND id = ' . (int)$txId : '';
            foreach ($this->db->query("SELECT id FROM accounting_transactions
                                       WHERE reference_type = 'bank_import' AND type = 'transfer'
                                         AND (matched_invoice_id IS NOT NULL OR notes LIKE '%Marked as already recorded%'){$oneAt}")
                         ->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $facts[(int)$id]['settled'] = true;
            }
        } catch (\Throwable $e) { /* no notes / matched_invoice_id column */ }
        return $facts;
    }

    /** $row + direction / settled from $facts (keyed by the row id). Pure. */
    public static function withDirection(array $row, array $facts): array
    {
        $f = $facts[(int)($row['id'] ?? 0)] ?? [];
        $row['settled'] = !empty($f['settled']);
        $row['direction'] = self::transferDirection($f);
        return $row;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DB SYNC (idempotent, per-record guarded)
    // ══════════════════════════════════════════════════════════════════════════

    public function syncAll(): array
    {
        return [
            'invoices'     => $this->syncInvoices(),
            'expenses'     => $this->syncExpenses(),
            'bank_imports' => $this->syncBankImports(),
        ];
    }

    /**
     * Post unmatched bank-import rows to the journal (labour, subs, payroll, fees,
     * loans, card/loan payments). Matched rows are already in the journal from the
     * invoice/expense side and are excluded; so are deposits settled through
     * invoice_payment_allocations (bankRowToEntryArgs returns null for them — the
     * invoice payment entry carries the cash).
     */
    public function syncBankImports(): array
    {
        $itcId    = $this->ledger->accountId(LedgerService::ACC_GST_ITC);
        $gstColId = $this->ledger->accountId(LedgerService::ACC_GST_COLLECTED);
        $bankId   = $this->ledger->accountId(LedgerService::ACC_BANK);
        $codeToCostType = $this->accountCodeToCostTypeMap();

        $posted = 0; $skipped = 0; $errors = 0; $failures = [];
        $rows = $this->db->query("
            SELECT at.id, at.transaction_date, at.type, at.account_id,
                   coa.type AS account_type, coa.code AS account_code,
                   at.bank_account_id, at.amount, at.gst_amount, at.pst_amount,
                   at.description, at.job_id, at.contact_id, at.vendor_id, at.matched_invoice_id
            FROM accounting_transactions at
            JOIN chart_of_accounts coa ON coa.id = at.account_id
            WHERE at.reference_type = 'bank_import'
              AND (at.matched_invoice_id IS NULL OR at.transaction_date >= " . $this->db->quote((string)($this->ledger->holdingFrom() ?? '9999-12-31')) . ")
              AND at.matched_expense_id IS NULL
              AND COALESCE(at.status, '') NOT IN ('void', 'deleted')   -- voided lines are not money (review 2026-10-12)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $facts = $this->directionFacts();
        $deposits = $this->bankDepositEntries();
        foreach ($rows as $row) {
            if (isset($deposits[(int)$row['id']])) { $skipped++; continue; }   // its cash is posted as a 'bank_deposit' (1236)
            try {
                $args = $this->bankRowToEntryArgs(self::withDirection($row, $facts), $itcId, $gstColId, $bankId, $codeToCostType);
                if ($args === null) { $skipped++; continue; }
                $this->ledger->postManual($args);
                $posted++;
            } catch (\Throwable $e) {
                $this->noteFailure('bank line', (int)$row['id'], $e, $skipped, $errors, $failures);
            }
        }
        return ['bank_posted' => $posted, 'skipped' => $skipped, 'errors' => $errors, 'failures' => $failures];
    }

    /** The entry a bank line should post now (null = it shouldn't post: revenue deposit, linked or settled deposit, zero). */
    public function bankEntryArgsFor(int $transactionId): ?array
    {
        $s = $this->db->prepare("
            SELECT at.id, at.transaction_date, at.type, at.account_id,
                   coa.type AS account_type, coa.code AS account_code,
                   at.bank_account_id, at.amount, at.gst_amount, at.pst_amount,
                   at.description, at.job_id, at.contact_id, at.vendor_id, at.matched_invoice_id
            FROM accounting_transactions at
            JOIN chart_of_accounts coa ON coa.id = at.account_id
            WHERE at.id = ? AND at.reference_type = 'bank_import'
              AND (at.matched_invoice_id IS NULL OR at.transaction_date >= ?) AND at.matched_expense_id IS NULL
        ");
        $s->execute([$transactionId, (string)($this->ledger->holdingFrom() ?? '9999-12-31')]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (isset($this->bankDepositEntries($transactionId)[$transactionId])) return null;   // posted as a 'bank_deposit' (1236)
        $row = self::withDirection($row, $this->directionFacts($transactionId));
        return $this->bankRowToEntryArgs($row, $this->ledger->accountId(LedgerService::ACC_GST_ITC),
            $this->ledger->accountId(LedgerService::ACC_GST_COLLECTED), $this->ledger->accountId(LedgerService::ACC_BANK),
            $this->accountCodeToCostTypeMap());
    }

    /**
     * Bank lines whose cash the bank balance check posted itself (a Jobber-era deposit with no
     * CRM invoice: 'bank_deposit', migration 1236): txId => entry id. They must never post a
     * second time as 'bank_import'.
     */
    public function bankDepositEntries(?int $txId = null): array
    {
        $out = [];
        try {
            $one = $txId !== null ? ' AND source_id = ' . (int)$txId : '';
            foreach ($this->db->query("SELECT id, source_id FROM journal_entries WHERE source_type = 'bank_deposit' AND reversed_by_entry_id IS NULL{$one}")
                         ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['source_id']] = (int)$r['id'];
            }
        } catch (\Throwable $e) { /* nothing posted that way */ }
        return $out;
    }

    /** Chart code => cost_types.id, so bank cost rows carry the GGOB drill-down dimension. */
    private function accountCodeToCostTypeMap(): array
    {
        $ids = [];
        foreach ($this->db->query("SELECT id, name FROM cost_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ids[strtolower($r['name'])] = (int)$r['id'];
        }
        $codeToName = [
            '5100' => 'labour', '5200' => 'materials', '5300' => 'equipment',
            '5400' => 'subcontractor', '6100' => 'fuel', '6200' => 'equipment',
        ];
        $map = [];
        foreach ($codeToName as $code => $name) {
            if (isset($ids[$name])) { $map[$code] = $ids[$name]; }
        }
        return $map;
    }

    /**
     * Is this invoice revenue? A draft was never issued; a cancelled or void one was withdrawn.
     * Those must not sit in the journal as revenue + GST + AR (review 2026-10-12: 4 did, $1,181.55).
     */
    public static function invoiceIsIssued(?string $status): bool
    {
        return !in_array(strtolower(trim((string)$status)), ['draft', 'cancelled', 'canceled', 'void', ''], true);
    }

    /**
     * A record the nightly sync could not post. A closed-year refusal (dated before FY_START) is an
     * expected skip; anything else (unmapped account, locked month, bad entry, a code error) is a
     * failure: logged with its source and returned so the cron reports it. Until 2026-10-13 every
     * one was counted as "skipped" and the cron said success — the same silent-catch family as the
     * Stripe fee bug (StripePayoutService built LedgerSyncService with one argument).
     */
    private function noteFailure(string $source, int $id, \Throwable $e, int &$skipped, int &$errors, array &$failures): void
    {
        if (self::isExpectedSkip($e)) { $skipped++; return; }
        $errors++;
        error_log("[LedgerSync] {$source} #{$id} not posted: " . $e->getMessage());
        if (count($failures) < 20) $failures[] = ['source' => $source, 'id' => $id, 'reason' => $e->getMessage()];
    }

    /** Pure (tested): is this refusal an expected skip rather than a failure? */
    public static function isExpectedSkip(\Throwable $e): bool
    {
        return strpos($e->getMessage(), 'Closed year:') === 0;
    }

    public function syncInvoices(): array
    {
        require_once __DIR__ . '/LedgerAccountMap.php';
        $map = new LedgerAccountMap($this->db);
        $posted = 0; $payments = 0; $skipped = 0; $errors = 0; $failures = [];
        $cols = "id, subtotal, tax_amount, total, amount_paid, status, contact_id, plan_id, contract_id, issue_date, paid_at, created_at";
        try {   // payment_method: a Stripe-paid invoice's payment waits in 1015 (holding accounts; Codex review 2026-10-14)
            $rows = $this->db->query("SELECT {$cols}, payment_method FROM invoices WHERE COALESCE(total, 0) > 0")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rows = $this->db->query("SELECT {$cols} FROM invoices WHERE COALESCE(total, 0) > 0")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Migration 1236: each payment is its own entry (InvoicePaymentPlanner). Before it ran, the
        // old way: one payment entry per invoice for amount_paid at the first sync.
        require_once __DIR__ . '/InvoicePaymentPlanner.php';
        $planner = new InvoicePaymentPlanner($this->db);
        $plans = null;
        if ($planner->goForwardFrom() !== null) {
            try { $plans = $planner->planAll(); } catch (\Throwable $e) { $plans = null; error_log('[LedgerSync] payment plan: ' . $e->getMessage()); }
        }

        $reversed = 0;
        foreach ($rows as $row) {
            try {
                if (!self::invoiceIsIssued($row['status'] ?? null)) {
                    // Not (or no longer) an issued invoice: take its revenue entry back out. Only when
                    // nothing was paid on it — a payment on a cancelled invoice is for a person to sort out.
                    $eid = $this->ledger->findEntryIdBySource('invoice', (int)$row['id']);
                    if ($eid !== null && (float)($row['amount_paid'] ?? 0) <= 0) {
                        $this->ledger->reverseEntry($eid, null, 'invoice is ' . $row['status'] . ' — not revenue', 'penny');
                        $reversed++;
                    }
                    continue;
                }
                $args = $this->mapInvoiceToInvoiceArgs($row);
                if ($map->ready()) {
                    $args['revenue_splits'] = $map->invoiceSplits($row, $args['net']);
                }
                $this->ledger->postInvoice($args);
                $posted++;
                if ($plans !== null) {
                    if (isset($plans[(int)$row['id']])) $payments += $planner->applyAuto($plans[(int)$row['id']], $this->ledger);
                    continue;
                }
                $pay = $this->mapInvoiceToPaymentArgs($row);
                if ($pay !== null) {
                    $pay['bank_account'] = LedgerService::paymentAccount('payment', (string)$pay['date'], $this->ledger->holdingFrom(), $row['payment_method'] ?? null);
                    $this->ledger->postPayment($pay);
                    $payments++;
                }
            } catch (\Throwable $e) {
                // never abort the batch — but a real failure is reported, not counted as a skip (2026-10-13)
                $this->noteFailure('invoice', (int)$row['id'], $e, $skipped, $errors, $failures);
            }
        }
        return ['invoices_posted' => $posted, 'payments_posted' => $payments, 'skipped' => $skipped, 'invoices_reversed' => $reversed,
                'errors' => $errors, 'failures' => $failures];
    }

    public function syncExpenses(): array
    {
        require_once __DIR__ . '/LedgerAccountMap.php';
        // The owner's category → account map (migration 1130) wins over chart aliases.
        $categoryToCode = (new LedgerAccountMap($this->db))->categoryCodes() + $this->categoryToCodeMap();
        $categoryToCost = $this->categoryToCostTypeMap();
        $posted = 0; $skipped = 0; $errors = 0; $failures = [];

        $rows = $this->db->query("
            SELECT id, expense_date, total, gst_amount, pst_amount, accounting_category,
                   payment_method, vendor_id, job_id, contact_id, asset_tag, vendor_name_raw, shareholder_account,
                   (SELECT v.name FROM vendors v WHERE v.id = expenses.vendor_id) AS vendor_name
            FROM expenses
            WHERE total > 0 AND status IN ('approved', 'forwarded')
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            try {
                $this->ledger->postExpense($this->mapExpenseToExpenseArgs($row, $categoryToCode, $categoryToCost));
                $posted++;
            } catch (\Throwable $e) {
                $this->noteFailure('expense', (int)$row['id'], $e, $skipped, $errors, $failures);
            }
        }
        // A receipt linked to its bank line after both had posted: retire the line's own posting (no double count).
        $retired = 0;
        $linked = $this->db->query("
            SELECT DISTINCT at.matched_expense_id FROM accounting_transactions at
            JOIN journal_entries je ON je.source_type = 'bank_import' AND je.source_id = at.id
                 AND je.status = 'posted' AND je.reversed_by_entry_id IS NULL
            WHERE at.reference_type = 'bank_import' AND at.matched_expense_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($linked as $expenseId) {
            try {
                $retired += $this->ledger->retireLinkedBankEntries((int)$expenseId);
            } catch (\Throwable $e) {
                $this->noteFailure('expense', (int)$expenseId, $e, $skipped, $errors, $failures);
            }
        }
        return ['expenses_posted' => $posted, 'bank_lines_retired' => $retired, 'skipped' => $skipped, 'errors' => $errors, 'failures' => $failures];
    }

    /** Expense posting args with the same maps the nightly sync uses (repost shares this). */
    public function expenseArgs(array $row): array
    {
        static $maps = null;
        if ($maps === null) {
            require_once __DIR__ . '/LedgerAccountMap.php';
            $maps = [(new LedgerAccountMap($this->db))->categoryCodes() + $this->categoryToCodeMap(), $this->categoryToCostTypeMap()];
        }
        return $this->mapExpenseToExpenseArgs($row, $maps[0], $maps[1]);
    }

    /** accounting_category(lower) => chart_of_accounts.code (via expense_category_alias). */
    private function categoryToCodeMap(): array
    {
        $map = [];
        $rows = $this->db->query("
            SELECT code, expense_category_alias
            FROM chart_of_accounts
            WHERE expense_category_alias IS NOT NULL AND is_active = 1
              AND type NOT IN ('revenue', 'income')   -- revenue accounts alias SERVICE types (4900 'other'), never a receipt
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $map[strtolower($r['expense_category_alias'])] = $r['code'];
        }
        return $map;
    }

    /** accounting_category(lower) => cost_types.id, best-effort by name match. */
    private function categoryToCostTypeMap(): array
    {
        $ids = [];
        foreach ($this->db->query("SELECT id, name FROM cost_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ids[strtolower($r['name'])] = (int)$r['id'];
        }
        // map common expense categories onto cost types
        $alias = [
            'materials' => 'materials', 'material' => 'materials',
            'labour' => 'labour', 'labor' => 'labour', 'wages' => 'labour',
            'fuel' => 'fuel', 'gas' => 'fuel',
            'equipment' => 'equipment', 'tools/equipment' => 'equipment', 'tools' => 'equipment',
            'equipment_rental' => 'equipment', 'equipment rental' => 'equipment',
            'subcontractors' => 'subcontractor', 'subcontractor' => 'subcontractor',
        ];
        $map = [];
        foreach ($alias as $cat => $costName) {
            if (isset($ids[$costName])) {
                $map[$cat] = $ids[$costName];
            }
        }
        return $map;
    }
}
