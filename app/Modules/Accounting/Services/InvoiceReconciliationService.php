<?php
/**
 * InvoiceReconciliationService — attach imported bank deposits to unpaid invoices.
 *
 * Scenario: a customer pays (e-Transfer / processor / cheque deposit) and the deposit
 * lands on the Vancity statement as an `accounting_transactions` row
 * (reference_type='bank_import', type='income'), but the invoice is still unpaid.
 * This service lets an admin SEE the likely match and ATTACH it — recording the payment
 * and reconciling the books without double-counting income.
 *
 * ── Accounting model (income recognized exactly once) ────────────────────────
 *  • Income is recognized on the INVOICE ledger row (reference_type='invoice'),
 *    carrying the invoice's own job_id (plan_id) + contact_id — so a deposit split
 *    across several invoices attributes per-job correctly.
 *  • The bank-deposit ledger row's `amount` always equals its UNALLOCATED remainder
 *    (original amount comes from the bank_import_rows staging row). While a remainder
 *    exists it stays type='income'; once fully allocated it flips to type='transfer'
 *    (a cash-clearing entry). Every report sums `type IN ('income','expense')`, so the
 *    transfer is excluded automatically — no report query changes, every dollar counted
 *    once for full, split, and partial allocations.
 *  • Fully reversible: detach() removes allocations and restores invoice + deposit state.
 *
 * All amounts are CAD DECIMAL(10,2). Pure PDO — no global helpers (unit-testable).
 */
declare(strict_types=1);

class InvoiceReconciliationService
{
    private PDO $db;

