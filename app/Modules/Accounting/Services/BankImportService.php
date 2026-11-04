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

require_once __DIR__ . '/BankLineReceipts.php';

class BankImportService
{
    private PDO $db;

    /** Per-page raw OCR text captured during extractTextViaImagickOcr(). */
    private array $rawPageTexts = [];

    /**
     * One preview run's bookkeeping (reset by preview()/enrichRows()), 2026-10-13:
     *   dupUsed     — existing lines already counted as "this row is a duplicate". A file with TWO
     *                 identical charges (Lawnboy $84 x2 on Oct 7) against ONE existing line is one
     *                 duplicate and one new line, not two duplicates.
     *   expClaimed  — receipts already matched to a row in this file: one receipt, one bank charge.
     */
    private array $dupUsed = [];
    private array $expClaimed = [];

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
        // Unrecognized institution but with separate Debit/Credit columns (e.g.
        // Vancity's downloadable statement, which has no bank name in the file).
        'generic_dc' => ['name' => 'Generic (Debit/Credit)', 'kind' => 'bank', 'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'generic_cc' => ['name' => 'Generic Credit Card','kind' => 'credit_card', 'date' => 0, 'description' => 1, 'amount' => 2,        'skip' => 1],
    ];

    // Default fallback accounts when no rule matches
    private const DEFAULT_INCOME_CODE  = '4900';
    private const DEFAULT_EXPENSE_CODE = '6900';
    private const CREDIT_CARD_CODE     = '2400';   // Credit Card Payable (liability)

    /** Import date ('Y-m-d'); tests pin it. A year-less row is never dated after it. */
    private ?string $today = null;

