<?php
declare(strict_types=1);

/**
 * ClientCreditService — client-scoped prepaid credit ledger.
 *
 * Some clients pay a lump sum up front (a season prepayment, an advance
 * cheque) that should be drawn down against invoices as they're raised,
 * with any leftover usable for OTHER services — not just the plan/contract
 * the prepayment happened to be tied to. So the balance lives at the
 * `client_id` level, not on a single contract or plan.
 *
 * Additive-only ledger: a client's balance is always SUM(amount) for their
 * client_id, never a mutable running-balance column. This keeps a full
 * audit trail (deposit → applied → adjustment) and avoids concurrent-write
 * balance bugs, mirroring the invoice_payment_allocations ledger pattern
 * (migration 1062).
 *
 * Does NOT call trackFieldChange()/logActivityExtended() itself (those are
 * globals that open their own DB connection via getDB()) — callers record
 * the invoice-side audit trail the same way the existing mark_paid flow in
 * public/crm/invoices/view.php does, using the values this class returns.
 *
 * No namespace — loaded via require_once, consistent with the rest of
 * app/Modules/[Module]/Services/.
 */
class ClientCreditService
{
    private bool $postToLedger = true;

    public function __construct(private PDO $db) {}

    /** Unit tests with a mocked PDO: skip the journal posting (LedgerService has its own tests). */
    public function withoutLedger(): self { $this->postToLedger = false; return $this; }

    // ─────────────────────────────────────────────────────────────────────────
    // Balance
    // ─────────────────────────────────────────────────────────────────────────

