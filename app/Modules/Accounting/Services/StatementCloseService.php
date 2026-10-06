<?php
/**
 * StatementCloseService — Penny's month-close proof for each bank / card account.
 *
 * Every imported statement (bank_import_sessions, status 'imported') is checked:
 *   adds up   — opening + every line on the statement = closing (the import-time check,
 *               bank_import_sessions.balance_* from migration 1068, recomputed here from
 *               bank_import_rows.raw_amount so a statement imported before 1068 counts too);
 *   in books  — every line is still in the ledger (a line deleted or rolled back since
 *               breaks the proof even though the statement itself added up);
 *   chains    — its opening balance equals the previous statement's closing balance for
 *               the same account (a gap means a missing statement or a wrong balance);
 *   coverage  — months between the first and last statement with no statement at all.
 * A month is closed (✅) only when its statements add up, are all in the books and chain.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class StatementCloseService
{
    public const CENTS = 0.02;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Statements are always checkable; balances need migration 1068 (run-migration-1068.php). */
    public function ready(): bool
    {
        return true;
    }

    public function hasBalances(): bool
    {
        try {
            return $this->db->query("SHOW COLUMNS FROM bank_import_sessions LIKE 'balance_opening'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<int, array{account: string, statements: array, gaps: array, closed: int, open: int}> */
    public function status(): array
    {
        $bal = $this->hasBalances() ? 's.balance_opening, s.balance_closing' : 'NULL AS balance_opening, NULL AS balance_closing';
        $sessions = $this->db->query("
            SELECT s.id, s.filename, s.bank_name, s.account_name, s.bank_account_id, s.date_from, s.date_to,
                   {$bal},
                   c.code AS acct_code, c.name AS acct_name,
                   (SELECT COALESCE(SUM(r.raw_amount), 0) FROM bank_import_rows r WHERE r.session_id = s.id) AS net,
                   (SELECT COUNT(*) FROM bank_import_rows r WHERE r.session_id = s.id) AS line_count,
                   (SELECT COUNT(*) FROM bank_import_rows r
                     WHERE r.session_id = s.id AND r.is_duplicate = 0 AND r.transaction_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM accounting_transactions t WHERE t.id = r.transaction_id)) AS missing
            FROM bank_import_sessions s
            LEFT JOIN chart_of_accounts c ON c.id = s.bank_account_id
            WHERE s.status = 'imported' AND s.date_from IS NOT NULL
            ORDER BY s.date_from, s.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $by = [];
        foreach ($sessions as $s) {
            $key = $s['bank_account_id'] ? 'a' . $s['bank_account_id'] : 'n' . strtolower(trim(($s['bank_name'] ?? '') . ' ' . ($s['account_name'] ?? '')));
            $label = $s['acct_name'] ? ($s['acct_code'] . ' ' . $s['acct_name']) : (trim(($s['bank_name'] ?? '') . ' ' . ($s['account_name'] ?? '')) ?: 'Unnamed account');
            $by[$key]['account'] = $label;
            $by[$key]['rows'][] = $s;
        }
        $out = [];
        foreach ($by as $acct) {
            $out[] = ['account' => $acct['account']] + self::check($acct['rows']);
        }
        return $out;
    }

    /** Locked months (YYYY-MM). */
    public function lockedMonths(): array
    {
        try {
            return array_map(fn($r) => sprintf('%04d-%02d', $r['year'], $r['month']),
                $this->db->query("SELECT year, month FROM accounting_periods WHERE status = 'locked' ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Lock a month — the owner's close. Nothing in it can be changed after this. */
    public function lockMonth(string $ym, int $userId): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return ['ok' => false, 'message' => 'Which month?'];
        if (!in_array($ym, self::lockable($this->status(), $this->lockedMonths()), true)) {
            return ['ok' => false, 'message' => "{$ym} isn't ready to lock — a statement covering it has a problem, or it's already locked."];
        }
        $this->db->prepare("
            INSERT INTO accounting_periods (year, month, label, status, closed_at, closed_by)
            VALUES (?, ?, ?, 'locked', NOW(), ?)
            ON DUPLICATE KEY UPDATE status = 'locked', closed_at = NOW(), closed_by = VALUES(closed_by)
        ")->execute([(int)$m[1], (int)$m[2], $ym, $userId]);
        return ['ok' => true, 'message' => "{$ym} is locked. Anything that turns up late for it goes into the current month."];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array $rows one account's statements, oldest first: date_from, date_to,
     *                    balance_opening, balance_closing, net, missing, filename, id
     */
    public static function check(array $rows): array
    {
        $statements = [];
        $prevClosing = null;
        foreach ($rows as $r) {
            $open = $r['balance_opening'] !== null ? (float)$r['balance_opening'] : null;
            $close = $r['balance_closing'] !== null ? (float)$r['balance_closing'] : null;
            $addsUp = ($open !== null && $close !== null) ? abs(round($open + (float)$r['net'] - $close, 2)) < self::CENTS : null;
            $chains = ($open !== null && $prevClosing !== null) ? abs(round($open - $prevClosing, 2)) < self::CENTS : null;
            $problems = [];
            if ($addsUp === false) $problems[] = sprintf('lines add up to %s, not the closing %s', number_format($open + (float)$r['net'], 2), number_format($close, 2));
            $unproven = $addsUp === null;
            if ((int)$r['missing'] > 0) $problems[] = (int)$r['missing'] . ' line' . ((int)$r['missing'] === 1 ? '' : 's') . ' no longer in the books';
            if ($chains === false) $problems[] = sprintf('opens at %s but the last statement closed at %s', number_format($open, 2), number_format($prevClosing, 2));
            $statements[] = [
                'id' => (int)$r['id'], 'from' => $r['date_from'], 'to' => $r['date_to'], 'file' => $r['filename'] ?? '',
                'opening' => $open, 'closing' => $close, 'adds_up' => $addsUp, 'chains' => $chains,
                'missing' => (int)$r['missing'], 'ok' => $addsUp === true && (int)$r['missing'] === 0 && $chains !== false,
                'unproven' => $unproven && !$problems,   // no balance saved, nothing wrong found
                'problems' => $problems,
            ];
            if ($close !== null) $prevClosing = $close;
        }
        $closed = count(array_filter($statements, fn($s) => $s['ok']));
        $unproven = count(array_filter($statements, fn($s) => $s['unproven']));
        return ['statements' => $statements, 'gaps' => self::gaps($rows), 'closed' => $closed, 'unproven' => $unproven,
                'open' => count($statements) - $closed - $unproven];
    }

    /**
     * Months that can be locked: before the current month, covered by a statement on
     * every account that has statements around it, none of those statements with a
     * problem, and not already locked.
     */
    public static function lockable(array $accounts, array $locked, ?string $today = null): array
    {
        $current = substr($today ?? date('Y-m-d'), 0, 7);
        $months = [];
        foreach ($accounts as $a) {
            foreach ($a['statements'] as $st) {
                $from = substr((string)$st['from'], 0, 7);
                $to = substr((string)($st['to'] ?: $st['from']), 0, 7);
                for ($m = $from; $m <= $to; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
                    $months[$m] = ($months[$m] ?? true) && empty($st['problems']);
                }
            }
            foreach ($a['gaps'] as $g) $months[$g] = false;
        }
        $out = [];
        foreach ($months as $m => $fine) {
            if ($fine && $m < $current && !in_array($m, $locked, true)) $out[] = $m;
        }
        sort($out);
        return $out;
    }

    /** Months (YYYY-MM) between the first and last statement that no statement covers. */
    public static function gaps(array $rows): array
    {
        if (!$rows) return [];
        $covered = [];
        $first = null; $last = null;
        foreach ($rows as $r) {
            $from = substr((string)$r['date_from'], 0, 7);
            $to = substr((string)($r['date_to'] ?: $r['date_from']), 0, 7);
            $first = $first === null || $from < $first ? $from : $first;
            $last = $last === null || $to > $last ? $to : $last;
            for ($m = $from; $m <= $to; $m = date('Y-m', strtotime($m . '-01 +1 month'))) $covered[$m] = true;
        }
        $gaps = [];
        for ($m = $first; $m <= $last; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            if (!isset($covered[$m])) $gaps[] = $m;
        }
        return $gaps;
    }
}
