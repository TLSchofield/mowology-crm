<?php
declare(strict_types=1);

/**
 * GeneralLedgerService — the general ledger by account and period (Accountant's Ledger step 2, 2026-10-13).
 *
 * For one account and a date range: the opening balance (every posted line dated before the range),
 * every line in the range in date order with its running balance, and the closing balance. Balances
 * are debit − credit (credits shown in parentheses), like the trial balance, so the GL closing equals
 * that account's trial-balance figure at the same date. Each line carries its entry id and source, so
 * the page drills through to the source document (JournalBrowserService::entry / source).
 *
 * The whole ledger (every account with activity) is one export: allAccounts() runs one query for the
 * openings and one for the lines.
 *
 * Pure shaping in build() (tested in tests/Unit/Ledger/GeneralLedgerTest.php).
 * No namespace / no autoloader in production: require_once and `new`.
 */
class GeneralLedgerService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The chart (for the account picker): code => [id, code, name, type]. */
    public function accounts(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT id, code, name, type FROM chart_of_accounts ORDER BY code")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out[(string)$a['code']] = $a;
        }
        return $out;
    }

    /**
     * Opening + lines with running balance + closing. Pure.
     * @param float $opening debit − credit before the range
     * @param array $lines   rows with debit, credit (in date, entry, line order)
     */
    public static function build(float $opening, array $lines): array
    {
        $run = round($opening, 2);
        $dr = 0.0; $cr = 0.0;
        foreach ($lines as &$l) {
            $d = round((float)$l['debit'], 2); $c = round((float)$l['credit'], 2);
            $dr += $d; $cr += $c;
            $run = round($run + $d - $c, 2);
            $l['debit'] = $d; $l['credit'] = $c; $l['balance'] = $run;
        }
        unset($l);
        return ['opening' => round($opening, 2), 'lines' => $lines, 'debits' => round($dr, 2), 'credits' => round($cr, 2),
                'closing' => round($opening + $dr - $cr, 2)];
    }

    private function kindCol(): string
    {
        try { $this->db->query("SELECT entry_kind FROM journal_entries LIMIT 1"); return 'je.entry_kind'; }
        catch (Throwable $e) { return "'system'"; }
    }

    /** One account's ledger for [from, to]; null when the account doesn't exist. */
    public function account(string $code, string $from, string $to): ?array
    {
        $st = $this->db->prepare("SELECT id, code, name, type, normal_balance FROM chart_of_accounts WHERE code = ?");
        $st->execute([$code]);
        $acct = $st->fetch(PDO::FETCH_ASSOC);
        if (!$acct) return null;
        $o = $this->db->prepare("SELECT COALESCE(SUM(jl.debit - jl.credit), 0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
                                 WHERE jl.account_id = ? AND je.status = 'posted' AND je.entry_date < ?");
        $o->execute([(int)$acct['id'], $from]);
        $opening = (float)$o->fetchColumn();
        $k = $this->kindCol();
        $l = $this->db->prepare("SELECT je.id AS entry_id, je.entry_date, $k AS entry_kind, je.source_type, je.source_id, je.memo,
                                        jl.id AS line_id, jl.description, jl.debit, jl.credit
                                 FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
                                 WHERE jl.account_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
                                 ORDER BY je.entry_date, je.id, jl.id");
        $l->execute([(int)$acct['id'], $from, $to]);
        $g = self::build($opening, $l->fetchAll(PDO::FETCH_ASSOC));
        return $g + ['account' => $acct, 'from' => $from, 'to' => $to];
    }

    /** Every account with an opening balance or lines in [from, to], each built like account(). */
    public function allAccounts(string $from, string $to): array
    {
        $open = $this->db->prepare("SELECT jl.account_id, SUM(jl.debit - jl.credit) AS bal FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
                                    WHERE je.status = 'posted' AND je.entry_date < ? GROUP BY jl.account_id");
        $open->execute([$from]);
        $openings = [];
        foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $r) $openings[(int)$r['account_id']] = (float)$r['bal'];
        $k = $this->kindCol();
        $l = $this->db->prepare("SELECT jl.account_id, je.id AS entry_id, je.entry_date, $k AS entry_kind, je.source_type, je.source_id, je.memo,
                                        jl.id AS line_id, jl.description, jl.debit, jl.credit
                                 FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
                                 WHERE je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
                                 ORDER BY jl.account_id, je.entry_date, je.id, jl.id");
        $l->execute([$from, $to]);
        $lines = [];
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $r) $lines[(int)$r['account_id']][] = $r;
        $out = [];
        foreach ($this->db->query("SELECT id, code, name, type, normal_balance FROM chart_of_accounts ORDER BY code")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $id = (int)$a['id'];
            $op = $openings[$id] ?? 0.0;
            if (abs($op) < 0.005 && empty($lines[$id])) continue;
            $out[] = self::build($op, $lines[$id] ?? []) + ['account' => $a, 'from' => $from, 'to' => $to];
        }
        return $out;
    }

    /** The export table for one or many account ledgers. Pure. */
    public static function exportTable(array $ledgers, string $from, string $to): array
    {
        $cols = [['Account', 'text', 9], ['Account name', 'text', 26], ['Date', 'date', 11], ['Entry', 'int', 8], ['Source', 'text', 16],
                 ['Memo / description', 'text', 48], ['Debit', 'money', 13], ['Credit', 'money', 13], ['Balance', 'money', 14]];
        $rows = [];
        foreach ($ledgers as $g) {
            $a = $g['account'];
            $rows[] = ['cells' => [$a['code'], $a['name'], $from, null, '', 'Opening balance', null, null, $g['opening']], 'bold' => true];
            foreach ($g['lines'] as $l) {
                $text = trim((string)$l['memo']);
                if (!empty($l['description']) && $l['description'] !== $l['memo']) $text .= ($text !== '' ? ' — ' : '') . $l['description'];
                $rows[] = [$a['code'], $a['name'], $l['entry_date'], (int)$l['entry_id'],
                           str_replace('_', ' ', (string)$l['source_type']) . ($l['source_id'] ? ' ' . (int)$l['source_id'] : ''),
                           $text, $l['debit'] ?: null, $l['credit'] ?: null, $l['balance']];
            }
            $rows[] = ['cells' => [$a['code'], $a['name'], $to, null, '', 'Closing balance', $g['debits'], $g['credits'], $g['closing']], 'bold' => true];
        }
        $one = count($ledgers) === 1 ? $ledgers[0]['account'] : null;
        return ['title' => 'General ledger' . ($one ? ' — ' . $one['code'] . ' ' . $one['name'] : ''), 'period' => $from . ' to ' . $to,
                'from' => $from, 'to' => $to, 'columns' => $cols, 'rows' => $rows, 'landscape' => true,
                'notes' => ['Balance = debits − credits; credits in parentheses. Opening = every posted line before ' . $from . '.']];
    }
}
