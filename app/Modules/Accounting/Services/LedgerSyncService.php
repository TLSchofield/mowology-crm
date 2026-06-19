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
            'expense_account' => $categoryToCode[$cat] ?? '6900',
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

    // ══════════════════════════════════════════════════════════════════════════
    // DB SYNC (idempotent, per-record guarded)
    // ══════════════════════════════════════════════════════════════════════════

    public function syncAll(): array
    {
        return [
            'invoices' => $this->syncInvoices(),
            'expenses' => $this->syncExpenses(),
        ];
    }

    public function syncInvoices(): array
    {
        $posted = 0; $payments = 0; $skipped = 0;
        $rows = $this->db->query("
            SELECT id, subtotal, tax_amount, total, amount_paid, status,
                   contact_id, plan_id, issue_date, paid_at, created_at
            FROM invoices
            WHERE COALESCE(total, 0) > 0
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            try {
                $this->ledger->postInvoice($this->mapInvoiceToInvoiceArgs($row));
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
        $categoryToCode = $this->categoryToCodeMap();
        $categoryToCost = $this->categoryToCostTypeMap();
        $posted = 0; $skipped = 0;

        $rows = $this->db->query("
            SELECT id, expense_date, total, gst_amount, pst_amount, accounting_category,
                   payment_method, vendor_id, job_id, contact_id
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
