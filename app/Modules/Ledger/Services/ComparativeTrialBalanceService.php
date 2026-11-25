<?php
declare(strict_types=1);

/**
 * ComparativeTrialBalanceService — the Ledger's comparative trial balance (Accountant's Ledger step 2,
 * 2026-10-13; plan docs/plans/2026-10-13-accountants-ledger.md).
 *
 * Columns, every one a signed balance (debit − credit; credits shown in parentheses):
 *   unadjusted  adjusted − AJE (the books before the accountant's adjustments)
 *   proposed    proposed adjusting entries — EMPTY until step 3 (the column is there so the layout and
 *               the exports never change shape)
 *   aje         posted adjusting entries (entry_kind aje / rje) dated in the fiscal year to the as-at date
 *   adjusted    every posted line dated on or before the as-at date — the same figure as the existing
 *               trial balance page (ReportingService::getTrialBalance), checked by a test
 *   py          prior fiscal year end, AS FILED: every line to that year end plus the year's FY opening
 *               entry (journal_entries.source_type 'fy_opening', source_id = that year, and any reversal of
 *               it), which moves each balance-sheet account to the signed statements and closes the
 *               income accounts into retained earnings. For Mowology FY2025 that is Fy2026OpeningService's
 *               entry: PY shows the filed figures (post-closing).
 *   py2         two years prior, the same way — or "not in the journal" when nothing is posted that early.
 *
 * Each line carries its GIFI code and lead-sheet group (migration 1355). Every column must sum to
 * zero; exports refuse an unbalanced trial balance.
 *
 * buildRows() is pure (tested in tests/Unit/Ledger/ComparativeTrialBalanceTest.php); load() is the
 * one grouped query. No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/FiscalYear.php';

class ComparativeTrialBalanceService
{
    public const COLUMNS = ['unadjusted', 'proposed', 'aje', 'adjusted', 'py', 'py2'];
    public const CENT = 0.005;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The FY opening entries that close fiscal year $fy (and any reversal of one). */
    public function closingEntryIds(int $fy): array
    {
        try {
            $st = $this->db->prepare("SELECT id FROM journal_entries WHERE source_type = 'fy_opening' AND source_id = ? AND status = 'posted'");
            $st->execute([$fy]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) return [];
            $in = implode(',', $ids);
            $rev = $this->db->query("SELECT id FROM journal_entries WHERE source_type = 'adjusting' AND source_id IN ($in) AND status = 'posted'")
                            ->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_unique(array_merge($ids, array_map('intval', $rev))));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Fiscal years and dates the columns stand for. Pure. */
    public static function periods(string $asOf, string $fye): array
    {
        $fy = FiscalYear::of($asOf, $fye);
        [$fyStart] = FiscalYear::bounds($fy, $fye);
        return [
            'as_of' => $asOf, 'fy' => $fy, 'fy_start' => $fyStart,
            'py' => $fy - 1, 'py_end' => FiscalYear::bounds($fy - 1, $fye)[1],
            'py2' => $fy - 2, 'py2_end' => FiscalYear::bounds($fy - 2, $fye)[1],
        ];
    }

    /**
     * Per-account sums for every column, in one grouped query.
     * @return array{accounts: array, sums: array, present: array, periods: array}
     */
    public function load(string $asOf): array
    {
        $fye = FiscalYear::setting($this->db);
        $p = self::periods($asOf, $fye);
        $pyIds = $this->closingEntryIds($p['py']) ?: [0];
        $py2Ids = $this->closingEntryIds($p['py2']) ?: [0];
        $kind = $this->hasKind() ? "je.entry_kind IN ('aje', 'rje')" : '1 = 0';
        $sql = "SELECT jl.account_id,
                   SUM(CASE WHEN je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END) AS adjusted,
                   SUM(CASE WHEN je.entry_date BETWEEN ? AND ? AND $kind THEN jl.debit - jl.credit ELSE 0 END) AS aje,
                   SUM(CASE WHEN je.entry_date <= ? OR je.id IN (" . implode(',', array_map('intval', $pyIds)) . ") THEN jl.debit - jl.credit ELSE 0 END) AS py,
                   SUM(CASE WHEN je.entry_date <= ? OR je.id IN (" . implode(',', array_map('intval', $py2Ids)) . ") THEN jl.debit - jl.credit ELSE 0 END) AS py2,
                   SUM(CASE WHEN je.entry_date <= ? OR je.id IN (" . implode(',', array_map('intval', $pyIds)) . ") THEN 1 ELSE 0 END) AS n_py,
                   SUM(CASE WHEN je.entry_date <= ? OR je.id IN (" . implode(',', array_map('intval', $py2Ids)) . ") THEN 1 ELSE 0 END) AS n_py2
                FROM journal_lines jl
                JOIN journal_entries je ON je.id = jl.entry_id
                WHERE je.status = 'posted'
                GROUP BY jl.account_id";
        $st = $this->db->prepare($sql);
        $st->execute([$asOf, $p['fy_start'], $asOf, $p['py_end'], $p['py2_end'], $p['py_end'], $p['py2_end']]);
        $sums = []; $nPy = 0; $nPy2 = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sums[(int)$r['account_id']] = ['adjusted' => (float)$r['adjusted'], 'aje' => (float)$r['aje'], 'py' => (float)$r['py'], 'py2' => (float)$r['py2']];
            $nPy += (int)$r['n_py']; $nPy2 += (int)$r['n_py2'];
        }
        $gifi = $this->hasGifi() ? 'gifi_code, lead_group' : 'NULL AS gifi_code, NULL AS lead_group';
        $accounts = $this->db->query("SELECT id, code, name, type, sub_type, normal_balance, parent_id, display_order, $gifi
                                      FROM chart_of_accounts ORDER BY display_order, code")->fetchAll(PDO::FETCH_ASSOC);
        return ['accounts' => $accounts, 'sums' => $sums, 'present' => ['py' => $nPy > 0, 'py2' => $nPy2 > 0], 'periods' => $p];
    }

    /**
     * The trial balance rows and totals. Pure.
     * @param array $accounts chart rows (id, code, name, type, gifi_code, lead_group)
     * @param array $sums     account id => [adjusted, aje, py, py2] (debit − credit)
     * @param array $present  ['py' => bool, 'py2' => bool] — is that year in the journal at all
     * @param array $proposed account id => proposed AJE amount (step 3; empty until then)
     */
    public static function buildRows(array $accounts, array $sums, array $present, array $proposed = []): array
    {
        $rows = [];
        $tot = array_fill_keys(self::COLUMNS, 0.0);
        $dr = array_fill_keys(self::COLUMNS, 0.0);
        foreach ($accounts as $a) {
            $id = (int)$a['id'];
            $s = $sums[$id] ?? ['adjusted' => 0.0, 'aje' => 0.0, 'py' => 0.0, 'py2' => 0.0];
            $adj = round((float)$s['adjusted'], 2);
            $aje = round((float)$s['aje'], 2);
            $v = [
                'unadjusted' => round($adj - $aje, 2),
                'proposed'   => $proposed ? round((float)($proposed[$id] ?? 0), 2) : null,   // null = nothing proposed yet (step 3)
                'aje'        => $aje,
                'adjusted'   => $adj,
                'py'         => empty($present['py']) ? null : round((float)$s['py'], 2),
                'py2'        => empty($present['py2']) ? null : round((float)$s['py2'], 2),
            ];
            $any = false;
            foreach ($v as $x) if ($x !== null && abs($x) >= self::CENT) { $any = true; break; }
            if (!$any) continue;
            foreach ($v as $k => $x) {
                if ($x === null) continue;
                $tot[$k] += $x;
                if ($x > 0) $dr[$k] += $x;
            }
            $rows[] = [
                'id' => $id, 'code' => (string)$a['code'], 'name' => (string)$a['name'], 'type' => (string)$a['type'], 'sub_type' => $a['sub_type'] ?? null,
                'gifi_code' => $a['gifi_code'] ?? null, 'lead_group' => $a['lead_group'] ?? null,
            ] + $v;
        }
        $balanced = [];
        foreach (self::COLUMNS as $k) {
            $tot[$k] = round($tot[$k], 2);
            $dr[$k] = round($dr[$k], 2);
            $has = $k === 'proposed' ? (bool)$proposed : (!in_array($k, ['py', 'py2'], true) || !empty($present[$k]));
            $balanced[$k] = !$has || abs($tot[$k]) < self::CENT;
        }
        return [
            'rows' => $rows, 'totals' => $tot, 'debits' => $dr, 'balanced' => $balanced,
            'in_balance' => !in_array(false, $balanced, true), 'present' => $present + ['proposed' => (bool)$proposed],
        ];
    }

    /** The comparative trial balance at $asOf. */
    public function build(string $asOf): array
    {
        $l = $this->load($asOf);
        $tb = self::buildRows($l['accounts'], $l['sums'], $l['present']);
        $tb['periods'] = $l['periods'];
        $tb['filed'] = $this->filedCheck($tb['rows'], $l['periods']);
        return $tb;
    }

    /**
     * The filed statements beside the PY column: each filed balance-sheet line (fy_filed_balances,
     * migration 1238) against the PY column summed over its accounts (Fy2026OpeningService's mapping).
     * Empty when the business has no filed figures recorded.
     */
    public function filedCheck(array $rows, array $periods): array
    {
        try {
            $st = $this->db->prepare("SELECT fiscal_year, line, label, side, amount, source FROM fy_filed_balances WHERE fiscal_year IN (?, ?) ORDER BY fiscal_year DESC, id");
            $st->execute([$periods['py'], $periods['py2']]);
            $filed = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        if (!$filed) return [];
        require_once dirname(__DIR__, 2) . '/Accounting/Services/Fy2026OpeningService.php';
        return self::filedLines($rows, $filed, (int)$periods['py']);
    }

    /**
     * Pure: filed lines vs the PY column. A balance-sheet account belongs to the filed line that
     * Fy2026OpeningService::LINES names it in (receiving account or member), else autoGroup().
     * @return array list of [fiscal_year, line, label, filed, ledger (null for years not in the column), diff]
     */
    public static function filedLines(array $rows, array $filed, int $pyYear): array
    {
        $lineOf = [];
        foreach (Fy2026OpeningService::LINES as $line => $cfg) {
            $lineOf[(string)$cfg['receive']] = $line;
            foreach (array_keys($cfg['members']) as $code) $lineOf[(string)$code] = $line;
        }
        $ledger = [];
        foreach ($rows as $r) {
            if (!in_array($r['type'], ['asset', 'liability', 'equity'], true) || $r['py'] === null) continue;
            $line = $lineOf[$r['code']] ?? Fy2026OpeningService::autoGroup($r)[0];
            $ledger[$line] = ($ledger[$line] ?? 0.0) + ($r['type'] === 'asset' ? $r['py'] : -$r['py']);
        }
        $out = [];
        foreach ($filed as $f) {
            $isPy = (int)$f['fiscal_year'] === $pyYear;
            $amt = round((float)$f['amount'], 2);
            $led = $isPy ? round($ledger[$f['line']] ?? 0.0, 2) : null;
            $out[] = ['fiscal_year' => (int)$f['fiscal_year'], 'line' => (string)$f['line'], 'label' => (string)$f['label'],
                      'filed' => $amt, 'ledger' => $led, 'diff' => $led === null ? null : round($led - $amt, 2)];
        }
        return $out;
    }

    /** Does this column hold figures (prior years in the journal; anything proposed)? Pure. */
    public static function hasColumn(array $tb, string $k): bool
    {
        if (in_array($k, ['py', 'py2', 'proposed'], true)) return !empty($tb['present'][$k]);
        return true;
    }

    /** Column headings for a set of periods. Pure. */
    public static function columnLabels(array $p, array $present): array
    {
        return [
            'unadjusted' => 'Unadjusted',
            'proposed'   => 'Proposed AJE',
            'aje'        => 'AJE',
            'adjusted'   => 'Adjusted ' . $p['as_of'],
            'py'         => 'FY' . $p['py'] . (empty($present['py']) ? ' (not in journal)' : ' filed'),
            'py2'        => 'FY' . $p['py2'] . (empty($present['py2']) ? ' (not in journal)' : ''),
        ];
    }

    /** The export table (LedgerExport::sendTable). Pure. */
    public static function exportTable(array $tb): array
    {
        $p = $tb['periods'];
        $lab = self::columnLabels($p, $tb['present']);
        $cols = [['Account', 'text', 10], ['Name', 'text', 34], ['GIFI', 'text', 7], ['Lead', 'text', 6]];
        foreach (self::COLUMNS as $k) $cols[] = [$lab[$k], 'money', 15];
        $rows = [];
        foreach ($tb['rows'] as $r) {
            $cells = [$r['code'], $r['name'], $r['gifi_code'], $r['lead_group']];
            foreach (self::COLUMNS as $k) $cells[] = $r[$k];
            $rows[] = $cells;
        }
        $t = ['cells' => ['', 'Net (must be zero)', '', ''], 'bold' => true];
        foreach (self::COLUMNS as $k) $t['cells'][] = self::hasColumn($tb, $k) ? $tb['totals'][$k] : null;
        $rows[] = $t;
        $d = ['cells' => ['', 'Total debits', '', ''], 'bold' => true];
        foreach (self::COLUMNS as $k) $d['cells'][] = self::hasColumn($tb, $k) ? $tb['debits'][$k] : null;
        $rows[] = $d;
        $notes = ['Signed balances: debits positive, credits negative. Proposed AJE is empty until proposed adjustments are built (Ledger step 3).',
                  'FY' . $p['py'] . ' = balances at ' . $p['py_end'] . ' after the FY opening entry that brings them to the filed statements (post-closing: income accounts closed to retained earnings).'];
        return ['title' => 'Trial balance (comparative)', 'period' => 'As at ' . $p['as_of'] . ' (FY' . $p['fy'] . ')',
                'from' => $p['fy_start'], 'to' => $p['as_of'], 'columns' => $cols, 'rows' => $rows, 'notes' => $notes, 'landscape' => true];
    }

    private function hasKind(): bool
    {
        try { $this->db->query("SELECT entry_kind FROM journal_entries LIMIT 1"); return true; } catch (Throwable $e) { return false; }
    }

    private function hasGifi(): bool
    {
        try { $this->db->query("SELECT gifi_code, lead_group FROM chart_of_accounts LIMIT 1"); return true; } catch (Throwable $e) { return false; }
    }
}
