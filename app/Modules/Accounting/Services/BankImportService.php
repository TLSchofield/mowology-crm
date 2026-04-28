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

    // Known bank CSV presets — column mapping and skip-header-rows count
    private const BANK_PRESETS = [
        'td'        => ['name' => 'TD Bank',    'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'rbc'       => ['name' => 'RBC',         'date' => 2, 'description' => 4, 'debit' => 6, 'credit' => 7, 'skip' => 1],
        'bmo'       => ['name' => 'BMO',         'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'cibc'      => ['name' => 'CIBC',        'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'scotiabank'=> ['name' => 'Scotiabank',  'date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'skip' => 1],
        'generic'   => ['name' => 'Generic',     'date' => 0, 'description' => 1, 'amount' => 2,               'skip' => 1],
    ];

    // Default fallback accounts when no rule matches
    private const DEFAULT_INCOME_CODE  = '4900';
    private const DEFAULT_EXPENSE_CODE = '6900';

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
    public function preview(string $content, array $mapping, int $skipRows = 1, string $bankName = ''): array
    {
        require_once __DIR__ . '/RulesEngine.php';
        $engine = new RulesEngine($this->db);

        $rows   = $this->parseCSV($content, $mapping, $skipRows);
        $totals = ['income' => 0, 'expense' => 0, 'duplicates' => 0, 'rows' => count($rows)];

        // Load account IDs for defaults
        $defaultIncomeId  = $this->getAccountId(self::DEFAULT_INCOME_CODE);
        $defaultExpenseId = $this->getAccountId(self::DEFAULT_EXPENSE_CODE);

        foreach ($rows as &$row) {
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

            // Duplicate detection
            $dupCheck = $this->checkDuplicate($row['date'], $row['amount'], $row['type']);
            $row['is_duplicate']   = $dupCheck !== null;
            $row['duplicate_tx_id'] = $dupCheck;

            if ($row['is_duplicate']) $totals['duplicates']++;
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
        bool $skipDuplicates = true
    ): array {

        $sessionId = $this->createSession([
            'filename'      => $bankName ?: 'bank_import',
            'bank_name'     => $bankName,
            'account_name'  => $accountName,
            'row_count'     => count($rows),
            'created_by'    => $userId,
        ]);

        $imported  = 0;
        $skipped   = 0;
        $dupes     = 0;

        $this->db->beginTransaction();
        try {
            $txStmt = $this->db->prepare("
                INSERT INTO accounting_transactions
                    (transaction_date, type, account_id, amount, gst_amount, pst_amount,
                     description, reference_type, status, is_auto_categorized, rule_id,
                     bank_account, import_session_id, created_by)
                VALUES (?, ?, ?, ?, 0, 0, ?, 'bank_import', 'cleared', ?, ?, ?, ?, ?)
            ");

            $rowStmt = $this->db->prepare("
                INSERT INTO bank_import_rows
                    (session_id, transaction_date, description, raw_amount, type, amount,
                     account_id, transaction_id, is_duplicate, duplicate_of_id, rule_id, raw_row)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($rows as $row) {
                if ($skipDuplicates && $row['is_duplicate']) {
                    $dupes++;
                    // Still log the row in staging as a skipped duplicate
                    $rowStmt->execute([
                        $sessionId, $row['date'], $row['description'],
                        $row['type'] === 'expense' ? -$row['amount'] : $row['amount'],
                        $row['type'], $row['amount'],
                        $row['account_id'], null, 1, $row['duplicate_tx_id'],
                        $row['rule_id'] ?? null, json_encode($row),
                    ]);
                    continue;
                }

                // Insert the transaction
                $txStmt->execute([
                    $row['date'],
                    $row['type'],
                    $row['account_id'],
                    $row['amount'],
                    $row['description'],
                    $row['auto_cat'] ? 1 : 0,
                    $row['rule_id'] ?? null,
                    $accountName,
                    $sessionId,
                    $userId,
                ]);

                $txId = (int)$this->db->lastInsertId();

                // Log the staged row
                $rowStmt->execute([
                    $sessionId, $row['date'], $row['description'],
                    $row['type'] === 'expense' ? -$row['amount'] : $row['amount'],
                    $row['type'], $row['amount'],
                    $row['account_id'], $txId, 0, null,
                    $row['rule_id'] ?? null, json_encode($row),
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

        return [
            'session_id' => $sessionId,
            'imported'   => $imported,
            'skipped'    => $skipped,
            'duplicates' => $dupes,
        ];
    }

    /**
     * Rollback an import session — deletes all transactions created in that session.
     */
    public function rollback(int $sessionId): int
    {
        $stmt = $this->db->prepare("
            DELETE t FROM accounting_transactions t
            JOIN bank_import_rows r ON r.transaction_id = t.id
            WHERE r.session_id = ? AND t.reference_type = 'bank_import'
        ");
        $stmt->execute([$sessionId]);
        $deleted = $stmt->rowCount();

        $this->db->prepare("UPDATE bank_import_sessions SET status = 'rolled_back' WHERE id = ?")
                 ->execute([$sessionId]);

        return $deleted;
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
     */
    public function previewPdf(string $filePath, string $bankName = ''): array
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
        // ToUnicode map. smalot returns garbled high-byte characters instead of
        // readable text. Detect this by measuring the non-ASCII ratio and fall
        // back to pdftotext (poppler) which handles font encoding natively.
        if ($this->isGarbledText($text)) {
            $text = $this->extractTextViaPdftotext($filePath);
        }

        $rows = $this->parsePdfText($text);

        if (empty($rows)) {
            throw new RuntimeException('No transactions could be extracted from this PDF. The format may not be supported — try exporting a CSV from your bank instead.');
        }

        // Run same enrichment as CSV preview (categorization + duplicate detection)
        require_once __DIR__ . '/RulesEngine.php';
        $engine = new RulesEngine($this->db);

        $defaultIncomeId  = $this->getAccountId(self::DEFAULT_INCOME_CODE);
        $defaultExpenseId = $this->getAccountId(self::DEFAULT_EXPENSE_CODE);
        $totals = ['income' => 0.0, 'expense' => 0.0, 'duplicates' => 0, 'rows' => count($rows)];

        foreach ($rows as &$row) {
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

            $dupCheck = $this->checkDuplicate($row['date'], $row['amount'], $row['type']);
            $row['is_duplicate']    = $dupCheck !== null;
            $row['duplicate_tx_id'] = $dupCheck;

            if ($row['is_duplicate'])       $totals['duplicates']++;
            if ($row['type'] === 'income')  $totals['income']  += $row['amount'];
            if ($row['type'] === 'expense') $totals['expense'] += $row['amount'];
        }
        unset($row);

        return [
            'rows'   => $rows,
            'totals' => $totals,
            'bank'   => $bankName,
            'source' => 'pdf',
        ];
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
    private function parsePdfText(string $text): array
    {
        $rows  = [];
        $lines = preg_split('/\r?\n/', $text);

        // Regex: date (various formats) + description + amount(s) at end of line
        // Handles: Jan 15, 15 Jan, 01/15, 01-15, 2024-01-15, etc.
        $datePatterns = [
            // Month-name day (no year) — Jan 15, Jan. 15
            '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+\d{1,2}',
            // Day Month-name — 15 Jan, 15MAR, 15 MAR (Vancity omits space inconsistently)
            '\d{1,2}\s*(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?',
            // MM/DD or MM-DD
            '\d{1,2}[\/\-]\d{1,2}',
            // YYYY-MM-DD
            '\d{4}-\d{2}-\d{2}',
            // MM/DD/YYYY or DD/MM/YYYY
            '\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}',
        ];
        $datePat = '(' . implode('|', $datePatterns) . ')';
        $dateStartPat = '/^' . $datePat . '\s+/i';

        // Amount pattern: optional sign or CR/DR, digits with optional comma-separators and decimal
        $amtPat = '([\-\+]?\$?\s*[\d,]+\.\d{2})';

        // Full line pattern: date | description (anything) | one or two amounts
        $pattern = '/^' . $datePat . '\s+(.+?)\s+' . $amtPat . '(?:\s+' . $amtPat . ')?$/i';

        // Detect year from surrounding text (statement year)
        $statementYear = date('Y');
        if (preg_match('/\b(20\d{2})\b/', $text, $ym)) {
            $statementYear = $ym[1];
        }

        // Join wrapped lines: some banks (Vancity, BMO) wrap long descriptions onto
        // the next line. The continuation line doesn't start with a date — merge it
        // with the preceding line so the full row can be parsed in one pass.
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
            } else {
                $buf = $buf !== '' ? rtrim($buf) . ' ' . $l : $l;
            }
        }
        if ($buf !== '') $joined[] = $buf;
        $lines = $joined;

        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) < 10) continue;

            if (!preg_match($pattern, $line, $m)) continue;

            $dateRaw = trim($m[1]);
            $desc    = trim($m[2]);
            $amt1Raw = trim($m[3]);
            $amt2Raw = isset($m[4]) ? trim($m[4]) : '';

            // Skip lines that look like balance/total rows
            $descLower = strtolower($desc);
            if (preg_match('/\b(balance|total|opening|closing|brought forward|carried forward)\b/', $descLower)) continue;
            if (strlen($desc) < 3) continue;

            // Parse date — append statement year if no year in date string
            $dateStr = $dateRaw;
            if (!preg_match('/\d{4}/', $dateRaw)) {
                $dateStr = $dateRaw . ' ' . $statementYear;
            }
            $date = $this->parseDate($dateStr);
            if (!$date) continue;

            // Determine type and amount
            // If two amounts: first is debit (expense), second is credit (income), ignore balance col
            $type   = null;
            $amount = null;

            if ($amt2Raw !== '') {
                // Two amount columns: either (debit | credit) or (transaction | running-balance).
                // Running-balance detection: if amt2 is much larger than amt1, it's a balance
                // column (Vancity / credit-union 3-column format: withdrawal | deposit | balance).
                $a1 = $this->parseNumber($amt1Raw);
                $a2 = $this->parseNumber($amt2Raw);
                $isCredit = (bool)preg_match('/\bCR\b|DEPOSIT|CREDIT|PAYROLL|SALARY|TRANSFER IN|REFUND/i', $desc);
                if ($a1 !== null && $a1 > 0 && ($a2 === null || $a2 > $a1 * 5)) {
                    // a2 is a running balance — a1 is the transaction amount
                    $type   = $isCredit ? 'income' : 'expense';
                    $amount = $a1;
                } elseif ($a2 !== null && $a2 > 0) {
                    $type   = 'income';
                    $amount = $a2;
                } elseif ($a1 !== null && $a1 > 0) {
                    $type   = $isCredit ? 'income' : 'expense';
                    $amount = $a1;
                }
            } else {
                $raw = $this->parseNumber($amt1Raw);
                if ($raw === null || $raw == 0) continue;

                // Sign-based: negative = expense, positive = income
                // But PDF bank statements often show all amounts as positive with
                // CR/DR markers or description keywords
                $isCredit = preg_match('/\bCR\b|DEPOSIT|CREDIT|PAYROLL|SALARY|TRANSFER IN|REFUND/i', $desc);
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

            if ($type === null || $amount === null || $amount <= 0) continue;

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

        return $rows;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════════

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
        return $out;
    }

    /**
     * Parse a raw CSV string into normalized row arrays.
     * Handles both debit/credit split and single-amount formats.
     */
    private function parseCSV(string $content, array $mapping, int $skipRows): array
    {
        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        $lines   = explode("\n", $content);

        $rows = [];
        $i    = 0;
        foreach ($lines as $line) {
            if ($i++ < $skipRows) continue;
            $line = trim($line);
            if ($line === '') continue;

            // Parse CSV line (handles quoted fields with commas inside)
            $cols = str_getcsv($line, ',', '"', '');

            // Determine amount and type
            $amount = null;
            $type   = null;

            if (isset($mapping['amount'])) {
                // Single-amount column: positive=income, negative=expense
                $raw    = $this->parseNumber($cols[$mapping['amount']] ?? '');
                if ($raw === null) continue;
                $type   = $raw >= 0 ? 'income' : 'expense';
                $amount = abs($raw);
            } elseif (isset($mapping['debit']) && isset($mapping['credit'])) {
                $debit  = $this->parseNumber($cols[$mapping['debit']]  ?? '');
                $credit = $this->parseNumber($cols[$mapping['credit']] ?? '');

                if ($debit !== null && $debit > 0) {
                    $type   = 'expense';
                    $amount = $debit;
                } elseif ($credit !== null && $credit > 0) {
                    $type   = 'income';
                    $amount = $credit;
                } else {
                    continue; // Zero row — skip
                }
            } else {
                continue;
            }

            if ($amount <= 0) continue;

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

            $rows[] = [
                'date'        => $date,
                'description' => $desc,
                'amount'      => round($amount, 2),
                'type'        => $type,
                'raw_line'    => $line,
                // These will be set by preview():
                'account_id'  => null,
                'account_name'=> null,
                'account_code'=> null,
                'rule_id'     => null,
                'auto_cat'    => false,
                'is_duplicate'=> false,
                'duplicate_tx_id' => null,
            ];
        }

        return $rows;
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
     * Check if a transaction already exists in the ledger.
     * Matches on date ± 1 day + same amount + same type.
     * Returns the matching transaction id, or null if no duplicate found.
     */
    private function checkDuplicate(string $date, float $amount, string $type): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id FROM accounting_transactions
            WHERE type = ?
              AND ABS(amount - ?) < 0.01
              AND ABS(DATEDIFF(transaction_date, ?)) <= 1
            LIMIT 1
        ");
        $stmt->execute([$type, $amount, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    private function getAccountId(string $code): int
    {
        $stmt = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        return (int)$stmt->fetchColumn();
    }

    private function createSession(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bank_import_sessions (filename, bank_name, account_name, row_count, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['filename'],
            $data['bank_name'] ?? null,
            $data['account_name'] ?? null,
            $data['row_count'],
            $data['created_by'],
        ]);
        return (int)$this->db->lastInsertId();
    }
}
