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
            'expense_account' => LedgerAccountMap::refineExpenseCode($categoryToCode[$cat] ?? '6900', $cat,
                                     $row['asset_tag'] ?? null, (string)($row['vendor_name'] ?? $row['vendor_name_raw'] ?? '')),
            'funding'         => $this->fundingAccountFor((string)($row['payment_method'] ?? '')),
            'cost_type_id'    => $categoryToCostType[$cat] ?? null,
            'service_type'    => $row['service_type'] ?? null,
            'job_id'          => !empty($row['job_id']) ? (int)$row['job_id'] : null,
            'vendor_id'       => !empty($row['vendor_id']) ? (int)$row['vendor_id'] : null,
        ];
    }

    /** Funding account code from a payment method. Card → 2400, else → 1010. */
    public function fundingAccountFor(string $paymentMethod): string
    {
        $pm = strtolower(trim($paymentMethod));
        if (str_contains($pm, 'credit') || str_contains($pm, 'card')) {
            return LedgerService::ACC_CREDIT_CARD; // 2400
        }
        return LedgerService::ACC_BANK; // 1010
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
        $gst     = round((float)($row['gst_amount'] ?? 0), 2);
        $pst     = round((float)($row['pst_amount'] ?? 0), 2);
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

        $posted = 0; $skipped = 0; $errors = 0;
        $rows = $this->db->query("
            SELECT at.id, at.transaction_date, at.type, at.account_id,
                   coa.type AS account_type, coa.code AS account_code,
                   at.bank_account_id, at.amount, at.gst_amount, at.pst_amount,
                   at.description, at.job_id, at.contact_id, at.vendor_id
            FROM accounting_transactions at
            JOIN chart_of_accounts coa ON coa.id = at.account_id
            WHERE at.reference_type = 'bank_import'
              AND at.matched_invoice_id IS NULL
              AND at.matched_expense_id IS NULL
        ")->fetchAll(PDO::FETCH_ASSOC);

        $facts = $this->directionFacts();
        foreach ($rows as $row) {
            try {
                $args = $this->bankRowToEntryArgs(self::withDirection($row, $facts), $itcId, $gstColId, $bankId, $codeToCostType);
                if ($args === null) { $skipped++; continue; }
                $this->ledger->postManual($args);
                $posted++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }
        return ['bank_posted' => $posted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** The entry a bank line should post now (null = it shouldn't post: revenue deposit, linked or settled deposit, zero). */
    public function bankEntryArgsFor(int $transactionId): ?array
    {
        $s = $this->db->prepare("
            SELECT at.id, at.transaction_date, at.type, at.account_id,
                   coa.type AS account_type, coa.code AS account_code,
                   at.bank_account_id, at.amount, at.gst_amount, at.pst_amount,
                   at.description, at.job_id, at.contact_id, at.vendor_id
            FROM accounting_transactions at
            JOIN chart_of_accounts coa ON coa.id = at.account_id
            WHERE at.id = ? AND at.reference_type = 'bank_import'
              AND at.matched_invoice_id IS NULL AND at.matched_expense_id IS NULL
        ");
        $s->execute([$transactionId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row = self::withDirection($row, $this->directionFacts($transactionId));
        return $this->bankRowToEntryArgs($row, $this->ledger->accountId(LedgerService::ACC_GST_ITC),
            $this->ledger->accountId(LedgerService::ACC_GST_COLLECTED), $this->ledger->accountId(LedgerService::ACC_BANK),
            $this->accountCodeToCostTypeMap());
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

    public function syncInvoices(): array
    {
        require_once __DIR__ . '/LedgerAccountMap.php';
        $map = new LedgerAccountMap($this->db);
        $posted = 0; $payments = 0; $skipped = 0;
        $rows = $this->db->query("
            SELECT id, subtotal, tax_amount, total, amount_paid, status,
                   contact_id, plan_id, contract_id, issue_date, paid_at, created_at
            FROM invoices
            WHERE COALESCE(total, 0) > 0
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            try {
                $args = $this->mapInvoiceToInvoiceArgs($row);
                if ($map->ready()) {
                    $args['revenue_splits'] = $map->invoiceSplits($row, $args['net']);
                }
                $this->ledger->postInvoice($args);
                $posted++;
                $pay = $this->mapInvoiceToPaymentArgs($row);
                if ($pay !== null) {
                    $this->ledger->postPayment($pay);
                    $payments++;
                }
            } catch (\Throwable $e) {
                $skipped++; // never abort the batch
            }
        }
        return ['invoices_posted' => $posted, 'payments_posted' => $payments, 'skipped' => $skipped];
    }

    public function syncExpenses(): array
    {
        require_once __DIR__ . '/LedgerAccountMap.php';
        // The owner's category → account map (migration 1130) wins over chart aliases.
        $categoryToCode = (new LedgerAccountMap($this->db))->categoryCodes() + $this->categoryToCodeMap();
        $categoryToCost = $this->categoryToCostTypeMap();
        $posted = 0; $skipped = 0;

        $rows = $this->db->query("
            SELECT id, expense_date, total, gst_amount, pst_amount, accounting_category,
                   payment_method, vendor_id, job_id, contact_id, asset_tag, vendor_name_raw,
                   (SELECT v.name FROM vendors v WHERE v.id = expenses.vendor_id) AS vendor_name
            FROM expenses
            WHERE total > 0 AND status IN ('approved', 'forwarded')
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            try {
                $this->ledger->postExpense($this->mapExpenseToExpenseArgs($row, $categoryToCode, $categoryToCost));
                $posted++;
            } catch (\Throwable $e) {
                $skipped++;
            }
        }
        return ['expenses_posted' => $posted, 'skipped' => $skipped];
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
