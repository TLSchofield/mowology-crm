<?php
/**
 * AccountingService — Core of the Mowology Accounting System (Phase 1)
 *
 * Responsibilities:
 *  - Sync invoices → income transactions
 *  - Sync expenses → expense transactions
 *  - Run auto-categorization rules
 *  - Generate P&L, Cash Flow, Tax Summary, Job Profitability
 *  - Provide real-time dashboard metrics
 *
 * All monetary values are in CAD, stored as DECIMAL(10,2).
 * Amounts in accounting_transactions are always POSITIVE;
 * the `type` column (income/expense) provides direction.
 */
declare(strict_types=1);

class AccountingService
{
    private PDO $db;

    // Default accounts for fallback categorization
    private const DEFAULT_INCOME_CODE  = '4900'; // Other Services
    private const DEFAULT_EXPENSE_CODE = '6900'; // Miscellaneous Expenses

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SYNC — pull records from operational tables into unified ledger
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Full sync: invoices + expenses, then apply rules to uncategorized.
     * Returns summary counts.
     */
    public function syncAll(): array
    {
        $invoiceResult  = $this->syncFromInvoices();
        $expenseResult  = $this->syncFromExpenses();
        $ruleResult     = $this->applyRulesToUncategorized();
        $removed        = $this->removeOrphanedTransactions();

        return [
            'invoices_synced'  => $invoiceResult['synced'],
            'invoices_updated' => $invoiceResult['updated'],
            'expenses_synced'  => $expenseResult['synced'],
            'expenses_updated' => $expenseResult['updated'],
            'rules_applied'    => $ruleResult,
            'removed_orphans'  => $removed,
        ];
    }

