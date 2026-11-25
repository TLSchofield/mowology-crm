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
 *   late     an automatic entry for a locked month moved to the current month (journal_entries.late_for,
 *            set by LedgerService::postEntry; migration 1356)
 *   reversed an entry in a locked month reversed after the lock (who reversed it, and the reason the
 *            reversal's memo carries)
 *
 * Who: journal_entries.created_by (a person) and proposed_by (system | penny | owner). Why: the memo.
 * "After the lock" means after the month was FIRST locked (accounting_period_locks, migration 1356): a change
 * made while a month was reopened still counts.
 * Pure rules in classify(); tested in tests/Unit/Ledger/JournalAndExceptionsTest.php.
 */
class LockExceptionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * 'YYYY-MM' => ['closed_at' => the FIRST time the month was locked, 'last_locked_at' => …, 'closed_by', 'notes',
     * 'relocked' => locked more than once] for every month locked now.
     *
     * accounting_periods holds only the current state and a re-lock overwrites closed_at, so the first lock
     * comes from accounting_period_locks (migration 1356) — a change made while a month was reopened is
     * still a change after it was first locked. Before 1356 it falls back to closed_at.
     */
    public function lockedPeriods(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT year, month, closed_at, closed_by, notes FROM accounting_periods WHERE status = 'locked'")
                     ->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $p['last_locked_at'] = $p['closed_at'];
            $p['relocked'] = false;
            $out[sprintf('%04d-%02d', (int)$p['year'], (int)$p['month'])] = $p;
        }
        if (!$out) return $out;
        try {
            $h = $this->db->query("SELECT year, month, MIN(locked_at) AS first_at, COUNT(*) AS n FROM accounting_period_locks GROUP BY year, month")
                          ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $h = [];   // before migration 1356
        }
        foreach ($h as $r) {
            $ym = sprintf('%04d-%02d', (int)$r['year'], (int)$r['month']);
            if (!isset($out[$ym]) || empty($r['first_at'])) continue;
            if (empty($out[$ym]['closed_at']) || (string)$r['first_at'] < (string)$out[$ym]['closed_at']) $out[$ym]['closed_at'] = (string)$r['first_at'];
            $out[$ym]['relocked'] = (int)$r['n'] > 1;
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
            $lateYm = !empty($e['late_for']) ? substr((string)$e['late_for'], 0, 7) : null;
            if ($lateYm === null && preg_match('/^Late entry for (\d{4}-\d{2})-\d{2}/', (string)($e['memo'] ?? ''), $m)) $lateYm = $m[1];   // rows given without the flag
            if ($lateYm !== null && isset($locked[$lateYm])) {
                $out[] = $e + ['kind' => 'late', 'period' => $lateYm, 'locked_at' => $locked[$lateYm]['closed_at']];
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

    /**
     * The exception report. One indexed range per locked month (entry_date BETWEEN, not DATE_FORMAT, so
     * the entry_date index is used) and the late_for flag set at post time (not memo LIKE).
     */
    public function report(): array
    {
        $locked = $this->lockedPeriods();
        if (!$locked) return ['rows' => [], 'locked' => []];
        [$inRange, $rangeParams] = self::rangesSql(array_keys($locked), 'je.entry_date');
        [$origRange, $origParams] = self::rangesSql(array_keys($locked), 'o.entry_date');
        $hasLate = $this->hasColumn('late_for');
        $lateSel = $hasLate ? 'je.late_for' : 'NULL AS late_for';
        $lateCond = $hasLate ? 'je.late_for IS NOT NULL' : "je.memo LIKE 'Late entry for %'";
        $sql = "SELECT je.id, je.entry_date, je.created_at, je.memo, je.source_type, je.source_id, je.created_by, je.proposed_by, $lateSel,
                       u.full_name AS created_by_name, o.entry_date AS orig_date, o.memo AS orig_memo,
                       (SELECT COALESCE(SUM(l.debit), 0) FROM journal_lines l WHERE l.entry_id = je.id) AS amount
                FROM journal_entries je
                LEFT JOIN users u ON u.id = je.created_by
                LEFT JOIN journal_entries o ON je.source_type = 'adjusting' AND o.id = je.source_id
                WHERE ($inRange)
                   OR $lateCond
                   OR (je.source_type = 'adjusting' AND ($origRange))";
        $st = $this->db->prepare($sql);
        $st->execute(array_merge($rangeParams, $origParams));
        return ['rows' => self::classify($st->fetchAll(PDO::FETCH_ASSOC), $locked), 'locked' => $locked];
    }

    /**
     * "(col BETWEEN ? AND ?) OR …" for a list of months, adjacent months merged into one range. Pure.
     * @return array{0: string, 1: array}
     */
    public static function rangesSql(array $months, string $col): array
    {
        sort($months);
        $ranges = [];
        foreach ($months as $ym) {
            $first = $ym . '-01';
            $last = date('Y-m-t', strtotime($first));
            $n = count($ranges);
            if ($n && date('Y-m-d', strtotime($ranges[$n - 1][1] . ' +1 day')) === $first) $ranges[$n - 1][1] = $last;
            else $ranges[] = [$first, $last];
        }
        if (!$ranges) return ['1 = 0', []];
        $sql = []; $p = [];
        foreach ($ranges as [$a, $b]) { $sql[] = "$col BETWEEN ? AND ?"; $p[] = $a; $p[] = $b; }
        return [implode(' OR ', $sql), $p];
    }

    private function hasColumn(string $col): bool
    {
        try { $this->db->query("SELECT $col FROM journal_entries LIMIT 1"); return true; }
        catch (Throwable $e) { return false; }
    }
}
