<?php
/**
 * LedgerService — true double-entry posting engine (Phase 1).
 *
 * Authoritative journal layer that sits alongside the legacy single-entry
 * accounting_transactions table. Every economic event becomes one balanced
 * journal_entries header + N journal_lines (debit XOR credit per line,
 * SUM(debit) == SUM(credit) per entry).
 *
 * Step 1 scope: postEntry() + validation invariant + period-lock guard +
 * idempotency by (source_type, source_id). Posting recipes (postInvoice, etc.)
 * are layered on in Step 2.
 *
 * Amounts: debit/credit are non-negative; direction is the column, not a sign.
 */
declare(strict_types=1);

class LedgerService
{
    private PDO $db;

    /** Balancing tolerance — entries must balance to within half a cent. */
    private const BALANCE_TOLERANCE = 0.005;

    /** Canonical account codes used by posting recipes. */
    public const ACC_BANK            = '1010'; // Chequing
    public const ACC_AR              = '1100'; // Accounts Receivable
    public const ACC_DUE_FROM_SH     = '1300'; // Due from Shareholder
    public const ACC_AP              = '2100'; // Accounts Payable
    public const ACC_GST_COLLECTED   = '2200'; // GST/HST Collected
    public const ACC_GST_ITC         = '2210'; // GST/HST Input Tax Credits
    public const ACC_PST_PAYABLE     = '2300'; // PST Payable
    public const ACC_CREDIT_CARD     = '2400'; // Credit Card Payable
    public const ACC_OWNER_CAPITAL   = '3100'; // Owner's Capital
    public const ACC_RETAINED        = '3200'; // Retained Earnings
    public const ACC_OWNER_DRAW      = '3300'; // Owner's Draw
    public const ACC_OPENING_EQUITY  = '3900'; // Opening Balance Equity

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // VALIDATION (pure — no DB) — the core integrity guarantee
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Validate a journal entry's structure and balance. Throws on any violation.
     *
     * Rules:
     *  - at least 2 lines
     *  - every line has a positive integer account_id
     *  - debit >= 0 and credit >= 0
     *  - each line has exactly one of debit/credit non-zero (debit XOR credit)
     *  - SUM(debit) == SUM(credit) within BALANCE_TOLERANCE, and total > 0
     *
     * @throws InvalidArgumentException
     */
    public function validateEntry(array $entry): void
    {
        $lines = $entry['lines'] ?? [];
        if (!is_array($lines) || count($lines) < 2) {
            throw new InvalidArgumentException('Journal entry must have at least 2 lines.');
        }

        $sumDebit = 0.0;
        $sumCredit = 0.0;

        foreach ($lines as $i => $line) {
            $accountId = $line['account_id'] ?? null;
            if (!is_int($accountId) && !(is_numeric($accountId) && (int)$accountId > 0)) {
                throw new InvalidArgumentException("Line $i: account_id is required and must be a positive integer.");
            }

            $debit  = round((float)($line['debit']  ?? 0), 2);
            $credit = round((float)($line['credit'] ?? 0), 2);

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException("Line $i: debit and credit must be non-negative.");
            }
            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException("Line $i: a line cannot have both a debit and a credit.");
            }
            if ($debit === 0.0 && $credit === 0.0) {
                throw new InvalidArgumentException("Line $i: a line must have either a debit or a credit.");
            }

