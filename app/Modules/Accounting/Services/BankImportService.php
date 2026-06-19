<?php
/**
 * BankImportService — bank statement import for the accounting ledger.
 *
 * Supports CSV and PDF (emailed bank statements via smalot/pdfparser).
 *
 * Workflow:
 *  1. preview(content, mapping)    → validate + parse CSV, return rows for user to review
 *     previewPdf(filePath)         → extract text from PDF, parse transactions, return rows
 *  2. commit(sessionId, rows)      → insert staged rows into accounting_transactions
 *  3. rollback(sessionId)          → delete all transactions from a session
 *
 * Column Mapping (CSV only):
 *  The user specifies which CSV column index maps to each field:
 *  { date: 0, description: 1, debit: 2, credit: 3 }
 *  OR single-amount format (positive = income, negative = expense):
 *  { date: 0, description: 1, amount: 2 }
 *
 * Supported bank presets (auto-detected by filename or user selection):
 *  TD, RBC, BMO, CIBC, Scotiabank, Generic
 *
 * Duplicate detection:
 *  Checks existing accounting_transactions for same date ± 1 day + same amount.
 *  Flags as duplicate if match_confidence >= 80%.
 */
declare(strict_types=1);

class BankImportService
{
    private PDO $db;

    /** Per-page raw OCR text captured during extractTextViaImagickOcr(). */
    private array $rawPageTexts = [];

