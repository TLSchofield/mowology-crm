<?php
declare(strict_types=1);

/**
 * LockExceptionService — every journal change touching a locked period after it was locked
 * (Accountant's Ledger step 1, 2026-10-13).
 *
 * The lock is the existing one: accounting_periods.status = 'locked' with closed_at / closed_by
 * (StatementCloseService::lock() for months; FY2025's twelve months were locked 2026-10-07 with the
 * note "FY2025 filed"). LedgerService refuses manual posts into a locked month and sends automatic
 * ones to the current month as "Late entry for <date> — …"; a reversal of an entry in a locked month
 * lands today. So an exception is any of:
 *
 *   inside   an entry DATED in a locked month but CREATED after the lock (should never happen —
 *            a direct database write, or a lock applied with an old created_at)
 *   late     an automatic entry for a locked month moved to the current month ("Late entry for …")
 *   reversed an entry in a locked month reversed after the lock (who reversed it, and the reason the
 *            reversal's memo carries)
 *
 * Who: journal_entries.created_by (a person) and proposed_by (system | penny | owner). Why: the memo.
 * Pure rules in classify(); tested in tests/Unit/Ledger/LockExceptionServiceTest.php.
 */
class LockExceptionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** 'YYYY-MM' => ['closed_at' => …, 'closed_by' => …, 'notes' => …] for every locked month. */
    public function lockedPeriods(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT year, month, closed_at, closed_by, notes FROM accounting_periods WHERE status = 'locked'")
                     ->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $out[sprintf('%04d-%02d', (int)$p['year'], (int)$p['month'])] = $p;
        }
        return $out;
    }

    /**
     * Pure: which of these entries are exceptions?
     * @param array $entries rows: id, entry_date, created_at, memo, source_type, source_id, created_by, proposed_by,
     *                       and for reversals: orig_date (the reversed entry's date)
     * @param array $locked  lockedPeriods()
     * @return array rows with 'kind', 'period', 'locked_at' added
     */
    public static function classify(array $entries, array $locked): array
    {
        $out = [];
        foreach ($entries as $e) {
            $created = (string)$e['created_at'];
            $ym = substr((string)$e['entry_date'], 0, 7);
            if (isset($locked[$ym]) && !empty($locked[$ym]['closed_at']) && $created > (string)$locked[$ym]['closed_at']) {
                $out[] = $e + ['kind' => 'inside', 'period' => $ym, 'locked_at' => $locked[$ym]['closed_at']];
                continue;
            }
            if (preg_match('/^Late entry for (\d{4}-\d{2})-\d{2}/', (string)($e['memo'] ?? ''), $m) && isset($locked[$m[1]])) {
                $out[] = $e + ['kind' => 'late', 'period' => $m[1], 'locked_at' => $locked[$m[1]]['closed_at']];
                continue;
            }
            if (($e['source_type'] ?? '') === 'adjusting' && !empty($e['orig_date'])) {
                $oym = substr((string)$e['orig_date'], 0, 7);
                if (isset($locked[$oym]) && !empty($locked[$oym]['closed_at']) && $created > (string)$locked[$oym]['closed_at']) {
                    $out[] = $e + ['kind' => 'reversed', 'period' => $oym, 'locked_at' => $locked[$oym]['closed_at']];
                }
            }
        }
        usort($out, static fn($a, $b) => [(string)$b['created_at'], (int)$b['id']] <=> [(string)$a['created_at'], (int)$a['id']]);
        return $out;
    }

    /** The exception report. */
    public function report(): array
    {
        $locked = $this->lockedPeriods();
        if (!$locked) return ['rows' => [], 'locked' => []];
        $months = array_keys($locked);
        $in = implode(',', array_fill(0, count($months), '?'));
        $sql = "SELECT je.id, je.entry_date, je.created_at, je.memo, je.source_type, je.source_id, je.created_by, je.proposed_by,
                       u.full_name AS created_by_name, o.entry_date AS orig_date, o.memo AS orig_memo,
                       (SELECT COALESCE(SUM(l.debit), 0) FROM journal_lines l WHERE l.entry_id = je.id) AS amount
                FROM journal_entries je
                LEFT JOIN users u ON u.id = je.created_by
                LEFT JOIN journal_entries o ON je.source_type = 'adjusting' AND o.id = je.source_id
                WHERE DATE_FORMAT(je.entry_date, '%Y-%m') IN ($in)
                   OR je.memo LIKE 'Late entry for %'
                   OR (je.source_type = 'adjusting' AND DATE_FORMAT(o.entry_date, '%Y-%m') IN ($in))";
        $st = $this->db->prepare($sql);
        $st->execute(array_merge($months, $months));
        return ['rows' => self::classify($st->fetchAll(PDO::FETCH_ASSOC), $locked), 'locked' => $locked];
    }
}