    /**
     * Remove accounting_transactions that reference expenses or invoices which
     * no longer qualify for the ledger (deleted, merged, status changed, etc.).
     * Safe to run on every sync — only touches non-manual reference_type rows.
     */
    public function removeOrphanedTransactions(): int
    {
        $removed = 0;

        // Expenses that have been deleted, merged, rejected, or reverted to draft
        $stmt = $this->db->query("
            DELETE FROM accounting_transactions
            WHERE reference_type = 'expense'
              AND reference_id IS NOT NULL
              AND reference_id NOT IN (
                  SELECT id FROM expenses
                  WHERE status IN ('approved', 'forwarded')
                    AND total > 0
              )
        ");
        $removed += $stmt->rowCount();

        // Invoices that are no longer paid/partial
        $stmt = $this->db->query("
            DELETE FROM accounting_transactions
            WHERE reference_type = 'invoice'
              AND reference_id IS NOT NULL
              AND reference_id NOT IN (
                  SELECT id FROM invoices
                  WHERE status IN ('paid', 'partial')
                    AND COALESCE(amount_paid, 0) > 0
              )
        ");
        $removed += $stmt->rowCount();

        return $removed;
    }

    /**
     * Sync paid invoices as income transactions.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotency.
     * Only syncs invoices with a paid_at date (cash-basis revenue recognition).
     */
    public function syncFromInvoices(): array
    {
        $defaultAccountId = $this->getAccountIdByCode(self::DEFAULT_INCOME_CODE);
        $synced  = 0;
        $updated = 0;

        // Pull invoices: partial payments also included (amount_paid > 0).
        // job_id here maps to job_plans.id (the plan the invoice was generated from),
        // since this codebase uses job_plans — there is no separate `jobs` table.
        // NOTE: invoices table has no assigned_user_id column — attribution is
        // implicit via the related job_plan / contact.
        $stmt = $this->db->query("
            SELECT
                i.id,
                COALESCE(i.paid_at, i.updated_at)   AS transaction_date,
                COALESCE(i.amount_paid, i.total, 0) AS amount,
                COALESCE(i.tax_amount, 0)           AS gst_amount,
                i.contact_id,
                i.notes,
                i.plan_id                           AS job_id
            FROM invoices i
            WHERE i.status IN ('paid', 'partial')
              AND COALESCE(i.amount_paid, 0) > 0
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $desc = 'Invoice payment';
            if (!empty($row['notes'])) {
                $desc .= ' — ' . mb_substr(strip_tags($row['notes']), 0, 120);
            }

            $result = $this->upsertTransaction([
                'transaction_date' => substr($row['transaction_date'], 0, 10),
                'type'             => 'income',
                'account_id'       => $defaultAccountId,
                'amount'           => (float)$row['amount'],
                'gst_amount'       => (float)$row['gst_amount'],
                'pst_amount'       => 0.00,
                'description'      => $desc,
                'reference_type'   => 'invoice',
                'reference_id'     => (int)$row['id'],
                'job_id'           => $row['job_id'] ? (int)$row['job_id'] : null,
                'contact_id'       => $row['contact_id'] ? (int)$row['contact_id'] : null,
                'vendor_id'        => null,
                'assigned_user_id' => null,
                'status'           => 'cleared',
                'needs_review'     => 0,
                'is_auto_categorized' => 0,
            ]);

            if ($result === 'inserted') $synced++;
            if ($result === 'updated')  $updated++;
        }

        return ['synced' => $synced, 'updated' => $updated];
    }

    /**
     * Sync expenses as expense transactions.
     * Maps legacy accounting_category to chart_of_accounts via expense_category_alias.
     */
    public function syncFromExpenses(): array
    {
        $defaultAccountId = $this->getAccountIdByCode(self::DEFAULT_EXPENSE_CODE);

        // Build alias → account_id map from chart_of_accounts
        $aliasMap = [];
        $aliasRows = $this->db->query("
            SELECT id, expense_category_alias
            FROM chart_of_accounts
            WHERE expense_category_alias IS NOT NULL AND is_active = 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($aliasRows as $r) {
            $aliasMap[$r['expense_category_alias']] = (int)$r['id'];
        }

        $synced  = 0;
        $updated = 0;

        $stmt = $this->db->query("
            SELECT
                e.id,
                e.expense_date               AS transaction_date,
                e.total                      AS amount,
                COALESCE(e.gst_amount, 0)    AS gst_amount,
                COALESCE(e.pst_amount, 0)    AS pst_amount,
                e.description,
                e.accounting_category,
                e.vendor_id,
                e.job_id,
                e.contact_id,
                e.created_by                 AS assigned_user_id
            FROM expenses e
            WHERE e.total > 0
              AND e.status IN ('approved', 'forwarded')
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Map category alias → account_id
            $category   = $row['accounting_category'] ?? '';
            $accountId  = $aliasMap[$category] ?? $defaultAccountId;

            $isAutoCat  = isset($aliasMap[$category]) ? 1 : 0;

            $result = $this->upsertTransaction([
                'transaction_date' => $row['transaction_date'],
                'type'             => 'expense',
                'account_id'       => $accountId,
                'amount'           => (float)$row['amount'],
                'gst_amount'       => (float)$row['gst_amount'],
                'pst_amount'       => (float)$row['pst_amount'],
                'description'      => $row['description'] ?? '',
                'reference_type'   => 'expense',
                'reference_id'     => (int)$row['id'],
                'job_id'           => $row['job_id'] ? (int)$row['job_id'] : null,
                'contact_id'       => $row['contact_id'] ? (int)$row['contact_id'] : null,
                'vendor_id'        => $row['vendor_id'] ? (int)$row['vendor_id'] : null,
                'assigned_user_id' => $row['assigned_user_id'] ? (int)$row['assigned_user_id'] : null,
                'status'           => 'cleared',
                'needs_review'     => 0,
                'is_auto_categorized' => $isAutoCat,
            ]);

            if ($result === 'inserted') $synced++;
            if ($result === 'updated')  $updated++;
        }

        return ['synced' => $synced, 'updated' => $updated];
    }

    /**
     * Apply transaction_rules to uncategorized / default-categorized transactions.
     * Runs after sync. Returns count of transactions updated by rules.
     */
    public function applyRulesToUncategorized(): int
    {
        require_once __DIR__ . '/RulesEngine.php';
        $engine = new RulesEngine($this->db);
        return $engine->applyAll();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REPORTING
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Profit & Loss statement for a date range.
     * Returns structured data: revenue accounts, expense accounts, totals.
     */
    public function getProfitLoss(string $dateFrom, string $dateTo): array
    {
        // Revenue by account
        $revRows = $this->db->prepare("
            SELECT
                coa.code,
                coa.name,
                SUM(t.amount) AS total
            FROM accounting_transactions t
            JOIN chart_of_accounts coa ON coa.id = t.account_id
            WHERE t.type = 'income'
              AND t.transaction_date BETWEEN ? AND ?
              AND t.status IN ('cleared', 'reconciled')
            GROUP BY coa.id, coa.code, coa.name
            ORDER BY coa.display_order
        ");
        $revRows->execute([$dateFrom, $dateTo]);
        $revenue = $revRows->fetchAll(PDO::FETCH_ASSOC);

        // Expenses by account
        $expRows = $this->db->prepare("
            SELECT
                coa.code,
                coa.name,
                SUM(t.amount) AS total
            FROM accounting_transactions t
            JOIN chart_of_accounts coa ON coa.id = t.account_id
            WHERE t.type = 'expense'
              AND t.transaction_date BETWEEN ? AND ?
              AND t.status IN ('cleared', 'reconciled')
            GROUP BY coa.id, coa.code, coa.name
            ORDER BY coa.display_order
        ");
        $expRows->execute([$dateFrom, $dateTo]);
        $expenses = $expRows->fetchAll(PDO::FETCH_ASSOC);

        $totalRevenue  = array_sum(array_column($revenue, 'total'));
        $totalExpenses = array_sum(array_column($expenses, 'total'));
        $netProfit     = $totalRevenue - $totalExpenses;
        $margin        = $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 1) : 0;

        return [
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'revenue'        => $revenue,
            'expenses'       => $expenses,
            'total_revenue'  => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'net_profit'     => $netProfit,
            'margin_pct'     => $margin,
        ];
    }

    /**
     * Monthly P&L trend — last N months.
     * Returns array of {month, revenue, expenses, net_profit}.
     */
    public function getMonthlyTrend(int $months = 12): array
    {
        $stmt = $this->db->prepare("
            SELECT
                DATE_FORMAT(t.transaction_date, '%Y-%m') AS month,
                SUM(CASE WHEN t.type = 'income'  THEN t.amount ELSE 0 END) AS revenue,
                SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) AS expenses
            FROM accounting_transactions t
            WHERE t.transaction_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
              AND t.status IN ('cleared', 'reconciled')
            GROUP BY DATE_FORMAT(t.transaction_date, '%Y-%m')
            ORDER BY month ASC
        ");
        $stmt->execute([$months]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $net = (float)$r['revenue'] - (float)$r['expenses'];
            return [
                'month'      => $r['month'],
                'revenue'    => (float)$r['revenue'],
                'expenses'   => (float)$r['expenses'],
                'net_profit' => $net,
            ];
        }, $rows);
    }

    /**
     * Cash Flow statement — simplified (operating only for Phase 1).
     * Inflows  = sum of income transactions
     * Outflows = sum of expense transactions
     */
    public function getCashFlow(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare("
            SELECT
                type,
                SUM(amount) AS total
            FROM accounting_transactions
            WHERE transaction_date BETWEEN ? AND ?
              AND status IN ('cleared', 'reconciled')
              AND type IN ('income', 'expense')
            GROUP BY type
        ");
        $stmt->execute([$dateFrom, $dateTo]);

        $totals = ['income' => 0.00, 'expense' => 0.00];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[$row['type']] = (float)$row['total'];
        }

        return [
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'inflows'       => $totals['income'],
            'outflows'      => $totals['expense'],
            'net_cash_flow' => $totals['income'] - $totals['expense'],
        ];
    }

    /**
     * GST/PST Tax Summary — for CRA remittance reporting.
     * Returns: GST collected (from invoices), GST paid/ITCs (from expenses),
     *          net GST owing.
     */
    public function getTaxSummary(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare("
            SELECT
                type,
                SUM(gst_amount) AS gst_total,
                SUM(pst_amount) AS pst_total
            FROM accounting_transactions
            WHERE transaction_date BETWEEN ? AND ?
              AND status IN ('cleared', 'reconciled')
              AND type IN ('income', 'expense')
            GROUP BY type
        ");
        $stmt->execute([$dateFrom, $dateTo]);

        $data = ['income' => ['gst' => 0, 'pst' => 0], 'expense' => ['gst' => 0, 'pst' => 0]];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data[$row['type']]['gst'] = (float)$row['gst_total'];
            $data[$row['type']]['pst'] = (float)$row['pst_total'];
        }

        $gstCollected = $data['income']['gst'];
        $gstPaidITC   = $data['expense']['gst'];
        $netGstOwing  = $gstCollected - $gstPaidITC;

        return [
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'gst_collected'   => $gstCollected,
            'gst_itc'         => $gstPaidITC,
            'net_gst_owing'   => $netGstOwing,
            'pst_collected'   => $data['income']['pst'],
            'pst_paid'        => $data['expense']['pst'],
        ];
    }

    /**
     * Job Profitability — revenue and expenses per job_plan (we use job_plans
     * as the "job" entity on this codebase — there is no `jobs` table).
     */
    public function getJobProfitability(string $dateFrom, string $dateTo, int $limit = 25): array
    {
        $stmt = $this->db->prepare("
            SELECT
                jp.id           AS job_id,
                jp.plan_number  AS job_number,
                COALESCE(co.company_name, p.property_name, p.address) AS client_name,
                p.address       AS property_address,
                SUM(CASE WHEN t.type = 'income'  THEN t.amount ELSE 0 END) AS revenue,
                SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) AS expenses
            FROM job_plans jp
            LEFT JOIN accounting_transactions t ON t.job_id = jp.id
              AND t.transaction_date BETWEEN ? AND ?
              AND t.status IN ('cleared', 'reconciled')
            LEFT JOIN companies  co ON co.id = jp.company_id
            LEFT JOIN properties p  ON p.id  = jp.property_id
            WHERE jp.status = 'completed'
              AND jp.created_at >= ?
            GROUP BY jp.id, jp.plan_number, co.company_name, p.property_name, p.address
            HAVING revenue > 0
            ORDER BY (revenue - expenses) DESC
            LIMIT ?
        ");
        $stmt->execute([$dateFrom, $dateTo, $dateFrom, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $profit = (float)$r['revenue'] - (float)$r['expenses'];
            $margin = (float)$r['revenue'] > 0
                ? round(($profit / (float)$r['revenue']) * 100, 1)
                : 0;
            return array_merge($r, [
                'revenue'  => (float)$r['revenue'],
                'expenses' => (float)$r['expenses'],
                'profit'   => $profit,
                'margin'   => $margin,
            ]);
        }, $rows);
    }

    /**
     * Dashboard metrics — today, MTD, YTD, alerts.
     */
    public function getDashboardMetrics(): array
    {
        $today     = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $yearStart  = date('Y-01-01');
        $qtrStart   = date('Y-m-01', strtotime('-' . ((int)date('n') - 1) % 3 . ' months'));

        return [
            'today'            => $this->getPeriodSummary($today, $today),
            'mtd'              => $this->getPeriodSummary($monthStart, $today),
            'ytd'              => $this->getPeriodSummary($yearStart, $today),
            'tax'              => $this->getTaxSummary($qtrStart, $today),
            'uncategorized'    => $this->countUncategorized(),
            'needs_review'     => $this->countNeedsReview(),
            'recent'           => $this->getRecentTransactions(8),
        ];
    }

    /**
     * Simple revenue/expense/profit for a date window.
     */
    public function getPeriodSummary(string $from, string $to): array
    {
        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN type = 'income'  THEN amount ELSE 0 END) AS revenue,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expenses
            FROM accounting_transactions
            WHERE transaction_date BETWEEN ? AND ?
              AND status IN ('cleared', 'reconciled')
        ");
        $stmt->execute([$from, $to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $rev = (float)($row['revenue'] ?? 0);
        $exp = (float)($row['expenses'] ?? 0);

        return [
            'revenue'  => $rev,
            'expenses' => $exp,
            'profit'   => $rev - $exp,
            'margin'   => $rev > 0 ? round((($rev - $exp) / $rev) * 100, 1) : 0,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // TRANSACTION CRUD
    // ══════════════════════════════════════════════════════════════════════════

    public function listTransactions(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 't.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 't.transaction_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 't.transaction_date <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['account_id'])) {
            $where[] = 't.account_id = ?';
            $params[] = (int)$filters['account_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['needs_review'])) {
            $where[] = 't.needs_review = 1';
        }
        if (!empty($filters['job_id'])) {
            $where[] = 't.job_id = ?';
            $params[] = (int)$filters['job_id'];
        }
        if (!empty($filters['search'])) {
            $where[]  = 't.description LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['source'])) {
            $where[] = 't.reference_type = ?';
            $params[] = $filters['source'];
        }

        $whereClause = implode(' AND ', $where);
        $offset      = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM accounting_transactions t WHERE $whereClause
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // NOTE: t.job_id refers to job_plans.id on this codebase (see sync).
        // For invoice rows we look up property/company directly from the invoices
        // table (inv.property_id / inv.company_id) because some invoices have no
        // plan_id and the job_plans route would return nothing.
        $stmt = $this->db->prepare("
            SELECT
                t.*,
                coa.code  AS account_code,
                coa.name  AS account_name,
                coa.type  AS account_type,
                v.name    AS vendor_name,
                CONCAT(c.first_name, ' ', c.last_name) AS contact_name,
                jp.plan_number AS job_number,
                -- Invoice-specific client lookup (property/company on the invoice itself)
                inv.invoice_number,
                COALESCE(
                    ico.company_name,
                    ip.property_name,
                    ip.address,
                    co.company_name,
                    prop.property_name,
                    prop.address
                ) AS client_name,
                COALESCE(ip.address, prop.address) AS property_address,
                (CASE WHEN t.reference_type = 'bank_import' THEN
                    (SELECT 1 FROM accounting_transactions sys
                     WHERE sys.reference_type IN ('expense', 'invoice')
                       AND sys.type = t.type
                       AND ABS(sys.amount - t.amount) < 0.02
                       AND sys.transaction_date BETWEEN
                           DATE_SUB(t.transaction_date, INTERVAL 3 DAY) AND
                           DATE_ADD(t.transaction_date, INTERVAL 3 DAY)
                     LIMIT 1)
                ELSE NULL END) AS bank_match_exists
            FROM accounting_transactions t
            JOIN chart_of_accounts coa ON coa.id = t.account_id
            LEFT JOIN vendors    v    ON v.id    = t.vendor_id
            LEFT JOIN contacts   c    ON c.id    = t.contact_id
            LEFT JOIN job_plans  jp   ON jp.id   = t.job_id
            LEFT JOIN properties prop ON prop.id = jp.property_id
            LEFT JOIN companies  co   ON co.id   = jp.company_id
            -- Invoice-direct joins (used when plan_id is not set on the invoice)
            LEFT JOIN invoices   inv  ON inv.id  = t.reference_id
                                    AND t.reference_type = 'invoice'
            LEFT JOIN properties ip   ON ip.id   = inv.property_id
            LEFT JOIN companies  ico  ON ico.id  = inv.company_id
            WHERE $whereClause
            ORDER BY t.transaction_date DESC, t.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'last_page'  => (int)ceil($total / $perPage),
        ];
    }

    public function createManualTransaction(array $data, int $userId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO accounting_transactions
                (transaction_date, type, account_id, amount, gst_amount, pst_amount,
                 description, reference_type, job_id, contact_id, vendor_id,
                 status, needs_review, is_auto_categorized, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'manual', ?, ?, ?, ?, ?, 0, ?, ?)
        ");
        $stmt->execute([
            $data['transaction_date'],
            $data['type'],
            (int)$data['account_id'],
            (float)$data['amount'],
            (float)($data['gst_amount'] ?? 0),
            (float)($data['pst_amount'] ?? 0),
            $data['description'] ?? '',
            $data['job_id']     ? (int)$data['job_id']     : null,
            $data['contact_id'] ? (int)$data['contact_id'] : null,
            $data['vendor_id']  ? (int)$data['vendor_id']  : null,
            $data['status']     ?? 'cleared',
            (int)($data['needs_review'] ?? 0),
            $data['notes'] ?? null,
            $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateTransaction(int $id, array $data): bool
    {
        $fields  = [];
        $params  = [];
        $allowed = ['account_id', 'amount', 'gst_amount', 'pst_amount',
                    'description', 'transaction_date', 'status',
                    'needs_review', 'notes', 'job_id', 'contact_id', 'vendor_id'];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f] === '' ? null : $data[$f];
            }
        }

        if (empty($fields)) return false;

        $params[] = $id;
        $stmt = $this->db->prepare(
            'UPDATE accounting_transactions SET ' . implode(', ', $fields) . ' WHERE id = ?'
        );
        return $stmt->execute($params);
    }

    public function deleteTransaction(int $id): bool
    {
        // Only allow deletion of manual entries
        $stmt = $this->db->prepare(
            "DELETE FROM accounting_transactions WHERE id = ? AND reference_type = 'manual'"
        );
        return $stmt->execute([$id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CHART OF ACCOUNTS
    // ══════════════════════════════════════════════════════════════════════════

    public function getAccounts(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return $this->db->query("
            SELECT * FROM chart_of_accounts $where ORDER BY display_order, code
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAccountsByType(string $type): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM chart_of_accounts WHERE type = ? AND is_active = 1 ORDER BY display_order, code"
        );
        $stmt->execute([$type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    private function getAccountIdByCode(string $code): int
    {
        $stmt = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException("Chart of accounts code '$code' not found. Run migration 980 first.");
        }
        return (int)$id;
    }

    /**
     * Upsert a transaction. Returns 'inserted' | 'updated' | 'skipped'.
     * Uses the UNIQUE KEY on (reference_type, reference_id).
     */
    private function upsertTransaction(array $data): string
    {
        // Check for NULL reference_id — can't upsert without it
        if ($data['reference_id'] === null) {
            $this->createManualTransaction($data, 0);
            return 'inserted';
        }

        $stmt = $this->db->prepare("
            INSERT INTO accounting_transactions
                (transaction_date, type, account_id, amount, gst_amount, pst_amount,
                 description, reference_type, reference_id, job_id, contact_id,
                 vendor_id, assigned_user_id, status, needs_review, is_auto_categorized)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                transaction_date      = VALUES(transaction_date),
                amount                = VALUES(amount),
                gst_amount            = VALUES(gst_amount),
                pst_amount            = VALUES(pst_amount),
                description           = VALUES(description),
                account_id            = VALUES(account_id),
                job_id                = VALUES(job_id),
                contact_id            = VALUES(contact_id),
                vendor_id             = VALUES(vendor_id),
                assigned_user_id      = VALUES(assigned_user_id),
                is_auto_categorized   = VALUES(is_auto_categorized),
                status                = VALUES(status),
                updated_at            = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $data['transaction_date'],
            $data['type'],
            $data['account_id'],
            $data['amount'],
            $data['gst_amount'],
            $data['pst_amount'],
            $data['description'] ?? '',
            $data['reference_type'],
            $data['reference_id'],
            $data['job_id'],
            $data['contact_id'],
            $data['vendor_id'],
            $data['assigned_user_id'] ?? null,
            $data['status'],
            $data['needs_review'],
            $data['is_auto_categorized'],
        ]);

        $rowCount = $stmt->rowCount();
        if ($rowCount === 1) return 'inserted';
        if ($rowCount === 2) return 'updated';
        return 'skipped';
    }

    private function countUncategorized(): int
    {
        $defaultExpId  = $this->getAccountIdByCode(self::DEFAULT_EXPENSE_CODE);
        $defaultIncId  = $this->getAccountIdByCode(self::DEFAULT_INCOME_CODE);

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM accounting_transactions
            WHERE account_id IN (?, ?) AND is_auto_categorized = 0 AND reference_type != 'manual'
        ");
        $stmt->execute([$defaultExpId, $defaultIncId]);
        return (int)$stmt->fetchColumn();
    }

    private function countNeedsReview(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM accounting_transactions WHERE needs_review = 1"
        )->fetchColumn();
    }

    /**
     * Find the best matching expense or invoice for a bank import transaction.
     * Used by the expand-row feature in the transaction ledger.
     */
    public function findMatchForBankTransaction(int $txId): ?array
    {
        // Fetch the bank import transaction
        $stmt = $this->db->prepare("
            SELECT id, type, amount, transaction_date, description, bank_account
            FROM accounting_transactions
            WHERE id = ? AND reference_type = 'bank_import'
        ");
        $stmt->execute([$txId]);
        $bankTx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bankTx) return null;

        // Find the closest match by date, then amount
        $stmt = $this->db->prepare("
            SELECT
                t.id, t.reference_type, t.reference_id, t.type,
                t.amount, t.gst_amount, t.transaction_date,
                t.description, t.account_id,
                coa.code  AS account_code,
                coa.name  AS account_name,
                v.name    AS vendor_name,
                CONCAT(c.first_name, ' ', c.last_name) AS contact_name,
                COALESCE(ico.company_name, ip.property_name, ip.address) AS client_name,
                inv.invoice_number
            FROM accounting_transactions t
            LEFT JOIN chart_of_accounts coa ON coa.id = t.account_id
            LEFT JOIN vendors   v   ON v.id   = t.vendor_id
            LEFT JOIN contacts  c   ON c.id   = t.contact_id
            LEFT JOIN invoices  inv ON inv.id = t.reference_id AND t.reference_type = 'invoice'
            LEFT JOIN properties ip  ON ip.id  = inv.property_id
            LEFT JOIN companies  ico ON ico.id = inv.company_id
            WHERE t.reference_type IN ('expense', 'invoice')
              AND t.type = ?
              AND ABS(t.amount - ?) < 0.02
              AND t.transaction_date BETWEEN
                  DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)
            ORDER BY ABS(DATEDIFF(t.transaction_date, ?)) ASC, t.id ASC
            LIMIT 1
        ");
        $stmt->execute([
            $bankTx['type'],
            $bankTx['amount'],
            $bankTx['transaction_date'],
            $bankTx['transaction_date'],
            $bankTx['transaction_date'],
        ]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        return $match ?: null;
    }

    private function getRecentTransactions(int $limit): array
    {
        $stmt = $this->db->prepare("
            SELECT
                t.id, t.transaction_date, t.type, t.amount,
                t.description, t.status, t.needs_review,
                coa.name AS account_name,
                coa.code AS account_code,
                v.name   AS vendor_name
            FROM accounting_transactions t
            JOIN chart_of_accounts coa ON coa.id = t.account_id
            LEFT JOIN vendors v ON v.id = t.vendor_id
            ORDER BY t.transaction_date DESC, t.id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
