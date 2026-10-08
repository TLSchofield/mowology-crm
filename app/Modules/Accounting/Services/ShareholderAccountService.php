<?php
/**
 * ShareholderAccountService — "Due from Shareholder" (1300), kept by Penny (2026-10-07).
 *
 * Tim: "with mine can Penny manage my transfers so wages and shareholder loan is figured out".
 *   - his transfers OUT to himself: net pay first (WavePayrollImportService moves them to 2520
 *     and sweeps the part beyond his net to 1300 when the month's payroll is booked); in a 2026
 *     month whose payroll isn't booked they wait ("waiting for the payroll report").
 *   - money IN from him → 1300 (a repayment; the deposit credits 1300).
 *   - personal charges the business paid (gym, streaming, …) → 1300, proposals only, unticked.
 *   - the accountant's clearing of the opening balance (filed FS: $86,086 at 2025-12-31) —
 *     ONE entry from Tim's / the accountant's figures, never guessed:
 *       dividend: DR 3400 Dividends Declared / CR 1300
 *       bonus:    DR 5100 wages (amount + withholdings) / CR 2310 withholdings / CR 1300 amount
 *   - the running balance by month, from the journal (1300 lines), with the year-end flag:
 *     a balance still owed at Dec 31 has tax consequences (CRA s.15(2)) — talk to the accountant.
 *
 * Penny proposes, Tim approves; moves go through BankLineMoveService (reversal + repost), 2026
 * only, locked months refused, every change logged (payroll_bank_moves / shareholder_clearings,
 * migration 1255) and undoable.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/WavePayrollImportService.php';

class ShareholderAccountService
{
    public const ACC = LedgerService::ACC_DUE_FROM_SH;   // 1300
    public const ACC_DIVIDENDS = '3400';
    public const YEAR = WavePayrollImportService::BOOK_YEAR;
    public const FILED_OPENING_DEFAULT = 86086.00;
    /** Business-paid charges that are usually personal — a proposal, never automatic. */
    public const PERSONAL_RE = '/\b(NETFLIX|SPOTIFY|DISNEY ?PLUS|DISNEYPLUS|CRAVE|PRIME ?VIDEO|APPLE\.COM\/BILL|GOODLIFE|ANYTIME FITNESS|FITNESS|GYM|YOGA|CINEPLEX|LULULEMON|SEPHORA|STEAM ?GAMES|PLAYSTATION|XBOX|NINTENDO)\b/i';
    public const BRIEF_FROM_MONTH = 11;
    public const BRIEF_FLOOR = 500.0;

    private PDO $db;
    private LedgerService $ledger;
    private WavePayrollImportService $payroll;

    public function __construct(PDO $db, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->ledger = $ledger ?? new LedgerService($db);
        $this->payroll = new WavePayrollImportService($db, $this->ledger);
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public function setShareholderName(string $name, int $userId): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) return ['ok' => false, 'message' => 'Which employee is the shareholder?'];
        $this->setSetting('payroll_shareholder_name', $name, $userId, 'Employee on the Wave report who is the shareholder (Due from Shareholder 1300)');
        return ['ok' => true, 'message' => $name . ' is the shareholder.'];
    }

    public function filedOpening(): float
    {
        $v = $this->setting('shareholder_filed_opening');
        return $v === null || $v === '' ? self::FILED_OPENING_DEFAULT : round((float)$v, 2);
    }

    // ── Pure ─────────────────────────────────────────────────────────────────

    /** Words of a person's name in a bank description? (both first and last) */
    public static function isFromShareholder(string $description, string $name): bool
    {
        $parts = preg_split('/\s+/', strtoupper(trim($name)));
        if (count($parts) < 2) return false;
        $d = ' ' . trim(preg_replace('/[^A-Z]+/', ' ', strtoupper($description))) . ' ';
        return strpos($d, ' ' . $parts[0] . ' ') !== false && strpos($d, ' ' . end($parts) . ' ') !== false;
    }

    /**
     * Split the shareholder's transfers in a month: net pay first, the remainder a withdrawal
     * (a shortfall leaves wages owed to him, which reduces what he owes).
     * @return array{net_pay: float, to_shareholder: float}
     */
    public static function split(float $transfers, float $net): array
    {
        $transfers = round($transfers, 2);
        return ['net_pay' => round(min($transfers, $net), 2), 'to_shareholder' => round($transfers - $net, 2)];
    }

    /**
     * Running balance by month. $rows: [month 'YYYY-MM', kind, amount (+ raises what he owes)].
     * Kinds: withdrawal, repayment, payroll (sweep), personal, clearing, other.
     */
    public static function running(float $opening, array $rows, int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $year, $m);
            $months[$ym] = ['month' => $ym, 'withdrawal' => 0.0, 'payroll' => 0.0, 'personal' => 0.0, 'repayment' => 0.0, 'clearing' => 0.0, 'other' => 0.0, 'balance' => 0.0];
        }
        foreach ($rows as $r) {
            if (!isset($months[$r['month']])) continue;
            $k = isset($months[$r['month']][$r['kind']]) ? $r['kind'] : 'other';
            $months[$r['month']][$k] = round($months[$r['month']][$k] + (float)$r['amount'], 2);
        }
        $bal = round($opening, 2);
        foreach ($months as &$mo) {
            $bal = round($bal + $mo['withdrawal'] + $mo['payroll'] + $mo['personal'] + $mo['repayment'] + $mo['clearing'] + $mo['other'], 2);
            $mo['balance'] = $bal;
        }
        unset($mo);
        return ['opening' => round($opening, 2), 'months' => array_values($months), 'closing' => $bal];
    }

    /** The clearing entry (codes). Amount clears 1300; a bonus also books its withholdings. */
    public static function buildClearing(string $type, float $amount, float $withholdings, string $date, string $note): array
    {
        $amount = round($amount, 2);
        $withholdings = round(max(0, $withholdings), 2);
        $memo = 'Shareholder account cleared by ' . ($type === 'bonus' ? 'bonus' : 'dividend') . ($note !== '' ? ' — ' . $note : '');
        if ($type === 'bonus') {
            $lines = [['account' => WavePayrollImportService::ACC_WAGES, 'debit' => round($amount + $withholdings, 2), 'credit' => 0, 'description' => 'Shareholder bonus (gross)']];
            if ($withholdings > 0) $lines[] = ['account' => WavePayrollImportService::ACC_SOURCE_DEDUCTIONS, 'debit' => 0, 'credit' => $withholdings, 'description' => 'Bonus withholdings owed to CRA'];
            $lines[] = ['account' => self::ACC, 'debit' => 0, 'credit' => $amount, 'description' => 'Bonus applied to the shareholder account'];
        } else {
            $lines = [['account' => self::ACC_DIVIDENDS, 'debit' => $amount, 'credit' => 0, 'description' => 'Dividend declared'],
                      ['account' => self::ACC, 'debit' => 0, 'credit' => $amount, 'description' => 'Dividend applied to the shareholder account']];
        }
        return ['entry_date' => $date, 'memo' => $memo, 'lines' => $lines];
    }

    /** Nov–Dec: a balance he still owes → Penny says so. */
    public static function briefItems(float $balance, string $today): array
    {
        if ((int)substr($today, 5, 2) < self::BRIEF_FROM_MONTH || $balance < self::BRIEF_FLOOR) return [];
        return [['key' => 'penny:shareholder:' . substr($today, 0, 4), 'kind' => 'penny:shareholder_balance', 'priority' => 2, 'value' => round($balance, 2),
                 'text' => 'Your shareholder account is $' . number_format($balance, 0) . ' owed by you — keep it near $0; a balance left at Dec 31 becomes taxable again. Talk to your accountant before Dec 31.',
                 'url' => WavePayrollImportService::URL . '#shareholder']];
    }

    // ── Ledger (DB) ──────────────────────────────────────────────────────────

    /** Opening (journal balance of 1300 at the year start) and the 2026 movements by month. */
    public function ledger(): array
    {
        $acct = $this->accountId(self::ACC);
        $start = self::YEAR . '-01-01';
        $opening = 0.0;
        $rows = [];
        if ($acct) {
            $s = $this->db->prepare("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                                     WHERE l.account_id = ? AND e.entry_date < ?");
            $s->execute([$acct, $start]);
            $opening = round((float)$s->fetchColumn(), 2);
            $s = $this->db->prepare("SELECT e.entry_date, e.source_type, e.source_id, l.debit, l.credit FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                                     WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ?
                                       AND e.reversed_by_entry_id IS NULL
                                       AND NOT EXISTS (SELECT 1 FROM journal_entries o WHERE o.reversed_by_entry_id = e.id)");
            $s->execute([$acct, $start, self::YEAR . '-12-31']);
            $personal = $this->personalIds();
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $amt = round((float)$r['debit'] - (float)$r['credit'], 2);
                $src = (string)$r['source_type'];
                if ($src === 'payroll_run') $kind = 'payroll';
                elseif ($src === 'shareholder_clearing') $kind = 'clearing';
                elseif ($src === 'bank_import') $kind = isset($personal[(int)$r['source_id']]) ? 'personal' : ($amt >= 0 ? 'withdrawal' : 'repayment');
                elseif ($src === 'opening_balance' || $src === 'opening') $kind = 'other';
                else $kind = 'other';
                $rows[] = ['month' => substr((string)$r['entry_date'], 0, 7), 'kind' => $kind, 'amount' => $amt];
            }
        }
        $filed = $this->filedOpening();
        $run = self::running($opening, $rows, self::YEAR);
        $run['filed_opening'] = $filed;
        $run['opening_note'] = abs($opening - $filed) < 0.5 ? null
            : 'The books open ' . self::YEAR . ' at $' . number_format($opening, 2) . ' on ' . self::ACC . '; the filed balance sheet says $' . number_format($filed, 2)
              . ' — the ' . self::YEAR . ' opening entry isn\'t in yet (or differs).';
        return $run;
    }

    private function personalIds(): array
    {
        try {
            return array_fill_keys(array_map('intval', $this->db->query("SELECT transaction_id FROM payroll_bank_moves WHERE role = 'personal' AND undone_at IS NULL")
                                                       ->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Proposals (DB) ───────────────────────────────────────────────────────

    /**
     * Repayments (money in from him), personal charges, and his transfers waiting for a
     * month's payroll. Locked months and lines already moved are left out.
     */
    public function proposals(): array
    {
        $name = $this->payroll->shareholderName();
        $out = ['shareholder' => $name, 'repayments' => [], 'personal' => [], 'waiting' => []];
        $locked = $this->payroll->lockedMonths();
        $lines = array_values(array_filter($this->payroll->bankLines(self::YEAR . '-01-01', self::YEAR . '-12-31'),
                                           fn($l) => !in_array(substr($l['date'], 0, 7), $locked, true) && $l['account_code'] !== self::ACC));
        $booked = [];
        foreach ($this->db->query("SELECT period_month FROM payroll_runs WHERE status = 'booked'")->fetchAll(PDO::FETCH_COLUMN) as $m) $booked[(string)$m] = true;
        $row = fn($l, $role, $checked) => ['id' => $l['id'], 'date' => $l['date'], 'amount' => $l['amount'], 'description' => $l['description'],
                                            'from' => $l['account_code'], 'to' => self::ACC, 'role' => $role, 'checked' => $checked];
        foreach ($lines as $l) {
            $mine = $name !== null && self::isFromShareholder($l['description'], $name);
            if ($mine && $l['direction'] === 'in') $out['repayments'][] = $row($l, 'repayment', true);
            elseif ($mine && $l['direction'] === 'out' && !isset($booked[substr($l['date'], 0, 7)])) $out['waiting'][] = $row($l, 'waiting', false);
            elseif (!$mine && $l['direction'] === 'out' && preg_match(self::PERSONAL_RE, $l['description'])) $out['personal'][] = $row($l, 'personal', false);
        }
        return $out;
    }

    /** Move the ticked repayment / personal lines to 1300 (one batch, undoable). */
    public function apply(array $txIds, int $userId): array
    {
        $acctId = $this->accountId(self::ACC);
        if (!$acctId) return ['ok' => false, 'message' => 'Account ' . self::ACC . ' Due from Shareholder is missing.'];
        $p = $this->proposals();
        $tick = array_map('intval', $txIds);
        $pick = array_values(array_filter(array_merge($p['repayments'], $p['personal']), fn($r) => in_array($r['id'], $tick, true)));
        if (!$pick) return ['ok' => false, 'message' => 'Nothing to move — those lines aren\'t proposals any more.'];
        $batch = 'sh-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $mover = new BankLineMoveService($this->db, $this->ledger);
        $moved = 0; $failed = [];
        foreach ($pick as $m) {
            $s = $this->db->prepare("SELECT account_id, type FROM accounting_transactions WHERE id = ?");
            $s->execute([$m['id']]);
            $was = $s->fetch(PDO::FETCH_ASSOC) ?: ['account_id' => null, 'type' => null];
            $res = $mover->move($m['id'], $acctId, $userId, 'penny');
            if (empty($res['ok'])) { $failed[] = $m['id'] . ': ' . $res['message']; continue; }
            $this->payroll->logMove(null, $batch, $m, $was, $acctId, $userId);
            $moved++;
        }
        return ['ok' => $moved > 0, 'batch_id' => $batch, 'moved' => $moved, 'failed' => $failed,
                'message' => $moved . ' line' . ($moved === 1 ? '' : 's') . ' moved to ' . self::ACC . ' Due from Shareholder.' . ($failed ? ' Not moved: ' . implode('; ', $failed) : '')];
    }

    public function undoBatch(string $batch, int $userId): array
    {
        $r = $this->payroll->undoMoves('batch_id = ?', [$batch], $userId);
        return ['ok' => $r['moved'] > 0, 'message' => $r['moved'] . ' line' . ($r['moved'] === 1 ? '' : 's') . ' moved back.' . ($r['failed'] ? ' Not moved back: ' . implode('; ', $r['failed']) : '')];
    }

    /** Batches Tim can undo (newest first). */
    public function batches(): array
    {
        try {
            return $this->db->query("SELECT batch_id, COUNT(*) AS n, SUM(amount) AS total, MIN(moved_at) AS moved_at FROM payroll_bank_moves
                                     WHERE batch_id IS NOT NULL AND undone_at IS NULL GROUP BY batch_id ORDER BY moved_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── The accountant's clearing (DB) ───────────────────────────────────────

    public function addClearing(string $type, string $date, float $amount, float $withholdings, string $note, int $userId): array
    {
        if (!in_array($type, ['dividend', 'bonus'], true)) return ['ok' => false, 'message' => 'Dividend or bonus?'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || (int)substr($date, 0, 4) !== self::YEAR) return ['ok' => false, 'message' => 'The date must be in ' . self::YEAR . '.'];
        if ($amount <= 0 || $amount > 1000000) return ['ok' => false, 'message' => 'How much did it clear?'];
        if ($withholdings < 0 || ($type === 'dividend' && $withholdings > 0)) return ['ok' => false, 'message' => 'Withholdings only apply to a bonus.'];
        if ($this->ledger->isLocked($date)) return ['ok' => false, 'message' => substr($date, 0, 7) . ' is locked.'];
        $need = $type === 'bonus' ? [WavePayrollImportService::ACC_WAGES, WavePayrollImportService::ACC_SOURCE_DEDUCTIONS, self::ACC] : [self::ACC_DIVIDENDS, self::ACC];
        foreach ($need as $code) if (!$this->accountId($code)) return ['ok' => false, 'message' => "Account {$code} is missing — run migration 1255."];
        $note = mb_substr(trim($note), 0, 250);

        $this->db->prepare("INSERT INTO shareholder_clearings (clearing_type, entry_date, amount, withholdings, note, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())")->execute([$type, $date, round($amount, 2), round($withholdings, 2), $note ?: null, $userId ?: null]);
        $id = (int)$this->db->lastInsertId();
        $entry = self::buildClearing($type, $amount, $withholdings, $date, $note)
               + ['source_type' => 'shareholder_clearing', 'source_id' => $id, 'created_by' => $userId, 'proposed_by' => 'owner'];
        try {
            $entryId = $this->ledger->postManual($entry);
        } catch (Throwable $e) {
            $this->db->prepare("DELETE FROM shareholder_clearings WHERE id = ?")->execute([$id]);
            throw $e;
        }
        $this->db->prepare("UPDATE shareholder_clearings SET journal_entry_id = ? WHERE id = ?")->execute([$entryId, $id]);
        return ['ok' => true, 'message' => 'Booked: $' . number_format($amount, 2) . ' ' . $type . ' clears the shareholder account (entry #' . $entryId . ').', 'id' => $id, 'entry_id' => $entryId];
    }

    public function undoClearing(int $id, int $userId): array
    {
        $s = $this->db->prepare("SELECT * FROM shareholder_clearings WHERE id = ? AND undone_at IS NULL");
        $s->execute([$id]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['ok' => false, 'message' => 'Not found'];
        if ($this->ledger->isLocked((string)$c['entry_date'])) return ['ok' => false, 'message' => substr((string)$c['entry_date'], 0, 7) . ' is locked.'];
        $rev = $c['journal_entry_id'] ? $this->ledger->reverseEntry((int)$c['journal_entry_id'], $userId, 'shareholder clearing undone', 'owner') : null;
        $this->db->prepare("UPDATE shareholder_clearings SET undone_at = NOW(), undone_by = ?, reversal_entry_id = ? WHERE id = ?")->execute([$userId ?: null, $rev, $id]);
        return ['ok' => true, 'message' => 'Clearing undone' . ($rev ? ' (reversal #' . $rev . ')' : '') . '.'];
    }

    public function clearings(): array
    {
        try {
            return $this->db->query("SELECT id, clearing_type, entry_date, amount, withholdings, note, journal_entry_id FROM shareholder_clearings
                                     WHERE undone_at IS NULL ORDER BY entry_date, id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Everything the shareholder section shows. */
    public function report(): array
    {
        $ledger = $this->ledger();
        return ['ledger' => $ledger, 'proposals' => $this->proposals(), 'batches' => $this->batches(), 'clearings' => $this->clearings(),
                'goal' => 'Keep it near $0 — a balance left at Dec 31 becomes taxable again.',
                'year_end_note' => 'A balance the shareholder still owes the company after its year end can be taxed as his income (CRA s.15(2)). Talk to your accountant before Dec 31 about clearing it — e.g. a bonus or a dividend.'];
    }

    public function brief(string $today): array
    {
        try {
            return self::briefItems($this->ledger()['closing'], $today);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function accountId(string $code): ?int
    {
        $s = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
        $s->execute([$code]);
        $id = $s->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function setting(string $key): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function setSetting(string $key, string $value, int $userId, string $desc): void
    {
        $u = $this->db->prepare("UPDATE ops_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
        $u->execute([$value, $userId ?: null, $key]);
        if ($u->rowCount() === 0 && $this->setting($key) === null) {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description, updated_by) VALUES (?, ?, ?, ?)")
               ->execute([$key, $value, $desc, $userId ?: null]);
        }
    }
}