            $sumDebit  += $debit;
            $sumCredit += $credit;
        }

        if ($sumDebit <= 0.0) {
            throw new InvalidArgumentException('Journal entry total must be greater than zero.');
        }
        if (abs($sumDebit - $sumCredit) > self::BALANCE_TOLERANCE) {
            throw new InvalidArgumentException(sprintf(
                'Journal entry does not balance: debits %.2f vs credits %.2f.',
                $sumDebit, $sumCredit
            ));
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POSTING
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Post a balanced journal entry. Returns the journal_entries.id.
     *
     * Idempotent: when source_type != 'manual' and source_id is set, a second
     * post for the same (source_type, source_id) returns the existing id without
     * re-inserting.
     *
     * @throws InvalidArgumentException on an unbalanced/invalid entry
     * @throws RuntimeException         when the target period is locked
     */
    public function postEntry(array $entry): int
    {
        $this->validateEntry($entry);

        $entryDate  = $entry['entry_date'] ?? date('Y-m-d');
        $sourceType = $entry['source_type'] ?? 'manual';
        $sourceId   = $entry['source_id'] ?? null;

        // Idempotency: skip if this source already posted.
        if ($sourceType !== 'manual' && $sourceId !== null) {
            $existing = $this->findEntryIdBySource($sourceType, (int)$sourceId);
            if ($existing !== null) {
                return $existing;
            }
        }

        // Period lock: an automatic posting (invoice, receipt, bank line, reversal) for a
        // locked month lands in the current month as a late entry — never inside the
        // locked month. Manual entries into a locked month are refused.
        if ($sourceType !== 'manual' && $this->isLocked($entryDate) && !$this->isLocked(date('Y-m-d'))) {
            $entry['memo'] = 'Late entry for ' . $entryDate . ' — ' . ($entry['memo'] ?? '');
            $entryDate = date('Y-m-d');
        }
        $this->assertPeriodPostable($entryDate);
        $periodId = $this->findPeriodId($entryDate);

        $this->db->beginTransaction();
        try {
            $withProposer = $this->hasColumn('journal_entries', 'proposed_by');
            $hdr = $this->db->prepare(
                "INSERT INTO journal_entries
                    (entry_date, memo, source_type, source_id, period_id, status, is_adjusting, created_by" . ($withProposer ? ', proposed_by' : '') . ")
                 VALUES (?, ?, ?, ?, ?, 'posted', ?, ?" . ($withProposer ? ', ?' : '') . ")"
            );
            $vals = [
                $entryDate,
                $entry['memo'] ?? null,
                $sourceType,
                $sourceId,
                $periodId,
                !empty($entry['is_adjusting']) ? 1 : 0,
                $entry['created_by'] ?? null,
            ];
            if ($withProposer) $vals[] = $entry['proposed_by'] ?? 'system';
            $vals[1] = $entry['memo'] ?? null;
            $hdr->execute($vals);
            $entryId = (int)$this->db->lastInsertId();

            $ln = $this->db->prepare(
                "INSERT INTO journal_lines
                    (entry_id, account_id, debit, credit, gst_amount, pst_amount, description,
                     job_id, contact_id, vendor_id, crew_user_id, cost_type_id, service_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($entry['lines'] as $line) {
                $ln->execute([
                    $entryId,
                    (int)$line['account_id'],
                    round((float)($line['debit']  ?? 0), 2),
                    round((float)($line['credit'] ?? 0), 2),
                    round((float)($line['gst_amount'] ?? 0), 2),
                    round((float)($line['pst_amount'] ?? 0), 2),
                    $line['description']  ?? null,
                    $line['job_id']       ?? null,
                    $line['contact_id']   ?? null,
                    $line['vendor_id']    ?? null,
                    $line['crew_user_id'] ?? null,
                    $line['cost_type_id'] ?? null,
                    $line['service_type'] ?? null,
                ]);
            }

            $this->db->commit();
            return $entryId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POSTING RECIPES — pure builders (no DB) + thin post wrappers
    //
    // Builders return an entry whose lines carry an 'account' CODE (string).
    // post*() resolves codes -> account_id and calls postEntry(). Keeping the
    // accounting logic in the pure builders makes every recipe unit-testable.
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Invoice raised (accrual): DR Accounts Receivable / CR Revenue (+ GST Collected).
     * @param array $inv id, date, net, gst?, revenue_account? (code, default 4900),
     *                   service_type?, contact_id?, job_id?, memo?
     */
    public function buildInvoiceEntry(array $inv): array
    {
        $net = round((float)$inv['net'], 2);
        $gst = round((float)($inv['gst'] ?? 0), 2);
        $total = round($net + $gst, 2);
        $revenue = $inv['revenue_account'] ?? '4900';

        $lines = [];
        $lines[] = [
            'account' => self::ACC_AR, 'debit' => $total, 'credit' => 0,
            'contact_id' => $inv['contact_id'] ?? null, 'job_id' => $inv['job_id'] ?? null,
        ];
        // Revenue: one line, or split across accounts (LedgerAccountMap::invoiceSplits —
        // contract income / per-service accounts). Splits must sum to $net.
        $splits = $inv['revenue_splits'] ?? [[$revenue, $net]];
        foreach ($splits as [$code, $amt]) {
            $lines[] = [
                'account' => $code, 'debit' => 0, 'credit' => round((float)$amt, 2),
                'service_type' => $inv['service_type'] ?? null,
                'contact_id' => $inv['contact_id'] ?? null, 'job_id' => $inv['job_id'] ?? null,
            ];
        }
        if ($gst > 0) {
            $lines[] = ['account' => self::ACC_GST_COLLECTED, 'debit' => 0, 'credit' => $gst, 'gst_amount' => $gst];
        }

        return [
            'entry_date'  => $inv['date'],
            'memo'        => $inv['memo'] ?? ('Invoice #' . ($inv['id'] ?? '')),
            'source_type' => 'invoice',
            'source_id'   => $inv['id'] ?? null,
            'lines'       => $lines,
        ];
    }

    /**
     * Customer payment: DR Bank / CR Accounts Receivable.
     * @param array $pmt id, date, amount, bank_account? (code, default 1010), contact_id?, memo?
     */
    public function buildPaymentEntry(array $pmt): array
    {
        $amount = round((float)$pmt['amount'], 2);
        $bank = $pmt['bank_account'] ?? self::ACC_BANK;

        return [
            'entry_date'  => $pmt['date'],
            'memo'        => $pmt['memo'] ?? ('Payment for invoice #' . ($pmt['invoice_id'] ?? '')),
            'source_type' => 'payment',
            'source_id'   => $pmt['id'] ?? null,
            'lines'       => [
                ['account' => $bank, 'debit' => $amount, 'credit' => 0, 'contact_id' => $pmt['contact_id'] ?? null],
                ['account' => self::ACC_AR, 'debit' => 0, 'credit' => $amount, 'contact_id' => $pmt['contact_id'] ?? null],
            ],
        ];
    }

    /**
     * Expense: DR Expense (+ PST as cost) + DR GST ITC / CR funding account.
     * PST is non-recoverable, so it is rolled into the expense cost, not the ITC.
     * @param array $exp id, date, net, gst?, pst?, expense_account (code),
     *                   funding? (code, default 2400 Credit Card), cost_type_id?,
     *                   service_type?, job_id?, vendor_id?, memo?
     */
    public function buildExpenseEntry(array $exp): array
    {
        if (!empty($exp['allocations']) && is_array($exp['allocations'])) {
            return $this->buildSplitExpenseEntry($exp);
        }
        $net = round((float)$exp['net'], 2);
        $gst = round((float)($exp['gst'] ?? 0), 2);
        $pst = round((float)($exp['pst'] ?? 0), 2);
        $total = round($net + $gst + $pst, 2);
        $funding = $exp['funding'] ?? self::ACC_CREDIT_CARD;

        $lines = [];
        $lines[] = [
            'account' => $exp['expense_account'], 'debit' => round($net + $pst, 2), 'credit' => 0,
            'gst_amount' => $gst, 'pst_amount' => $pst,
            'cost_type_id' => $exp['cost_type_id'] ?? null,
            'service_type' => $exp['service_type'] ?? null,
            'job_id' => $exp['job_id'] ?? null, 'vendor_id' => $exp['vendor_id'] ?? null,
        ];
        if ($gst > 0) {
            $lines[] = ['account' => self::ACC_GST_ITC, 'debit' => $gst, 'credit' => 0, 'gst_amount' => $gst];
        }
        $lines[] = ['account' => $funding, 'debit' => 0, 'credit' => $total, 'vendor_id' => $exp['vendor_id'] ?? null];

        return [
            'entry_date'  => $exp['date'],
            'memo'        => $exp['memo'] ?? ('Expense #' . ($exp['id'] ?? '')),
            'source_type' => 'expense',
            'source_id'   => $exp['id'] ?? null,
            'lines'       => $lines,
        ];
    }

    /**
     * A receipt split by line (ExpenseSplitService, migration 1233): one debit per allocation —
     * its account, its job — carrying net + PST + the GST that can't be claimed (the meals
     * share's GST above the ITC rate), then DR GST ITC for what can be claimed, CR funding for
     * the receipt's total. Lawnboy #412: DR 5200 $80.00 (Oakridge) + DR 5200 $128.40 (stock,
     * PST $8.40 in cost) + DR 2210 $10.00 / CR 2400 $218.40.
     * @param array $exp as buildExpenseEntry + allocations: list<{account, net, gst, pst, job_id?,
     *                   cost_type_id?, label?, meals?: bool}>, meals_itc_rate? (default 0.5)
     */
    public function buildSplitExpenseEntry(array $exp): array
    {
        $rate = isset($exp['meals_itc_rate']) ? (float)$exp['meals_itc_rate'] : 0.5;
        $funding = $exp['funding'] ?? self::ACC_CREDIT_CARD;
        $lines = [];
        $itc = 0.0;
        $total = 0.0;
        foreach ($exp['allocations'] as $a) {
            $net = round((float)$a['net'], 2);
            $gst = round((float)($a['gst'] ?? 0), 2);
            $pst = round((float)($a['pst'] ?? 0), 2);
            $claim = !empty($a['meals']) ? round($gst * $rate, 2) : $gst;
            $itc += $claim;
            $total += $net + $gst + $pst;
            $debit = round($net + $pst + $gst - $claim, 2);
            if (abs($debit) < 0.005) continue;
            $lines[] = [
                // A discount line (negative) is a credit to its account.
                'account' => $a['account'], 'debit' => max(0.0, $debit), 'credit' => max(0.0, -$debit),
                'gst_amount' => $claim, 'pst_amount' => $pst,
                'description' => isset($a['label']) ? mb_substr((string)$a['label'], 0, 255) : null,
                'cost_type_id' => $a['cost_type_id'] ?? null,
                'service_type' => $exp['service_type'] ?? null,
                'job_id' => $a['job_id'] ?? null, 'vendor_id' => $exp['vendor_id'] ?? null,
            ];
        }
        $itc = round($itc, 2);
        if ($itc > 0) {
            $lines[] = ['account' => self::ACC_GST_ITC, 'debit' => $itc, 'credit' => 0, 'gst_amount' => $itc];
        }
        $lines[] = ['account' => $funding, 'debit' => 0, 'credit' => round($total, 2), 'vendor_id' => $exp['vendor_id'] ?? null];

        return [
            'entry_date'  => $exp['date'],
            'memo'        => $exp['memo'] ?? ('Expense #' . ($exp['id'] ?? '') . ' (split by line)'),
            'source_type' => 'expense',
            'source_id'   => $exp['id'] ?? null,
            'lines'       => $lines,
        ];
    }

    /**
     * A split receipt's allocations onto the posting args (when the caller — the nightly sync,
     * the repost runner — didn't bring them). Unchanged when the receipt isn't split.
     */
    public function withAllocations(array $exp): array
    {
        if (array_key_exists('allocations', $exp) || empty($exp['id'])) return $exp;
        try {
            $f = dirname(__DIR__, 2) . '/Expenses/Services/ExpenseSplitService.php';
            if (!class_exists('ExpenseSplitService')) {
                if (!is_file($f)) return $exp;
                require_once $f;
            }
            $split = new ExpenseSplitService($this->db);
            if (!$split->ready()) return $exp;
            $args = $split->ledgerArgs((int)$exp['id'], $exp['vendor_name'] ?? null);
            return $args ? array_merge($exp, $args) : $exp;
        } catch (\Throwable $e) {
            return $exp;   // never block a posting over the split read
        }
    }

    /**
     * Owner draw: DR Owner's Draw (or Due from Shareholder) / CR Bank.
     * @param array $d date, amount, draw_account? (code, default 3300), bank_account? (code, default 1010), memo?
     */
    public function buildOwnerDrawEntry(array $d): array
    {
        $amount = round((float)$d['amount'], 2);
        return [
            'entry_date'  => $d['date'],
            'memo'        => $d['memo'] ?? 'Owner draw',
            'source_type' => $d['source_type'] ?? 'manual',
            'source_id'   => $d['id'] ?? null,
            'lines'       => [
                ['account' => $d['draw_account'] ?? self::ACC_OWNER_DRAW, 'debit' => $amount, 'credit' => 0],
                ['account' => $d['bank_account'] ?? self::ACC_BANK, 'debit' => 0, 'credit' => $amount],
            ],
        ];
    }

    /**
     * Bank transfer: DR destination / CR source.
     * @param array $t date, amount, from (code), to (code), memo?
     */
    public function buildTransferEntry(array $t): array
    {
        $amount = round((float)$t['amount'], 2);
        return [
            'entry_date'  => $t['date'],
            'memo'        => $t['memo'] ?? 'Transfer',
            'source_type' => 'manual',
            'lines'       => [
                ['account' => $t['to'],   'debit' => $amount, 'credit' => 0],
                ['account' => $t['from'], 'debit' => 0, 'credit' => $amount],
            ],
        ];
    }

    /**
     * Opening balances: caller supplies known asset/liability/equity opening
     * lines (each ['account'=>code, 'debit'|'credit'=>x]); the difference is
     * plugged to Opening Balance Equity (3900) so the entry balances.
     * @param array $b date, lines[], memo?
     */
    public function buildOpeningBalanceEntry(array $b): array
    {
        $lines = $b['lines'];
        $sumDebit = 0.0; $sumCredit = 0.0;
        foreach ($lines as $l) {
            $sumDebit  += round((float)($l['debit']  ?? 0), 2);
            $sumCredit += round((float)($l['credit'] ?? 0), 2);
        }
        $diff = round($sumDebit - $sumCredit, 2);
        if (abs($diff) >= 0.005) {
            // plug to Opening Balance Equity to balance
            $lines[] = $diff > 0
                ? ['account' => self::ACC_OPENING_EQUITY, 'debit' => 0, 'credit' => $diff]
                : ['account' => self::ACC_OPENING_EQUITY, 'debit' => -$diff, 'credit' => 0];
        }
        return [
            'entry_date'  => $b['date'],
            'memo'        => $b['memo'] ?? 'Opening balances',
            'source_type' => 'opening',
            'source_id'   => $b['id'] ?? null,
            'is_adjusting'=> 1,
            'lines'       => $lines,
        ];
    }

    // ── post wrappers: resolve account codes -> ids, then postEntry ─────────────

    public function postInvoice(array $inv): int      { return $this->postBuilt(self::withWho($this->buildInvoiceEntry($inv), $inv)); }
    public function postPayment(array $pmt): int       { return $this->postBuilt($this->buildPaymentEntry($pmt)); }
    public function postExpense(array $exp): int        { $exp = $this->withAllocations($exp); return $this->postBuilt(self::withWho($this->buildExpenseEntry($exp), $exp)); }

    /** Carry who posted it (created_by) and who proposed it (proposed_by) onto the entry. */
    private static function withWho(array $entry, array $args): array
    {
        foreach (['created_by', 'proposed_by'] as $k) {
            if (isset($args[$k])) $entry[$k] = $args[$k];
        }
        return $entry;
    }
    public function postOwnerDraw(array $d): int        { return $this->postBuilt($this->buildOwnerDrawEntry($d)); }
    public function postTransfer(array $t): int         { return $this->postBuilt($this->buildTransferEntry($t)); }
    public function postOpeningBalances(array $b): int  { return $this->postBuilt($this->buildOpeningBalanceEntry($b)); }
    public function postManual(array $entry): int       { return $this->postBuilt($entry); }

    /** Resolve each line's 'account' code to account_id (if present) and post. */
    private function postBuilt(array $entry): int
    {
        foreach ($entry['lines'] as &$line) {
            if (!isset($line['account_id']) && isset($line['account'])) {
                $line['account_id'] = $this->accountId((string)$line['account']);
            }
        }
        unset($line);
        return $this->postEntry($entry);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /** Existing (not reversed) entry id for a source, or null. */
    public function findEntryIdBySource(string $sourceType, int $sourceId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM journal_entries WHERE source_type = ? AND source_id = ? AND reversed_by_entry_id IS NULL LIMIT 1"
        );
        $stmt->execute([$sourceType, $sourceId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Reverse a posted entry — the append-only way to correct the books: a new entry
     * with every debit and credit swapped, linked both ways (the original's
     * reversed_by_entry_id). Dated with the original unless that month is locked, in
     * which case the reversal lands today (an adjustment in the current month).
     * Idempotent: an entry already reversed returns its reversal.
     * @return int the reversal entry id
     */
    public function reverseEntry(int $entryId, ?int $userId, string $why, string $proposedBy = 'owner'): int
    {
        $s = $this->db->prepare("SELECT id, entry_date, memo, reversed_by_entry_id FROM journal_entries WHERE id = ?");
        $s->execute([$entryId]);
        $orig = $s->fetch(PDO::FETCH_ASSOC);
        if (!$orig) throw new RuntimeException("Journal entry $entryId not found");
        if (!empty($orig['reversed_by_entry_id'])) return (int)$orig['reversed_by_entry_id'];

        $l = $this->db->prepare("SELECT account_id, debit, credit, gst_amount, pst_amount, description, job_id, contact_id,
                                        vendor_id, crew_user_id, cost_type_id, service_type FROM journal_lines WHERE entry_id = ?");
        $l->execute([$entryId]);
        $lines = [];
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $ln) {
            $lines[] = ['account_id' => (int)$ln['account_id'], 'debit' => (float)$ln['credit'], 'credit' => (float)$ln['debit'],
                        'gst_amount' => -(float)$ln['gst_amount'], 'pst_amount' => -(float)$ln['pst_amount'],
                        'description' => $ln['description'], 'job_id' => $ln['job_id'], 'contact_id' => $ln['contact_id'],
                        'vendor_id' => $ln['vendor_id'], 'crew_user_id' => $ln['crew_user_id'], 'cost_type_id' => $ln['cost_type_id'],
                        'service_type' => $ln['service_type']];
        }
        $date = $this->isLocked((string)$orig['entry_date']) ? date('Y-m-d') : (string)$orig['entry_date'];
        $revId = $this->postEntry([
            'entry_date' => $date, 'memo' => 'Reversal of #' . $entryId . ' — ' . $why,
            'source_type' => 'adjusting', 'source_id' => $entryId, 'is_adjusting' => 1,
            'created_by' => $userId, 'proposed_by' => $proposedBy, 'lines' => $lines,
        ]);
        $this->db->prepare("UPDATE journal_entries SET reversed_by_entry_id = ? WHERE id = ? AND reversed_by_entry_id IS NULL")
           ->execute([$revId, $entryId]);
        return $revId;
    }

    /** Is the month containing $date locked? */
    public function isLocked(string $date): bool
    {
        [$y, $m] = $this->yearMonth($date);
        $stmt = $this->db->prepare("SELECT status FROM accounting_periods WHERE year = ? AND month = ? LIMIT 1");
        $stmt->execute([$y, $m]);
        return $stmt->fetchColumn() === 'locked';
    }

    /**
     * Can a source be posted again after its entry is reversed? Only once migration
     * 1131 has dropped the old one-entry-per-source UNIQUE key.
     */
    public function canRepostSource(): bool
    {
        try {
            return $this->db->query("SHOW INDEX FROM journal_entries WHERE Key_name = 'uq_source'")->rowCount() === 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        static $seen = [];
        $k = $table . '.' . $column;
        if (!array_key_exists($k, $seen)) {
            try {
                $seen[$k] = $this->db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $this->db->quote($column))->rowCount() > 0;
            } catch (Throwable $e) {
                $seen[$k] = false;
            }
        }
        return $seen[$k];
    }

    /** Resolve a chart_of_accounts.id from its code. Cached per-request. */
    public function accountId(string $code): int
    {
        static $cache = [];
        if (isset($cache[$code])) {
            return $cache[$code];
        }
        $stmt = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Chart of accounts code not found: $code");
        }
        return $cache[$code] = (int)$id;
    }

    /** accounting_periods.id for the period containing $date, or null if none. */
    private function findPeriodId(string $date): ?int
    {
        [$y, $m] = $this->yearMonth($date);
        $stmt = $this->db->prepare("SELECT id FROM accounting_periods WHERE year = ? AND month = ? LIMIT 1");
        $stmt->execute([$y, $m]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Reject posting into a locked period.
     * @throws RuntimeException
     */
    private function assertPeriodPostable(string $date): void
    {
        [$y, $m] = $this->yearMonth($date);
        $stmt = $this->db->prepare("SELECT status FROM accounting_periods WHERE year = ? AND month = ? LIMIT 1");
        $stmt->execute([$y, $m]);
        $status = $stmt->fetchColumn();
        if ($status === 'locked') {
            throw new RuntimeException("Accounting period $y-" . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . " is locked; cannot post.");
        }
    }

    /** @return array{0:int,1:int} [year, month] */
    private function yearMonth(string $date): array
    {
        $ts = strtotime($date);
        if ($ts === false) {
            throw new InvalidArgumentException("Invalid entry_date: $date");
        }
        return [(int)date('Y', $ts), (int)date('n', $ts)];
    }
}
