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

        // Period lock guard.
        $this->assertPeriodPostable($entryDate);
        $periodId = $this->findPeriodId($entryDate);

        $this->db->beginTransaction();
        try {
            $hdr = $this->db->prepare(
                "INSERT INTO journal_entries
                    (entry_date, memo, source_type, source_id, period_id, status, is_adjusting, created_by)
                 VALUES (?, ?, ?, ?, ?, 'posted', ?, ?)"
            );
            $hdr->execute([
                $entryDate,
                $entry['memo'] ?? null,
                $sourceType,
                $sourceId,
                $periodId,
                !empty($entry['is_adjusting']) ? 1 : 0,
                $entry['created_by'] ?? null,
            ]);
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
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /** Existing entry id for a source, or null. */
    public function findEntryIdBySource(string $sourceType, int $sourceId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM journal_entries WHERE source_type = ? AND source_id = ? LIMIT 1"
        );
        $stmt->execute([$sourceType, $sourceId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
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