    // Known bank/credit-card CSV presets — column mapping, skip-header-rows count,
    // and `kind` ('bank' | 'credit_card'). `kind` drives transaction routing:
    //   bank        — debit=expense, credit=income (income rows are invoice-matched)
    //   credit_card — charge=expense, refund=expense reversal, payment=settlement
    //                 (no income/invoice matching; see classifyCreditCardRow + parseCSV)
    private const BANK_PRESETS = [
        'td'         => ['name' => 'TD Bank',            'kind' => 'bank', 'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'rbc'        => ['name' => 'RBC',                'kind' => 'bank', 'date' => 2, 'description' => 4, 'debit' => 6, 'credit' => 7, 'skip' => 1],
        'bmo'        => ['name' => 'BMO',                'kind' => 'bank', 'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'cibc'       => ['name' => 'CIBC',               'kind' => 'bank', 'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'scotiabank' => ['name' => 'Scotiabank',         'kind' => 'bank', 'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'vancity'    => ['name' => 'Vancity (Bank)',     'kind' => 'bank', 'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        // ── Credit card statements ────────────────────────────────────────────────
        // TD credit card exports vary: some have Debit/Credit columns, others a single
        // signed Amount column (charges positive, payments/credits negative). The
        // single-amount mapping covers both because parseCSV treats a missing
        // debit/credit pair as single-amount. We default to debit/credit; the header
        // detector (detectStatementType) upgrades to single-amount when it sees one.
        'td_cc'      => ['name' => 'TD Credit Card',     'kind' => 'credit_card', 'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'vancity_cc' => ['name' => 'Vancity Credit Card','kind' => 'credit_card', 'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'generic'    => ['name' => 'Generic',            'kind' => 'bank', 'date' => 0, 'description' => 1, 'amount' => 2,               'skip' => 1],
        'generic_cc' => ['name' => 'Generic Credit Card','kind' => 'credit_card', 'date' => 0, 'description' => 1, 'amount' => 2,        'skip' => 1],
    ];

    // Default fallback accounts when no rule matches
    private const DEFAULT_INCOME_CODE  = '4900';
    private const DEFAULT_EXPENSE_CODE = '6900';
    private const CREDIT_CARD_CODE     = '2400';   // Credit Card Payable (liability)

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRESET DETECTION
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Detect bank preset from filename.
     * Returns preset key or 'generic'.
     */
    public function detectPreset(string $filename): string
    {
        $lower = strtolower($filename);
        foreach (array_keys(self::BANK_PRESETS) as $key) {
            if (strpos($lower, $key) !== false) return $key;
        }
        return 'generic';
    }

    public function getPresets(): array
    {
        return array_map(fn($p) => $p['name'], self::BANK_PRESETS);
    }

    public function getPreset(string $key): ?array
    {
        return self::BANK_PRESETS[$key] ?? null;
    }

    /**
     * Return the routing kind ('bank' | 'credit_card') for a preset key.
     * Unknown keys default to 'bank'.
     */
    public function getPresetKind(string $key): string
    {
        return self::BANK_PRESETS[$key]['kind'] ?? 'bank';
    }

    /**
     * Detect the statement type from a CSV header row (and optionally filename),
     * returning a preset key: distinguishes Vancity bank, TD credit card, and
     * Vancity credit card from one another.
     *
     * Header-based detection is best-effort — bank/CC CSV exports are inconsistent
     * (TD in particular often ships headerless). The UI dropdown remains
     * authoritative; this only improves the auto-selected default.
     *
     * Strategy:
     *   1. Identify the institution from the header text / filename (vancity, td, …).
     *   2. Decide bank vs credit-card from column-name signatures
     *      (credit-card statements mention card/visa/mastercard/posted; bank
     *       statements mention withdrawal/deposit/cheque/balance).
     *
     * @param string $headerLine First (header) line of the CSV
     * @param string $filename   Original upload filename (optional hint)
     * @return string preset key, or '' when nothing matched (caller falls back)
     */
    public function detectStatementType(string $headerLine, string $filename = ''): string
    {
        // Normalize: collapse punctuation/underscores (CSV commas, filename
        // separators) to single spaces so word-boundary matches work on tokens
        // like "td_visa" or "vancity_chequing".
        $hay = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $headerLine . ' ' . $filename) ?? '');

        // Credit-card signals: explicit card wording, or a "posted"/"transaction"
        // date pairing typical of card exports.
        $looksCC = (bool)preg_match('/\b(credit\s*card|visa|mastercard|master\s*card|amex|american\s+express|card\s*(no|number|holder)?|posted\s+date)\b/', $hay);
        // Bank signals: chequing/withdrawal/deposit columns.
        $looksBank = (bool)preg_match('/\b(withdrawal|deposit|cheque|chequing|chequed|opening\s+balance|account\s+balance)\b/', $hay);

        // Single signed amount column (no separate debit/credit)?
        $hasDebitCredit = (bool)preg_match('/\b(debit|withdrawal)\b/', $hay)
                       && (bool)preg_match('/\b(credit|deposit)\b/', $hay);

        $isVancity = (bool)preg_match('/\b(vancity|vcty)\b/', $hay);
        $isTd      = (bool)preg_match('/\b(td|toronto\s*dominion)\b/', $hay);

        if ($isVancity) {
            return ($looksCC && !$looksBank) ? 'vancity_cc' : 'vancity';
        }
        if ($isTd) {
            if ($looksCC && !$looksBank) {
                return 'td_cc';
            }
            return 'td';
        }

        // Institution unknown — still classify bank vs CC so routing is correct.
        if ($looksCC && !$looksBank) {
            return $hasDebitCredit ? 'generic_cc' : 'generic_cc';
        }
        return '';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PARSE + PREVIEW
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Parse CSV content using the provided column mapping.
     * Returns an array of parsed row objects with duplicate flags.
     *
     * @param string $content   Raw CSV file content
     * @param array  $mapping   ['date'=>col, 'description'=>col, 'debit'=>col, 'credit'=>col]
     *                          OR ['date'=>col, 'description'=>col, 'amount'=>col]
     * @param int    $skipRows  Number of header rows to skip
     * @param string $bankName  Label for the bank account
     */
    public function preview(string $content, array $mapping, int $skipRows = 1, string $bankName = '', string $kind = 'bank'): array
    {
        require_once __DIR__ . '/RulesEngine.php';
        $engine = new RulesEngine($this->db);

        $rows   = $this->parseCSV($content, $mapping, $skipRows, $kind);
        $totals = ['income' => 0, 'expense' => 0, 'duplicates' => 0, 'matched' => 0, 'rows' => count($rows)];

        // Load account IDs for defaults
        $defaultIncomeId  = $this->getAccountId(self::DEFAULT_INCOME_CODE);
        $defaultExpenseId = $this->getAccountId(self::DEFAULT_EXPENSE_CODE);
        $ccPayableId      = $this->getAccountId(self::CREDIT_CARD_CODE);

        foreach ($rows as &$row) {
            // ── Credit-card settlement: the monthly payment that moves cash from the
            //    bank to the card is a TRANSFER (excluded from P&L), not an expense.
            //    Flagged here so it is never double-counted against the CC statement's
            //    individual charges. Applies to both the CC statement's "payment
            //    received" line and the bank statement's "pay credit card" debit.
            if ($this->applyCcSettlement($row, $ccPayableId, $defaultExpenseId)) {
                continue; // transfer — excluded from income/expense totals
            }

            // Apply rules engine for preview categorization
            $match = $engine->previewMatch($row['description'], '', $row['type']);
            if ($match) {
                $row['account_id']   = $match['account_id'];
                $row['account_name'] = $match['account_name'];
                $row['account_code'] = $match['account_code'];
                $row['rule_id']      = $match['rule_id'];
                $row['auto_cat']     = true;
            } else {
                $id = $row['type'] === 'income' ? $defaultIncomeId : $defaultExpenseId;
                $row['account_id']   = $id;
                $row['account_name'] = $row['type'] === 'income' ? 'Other Services' : 'Miscellaneous Expenses';
                $row['account_code'] = $row['type'] === 'income' ? self::DEFAULT_INCOME_CODE : self::DEFAULT_EXPENSE_CODE;
                $row['rule_id']      = null;
                $row['auto_cat']     = false;
            }

            // Two-stage duplicate / match detection (same logic as enrichRows)
            $trueDupe = $this->checkTrueDuplicate($row['date'], $row['amount'], $row['type']);
            if ($trueDupe) {
                $row['is_duplicate']    = true;
                $row['duplicate_type']  = 'true_duplicate';
                $row['duplicate_tx_id'] = $trueDupe;
                $row['match_candidate'] = false;
                $totals['duplicates']++;
            } else {
                $expenseMatch = $this->findExpenseMatch(
                    $row['date'], $row['amount'], $row['type'], $row['description']
                );
                if ($expenseMatch) {
                    $exp = $expenseMatch['expense'];
                    $row['is_duplicate']       = false;
                    $row['duplicate_type']     = null;
                    $row['duplicate_tx_id']    = null;
                    $row['match_candidate']    = true;
                    $row['matched_expense_id'] = (int)$exp['id'];
                    $row['match_confidence']   = (int)$expenseMatch['confidence'];
                    $row['matched_expense'] = [
                        'id'          => (int)$exp['id'],
                        'vendor_name' => $exp['vendor_name_raw'] ?? '',
                        'expense_date'=> $exp['expense_date'] ?? '',
                        'receipt_path'=> $exp['receipt_path'] ?? null,
                    ];
                    if (!empty($exp['accounting_category'])) {
                        $catId = $this->resolveAccountFromCategory($exp['accounting_category']);
                        if ($catId) { $row['account_id'] = $catId; $row['auto_cat'] = true; }
                    }
                    $row['gst_amount'] = (float)($exp['tax_amount'] ?? 0);
                    $row['vendor_id']  = $exp['vendor_id'] ? (int)$exp['vendor_id'] : null;
                    $row['job_id']     = $exp['job_id']    ? (int)$exp['job_id']    : null;
                    if (!isset($totals['matched'])) $totals['matched'] = 0;
                    $totals['matched']++;
                } else {
                    $row['is_duplicate']    = false;
                    $row['duplicate_type']  = null;
                    $row['duplicate_tx_id'] = null;
                    $row['match_candidate'] = false;

                    // Invoice matching for income rows (same as enrichRows)
                    if ($row['type'] === 'income') {
                        $invMatch = null;
                        if ($this->isPaymentProcessor($row['description'])) {
                            $invMatch = $this->findInvoiceMatchForProcessor(
                                $row['date'], $row['amount'], $row['description']
                            );
                        } elseif ($this->isETransfer($row['description'])) {
                            $invMatch = $this->findInvoiceMatchForETransfer(
                                $row['date'], $row['amount'], $row['description']
                            );
                        }
                        if ($invMatch) {
                            $row['match_candidate']       = true;
                            $row['matched_invoice_tx_id'] = $invMatch['tx_id'];
                            $row['matched_invoice_id']    = $invMatch['invoice_id'];
                            $row['match_confidence']      = $invMatch['confidence'];
                            $row['match_method']          = $invMatch['match_method'] ?? 'amount';
                            $row['processing_fee']        = $invMatch['processing_fee'];
                            $row['matched_invoice'] = [
                                'invoice_number'   => $invMatch['invoice_number'],
                                'client_name'      => $invMatch['client_name'],
                                'property_address' => $invMatch['property_address'],
                                'invoice_amount'   => $invMatch['invoice_amount'],
                                'payment_reference'=> $invMatch['payment_reference'] ?? '',
                            ];
                            $totals['matched']++;
                        }
                    }
                }
            }

            if ($row['type'] === 'income')  $totals['income']  += $row['amount'];
            if ($row['type'] === 'expense') $totals['expense'] += $row['amount'];
        }
        unset($row);

        return [
            'rows'     => $rows,
            'totals'   => $totals,
            'bank'     => $bankName,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // COMMIT
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Commit previewed rows into accounting_transactions.
     * Creates a bank_import_session to track the batch.
     *
     * @param array  $rows      Parsed rows from preview() (may include user edits)
     * @param int    $userId
     * @param string $bankName
     * @param string $accountName  Which bank account (e.g. "TD Chequing")
     * @param bool   $skipDuplicates  Don't import rows marked as duplicate
     */
    public function commit(
        array $rows,
        int $userId,
        string $bankName = '',
        string $accountName = '',
        bool $skipDuplicates = true,
        int $bankAccountId = 0
    ): array {

        $sessionId = $this->createSession([
            'filename'        => $bankName ?: 'bank_import',
            'bank_name'       => $bankName,
            'account_name'    => $accountName,
            'bank_account_id' => $bankAccountId ?: null,
            'row_count'       => count($rows),
            'created_by'      => $userId,
        ]);

        $imported   = 0;
        $skipped    = 0;
        $dupes      = 0;
        $reconciled = 0;

        $this->db->beginTransaction();
        try {
            // Standard bank-import INSERT (for unmatched rows)
            $txStmt = $this->db->prepare("
                INSERT INTO accounting_transactions
                    (transaction_date, type, account_id, amount, gst_amount, pst_amount,
                     description, reference_type, status, is_auto_categorized, rule_id,
                     bank_account, bank_account_id, import_session_id, created_by)
                VALUES (?, ?, ?, ?, ?, 0, ?, 'bank_import', 'cleared', ?, ?, ?, ?, ?, ?)
            ");

            // Staging row INSERT — includes new match_status + matched_expense_id columns
            $rowStmt = $this->db->prepare("
                INSERT INTO bank_import_rows
                    (session_id, transaction_date, description, raw_amount, type, amount,
                     account_id, transaction_id, is_duplicate, duplicate_of_id, rule_id, raw_row,
                     match_status, matched_expense_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($rows as $row) {
                $rawAmount  = $row['type'] === 'expense' ? -$row['amount'] : $row['amount'];
                // bank_import_rows.type is ENUM('income','expense'); credit-card
                // settlement rows carry type='transfer' on the ledger — stage them
                // as 'expense' (the full row, incl. type/cc_role, is preserved in raw_row).
                $stagedType = in_array($row['type'], ['income', 'expense'], true) ? $row['type'] : 'expense';
                $matchStatus = 'unmatched';
                $matchExpId  = null;

                // ── Skip true duplicates ───────────────────────────────────────
                if ($skipDuplicates && !empty($row['is_duplicate'])) {
                    $dupes++;
                    $matchStatus = 'true_duplicate';
                    $rowStmt->execute([
                        $sessionId, $row['date'], $row['description'], $rawAmount,
                        $stagedType, $row['amount'], $row['account_id'],
                        null, 1, $row['duplicate_tx_id'] ?? null,
                        $row['rule_id'] ?? null, json_encode($row),
                        $matchStatus, null,
                    ]);
                    continue;
                }

                // ── Reconcile matched expense receipts ────────────────────────
                if (!empty($row['match_candidate']) && !empty($row['matched_expense_id'])) {
                    $expenseId  = (int)$row['matched_expense_id'];
                    $confidence = (int)($row['match_confidence'] ?? 0);
                    $matchStatus = 'auto_matched';
                    $matchExpId  = $expenseId;

                    // Find the expense's existing accounting_transactions row
                    $existingStmt = $this->db->prepare("
                        SELECT id FROM accounting_transactions
                        WHERE reference_type = 'expense' AND reference_id = ?
                        LIMIT 1
                    ");
                    $existingStmt->execute([$expenseId]);
                    $existingTxId = $existingStmt->fetchColumn();

                    if ($existingTxId) {
                        // Update existing expense transaction: mark reconciled + bank metadata
                        $this->db->prepare("
                            UPDATE accounting_transactions SET
                              status             = 'reconciled',
                              bank_account       = ?,
                              bank_account_id    = COALESCE(bank_account_id, ?),
                              import_session_id  = ?,
                              matched_expense_id = ?,
                              match_confidence   = ?,
                              matched_at         = NOW(),
                              matched_by         = 'auto',
                              gst_amount         = CASE WHEN gst_amount = 0 THEN ? ELSE gst_amount END,
                              vendor_id          = COALESCE(vendor_id, ?),
                              job_id             = COALESCE(job_id, ?)
                            WHERE id = ?
                        ")->execute([
                            $accountName, $bankAccountId ?: null, $sessionId,
                            $expenseId, $confidence,
                            (float)($row['gst_amount'] ?? 0),
                            !empty($row['vendor_id']) ? (int)$row['vendor_id'] : null,
                            !empty($row['job_id'])    ? (int)$row['job_id']    : null,
                            (int)$existingTxId,
                        ]);
                        $txId = (int)$existingTxId;
                    } else {
                        // No accounting tx exists yet for this expense — create one as reconciled
                        $txStmt->execute([
                            $row['date'], $row['type'], $row['account_id'], $row['amount'],
                            (float)($row['gst_amount'] ?? 0),
                            $row['description'],
                            $row['auto_cat'] ? 1 : 0,
                            $row['rule_id'] ?? null,
                            $accountName, $bankAccountId ?: null, $sessionId, $userId,
                        ]);
                        $txId = (int)$this->db->lastInsertId();
                        // Upgrade to reconciled with match metadata
                        $this->db->prepare("
                            UPDATE accounting_transactions SET
                              reference_type     = 'expense',
                              reference_id       = ?,
                              status             = 'reconciled',
                              matched_expense_id = ?,
                              match_confidence   = ?,
                              matched_at         = NOW(),
                              matched_by         = 'auto',
                              vendor_id          = COALESCE(vendor_id, ?),
                              job_id             = COALESCE(job_id, ?)
                            WHERE id = ?
                        ")->execute([
                            $expenseId, $expenseId, $confidence,
                            !empty($row['vendor_id']) ? (int)$row['vendor_id'] : null,
                            !empty($row['job_id'])    ? (int)$row['job_id']    : null,
                            $txId,
                        ]);
                    }

                    // Capture rollback intent: a pre-existing expense tx is only
                    // un-reconciled; a tx we created in this session is deleted.
                    $row['_revert'] = $existingTxId
                        ? ['unflag_tx' => [(int)$existingTxId]]
                        : ['delete_tx' => [$txId]];
                    $rowStmt->execute([
                        $sessionId, $row['date'], $row['description'], $rawAmount,
                        $row['type'], $row['amount'], $row['account_id'],
                        $txId, 0, null,
                        $row['rule_id'] ?? null, json_encode($row),
                        $matchStatus, $matchExpId,
                    ]);

                    $reconciled++;
                    $imported++;
                    continue;
                }

                // ── Reconcile processor payment (Stripe/PayPal/etc) to invoice ─
                if (!empty($row['match_candidate']) && !empty($row['matched_invoice_tx_id'])) {
                    $invTxId       = (int)$row['matched_invoice_tx_id'];
                    $processingFee = (float)($row['processing_fee'] ?? 0);
                    $confidence    = (int)($row['match_confidence'] ?? 88);
                    $payRef        = $row['matched_invoice']['payment_reference'] ?? '';

                    // Mark the invoice's accounting_transactions row as reconciled
                    $this->db->prepare("
                        UPDATE accounting_transactions SET
                          status            = 'reconciled',
                          bank_account      = ?,
                          bank_account_id   = COALESCE(bank_account_id, ?),
                          import_session_id = ?,
                          match_confidence  = ?,
                          matched_at        = NOW(),
                          matched_by        = 'auto'
                        WHERE id = ?
                    ")->execute([$accountName, $bankAccountId ?: null, $sessionId, $confidence, $invTxId]);

                    // Book the bank deposit as a cash-clearing TRANSFER — NOT a second
                    // income row. The invoice's own ledger row (marked 'reconciled'
                    // above) already recognizes this revenue; booking the deposit as
                    // income too would double-count it in the P&L / GST. Mirrors
                    // InvoiceReconciliationService::recomputeDepositRow() (fully-allocated
                    // deposit → type='transfer', excluded from income reports).
                    $invoiceIdForDeposit = (int)($row['matched_invoice_id'] ?? 0);
                    $txStmt->execute([
                        $row['date'], 'transfer', $row['account_id'], $row['amount'],
                        0, $row['description'],
                        0, null, $accountName, $bankAccountId ?: null, $sessionId, $userId,
                    ]);
                    $txId = (int)$this->db->lastInsertId();

                    // Flag the transfer reconciled + link it to the invoice (audit trail).
                    $this->db->prepare("
                        UPDATE accounting_transactions SET
                          status             = 'reconciled',
                          matched_invoice_id = ?,
                          match_confidence   = ?,
                          matched_at         = NOW(),
                          matched_by         = 'auto',
                          payment_reference  = ?
                        WHERE id = ?
                    ")->execute([$invoiceIdForDeposit ?: null, $confidence, $payRef ?: null, $txId]);

                    // Close the invoice if this was an e-Transfer (Stripe closes via webhook)
                    $matchMethod   = $row['match_method'] ?? '';
                    $invoiceId     = (int)($row['matched_invoice_id'] ?? 0);
                    $priorInvoice  = null;
                    if ($matchMethod === 'etransfer' && $invoiceId) {
                        // Snapshot prior invoice state BEFORE closing it, so rollback()
                        // can restore it exactly (status/amount_paid/balance/method/paid_at).
                        $invRow = $this->db->prepare("
                            SELECT total, amount_paid, balance_due, status, payment_method, paid_at
                            FROM invoices
                            WHERE id = ? AND status NOT IN ('paid','cancelled')
                            LIMIT 1
                        ");
                        $invRow->execute([$invoiceId]);
                        $inv = $invRow->fetch(PDO::FETCH_ASSOC);
                        if ($inv) {
                            $priorInvoice = [
                                'id'             => $invoiceId,
                                'status'         => $inv['status'],
                                'amount_paid'    => $inv['amount_paid'],
                                'balance_due'    => $inv['balance_due'],
                                'payment_method' => $inv['payment_method'],
                                'paid_at'        => $inv['paid_at'],
                            ];
                            $this->db->prepare("
                                UPDATE invoices SET
                                    status           = 'paid',
                                    amount_paid      = total,
                                    balance_due      = 0,
                                    payment_method   = 'e_transfer',
                                    paid_at          = COALESCE(paid_at, ?)
                                WHERE id = ?
                            ")->execute([$row['date'], $invoiceId]);
                        }
                    }

                    // Auto-create the processing fee as an expense
                    if ($processingFee > 0.01) {
                        $feeAcctId = $this->getProcessingFeesAccountId();
                        $this->db->prepare("
                            INSERT INTO accounting_transactions
                                (transaction_date, type, account_id, amount, description,
                                 reference_type, status, is_auto_categorized,
                                 bank_account, bank_account_id, import_session_id, created_by)
                            VALUES (?, 'expense', ?, ?, ?, 'bank_import', 'cleared', 1, ?, ?, ?, ?)
                        ")->execute([
                            $row['date'], $feeAcctId, $processingFee,
                            'Payment processing fee — ' . substr($row['description'], 0, 100),
                            $accountName, $bankAccountId ?: null, $sessionId, $userId,
                        ]);
                    }

                    // Capture what rollback() must undo for this row: un-reconcile the
                    // invoice's income ledger row, and reopen the invoice if we closed it.
                    $row['_revert'] = ['unflag_tx' => [$invTxId]];
                    if ($priorInvoice) {
                        $row['_revert']['reopen_invoice'] = $priorInvoice;
                    }
                    $rowStmt->execute([
                        $sessionId, $row['date'], $row['description'], $rawAmount,
                        'income', $row['amount'], $row['account_id'],
                        $txId, 0, null, null, json_encode($row),
                        'auto_matched', null,
                    ]);

                    $reconciled++;
                    $imported++;
                    continue;
                }

                // ── Standard bank-import transaction (no expense match) ────────
                $txStmt->execute([
                    $row['date'], $row['type'], $row['account_id'], $row['amount'],
                    (float)($row['gst_amount'] ?? 0),
                    $row['description'],
                    $row['auto_cat'] ? 1 : 0,
                    $row['rule_id'] ?? null,
                    $accountName, $bankAccountId ?: null, $sessionId, $userId,
                ]);
                $txId = (int)$this->db->lastInsertId();

                $rowStmt->execute([
                    $sessionId, $row['date'], $row['description'], $rawAmount,
                    $stagedType, $row['amount'], $row['account_id'],
                    $txId, 0, null,
                    $row['rule_id'] ?? null, json_encode($row),
                    'unmatched', null,
                ]);

                $imported++;
            }

            // Update session stats
            $this->db->prepare("
                UPDATE bank_import_sessions
                SET imported_count = ?, skipped_count = ?, duplicate_count = ?,
                    date_from = (SELECT MIN(transaction_date) FROM bank_import_rows WHERE session_id = ?),
                    date_to   = (SELECT MAX(transaction_date) FROM bank_import_rows WHERE session_id = ?),
                    status = 'imported'
                WHERE id = ?
            ")->execute([$imported, $skipped, $dupes, $sessionId, $sessionId, $sessionId]);

            $this->db->commit();

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Learn from confirmed categorizations — non-critical, runs after commit.
        try {
            $this->learnFromCommit($rows, $userId);
        } catch (Throwable $ignored) {
            // Learning failure must never surface to the user or undo the import.
        }

        return [
            'session_id'  => $sessionId,
            'imported'    => $imported,
            'skipped'     => $skipped,
            'duplicates'  => $dupes,
            'reconciled'  => $reconciled,
        ];
    }

    /**
     * Rollback an import session — fully reverse everything commit() changed.
     *
     * commit() does more than insert bank-import rows: it un-reconciles/closes
     * pre-existing invoice + expense ledger rows and can mark invoices 'paid'.
     * A delete-only rollback left those mutations in place (falsely-paid invoices,
     * orphaned 'reconciled' flags). We now replay the per-row reversal payload that
     * commit() recorded in bank_import_rows.raw_row._revert, inside one transaction.
     *
     * Backward-compatible: sessions committed before this change have no _revert
     * payload, so they degrade to the original delete-inserted-rows behaviour.
     *
     * @return int Number of ledger rows deleted.
     */
    public function rollback(int $sessionId): int
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // ── 1. Replay captured reversals (un-reconcile / reopen / delete-created) ──
            $unflag = $this->db->prepare("
                UPDATE accounting_transactions SET
                    status = 'cleared', matched_invoice_id = NULL,
                    matched_expense_id = NULL, match_confidence = NULL,
                    matched_at = NULL, matched_by = NULL, import_session_id = NULL
                WHERE id = ?
            ");
            $deleteTx = $this->db->prepare("DELETE FROM accounting_transactions WHERE id = ?");
            $reopen   = $this->db->prepare("
                UPDATE invoices SET
                    status = ?, amount_paid = ?, balance_due = ?,
                    payment_method = ?, paid_at = ?
                WHERE id = ? AND status = 'paid'
            ");

            $rows = $this->db->prepare("SELECT raw_row FROM bank_import_rows WHERE session_id = ?");
            $rows->execute([$sessionId]);
            while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
                $data   = json_decode($r['raw_row'] ?? '', true);
                $revert = is_array($data) ? ($data['_revert'] ?? null) : null;
                if (!is_array($revert)) {
                    continue;
                }
                foreach (($revert['unflag_tx'] ?? []) as $txId) {
                    $unflag->execute([(int)$txId]);
                }
                foreach (($revert['delete_tx'] ?? []) as $txId) {
                    $deleteTx->execute([(int)$txId]);
                }
                if (!empty($revert['reopen_invoice']['id'])) {
                    $pi = $revert['reopen_invoice'];
                    $reopen->execute([
                        $pi['status'], $pi['amount_paid'], $pi['balance_due'],
                        $pi['payment_method'], $pi['paid_at'], (int)$pi['id'],
                    ]);
                }
            }

            // ── 2. Delete every ledger row this session INSERTED ──────────────────
            //   (unmatched bank rows, invoice-match transfers, processing-fee expenses).
            //   Keyed on import_session_id so the fee rows — which have no
            //   bank_import_rows.transaction_id link — are also removed.
            $del = $this->db->prepare("
                DELETE FROM accounting_transactions
                WHERE import_session_id = ? AND reference_type = 'bank_import'
            ");
            $del->execute([$sessionId]);
            $deleted = $del->rowCount();

            $this->db->prepare("UPDATE bank_import_sessions SET status = 'rolled_back' WHERE id = ?")
                     ->execute([$sessionId]);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $deleted;

        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SESSION HISTORY
    // ══════════════════════════════════════════════════════════════════════════

    public function getSessions(int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, u.full_name AS imported_by
            FROM bank_import_sessions s
            LEFT JOIN users u ON u.id = s.created_by
            ORDER BY s.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSessionRows(int $sessionId): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, coa.name AS account_name, coa.code AS account_code
            FROM bank_import_rows r
            LEFT JOIN chart_of_accounts coa ON coa.id = r.account_id
            WHERE r.session_id = ?
            ORDER BY r.transaction_date ASC
        ");
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PDF IMPORT
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Parse a PDF bank statement file and return the same preview structure as preview().
     * Uses smalot/pdfparser (Composer). Falls back gracefully if not installed.
     *
     * @param string $filePath   Absolute path to the uploaded PDF file
     * @param string $bankName   Bank label
     * @param string $kind       'bank' | 'credit_card' — routes CC settlement flagging
     */
    public function previewPdf(string $filePath, string $bankName = '', string $kind = 'bank'): array
    {
        if (!defined('VENDOR_ROOT') || !is_file(VENDOR_ROOT . '/autoload.php')) {
            throw new RuntimeException('Composer autoloader not found. Run composer install in the project root.');
        }
        require_once VENDOR_ROOT . '/autoload.php';

        if (!class_exists('\Smalot\PdfParser\Parser')) {
            throw new RuntimeException('smalot/pdfparser is not installed. Run: composer require smalot/pdfparser');
        }

        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($filePath);
        $text   = $pdf->getText();

        // Some bank PDFs (e.g. Vancity) use custom font encodings without a
        // ToUnicode map. Two variants:
        //   A) Non-ASCII garbling: smalot returns high-byte characters (caught by isGarbledText).
        //   B) Space-padded ASCII: all chars are ASCII but spaces are inserted between character
        //      groups ("A UG", "7 .34") — same visual result, different encoding scheme
        //      (seen in Vancity VCTY_16310 2025 statements). Caught by isSpacePaddedText.
        // Both cases route through the OCR fallback which renders page images cleanly.
        if ($this->isGarbledText($text) || $this->isSpacePaddedText($text)) {
            $text = $this->extractTextFallback($filePath);
        }

        $parsed = $this->parsePdfText($text);
        $rows   = $parsed['rows'];
        $balMeta = $parsed['balance_meta'];

        if (empty($rows)) {
            throw new RuntimeException(
                'No transactions could be extracted from this PDF. ' .
                'The format may not be supported. Try uploading a photo or scan of the statement instead.'
            );
        }

        $result = $this->enrichRows($rows, $bankName, 'pdf', $kind);
        $result['balance_check'] = $this->computeBalanceCheck($balMeta, $result['totals']);
        $result += $this->detectAccountNumber($text);
        return $result;
    }

    /**
     * Debug variant of previewPdf() — returns raw per-page OCR text and a log of
     * every line that was rejected by the parser with the reason why.
     *
     * Only expose this via an admin-gated API endpoint.
     *
     * @return array {
     *   rows: parsed transaction rows,
     *   balance_check: balance strip data,
     *   raw_pages: { page_number: raw_ocr_text, ... },
     *   reject_log: [{ line: string, reason: string, ... }, ...],
     *   joined_lines: all lines after the wrap-join step,
     *   noise_lines_dropped: int,
     * }
     */
    public function previewPdfDebug(string $filePath, string $bankName = ''): array
    {
        if (!defined('VENDOR_ROOT') || !is_file(VENDOR_ROOT . '/autoload.php')) {
            throw new RuntimeException('Composer autoloader not found.');
        }
        require_once VENDOR_ROOT . '/autoload.php';

        if (!class_exists('\Smalot\PdfParser\Parser')) {
            throw new RuntimeException('smalot/pdfparser is not installed.');
        }

        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($filePath);
        $text   = $pdf->getText();

        if ($this->isGarbledText($text) || $this->isSpacePaddedText($text)) {
            $text = $this->extractTextFallback($filePath);
        }

        // Capture joined lines for inspection
        $joinedLines = $this->getJoinedLinesDebug($text);

        $parsed  = $this->parsePdfText($text, true);
        $rows    = $parsed['rows'];
        $balMeta = $parsed['balance_meta'];

        $result = empty($rows) ? ['rows' => [], 'totals' => ['income' => 0, 'expenses' => 0, 'net' => 0]] : $this->enrichRows($rows, $bankName, 'pdf');
        $result['balance_check']      = $this->computeBalanceCheck($balMeta, $result['totals'] ?? []);
        $result['raw_pages']          = $this->rawPageTexts;
        $result['reject_log']         = $parsed['reject_log'];
        $result['joined_lines']       = $joinedLines;
        $result['noise_lines_dropped'] = $balMeta['noise_lines_dropped'];
        return $result;
    }

    /**
     * Returns the lines array after the wrap-join step (for debug inspection).
     * Duplicates the join loop without modifying any state.
     */
    private function getJoinedLinesDebug(string $text): array
    {
        $lines = preg_split('/\r?\n|\x0c/', $text);

        $datePatterns = [
            '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+\d{1,2}',
            '\d{1,2}\s*(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?',
            '\d{1,2}[\/\-]\d{1,2}',
            '\d{4}-\d{2}-\d{2}',
            '\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}',
        ];
        $datePat      = '(' . implode('|', $datePatterns) . ')';
        $dateStartPat = '/^' . $datePat . '\s+/i';
        $amtPat       = '([\-\+]?\$?\s*[\d,]+\s*\.\d{2})';
        $pattern      = '/^' . $datePat . '\s+(.+?)\s+' . $amtPat . '(?:\s+' . $amtPat . ')?$/i';

        $joined = [];
        $buf    = '';
        foreach ($lines as $raw) {
            $l = trim($raw);
            if ($l === '') {
                if ($buf !== '') { $joined[] = $buf; $buf = ''; }
                continue;
            }
            if (preg_match($dateStartPat, $l)) {
                if ($buf !== '') $joined[] = $buf;
                $buf = $l;
            } elseif ($this->isNoiseLine($l)) {
                // drop
            } elseif ($buf !== '' && preg_match($pattern, $buf)) {
                // Buffer is complete — trailing line is page-boundary noise
            } else {
                $buf = $buf !== '' ? rtrim($buf) . ' ' . $l : $l;
            }
        }
        if ($buf !== '') $joined[] = $buf;
        return $joined;
    }

    /**
     * Parse an image file (JPEG/PNG/WEBP) — a photo or scan of a bank statement.
     * Sends the image to the OCR pipeline and parses the resulting text.
     *
     * @param string $filePath   Absolute path to the uploaded image
     * @param string $mimeType   e.g. 'image/jpeg', 'image/png'
     * @param string $bankName   Label for the bank account
     */
    public function previewImage(string $filePath, string $mimeType, string $bankName = '', string $kind = 'bank'): array
    {
        $imageData = file_get_contents($filePath);
        if ($imageData === false || strlen($imageData) < 100) {
            throw new RuntimeException('Could not read uploaded image file.');
        }

        $subtype = strpos($mimeType, 'png') !== false ? 'png' : 'jpeg';
        $text    = $this->ocrImageBytes($imageData, $subtype);

        $parsed  = $this->parsePdfText($text);
        $rows    = $parsed['rows'];
        $balMeta = $parsed['balance_meta'];

        if (empty($rows)) {
            throw new RuntimeException(
                'No transactions could be extracted from this image. ' .
                'Ensure the photo is well-lit, in focus, and shows the full statement page.'
            );
        }

        $result = $this->enrichRows($rows, $bankName, 'image', $kind);
        $result['balance_check'] = $this->computeBalanceCheck($balMeta, $result['totals']);
        $result += $this->detectAccountNumber($text);
        return $result;
    }

    /**
     * Extract transaction rows from raw PDF text.
     *
     * Handles two common Canadian bank PDF statement layouts:
     *
     *   Layout A — date at start of line, amount at end (TD, BMO, most banks):
     *     Jan 15  GROCERY STORE PURCHASE   123.45
     *     Jan 15  PAYROLL DEPOSIT         +1500.00
     *
     *   Layout B — debit/credit in separate columns (RBC-style):
     *     01/15  Description    100.00
     *     01/16  Description              200.00  1500.00  (last col = balance, ignored)
     *
     * Returns normalized rows identical in shape to parseCSV() output.
     */
    /**
     * Parse PDF/OCR text into transaction rows.
     * Returns ['rows' => [...], 'balance_meta' => ['opening'=>?, 'closing'=>?]].
     *
     * Layout detection:
     *   3-column (WITHDRAWALS | DEPOSITS | BALANCE — Vancity, most credit unions):
     *     Every transaction line ends with: amount  running_balance
     *     → use first amount as the transaction; discard second (it is the balance).
     *
     *   2-column (DEBIT | CREDIT — RBC, some TD):
     *     Lines have either a debit amount or a credit amount, never both.
     *     When both appear it means (debit=0, credit>0) — use the non-zero one.
     */
    private function parsePdfText(string $text, bool $debug = false): array
    {
        $rows     = [];
        $rejectLog = [];  // populated when $debug === true
        $lines    = preg_split('/\r?\n|\x0c/', $text);

        // ── Date patterns ─────────────────────────────────────────────────────
        $datePatterns = [
            '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+\d{1,2}',
            '\d{1,2}\s*(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?',
            '\d{1,2}[\/\-]\d{1,2}',
            '\d{4}-\d{2}-\d{2}',
            '\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}',
        ];
        $datePat      = '(' . implode('|', $datePatterns) . ')';
        $dateStartPat = '/^' . $datePat . '\s+/i';
        $amtPat       = '([\-\+]?\$?\s*[\d,]+\s*\.\d{2})';
        $pattern      = '/^' . $datePat . '\s+(.+?)\s+' . $amtPat . '(?:\s+' . $amtPat . ')?$/i';

        // ── Statement year ────────────────────────────────────────────────────
        $statementYear = date('Y');
        if (preg_match('/\b(20\d{2})\b/', $text, $ym)) {
            $statementYear = $ym[1];
        }

        // ── 3-column format detection (WITHDRAWALS | DEPOSITS | BALANCE) ─────
        // When true, every line with two amounts has: transaction_amount  running_balance.
        // The running balance MUST be discarded — it is not a second transaction.
        // The old "a2 > a1 * 5" heuristic fails for large transactions (e.g. $9,590
        // deposit with $20,145 balance → ratio only 2.1x, not caught).
        $is3Col = (bool)preg_match(
            '/WITHDRAWALS?\s+DEPOSITS?\s+BALANCE|DEBITS?\s+CREDITS?\s+BALANCE/i',
            $text
        );

        // ── Opening / closing balance extraction ──────────────────────────────
        $openingBalance = null;
        $closingBalance = null;

        // Strategy 1 — explicit "OPENING BALANCE" / "CLOSING BALANCE" label regex.
        // Uses .*? + /s to skip embedded dates (e.g. "BALANCE ON 31 MAR 2026").
        if (preg_match(
            '/(?:opening|previous|beginning|prior)\s+balance\b.*?(\d{1,3}(?:,\d{3})*\.\d{2})/is',
            $text, $bm
        )) {
            $openingBalance = $this->parseNumber($bm[1]);
        }
        if (preg_match(
            '/(?:closing|ending|end\s+of\s+period)\s+balance\b.*?(\d{1,3}(?:,\d{3})*\.\d{2})/is',
            $text, $bm
        )) {
            $closingBalance = $this->parseNumber($bm[1]);
        }

        // If the closing regex matched the same value as opening — this happens when
        // the statement table puts the opening column before the closing column in text
        // order (e.g. Vancity multi-column headers), so .*? finds the opening amount
        // first.  Discard and let Strategy 2 correct it.
        if ($closingBalance !== null && $openingBalance !== null &&
            abs($closingBalance - $openingBalance) < 0.02) {
            $closingBalance = null;
        }

        // Strategy 2 — Account-summary math: find 4 consecutive decimal amounts in the
        // first 8 KB of text where n1 − n2 + n3 ≈ n4 (opening − withdrawals + deposits
        // = closing).  Works for Vancity and any bank whose table header splits "CLOSING"
        // and "BALANCE" across separate lines, defeating the label regex.
        if ($openingBalance === null || $closingBalance === null) {
            $summaryWindow = substr($text, 0, 8000);
            $summaryNums   = [];
            if (preg_match_all('/(\d{1,3}(?:,\d{3})*\.\d{2})/', $summaryWindow, $sm)) {
                foreach ($sm[1] as $n) {
                    $summaryNums[] = $this->parseNumber($n);
                }
            }
            for ($i = 0; $i <= count($summaryNums) - 4; $i++) {
                $n1 = $summaryNums[$i];       // opening
                $n2 = $summaryNums[$i + 1];   // total withdrawals
                $n3 = $summaryNums[$i + 2];   // total deposits
                $n4 = $summaryNums[$i + 3];   // closing
                if ($n1 < 50.0 || $n4 < 0.01) continue;
                if (abs(round($n1 - $n2 + $n3, 2) - $n4) < 0.02) {
                    if ($openingBalance === null) $openingBalance = $n1;
                    if ($closingBalance === null) $closingBalance = $n4;
                    break;
                }
            }
        }

        // ── Join wrapped lines ────────────────────────────────────────────────
        // Non-date lines are appended to the current transaction buffer so that
        // multi-line descriptions stay together.  Exception: page header/footer
        // noise (serial numbers, "continued on next page", column headers) must
        // be dropped — if joined, they corrupt the last transaction on each page
        // and cause the $ -anchored regex to fail, silently losing the row.
        $joined           = [];
        $buf              = '';
        $noiseLinesDropped = 0;
        foreach ($lines as $raw) {
            $l = trim($raw);
            if ($l === '') {
                if ($buf !== '') { $joined[] = $buf; $buf = ''; }
                continue;
            }
            if (preg_match($dateStartPat, $l)) {
                if ($buf !== '') $joined[] = $buf;
                $buf = $l;
            } elseif ($this->isNoiseLine($l)) {
                // Explicit noise — serial numbers, footer phrases, column headers, etc.
                $noiseLinesDropped++;
                if ($debug) $rejectLog[] = ['line' => $l, 'reason' => 'noise'];
            } elseif ($buf !== '' && preg_match($pattern, $buf)) {
                // The buffer already contains a COMPLETE transaction (date + desc + amount).
                // Any subsequent non-date line is page-boundary continuation noise:
                // reference codes, addresses, subsidiary account headers, etc.
                // Dropping it is safe — the transaction data is already captured.
                $noiseLinesDropped++;
                if ($debug) $rejectLog[] = ['line' => $l, 'reason' => 'noise_after_complete_tx'];
            } else {
                $buf = $buf !== '' ? rtrim($buf) . ' ' . $l : $l;
            }
        }
        if ($buf !== '') $joined[] = $buf;
        $lines = $joined;

        // Track running balance column values (a2) to derive opening balance if
        // no explicit label was found.
        // Also track previous balance for 3-col income/expense type detection.
        $runningBalances    = [];
        $prevRunningBalance = $openingBalance;

        // ── Parse transaction lines ───────────────────────────────────────────
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) < 10) {
                if ($debug && $line !== '') $rejectLog[] = ['line' => $line, 'reason' => 'too_short'];
                continue;
            }
            if (!preg_match($pattern, $line, $m)) {
                if ($debug) $rejectLog[] = ['line' => $line, 'reason' => 'no_regex_match'];
                continue;
            }

            $dateRaw = trim($m[1]);
            $desc    = trim($m[2]);
            $amt1Raw = trim($m[3]);
            $amt2Raw = isset($m[4]) ? trim($m[4]) : '';

            // Skip balance-forward / summary rows.
            // Match only lines whose description IS a balance indicator, not ones that
            // merely contain the word — e.g. "BALANCE PROTECTION INSURANCE" must not drop.
            if (preg_match(
                '/^(balance\s+(brought|carried|forward)|opening\s+balance|closing\s+balance'
                . '|brought\s+forward|carried\s+forward|subtotal\b'
                . '|total\s+deposits?|total\s+withdrawals?|total\s+debits?|total\s+credits?)/i',
                $desc
            )) {
                if ($debug) $rejectLog[] = ['line' => $line, 'reason' => 'balance_summary_skip', 'desc' => $desc];
                continue;
            }
            if (strlen($desc) < 3) {
                if ($debug) $rejectLog[] = ['line' => $line, 'reason' => 'desc_too_short', 'desc' => $desc];
                continue;
            }

            // Parse date
            $dateStr = $dateRaw;
            if (!preg_match('/\d{4}/', $dateRaw)) {
                $dateStr = $dateRaw . ' ' . $statementYear;
            }
            $date = $this->parseDate($dateStr);
            if (!$date) {
                if ($debug) $rejectLog[] = ['line' => $line, 'reason' => 'bad_date', 'raw_date' => $dateStr];
                continue;
            }

            // ── Determine type + amount ───────────────────────────────────────
            $type   = null;
            $amount = null;

            // Keywords that identify a credit/income transaction
            $isCredit = (bool)preg_match(
                '/\bCR\b|DEPOSIT|CREDIT|PAYROLL|SALARY|TRANSFER\s+IN|REFUND|PREAUTH\s+CREDIT/i',
                $desc
            );

            if ($amt2Raw !== '') {
                $a1 = $this->parseNumber($amt1Raw);
                $a2 = $this->parseNumber($amt2Raw);

                if ($is3Col) {
                    // 3-column: a1 = transaction amount, a2 = running balance.
                    // Use the running balance delta (a2 vs previous balance) as the primary
                    // income/expense signal — it's reliable even when description keywords are
                    // absent (e.g. a POINT OF SALE reversal that is actually a deposit).
                    if ($a1 !== null && $a1 > 0) {
                        if ($a2 !== null && $prevRunningBalance !== null) {
                            $type = ($a2 > $prevRunningBalance) ? 'income' : 'expense';
                        } else {
                            $type = $isCredit ? 'income' : 'expense';
                        }
                        $amount = $a1;
                        if ($a2 !== null) {
                            $runningBalances[]   = $a2;
                            $prevRunningBalance  = $a2;
                        }
                    } elseif ($a1 !== null && $a1 == 0 && $a2 !== null && $a2 > 0) {
                        // a1 is an explicit zero withdrawal column; a2 is the deposit amount.
                        // Regex captured only 2 amounts so no running balance is available.
                        $type   = 'income';
                        $amount = $a2;
                    }
                } else {
                    // 2-column or unknown: use ratio heuristic as fallback
                    if ($a1 !== null && $a1 > 0 && ($a2 === null || $a2 > $a1 * 3)) {
                        // a2 is likely a running balance
                        $type   = $isCredit ? 'income' : 'expense';
                        $amount = $a1;
                        if ($a2 !== null) $runningBalances[] = $a2;
                    } elseif ($a2 !== null && $a2 > 0) {
                        $type   = 'income';
                        $amount = $a2;
                    } elseif ($a1 !== null && $a1 > 0) {
                        $type   = $isCredit ? 'income' : 'expense';
                        $amount = $a1;
                    }
                }
            } else {
                $raw = $this->parseNumber($amt1Raw);
                if ($raw === null || $raw == 0) {
                    if ($debug) $rejectLog[] = ['line' => $line, 'reason' => 'zero_or_null_amount', 'amt_raw' => $amt1Raw];
                    continue;
                }
                if ($raw < 0) {
                    $type   = 'expense';
                    $amount = abs($raw);
                } elseif ($isCredit) {
                    $type   = 'income';
                    $amount = abs($raw);
                } else {
                    $type   = 'expense';
                    $amount = abs($raw);
                }
            }

            if ($type === null || $amount === null || $amount <= 0) {
                if ($debug) $rejectLog[] = ['line' => $line, 'reason' => 'no_amount_resolved', 'amt1' => $amt1Raw, 'amt2' => $amt2Raw];
                continue;
            }

            $rows[] = [
                'date'            => $date,
                'description'     => substr($desc, 0, 500),
                'amount'          => round($amount, 2),
                'type'            => $type,
                'raw_line'        => $line,
                'account_id'      => null,
                'account_name'    => null,
                'account_code'    => null,
                'rule_id'         => null,
                'auto_cat'        => false,
                'is_duplicate'    => false,
                'duplicate_tx_id' => null,
            ];
        }

        // ── Derive opening balance from running balance column if not explicit ─
        // In 3-column format: balance_after_row_1 = opening ± row_1_amount.
        if ($openingBalance === null && !empty($runningBalances) && !empty($rows)) {
            $firstBalance = $runningBalances[0];
            $firstRow     = $rows[0];
            if ($firstRow['type'] === 'income') {
                $openingBalance = round($firstBalance - $firstRow['amount'], 2);
            } else {
                $openingBalance = round($firstBalance + $firstRow['amount'], 2);
            }
        }
        if ($closingBalance === null && !empty($runningBalances)) {
            // Walk backwards and skip subsidiary-account balances (e.g. a $8.59 savings
            // account interest entry that appears after the main account's $19,203 CHARGES
            // line).  Any balance that is < 1% of the statement's peak is almost certainly
            // from a minor linked account, not the primary chequing account.
            $maxBal = max($runningBalances);
            for ($i = count($runningBalances) - 1; $i >= 0; $i--) {
                if ($runningBalances[$i] >= $maxBal * 0.01) {
                    $closingBalance = $runningBalances[$i];
                    break;
                }
            }
        }

        return [
            'rows'         => $rows,
            'reject_log'   => $rejectLog,
            'balance_meta' => [
                'opening'            => $openingBalance,
                'closing'            => $closingBalance,
                'noise_lines_dropped' => $noiseLinesDropped,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns true for lines that are page header/footer artifacts in OCR output:
     * serial numbers, page numbers, "continued" text, column headers, disclaimers.
     *
     * These lines have no date and must NOT be appended to the previous transaction
     * buffer.  If they are, the $-anchored transaction regex fails and the last
     * transaction on the page is silently dropped (the root cause of the 6-row gap
     * on the Vancity March 2026 statement).
     *
     * Safety: the all-numeric check only fires on lines that are ENTIRELY digits and
     * spaces — it will not match amounts ("1,234.56"), vendor names, or descriptions
     * that happen to contain numbers.
     */
    private function isNoiseLine(string $line): bool
    {
        $l = trim($line);
        if (strlen($l) < 3) return true;

        // Serial/account/transit numbers: all digits and spaces, no punctuation/alpha
        if (preg_match('/^\d[\d\s]{5,}$/', $l)) return true;

        // Vancity (and similar) internal tracking/reference codes that appear in
        // page-continuation headers.  Pattern: VANA + letter + digits + underscore + digits.
        // e.g. "VANAS11000_3986752_001 - 0036173 HRI- -03-01-17-- 118691"
        // These lines have no date and must never be joined to a transaction buffer.
        if (preg_match('/\bVANA[A-Z]\d{3,}[_\-]\d+\b/i', $l)) return true;

        // Page-continuation marker: "INDEPENDENT BUSINESS ACCOUNT # 100058186801 (CONT.)"
        // "(CONT.)" is the definitive signal — safe to drop unconditionally.
        if (preg_match('/\(CONT\.?\)/i', $l)) return true;

        // Subsidiary-account balance headers at the end of multi-account statements:
        // "BUSINESS INVESTMENT SAVINGS # 100058186819 (RESERVE FUNDS) OPENING BALANCE 0.69"
        // "BUSINESS JUMPSTART SAVINGS # 100058186827 (GST RESERVES) OPENING BALANCE 8.58"
        // These non-date lines contaminate the last transaction on the final page.
        // Note: balance extraction in parsePdfText() runs on the raw text *before* the
        // join loop, so dropping these here does not affect opening/closing balance detection.
        if (preg_match('/\b(opening|closing)\s+balance\b/i', $l)) return true;

        // Known footer/header boilerplate (Vancity + common Canadian banks)
        if (preg_match(
            '/\b(continued on next page|continued on following page'
            . '|page \d+ of \d+|branch transit'
            . '|account number|account summary'
            . '|please examine|please check this statement'
            . '|if no error is reported|statement date'
            . '|for the period|for period ending)\b/i',
            $l
        )) return true;

        // Column header rows: a line composed entirely of table header keywords
        if (preg_match(
            '/^(DATE|DESCRIPTION|DEBIT|CREDIT|BALANCE|WITHDRAWALS?|DEPOSITS?)'
            . '(\s+(DATE|DESCRIPTION|DEBIT|CREDIT|BALANCE|WITHDRAWALS?|DEPOSITS?))+$/i',
            $l
        )) return true;

        return false;
    }

    /**
     * Shared enrichment: categorize rows with RulesEngine, flag duplicates,
     * compute totals. Returns the final preview result array.
     */
    /**
     * Verify parsed transaction totals against the statement's opening/closing balances.
     * Returns a balance_check array included in the preview result for display in the UI.
     */
    private function computeBalanceCheck(array $balMeta, array $totals): array
    {
        $opening = $balMeta['opening'];
        $closing = $balMeta['closing'];

        if ($opening === null && $closing === null) {
            return ['available' => false];
        }

        $income  = round((float)$totals['income'],  2);
        $expense = round((float)$totals['expense'], 2);

        $computed    = $opening !== null ? round($opening + $income - $expense, 2) : null;
        $discrepancy = ($computed !== null && $closing !== null)
            ? round($computed - $closing, 2)
            : null;
        $matches     = $discrepancy !== null && abs($discrepancy) < 0.02;

        return [
            'available'          => true,
            'opening'            => $opening,
            'closing'            => $closing,
            'computed'           => $computed,
            'discrepancy'        => $discrepancy,
            'matches'            => $matches,
            'noise_lines_dropped' => (int)($balMeta['noise_lines_dropped'] ?? 0),
        ];
    }

    private function enrichRows(array $rows, string $bankName, string $source, string $kind = 'bank'): array
    {
        require_once __DIR__ . '/RulesEngine.php';
        $engine = new RulesEngine($this->db);

        $defaultIncomeId  = $this->getAccountId(self::DEFAULT_INCOME_CODE);
        $defaultExpenseId = $this->getAccountId(self::DEFAULT_EXPENSE_CODE);
        $ccPayableId      = $this->getAccountId(self::CREDIT_CARD_CODE);
        $totals = [
            'income'     => 0.0,
            'expense'    => 0.0,
            'duplicates' => 0,   // true bank duplicates (same import twice)
            'matched'    => 0,   // matched to existing expense receipts
            'rows'       => count($rows),
        ];

        foreach ($rows as &$row) {
            // ── Credit-card settlement (transfer, excluded from P&L) ───────────
            //    Catches the monthly "pay credit card" debit on a bank statement
            //    and the "payment received" credit on a CC statement.
            if ($this->applyCcSettlement($row, $ccPayableId, $defaultExpenseId)) {
                continue;
            }

            // ── Auto-categorize via rules engine ──────────────────────────────
            $ruleMatch = $engine->previewMatch($row['description'], '', $row['type']);
            if ($ruleMatch) {
                $row['account_id']   = $ruleMatch['account_id'];
                $row['account_name'] = $ruleMatch['account_name'];
                $row['account_code'] = $ruleMatch['account_code'];
                $row['rule_id']      = $ruleMatch['rule_id'];
                $row['auto_cat']     = true;
            } else {
                $id = $row['type'] === 'income' ? $defaultIncomeId : $defaultExpenseId;
                $row['account_id']   = $id;
                $row['account_name'] = $row['type'] === 'income' ? 'Other Services' : 'Miscellaneous Expenses';
                $row['account_code'] = $row['type'] === 'income' ? self::DEFAULT_INCOME_CODE : self::DEFAULT_EXPENSE_CODE;
                $row['rule_id']      = null;
                $row['auto_cat']     = false;
            }

            // ── Two-stage duplicate / match detection ─────────────────────────
            // Stage 1: same transaction already imported from a bank statement?
            $trueDupe = $this->checkTrueDuplicate($row['date'], $row['amount'], $row['type']);

            if ($trueDupe) {
                // Real duplicate — same bank import done twice
                $row['is_duplicate']    = true;
                $row['duplicate_type']  = 'true_duplicate';
                $row['duplicate_tx_id'] = $trueDupe;
                $row['match_candidate'] = false;
                $totals['duplicates']++;
            } else {
                // Stage 2: does an approved expense receipt match this bank tx?
                $expenseMatch = $this->findExpenseMatch(
                    $row['date'], $row['amount'], $row['type'], $row['description']
                );

                if ($expenseMatch) {
                    $exp = $expenseMatch['expense'];
                    $row['is_duplicate']       = false;
                    $row['duplicate_type']     = null;
                    $row['duplicate_tx_id']    = null;
                    $row['match_candidate']    = true;
                    $row['matched_expense_id'] = (int)$exp['id'];
                    $row['match_confidence']   = (int)$expenseMatch['confidence'];
                    // Enrich from the matched expense
                    $row['matched_expense'] = [
                        'id'          => (int)$exp['id'],
                        'vendor_name' => $exp['vendor_name_raw'] ?? '',
                        'expense_date'=> $exp['expense_date'] ?? '',
                        'receipt_path'=> $exp['receipt_path'] ?? null,
                    ];
                    // Override account/category/job from receipt data
                    if (!empty($exp['accounting_category'])) {
                        $catId = $this->resolveAccountFromCategory($exp['accounting_category']);
                        if ($catId) {
                            $row['account_id'] = $catId;
                            $row['auto_cat']   = true;
                        }
                    }
                    $row['gst_amount'] = (float)($exp['tax_amount'] ?? 0);
                    $row['vendor_id']  = $exp['vendor_id'] ? (int)$exp['vendor_id'] : null;
                    $row['job_id']     = $exp['job_id']    ? (int)$exp['job_id']    : null;
                    $totals['matched']++;
                } else {
                    $row['is_duplicate']    = false;
                    $row['duplicate_type']  = null;
                    $row['duplicate_tx_id'] = null;
                    $row['match_candidate'] = false;

                    // For unmatched income rows — try to match to an existing invoice.
                    // Priority order:
                    //   1. Payment processor (Stripe/PayPal/etc) — net-of-fee amount
                    //   2. Interac e-Transfer — exact amount, sender name extracted
                    if ($row['type'] === 'income') {
                        $invMatch = null;

                        if ($this->isPaymentProcessor($row['description'])) {
                            $invMatch = $this->findInvoiceMatchForProcessor(
                                $row['date'],
                                $row['amount'],
                                $row['description']
                            );
                        } elseif ($this->isETransfer($row['description'])) {
                            $invMatch = $this->findInvoiceMatchForETransfer(
                                $row['date'],
                                $row['amount'],
                                $row['description']
                            );
                        }

                        if ($invMatch) {
                            $row['match_candidate']       = true;
                            $row['matched_invoice_tx_id'] = $invMatch['tx_id'];
                            $row['matched_invoice_id']    = $invMatch['invoice_id'];
                            $row['match_confidence']      = $invMatch['confidence'];
                            $row['match_method']          = $invMatch['match_method'] ?? 'amount';
                            $row['processing_fee']        = $invMatch['processing_fee'];
                            $row['matched_invoice'] = [
                                'invoice_number'   => $invMatch['invoice_number'],
                                'client_name'      => $invMatch['client_name'],
                                'property_address' => $invMatch['property_address'],
                                'invoice_amount'   => $invMatch['invoice_amount'],
                                'payment_reference'=> $invMatch['payment_reference'] ?? '',
                            ];
                            $totals['matched']++;
                        }
                    }
                }
            }

            if ($row['type'] === 'income')  $totals['income']  += $row['amount'];
            if ($row['type'] === 'expense') $totals['expense'] += $row['amount'];
        }
        unset($row);

        return [
            'rows'   => $rows,
            'totals' => $totals,
            'bank'   => $bankName,
            'source' => $source,
        ];
    }

    /**
     * Fallback text extraction cascade for PDFs with garbled smalot output:
     *   1. pdftotext (poppler) — works if binary is on PATH
     *   2. Imagick page rendering + OCR.space API — works if Imagick+curl present
     *
     * Throws RuntimeException with a clear user-facing message if all fail.
     */
    private function extractTextFallback(string $filePath): string
    {
        // 1 — pdftotext
        try {
            return $this->extractTextViaPdftotext($filePath);
        } catch (RuntimeException $e) {
            // Not available — continue to next option
        }

        // 2 — Imagick (PDF rendering) + OCR.space
        if (class_exists('Imagick') && function_exists('curl_init')) {
            return $this->extractTextViaImagickOcr($filePath);
        }

        throw new RuntimeException(
            'This PDF uses a custom font encoding that cannot be decoded on this server. ' .
            'Please upload a photo or scan of your statement instead.'
        );
    }

    /**
     * Render each PDF page to a JPEG via Imagick (needs Ghostscript in the
     * Imagick build) then run each image through the best available OCR engine:
     * Google Cloud Vision (DOCUMENT_TEXT_DETECTION) if configured, otherwise
     * OCR.space as fallback.  Images are processed one page at a time to keep
     * memory manageable.
     */
    private function extractTextViaImagickOcr(string $filePath): string
    {
        // At 200 DPI, a letter page is ~1700×2200 px — typically 400–650 KB at
        // q70.  Higher DPI captures text close to page margins more reliably.
        $im = new \Imagick();
        $im->setResolution(200, 200);
        $im->readImage('pdf:' . $filePath);

        $numPages = $im->getNumberImages();
        if ($numPages === 0) {
            throw new RuntimeException('Imagick could not render any pages from this PDF.');
        }

        $useVision = $this->isGoogleVisionAvailable();

        $this->rawPageTexts = [];
        $allText = '';
        for ($i = 0; $i < $numPages; $i++) {
            $im->setIteratorIndex($i);
            $page = $im->getImage();
            $page->setImageFormat('jpeg');
            $page->setImageCompressionQuality(85); // higher quality for Vision accuracy
            $page->setImageBackgroundColor('white');
            $flat = $page->flattenImages();
            $jpegData = $flat->getImageBlob();
            $flat->destroy();
            $page->destroy();

            // For OCR.space: fall back to 100 DPI if still over ~700 KB.
            // Google Vision handles larger images fine; skip the downgrade for Vision.
            if (!$useVision && strlen($jpegData) > 700000) {
                $im2 = new \Imagick();
                $im2->setResolution(100, 100);
                $im2->readImage('pdf:' . $filePath . '[' . $i . ']');
                $p2 = $im2->getImage();
                $p2->setImageFormat('jpeg');
                $p2->setImageCompressionQuality(65);
                $p2->setImageBackgroundColor('white');
                $f2 = $p2->flattenImages();
                $jpegData = $f2->getImageBlob();
                $f2->destroy(); $p2->destroy(); $im2->destroy();
            }

            if ($useVision) {
                $pageText = $this->ocrPageViaGoogleVision($jpegData);
                if ($pageText === null) {
                    // Vision call failed — fall back to OCR.space for this page
                    $pageText = $this->ocrImageBytes($jpegData, 'jpeg');
                }
            } else {
                $pageText = $this->ocrImageBytes($jpegData, 'jpeg');
            }

            $this->rawPageTexts[$i + 1] = $pageText; // 1-indexed page numbers
            $allText .= $pageText . "\n";
        }
        $im->destroy();

        return $allText;
    }

    /**
     * Returns true if Google Cloud Vision is configured on this installation.
     */
    private function isGoogleVisionAvailable(): bool
    {
        return defined('GOOGLE_VISION_CREDENTIALS')
            && !empty(GOOGLE_VISION_CREDENTIALS)
            && function_exists('curl_init');
    }

    /**
     * OCR a JPEG image using Google Cloud Vision DOCUMENT_TEXT_DETECTION.
     * Writes bytes to a temp file, calls the shared ReceiptOCR service, cleans up.
     * Returns null if the call fails (caller should fall back to OCR.space).
     *
     * Uses bounding-box reconstruction instead of Vision's fullTextAnnotation.text.
     * Vision reads multi-column tables column-first (all dates, then all descriptions,
     * then all amounts), which breaks transaction regexes.  Grouping words by Y-centre
     * rebuilds the natural row-first order that the parser expects.
     */
    private function ocrPageViaGoogleVision(string $jpegData): ?string
    {
        static $visionLoaded = false;
        if (!$visionLoaded) {
            $rcptOcr = APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
            if (!file_exists($rcptOcr)) return null;
            require_once $rcptOcr;
            $visionLoaded = true;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mw_bi_') . '.jpg';
        if (file_put_contents($tmp, $jpegData) === false) return null;

        try {
            $result = extractTextFromImage($tmp);
        } catch (Throwable $e) {
            @unlink($tmp);
            return null;
        }
        @unlink($tmp);

        if (!$result['success']) return null;

        // Reconstruct row-first text from word bounding boxes.
        // Fall back to Vision's plain text only if no bounding box data is present.
        if (!empty($result['raw_response'])) {
            $reconstructed = $this->reconstructTextFromVisionBoundingBoxes($result['raw_response']);
            if ($reconstructed !== '') return $reconstructed;
        }

        return $result['text'] ?? null;
    }

    /**
     * Reconstruct page text from Google Vision DOCUMENT_TEXT_DETECTION bounding boxes.
     *
     * Vision annotates every word with pixel coordinates.  For multi-column documents
     * (bank statements), fullTextAnnotation.text is column-first — all dates, then all
     * descriptions, then all amounts — which breaks the transaction parser regex.
     *
     * This method groups words by Y-centre instead, producing row-first output:
     *   "01 MAR POINT OF SALE (SHELL) 22.50 21,897.00"
     * which matches the existing date+description+amount+balance pattern.
     *
     * @param  array  $rawResponse  Decoded JSON from the Vision annotate API
     * @return string               Reconstructed text; '' if no word data present
     */
    private function reconstructTextFromVisionBoundingBoxes(array $rawResponse): string
    {
        $pages = $rawResponse['responses'][0]['fullTextAnnotation']['pages'] ?? [];
        if (empty($pages)) return '';

        // Collect every word with its Y-centre and X-left from all blocks/paragraphs
        $words = [];
        foreach ($pages as $page) {
            foreach ($page['blocks'] ?? [] as $block) {
                foreach ($block['paragraphs'] ?? [] as $para) {
                    foreach ($para['words'] ?? [] as $word) {
                        $verts = $word['boundingBox']['vertices'] ?? [];
                        if (empty($verts)) continue;

                        $ys = [];
                        $xs = [];
                        foreach ($verts as $v) {
                            if (isset($v['y'])) $ys[] = (float)$v['y'];
                            if (isset($v['x'])) $xs[] = (float)$v['x'];
                        }
                        if (empty($ys) || empty($xs)) continue;

                        $wordText = '';
                        foreach ($word['symbols'] ?? [] as $sym) {
                            $wordText .= $sym['text'] ?? '';
                        }
                        if ($wordText === '') continue;

                        $words[] = [
                            'text' => $wordText,
                            'y'    => (min($ys) + max($ys)) / 2.0,
                            'x'    => min($xs),
                        ];
                    }
                }
            }
        }

        if (empty($words)) return '';

        // Sort top-to-bottom
        usort($words, function ($a, $b) { return $a['y'] <=> $b['y']; });

        // Threshold: ~0.7% of page height ≈ 15px at 2200px (200 DPI letter page).
        // Clamped to [8, 30] so slight DPI changes do not require code edits.
        $pageHeight   = (float)($pages[0]['height'] ?? 2200);
        $rowThreshold = (int)round($pageHeight * 0.007);
        if ($rowThreshold < 8)  $rowThreshold = 8;
        if ($rowThreshold > 30) $rowThreshold = 30;

        // Group words into rows using a sliding Y-threshold
        $rows   = [];
        $rowBuf = [];
        $rowY   = null;
        $rowN   = 0;

        foreach ($words as $w) {
            if ($rowY === null || abs($w['y'] - $rowY) > $rowThreshold) {
                if (!empty($rowBuf)) $rows[] = $rowBuf;
                $rowBuf = [$w];
                $rowY   = $w['y'];
                $rowN   = 1;
            } else {
                $rowBuf[] = $w;
                $rowN++;
                $rowY = $rowY + ($w['y'] - $rowY) / $rowN; // rolling mean
            }
        }
        if (!empty($rowBuf)) $rows[] = $rowBuf;

        // Sort words within each row left-to-right, join with spaces
        $lines = [];
        foreach ($rows as $row) {
            usort($row, function ($a, $b) { return $a['x'] <=> $b['x']; });
            $line = implode(' ', array_column($row, 'text'));
            // Vision tokenises punctuation as separate words, leaving spaces inside
            // brackets and before commas/periods.  Collapse those back.
            $line = preg_replace('/\(\s+/', '(', $line);
            $line = preg_replace('/\s+\)/', ')', $line);
            $line = preg_replace('/\s+([,\.])/', '$1', $line);
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Send raw image bytes to OCR.space and return the extracted plain text.
     * Uses OCR_SPACE_API_KEY from config if defined, otherwise the demo key.
     *
     * Docs: https://ocr.space/ocrapi
     */
    private function ocrImageBytes(string $imageData, string $subtype = 'jpeg'): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is not available — cannot call OCR API.');
        }

        $apiKey = defined('OCR_SPACE_API_KEY') ? OCR_SPACE_API_KEY : 'helloworld';
        $b64    = 'data:image/' . $subtype . ';base64,' . base64_encode($imageData);

        $ch = curl_init('https://api.ocr.space/parse/image');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => [
                'apikey'              => $apiKey,
                'base64Image'         => $b64,
                'language'            => 'eng',
                'isTable'             => 'true',
                'scale'               => 'true',
                'isOverlayRequired'   => 'false',
                'detectOrientation'   => 'true',
                'OCREngine'           => '2',
            ],
        ]);

        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            throw new RuntimeException('OCR API request failed: ' . $curlErr);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('OCR API returned an unexpected response.');
        }
        if (!empty($data['IsErroredOnProcessing'])) {
            $msg = isset($data['ErrorMessage']) ? (is_array($data['ErrorMessage']) ? implode('; ', $data['ErrorMessage']) : $data['ErrorMessage']) : 'Unknown OCR error';
            throw new RuntimeException('OCR processing failed: ' . $msg);
        }

        // Check OCR exit code for specific failure modes
        $exitCode = (int)($data['OCRExitCode'] ?? 0);
        if ($exitCode === 6) {
            throw new RuntimeException('OCR API rate limit reached. Please sign up for a free OCR.space key at ocr.space/ocrapi and add it to your config.');
        }
        if ($exitCode >= 3 && empty($data['ParsedResults'])) {
            $msg = isset($data['ErrorMessage'])
                ? (is_array($data['ErrorMessage']) ? implode('; ', $data['ErrorMessage']) : $data['ErrorMessage'])
                : ('OCR exit code ' . $exitCode);
            throw new RuntimeException('OCR processing failed: ' . $msg);
        }

        $text = '';
        foreach ($data['ParsedResults'] ?? [] as $result) {
            $text .= ($result['ParsedText'] ?? '') . "\n";
        }

        if (trim($text) === '') {
            // Include a fragment of the raw response to help diagnose unexpected failures
            $hint = substr(json_encode($data), 0, 200);
            throw new RuntimeException('OCR returned no text. API response: ' . $hint);
        }

        return $text;
    }

    /**
     * Returns true if the extracted text looks like garbled font encoding
     * rather than readable ASCII — e.g. Vancity PDFs with no ToUnicode map.
     * Threshold: >25% of printable characters are outside ASCII 0x20–0x7E.
     */
    private function isGarbledText(string $text): bool
    {
        $printable = preg_replace('/\s/', '', $text);
        if (strlen($printable) < 20) return false;
        $nonAscii = preg_match_all('/[^\x20-\x7E]/', $printable);
        return ($nonAscii / strlen($printable)) > 0.25;
    }

    /**
     * Returns true when smalot/pdfparser extracted all-ASCII text but the PDF
     * uses a custom encoding that inserts extra spaces between character groups.
     *
     * Distinguishing signal: amounts appear as "7 .34" or "25 ,947 .55" —
     * a digit followed by whitespace followed by a period/comma and more digits.
     * This never occurs in legitimate bank-statement text.
     *
     * Seen in Vancity VCTY_16310_YYYYMM01 statements (2025 and earlier format)
     * where every character group is separated by spaces. The 2026+ VCTY_11504290
     * format uses non-ASCII characters instead and is caught by isGarbledText().
     *
     * Threshold: ≥3 matches to avoid false-positives on edge-case formatting.
     */
    private function isSpacePaddedText(string $text): bool
    {
        // "7 .34" pattern — digit, whitespace, decimal point, two digits
        $spacedDecimal = preg_match_all('/\d\s+\.\s*\d{2}\b/', $text);
        // "25 ,947" pattern — digit, whitespace, comma, three digits
        $spacedComma   = preg_match_all('/\d\s+,\s*\d{3}/', $text);
        return ($spacedDecimal + $spacedComma) >= 3;
    }

    /**
     * Extract text from a PDF using the system pdftotext binary (poppler).
     * Falls back gracefully with a descriptive message if not installed.
     * Uses -layout mode to preserve column spacing for amount detection.
     */
    private function extractTextViaPdftotext(string $filePath): string
    {
        // Common install paths on macOS (Homebrew) and Linux (cPanel/Ubuntu)
        $candidates = [
            '/usr/bin/pdftotext',
            '/usr/local/bin/pdftotext',
            '/opt/homebrew/bin/pdftotext',
        ];
        $bin = null;
        foreach ($candidates as $c) {
            if (is_executable($c)) { $bin = $c; break; }
        }

        if ($bin === null && function_exists('shell_exec')) {
            // Try PATH lookup as last resort (only if shell_exec is available)
            $found = trim((string)shell_exec('which pdftotext 2>/dev/null'));
            if ($found !== '' && is_executable($found)) $bin = $found;
        }

        if ($bin === null) {
            throw new RuntimeException(
                'This PDF uses a custom font encoding that cannot be decoded automatically. ' .
                'Please export a CSV from your online banking instead, or contact support.'
            );
        }

        if (!function_exists('shell_exec')) {
            throw new RuntimeException(
                'This PDF uses a custom font encoding that requires pdftotext, ' .
                'but shell execution is disabled on this server. ' .
                'Please export a CSV from your online banking instead.'
            );
        }
        $escaped = escapeshellarg($filePath);
        $out = shell_exec("{$bin} -layout {$escaped} - 2>/dev/null");
        if ($out === null || trim($out) === '') {
            throw new RuntimeException('pdftotext failed to extract text from this PDF.');
        }
        // Populate rawPageTexts so the debug modal can show extracted text per page.
        $pages = preg_split('/\f/', $out);
        foreach ($pages as $i => $pageText) {
            $this->rawPageTexts[$i + 1] = $pageText;
        }
        return $out;
    }

    /**
     * Parse a raw CSV string into normalized row arrays.
     * Handles both debit/credit split and single-amount formats.
     */
    private function parseCSV(string $content, array $mapping, int $skipRows, string $kind = 'bank'): array
    {
        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        $lines   = explode("\n", $content);

        $isCreditCard = ($kind === 'credit_card');

        $rows = [];
        $i    = 0;
        foreach ($lines as $line) {
            if ($i++ < $skipRows) continue;
            $line = trim($line);
            if ($line === '') continue;

            // Parse CSV line (handles quoted fields with commas inside)
            $cols = str_getcsv($line, ',', '"', '');

            // ── Resolve raw debit/credit/single-amount values ────────────────────
            //   `$signed` is positive when money is OWED on the statement and
            //   negative when money is paid back / credited. For bank accounts that
            //   is income(+)/expense(−); for credit cards it is charge(+)/payment(−).
            $signed = null;
            if (isset($mapping['amount'])) {
                $raw = $this->parseNumber($cols[$mapping['amount']] ?? '');
                if ($raw === null) continue;
                // Bank single-amount: +income / −expense.
                // Credit-card single-amount (TD): +charge / −payment-or-credit.
                $signed = $isCreditCard ? $raw : $raw;
            } elseif (isset($mapping['debit']) && isset($mapping['credit'])) {
                $debit  = $this->parseNumber($cols[$mapping['debit']]  ?? '');
                $credit = $this->parseNumber($cols[$mapping['credit']] ?? '');

                if ($debit !== null && $debit > 0) {
                    // Bank: money out (expense). CC: a charge.
                    $signed = $isCreditCard ? $debit : -$debit;
                } elseif ($credit !== null && $credit > 0) {
                    // Bank: money in (income). CC: a payment / refund.
                    $signed = $isCreditCard ? -$credit : $credit;
                } else {
                    continue; // Zero row — skip
                }
            } else {
                continue;
            }

            if (abs($signed) <= 0) continue;

            // Parse date
            $dateRaw = trim($cols[$mapping['date']] ?? '');
            $date    = $this->parseDate($dateRaw);
            if (!$date) continue;

            // Description
            $desc = trim($cols[$mapping['description']] ?? '');
            if (isset($mapping['description2']) && !empty($cols[$mapping['description2']])) {
                $desc .= ' ' . trim($cols[$mapping['description2']]);
            }
            $desc = substr($desc, 0, 500);

            // ── Resolve type/amount + credit-card role ───────────────────────────
            if ($isCreditCard) {
                $row = $this->makeCreditCardRow($date, $desc, $signed, $line);
            } else {
                $type   = $signed >= 0 ? 'income' : 'expense';
                $amount = abs($signed);
                $row = [
                    'date'        => $date,
                    'description' => $desc,
                    'amount'      => round($amount, 2),
                    'type'        => $type,
                    'raw_line'    => $line,
                ];
            }

            // Common fields set by preview()/enrichRows() downstream:
            $row += [
                'account_id'  => null,
                'account_name'=> null,
                'account_code'=> null,
                'rule_id'     => null,
                'auto_cat'    => false,
                'is_duplicate'=> false,
                'duplicate_tx_id' => null,
            ];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Build a staged row for a single credit-card statement line.
     *
     * Routing (no income / invoice matching for cards):
     *   charge  (signed > 0) → expense, positive amount
     *   payment (signed < 0, description looks like a CC payment) → settlement;
     *            tagged cc_payment so applyCcSettlement() routes it to Credit Card
     *            Payable as a transfer (excluded from P&L — the bank statement's
     *            matching debit is the real cash movement).
     *   refund  (signed < 0, anything else) → expense reversal, NEGATIVE amount
     *            so it nets against the original charge in expense totals/reports.
     *
     * @param float $signed  + = charge owed, − = payment/credit on the card
     */
    private function makeCreditCardRow(string $date, string $desc, float $signed, string $line): array
    {
        $mag = round(abs($signed), 2);

        if ($signed > 0) {
            // Purchase / charge — a business expense.
            return [
                'date'        => $date,
                'description' => $desc,
                'amount'      => $mag,
                'type'        => 'expense',
                'cc_role'     => 'charge',
                'raw_line'    => $line,
            ];
        }

        if ($this->isCreditCardPayment($desc)) {
            // "Payment received — thank you" — settlement, flagged for applyCcSettlement().
            return [
                'date'        => $date,
                'description' => $desc,
                'amount'      => $mag,
                'type'        => 'expense',     // staging-safe; promoted to transfer downstream
                'cc_role'     => 'payment',
                'cc_payment'  => true,
                'raw_line'    => $line,
            ];
        }

        // Merchant refund / statement credit — reverses an expense (negative amount).
        return [
            'date'        => $date,
            'description' => $desc,
            'amount'      => -$mag,
            'type'        => 'expense',
            'cc_role'     => 'refund',
            'raw_line'    => $line,
        ];
    }

    /**
     * Parse a number string that may contain currency symbols, commas, parentheses.
     * Returns null if not a valid number.
     */
    private function parseNumber(string $s): ?float
    {
        $s = trim($s);
        if ($s === '' || $s === '-' || $s === '—') return null;

        // Handle parentheses as negative (accounting convention): (100.00) = -100.00
        $negative = (isset($s[0]) && $s[0] === '(') && (substr($s, -1) === ')');
        $s = str_replace(['(', ')'], '', $s);

        // Remove currency symbols and spaces
        $s = preg_replace('/[$,\s]/', '', $s);
        if (!is_numeric($s)) return null;

        $val = (float)$s;
        return $negative ? -$val : $val;
    }

    /**
     * Attempt to parse a date string in common Canadian bank formats.
     * Returns YYYY-MM-DD string or null.
     */
    private function parseDate(string $s): ?string
    {
        $s = trim($s);
        if (!$s) return null;

        // Try common formats
        $formats = ['Y-m-d', 'm/d/Y', 'd/m/Y', 'M d, Y', 'M. d, Y', 'd-M-y', 'Y/m/d', 'm-d-Y'];
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $s);
            if ($dt && $dt->format($fmt) === $s) {
                return $dt->format('Y-m-d');
            }
        }

        // Last resort: strtotime
        $ts = strtotime($s);
        if ($ts && $ts > 0) {
            return date('Y-m-d', $ts);
        }

        return null;
    }

    /**
     * Returns true if this description is a credit-card payment/settlement —
     * either the bank statement's "pay credit card" debit, or the CC statement's
     * "payment received — thank you" credit. These move cash between the bank
     * account and the card; they are NOT income or expense (the CC statement's
     * individual charges are the real expenses) and must not be double-counted.
     */
    private function isCreditCardPayment(string $description): bool
    {
        $d = ' ' . strtoupper($description) . ' ';

        // The card's own "payment received" / autopay lines.
        if (preg_match('/\bPAYMENT\b.*\bTHANK\s*YOU\b/', $d)) return true;
        if (preg_match('/\b(PRE-?AUTH(ORIZED)?|AUTOMATIC|AUTO)\s+PAYMENT\b/', $d)) return true;

        // A payment from the bank TO a card, in either word order
        // ("VISA PAYMENT", "PAYMENT - VANCITY VISA", "MASTERCARD PMT", "PAY CREDIT CARD").
        $card = '(VISA|MASTERCARD|MASTER\s*CARD|AMEX|AMERICAN\s+EXPRESS|CREDIT\s*CARD|CREDITCARD)';
        if (preg_match('/\b' . $card . '\b.*\b(PAYMENT|PYMT|PMT|PAY)\b/', $d)) return true;
        if (preg_match('/\b(PAYMENT|PYMT|PMT)\b.*\b' . $card . '\b/', $d)) return true;

        return false;
    }

    /**
     * Promote a credit-card settlement row to a TRANSFER against Credit Card
     * Payable so it is excluded from income/expense reporting. Returns true when
     * the row was handled (caller should skip further categorization/matching).
     *
     * Triggers for:
     *   (a) CC statement payment lines already flagged cc_payment in parseCSV, and
     *   (b) bank statement debits whose description looks like a CC payment.
     *
     * Falls back to a flagged expense (never a broken FK) if the Credit Card
     * Payable account is missing from the chart of accounts.
     */
    private function applyCcSettlement(array &$row, int $ccPayableId, int $defaultExpenseId): bool
    {
        $isSettlement = !empty($row['cc_payment'])
            || (($row['type'] ?? '') === 'expense' && $this->isCreditCardPayment($row['description'] ?? ''));
        if (!$isSettlement) {
            return false;
        }

        $row['cc_payment']      = true;
        $row['cc_note']         = 'Credit card payment — reconcile against CC statement';
        $row['amount']          = round(abs((float)$row['amount']), 2);
        $row['auto_cat']        = true;
        $row['rule_id']         = null;
        $row['is_duplicate']    = false;
        $row['duplicate_type']  = null;
        $row['duplicate_tx_id'] = null;
        $row['match_candidate'] = false;

        if ($ccPayableId > 0) {
            $row['type']         = 'transfer';
            $row['account_id']   = $ccPayableId;
            $row['account_name'] = 'Credit Card Payable';
            $row['account_code'] = self::CREDIT_CARD_CODE;
        } else {
            // No Credit Card Payable account — keep it a (flagged) expense so the
            // NOT NULL account_id FK stays valid; the note still warns the user.
            $row['type']         = 'expense';
            $row['account_id']   = $defaultExpenseId;
            $row['account_name'] = 'Miscellaneous Expenses';
            $row['account_code'] = self::DEFAULT_EXPENSE_CODE;
        }
        return true;
    }

    /**
     * Returns true if this bank description is a payment processor settlement.
     * These deposits are net-of-fee — the invoice amount is 1-6% higher than the deposit.
     */
    private function isPaymentProcessor(string $description): bool
    {
        return (bool)preg_match(
            '/\b(STRIPE|PAYPAL|PAY\s*PAL|SQUARE|MONERIS|PAYMENTECH|BRAINTREE|HELCIM|BAMBORA|PAYFIRMA)\b/i',
            $description
        );
    }

    /**
     * Returns true if this bank description is an Interac e-Transfer.
     * These deposits are full-amount — no processing fee is deducted.
     */
    private function isETransfer(string $description): bool
    {
        return (bool)preg_match(
            '/\b(INTERAC|E-TRANSFER|E\s*TRF|ETRANSFER|ETRF|INTERAC\s+E-TRF|IDP\s+PURCHASE|e-TRF)\b/i',
            $description
        );
    }

    /**
     * Extract sender name from an Interac e-transfer bank description.
     *
     * Typical formats:
     *   "INTERAC E-TRF 1234 GARY HUGHES"
     *   "INTERAC E-TRANSFER FROM GARY HUGHES REF#12345"
     *   "E-TRANSFER GARY HUGHES"
     *
     * Returns an array of name words (lowercased, filtered), or [].
     */
    private function extractETransferSenderName(string $description): array
    {
        // Strip leading processor keywords + numeric tokens
        $clean = preg_replace(
            '/\b(INTERAC|E-TRANSFER|ETRANSFER|E-TRF|ETRF|IDP\s+PURCHASE|FROM|REF#?\s*\w+|\d+)\b/i',
            ' ',
            $description
        );
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        // Words of ≥3 chars that are not pure numbers
        $words = [];
        foreach (preg_split('/\s+/', strtolower($clean)) as $w) {
            if (strlen($w) >= 3 && !is_numeric($w)) {
                $words[] = $w;
            }
        }
        return $words;
    }

    /**
     * Match an Interac e-Transfer deposit to an unreconciled invoice.
     *
     * Confidence scoring (max 100):
     *   Exact amount match (±$0.01): 50 pts  — required
     *   Same day: 20 | ±1 day: 12 | ±2 days: 6 | ±3 days: 2
     *   Sender name word overlaps client/contact name: +25 pts
     *
     * Requires ≥60 pts to return a result (amount + date match, no name needed;
     * or amount + partial name if the date is older).
     */
    private function findInvoiceMatchForETransfer(
        string $date,
        float  $bankAmount,
        string $description
    ): ?array {

        $stmt = $this->db->prepare("
            SELECT
                t.id           AS tx_id,
                t.reference_id AS invoice_id,
                t.amount       AS invoice_amount,
                t.transaction_date,
                inv.invoice_number,
                inv.payment_reference,
                COALESCE(
                    CONCAT(c.first_name, ' ', c.last_name),
                    co.company_name,
                    ip.property_name,
                    ip.address
                ) AS client_name,
                ip.address AS property_address,
                ABS(DATEDIFF(t.transaction_date, ?)) AS day_diff
            FROM accounting_transactions t
            JOIN invoices inv ON inv.id = t.reference_id
            LEFT JOIN properties ip  ON ip.id  = inv.property_id
            LEFT JOIN contacts   c   ON c.id   = inv.contact_id
            LEFT JOIN companies  co  ON co.id  = inv.company_id
            WHERE t.reference_type = 'invoice'
              AND t.type = 'income'
              AND t.status != 'reconciled'
              AND ABS(t.amount - ?) < 0.02
              AND t.transaction_date BETWEEN
                  DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)
            ORDER BY day_diff ASC, t.id DESC
            LIMIT 5
        ");
        $stmt->execute([$date, $bankAmount, $date, $date]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($candidates)) return null;

        $senderWords = $this->extractETransferSenderName($description);

        $best      = null;
        $bestScore = 0;

        foreach ($candidates as $c) {
            $score   = 50; // amount matched
            $dayDiff = (int)$c['day_diff'];
            $score  += $dayDiff === 0 ? 20 : ($dayDiff === 1 ? 12 : ($dayDiff === 2 ? 6 : 2));

            // Sender name overlap with client name
            if (!empty($senderWords) && !empty($c['client_name'])) {
                $clientLower = strtolower($c['client_name']);
                foreach ($senderWords as $word) {
                    if (strpos($clientLower, $word) !== false) {
                        $score += 25;
                        break;
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }

        // Require at least amount + date (score ≥ 60)
        if (!$best || $bestScore < 60) return null;

        return [
            'tx_id'            => (int)$best['tx_id'],
            'invoice_id'       => (int)$best['invoice_id'],
            'invoice_number'   => $best['invoice_number']   ?? '',
            'client_name'      => $best['client_name']      ?? '',
            'property_address' => $best['property_address'] ?? '',
            'invoice_amount'   => (float)$best['invoice_amount'],
            'processing_fee'   => 0.0,  // e-transfers have no fee
            'payment_reference'=> $best['payment_reference'] ?? '',
            'confidence'       => min($bestScore, 100),
            'match_method'     => 'etransfer',
        ];
    }

    /**
     * Match a payment processor bank deposit to an unreconciled invoice.
     *
     * Two-stage matching:
     *   1. Reference match — if the bank description contains a pi_/ch_ Stripe ID
     *      that is stored on an invoice, it's a deterministic 100% confidence match.
     *   2. Amount range match — invoice amount in [bankAmount, bankAmount × 1.065]
     *      within a 7-day lookback window (processor fee is at most ~6.5%).
     *
     * @param string $bankDescription  Raw bank description (e.g. "STRIPE TRANSFER")
     */
    private function findInvoiceMatchForProcessor(
        string $date,
        float  $bankAmount,
        string $bankDescription = ''
    ): ?array {

        // ── Stage 1: Reference-based deterministic match ──────────────────────
        // Extract any Stripe payment intent ID (pi_XXX) or charge ID (ch_XXX)
        // that might appear in the bank description.
        $refMatch = null;
        if (preg_match('/\b(pi_[A-Za-z0-9]{10,}|ch_[A-Za-z0-9]{10,})\b/', $bankDescription, $rm)) {
            $ref = $rm[1];
            $stmt = $this->db->prepare("
                SELECT
                    t.id           AS tx_id,
                    t.reference_id AS invoice_id,
                    t.amount       AS invoice_amount,
                    t.transaction_date,
                    inv.invoice_number,
                    inv.payment_reference,
                    inv.stripe_charge_id,
                    COALESCE(ico.company_name, ip.property_name, ip.address) AS client_name,
                    ip.address AS property_address
                FROM accounting_transactions t
                JOIN invoices inv ON inv.id = t.reference_id
                LEFT JOIN properties ip  ON ip.id  = inv.property_id
                LEFT JOIN companies  ico ON ico.id = inv.company_id
                WHERE t.reference_type = 'invoice'
                  AND t.type = 'income'
                  AND t.status != 'reconciled'
                  AND (inv.stripe_payment_intent_id = ? OR inv.stripe_charge_id = ?)
                LIMIT 1
            ");
            $stmt->execute([$ref, $ref]);
            $refMatch = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($refMatch) {
            return [
                'tx_id'            => (int)$refMatch['tx_id'],
                'invoice_id'       => (int)$refMatch['invoice_id'],
                'invoice_number'   => $refMatch['invoice_number']   ?? '',
                'client_name'      => $refMatch['client_name']      ?? '',
                'property_address' => $refMatch['property_address'] ?? '',
                'invoice_amount'   => (float)$refMatch['invoice_amount'],
                'processing_fee'   => round((float)$refMatch['invoice_amount'] - $bankAmount, 2),
                'payment_reference'=> $refMatch['payment_reference'] ?? $refMatch['stripe_charge_id'] ?? '',
                'confidence'       => 100,
                'match_method'     => 'reference',
            ];
        }

        // ── Stage 2: Amount-range match ───────────────────────────────────────
        $maxGross = round($bankAmount * 1.065, 2);

        $stmt = $this->db->prepare("
            SELECT
                t.id           AS tx_id,
                t.reference_id AS invoice_id,
                t.amount       AS invoice_amount,
                t.transaction_date,
                inv.invoice_number,
                inv.payment_reference,
                inv.stripe_charge_id,
                COALESCE(ico.company_name, ip.property_name, ip.address) AS client_name,
                ip.address AS property_address,
                ABS(DATEDIFF(t.transaction_date, ?)) AS day_diff
            FROM accounting_transactions t
            JOIN invoices inv ON inv.id = t.reference_id
            LEFT JOIN properties ip  ON ip.id  = inv.property_id
            LEFT JOIN companies  ico ON ico.id = inv.company_id
            WHERE t.reference_type = 'invoice'
              AND t.type = 'income'
              AND t.status != 'reconciled'
              AND t.amount >= ?
              AND t.amount <= ?
              AND t.transaction_date BETWEEN
                  DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY day_diff ASC, t.id DESC
            LIMIT 1
        ");
        $stmt->execute([$date, $bankAmount, $maxGross, $date, $date]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) return null;

        return [
            'tx_id'            => (int)$match['tx_id'],
            'invoice_id'       => (int)$match['invoice_id'],
            'invoice_number'   => $match['invoice_number']   ?? '',
            'client_name'      => $match['client_name']      ?? '',
            'property_address' => $match['property_address'] ?? '',
            'invoice_amount'   => (float)$match['invoice_amount'],
            'processing_fee'   => round((float)$match['invoice_amount'] - $bankAmount, 2),
            'payment_reference'=> $match['payment_reference'] ?? $match['stripe_charge_id'] ?? '',
            'confidence'       => 88,
            'match_method'     => 'amount',
        ];
    }

    /**
     * Get (or create) the Payment Processing Fees expense account.
     */
    private function getProcessingFeesAccountId(): int
    {
        $stmt = $this->db->query("
            SELECT id FROM chart_of_accounts
            WHERE (code = '6850'
                   OR name LIKE '%processing fee%'
                   OR name LIKE '%payment processing%'
                   OR name LIKE '%bank charge%')
              AND type = 'expense' AND is_active = 1
            ORDER BY CASE WHEN code = '6850' THEN 0 ELSE 1 END
            LIMIT 1
        ");
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        $this->db->prepare("
            INSERT INTO chart_of_accounts
                (code, name, type, sub_type, normal_balance, is_active, display_order)
            VALUES ('6850', 'Payment Processing Fees', 'expense', 'operating', 'debit', 1, 685)
        ")->execute();
        return (int)$this->db->lastInsertId();
    }

    /**
     * Stage 1 — True duplicate: the same bank transaction was already imported.
     * Checks bank_import_rows (not all of accounting_transactions) so that
     * expense-backed transactions are NOT falsely flagged as duplicates.
     * Returns the existing transaction_id, or null.
     */
    private function checkTrueDuplicate(string $date, float $amount, string $type): ?int
    {
        $stmt = $this->db->prepare("
            SELECT r.transaction_id
            FROM bank_import_rows r
            JOIN accounting_transactions t ON t.id = r.transaction_id
            WHERE t.type = ?
              AND ABS(t.amount - ?) < 0.01
              AND ABS(DATEDIFF(t.transaction_date, ?)) <= 1
              AND t.reference_type = 'bank_import'
            LIMIT 1
        ");
        $stmt->execute([$type, $amount, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['transaction_id'] : null;
    }

    /**
     * Stage 2 — Expense match: does this bank transaction correspond to an
     * existing approved expense receipt?
     *
     * Confidence scoring (max 100):
     *   Amount match (±$0.01)  : 50 pts (always present if this query returns rows)
     *   Date proximity         : same day=20, ±1=12, ±2=6, ±3=2
     *   Vendor name overlap    : 20 pts if any 4-char word from bank description
     *                            appears in expense vendor_name_raw
     *
     * Returns ['expense' => row, 'confidence' => int] or null.
     */
    private function findExpenseMatch(string $date, float $amount, string $type, string $description): ?array
    {
        if ($type !== 'expense') return null;

        // Only find expenses not already claimed by a previous bank import
        $stmt = $this->db->prepare("
            SELECT e.id, e.expense_date, e.vendor_name_raw, e.vendor_id,
                   e.total, e.gst_amount AS tax_amount, e.job_id, e.contact_id,
                   e.accounting_category, e.receipt_media_id,
                   ma.file_path AS receipt_path,
                   ABS(DATEDIFF(e.expense_date, ?)) AS day_diff
            FROM expenses e
            LEFT JOIN media_assets ma ON ma.id = e.receipt_media_id
            LEFT JOIN accounting_transactions bt
                   ON bt.matched_expense_id = e.id
                  AND bt.reference_type = 'bank_import'
            WHERE ABS(e.total - ?) < 0.01
              AND e.expense_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY)
                                    AND DATE_ADD(?, INTERVAL 3 DAY)
              AND e.status IN ('approved','forwarded')
              AND bt.id IS NULL
            ORDER BY day_diff ASC, e.expense_date DESC
            LIMIT 5
        ");
        $stmt->execute([$date, $amount, $date, $date]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($candidates)) return null;

        // Score each candidate and pick the best
        $descWords = array_filter(
            array_map('strtolower', preg_split('/[\s\-\/]+/', $description)),
            function($w) { return strlen($w) >= 4; }
        );

        $best      = null;
        $bestScore = 0;

        foreach ($candidates as $c) {
            $dayDiff = (int)$c['day_diff'];
            $score   = 50; // base: amount matched
            $score  += $dayDiff === 0 ? 20 : ($dayDiff === 1 ? 12 : ($dayDiff === 2 ? 6 : 2));

            // Vendor name overlap
            $vendorLower = strtolower($c['vendor_name_raw'] ?? '');
            foreach ($descWords as $word) {
                if (strpos($vendorLower, $word) !== false) {
                    $score += 20;
                    break;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $c;
            }
        }

        if (!$best) return null;

        return ['expense' => $best, 'confidence' => min($bestScore, 100)];
    }

    /**
     * Resolve a COA account_id from an expense accounting_category string.
     * Uses the expense_category_alias column on chart_of_accounts.
     * Falls back to the default expense account (6900).
     */
    private function resolveAccountFromCategory(string $category): int
    {
        if (!$category) return $this->getAccountId(self::DEFAULT_EXPENSE_CODE);
        $stmt = $this->db->prepare(
            "SELECT id FROM chart_of_accounts
             WHERE expense_category_alias = ? AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$category]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : $this->getAccountId(self::DEFAULT_EXPENSE_CODE);
    }

    private function getAccountId(string $code): int
    {
        $stmt = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Scan raw statement text for a bank account number and look it up in
     * chart_of_accounts. Returns an array with:
     *   detected_account_number — normalized digits-only string, or null
     *   matched_account_id      — chart_of_accounts.id if found, or null
     *   matched_account_name    — display label if found, or null
     */
    private function detectAccountNumber(string $text): array
    {
        $result = [
            'detected_account_number' => null,
            'matched_account_id'      => null,
            'matched_account_name'    => null,
        ];

        // Pattern list — ordered from most specific to most general.
        // Each captures the raw account number (may include spaces/dashes).
        $patterns = [
            // "Account # 11504290" / "Account No. 1150 4290" / "Account: 1150-4290"
            '/\bAccount\s*(?:#|No\.?|Number)\s*:?\s*([\d][\d\s\-]{4,18}[\d])/i',
            // "ACCT # 12345678"
            '/\bACCT\s*#?\s*([\d][\d\s\-]{4,18}[\d])/i',
            // Vancity: "INDEPENDENT BUSINESS ACCOUNT # 11504290"
            '/INDEPENDENT\s+BUSINESS\s+ACCOUNT\s*#?\s*([\d][\d\s\-]{4,14}[\d])/i',
        ];

        $raw = null;
        foreach ($patterns as $pat) {
            if (preg_match($pat, $text, $m)) {
                $raw = $m[1];
                break;
            }
        }

        if ($raw === null) {
            return $result;
        }

        // Normalize: digits only
        $normalized = preg_replace('/\D/', '', $raw);
        if (strlen($normalized) < 5) {
            return $result; // Too short to be a real account number
        }

        $result['detected_account_number'] = $normalized;

        // Look for a match in chart_of_accounts (bank sub-type accounts)
        // Try exact match first, then suffix match (in case of transit+account concatenation)
        $stmt = $this->db->prepare("
            SELECT id, code, name
            FROM chart_of_accounts
            WHERE account_number IS NOT NULL
              AND account_number != ''
              AND (account_number = ? OR ? LIKE CONCAT('%', account_number))
            ORDER BY LENGTH(account_number) DESC
            LIMIT 1
        ");
        $stmt->execute([$normalized, $normalized]);
        $match = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($match) {
            $result['matched_account_id']   = (int)$match['id'];
            $result['matched_account_name'] = $match['code'] . ' – ' . $match['name'];
        }

        return $result;
    }

    private function createSession(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bank_import_sessions (filename, bank_name, account_name, bank_account_id, row_count, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['filename'],
            $data['bank_name']       ?? null,
            $data['account_name']    ?? null,
            $data['bank_account_id'] ?? null,
            $data['row_count'],
            $data['created_by'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SELF-LEARNING — description → account rule generation
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * After a successful import, write back learned categorization rules.
     *
     * For every committed (non-duplicate) row that had NO existing rule match
     * (rule_id === null) but does have an account assigned, we upsert a
     * 'learned' rule in transaction_rules keyed on the normalized description.
     *
     * Learned rules sit at priority 9000+ so they never override manual rules.
     * They are applied automatically on future imports by the existing RulesEngine,
     * and will continue to work identically when the bank API replaces PDF/OCR.
     *
     * Rows that already fired a rule (rule_id set) are skipped — the rule's
     * match_count is updated by RulesEngine when applyAll() runs.
     */
    private function learnFromCommit(array $rows, int $userId): void
    {
        // Preload all learned rules for fast duplicate detection (key → id mapping)
        $existing = $this->db->query("
            SELECT id, condition_value, account_id
            FROM transaction_rules
            WHERE source = 'learned' AND condition_field = 'description'
        ")->fetchAll(PDO::FETCH_ASSOC);

        $learnedMap = [];
        foreach ($existing as $r) {
            $learnedMap[$r['condition_value'] . '|' . $r['account_id']] = (int)$r['id'];
        }

        // Highest priority among learned rules (so new ones append after existing)
        $maxPriority = (int)$this->db->query("
            SELECT COALESCE(MAX(priority), 8999) FROM transaction_rules WHERE source = 'learned'
        ")->fetchColumn();

        foreach ($rows as $row) {
            // Skip duplicates and rows without a confirmed account
            if (!empty($row['is_duplicate']))   continue;
            if (empty($row['account_id']))       continue;
            if (!empty($row['rule_id']))         continue; // existing rule already covers this

            $desc      = trim($row['description'] ?? '');
            $accountId = (int)$row['account_id'];
            $type      = $row['type'] === 'income' ? 'income' : 'expense';

            $key = $this->normalizeDescriptionKey($desc);
            if (strlen($key) < 4) continue;

            $mapKey = $key . '|' . $accountId;

            if (isset($learnedMap[$mapKey])) {
                // Already have a rule for this key+account — increment training count
                $this->db->prepare("
                    UPDATE transaction_rules
                    SET learned_count = learned_count + 1, last_learned_at = NOW()
                    WHERE id = ?
                ")->execute([$learnedMap[$mapKey]]);
            } else {
                // New pattern — create a low-priority learned rule
                $maxPriority++;
                $this->db->prepare("
                    INSERT INTO transaction_rules
                        (name, priority, applies_to, condition_field, condition_operator,
                         condition_value, account_id, transaction_type,
                         is_active, source, learned_count, last_learned_at, created_by, created_at)
                    VALUES (?, ?, ?, 'description', 'contains', ?, ?, ?, 1, 'learned', 1, NOW(), ?, NOW())
                ")->execute([
                    'Learned: ' . mb_substr($desc, 0, 80),
                    $maxPriority,
                    $type,
                    $key,
                    $accountId,
                    $type,
                    $userId,
                ]);
                $learnedMap[$mapKey] = (int)$this->db->lastInsertId();
            }
        }
    }

    /**
     * Produce a stable, bank-agnostic key from a raw transaction description.
     *
     * Strategy:
     *  1. Remove parentheses delimiters (keep content — it has vendor names)
     *  2. Strip transaction/reference IDs (alphanumeric codes with 5+ digits)
     *  3. Strip standalone numbers (amounts, dates accidentally included)
     *  4. Strip Canadian province codes and common city names
     *  5. Discard tokens shorter than 3 chars, keep first 5 meaningful words
     *
     * Examples:
     *  "POINT OF SALE (SHELL CO1303 VANCOUVER BCCA)"  → "point of sale shell"
     *  "POINT OF SALE (STARBUCKS COFFEE 04591VANCOUVER BCCA)" → "point of sale starbucks coffee"
     *  "INTERAC e-TRF 8884523901 SMITH JOHN"         → "interac trf smith john"
     *  "CHARGES APPLIED TO ACCOUNT (PER ITEM FEES)"  → "charges applied account per item"
     *  "MONTHLY MAINTENANCE FEE"                      → "monthly maintenance fee"
     */
    private function normalizeDescriptionKey(string $desc): string
    {
        $s = strtoupper(trim($desc));

        // Replace parenthesis delimiters with spaces (preserve inner text)
        $s = str_replace(['(', ')'], ' ', $s);

        // Strip alphanumeric reference IDs — tokens containing 5+ consecutive digits
        $s = preg_replace('/\b[A-Z0-9]*\d{5,}[A-Z0-9]*\b/', ' ', $s);

        // Strip standalone numeric tokens (e.g. "16" in a street address suffix)
        $s = preg_replace('/\b\d+\b/', ' ', $s);

        // Strip Canadian province abbreviations and frequent city tokens
        $s = preg_replace(
            '/\b(BC|AB|ON|QC|MB|SK|NS|NB|NL|PEI?|YT|NT|NU|BCCA|ABCA|ONCA'
            . '|VANCOUVER|BURNABY|SURREY|VICTORIA|TORONTO|CALGARY|EDMONTON'
            . '|WINNIPEG|MONTREAL|OTTAWA|RICHMOND|LANGLEY|KELOWNA|NANAIMO)\b/',
            ' ',
            $s
        );

        // Keep only letters and spaces
        $s = preg_replace('/[^A-Z\s]/', ' ', $s);

        // Lowercase and collapse whitespace
        $s = strtolower(trim(preg_replace('/\s+/', ' ', $s)));

        // Keep the first 5 tokens of at least 3 characters each
        $words = array_values(array_filter(
            explode(' ', $s),
            function (string $w) { return strlen($w) >= 3; }
        ));

        return implode(' ', array_slice($words, 0, 5));
    }
}