    /** The account summary a Vancity (EBCDIC) statement prints on page 1: suffix => opening/closing. */
    private array $pdfSummary = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function today(): string
    {
        return $this->today ?? date('Y-m-d');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRESET DETECTION
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Detect bank preset from filename.
     * Returns preset key or 'generic'.
     */
    private ?InvoiceReconciliationService $reconSvc = null;

    /** Lazily built — no autoloader on production, so require the file here. */
    /**
     * What commit() does with a deposit auto-matched to an invoice's ledger row. Pure.
     *
     *  - Not an e-Transfer match (Stripe/processor — the webhook closes those) or no
     *    invoice → 'transfer_only': the deposit is a cash-clearing transfer, invoice untouched.
     *  - Payments already recorded on the invoice and not yet linked to any deposit that
     *    add up exactly to the deposit → 'link_recorded' (allocation_ids): link them; the
     *    invoice is NOT credited again.
     *  - Invoice still open (not paid/cancelled, balance > 0) → 'allocate' (amount): record
     *    the payment through InvoiceReconciliationService::applyAllocation().
     *  - Otherwise (already paid, nothing to link) → 'transfer_only'.
     *
     * @param array|null $invoice  ['status' => string, 'balance_due' => float|string]
     * @param array $unlinked      [['id' => int, 'amount' => float], ...] the invoice's
     *                             allocations with transaction_id NULL
     * @return array{action:string, allocation_ids?:int[], amount?:float}
     */
    public static function invoiceMatchPlan(string $matchMethod, ?array $invoice, float $depositAmount, array $unlinked): array
    {
        $deposit = round($depositAmount, 2);
        if ($matchMethod !== 'etransfer' || !$invoice || $deposit <= 0) {
            return ['action' => 'transfer_only'];
        }
        if ($unlinked) {
            if (!class_exists('InvoiceReconciliationService')) {
                require_once __DIR__ . '/InvoiceReconciliationService.php';
            }
            $pick = InvoiceReconciliationService::exactSubset(array_map(fn($a) => round((float)$a['amount'], 2), $unlinked), $deposit);
            if ($pick !== null) {
                return ['action' => 'link_recorded', 'allocation_ids' => array_map(fn($i) => (int)$unlinked[$i]['id'], $pick)];
            }
        }
        $status = (string)($invoice['status'] ?? '');
        if (!in_array($status, ['paid', 'cancelled'], true) && round((float)($invoice['balance_due'] ?? 0), 2) > 0.005) {
            return ['action' => 'allocate', 'amount' => $deposit];
        }
        return ['action' => 'transfer_only'];
    }

    private function reconciliationService(): InvoiceReconciliationService
    {
        if ($this->reconSvc === null) {
            require_once __DIR__ . '/InvoiceReconciliationService.php';
            $this->reconSvc = new InvoiceReconciliationService($this->db);
        }
        return $this->reconSvc;
    }

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
        // Bank signals: chequing/withdrawal/deposit columns, or a plain "debits/credits"
        // header pair (e.g. Vancity's downloadable "Date,Description,Debits,Credits,Balance"
        // export, which carries no institution name at all).
        $looksBank = (bool)preg_match('/\b(withdrawal|deposit|cheque|chequing|chequed|opening\s+balance|account\s+balance|debits|credits)\b/', $hay);

        // Separate debit/credit columns (as opposed to a single signed amount column)?
        $hasDebitCredit = (bool)preg_match('/\b(debit|withdrawal)s?\b/', $hay)
                       && (bool)preg_match('/\b(credit|deposit)s?\b/', $hay);

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

        // Institution unknown — still classify bank vs CC, and split vs single-amount,
        // so routing and column mapping are correct. Without this, an unrecognized
        // bank export with separate Debit/Credit columns fell through to the
        // 'generic' preset (a SINGLE signed amount column), which silently misreads
        // the Debits column as a positive "amount" and flips every expense to income —
        // and breaks true-duplicate detection since the row's type no longer matches
        // the type already recorded for that transaction.
        if ($looksCC && !$looksBank) {
            return 'generic_cc';
        }
        if ($looksBank && $hasDebitCredit) {
            return 'generic_dc';
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
        $this->dupUsed = []; $this->expClaimed = [];

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
            if ($this->applyCcSettlement($row, $ccPayableId, $defaultExpenseId, $kind === 'credit_card' ? 'credit_card' : 'bank')) {
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
            $trueDupe = $this->checkTrueDuplicate($row['date'], $row['amount'], $row['type'], $kind === 'credit_card' ? 'credit_card' : 'bank');
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
        int $bankAccountId = 0,
        ?array $balanceCheck = null
    ): array {

        // A bank line cannot post after the day it is imported. A future date means
        // the year was mis-inferred — refuse the batch rather than book it.
        $latestAllowed = date('Y-m-d', strtotime($this->today() . ' +1 day'));
        $future = array_values(array_filter($rows, static fn($r) => (string)($r['date'] ?? '') > $latestAllowed));
        if ($future) {
            throw new RuntimeException(sprintf(
                '%d row(s) are dated after today (first: %s "%s") — check the statement year before importing.',
                count($future), $future[0]['date'], substr((string)($future[0]['description'] ?? ''), 0, 60)
            ), 422);
        }

        $sessionId = $this->createSession([
            'filename'        => $bankName ?: 'bank_import',
            'bank_name'       => $bankName,
            'account_name'    => $accountName,
            'bank_account_id' => $bankAccountId ?: null,
            'row_count'       => count($rows),
            'created_by'      => $userId,
        ]);

        // Persist the statement reconciliation result so an import that does NOT tie
        // out to its opening/closing balance is flagged for review (not silent).
        $reconciledFlag = null;
        if ($balanceCheck !== null && !empty($balanceCheck['available'])) {
            $reconciledFlag = !empty($balanceCheck['matches']) ? 1 : 0;
            try {
                $this->db->prepare(
                    "UPDATE bank_import_sessions
                        SET balance_opening = ?, balance_closing = ?, balance_discrepancy = ?, reconciled = ?
                      WHERE id = ?"
                )->execute([
                    $balanceCheck['opening']     ?? null,
                    $balanceCheck['closing']     ?? null,
                    $balanceCheck['discrepancy'] ?? null,
                    $reconciledFlag,
                    $sessionId,
                ]);
            } catch (\Throwable $ignored) {
                // Columns may not exist yet (migration 1068 not run) — never block the import.
            }
        }

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

            $bankIds = $this->bankAccountIds();
            foreach ($rows as $row) {
                // A line printed for another account on the same statement (a Vancity savings
                // section) goes to that account — only ever a chart account of sub_type bank.
                $rowBankId = $bankAccountId;
                if (!empty($row['bank_account_id']) && in_array((int)$row['bank_account_id'], $bankIds, true)) {
                    $rowBankId = (int)$row['bank_account_id'];
                }
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

                // A receipt already joined to a statement line (earlier in this file, or an earlier
                // import) takes no second one: this row is its own charge (2026-10-13, Lawnboy $84 x2).
                if (!empty($row['match_candidate']) && !empty($row['matched_expense_id'])) {
                    $taken = $this->db->prepare("SELECT 1 FROM accounting_transactions x JOIN bank_import_rows xr ON xr.transaction_id = x.id
                                                  WHERE x.reference_type = 'expense' AND x.reference_id = ? LIMIT 1");
                    $taken->execute([(int)$row['matched_expense_id']]);
                    if ($taken->fetchColumn()) { $row['match_candidate'] = false; $row['matched_expense_id'] = null; }
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
                            $accountName, $rowBankId ?: null, $sessionId,
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
                            $accountName, $rowBankId ?: null, $sessionId, $userId,
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

                    // The statement line says where the money came from (2026-10-12).
                    $this->checkReceiptFunding($expenseId, $txId, $userId ? (int)$userId : null);

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
                    ")->execute([$accountName, $rowBankId ?: null, $sessionId, $confidence, $invTxId]);

                    // The deposit is NOT a second income row: the invoice's own ledger
                    // row already recognizes this revenue (double-count in P&L / GST
                    // otherwise). What happens to the invoice goes through the allocation
                    // ledger (InvoiceReconciliationService), never a direct UPDATE:
                    //   link_recorded — the money was already recorded on this invoice
                    //                   (Record Payment / e-Transfer inbox): link those
                    //                   payments to this deposit; the invoice is unchanged.
                    //   allocate      — an e-Transfer for an invoice still open: record the
                    //                   payment with applyAllocation() (allocation row linked
                    //                   to this deposit, invoice paid/partial, its income row,
                    //                   and on production the pipeline-stage hook).
                    //   transfer_only — Stripe/processor (closed by the webhook) or already
                    //                   paid: the deposit is a cash-clearing transfer.
                    // Either way the deposit ends as type='transfer' once fully accounted for
                    // (mirrors InvoiceReconciliationService::recomputeDepositRow()).
                    $invoiceIdForDeposit = (int)($row['matched_invoice_id'] ?? 0);
                    $matchMethod   = $row['match_method'] ?? '';
                    $depositAmount = round((float)$row['amount'], 2);
                    $priorInvoice  = null;
                    $priorInvoiceTx = null;
                    $newAllocationIds = [];

                    $invState = null;
                    $unlinked = [];
                    if ($matchMethod === 'etransfer' && $invoiceIdForDeposit) {
                        $invRow = $this->db->prepare("
                            SELECT total, amount_paid, balance_due, status, payment_method, paid_at
                            FROM invoices WHERE id = ? LIMIT 1
                        ");
                        $invRow->execute([$invoiceIdForDeposit]);
                        $invState = $invRow->fetch(PDO::FETCH_ASSOC) ?: null;
                        $ua = $this->db->prepare("
                            SELECT id, amount FROM invoice_payment_allocations
                            WHERE invoice_id = ? AND transaction_id IS NULL AND amount > 0
                            ORDER BY payment_date ASC, id ASC
                        ");
                        $ua->execute([$invoiceIdForDeposit]);
                        $unlinked = $ua->fetchAll(PDO::FETCH_ASSOC);
                    }
                    $plan = self::invoiceMatchPlan($matchMethod, $invState, $depositAmount, $unlinked);

                    $txStmt->execute([
                        $row['date'], $plan['action'] === 'transfer_only' ? 'transfer' : 'income',
                        $row['account_id'], $row['amount'],
                        0, $row['description'],
                        0, null, $accountName, $rowBankId ?: null, $sessionId, $userId,
                    ]);
                    $txId = (int)$this->db->lastInsertId();

                    if ($plan['action'] === 'link_recorded') {
                        // Deposit → transfer, linked to the payments already on the invoice.
                        $this->reconciliationService()->linkAllocationsToDeposit($txId, $plan['allocation_ids'], $userId);
                    } elseif ($plan['action'] === 'allocate') {
                        // Snapshot BEFORE recording, so rollback() restores it exactly.
                        $priorInvoice = [
                            'id'             => $invoiceIdForDeposit,
                            'status'         => $invState['status'],
                            'amount_paid'    => $invState['amount_paid'],
                            'balance_due'    => $invState['balance_due'],
                            'payment_method' => $invState['payment_method'],
                            'paid_at'        => $invState['paid_at'],
                        ];
                        $snap = $this->db->prepare("SELECT id, amount, gst_amount, transaction_date FROM accounting_transactions WHERE id = ?");
                        $snap->execute([$invTxId]);
                        $priorInvoiceTx = $snap->fetch(PDO::FETCH_ASSOC) ?: null;

                        $applied = $this->reconciliationService()->applyAllocation(
                            $invoiceIdForDeposit, $depositAmount, 'e_transfer', $payRef !== '' ? $payRef : null,
                            (string)$row['date'], $txId, $userId
                        );
                        $ids = $this->db->prepare("SELECT id FROM invoice_payment_allocations WHERE transaction_id = ?");
                        $ids->execute([$txId]);
                        $newAllocationIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));

                        // Deposit row from what was allocated: all of it → transfer; a
                        // remainder (invoice balance was smaller) stays income for that remainder.
                        // (null = the invoice was settled meanwhile: as before, just a transfer.)
                        $remaining = $applied === null ? 0.0 : round($depositAmount - (float)$applied['applied'], 2);
                        if ($remaining > 0.005) {
                            $this->db->prepare("UPDATE accounting_transactions SET amount = ? WHERE id = ?")
                                     ->execute([$remaining, $txId]);
                        } else {
                            $this->db->prepare("UPDATE accounting_transactions SET type = 'transfer' WHERE id = ?")
                                     ->execute([$txId]);
                        }
                    }

                    // Flag the deposit + link it to the invoice (audit trail). A remainder
                    // still counted as income stays 'cleared' rather than 'reconciled'.
                    $this->db->prepare("
                        UPDATE accounting_transactions SET
                          status             = CASE WHEN type = 'transfer' THEN 'reconciled' ELSE status END,
                          matched_invoice_id = ?,
                          match_confidence   = ?,
                          matched_at         = NOW(),
                          matched_by         = 'auto',
                          payment_reference  = ?
                        WHERE id = ?
                    ")->execute([$invoiceIdForDeposit ?: null, $confidence, $payRef ?: null, $txId]);

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
                            $accountName, $rowBankId ?: null, $sessionId, $userId,
                        ]);
                    }

                    // Capture what rollback() must undo for this row: un-reconcile the
                    // invoice's income ledger row; for a payment recorded here, delete its
                    // allocation and restore the invoice + its ledger row exactly. (A linked
                    // already-recorded payment unlinks itself when the deposit is deleted:
                    // the allocation FK sets transaction_id back to NULL.)
                    $row['_revert'] = ['unflag_tx' => [$invTxId]];
                    if ($priorInvoice) {
                        $row['_revert']['restore_invoice'] = $priorInvoice;
                    }
                    if ($newAllocationIds) {
                        $row['_revert']['delete_allocations'] = $newAllocationIds;
                    }
                    if ($priorInvoiceTx) {
                        $row['_revert']['restore_tx'] = $priorInvoiceTx;
                    }
                    $row['invoice_match_action'] = $plan['action'];
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
                    $accountName, $rowBankId ?: null, $sessionId, $userId,
                ]);
                $txId = (int)$this->db->lastInsertId();

                // An e-Transfer deposit whose money was already recorded against
                // invoices (Record Payment / e-Transfer inbox) — link those payments
                // to this deposit now so it isn't booked as a second income and never
                // shows up as a "likely match". Rolling the session back deletes the
                // tx, and the FK sets the allocations' transaction_id back to NULL.
                $linkedRecorded = null;
                if ($row['type'] === 'income' && $this->isETransfer((string)$row['description'])) {
                    // A savepoint (Codex review 2026-10-14): the link writes the allocations, then
                    // turns the deposit into a transfer. A failure between the two left the payment
                    // linked and the deposit still income — counted twice. Undo the half-step.
                    $sp = $this->db->inTransaction() && $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
                    if ($sp) $this->db->exec('SAVEPOINT mw_link_recorded');
                    try {
                        $linkedRecorded = $this->reconciliationService()->linkRecordedPaymentsToDeposit($txId, $userId);
                        if ($sp) $this->db->exec('RELEASE SAVEPOINT mw_link_recorded');
                    } catch (\Throwable $e) {
                        if ($sp) { try { $this->db->exec('ROLLBACK TO SAVEPOINT mw_link_recorded'); } catch (\Throwable $e2) { /* outer rollback covers it */ } }
                        error_log('[BankImportService] link recorded payments tx ' . $txId . ': ' . $e->getMessage());
                        $linkedRecorded = null;
                    }
                }
                if ($linkedRecorded) {
                    $row['_revert']           = ['delete_tx' => [$txId]];
                    $row['linked_recorded']   = $linkedRecorded;
                    $reconciled++;
                }

                $rowStmt->execute([
                    $sessionId, $row['date'], $row['description'], $rawAmount,
                    $stagedType, $row['amount'], $row['account_id'],
                    $txId, 0, null,
                    $row['rule_id'] ?? null, json_encode($row),
                    $linkedRecorded ? 'auto_matched' : 'unmatched', null,
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

        // An import never teaches rules: lines committed untouched taught wrong ones (Wave PYRL →
        // 2400). Only the owner's decisions count (BankRuleLearning, migration 1220, 2026-10-07).

        return [
            'session_id'        => $sessionId,
            'imported'          => $imported,
            'skipped'           => $skipped,
            'duplicates'        => $dupes,
            'reconciled'        => $reconciled,
            'statement_reconciled' => $reconciledFlag, // 1=ties, 0=discrepancy, null=no balance data
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
    public function rollback(int $sessionId, ?int $userId = null): int
    {
        $ownTransaction = !$this->db->inTransaction();
        // Audit trail (Fable audit #25, 2026-10-12): every invoice this undo touches, before → after.
        $audit = [];
        $invSnap = $this->db->prepare("SELECT invoice_number, status, amount_paid, balance_due, payment_method, paid_at FROM invoices WHERE id = ?");
        $snap = function (int $id) use ($invSnap): ?array {
            $invSnap->execute([$id]);
            return $invSnap->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $allocSnap = $this->db->prepare("SELECT a.invoice_id, a.amount, i.invoice_number FROM invoice_payment_allocations a LEFT JOIN invoices i ON i.id = a.invoice_id WHERE a.id = ?");
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
                    // Sessions committed before 2026-10-07 (invoice closed directly).
                    $pi = $revert['reopen_invoice'];
                    $before = $snap((int)$pi['id']);
                    if ($before && $before['status'] === 'paid') $audit[] = ['invoice', (int)$pi['id'], $before, $pi];
                    $reopen->execute([
                        $pi['status'], $pi['amount_paid'], $pi['balance_due'],
                        $pi['payment_method'], $pi['paid_at'], (int)$pi['id'],
                    ]);
                }
                // A payment recorded through the allocation ledger: remove the allocation,
                // then put the invoice and its income ledger row back as they were.
                foreach (($revert['delete_allocations'] ?? []) as $allocId) {
                    $allocSnap->execute([(int)$allocId]);
                    if ($a = $allocSnap->fetch(PDO::FETCH_ASSOC)) $audit[] = ['allocation', (int)$a['invoice_id'], $a, null];
                    $this->db->prepare("DELETE FROM invoice_payment_allocations WHERE id = ?")->execute([(int)$allocId]);
                }
                if (!empty($revert['restore_invoice']['id'])) {
                    $pi = $revert['restore_invoice'];
                    if ($before = $snap((int)$pi['id'])) $audit[] = ['invoice', (int)$pi['id'], $before, $pi];
                    $this->db->prepare("
                        UPDATE invoices SET status = ?, amount_paid = ?, balance_due = ?, payment_method = ?, paid_at = ?
                        WHERE id = ?
                    ")->execute([
                        $pi['status'], $pi['amount_paid'], $pi['balance_due'],
                        $pi['payment_method'], $pi['paid_at'], (int)$pi['id'],
                    ]);
                }
                if (!empty($revert['restore_tx']['id'])) {
                    $pt = $revert['restore_tx'];
                    $this->db->prepare("UPDATE accounting_transactions SET amount = ?, gst_amount = ?, transaction_date = ? WHERE id = ?")
                             ->execute([$pt['amount'], $pt['gst_amount'], $pt['transaction_date'], (int)$pt['id']]);
                }
            }

            // ── 2. Delete every ledger row this session INSERTED ──────────────────
            //   (unmatched bank rows, invoice-match transfers, processing-fee expenses).
            //   Keyed on import_session_id so the fee rows — which have no
            //   bank_import_rows.transaction_id link — are also removed.
            //   Their journal entries are reversed first, in this transaction (2026-10-13):
            //   they used to stay live until someone approved the orphan proposal.
            $ids = $this->db->prepare("SELECT id FROM accounting_transactions WHERE import_session_id = ? AND reference_type = 'bank_import'");
            $ids->execute([$sessionId]);
            $this->reverseJournalForLines(array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN)), $userId, 'import session ' . $sessionId . ' undone');
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
            foreach (self::rollbackAuditLines($sessionId, $deleted, $audit) as [$invoiceId, $line]) {
                if (function_exists('logActivityExtended')) {
                    logActivityExtended($userId, $invoiceId ? 'Payment unapplied (bank import undone)' : 'Bank import rolled back',
                        $line, null, null, null, $invoiceId);
                }
            }
            return $deleted;

        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pure: the audit lines for one rollback — one per invoice touched (before → after), then
     * a summary line. @return array<int, array{0: ?int, 1: string}>  [invoice_id or null, details]
     */
    public static function rollbackAuditLines(int $sessionId, int $deleted, array $audit): array
    {
        require_once dirname(__DIR__, 2) . '/Technology/Services/AuditDiff.php';
        $out = [];
        foreach ($audit as [$kind, $invoiceId, $before, $after]) {
            if ($kind === 'allocation') {
                $out[] = [$invoiceId, 'Bank import session #' . $sessionId . ' undone: payment of $' . number_format((float)$before['amount'], 2)
                    . ' removed from invoice ' . ((string)($before['invoice_number'] ?? '') ?: '#' . $invoiceId)];
                continue;
            }
            $diff = AuditDiff::describe(AuditDiff::changes($before, $after,
                ['status', 'amount_paid', 'balance_due', 'payment_method', 'paid_at'], ['amount_paid', 'balance_due']));
            if ($diff === '') continue;
            $out[] = [$invoiceId, 'Bank import session #' . $sessionId . ' undone: invoice '
                . ((string)($before['invoice_number'] ?? '') ?: '#' . $invoiceId) . ' — ' . $diff];
        }
        $out[] = [null, 'Bank import session #' . $sessionId . ' rolled back: ' . $deleted . ' ledger row(s) deleted, '
            . count(array_filter($audit, fn($a) => $a[0] === 'invoice')) . ' invoice(s) restored, '
            . count(array_filter($audit, fn($a) => $a[0] === 'allocation')) . ' payment allocation(s) removed.'];
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // COMMITTED-DUPLICATE CLEANUP
    //
    // checkTrueDuplicate() prevents new duplicates going forward, but doesn't
    // help with rows that were already committed before that fix — e.g. a
    // statement re-imported over an already-covered period. These two methods
    // find and safely remove that kind of already-committed duplicate.
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Parse a raw "Date,Description,Debits,Credits,Balance" statement export
     * (Vancity's CSV format — same shape as the regular import, but this path
     * exists purely to establish ground truth for duplicate verification, not
     * to categorize/commit transactions) into per-(date, amount) counts.
     *
     * @return array [ ['date'=>'YYYY-MM-DD', 'amount'=>float, 'count'=>int], ... ]
     */
    public function parseVerificationCsv(string $csvText): array
    {
        $months = ['Jan'=>'01','Feb'=>'02','Mar'=>'03','Apr'=>'04','May'=>'05','Jun'=>'06',
                   'Jul'=>'07','Aug'=>'08','Sep'=>'09','Oct'=>'10','Nov'=>'11','Dec'=>'12'];

        $lines  = preg_split('/\r\n|\r|\n/', trim($csvText));
        $counts = []; // "date|amount" => count

        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cols = str_getcsv($line);
            if (count($cols) < 4) continue;
            [$date, , $debit, $credit] = $cols;

            if (!preg_match('/^(\d{2})-([A-Za-z]{3})-(\d{4})$/', trim($date), $m)) continue;
            if (!isset($months[$m[2]])) continue;
            $iso = $m[3] . '-' . $months[$m[2]] . '-' . $m[1];

            $amt = trim((string) $debit) !== '' ? (float) $debit : (float) $credit;
            if ($amt <= 0) continue;

            $key = $iso . '|' . number_format($amt, 2, '.', '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $result = [];
        foreach ($counts as $key => $count) {
            [$date, $amount] = explode('|', $key);
            $result[] = ['date' => $date, 'amount' => (float) $amount, 'count' => $count];
        }
        return $result;
    }

    /**
     * Record the TRUE per-(date, amount) count from a real bank statement, as
     * confirmed by an admin uploading/pasting the actual export. Ground truth
     * for findDuplicateGroups()/removeDuplicateRow() — see migration 1113.
     *
     * @param array $counts [ ['date'=>'YYYY-MM-DD', 'amount'=>float, 'count'=>int], ... ]
     * @return int rows upserted
     */
    public function recordStatementVerification(array $counts, int $userId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bank_statement_verifications (transaction_date, amount, real_count, verified_by, verified_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE real_count = VALUES(real_count), verified_by = VALUES(verified_by), verified_at = NOW()
        ");
        $n = 0;
        foreach ($counts as $c) {
            $date = $c['date'] ?? null;
            $amt  = isset($c['amount']) ? round((float) $c['amount'], 2) : null;
            $cnt  = isset($c['count']) ? (int) $c['count'] : null;
            if (!$date || $amt === null || $cnt === null) continue;
            $stmt->execute([$date, $amt, $cnt, $userId]);
            $n++;
        }
        return $n;
    }

    /**
     * Find groups of committed bank_import_rows that share the same
     * (transaction_date, amount) — i.e. the same real bank line, imported
     * more than once. Grouped by (date, amount) only, not description, since
     * the same real transaction's description text varies cosmetically
     * between import batches/formats (spacing, casing) — see migration 1113.
     *
     * How many copies to KEEP depends on whether this (date, amount) has been
     * verified against a real statement (bank_statement_verifications):
     *   - Verified: keep exactly `real_count` (the oldest N rows by id) —
     *     this correctly handles a payer sending several separate real
     *     transactions of the identical amount on the same day (e.g. a
     *     property manager paying multiple units the same fee), which a
     *     naive "any duplicate date+amount is one extra copy" rule would
     *     wrongly collapse to 1.
     *   - Verified with real_count=0: no real transaction matches at all —
     *     flagged `verified_phantom`, never auto-removable (likely a parsing
     *     error, not a duplicate — needs a human to figure out what it
     *     actually is before anything is deleted).
     *   - Unverified: fall back to the original heuristic — keep 1, and only
     *     offer removal when the extra copy is already type='transfer'
     *     (reconciled elsewhere, not raw unrecognized income).
     *
     * Every candidate also requires zero invoice_payment_allocations rows and
     * no etransfer_notifications reference, verified or not.
     *
     * @return array<int, array{transaction_date:string,amount:float,description:string,verified:bool,real_count:?int,keep:array,candidates:array}>
     */
    public function findDuplicateGroups(): array
    {
        $groups = $this->db->query("
            SELECT bir.transaction_date, bir.amount, COUNT(*) AS n,
                   GROUP_CONCAT(bir.id ORDER BY bir.id) AS row_ids,
                   MIN(bir.description) AS description,
                   bsv.real_count AS verified_real_count
              FROM bank_import_rows bir
              LEFT JOIN bank_statement_verifications bsv
                     ON bsv.transaction_date = bir.transaction_date AND bsv.amount = bir.amount
             GROUP BY bir.transaction_date, bir.amount, bsv.real_count
            HAVING COUNT(*) > 1
             ORDER BY bir.transaction_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($groups)) {
            return [];
        }

        $result = [];
        foreach ($groups as $g) {
            $rowIds = array_map('intval', explode(',', $g['row_ids']));
            $rows   = $this->loadDuplicateCandidateRows($rowIds);
            if (count($rows) < 2) {
                continue; // a sibling was already removed since the GROUP BY ran
            }

            usort($rows, fn($a, $b) => $a['row_id'] <=> $b['row_id']);

            $verified   = $g['verified_real_count'] !== null;
            $realCount  = $verified ? (int) $g['verified_real_count'] : null;
            $isPhantom  = $verified && $realCount === 0;
            $keepCount  = $verified ? max(0, $realCount) : 1;

            $keepRows   = array_slice($rows, 0, max(1, $keepCount)); // always visually keep >=1 as "original"
            $candidateRows = array_slice($rows, count($keepRows));

            $candidates = [];
            foreach ($candidateRows as $r) {
                $safe = $r['allocation_count'] === 0 && $r['notification_count'] === 0;
                if ($isPhantom) {
                    $r['removable']        = false;
                    $r['verified_phantom'] = true;
                } elseif ($verified) {
                    $r['removable']        = $safe;
                    $r['verified_phantom'] = false;
                } else {
                    $r['removable']        = $safe && $r['tx_type'] === 'transfer';
                    $r['verified_phantom'] = false;
                }
                $candidates[] = $r;
            }

            $result[] = [
                'transaction_date' => $g['transaction_date'],
                'amount'           => (float) $g['amount'],
                'description'      => $g['description'],
                'verified'         => $verified,
                'real_count'       => $realCount,
                'keep'             => $keepRows[0],
                'candidates'       => $candidates,
            ];
        }

        return $result;
    }

    private function loadDuplicateCandidateRows(array $rowIds): array
    {
        if (empty($rowIds)) {
            return [];
        }
        $in = implode(',', $rowIds);
        $stmt = $this->db->query("
            SELECT bir.id AS row_id, bir.session_id, bir.transaction_id, bir.created_at,
                   at.type AS tx_type,
                   (SELECT COUNT(*) FROM invoice_payment_allocations ipa WHERE ipa.transaction_id = at.id) AS allocation_count,
                   (SELECT COUNT(*) FROM etransfer_notifications en WHERE en.bank_transaction_id = at.id) AS notification_count
              FROM bank_import_rows bir
              LEFT JOIN accounting_transactions at ON at.id = bir.transaction_id
             WHERE bir.id IN ($in)
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reverse the live journal entry of each bank line about to be deleted, inside the caller's
     * transaction (posting joins it). A failure throws, so the delete is refused rather than
     * leaving the books holding money the bank never had. @return int entries reversed
     */
    protected function reverseJournalForLines(array $txIds, ?int $userId, string $why): int
    {
        $txIds = array_values(array_filter(array_map('intval', $txIds)));
        if (!$txIds) return 0;
        // Credit kept from a payment on these lines stays the client's; it stops pointing at a line that goes.
        require_once __DIR__ . '/ClientCreditService.php';
        ClientCreditService::unlinkDeposits($this->db, $txIds);
        require_once __DIR__ . '/LedgerService.php';
        $ledger = new LedgerService($this->db);
        $n = 0;
        foreach ($txIds as $id) {
            $entryId = $ledger->findEntryIdBySource('bank_import', $id);
            if ($entryId) { $ledger->reverseEntry($entryId, $userId, $why, 'owner'); $n++; }
        }
        return $n;
    }

    /**
     * Remove one committed duplicate bank row (and its accounting_transactions
     * row) after re-verifying it's still safe — never trusts a caller-supplied
     * "this is fine" flag from an earlier read. Refuses (does not force) if
     * anything has changed since the row was surfaced as a candidate.
     *
     * Two independent grounds for safety, either is sufficient:
     *   (a) type='transfer' — already reconciled elsewhere, structurally safe
     *   (b) verified excess — bank_statement_verifications proves fewer real
     *       copies exist than are currently in the DB, and this row is not
     *       among the oldest `real_count` (the ones being kept)
     */
    public function removeDuplicateRow(int $bankImportRowId, int $userId): array
    {
        $row = $this->db->prepare("
            SELECT bir.id, bir.session_id, bir.transaction_id, bir.transaction_date, bir.amount, bir.description,
                   at.type AS tx_type
              FROM bank_import_rows bir
              LEFT JOIN accounting_transactions at ON at.id = bir.transaction_id
             WHERE bir.id = ?
        ");
        $row->execute([$bankImportRowId]);
        $row = $row->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Row not found.');
        }
        if (!$row['transaction_id']) {
            throw new RuntimeException('Row has no linked transaction — nothing to remove.');
        }

        // All rows for this (date, amount), oldest first — the true sibling
        // set regardless of description-text formatting differences.
        $siblingStmt = $this->db->prepare("
            SELECT id FROM bank_import_rows
             WHERE transaction_date = ? AND amount = ?
             ORDER BY id
        ");
        $siblingStmt->execute([$row['transaction_date'], $row['amount']]);
        $allIds = array_map('intval', $siblingStmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($allIds) < 2) {
            throw new RuntimeException('No other copy of this transaction remains — refusing to delete the only record of it.');
        }

        $verifyStmt = $this->db->prepare("
            SELECT real_count FROM bank_statement_verifications
             WHERE transaction_date = ? AND amount = ?
        ");
        $verifyStmt->execute([$row['transaction_date'], $row['amount']]);
        $realCount = $verifyStmt->fetchColumn();

        $verifiedExcess = false;
        if ($realCount !== false) {
            $realCount = (int) $realCount;
            if ($realCount <= 0) {
                throw new RuntimeException('This (date, amount) has zero matches in the verified statement — needs manual review, not auto-removal.');
            }
            $keepIds        = array_slice($allIds, 0, $realCount);
            $verifiedExcess = !in_array($bankImportRowId, $keepIds, true);
        }

        if (!$verifiedExcess && $row['tx_type'] !== 'transfer') {
            throw new RuntimeException('Refusing to remove — not verified against a real statement, and not type=transfer (may represent unrecognized real income).');
        }

        $allocCheck = $this->db->prepare("SELECT COUNT(*) FROM invoice_payment_allocations WHERE transaction_id = ?");
        $allocCheck->execute([$row['transaction_id']]);
        if ((int) $allocCheck->fetchColumn() > 0) {
            throw new RuntimeException('This transaction is referenced by an invoice payment allocation — cannot remove.');
        }

        $noteCheck = $this->db->prepare("SELECT COUNT(*) FROM etransfer_notifications WHERE bank_transaction_id = ?");
        $noteCheck->execute([$row['transaction_id']]);
        if ((int) $noteCheck->fetchColumn() > 0) {
            throw new RuntimeException('This transaction is linked from a pending e-Transfer notification — cannot remove.');
        }

        $this->db->beginTransaction();
        try {
            $this->reverseJournalForLines([(int)$row['transaction_id']], $userId, 'duplicate bank line removed');
            $this->db->prepare("DELETE FROM bank_import_rows WHERE id = ?")->execute([$bankImportRowId]);
            $this->db->prepare("DELETE FROM accounting_transactions WHERE id = ?")
                ->execute([$row['transaction_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        return [
            'removed_row_id' => $bankImportRowId,
            'removed_tx_id'  => (int) $row['transaction_id'],
            'description'    => $row['description'],
            'amount'         => (float) $row['amount'],
        ];
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
        $this->pdfSummary = [];
        if ($this->isGarbledText($text) || $this->isSpacePaddedText($text)) {
            // Native EBCDIC decode first (Vancity 2026+ font) — no poppler/OCR needed.
            $decoded = $this->decodeEbcdicStatement($text);
            $text = $decoded ?? $this->extractTextFallback($filePath);
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
        // Every account section the statement prints — including ones with no transactions — so
        // the statements check can say "in, no activity" (bank_statement_sections, migration 1360).
        $dates = array_column($rows, 'date');
        $result['statement_sections'] = self::statementSections(
            $parsed['sections'] ?? [], $this->pdfSummary,
            self::statementPeriod($text) ?? ($dates ? [min($dates), max($dates)] : null),
            $balMeta, $result['detected_account_number'] ?? null
        );
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
        // Anchor to the LATEST year attached to a date ("DD MON YYYY" / "MON DD, YYYY")
        // — i.e. the statement-period end. This ignores stray year references (a
        // print/due date) that would otherwise mis-date the whole statement. Earlier
        // rows are corrected by the year-rollover pass after parsing.
        // When a full date is present its LATEST value (the period end, or a due
        // date a few weeks after it) is the anchor: every year-less row is the most
        // recent occurrence of its month/day on or before it — order-independent, so
        // multi-account statements and Dec→Jan periods both date correctly. With only
        // bare years, fall back to the rollover pass below. Either way, no year-less
        // row is ever dated after the import date (a Dec-2025 statement imported in
        // April 2026 once landed as Dec 2026 — 122 future rows).
        $statementYear = (int)substr($this->today(), 0, 4);
        $anchorDate    = $this->statementAnchorDate($text);
        if ($anchorDate !== null) {
            $statementYear = (int)substr($anchorDate, 0, 4);
        } elseif (preg_match_all('/\b(20\d{2})\b/', $text, $ym2)) {
            $statementYear = min(max(array_map('intval', $ym2[1])), $statementYear);
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

        // Credit card statements (Vancity Visa) label the closing figure "Statement balance" with the amount
        // right after it ("Statement balance\t$5,574.08"); the label must touch the amount, so "pay your
        // statement balance in full" never matches (2026-10-15: every card import showed "closing ?").
        if ($closingBalance === null && preg_match('/statement\s+balance[ \t]*\$?[ \t]*(\d{1,3}(?:,\d{3})*\.\d{2})/i', $text, $bm)) {
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
        // Account sections (2026-10-07): a Vancity statement prints one section per account —
        //   INDEPENDENT BUSINESS ACCOUNT #100058186801 (CONT.)          chequing   → the session's account
        //   BUSINESS INVESTMENT SAVINGS #100058186819 (RESERVE FUNDS)   savings    → 1020
        //   BUSINESS JUMPSTART SAVINGS #100058186827 (GST RESERVES)     savings    → 1025
        //   … CLASS B MEMBERSHIP SHARES #100013640901                  shares     → never imported
        // each with its own OPENING BALANCE. Every transaction carries the last four digits of
        // the account it is printed under (statement_account); the section's opening balance
        // seeds its running balance. A statement with one section is parsed exactly as before.
        $joined           = [];
        $joinedSection    = [];
        $buf              = '';
        $bufSection       = '';
        $section          = '';
        $sectionOpening   = [];
        $sharesSections   = [];
        $sectionKind      = [];   // suffix => primary|savings, every non-shares header seen (even with no lines)
        $noiseLinesDropped = 0;
        foreach ($lines as $raw) {
            $l = trim($raw);
            if ($l === '') {
                if ($buf !== '') { $joined[] = $buf; $joinedSection[] = $bufSection; $buf = ''; }
                continue;
            }
            $isDateLine = (bool)preg_match($dateStartPat, $l);
            if (!$isDateLine) {
                $hdr = self::accountSectionHeader($l);
                if ($hdr !== null) {
                    $section = $hdr['suffix'];
                    if ($hdr['kind'] === 'shares') $sharesSections[$section] = true;
                    elseif (!isset($sectionKind[$section])) $sectionKind[$section] = $hdr['kind'];
                    if ($hdr['opening'] !== null && !isset($sectionOpening[$section])) $sectionOpening[$section] = $hdr['opening'];
                } elseif ($section !== '' && !isset($sectionOpening[$section])
                          && preg_match('/^OPENING\s+BALANCE\s+\$?(-?\d{1,3}(?:,\d{3})*\.\d{2})\s*$/i', $l, $ob)) {
                    $sectionOpening[$section] = (float)str_replace(',', '', $ob[1]);
                }
            }
            if ($isDateLine) {
                if ($buf !== '') { $joined[] = $buf; $joinedSection[] = $bufSection; }
                $buf = $l;
                $bufSection = $section;
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
        if ($buf !== '') { $joined[] = $buf; $joinedSection[] = $bufSection; }
        $lines = $joined;
        // The account with the most lines is the statement's own; '' = before any header.
        $bankSections = array_values(array_filter($joinedSection, static fn($x) => !isset($sharesSections[$x])));
        $multiSection = count(array_unique($joinedSection)) > 1;
        $primarySection = '';
        if ($bankSections) {
            $counts = array_count_values($bankSections);
            arsort($counts);
            $primarySection = (string)array_key_first($counts);
        }
        $lastSection = false;

        // Track running balance column values (a2) to derive opening balance if
        // no explicit label was found.
        // Also track previous balance for 3-col income/expense type detection.
        $sectionLines       = [];   // section suffix ('' = before any header) => transactions read
        $runningBalances    = [];
        $prevRunningBalance = $openingBalance;
        $primaryLastBalance = null;

        // ── Parse transaction lines ───────────────────────────────────────────
        foreach ($lines as $lineIdx => $line) {
            $line = trim($line);
            $rowSection = $joinedSection[$lineIdx] ?? '';
            if (isset($sharesSections[$rowSection])) {
                // Membership shares are not a bank account: never a transaction.
                if ($debug) $rejectLog[] = ['line' => $line, 'reason' => 'shares_section'];
                continue;
            }
            $isPrimary = !$multiSection || $rowSection === $primarySection || ($rowSection === '' && $primarySection === '');
            if ($multiSection && $rowSection !== $lastSection) {
                // A new account's lines: read their direction from THEIR balance, not the last account's.
                if ($lastSection !== false && !$isPrimary) {
                    $prevRunningBalance = $sectionOpening[$rowSection] ?? null;
                } elseif ($lastSection !== false && $isPrimary) {
                    $prevRunningBalance = $primaryLastBalance ?? $openingBalance;
                }
                $lastSection = $rowSection;
            }
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
            $dateStr      = $dateRaw;
            $yearInferred = !preg_match('/\d{4}/', $dateRaw);
            if ($yearInferred) {
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

            // Keywords that identify a credit/income transaction. "PAYROLL" means money in only as a
            // payroll deposit — "WAVE - PAYROLL FEES" on the Visa is a charge (read as income until
            // 2026-10-13).
            $isCredit = (bool)preg_match(
                '/\bCR\b|DEPOSIT|CREDIT|PAYROLL(?!\s*FEES?\b)|SALARY|TRANSFER\s+IN|REFUND|PREAUTH\s+CREDIT/i',
                $desc
            ) && !preg_match('/\bFEES?\b/i', $desc);

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
                            if ($isPrimary) { $runningBalances[] = $a2; $primaryLastBalance = $a2; }
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
                        // a2 is likely a running balance. Prefer the balance delta
                        // (reliable) over description keywords whenever we have a
                        // previous balance — this is the same signal the 3-column
                        // path uses, applied here so a missed header still classifies.
                        if ($a2 !== null && $prevRunningBalance !== null) {
                            $type = ($a2 > $prevRunningBalance) ? 'income' : 'expense';
                        } else {
                            $type = $isCredit ? 'income' : 'expense';
                        }
                        $amount = $a1;
                        if ($a2 !== null) { if ($isPrimary) { $runningBalances[] = $a2; $primaryLastBalance = $a2; } $prevRunningBalance = $a2; }
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

            $sectionLines[$rowSection] = ($sectionLines[$rowSection] ?? 0) + 1;
            $rows[] = [
                'date'            => $date,
                '_year_inferred'  => $yearInferred,
                'description'     => substr($desc, 0, 500),
                'amount'          => round($amount, 2),
                'type'            => $type,
                'raw_line'        => $line,
                'statement_account' => $multiSection && $rowSection !== '' ? $rowSection : null,
                'statement_primary' => $multiSection ? $isPrimary : null,
                'account_id'      => null,
                'account_name'    => null,
                'account_code'    => null,
                'rule_id'         => null,
                'auto_cat'        => false,
                'is_duplicate'    => false,
                'duplicate_tx_id' => null,
            ];
        }

        // ── Year assignment for year-less rows ────────────────────────────────
        // Rows that carried their own year are left alone.
        if (!empty($rows)) {
            if ($anchorDate !== null) {
                foreach ($rows as $k => $r) {
                    if (!$r['_year_inferred']) continue;
                    $rows[$k]['date'] = $this->latestOnOrBefore((int)substr($r['date'], 5, 2), (int)substr($r['date'], 8, 2), $anchorDate);
                }
            } else {
                // No full date to anchor on: transactions are chronological, so a
                // month that drops by six or more marks a Dec → Jan rollover. (A
                // smaller drop is a new account section restarting the period.)
                $inferred = array_keys(array_filter($rows, static fn($r) => $r['_year_inferred']));
                $months   = array_map(static fn($k) => (int)substr($rows[$k]['date'], 5, 2), $inferred);
                $rollovers = 0;
                for ($i = 1; $i < count($months); $i++) {
                    if ($months[$i - 1] - $months[$i] >= 6) $rollovers++;
                }
                $year  = $statementYear - $rollovers;
                $prevM = null;
                foreach ($inferred as $k) {
                    $m = (int)substr($rows[$k]['date'], 5, 2);
                    $d = (int)substr($rows[$k]['date'], 8, 2);
                    if ($prevM !== null && $prevM - $m >= 6) $year++;
                    $rows[$k]['date'] = $this->latestOnOrBefore($m, $d, sprintf('%04d-12-31', $year));
                    $prevM = $m;
                }
            }
            foreach ($rows as $k => $r) unset($rows[$k]['_year_inferred']);
        }

        // ── Derive opening balance from running balance column if not explicit ─
        // In 3-column format: balance_after_row_1 = opening ± row_1_amount.
        if ($openingBalance === null && !empty($runningBalances) && !empty($rows)) {
            $firstBalance = $runningBalances[0];
            $firstRow     = $rows[0];
            foreach ($rows as $r) {
                if (($r['statement_primary'] ?? null) !== false) { $firstRow = $r; break; }
            }
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

        // Every account section: the ones with lines and the headers printed with none.
        $sections = [];
        foreach (array_unique(array_merge(array_keys($sectionLines), array_keys($sectionKind))) as $sfx) {
            $sfx = (string)$sfx;
            if (isset($sharesSections[$sfx])) continue;
            $sections[] = [
                'suffix'  => $sfx,
                'kind'    => $sectionKind[$sfx] ?? 'primary',
                'opening' => $sfx === '' ? $openingBalance : ($sectionOpening[$sfx] ?? null),
                'lines'   => (int)($sectionLines[$sfx] ?? 0),
            ];
        }

        return [
            'rows'         => $rows,
            'reject_log'   => $rejectLog,
            'sections'     => $sections,
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
     * The statement period "01 JAN 2026 to 31 JAN 2026" / "Jan 1, 2026 - Jan 31, 2026" → [from, to], or null. Pure.
     */
    public static function statementPeriod(string $text): ?array
    {
        $mon = '(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*\.?';
        $d1 = null; $d2 = null;
        if (preg_match('/(\d{1,2})\s*' . $mon . '\s*(20\d{2})\s*(?:to|-|–|through)\s*(\d{1,2})\s*' . $mon . '\s*(20\d{2})/i', $text, $m)) {
            $d1 = sprintf('%s %s %s', $m[1], $m[2], $m[3]); $d2 = sprintf('%s %s %s', $m[4], $m[5], $m[6]);
        } elseif (preg_match('/' . $mon . '\s*(\d{1,2}),?\s*(20\d{2})\s*(?:to|-|–|through)\s*' . $mon . '\s*(\d{1,2}),?\s*(20\d{2})/i', $text, $m)) {
            $d1 = sprintf('%s %s %s', $m[2], $m[1], $m[3]); $d2 = sprintf('%s %s %s', $m[5], $m[4], $m[6]);
        }
        if ($d1 === null) return null;
        $a = strtotime($d1); $b = strtotime($d2);
        if (!$a || !$b || $b < $a || $b - $a > 100 * 86400) return null;
        return [date('Y-m-d', $a), date('Y-m-d', $b)];
    }

    /**
     * The ACCOUNT SUMMARY block on page 1 of a decoded Vancity statement: each account line
     * ("INDEPENDENT BUSINESS ACCOUNT #100058186801", "…SAVINGS # 10 00 58 18 6 81 9 (RESERVE FUNDS)")
     * followed by opening, withdrawals, deposits, closing. Kept only when the four add up. Pure.
     * @return array<string, array{kind: string, opening: float, closing: float}>
     */
    public static function vancitySummary(array $lines): array
    {
        $out = [];
        $n = count($lines);
        $in = false;
        $num = static function (string $s): ?float {
            $c = str_replace(' ', '', $s);
            return preg_match('/^-?\d{1,3}(?:,\d{3})*\.\d{2}$/', $c) ? (float)str_replace(',', '', $c) : null;
        };
        for ($i = 0; $i < $n; $i++) {
            $c = strtoupper(str_replace(' ', '', $lines[$i]));
            if (strpos($c, 'ACCOUNTSUMMARY') !== false) { $in = true; continue; }
            if (!$in) continue;
            if (strpos($c, 'TOTALNUMBEROFCHEQUES') !== false || strpos($c, 'OPENINGBALANCE') !== false) break;
            if (!preg_match('/#(\d{6,})/', $c, $m)) continue;
            $nums = [];
            for ($j = $i + 1; $j < min($n, $i + 8) && count($nums) < 4; $j++) {
                $v = $num($lines[$j]);
                if ($v !== null) { $nums[] = $v; continue; }
                if ($nums || preg_match('/#\d{6,}/', str_replace(' ', '', $lines[$j]))) break;   // another account's line
            }
            if (count($nums) < 4 || abs(round($nums[0] - $nums[1] + $nums[2], 2) - $nums[3]) > 0.015) continue;
            $kind = strpos($c, 'SHARES') !== false ? 'shares' : (strpos($c, 'SAVINGS') !== false ? 'savings' : 'primary');
            $out[substr($m[1], -4)] = ['kind' => $kind, 'opening' => $nums[0], 'closing' => $nums[3]];
        }
        return $out;
    }

    /**
     * One row per account section the statement prints — with its period, opening / closing
     * balance and how many transactions it had (0 = a quiet month, still "in"). Pure.
     * @param array $parsed   parsePdfText()['sections']: [{suffix, kind, opening, lines}] ('' = before any header)
     * @param array $summary  vancitySummary(): suffix => {kind, opening, closing}
     * @param ?array $period  [from, to]
     * @return array [{suffix, kind, period_from, period_to, opening, closing, lines}]
     */
    public static function statementSections(array $parsed, array $summary, ?array $period, array $balMeta = [], ?string $accountNumber = null): array
    {
        if (!$period) return [];
        $summary = array_filter($summary, static fn($x) => $x['kind'] !== 'shares');
        $primaries = array_keys(array_filter($summary, static fn($x) => $x['kind'] === 'primary'));
        $out = [];
        foreach ($parsed as $p) {
            $sfx = (string)$p['suffix'];
            if ($sfx === '') {
                if (count($primaries) === 1) $sfx = (string)$primaries[0];
                elseif ($accountNumber && strlen($accountNumber) >= 4) $sfx = substr($accountNumber, -4);
            }
            $sum = $sfx !== '' ? ($summary[$sfx] ?? null) : null;
            $primary = $p['kind'] === 'primary' || (string)$p['suffix'] === '';
            $out[$sfx] = [
                'suffix' => $sfx, 'kind' => $sum['kind'] ?? ($primary ? 'primary' : 'savings'),
                'opening' => $sum['opening'] ?? ($p['opening'] ?? ($primary ? ($balMeta['opening'] ?? null) : null)),
                'closing' => $sum['closing'] ?? ($primary ? ($balMeta['closing'] ?? null) : null),
                'lines' => ($out[$sfx]['lines'] ?? 0) + (int)$p['lines'],
            ];
        }
        foreach ($summary as $sfx => $sum) {
            $sfx = (string)$sfx;
            if (isset($out[$sfx])) continue;
            $out[$sfx] = ['suffix' => $sfx, 'kind' => $sum['kind'], 'opening' => $sum['opening'], 'closing' => $sum['closing'], 'lines' => 0];
        }
        $rows = [];
        foreach ($out as $x) {
            $rows[] = $x + ['period_from' => $period[0], 'period_to' => $period[1]];
        }
        return $rows;
    }

    /**
     * Record the statement's sections for a committed import (bank_statement_sections, migration
     * 1360). Each maps to a chart account by its last four digits (bank_statement_accounts'
     * bank name "Vancity ••6819", or a bank account named with them); the statement's own
     * account falls back to the account the import went to. Input comes back from the browser,
     * so every field is re-checked. Never blocks an import.
     */
    public function recordSections(int $sessionId, array $sections, int $bankAccountId = 0): int
    {
        if ($sessionId <= 0 || !$sections) return 0;
        try {
            if ($this->db->query("SHOW TABLES LIKE 'bank_statement_sections'")->rowCount() === 0) return 0;
            $bankIds = $this->bankAccountIds();
            $ins = $this->db->prepare("
                INSERT INTO bank_statement_sections
                    (session_id, account_suffix, bank_account_id, period_from, period_to, opening_balance, closing_balance, line_count, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $n = 0; $from = null; $to = null;
            foreach (array_slice(array_values($sections), 0, 10) as $x) {
                if (!is_array($x)) continue;
                $sfx = (string)($x['suffix'] ?? '');
                if ($sfx !== '' && !preg_match('/^\d{4}$/', $sfx)) continue;
                $pf = (string)($x['period_from'] ?? ''); $pt = (string)($x['period_to'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pf) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pt) || $pt < $pf) continue;
                if ((strtotime($pt) - strtotime($pf)) > 100 * 86400 || $pt > date('Y-m-d', strtotime($this->today() . ' +1 day'))) continue;
                $acct = $sfx !== '' ? $this->accountForSuffix($sfx, $bankIds) : null;
                if ($acct === null && ($x['kind'] ?? '') === 'primary' && $bankAccountId > 0) $acct = $bankAccountId;
                $num = static fn($v) => is_numeric($v) ? round((float)$v, 2) : null;
                $ins->execute([$sessionId, $sfx !== '' ? $sfx : null, $acct, $pf, $pt, $num($x['opening'] ?? null),
                               $num($x['closing'] ?? null), max(0, min(100000, (int)($x['lines'] ?? 0)))]);
                $n++;
                $from = $from === null ? $pf : min($from, $pf);
                $to = $to === null ? $pt : max($to, $pt);
            }
            if ($n) {
                // A statement-only import (every line already in) still says which period it covered.
                $this->db->prepare("UPDATE bank_import_sessions SET date_from = COALESCE(date_from, ?), date_to = COALESCE(date_to, ?) WHERE id = ?")
                         ->execute([$from, $to, $sessionId]);
            }
            return $n;
        } catch (Throwable $e) {
            error_log('bank_statement_sections: ' . $e->getMessage());
            return 0;
        }
    }

    /** The bank account whose expected-statement entry or name carries these four digits, when only one does. */
    private function accountForSuffix(string $sfx, array $bankIds): ?int
    {
        $ids = [];
        try {
            $s = $this->db->prepare("SELECT account_id FROM bank_statement_accounts WHERE bank_name_match LIKE ?");
            $s->execute(['%' . $sfx . '%']);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { /* no list yet */ }
        if (!$ids) {
            $s = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE sub_type = 'bank' AND (name LIKE ? OR account_number LIKE ?)");
            $s->execute(['%' . $sfx . '%', '%' . $sfx]);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }
        $ids = array_values(array_unique(array_filter($ids, static fn($i) => in_array($i, $bankIds, true))));
        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * An account section header on a multi-account statement (never a transaction line), or null:
     *   "INDEPENDENT BUSINESS ACCOUNT #100058186801 (CONT.)"               → 6801, primary
     *   "BUSINESS INVESTMENT SAVINGS #100058186819 (RESERVE FUNDS)"        → 6819, savings
     *   "BUSINESS JUMPSTART SAVINGS #100058186827 (GST RESERVES) OPENING BALANCE 8.58" → 6827, 8.58
     *   "SHARES … CLASS B MEMBERSHIP SHARES #100013640901 OPENING BALANCE 7.29" → 0901, shares
     * A header whose number didn't survive the text extraction still maps by its name
     * (INVESTMENT SAVINGS / RESERVE FUNDS → 6819, JUMPSTART SAVINGS / GST RESERVES → 6827). Pure.
     * @return array{suffix: string, kind: string, opening: ?float}|null kind: primary|savings|shares
     */
    public static function accountSectionHeader(string $line): ?array
    {
        $l = trim($line);
        if ($l === '' || preg_match('/^\d{1,2}\s*(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)\b/i', $l)) return null;
        $isShares = (bool)preg_match('/\bSHARES?\b/i', $l);
        $isSavings = (bool)preg_match('/\bSAVINGS\b/i', $l);
        $isAccount = (bool)preg_match('/\b(?:ACCOUNT|CHEQUING|CHECKING)\b/i', $l);
        $suffix = null;
        if (($isShares || $isSavings || $isAccount)
            && preg_match('/#\s*(\d[\d\s\-]{2,24}\d)/', $l, $m)) {
            $digits = preg_replace('/\D/', '', $m[1]);
            if (strlen($digits) >= 4) $suffix = substr($digits, -4);
        }
        if ($suffix === null) {
            if (preg_match('/MEMBERSHIP\s+SHARES/i', $l)) $suffix = 'shares';
            elseif (preg_match('/JUMPSTART\s+SAVINGS|SAVINGS.*\(\s*GST\s+RESERVES?\s*\)/i', $l)) $suffix = '6827';
            elseif (preg_match('/INVESTMENT\s+SAVINGS|SAVINGS.*\(\s*RESERVE\s+FUNDS?\s*\)/i', $l)) $suffix = '6819';
        }
        if ($suffix === null) return null;
        $opening = null;
        if (preg_match('/OPENING\s+BALANCE\s+\$?(-?\d{1,3}(?:,\d{3})*\.\d{2})/i', $l, $o)) {
            $opening = (float)str_replace(',', '', $o[1]);
        }
        return ['suffix' => $suffix, 'kind' => $isShares || $suffix === 'shares' ? 'shares' : ($isSavings ? 'savings' : 'primary'), 'opening' => $opening];
    }

    /** Chart ids of every bank account (sub_type bank) — where a statement line may be routed. */
    /**
     * The statement says whose it is (2026-10-14): when its primary section's last four digits name
     * exactly one bank account (the statements list, the chart, or — when every earlier import agrees —
     * the account past statements with those digits were filed under) and the account picked for the upload is a different one, the import
     * is refused. A session filed under the wrong account put GST Reserves lines on chequing.
     * Returns the refusal message, or null when the statement doesn't say or agrees.
     */
    public function statementAccountMismatch(array $sections, int $chosenAccountId): ?string
    {
        if ($chosenAccountId <= 0) return null;
        $bankIds = $this->bankAccountIds();
        $found = [];
        foreach (array_slice(array_values($sections), 0, 10) as $x) {
            if (!is_array($x) || ($x['kind'] ?? '') !== 'primary') continue;
            $sfx = (string)($x['suffix'] ?? '');
            if (!preg_match('/^\d{4}$/', $sfx)) continue;
            $id = $this->accountForSuffix($sfx, $bankIds) ?? $this->accountFromPastSections($sfx, $bankIds);
            if ($id !== null) $found[$id] = $sfx;
        }
        if (count($found) !== 1) return null;
        $stmtId = (int)array_key_first($found);
        if ($stmtId === $chosenAccountId) return null;
        $name = function (int $id): string {
            $s = $this->db->prepare("SELECT CONCAT(code, ' ', name) FROM chart_of_accounts WHERE id = ?");
            $s->execute([$id]);
            return (string)($s->fetchColumn() ?: ('account #' . $id));
        };
        return sprintf('This statement is for %s (••%s), but the import was set to %s. Pick %s and import again.',
            $name($stmtId), $found[$stmtId], $name($chosenAccountId), $name($stmtId));
    }

    /** The one bank account every earlier import recorded these four digits under, or null (none, or they disagree). */
    private function accountFromPastSections(string $sfx, array $bankIds): ?int
    {
        try {
            $s = $this->db->prepare("SELECT DISTINCT bank_account_id FROM bank_statement_sections WHERE account_suffix = ? AND bank_account_id IS NOT NULL");
            $s->execute([$sfx]);
            $ids = array_values(array_filter(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)), static fn($i) => in_array($i, $bankIds, true)));
            return count($ids) === 1 ? $ids[0] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function bankAccountIds(): array
    {
        try {
            return array_map('intval', $this->db->query("SELECT id FROM chart_of_accounts WHERE sub_type = 'bank'")->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Lines printed for another account on the same statement get that account as their bank
     * account (Reserve funds ••6819 → 1020, GST Reserves ••6827 → 1025, preselected) — by the
     * section the parser saw them under. A transfer between chequing and a savings account is
     * printed in BOTH sections; it becomes one transfer (BankAccountSplitService::pairMirrors):
     *   the chequing line → category = the savings account (money out: type transfer, DR savings /
     *     CR chequing; money in: DR chequing / CR savings);
     *   the savings line  → category = its own account, type transfer: the journal posts nothing
     *     for it (LedgerSyncService), but it stays on the savings account's list so its running
     *     balance is complete for the statements check.
     * Lines with no section header (photos, older formats) that follow a running balance of
     * their own are flagged without a preselected account. Public for tests.
     */
    public function routeOtherAccountRows(array $rows): array
    {
        require_once __DIR__ . '/BankAccountSplitService.php';
        $sectioned = array_filter($rows, static fn($r) => array_key_exists('statement_primary', $r) && $r['statement_primary'] !== null);
        if ($sectioned && array_filter($rows, static fn($r) => ($r['statement_primary'] ?? null) === false)) {
            foreach ($rows as &$r) {
                if (($r['statement_primary'] ?? null) !== false) continue;
                $sfx = (string)($r['statement_account'] ?? '');
                $code = BankAccountSplitService::codeForSuffix($sfx);
                $acct = $code ? $this->accountByCode($code) : null;
                $r['other_account'] = [
                    'suffix'          => $sfx,
                    'label'           => $code ? BankAccountSplitService::TARGETS[$code]['label'] : 'account ••' . $sfx,
                    'bank_account_id' => $acct ? (int)$acct['id'] : null,
                    'account'         => $acct ? $acct['code'] . ' – ' . $acct['name'] : null,
                    'by'              => 'statement',
                ];
                if ($acct) $r['bank_account_id'] = (int)$acct['id'];
            }
            unset($r);
            return $this->applyMirrors($rows);
        }

        // No headers: lines that follow a running balance of their own.
        $keys = array_keys($rows);
        $lines = [];
        foreach ($keys as $i => $k) {
            $r = $rows[$k];
            $lines[] = StatementCoverageService::lineFromRow(
                ['id' => $i + 1, 'session_id' => 1, 'transaction_date' => $r['date'] ?? '', 'type' => $r['type'] ?? '', 'amount' => $r['amount'] ?? 0],
                ['amount' => $r['amount'] ?? 0, 'type' => $r['type'] ?? '', 'raw_line' => $r['raw_line'] ?? ''])
                + ['description' => (string)($r['description'] ?? '')];
        }
        if (count(array_filter($lines, static fn($l) => $l['balance'] !== null)) < 3) return $rows;
        $split = BankAccountSplitService::split($lines);
        foreach ($split['chains'] as $c) {
            if ($c['suggest'] === 'keep') continue;   // chequing activity, or nothing says otherwise
            foreach ($c['lines'] as $l) {
                $k = $keys[$l['id'] - 1] ?? null;
                if ($k === null) continue;
                $rows[$k]['other_account'] = [
                    'suffix'          => null,
                    'label'           => $c['suggest'] === 'exclude' ? 'membership shares — not a bank account; untick it'
                                         : 'follows a balance of its own (up to $' . number_format((float)($c['max_balance'] ?? 0), 2) . ') — another account?',
                    'bank_account_id' => null,
                    'account'         => null,
                    'by'              => 'balance',
                    'guess'           => $c['guess'],
                ];
            }
        }
        return $rows;
    }

    /** Pair each routed savings line with its chequing twin and make the two one transfer. */
    private function applyMirrors(array $rows): array
    {
        $pairs = BankAccountSplitService::pairMirrors(
            array_map(static fn($r) => ['date' => $r['date'] ?? '', 'amount' => (float)($r['amount'] ?? 0),
                                        'in' => ($r['type'] ?? '') === 'income',
                                        'other' => !empty($r['other_account']['bank_account_id'])], $rows));
        foreach ($pairs as $savingsKey => $chequingKey) {
            $acctId = (int)$rows[$savingsKey]['other_account']['bank_account_id'];
            $acct = $rows[$savingsKey]['other_account']['account'];
            // the chequing side carries the transfer
            $rows[$chequingKey]['account_id']   = $acctId;
            $rows[$chequingKey]['account_name'] = $acct;
            $rows[$chequingKey]['type']         = $rows[$chequingKey]['type'] === 'income' ? 'income' : 'transfer';
            $rows[$chequingKey]['auto_cat']     = true;
            $rows[$chequingKey]['internal_transfer'] = ['with' => $rows[$savingsKey]['other_account']['label'], 'side' => 'chequing'];
            // the savings side moves nothing in the books
            $rows[$savingsKey]['account_id']   = $acctId;
            $rows[$savingsKey]['account_name'] = $acct;
            $rows[$savingsKey]['type']         = 'transfer';
            $rows[$savingsKey]['auto_cat']     = true;
            $rows[$savingsKey]['internal_transfer'] = ['with' => 'chequing', 'side' => 'savings'];
        }
        return $rows;
    }

    private function accountByCode(string $code): ?array
    {
        try {
            $s = $this->db->prepare("SELECT id, code, name FROM chart_of_accounts WHERE code = ? LIMIT 1");
            $s->execute([$code]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

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
        $expense = round((float)$totals['expense'] + (float)($totals['transfer_out'] ?? 0), 2);   // card payoffs left the account too

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
        $this->dupUsed = []; $this->expClaimed = [];
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

        if ($kind !== 'credit_card') $rows = $this->routeOtherAccountRows($rows);
        $totals['other_account'] = 0;

        foreach ($rows as &$row) {
            // A chequing ↔ savings transfer is already decided (routeOtherAccountRows): it is
            // never a cost, an invoice payment or a card payoff.
            if (!empty($row['internal_transfer'])) {
                $row['is_duplicate'] = false; $row['duplicate_type'] = null; $row['duplicate_tx_id'] = null; $row['match_candidate'] = false;
                $dupe = $this->checkTrueDuplicate($row['date'], $row['amount'], $row['type'] === 'income' ? 'income' : 'expense', $kind === 'credit_card' ? 'credit_card' : 'bank');
                if ($dupe) { $row['is_duplicate'] = true; $row['duplicate_type'] = 'true_duplicate'; $row['duplicate_tx_id'] = $dupe; $totals['duplicates']++; }
                if (!empty($row['other_account'])) { $totals['other_account']++; continue; }
                if ($row['type'] === 'income') $totals['income'] += $row['amount']; else $totals['expense'] += $row['amount'];
                continue;
            }
            // ── Credit-card settlement (transfer, excluded from P&L) ───────────
            //    Catches the monthly "pay credit card" debit on a bank statement
            //    and the "payment received" credit on a CC statement.
            if ($this->applyCcSettlement($row, $ccPayableId, $defaultExpenseId, $kind === 'credit_card' ? 'credit_card' : 'bank')) {
                // Not income or expense, but it still left the account: the statement's own
                // opening + in - out = closing proof must count it (it never balanced with a payoff).
                if ($kind !== 'credit_card') $totals['transfer_out'] = ($totals['transfer_out'] ?? 0) + abs((float)$row['amount']);
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
            $trueDupe = $this->checkTrueDuplicate($row['date'], $row['amount'], $row['type'], $kind === 'credit_card' ? 'credit_card' : 'bank');

            if ($trueDupe) {
                // Real duplicate — same bank import done twice
                $row['is_duplicate']    = true;
                $row['duplicate_type']  = 'true_duplicate';
                $row['duplicate_tx_id'] = $trueDupe;
                $row['match_candidate'] = false;
                $totals['duplicates']++;
            } elseif (!empty($row['other_account'])) {
                // Another account's line (a savings section): never matched to a receipt or an
                // invoice of this account.
                $row['is_duplicate']    = false;
                $row['duplicate_type']  = null;
                $row['duplicate_tx_id'] = null;
                $row['match_candidate'] = false;
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

            if (!empty($row['other_account'])) {   // not this account's money: kept out of its balance check
                $totals['other_account']++;
                continue;
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
     *   2. Imagick page rendering + Google Vision or local Tesseract (never a public OCR service)
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

        // 2 — Imagick (PDF rendering) + Vision / Tesseract
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
     * Google Cloud Vision (DOCUMENT_TEXT_DETECTION) if configured, otherwise a
     * local Tesseract (never a public OCR service).  Images are processed one page at a time to keep
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

            // Without Vision (Tesseract): fall back to 100 DPI if still over ~700 KB.
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

            // Vision, else local Tesseract, else a clear refusal (ocrImageBytes — audit #19).
            $pageText = $this->ocrImageBytes($jpegData, 'jpeg');

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
     * Returns null if the call fails (caller falls back to local Tesseract, else refuses).
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
     * OCR one page image (security review #19, 2026-10-12). Bank statements are the business's most
     * private papers: they go to Google Cloud Vision (already contracted, ReceiptOCR) or a local
     * Tesseract on the server — never to a public OCR service. (This used to post every page to
     * OCR.space, on its shared public demo key whenever OCR_SPACE_API_KEY was not set.)
     * Neither available, or both fail: refuse with a clear message — nothing is guessed.
     */
    private function ocrImageBytes(string $imageData, string $subtype = 'jpeg'): string
    {
        $engine = self::ocrEngine($this->isGoogleVisionAvailable(), self::tesseractPath());
        if ($engine === 'vision') {
            $text = $this->ocrPageViaGoogleVision($imageData);
            if ($text !== null && trim($text) !== '') return $text;
            $engine = self::tesseractPath() !== null ? 'tesseract' : 'none';
        }
        if ($engine === 'tesseract') {
            $text = $this->ocrViaTesseract($imageData, $subtype);
            if (trim($text) !== '') return $text;
            throw new RuntimeException('Could not read this scanned statement (Tesseract found no text). Upload the CSV or the bank\'s own PDF instead.');
        }
        throw new RuntimeException('Scanned statements need Google Vision (GOOGLE_VISION_CREDENTIALS) or Tesseract on the server, and neither is working. '
            . 'Upload the CSV or the bank\'s own (text) PDF instead.');
    }

    /** Which OCR engine to use: 'vision' | 'tesseract' | 'none'. Never a public web service. PURE. */
    public static function ocrEngine(bool $visionAvailable, ?string $tesseractPath): string
    {
        if ($visionAvailable) return 'vision';
        return $tesseractPath !== null ? 'tesseract' : 'none';
    }

    /** The local tesseract binary (TESSERACT_PATH, else the usual places), or null. */
    private static function tesseractPath(): ?string
    {
        if (!function_exists('exec')) return null;
        $candidates = defined('TESSERACT_PATH') ? [(string)TESSERACT_PATH] : ['/usr/bin/tesseract', '/usr/local/bin/tesseract', '/opt/homebrew/bin/tesseract'];
        foreach ($candidates as $p) if ($p !== '' && @is_executable($p)) return $p;
        return null;
    }

    private function ocrViaTesseract(string $imageData, string $subtype): string
    {
        $bin = self::tesseractPath();
        if ($bin === null) return '';
        $ext = preg_replace('/[^a-z]/', '', strtolower($subtype)) ?: 'jpg';
        $tmp = tempnam(sys_get_temp_dir(), 'mw_bi_') . '.' . $ext;
        if (file_put_contents($tmp, $imageData) === false) return '';
        try {
            $out = [];
            $code = 0;
            @exec(escapeshellarg($bin) . ' ' . escapeshellarg($tmp) . ' stdout -l eng --psm 6 2>/dev/null', $out, $code);
            return $code === 0 ? implode("\n", $out) : '';
        } finally {
            @unlink($tmp);
        }
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
     * EBCDIC (IBM CP037) byte → ASCII translation table. The Vancity 2026+
     * statement font encodes glyphs with EBCDIC code points (A–I=0xC1-C9,
     * J–R=0xD1-D9, S–Z=0xE2-E9, 0–9=0xF0-F9), which smalot emits as raw bytes.
     */
    private function ebcdicTable(): array
    {
        static $tr = null;
        if ($tr !== null) return $tr;
        $m = [0x40 => ' ', 0x4B => '.', 0x4D => '(', 0x4E => '+', 0x50 => '&', 0x5B => '$',
              0x5C => '*', 0x5D => ')', 0x60 => '-', 0x61 => '/', 0x6B => ',', 0x7A => ':',
              0x7C => '@', 0x7D => "'", 0x7E => '=', 0x6F => '?', 0x6C => '%', 0x7B => '#'];
        for ($i = 0; $i <= 8; $i++) $m[0x81 + $i] = chr(ord('a') + $i);   // a-i
        for ($i = 0; $i <= 8; $i++) $m[0x91 + $i] = chr(ord('j') + $i);   // j-r
        for ($i = 0; $i <= 7; $i++) $m[0xA2 + $i] = chr(ord('s') + $i);   // s-z
        for ($i = 0; $i <= 8; $i++) $m[0xC1 + $i] = chr(ord('A') + $i);   // A-I
        for ($i = 0; $i <= 8; $i++) $m[0xD1 + $i] = chr(ord('J') + $i);   // J-R
        for ($i = 0; $i <= 7; $i++) $m[0xE2 + $i] = chr(ord('S') + $i);   // S-Z
        for ($i = 0; $i <= 9; $i++) $m[0xF0 + $i] = chr(ord('0') + $i);   // 0-9
        $tr = [];
        foreach ($m as $b => $c) $tr[chr($b)] = $c;
        $tr[chr(0x0A)] = "\n"; $tr[chr(0x0D)] = "\n"; $tr[chr(0x25)] = "\n"; // EBCDIC LF
        return $tr;
    }

    /** True when smalot text is EBCDIC-encoded (high ratio of EBCDIC letter/digit bytes). */
    private function looksLikeEbcdic(string $utf8): bool
    {
        $raw = @mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
        if ($raw === false || strlen($raw) < 40) return false;
        $hits = preg_match_all('/[\xC1-\xC9\xD1-\xD9\xE2-\xE9\xF0-\xF9]/', $raw);
        return ($hits / strlen($raw)) > 0.25;
    }

    /**
     * Decode an EBCDIC-encoded Vancity statement (smalot UTF-8 output) into clean
     * "DD MON DESCRIPTION AMOUNT BALANCE" lines that parsePdfText can consume, with
     * the 3-column header + opening balance prepended so the parser uses its reliable
     * running-balance classification. Returns null when the text isn't EBCDIC.
     */
    private function decodeEbcdicStatement(string $utf8): ?string
    {
        if (!$this->looksLikeEbcdic($utf8)) return null;

        $raw = mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
        $dec = strtr($raw, $this->ebcdicTable());
        $dec = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x80-\xFF]/', ' ', $dec);

        $lines = [];
        foreach (explode("\n", $dec) as $l) {
            $l = trim(preg_replace('/\s+/', ' ', $l));
            if ($l !== '') $lines[] = $l;
        }
        $n = count($lines);
        $isNum = static fn($s) => (bool)preg_match('/^\d{1,3}(?:,\d{3})*\.\d{2}$/', str_replace(' ', '', $s));
        $this->pdfSummary = self::vancitySummary($lines);

        // Opening balance — first "OPENING BALANCE" label followed by a number line.
        // This is the primary (chequing) account; the statement also lists Reserve /
        // GST-reserve sub-accounts further down, each with their own OPENING BALANCE.
        $opening = null; $startIdx = 0; $openingLabelIdx = -1;
        for ($i = 0; $i < $n; $i++) {
            if (stripos($lines[$i], 'OPENING BALANCE') !== false) {
                for ($k = $i + 1; $k < min($i + 3, $n); $k++) {
                    if ($isNum($lines[$k])) { $opening = str_replace(' ', '', $lines[$k]); $startIdx = $k + 1; $openingLabelIdx = $i; break 2; }
                }
            }
        }

        // Bound to the primary account: stop at the next account section — a second
        // "OPENING BALANCE" label or a Reserve/Savings/GST sub-account header. Without
        // this, the tiny reserve-account transactions corrupt the running balance.
        $endIdx = $n;
        for ($i = $startIdx; $i < $n; $i++) {
            if (stripos($lines[$i], 'OPENING BALANCE') !== false
                || preg_match('/INVESTMENT\s+SAVINGS|JUMPSTART|RESERVE\s+FUND|GST\s+RESERVE/i', $lines[$i])) {
                $endIdx = $i;
                break;
            }
        }

        // Reflow each transaction: a date+description line, optional wrapped
        // description lines, then the amount line and the running-balance line.
        $monthRe = 'JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC';
        $isDate  = static fn($s) => (bool)preg_match('/^\d{1,2}(' . $monthRe . ')/i', str_replace(' ', '', $s));
        $tx = [];
        for ($i = $startIdx; $i < $endIdx; $i++) {
            $compact = str_replace(' ', '', $lines[$i]);
            if (!preg_match('/^(\d{1,2})(' . $monthRe . ')(.*)$/i', $compact, $x)) continue;

            $desc = $x[3];
            $j = $i + 1;
            // Absorb wrapped-description lines (e.g. "(SQ SOUTHLANDS NURSERY ... BCCA)")
            // until we reach the amount column. Stop at a number or the next date.
            while ($j < $endIdx && !$isNum($lines[$j]) && !$isDate($lines[$j])) {
                $desc .= str_replace(' ', '', $lines[$j]);
                $j++;
            }
            $nums = [];
            while ($j < $endIdx && count($nums) < 2 && $isNum($lines[$j])) { $nums[] = str_replace(' ', '', $lines[$j]); $j++; }
            if (count($nums) < 2) continue;
            if ($desc === '') $desc = 'TRANSACTION';
            $tx[] = sprintf('%02d %s %s %s %s', (int)$x[1], strtoupper($x[2]), $desc, $nums[0], $nums[1]);
            $i = $j - 1;
        }
        if (count($tx) < 1) return null;

        // The other accounts' sections (savings, shares) after the primary one: each header,
        // its opening balance and its transactions, so parsePdfText can route them (2026-10-07).
        // Headers are recognised by accountSectionHeader(); anything else is skipped.
        $subs = [];
        for ($i = $endIdx; $i < $n; $i++) {
            $h = self::accountSectionHeader($lines[$i]);
            if ($h === null || $h['kind'] === 'primary') continue;
            $open = null;
            // The label sits under the column headings (DATE / DESCRIPTION / WITHDRAWALS / DEPOSITS /
            // BALANCE), six lines below the account's header — look that far, not past a date line.
            for ($k = $i; $k < min($i + 9, $n); $k++) {
                if ($k > $i && preg_match('/^\d{1,2}\s*(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)/i', str_replace(' ', '', $lines[$k]))) break;
                if ($k > $i && self::accountSectionHeader($lines[$k]) !== null) break;
                if (stripos($lines[$k], 'OPENING BALANCE') === false) continue;
                if (preg_match('/OPENING\s+BALANCE\s+(\d{1,3}(?:,\d{3})*\.\d{2})/i', $lines[$k], $om)) { $open = $om[1]; break; }
                for ($q = $k + 1; $q < min($k + 3, $n); $q++) {
                    if ($isNum($lines[$q])) { $open = str_replace(' ', '', $lines[$q]); break 2; }
                }
            }
            $subs[] = ['idx' => $i, 'line' => $lines[$i], 'open' => $open];
        }
        $subTx = [];
        foreach ($subs as $si => $sub) {
            $to = $subs[$si + 1]['idx'] ?? $n;
            $block = [];
            for ($i = $sub['idx'] + 1; $i < $to; $i++) {
                $compact = str_replace(' ', '', $lines[$i]);
                if (!preg_match('/^(\d{1,2})(' . $monthRe . ')(.*)$/i', $compact, $x)) continue;
                $desc = $x[3];
                $j = $i + 1;
                while ($j < $to && !$isNum($lines[$j]) && !$isDate($lines[$j])) { $desc .= str_replace(' ', '', $lines[$j]); $j++; }
                $nums = [];
                while ($j < $to && count($nums) < 2 && $isNum($lines[$j])) { $nums[] = str_replace(' ', '', $lines[$j]); $j++; }
                if (count($nums) < 2) continue;
                if ($desc === '') $desc = 'TRANSACTION';
                $block[] = sprintf('%02d %s %s %s %s', (int)$x[1], strtoupper($x[2]), $desc, $nums[0], $nums[1]);
                $i = $j - 1;
            }
            $subTx[] = '';
            $subTx[] = $sub['line'];
            if ($sub['open'] !== null) $subTx[] = 'OPENING BALANCE ' . $sub['open'];
            foreach ($block as $b) $subTx[] = $b;
        }

        // Preserve the statement period dates so parsePdfText can date rows.
        // Only "DD MON YYYY" patterns count — those appear in the period header;
        // transaction lines have no year, and stray references (print/due dates)
        // are NOT attached to a month, so they can't hijack the year (e.g. a
        // December-2025 statement printed in 2026 must still date rows as 2025).
        $periodDates = [];
        foreach ($lines as $l) {
            $c = str_replace(' ', '', $l);
            if (preg_match_all('/(\d{1,2})(' . $monthRe . ')(20\d\d)/i', $c, $pm, PREG_SET_ORDER)) {
                foreach ($pm as $mm) $periodDates[] = $mm[1] . ' ' . strtoupper($mm[2]) . ' ' . $mm[3];
            }
        }
        $yearLine = $periodDates ? ('STATEMENT PERIOD ' . implode(' to ', $periodDates) . "\n") : '';

        $header = $yearLine . "WITHDRAWALS DEPOSITS BALANCE\n";
        if ($opening !== null) $header .= "OPENING BALANCE $opening\n";
        return $header . implode("\n", $tx) . ($subTx ? "\n" . implode("\n", $subTx) : '');
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
        // Normalize line endings; drop a UTF-8 byte-order mark (Vancity's card export starts with one)
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        $lines   = explode("\n", $content);

        // Header-driven layouts. Vancity's card "Transaction history" export (2026) is
        // Transaction Date, Posted Date, Reference Number, …, Merchant Name, …, Amount ("$75.93",
        // payments "-$232.07") — not the date/description/debit/credit preset, which read 0 rows.
        $head = array_map(fn($h) => strtolower(trim((string)$h)), str_getcsv($lines[0] ?? '', ',', '"', ''));
        $col  = fn(string $name) => array_search($name, $head, true);
        if ($col('merchant name') !== false && $col('amount') !== false && $col('transaction date') !== false) {
            $mapping = ['date' => $col('transaction date'), 'description' => $col('merchant name'), 'amount' => $col('amount')];
            $skipRows = max($skipRows, 1);
            $kind = 'credit_card';
        }

        $isCreditCard = ($kind === 'credit_card');

        // One date order per file: if any n/n/yyyy date has a first part over 12, the file is day-first
        // (d/m/Y). Tried row by row, m/d/Y won for every day ≤ 12 and put those lines in the wrong
        // month (review 2026-10-12).
        $dates = [];
        $j = 0;
        foreach ($lines as $ln) {
            if ($j++ < $skipRows || trim($ln) === '') continue;
            $c = str_getcsv(trim($ln), ',', '"', '');
            $dates[] = trim($c[$mapping['date'] ?? 0] ?? '');
        }
        $this->dayFirst = self::isDayFirst($dates);

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
            // A year-less export date ("Dec 07") gets the current year from
            // strtotime — take its most recent past occurrence instead.
            if (!preg_match('/\d{4}|\b\d{1,2}[\/\-][A-Za-z]{3}[\/\-]\d{2}\b|[\/\-]\d{2}$/', $dateRaw)) {
                $date = $this->latestOnOrBefore((int)substr($date, 5, 2), (int)substr($date, 8, 2), $this->today());
            }

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

        if ($this->isCreditCardPayment($desc) || self::isCardStatementPayment($desc)) {
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
     * The latest month-attached full date in the statement text ("15 DEC 2025",
     * "Dec 15, 2025", "15DEC2025"), capped at the import date — i.e. the period
     * end (or a due date just after it). Null when the text has none.
     */
    private function statementAnchorDate(string $text): ?string
    {
        $mon = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';
        $found = [];
        if (preg_match_all('/\b(\d{1,2})\s*(' . $mon . ')[a-z]*\.?,?\s*(20\d{2})(?![.,]?\d)/i', $text, $a, PREG_SET_ORDER)) {
            foreach ($a as $x) $found[] = [(int)$x[3], $x[2], (int)$x[1]];
        }
        if (preg_match_all('/\b(' . $mon . ')[a-z]*\.?\s*(\d{1,2}),?\s*(20\d{2})(?![.,]?\d)/i', $text, $b, PREG_SET_ORDER)) {
            foreach ($b as $x) $found[] = [(int)$x[3], $x[1], (int)$x[2]];
        }
        $latest = null;
        foreach ($found as [$y, $m, $d]) {
            $mm = (int)date('n', strtotime("1 $m 2000"));
            if (!checkdate($mm, $d, $y)) continue;
            $iso = sprintf('%04d-%02d-%02d', $y, $mm, $d);
            if ($latest === null || $iso > $latest) $latest = $iso;
        }
        if ($latest === null) return null;
        return min($latest, $this->today());
    }

    /**
     * The most recent occurrence of month/day on or before $limit (and never
     * after the import date) — how a statement line with no year is dated.
     */
    private function latestOnOrBefore(int $month, int $day, string $limit): string
    {
        $limit = min($limit, $this->today());
        $year  = (int)substr($limit, 0, 4);
        for ($i = 0; $i < 8; $i++, $year--) {
            if (!checkdate($month, $day, $year)) continue;   // 29 Feb in a non-leap year
            $iso = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($iso <= $limit) return $iso;
        }
        return sprintf('%04d-%02d-%02d', (int)substr($limit, 0, 4), $month, $day);
    }

    /**
     * Attempt to parse a date string in common Canadian bank formats.
     * Returns YYYY-MM-DD string or null.
     */
    /** Set per file by parseCSV(): the file writes dates day-first (d/m/Y). */
    private bool $dayFirst = false;

    /** Is a list of n/n/yyyy dates day-first? True when any first part is over 12. Pure. */
    public static function isDayFirst(array $dates): bool
    {
        foreach ($dates as $d) {
            if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-]\d{2,4}$#', trim((string)$d), $m) && (int)$m[1] > 12 && (int)$m[2] <= 12) return true;
        }
        return false;
    }

    private function parseDate(string $s): ?string
    {
        $s = trim($s);
        if (!$s) return null;

        // Try common formats (day-first files try d/m/Y before m/d/Y)
        $formats = $this->dayFirst
            ? ['Y-m-d', 'd/m/Y', 'm/d/Y', 'M d, Y', 'M. d, Y', 'd-M-y', 'Y/m/d', 'd-m-Y', 'm-d-Y']
            : ['Y-m-d', 'm/d/Y', 'd/m/Y', 'M d, Y', 'M. d, Y', 'd-M-y', 'Y/m/d', 'm-d-Y'];
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
    /**
     * On a CARD statement, a credit that says "payment" is the payoff, whatever the bank calls it
     * ("PAYMENT RECEIVED", "ONLINE PAYMENT", "PAYMENT FROM CHQ") — unless it's a refund. Missed,
     * it became a "refund" and cut expenses by the payoff (review 2026-10-12). Pure.
     */
    public static function isCardStatementPayment(string $description): bool
    {
        $d = strtoupper($description);
        // Vancity glues the posting date on: "JUL 13PAYMENT RECEIVED" — no \b between "13" and "P".
        return (bool)preg_match('/(?<![A-Z])(PAYMENT|PYMT|PMT)\b/', $d)
            && !preg_match('/\b(REFUND|RETURN|REVERSAL|CREDIT\s+VOUCHER|ADJUSTMENT|CASH\s*BACK|REWARD)/', $d);
    }

    private function isCreditCardPayment(string $description): bool
    {
        $d = ' ' . strtoupper($description) . ' ';

        // The card's own "payment received" / autopay lines.
        if (preg_match('/\bPAYMENT\b.*\bTHANK\s*YOU\b/', $d)) return true;
        // A card statement's own bare autopay line ("PRE-AUTHORIZED PAYMENT") — but NOT a bank
        // statement's pre-authorized bill, which names its payee ("Preauthorized payment TELUS
        // MOBILITY"): that's a bill. Catching those booked every PAD bill since June 2026 as a
        // card payoff (Telus, ICBC, insurance, payroll, the RAM loan).
        if (preg_match('/^\s*(PRE-?AUTH(ORIZED)?|AUTOMATIC|AUTO)\s+PAYMENT\s*(-?\s*THANK\s*YOU)?\s*$/', $d)) return true;

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
    private function applyCcSettlement(array &$row, int $ccPayableId, int $defaultExpenseId, string $kind = 'bank'): bool
    {
        $isSettlement = !empty($row['cc_payment'])
            || (($row['type'] ?? '') === 'expense' && $this->isCreditCardPayment($row['description'] ?? ''))
            // On the card's OWN statement, "PAYMENT RECEIVED -- THANK YOU" is the payoff (the chequing
            // line carries the cash) — read as a $250 charge on 2026-10-13.
            // A line the card parser already called a charge ("GOOGLE *PAYMENT") never is (Fable 2026-10-13).
            || ($kind === 'credit_card' && ($row['cc_role'] ?? null) !== 'charge' && self::isCardStatementPayment($row['description'] ?? ''));
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

        // Card payoffs go through the duplicate check like every other line: skipping it let
        // overlapping statement imports re-add the same payoff 2-4 times (2026-10-05).
        try {
            // Compared only with lines from the same kind of statement: the card statement's own
            // payment line and the chequing payoff are two lines, not one imported twice.
            $dupe = !empty($row['date']) ? $this->checkTrueDuplicate((string)$row['date'], (float)$row['amount'], 'expense', $kind) : null;
        } catch (Throwable $e) {
            $dupe = null;   // never block an import on the check itself
        }
        if ($dupe) {
            $row['is_duplicate']    = true;
            $row['duplicate_type']  = 'true_duplicate';
            $row['duplicate_tx_id'] = $dupe;
        }

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
        // Never for Stripe: a payout bundles several charges, and StripePayoutService books it
        // from Stripe's own payout data. A range guess tied a payout to one unrelated invoice,
        // invented a "fee" and then blocked the real booking (review 2026-10-12).
        if (stripos($bankDescription, 'stripe') !== false) return null;
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
            LIMIT 2
        ");
        $stmt->execute([$date, $bankAmount, $maxGross, $date, $date]);
        $cands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Only when one invoice fits: two in range is a guess, left for a person.
        if (count($cands) !== 1) return null;
        $match = $cands[0];

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
            WHERE (code = '6800'
                   OR name LIKE '%processing fee%'
                   OR name LIKE '%payment processing%'
                   OR name LIKE '%bank charge%')
              AND name NOT LIKE '%meal%'          -- 6850 is Meals & Entertainment on production (2026-10-13)
              AND type = 'expense' AND is_active = 1
            ORDER BY CASE WHEN code = '6800' THEN 0 ELSE 1 END
            LIMIT 1
        ");
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        $this->db->prepare("
            INSERT INTO chart_of_accounts
                (code, name, type, sub_type, normal_balance, is_active, display_order)
            VALUES ('6800', 'Bank Charges & Fees', 'expense', 'banking', 'debit', 1, 510)
        ")->execute();
        return (int)$this->db->lastInsertId();
    }

    /**
     * Stage 1 — True duplicate: the same bank transaction was already imported.
     * Checks bank_import_rows (not all of accounting_transactions) so that
     * expense-backed transactions are NOT falsely flagged as duplicates.
     * Returns the existing transaction_id, or null.
     */
    /**
     * @param ?string $kind 'credit_card' or 'bank' — the statement being imported. A card statement's
     *        own "PAYMENT – THANK YOU" line and the chequing line that paid it are the SAME amount on
     *        the same day but two different lines; matched across kinds, the chequing payoff was
     *        dropped as a duplicate and DR 2400 / CR 1010 never booked (review 2026-10-12).
     */
    private function checkTrueDuplicate(string $date, float $amount, string $type, ?string $kind = null): ?int
    {
        // Match directly against accounting_transactions rather than requiring a
        // bank_import_rows audit entry — historical imports (or transactions created
        // outside the standard commit path) may lack a bank_import_rows row for the
        // same transaction, which previously made this check silently miss real
        // duplicates and let bulk re-uploads re-import already-recorded transactions.
        //
        // type IN (?, 'transfer') — not just type = ? — because a prior import of
        // this exact row may have already been reclassified to 'transfer': an
        // income deposit auto-matched to an invoice (see the "Book the bank
        // deposit as a cash-clearing TRANSFER" branch above) or a credit-card
        // settlement, both relabel the row post-import. Checking only the
        // incoming row's own type missed every such row on re-import — the
        // duplicate silently got re-created instead of skipped. Real case: a
        // July statement re-imported over an already-covered period created 22
        // duplicate rows this way. 'transfer' is still narrower than dropping
        // the type filter entirely, which would risk matching an unrelated
        // expense against an income of the same amount/date.
        $stmt = $this->db->prepare("
            SELECT id
            FROM accounting_transactions
            WHERE type IN (?, 'transfer')
              AND ABS(amount - ?) < 0.01
              AND ABS(DATEDIFF(transaction_date, ?)) <= 1
              AND (reference_type = 'bank_import'
                   /* a statement line joined to its receipt at import is still a statement line
                      (2026-10-13): re-importing it re-matched the receipt instead of skipping */
                   OR (reference_type = 'expense' AND EXISTS (SELECT 1 FROM bank_import_rows bir WHERE bir.transaction_id = accounting_transactions.id)))
              " . ($kind === null ? '' : ($kind === 'credit_card'
                    ? "AND bank_account_id IN (SELECT account_id FROM bank_statement_accounts WHERE kind = 'card')"
                    : "AND (bank_account_id IS NULL OR bank_account_id NOT IN (SELECT account_id FROM bank_statement_accounts WHERE kind = 'card'))")) . "
            ORDER BY ABS(DATEDIFF(transaction_date, ?)), id
            LIMIT 20
        ");
        $stmt->execute([$type, $amount, $date, $date]);
        // Count-aware: each existing line answers for ONE row of this file.
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (isset($this->dupUsed[(int)$id])) continue;
            $this->dupUsed[(int)$id] = true;
            return (int)$id;
        }
        return null;
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
              AND NOT " . BankLineReceipts::linkedSql($this->db, 'e.id') . "   -- one of several on another line (migration 1372)
              -- already joined to a statement line at an earlier import: one receipt, one charge (2026-10-13)
              AND NOT EXISTS (SELECT 1 FROM accounting_transactions x JOIN bank_import_rows xr ON xr.transaction_id = x.id
                               WHERE x.reference_type = 'expense' AND x.reference_id = e.id)
            ORDER BY day_diff ASC, e.expense_date DESC
            LIMIT 5
        ");
        $stmt->execute([$date, $amount, $date, $date]);
        $candidates = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn($c) => !isset($this->expClaimed[(int)$c['id']])));   // matched to an earlier row of this file

        if (empty($candidates)) return null;

        // Score each candidate and pick the best

        $best      = null;
        $bestScore = 0;

        foreach ($candidates as $c) {
            $dayDiff = (int)$c['day_diff'];
            $score   = 50; // base: amount matched
            $score  += $dayDiff === 0 ? 20 : ($dayDiff === 1 ? 12 : ($dayDiff === 2 ? 6 : 2));

            // Vendor name overlap
            if (self::vendorInDescription((string)($c['vendor_name_raw'] ?? ''), $description)) {
                $score += 20;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $c;
            }
        }

        if (!$best) return null;
        $this->expClaimed[(int)$best['id']] = true;

        return ['expense' => $best, 'confidence' => min($bestScore, 100)];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // MANUAL RECONCILIATION — expense ↔ bank transaction
    //
    // findExpenseMatch() above only runs once, inline during import commit, and
    // only considers approved/forwarded expenses. There is no later rescan, so
    // any transaction that misses that single pass (receipt still draft at
    // import time, amount/date drift) stays unmatched forever. These methods
    // back a human "find match → attach" flow reachable from both the Edit
    // Expense modal and the transactions list, mirroring
    // InvoiceReconciliationService's candidates/attach/detach pattern.
    // ══════════════════════════════════════════════════════════════════════════

    private const EXPENSE_MATCH_THRESHOLD = 30; // lower bar than auto-match — a human confirms
    /**
     * A Stripe fee line is split out of a payout by StripePayoutService (description "Stripe fees — payout po_…",
     * payment_reference = the payout id): Stripe's fee, booked to fees, never a receipt's purchase. Receipt matching
     * leaves it out (Tim, 2026-10-15: it was offered as a match for a Chevron receipt). SQL for alias `at`.
     */
    public const NOT_STRIPE_FEE_SQL = "NOT (COALESCE(at.payment_reference, '') LIKE 'po\\_%' AND at.description LIKE 'Stripe fees%')";

    public static function isStripeFeeLine(string $description, ?string $paymentReference): bool
    {
        return strncmp((string)$paymentReference, 'po_', 3) === 0 && strncmp($description, 'Stripe fees', 11) === 0;
    }

    /**
     * Amount part of a receipt ↔ bank line score. With Penny's read of the printed total (she disagrees
     * with the saved one), the PRINTED amount is the receipt: exact to her read = 50; exact only to the
     * saved (misread) total = 35, said so. Without her read: exact 50, within 2% 35. PURE.
     * @return array{0: int, 1: string[]}|null
     */
    public static function amountScore(float $saved, float $bank, ?float $pennyTotal = null): ?array
    {
        $saved = round($saved, 2);
        $bank = round($bank, 2);
        $close = static fn(float $a) => abs($a - $bank) <= max(0.5, $a * 0.02);
        if ($pennyTotal !== null && abs(round($pennyTotal, 2) - $saved) >= 0.01) {
            $p = round($pennyTotal, 2);
            if (abs($p - $bank) < 0.01) return [50, ['Exact match to the printed total (Penny\'s read)']];
            if (abs($saved - $bank) < 0.01) return [35, ['Matches the saved total — Penny read $' . number_format($p, 2) . ' printed']];
            if ($close($p)) return [35, ['Amount close to the printed total (within 2%)']];
            return $close($saved) ? [30, ['Amount close (within 2%)']] : null;
        }
        if (abs($saved - $bank) < 0.01) return [50, ['Exact amount match']];
        if ($close($saved)) return [35, ['Amount close (within 2%)']];
        return null;
    }

    /** Penny's pending read of an expense's total (expense_suggestions, migration 1125), or null. */
    private function pennyTotal(int $expenseId): ?float
    {
        try {
            $s = $this->db->prepare("SELECT suggestion_json FROM expense_suggestions WHERE expense_id = ? AND source = 'live' AND status = 'pending' ORDER BY id DESC LIMIT 1");
            $s->execute([$expenseId]);
            $j = json_decode((string)$s->fetchColumn(), true);
            $v = is_array($j) ? ($j['total']['value'] ?? ($j['total'] ?? null)) : null;
            return is_numeric($v) && (float)$v > 0 ? round((float)$v, 2) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private const EXPENSE_MATCH_WINDOW_DAYS = 14; // wider than findExpenseMatch()'s ±3d, same reason

    /**
     * Does the bank line name this vendor? Banks run words together — Vancity writes
     * "POINTOFSALE(THEHOMEDEPOT7043RICHMONDBCCA)" — so whole-word splitting never found
     * "Home Depot" and an exact $199.71 same-day match scored 55% (2026-10-13). Compares
     * letters and digits only: the whole vendor name, or any vendor word of 4+ letters.
     */
    public static function vendorInDescription(string $vendor, string $description): bool
    {
        $compact = static fn(string $x): string => (string)preg_replace('/[^a-z0-9]+/', '', strtolower($x));
        $desc = $compact($description);
        $whole = $compact($vendor);
        if ($desc === '' || strlen($whole) < 4) return false;
        if (strpos($desc, $whole) !== false) return true;
        foreach (preg_split('/[^a-z0-9]+/', strtolower($vendor)) as $w) {
            if (strlen($w) >= 4 && !in_array($w, ['store', 'shop', 'company', 'canada', 'limited'], true)
                && strpos($desc, $w) !== false) return true;
        }
        return false;
    }

    /**
     * Score an expense against a bank transaction for manual-match candidates.
     * Same signal as findExpenseMatch() (amount, date proximity, vendor/description
     * overlap) but with wider tolerances and a ranked reasons[] list for display,
     * since a human reviews before confirming rather than a silent auto-commit.
     *
     * @return array{confidence:int, reasons:string[]}|null
     */
    private function scoreExpenseTransactionPair(array $expense, array $transaction, ?array $stop = null): ?array
    {
        $amt = self::amountScore((float)$expense['total'], (float)$transaction['amount'],
                                 isset($expense['penny_total']) ? (float)$expense['penny_total'] : null);
        if ($amt === null) return null; // amounts unrelated
        [$score, $reasons] = $amt;

        $dayDiff = (int) round(
            abs(strtotime((string)$transaction['transaction_date']) - strtotime((string)$expense['expense_date'])) / 86400
        );
        if ($dayDiff === 0)      { $score += 20; $reasons[] = 'Same day'; }
        elseif ($dayDiff <= 1)   { $score += 15; }
        elseif ($dayDiff <= 3)   { $score += 10; }
        elseif ($dayDiff <= 7)   { $score += 5;  $reasons[] = 'Within a week'; }
        elseif ($dayDiff <= self::EXPENSE_MATCH_WINDOW_DAYS) { $score += 2; }
        else return null; // beyond the manual-review window

        if (self::vendorInDescription((string)($expense['vendor_name_raw'] ?? ''), (string)$transaction['description'])) {
            $score += 20;
            $reasons[] = 'Vendor name matches statement description';
        }
        // Otto saw the crew at the place that day (Tim, 2026-10-15: "time, GPS confirmation and date should add strength").
        if ($o = self::ottoStopBonus($stop, (string)$transaction['transaction_date'])) {
            $score += $o['points'];
            $reasons[] = $o['reason'];
        }

        $score = min(100, $score);
        if ($score < self::EXPENSE_MATCH_THRESHOLD) return null;
        return ['confidence' => $score, 'reasons' => $reasons];
    }

    /**
     * Otto's GPS evidence for a receipt's bank line: the crew were at the place (SupplierStopService),
     * and the receipt is tied to that stop by the time printed on it (strongest), the photo's time, or
     * only the date. Counts when the bank line is dated the stop's day or up to 3 days after (card
     * payments post late); never for a line dated before the stop.
     * @return array{points: int, reason: string}|null
     */
    public static function ottoStopBonus(?array $stop, string $txDate): ?array
    {
        if (!$stop || empty($stop['arrived_at']) || $txDate === '') return null;
        $lag = (int)round((strtotime($txDate) - strtotime(substr((string)$stop['arrived_at'], 0, 10))) / 86400);
        if ($lag < 0 || $lag > 3) return null;
        $points = ['printed' => 20, 'photo' => 15][$stop['basis'] ?? ''] ?? 8;
        $who = trim(preg_replace('/\s*\(on the clock\)$/', '', (string)($stop['people'] ?? ''))) ?: 'The crew';
        $at = date('G:i', strtotime((string)$stop['arrived_at']));
        $how = ['printed' => 'at the time printed on the receipt', 'photo' => 'when the receipt photo was taken'][$stop['basis'] ?? ''] ?? 'that day';
        return ['points' => $points, 'reason' => 'GPS: ' . $who . ' at ' . trim((string)$stop['place_name']) . ' ' . $at . ' — ' . $how];
    }

    /** Otto's stop for a receipt, or null (never fails a match). */
    private function stopForExpense(int $expenseId): ?array
    {
        try {
            require_once APP_ROOT . '/Modules/Operations/Services/SupplierStopService.php';
            return (new SupplierStopService($this->db))->stopForExpense($expenseId);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Candidate bank transactions for a single expense — "Find Match" from the
     * Edit Expense modal. Only offers transactions not already claimed by another
     * expense (mirrors findExpenseMatch()'s bt.id IS NULL guard).
     */
    public function candidateTransactionsForExpense(int $expenseId, int $limit = 8, ?float $total = null, ?string $date = null): array
    {
        $stmt = $this->db->prepare("
            SELECT e.id, e.expense_date, e.total, e.vendor_name_raw, v.name AS vendor_name
            FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?
        ");
        $stmt->execute([$expenseId]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$expense) return [];
        // What the Edit Expense form shows right now, before Save — a receipt saved without its
        // PST ($196.08) matched its $199.71 bank line as "within 2%" (2026-10-13).
        if ($total !== null && $total > 0) $expense['total'] = round($total, 2);
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $expense['expense_date'] = $date;
        // Penny's read of the PRINTED total, when she disagrees with the saved one (a scanner misread):
        // the bank line for the printed amount is the exact match (Tim, 2026-10-19: Chevron $14.88 printed,
        // saved $14.69 — the $14.69 line was shown as "exact", the right $14.88 line as "close").
        $penny = $this->pennyTotal($expenseId);
        if ($penny !== null && abs($penny - (float)$expense['total']) >= 0.01) $expense['penny_total'] = $penny;
        $alt = $expense['penny_total'] ?? $expense['total'];

        $stmt = $this->db->prepare("
            SELECT at.id, at.transaction_date, at.description, at.amount, at.bank_account
            FROM accounting_transactions at
            WHERE at.type = 'expense'
              AND at.reference_type = 'bank_import'
              AND at.matched_expense_id IS NULL
              AND " . self::NOT_STRIPE_FEE_SQL . "
              AND (ABS(at.amount - ?) <= GREATEST(0.5, ? * 0.02) OR ABS(at.amount - ?) <= GREATEST(0.5, ? * 0.02))
              AND at.transaction_date BETWEEN DATE_SUB(?, INTERVAL " . self::EXPENSE_MATCH_WINDOW_DAYS . " DAY)
                                          AND DATE_ADD(?, INTERVAL " . self::EXPENSE_MATCH_WINDOW_DAYS . " DAY)
            ORDER BY ABS(DATEDIFF(at.transaction_date, ?)) ASC
            LIMIT 50
        ");
        $stmt->execute([
            $expense['total'], $expense['total'], $alt, $alt,
            $expense['expense_date'], $expense['expense_date'],
            $expense['expense_date'],
        ]);

        $out = [];
        $stop = $this->stopForExpense($expenseId);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
            $score = $this->scoreExpenseTransactionPair($expense, $tx, $stop);
            if ($score === null) continue;
            $out[] = [
                'transaction_id' => (int)$tx['id'],
                'date'           => substr((string)$tx['transaction_date'], 0, 10),
                'description'    => (string)$tx['description'],
                'bank_account'   => (string)($tx['bank_account'] ?? ''),
                'amount'         => round((float)$tx['amount'], 2),
                'confidence'     => $score['confidence'],
                'reasons'        => $score['reasons'],
            ];
        }
        usort($out, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        $out = $this->receiptFactsBoost($out, null, $expenseId);
        $out = array_slice($out, 0, $limit);
        // Below them: the same vendor's lines at ANY amount within ±VENDOR_WINDOW_DAYS — a misread total or date
        // (handwritten 8 read as 3: Champion $184.40 vs the receipt's $134.40, Tim 2026-10-15). Never above an amount match.
        return array_merge($out, $this->sameVendorLines($expense, array_column($out, 'transaction_id')));
    }

    public const VENDOR_WINDOW_DAYS = 30;

    /** Do a receipt total and a bank line agree (exact, or within 2% / 50¢ — the candidates' own rule)? Pure. */
    public static function amountsAgree(float $total, float $line): bool
    {
        return abs(round($total, 2) - round($line, 2)) <= max(0.5, round($total, 2) * 0.02) + 0.001;
    }
    public const VENDOR_LINES_MAX = 3;

    /** Unmatched bank lines naming the receipt's vendor, any amount, nearest date first. */
    private function sameVendorLines(array $expense, array $exclude): array
    {
        $names = array_values(array_filter([trim((string)($expense['vendor_name'] ?? '')), trim((string)($expense['vendor_name_raw'] ?? ''))]));
        if (!$names) return [];
        // Narrow in SQL by the vendor's words (vendorInDescription confirms each in PHP), so no cap can hide a match.
        $words = [];
        foreach ($names as $n) {
            foreach (preg_split('/[^a-z0-9]+/', strtolower($n)) as $w) {
                if (strlen($w) >= 4 && !in_array($w, ['store', 'shop', 'company', 'canada', 'limited'], true)) $words[$w] = true;
            }
        }
        if (!$words) return [];
        $like = implode(' OR ', array_fill(0, count($words), 'LOWER(at.description) LIKE ?'));
        $s = $this->db->prepare("
            SELECT at.id, at.transaction_date, at.description, at.amount, at.bank_account
            FROM accounting_transactions at
            WHERE at.type = 'expense' AND at.reference_type = 'bank_import' AND at.matched_expense_id IS NULL
              AND " . self::NOT_STRIPE_FEE_SQL . "
              AND at.transaction_date BETWEEN DATE_SUB(?, INTERVAL " . self::VENDOR_WINDOW_DAYS . " DAY) AND DATE_ADD(?, INTERVAL " . self::VENDOR_WINDOW_DAYS . " DAY)
              AND ({$like})
            ORDER BY ABS(DATEDIFF(at.transaction_date, ?)) ASC
            LIMIT 50
        ");
        $s->execute(array_merge([$expense['expense_date'], $expense['expense_date']], array_map(fn($w) => '%' . $w . '%', array_keys($words)), [$expense['expense_date']]));
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $tx) {
            if (in_array((int)$tx['id'], $exclude, true)) continue;
            $hit = false;
            foreach ($names as $n) if (self::vendorInDescription($n, (string)$tx['description'])) { $hit = true; break; }
            if (!$hit) continue;
            $out[] = self::vendorLine($expense, $tx);
            if (count($out) >= self::VENDOR_LINES_MAX) break;
        }
        return $out;
    }

    /** One same-vendor candidate, shaped like the others; low confidence, says what to check. Pure. */
    public static function vendorLine(array $expense, array $tx): array
    {
        $days = (int)round(abs(strtotime((string)$tx['transaction_date']) - strtotime((string)$expense['expense_date'])) / 86400);
        $amt = round((float)$tx['amount'], 2);
        $same = abs($amt - round((float)$expense['total'], 2)) < 0.01;
        $reasons = ['Same vendor'];
        $reasons[] = $same ? 'Same amount, ' . $days . ' day' . ($days === 1 ? '' : 's') . ' apart — check the date'
                           : 'Different amount ($' . number_format($amt, 2) . ' vs $' . number_format((float)$expense['total'], 2) . ') — check the total';
        return [
            'transaction_id' => (int)$tx['id'],
            'date'           => substr((string)$tx['transaction_date'], 0, 10),
            'description'    => (string)$tx['description'],
            'bank_account'   => (string)($tx['bank_account'] ?? ''),
            'amount'         => $amt,
            'confidence'     => max(10, 30 - $days),
            'reasons'        => $reasons,
            'vendor_only'    => true,
        ];
    }

    /**
     * Candidate expenses for a single unmatched bank transaction — "Find Expense
     * Match" from the transactions list. Deliberately includes draft/pending_approval
     * expenses, not just approved/forwarded like findExpenseMatch() — a human is
     * confirming the link here, and an unapproved-at-import-time receipt is exactly
     * the gap the automatic matcher can never recover from. Attaching does not
     * change the expense's own approval status.
     */
    public function candidateExpensesForTransaction(int $transactionId, int $limit = 8): array
    {
        $stmt = $this->db->prepare("
            SELECT id, transaction_date, description, amount, matched_expense_id, payment_reference
            FROM accounting_transactions
            WHERE id = ? AND type = 'expense' AND reference_type = 'bank_import'
        ");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transaction || $transaction['matched_expense_id']) return [];
        if (self::isStripeFeeLine((string)$transaction['description'], $transaction['payment_reference'] ?? null)) return [];

        $stmt = $this->db->prepare("
            SELECT e.id, e.expense_date, e.total, e.vendor_name_raw, e.status, e.accounting_category
            FROM expenses e
            LEFT JOIN accounting_transactions bt ON bt.matched_expense_id = e.id
            WHERE e.status IN ('draft','pending_approval','approved','forwarded')
              AND bt.id IS NULL
              AND NOT " . BankLineReceipts::linkedSql($this->db, 'e.id') . "
              AND ABS(e.total - ?) <= GREATEST(0.5, ? * 0.02)
              AND e.expense_date BETWEEN DATE_SUB(?, INTERVAL " . self::EXPENSE_MATCH_WINDOW_DAYS . " DAY)
                                     AND DATE_ADD(?, INTERVAL " . self::EXPENSE_MATCH_WINDOW_DAYS . " DAY)
            ORDER BY ABS(DATEDIFF(e.expense_date, ?)) ASC
            LIMIT 50
        ");
        $stmt->execute([
            $transaction['amount'], $transaction['amount'],
            $transaction['transaction_date'], $transaction['transaction_date'],
            $transaction['transaction_date'],
        ]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $exp) {
            $score = $this->scoreExpenseTransactionPair($exp, $transaction);
            if ($score === null) continue;
            $out[] = [
                'expense_id' => (int)$exp['id'],
                'date'       => substr((string)$exp['expense_date'], 0, 10),
                'vendor'     => (string)($exp['vendor_name_raw'] ?: 'Unknown vendor'),
                'category'   => (string)($exp['accounting_category'] ?? ''),
                'amount'     => round((float)$exp['total'], 2),
                'status'     => (string)$exp['status'],
                'confidence' => $score['confidence'],
                'reasons'    => $score['reasons'],
            ];
        }
        usort($out, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        $out = $this->receiptFactsBoost($out, $transactionId, null);
        return array_slice($out, 0, $limit);
    }

    /**
     * Printed receipt facts (card last 4, printed date / time — receipt_facts, migration 1227)
     * raise a candidate's confidence and say why. Suggestions only: nothing is matched here.
     */
    private function receiptFactsBoost(array $rows, ?int $transactionId, ?int $expenseId): array
    {
        try {
            require_once dirname(__DIR__, 2) . '/Expenses/Services/ReceiptFactsService.php';
            return (new ReceiptFactsService($this->db))->boostBankCandidates($rows, $transactionId, $expenseId);
        } catch (Throwable $e) {
            return $rows;
        }
    }

    /**
     * Manually attach a bank transaction to an expense — the human-confirmed
     * counterpart to findExpenseMatch()'s silent auto-match at import time.
     * Does not touch the expense's own status (draft/approved/etc.) — purely
     * records the bookkeeping link between the two rows.
     */
    public function attachExpenseMatch(int $transactionId, int $expenseId, int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, matched_expense_id, transaction_date, description, amount
            FROM accounting_transactions
            WHERE id = ? AND type = 'expense' AND reference_type = 'bank_import'
        ");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transaction) throw new RuntimeException('Bank transaction not found.');
        if ($transaction['matched_expense_id']) throw new RuntimeException('Transaction is already matched to an expense.');

        $stmt = $this->db->prepare("SELECT id, expense_date, total, vendor_name_raw FROM expenses WHERE id = ?");
        $stmt->execute([$expenseId]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$expense) throw new RuntimeException('Expense not found.');
        // A bank line is the same purchase only when the amounts agree (within the candidates' 2%): a same-vendor
        // line at another amount means the receipt's total is misread — fix and save it first (2026-10-15).
        if (!self::amountsAgree((float)$expense['total'], (float)$transaction['amount'])) {
            throw new RuntimeException('The receipt says $' . number_format((float)$expense['total'], 2) . ' and the bank line $'
                . number_format((float)$transaction['amount'], 2) . ' — correct the receipt total and save it first.');
        }

        if ((new BankLineReceipts($this->db))->lineFor($expenseId) !== null) throw new RuntimeException('This expense is already matched to another transaction.');

        // Recorded for reporting even though the user picked it manually — falls
        // back to 0 if the pair is outside the normal scoring window (a deliberate
        // human override of what the candidate list would have suggested).
        $score = $this->scoreExpenseTransactionPair($expense, $transaction, $this->stopForExpense($expenseId));
        $confidence = $score['confidence'] ?? 0;

        $this->db->prepare("
            UPDATE accounting_transactions SET
              status             = 'reconciled',
              matched_expense_id = ?,
              match_confidence   = ?,
              matched_at         = NOW(),
              matched_by         = ?
            WHERE id = ?
        ")->execute([$expenseId, $confidence, (string)$userId, $transactionId]);

        // A person linked this receipt (head_lessons, migration 1348): what Penny's scoring made of the pair.
        if ($userId > 0) {
            require_once __DIR__ . '/PennyLessons.php';
            PennyLessons::bankLine($this->db, $transactionId, 'receipt_link',
                $confidence > 0 ? 'possible match (' . (int)$confidence . '%)' : 'no match', 'receipt #' . $expenseId,
                'receipt_link', $userId);
        }

        // The bank line is the truth about where the money came from (Vital Auto #384, 2026-10-12).
        $source = $this->checkReceiptFunding($expenseId, $transactionId, $userId);

        return ['transaction_id' => $transactionId, 'expense_id' => $expenseId, 'confidence' => $confidence, 'payment_source' => $source];
    }

    /** Every receipt ↔ statement-line link: the receipt's payment method follows the line's account. */
    private function checkReceiptFunding(int $expenseId, int $transactionId, ?int $userId): array
    {
        try {
            require_once __DIR__ . '/ReceiptFundingCheck.php';
            return (new ReceiptFundingCheck($this->db))->onLink($expenseId, $transactionId, $userId ?: null);
        } catch (Throwable $e) {
            error_log('Receipt funding check: ' . $e->getMessage());
            return ['action' => 'error'];
        }
    }

    /**
     * Reverse a manual (or automatic) expense match — restores the transaction to
     * 'cleared'/unmatched so it can be re-matched or matched to a different expense.
     */
    public function detachExpenseMatch(int $transactionId, int $userId): array
    {
        $stmt = $this->db->prepare("SELECT id, matched_expense_id FROM accounting_transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transaction || !$transaction['matched_expense_id']) {
            throw new RuntimeException('Transaction is not currently matched.');
        }
        // Several receipts on this line (migration 1372): unmatching the line unmatches them all — in one change.
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
        $extras = (new BankLineReceipts($this->db))->detachExtras($transactionId);

        $this->db->prepare("
            UPDATE accounting_transactions SET
              status             = 'cleared',
              matched_expense_id = NULL,
              match_confidence   = NULL,
              matched_at         = NULL,
              matched_by         = NULL
            WHERE id = ?
        ")->execute([$transactionId]);
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        return ['transaction_id' => $transactionId, 'expense_id' => (int)$transaction['matched_expense_id'], 'also_unmatched' => $extras];
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
    // SELF-LEARNING — the description key rules are learned on (BankRuleLearning;
    // only the owner's decisions teach — an import never does, since 2026-10-07)
    // ══════════════════════════════════════════════════════════════════════════

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
        return self::descriptionKey($desc);
    }

    /** The bank-agnostic rule key for a description (shared with BankRuleLearning). */
    public static function descriptionKey(string $desc): string
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