    private const DEFAULT_INCOME_CODE = '4900';   // Other Services (fallback revenue account)
    private const MATCH_THRESHOLD     = 55;        // minimum confidence to surface a candidate
    private const PAYABLE_STATUSES    = ['sent', 'viewed', 'partial', 'overdue'];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CANDIDATE MATCHING (description-aware scoring)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Candidate deposits for a single invoice, scored & sorted high→low.
     */
    public function candidatesForInvoice(int $invoiceId, int $limit = 6): array
    {
        $invoices = $this->loadInvoicesForScoring([$invoiceId]);
        if (empty($invoices)) return [];
        $invoice = $invoices[0];

        $out = [];
        foreach ($this->loadUnmatchedDeposits() as $dep) {
            $score = $this->scoreDeposit($dep, $invoice);
            if ($score === null) continue;
            $out[] = $this->candidatePayload($dep, $invoice, $score);
        }
        usort($out, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        return array_slice($out, 0, $limit);
    }

    /**
     * Best candidate deposits for many invoices at once (drives the list-page pills
     * with one deposit query — no per-row N+1).
     *
     * @return array<int, array> invoiceId => [candidate, ...] (best `$per` each)
     */
    public function topCandidatesForInvoiceIds(array $invoiceIds, int $per = 4): array
    {
        $invoiceIds = array_values(array_filter(array_map('intval', $invoiceIds)));
        if (empty($invoiceIds)) return [];

        $invoices = $this->loadInvoicesForScoring($invoiceIds);
        if (empty($invoices)) return [];

        $deposits = $this->loadUnmatchedDeposits();
        if (empty($deposits)) return [];

        $map = [];
        foreach ($invoices as $invoice) {
            $hits = [];
            foreach ($deposits as $dep) {
                $score = $this->scoreDeposit($dep, $invoice);
                if ($score === null) continue;
                $hits[] = $this->candidatePayload($dep, $invoice, $score);
            }
            if ($hits) {
                usort($hits, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
                $map[(int)$invoice['id']] = array_slice($hits, 0, $per);
            }
        }
        return $map;
    }

    /** Remaining unallocated amount on a deposit. */
    public function remainingForDeposit(int $transactionId): float
    {
        $dep = $this->getDeposit($transactionId);
        if (!$dep) return 0.0;
        $original = $this->originalDepositAmount($dep);
        return round($original - $this->allocatedTotal($transactionId), 2);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ATTACH / DETACH
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Attach a bank deposit to one or more invoices.
     *
     * @param array $allocations  [ ['invoice_id'=>int, 'amount'=>float], ... ]
     * @return array  summary: recorded[], deposit_remaining, fully_allocated
     */
    public function attach(int $transactionId, array $allocations, int $userId): array
    {
        $clean = [];
        foreach ($allocations as $a) {
            $iid = (int)($a['invoice_id'] ?? 0);
            $amt = round((float)($a['amount'] ?? 0), 2);
            if ($iid > 0 && $amt > 0) {
                $clean[] = ['invoice_id' => $iid, 'amount' => $amt];
            }
        }
        if (empty($clean)) {
            throw new InvalidArgumentException('No valid allocations provided.');
        }

        $dep = $this->getDeposit($transactionId, true); // require income deposit
        if (!$dep) {
            throw new RuntimeException('Bank deposit not found, or it is already fully reconciled.');
        }

        $original  = $this->originalDepositAmount($dep);
        $remaining = round($original - $this->allocatedTotal($transactionId), 2);
        $sum       = round(array_sum(array_column($clean, 'amount')), 2);
        if ($sum > $remaining + 0.005) {
            throw new InvalidArgumentException(sprintf(
                'Allocation total $%.2f exceeds the deposit\'s remaining $%.2f.', $sum, $remaining
            ));
        }

        $method  = $this->deriveMethod($dep['description'] ?? '');
        $ref     = $this->deriveReference($dep);
        $payDate = substr((string)$dep['transaction_date'], 0, 10);

        $this->db->beginTransaction();
        try {
            $recorded = [];
            foreach ($clean as $a) {
                $result = $this->applyAllocation(
                    $a['invoice_id'], $a['amount'], $method, $ref, $payDate, $transactionId, $userId
                );
                if ($result) {
                    $recorded[] = $result;
                }
            }

            $singleInvoiceId = count($clean) === 1 ? $clean[0]['invoice_id'] : null;
            $this->recomputeDepositRow($transactionId, $original, $userId, $singleInvoiceId);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        $remainingAfter = $this->remainingForDeposit($transactionId);
        return [
            'recorded'          => $recorded,
            'deposit_remaining' => $remainingAfter,
            'fully_allocated'   => $remainingAfter <= 0.005,
        ];
    }

    /**
     * Apply one payment amount to one invoice: caps to the invoice's remaining
     * balance_due, writes an invoice_payment_allocations row, updates the invoice,
     * and upserts its income ledger row. Caller must already be inside a
     * transaction (this locks the invoice row via FOR UPDATE).
     *
     * Shared by attach() (bank-deposit reconciliation, $transactionId set) and
     * EtransferInboxService (e-Transfer inbox, $transactionId null — no bank
     * deposit exists yet) so both "record a payment" paths write to the same
     * allocation ledger instead of maintaining two.
     *
     * @return array|null  allocation summary, or null if the invoice was already
     *                      settled and nothing was applied (not an error — caller
     *                      should just skip it, mirroring attach()'s prior behaviour).
     */
    public function applyAllocation(
        int $invoiceId,
        float $requestedAmount,
        string $method,
        ?string $reference,
        string $paymentDate,
        ?int $transactionId,
        int $userId,
        ?int $etransferNotificationId = null
    ): ?array {
        $inv = $this->lockInvoice($invoiceId);
        if (!$inv) {
            throw new RuntimeException("Invoice #{$invoiceId} not found.");
        }

        $balance = round((float)$inv['balance_due'], 2);
        $applied = round(min($requestedAmount, max(0, $balance)), 2);
        if ($applied <= 0) {
            // Invoice already settled — skip rather than create a negative payment
            return null;
        }

        $this->db->prepare("
            INSERT INTO invoice_payment_allocations
                (invoice_id, transaction_id, etransfer_notification_id, amount, payment_date, method, reference, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId, $transactionId, $etransferNotificationId, $applied, $paymentDate, $method, $reference, $userId,
        ]);

        $newPaid    = round((float)$inv['amount_paid'] + $applied, 2);
        $newBalance = round(max(0, $balance - $applied), 2);
        $newStatus  = $newBalance <= 0.005 ? 'paid' : 'partial';

        $this->db->prepare("
            UPDATE invoices SET
                amount_paid       = ?,
                balance_due       = ?,
                status            = ?,
                payment_method    = ?,
                payment_reference = COALESCE(NULLIF(payment_reference, ''), ?),
                paid_at           = COALESCE(paid_at, ?),
                pdf_path          = NULL,
                pdf_version       = NULL,
                pdf_generated_at  = NULL
            WHERE id = ?
        ")->execute([
            $newPaid, $newBalance, $newStatus, $method, $reference,
            $paymentDate . ' 12:00:00', $invoiceId,
        ]);

        // Recognize income on the invoice ledger row (per-job attribution)
        $this->upsertInvoiceIncomeRow($invoiceId);

        return [
            'invoice_id'     => $invoiceId,
            'invoice_number' => $inv['invoice_number'],
            'requested'      => round($requestedAmount, 2),
            'applied'        => $applied,
            'capped'         => $applied < $requestedAmount - 0.005,
            'status'         => $newStatus,
            'fully_paid'     => $newStatus === 'paid',
        ];
    }

    /**
     * Reverse an attachment. Detaches every allocation of the deposit, or just the
     * allocation(s) for one invoice when $invoiceId is given.
     */
    public function detach(int $transactionId, ?int $invoiceId, int $userId): array
    {
        $sql = "SELECT id, invoice_id, amount FROM invoice_payment_allocations WHERE transaction_id = ?";
        $params = [$transactionId];
        if ($invoiceId) {
            $sql .= " AND invoice_id = ?";
            $params[] = $invoiceId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $allocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($allocs)) {
            throw new RuntimeException('No allocation found to detach.');
        }

        $dep      = $this->getDeposit($transactionId);
        $original = $dep ? $this->originalDepositAmount($dep) : 0.0;

        $this->db->beginTransaction();
        try {
            $reversed = [];
            foreach ($allocs as $alloc) {
                $inv = $this->lockInvoice((int)$alloc['invoice_id']);
                if ($inv) {
                    $total      = round((float)$inv['total'], 2);
                    $newPaid    = round(max(0, (float)$inv['amount_paid'] - (float)$alloc['amount']), 2);
                    $newBalance = round(max(0, $total - $newPaid), 2);

                    if ($newPaid <= 0.005) {
                        $newStatus = $this->unpaidStatusFor($inv);
                        $this->db->prepare("
                            UPDATE invoices SET amount_paid = 0, balance_due = ?, status = ?,
                                   paid_at = NULL, payment_reference = NULL
                            WHERE id = ?
                        ")->execute([$total, $newStatus, $alloc['invoice_id']]);
                        // Remove the invoice income ledger row entirely
                        $this->db->prepare("
                            DELETE FROM accounting_transactions
                            WHERE reference_type = 'invoice' AND reference_id = ?
                        ")->execute([$alloc['invoice_id']]);
                    } else {
                        $newStatus = $newBalance <= 0.005 ? 'paid' : 'partial';
                        $this->db->prepare("
                            UPDATE invoices SET amount_paid = ?, balance_due = ?, status = ?
                            WHERE id = ?
                        ")->execute([$newPaid, $newBalance, $newStatus, $alloc['invoice_id']]);
                        $this->upsertInvoiceIncomeRow((int)$alloc['invoice_id']);
                    }
                    $reversed[] = ['invoice_id' => (int)$alloc['invoice_id'], 'amount' => (float)$alloc['amount']];
                }

                $this->db->prepare("DELETE FROM invoice_payment_allocations WHERE id = ?")
                    ->execute([$alloc['id']]);
            }

            if ($dep && $original > 0) {
                $this->recomputeDepositRow($transactionId, $original, $userId, null);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        return ['reversed' => $reversed, 'deposit_remaining' => $this->remainingForDeposit($transactionId)];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SCORING INTERNALS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Score a deposit against an invoice. Blends amount, date proximity, and the
     * deposit's description text. Returns null below the surface threshold.
     *
     * @return array{confidence:int, reasons:string[]}|null
     */
    private function scoreDeposit(array $deposit, array $invoice): ?array
    {
        $depAmt  = round((float)$deposit['amount'], 2);
        $balance = round((float)$invoice['balance_due'], 2);
        if ($balance <= 0.005) return null;

        $reasons = [];

        // ── Amount (max 50) ──────────────────────────────────────────────
        if (abs($depAmt - $balance) <= 0.01) {
            $score = 50; $reasons[] = 'Exact amount';
        } elseif ($balance >= $depAmt && $balance <= $depAmt * 1.065) {
            $score = 42; $reasons[] = 'Amount matches (net of fee)';
        } elseif ($depAmt > $balance) {
            $score = 28; $reasons[] = 'Deposit covers this invoice';
        } elseif ($depAmt >= $balance * 0.5) {
            $score = 16; $reasons[] = 'Partial amount';
        } else {
            return null; // amounts unrelated
        }

        // ── Date proximity (max 20) ──────────────────────────────────────
        $dep  = strtotime((string)$deposit['transaction_date']);
        $iRef = strtotime((string)($invoice['invoice_date'] ?: $invoice['due_date'] ?: $deposit['transaction_date']));
        $dRef = strtotime((string)($invoice['due_date'] ?: $invoice['invoice_date'] ?: $deposit['transaction_date']));
        $dayDiff = min(abs($dep - $iRef), abs($dep - $dRef)) / 86400;
        if ($dayDiff <= 1)        { $score += 20; }
        elseif ($dayDiff <= 2)    { $score += 14; }
        elseif ($dayDiff <= 7)    { $score += 10; $reasons[] = 'Within a week'; }
        elseif ($dayDiff <= 21)   { $score += 5; }
        elseif ($dayDiff <= 60)   { $score += 2; }

        // ── Description text (max 35) ────────────────────────────────────
        $descScore = 0;
        $descRaw   = (string)($deposit['description'] ?? '');
        $descDigits = preg_replace('/\D/', '', $descRaw);
        $invDigits  = preg_replace('/\D/', '', (string)($invoice['invoice_number'] ?? ''));

        if (stripos($descRaw, (string)$invoice['invoice_number']) !== false
            || (strlen($invDigits) >= 4 && $descDigits !== '' && strpos($descDigits, $invDigits) !== false)) {
            $descScore = 35; $reasons[] = 'Invoice # in memo';
        } else {
            // Match memo words against each name field separately so the reason names
            // the field that actually matched (e.g. the company), not just the contact.
            $fields = [
                'company_name'  => trim((string)($invoice['company_name'] ?? '')),
                'contact_name'  => trim((string)($invoice['contact_name'] ?? '')),
                'property_name' => trim((string)($invoice['property_name'] ?? '')),
            ];
            $words = $this->extractNameWords($descRaw);
            foreach ($fields as $value) {
                if ($value === '') continue;
                $valLower = strtolower($value);
                foreach ($words as $w) {
                    if ($w !== '' && strpos($valLower, $w) !== false) {
                        $descScore = 25;
                        $reasons[] = 'Memo names ' . $value;
                        break 2;
                    }
                }
            }
        }

        // Stripe / processor reference in memo
        if ($descScore < 35) {
            foreach (['stripe_payment_intent_id', 'stripe_charge_id'] as $k) {
                $v = trim((string)($invoice[$k] ?? ''));
                if ($v !== '' && stripos($descRaw, $v) !== false) {
                    $descScore = max($descScore, 40);
                    $reasons[] = 'Payment reference matches';
                    break;
                }
            }
        }

        $score = min(100, $score + $descScore);
        if ($score < self::MATCH_THRESHOLD) return null;

        return ['confidence' => (int)$score, 'reasons' => $reasons];
    }

    private function candidatePayload(array $dep, array $invoice, array $score): array
    {
        $depAmt  = round((float)$dep['amount'], 2);
        $balance = round((float)$invoice['balance_due'], 2);
        return [
            'tx_id'            => (int)$dep['id'],
            'date'             => substr((string)$dep['transaction_date'], 0, 10),
            'description'      => (string)$dep['description'],
            'bank_account'     => (string)($dep['bank_account'] ?? ''),
            'amount'           => $depAmt,           // remaining unallocated on the deposit
            'suggested_amount' => round(min($depAmt, $balance), 2),
            'covers_more'      => $depAmt > $balance + 0.005,
            'confidence'       => $score['confidence'],
            'reasons'          => $score['reasons'],
        ];
    }

    /**
     * Generic banking / business-suffix words that must NOT count as a name match —
     * otherwise "...PROPERTY MANAGEMENT" in a memo falsely matches "Smith Property".
     */
    private const NAME_STOPWORDS = [
        'property','properties','management','mgmt','branch','deposit','transfer',
        'payment','cheque','cheq','chq','check','interac','etransfer','holdings',
        'holding','limited','ltd','inc','incorporated','corp','corporation','company',
        'services','service','group','account','online','banking','mobile','vancity',
        'credit','union','the','and','from','for','bill','invoice','llc','enterprises',
        'contracting','landscaping','lawn','care','maintenance',
    ];

    /** Split a bank description into candidate name words (≥3 chars, non-numeric, non-stopword). */
    private function extractNameWords(string $description): array
    {
        $clean = preg_replace(
            '/\b(INTERAC|E-TRANSFER|ETRANSFER|E-TRF|ETRF|IDP\s+PURCHASE|FROM|DEPOSIT|TRANSFER|PAYMENT|REF#?\s*\w+|STRIPE|PAYPAL|SQUARE|MONERIS|PAYMENTECH|\d+)\b/i',
            ' ',
            $description
        );
        $clean = trim((string)preg_replace('/\s+/', ' ', (string)$clean));
        $words = [];
        foreach (preg_split('/\s+/', strtolower($clean)) as $w) {
            // Strip surrounding punctuation, e.g. "(obsidian" → "obsidian", "management)" → "management"
            $w = preg_replace('/[^a-z0-9]/', '', $w);
            if (strlen($w) >= 3 && !is_numeric($w) && !in_array($w, self::NAME_STOPWORDS, true)) {
                $words[] = $w;
            }
        }
        return array_values(array_unique($words));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // LEDGER INTERNALS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Recompute the bank-deposit ledger row from its allocations:
     *  • remaining ≈ 0  → type='transfer', amount=original, status='reconciled' (excluded from P&L)
     *  • remaining > 0  → type='income',   amount=remaining, status='cleared'
     *  • no allocations → restore original income deposit (detach to empty)
     */
    private function recomputeDepositRow(int $txId, float $original, int $userId, ?int $singleInvoiceId): void
    {
        $allocated = $this->allocatedTotal($txId);
        $remaining = round($original - $allocated, 2);

        if ($allocated <= 0.005) {
            // Fully detached — restore the standalone income deposit
            $this->db->prepare("
                UPDATE accounting_transactions SET
                    type = 'income', amount = ?, status = 'cleared',
                    matched_invoice_id = NULL, match_confidence = NULL,
                    matched_at = NULL, matched_by = NULL
                WHERE id = ?
            ")->execute([$original, $txId]);
            $this->setStagingMatchStatus($txId, 'unmatched');
            return;
        }

        if ($remaining <= 0.005) {
            // Fully allocated — becomes a cash-clearing transfer (excluded from income)
            $this->db->prepare("
                UPDATE accounting_transactions SET
                    type = 'transfer', amount = ?, status = 'reconciled',
                    matched_invoice_id = ?, matched_at = NOW(), matched_by = ?
                WHERE id = ?
            ")->execute([$original, $singleInvoiceId, (string)$userId, $txId]);
            $this->setStagingMatchStatus($txId, 'matched_invoice');
        } else {
            // Partially allocated — income for the remainder
            $this->db->prepare("
                UPDATE accounting_transactions SET
                    type = 'income', amount = ?, status = 'cleared',
                    matched_invoice_id = ?, matched_at = NOW(), matched_by = ?
                WHERE id = ?
            ")->execute([$remaining, $singleInvoiceId, (string)$userId, $txId]);
            $this->setStagingMatchStatus($txId, 'manually_matched');
        }
    }

    /** Upsert the invoice's income ledger row (mirrors AccountingService::syncFromInvoices). */
    private function upsertInvoiceIncomeRow(int $invoiceId): void
    {
        $stmt = $this->db->prepare("
            SELECT id, COALESCE(paid_at, updated_at) AS td,
                   COALESCE(amount_paid, total, 0)   AS amt,
                   COALESCE(tax_amount, 0)           AS gst,
                   contact_id, plan_id, notes
            FROM invoices WHERE id = ?
        ");
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv || (float)$inv['amt'] <= 0) return;

        $accountId = $this->getDefaultIncomeAccountId();
        $desc = 'Invoice payment';
        if (!empty($inv['notes'])) {
            $desc .= ' — ' . mb_substr(strip_tags((string)$inv['notes']), 0, 120);
        }

        $this->db->prepare("
            INSERT INTO accounting_transactions
                (transaction_date, type, account_id, amount, gst_amount, pst_amount,
                 description, reference_type, reference_id, job_id, contact_id,
                 status, needs_review, is_auto_categorized)
            VALUES (?, 'income', ?, ?, ?, 0, ?, 'invoice', ?, ?, ?, 'reconciled', 0, 0)
            ON DUPLICATE KEY UPDATE
                transaction_date = VALUES(transaction_date),
                amount           = VALUES(amount),
                gst_amount       = VALUES(gst_amount),
                description      = VALUES(description),
                account_id       = VALUES(account_id),
                job_id           = VALUES(job_id),
                contact_id       = VALUES(contact_id),
                status           = 'reconciled',
                updated_at       = CURRENT_TIMESTAMP
        ")->execute([
            substr((string)$inv['td'], 0, 10),
            $accountId,
            (float)$inv['amt'],
            (float)$inv['gst'],
            $desc,
            $invoiceId,
            $inv['plan_id']    ? (int)$inv['plan_id']    : null,
            $inv['contact_id'] ? (int)$inv['contact_id'] : null,
        ]);
    }

    private function setStagingMatchStatus(int $txId, string $status): void
    {
        try {
            $this->db->prepare("UPDATE bank_import_rows SET match_status = ? WHERE transaction_id = ?")
                ->execute([$status, $txId]);
        } catch (PDOException $e) {
            // Staging row may not exist (manually-created deposit) — non-fatal
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // QUERIES / HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    private function loadInvoicesForScoring(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) return [];
        $place = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare("
            SELECT
                i.id, i.invoice_number, i.balance_due, i.total, i.amount_paid,
                i.invoice_date, i.due_date, i.status, i.contact_id, i.plan_id,
                i.stripe_payment_intent_id, i.stripe_charge_id,
                TRIM(CONCAT(COALESCE(ct.first_name,''),' ',COALESCE(ct.last_name,''))) AS contact_name,
                co.company_name, p.property_name
            FROM invoices i
            LEFT JOIN contacts   ct ON ct.id = i.contact_id
            LEFT JOIN companies  co ON co.id = i.company_id
            LEFT JOIN properties p  ON p.id  = i.property_id
            WHERE i.id IN ($place)
              AND i.balance_due > 0.005
              AND i.status IN ('sent','viewed','partial','overdue')
        ");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadUnmatchedDeposits(int $limit = 500): array
    {
        $stmt = $this->db->prepare("
            SELECT id, transaction_date, amount, description, bank_account
            FROM accounting_transactions
            WHERE reference_type = 'bank_import'
              AND type = 'income'
              AND amount > 0.005
            ORDER BY transaction_date DESC, id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getDeposit(int $txId, bool $requireIncome = false): ?array
    {
        $sql = "SELECT id, type, amount, transaction_date, description, account_id
                FROM accounting_transactions
                WHERE id = ? AND reference_type = 'bank_import'";
        if ($requireIncome) {
            $sql .= " AND type = 'income' AND amount > 0.005";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Original (pre-allocation) deposit amount — from staging row, else reconstructed. */
    private function originalDepositAmount(array $dep): float
    {
        $stmt = $this->db->prepare(
            "SELECT amount FROM bank_import_rows WHERE transaction_id = ? ORDER BY id LIMIT 1"
        );
        $stmt->execute([(int)$dep['id']]);
        $staged = $stmt->fetchColumn();
        if ($staged !== false && $staged !== null) {
            return round((float)$staged, 2);
        }
        // No staging row: a 'transfer' row already holds the full amount; an 'income'
        // row holds the remainder, so reconstruct original = remainder + allocated.
        if (($dep['type'] ?? '') === 'transfer') {
            return round((float)$dep['amount'], 2);
        }
        return round((float)$dep['amount'] + $this->allocatedTotal((int)$dep['id']), 2);
    }

    private function allocatedTotal(int $txId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM invoice_payment_allocations WHERE transaction_id = ?"
        );
        $stmt->execute([$txId]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    private function lockInvoice(int $invoiceId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, invoice_number, total, amount_paid, balance_due, status, due_date
            FROM invoices WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Status to restore an invoice to when a payment is fully removed. */
    private function unpaidStatusFor(array $inv): string
    {
        $due = $inv['due_date'] ?? null;
        if ($due && strtotime((string)$due) < strtotime('today')) {
            return 'overdue';
        }
        return 'sent';
    }

    private function deriveMethod(string $description): string
    {
        if (preg_match('/\b(INTERAC|E-TRANSFER|E\s*TRF|ETRANSFER|ETRF|IDP\s+PURCHASE)\b/i', $description)) {
            return 'e_transfer';
        }
        if (preg_match('/\bSTRIPE\b/i', $description)) return 'stripe';
        if (preg_match('/\b(PAYPAL|SQUARE|MONERIS|PAYMENTECH|VISA|MASTERCARD|CREDIT)\b/i', $description)) {
            return 'credit_card';
        }
        if (preg_match('/\b(CHEQUE|CHEQ|CHQ|CHECK)\b/i', $description)) return 'cheque';
        if (preg_match('/\b(CASH|DEPOSIT)\b/i', $description)) return 'cash';
        return 'other';
    }

    private function deriveReference(array $dep): string
    {
        $desc = (string)($dep['description'] ?? '');
        if (preg_match('/\b(pi_[A-Za-z0-9]{10,}|ch_[A-Za-z0-9]{10,})\b/', $desc, $m)) {
            return $m[1];
        }
        if (preg_match('/REF#?\s*([A-Za-z0-9]{4,})/i', $desc, $m)) {
            return strtoupper($m[1]);
        }
        return 'BANK-TXN-' . (int)$dep['id'];
    }

    private function getDefaultIncomeAccountId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
        $stmt->execute([self::DEFAULT_INCOME_CODE]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException("Income account '" . self::DEFAULT_INCOME_CODE . "' not found.");
        }
        return (int)$id;
    }
}