    /** Current available balance for a client (sum of the ledger). */
    public function getBalance(int $clientId): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM client_credits WHERE client_id = ?");
        $stmt->execute([$clientId]);
        return (float) $stmt->fetchColumn();
    }

    /** Full ledger history for a client, most recent first. */
    public function getLedger(int $clientId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT cc.*, i.invoice_number
            FROM client_credits cc
            LEFT JOIN invoices i ON i.id = cc.invoice_id
            WHERE cc.client_id = ?
            ORDER BY cc.created_at DESC, cc.id DESC
            LIMIT " . max(1, min(200, $limit)) . "
        ");
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Resolve an invoice to its client and available credit balance in one call —
     * lets a page decide whether to show an "Apply credit" action without
     * duplicating the resolution SQL.
     *
     * @return array{client_id: ?int, balance: float}
     */
    public function getBalanceForInvoiceClient(int $invoiceId): array
    {
        $stmt = $this->db->prepare("SELECT id, client_id, contact_id, company_id FROM invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return ['client_id' => null, 'balance' => 0.0];
        }
        $clientId = $this->resolveClientId($invoice);
        return ['client_id' => $clientId, 'balance' => $clientId ? $this->getBalance($clientId) : 0.0];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mutations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record a prepayment (e.g. an advance cheque) as available credit.
     * Returns the new client_credits row id.
     */
    /** Chart code for money a client paid ahead — a liability until it pays an invoice. */
    public const DEPOSITS_CODE = '2160';

    /**
     * @param ?int $bankTransactionId the bank line the advance arrived on: it stops being income
     *        (a transfer into 2160 Customer Deposits) — income is the invoice the credit later pays.
     *        Without this the cheque counted once as a deposit and again on every invoice it paid
     *        (review 2026-10-12: Roberge cheque #011, $529.20).
     */
    public function addDeposit(int $clientId, float $amount, string $note, ?int $userId = null, ?int $bankTransactionId = null): int
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deposit amount must be greater than zero');
        }

        $stmt = $this->db->prepare("
            INSERT INTO client_credits (client_id, type, amount, source_note, created_by)
            VALUES (?, 'deposit', ?, ?, ?)
        ");
        $stmt->execute([$clientId, $amount, $note, $userId]);
        $id = (int) $this->db->lastInsertId();
        $bankTransactionId = $bankTransactionId ?: $this->findDepositLine($amount);
        if ($bankTransactionId) $this->moveBankLineToDeposits($bankTransactionId);
        else error_log("[ClientCredit] prepayment #{$id} (\${$amount}) not tied to a bank line — Penny: find its deposit");
        return $id;
    }

    /**
     * The bank line an advance most likely arrived on: the ONLY bank-import income line of exactly
     * this amount in the last 60 days (not tied to an invoice). Two or more → null (a person picks).
     */
    public function findDepositLine(float $amount): ?int
    {
        $s = $this->db->prepare("SELECT id FROM accounting_transactions
                                 WHERE type = 'income' AND reference_type = 'bank_import'
                                   AND ABS(amount - ?) < 0.005
                                   AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                                   AND (matched_invoice_id IS NULL OR matched_invoice_id = 0)
                                 LIMIT 2");
        $s->execute([$amount]);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    /** The advance's bank line → transfer into 2160 Customer Deposits (not income). */
    public function moveBankLineToDeposits(int $transactionId): bool
    {
        $acct = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
        $acct->execute([self::DEPOSITS_CODE]);
        $depositsId = $acct->fetchColumn();
        if (!$depositsId) throw new \RuntimeException('Account ' . self::DEPOSITS_CODE . ' Customer Deposits is missing from the chart');
        $u = $this->db->prepare("UPDATE accounting_transactions SET type = 'transfer', account_id = ?
                                 WHERE id = ? AND type = 'income' AND reference_type = 'bank_import'");
        $u->execute([(int)$depositsId, $transactionId]);
        return $u->rowCount() === 1;
    }

    /** Journal for credit applied to an invoice: DR 2160 Customer Deposits / CR 1100 AR — no cash moves. */
    public function postAppliedToLedger(int $creditRowId, int $invoiceId, float $applied, string $date, ?int $userId = null): ?int
    {
        require_once __DIR__ . '/LedgerService.php';
        $ledger = new LedgerService($this->db);
        if ($ledger->findEntryIdBySource('client_credit', $creditRowId) !== null) return null;
        return $ledger->postEntry([
            'entry_date' => $date, 'memo' => 'Client credit applied to invoice #' . $invoiceId,
            'source_type' => 'client_credit', 'source_id' => $creditRowId,
            'created_by' => $userId, 'proposed_by' => 'owner',
            'lines' => [
                ['account_id' => $ledger->accountId(self::DEPOSITS_CODE), 'debit' => $applied, 'credit' => 0],
                ['account_id' => $ledger->accountId(LedgerService::ACC_AR), 'debit' => 0, 'credit' => $applied],
            ],
        ]);
    }

    /**
     * Apply available client credit to an invoice's outstanding balance.
     *
     * Resolves the invoice's client via `invoices.client_id` if set, else
     * falls back to the same legacy_contact_id/legacy_company_id lookup
     * already used inline in contracts/view.php and invoices/create.php.
     * Caps the applied amount to min(available balance, invoice balance_due)
     * — never overdraws the ledger or overpays the invoice.
     *
     * @return array{client_id:int, applied:float, invoice_status:string, invoice_balance_due:float, remaining_credit:float, old_status:string, old_amount_paid:float, old_balance_due:float}
     * @throws \RuntimeException if the invoice doesn't exist, has no resolvable
     *         client, or there's no credit/balance available to apply.
     */
    public function applyToInvoice(int $invoiceId, ?int $userId = null, ?float $amount = null): array
    {
        $invStmt = $this->db->prepare("SELECT id, client_id, contact_id, company_id, status, amount_paid, balance_due FROM invoices WHERE id = ?");
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new \RuntimeException("Invoice {$invoiceId} not found");
        }
        if (in_array($invoice['status'], ['paid', 'cancelled'], true)) {
            throw new \RuntimeException("Invoice is already {$invoice['status']} — nothing to apply credit to");
        }

        $clientId = $this->resolveClientId($invoice);
        if (!$clientId) {
            throw new \RuntimeException("Could not resolve a client for invoice {$invoiceId}");
        }

        $balanceDue = (float) $invoice['balance_due'];
        $available  = $this->getBalance($clientId);
        $requested  = $amount !== null && $amount > 0 ? $amount : min($available, $balanceDue);
        $applied    = round(min($requested, $available, $balanceDue), 2);

        if ($applied <= 0) {
            throw new \RuntimeException('No credit available to apply, or invoice already fully paid');
        }

        $newPaid    = round((float) $invoice['amount_paid'] + $applied, 2);
        $newBalance = round(max(0, $balanceDue - $applied), 2);
        // 0.5¢ tolerance handles floating-point rounding, matching mark_paid in invoices/view.php.
        $newStatus  = $newBalance <= 0.005 ? 'paid' : 'partial';

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO client_credits (client_id, type, amount, invoice_id, source_note, created_by)
                VALUES (?, 'applied', ?, ?, ?, ?)
            ")->execute([$clientId, -$applied, $invoiceId, 'Applied to invoice', $userId]);
            $creditRowId = (int) $this->db->lastInsertId();

            $this->db->prepare("
                UPDATE invoices
                SET amount_paid = ?, balance_due = ?, status = ?, payment_method = 'account_credit',
                    payment_reference = 'Client credit ledger', paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END
                WHERE id = ?
            ")->execute([$newPaid, $newBalance, $newStatus, $newStatus, $invoiceId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if ($this->postToLedger) try {
            $this->postAppliedToLedger($creditRowId, $invoiceId, $applied, date('Y-m-d'), $userId);
        } catch (\Throwable $e) {
            error_log('[ClientCredit] journal for credit #' . $creditRowId . ': ' . $e->getMessage());
        }

        return [
            'client_id'            => $clientId,
            'applied'              => $applied,
            'invoice_status'       => $newStatus,
            'invoice_balance_due'  => $newBalance,
            'remaining_credit'     => $this->getBalance($clientId),
            'old_status'           => (string) $invoice['status'],
            'old_amount_paid'      => (float) $invoice['amount_paid'],
            'old_balance_due'      => $balanceDue,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve an invoice row to a clients.id, same fallback ladder already
     * used inline elsewhere (contracts/view.php:90-94, invoices/create.php:194-197):
     * direct client_id first, then legacy_company_id, then legacy_contact_id.
     */
    private function resolveClientId(array $invoice): ?int
    {
        if (!empty($invoice['client_id'])) {
            return (int) $invoice['client_id'];
        }
        if (!empty($invoice['company_id'])) {
            $stmt = $this->db->prepare("SELECT id FROM clients WHERE legacy_company_id = ? LIMIT 1");
            $stmt->execute([$invoice['company_id']]);
            $id = $stmt->fetchColumn();
            if ($id) { return (int) $id; }
        }
        if (!empty($invoice['contact_id'])) {
            $stmt = $this->db->prepare("SELECT id FROM clients WHERE legacy_contact_id = ? LIMIT 1");
            $stmt->execute([$invoice['contact_id']]);
            $id = $stmt->fetchColumn();
            if ($id) { return (int) $id; }
        }
        return null;
    }
}
