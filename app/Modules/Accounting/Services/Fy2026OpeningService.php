<?php
/**
 * Fy2026OpeningService — start 2026 from the accountant's filed numbers (2026-10-07).
 *
 * FY2025 is filed (Signed FS YE2025) and locked (migration 1237). The CRM journal's balances at
 * 2025-12-31 are not the filed ones: bank drift, Jobber-era income never journaled, the RAM loan
 * split over 2600 and 2610, GST on its own accounts … This service proposes ONE adjusting entry
 * dated 2026-01-01 (source_type 'fy_opening', source_id 2025, is_adjusting = 1) whose lines:
 *
 *   - move every balance-sheet account from its CRM journal balance at 2025-12-31 to its share of
 *     the filed balance-sheet line it belongs to (LINES below; the line's receiving account takes
 *     whatever the other members don't hold);
 *   - close every revenue and expense account (and the other equity accounts — draws, 3900) to zero;
 *   - put the balancing amount on 3200 Retained Earnings, which therefore ends at the filed figure.
 *     That line = filed RE − CRM 3200; of it, (CRM implied RE − CRM 3200) only closes 2025's books
 *     and (filed RE − CRM implied RE) is the real correction.
 *
 * Member modes inside a filed line:
 *   receive    takes the filed figure minus what the other members hold
 *   statement  the bank / card statement's balance at Dec 31 (running-balance chain, read the way
 *              BankBalanceCheckService does); no statement → keep
 *   keep       stays at its CRM balance
 *   zero       moved into the receiving account (e.g. 2600 → 2610: the RAM loan's 2024 opening
 *              was put on 2600; GST accounts → 2500: the 2025 GST owing is in "due to government")
 *
 * Balance-sheet accounts that belong to no filed line are zeroed and flagged (the filed balance
 * sheet says they were nil, or the accountant folded them into another line — ask).
 *
 * Nothing is booked until Tim clicks Book (the server rebuilds the entry and refuses it if it
 * changed since he looked). Undo = a reversal (append-only journal, migration 1131).
 * ReportingService leaves the entry (and its reversal) out of income statements, so 2026's P&L
 * never shows 2025 being closed.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';

class Fy2026OpeningService
{
    public const FISCAL_YEAR = 2025;
    public const AS_OF = '2025-12-31';
    public const OPENING_DATE = '2026-01-01';
    public const SOURCE_TYPE = 'fy_opening';
    public const SOURCE = 'Signed FS YE2025 (Updated)';
    public const CENT = 0.005;

    /** The filed figures (1238 seeded them; 1242 applied the Updated FS: $86,100 dividend cleared the shareholder loan, leaving $14 due TO the shareholder = -14 here). */
    public const FILED_2025 = [
        'cash' => 11017.00, 'ar' => 51948.00, 'income_tax_receivable' => 0.00, 'ppe' => 19035.00,
        'due_from_shareholder' => -14.00, 'ap' => 5848.00, 'due_to_government' => 16432.00,
        'income_tax_payable' => 10454.00, 'loan' => 23886.00, 'share_capital' => 1.00, 'retained_earnings' => 25365.00,
    ];

    /** Filed balance-sheet line → chart accounts. */
    public const LINES = [
        'cash'                  => ['label' => 'Cash', 'side' => 'asset', 'receive' => '1010',
                                    'members' => ['1020' => 'statement', '1025' => 'statement']],
        'ar'                    => ['label' => 'Accounts receivable', 'side' => 'asset', 'receive' => '1100', 'members' => []],
        'income_tax_receivable' => ['label' => 'Income tax receivable', 'side' => 'asset', 'receive' => '1250', 'members' => []],
        'ppe'                   => ['label' => 'Property, plant & equipment (net)', 'side' => 'asset', 'receive' => '1500', 'members' => []],
        'due_from_shareholder'  => ['label' => 'Due from shareholder', 'side' => 'asset', 'receive' => '1300', 'members' => []],
        'ap'                    => ['label' => 'Accounts payable', 'side' => 'liability', 'receive' => '2100',
                                    'members' => ['2400' => 'statement']],
        'due_to_government'     => ['label' => 'Amount due to government agencies', 'side' => 'liability', 'receive' => '2500',
                                    'members' => ['2200' => 'zero', '2210' => 'zero', '2300' => 'zero']],
        'income_tax_payable'    => ['label' => 'Income tax payable', 'side' => 'liability', 'receive' => '2510', 'members' => [],
                                    'receive_name' => 'income tax payable'],
        'loan'                  => ['label' => 'Loan payable (RAM 3500HD)', 'side' => 'liability', 'receive' => '2610',
                                    'members' => ['2600' => 'zero']],
        'share_capital'         => ['label' => 'Share capital', 'side' => 'equity', 'receive' => '3100', 'members' => []],
        'retained_earnings'     => ['label' => 'Retained earnings', 'side' => 'equity', 'receive' => '3200',
                                    'members' => ['3300' => 'zero', '3900' => 'zero']],
    ];

    public const GROUP_UNMAPPED = 'unmapped';
    public const GROUP_CLOSE = 'close';

    private PDO $db;
    private LedgerService $ledger;

    public function __construct(PDO $db, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->ledger = $ledger ?? new LedgerService($db);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PURE — the opening math
    // ══════════════════════════════════════════════════════════════════════════

    /** A signed Dr − Cr amount shown on the account's normal side. Pure. */
    public static function normal(string $type, float $d): float
    {
        return round(in_array($type, ['asset', 'expense'], true) ? $d : -$d, 2);
    }

    /** Which filed line (and mode) an account belongs to when LINES doesn't name it. Pure. */
    public static function autoGroup(array $acct): array
    {
        $type = (string)$acct['type'];
        $sub = strtolower((string)($acct['sub_type'] ?? ''));
        $name = strtolower((string)($acct['name'] ?? ''));
        if ($type === 'revenue' || $type === 'expense') return [self::GROUP_CLOSE, 'zero'];
        if ($type === 'equity') return ['retained_earnings', 'zero'];
        if ($type === 'asset') {
            if ($sub === 'bank') return ['cash', 'statement'];
            if ($sub === 'receivable' && strpos($name, 'tax') === false) return ['ar', 'zero'];
            if ($sub === 'fixed' || strpos($name, 'depreciation') !== false || strpos($name, 'equipment') !== false
                || strpos($name, 'vehicle') !== false && strpos($name, 'loan') === false) return ['ppe', 'zero'];
            if ($sub === 'shareholder_loan' || strpos($name, 'shareholder') !== false) return ['due_from_shareholder', 'zero'];
            return [self::GROUP_UNMAPPED, 'zero'];
        }
        if ($type === 'liability') {
            if (strpos($name, 'income tax') !== false) return ['income_tax_payable', 'zero'];
            if ($sub === 'credit_card') return ['ap', 'statement'];
            if ($sub === 'payable') return ['ap', 'zero'];
            if (in_array($sub, ['tax_payable', 'tax_itc', 'gov_payable', 'payroll', 'payroll_payable'], true)
                || strpos($name, 'gst') !== false || strpos($name, 'pst') !== false || strpos($name, 'payroll') !== false
                || strpos($name, 'source deduction') !== false || strpos($name, 'government') !== false) return ['due_to_government', 'zero'];
            if ($sub === 'loan' || strpos($name, 'loan') !== false) return ['loan', 'zero'];
            return [self::GROUP_UNMAPPED, 'zero'];
        }
        return [self::GROUP_UNMAPPED, 'zero'];
    }

    /**
     * The opening entry. Pure.
     * @param array $accounts   list of [id, code, name, type, sub_type?]
     * @param array $balances   account id => Dr − Cr at 2025-12-31 (journal)
     * @param array $filed      line => filed amount (normal side)
     * @param array $statementAt code => statement balance at Dec 31 (bank: money in the account; card: owed)
     * @return array{groups:array, lines:array, retained:array, totals:array, problems:array, notes:array, signature:string, filed_check:array}
     */
    public static function plan(array $accounts, array $balances, array $filed, array $statementAt = []): array
    {
        $problems = [];
        $notes = [];
        $byCode = [];
        foreach ($accounts as $a) {
            $code = (string)$a['code'];
            if (!isset($byCode[$code])) $byCode[$code] = $a;
        }
        // Receiving account per line (by code, else by name).
        $receive = [];
        foreach (self::LINES as $line => $cfg) {
            $acct = $byCode[$cfg['receive']] ?? null;
            if ($acct === null && !empty($cfg['receive_name'])) {
                foreach ($accounts as $a) {
                    if (strpos(strtolower((string)$a['name']), $cfg['receive_name']) !== false && in_array($a['type'], ['asset', 'liability', 'equity'], true)) { $acct = $a; break; }
                }
            }
            $receive[$line] = $acct;
            if ($acct === null && abs((float)($filed[$line] ?? 0)) >= self::CENT) {
                $problems[] = 'No account ' . $cfg['receive'] . ' for "' . $cfg['label'] . '" — run migration 1238 (or add it to the chart).';
            }
        }

        // Every account: its line and mode.
        $rows = [];
        $receiveIds = [];
        foreach ($receive as $line => $a) if ($a) $receiveIds[(int)$a['id']] = $line;
        foreach ($accounts as $a) {
            $id = (int)$a['id'];
            $crmD = round((float)($balances[$id] ?? 0), 2);
            $code = (string)$a['code'];
            if (isset($receiveIds[$id])) {
                [$line, $mode] = [$receiveIds[$id], 'receive'];
            } else {
                $line = null; $mode = null;
                foreach (self::LINES as $l => $cfg) {
                    if (isset($cfg['members'][$code]) && ($byCode[$code]['id'] ?? null) == $id) { $line = $l; $mode = $cfg['members'][$code]; break; }
                }
                if ($line === null) {
                    if (abs($crmD) < self::CENT) continue;           // nothing to move
                    [$line, $mode] = self::autoGroup($a);
                }
            }
            $rows[$id] = ['account_id' => $id, 'code' => $code, 'name' => (string)$a['name'], 'type' => (string)$a['type'],
                          'group' => $line, 'mode' => $mode, 'crm_d' => $crmD, 'target_d' => null, 'note' => ''];
        }

        // Targets (Dr − Cr) for every non-receiving member.
        foreach ($rows as $id => &$r) {
            if ($r['mode'] === 'receive') continue;
            if ($r['mode'] === 'zero') { $r['target_d'] = 0.0; continue; }
            if ($r['mode'] === 'keep') { $r['target_d'] = $r['crm_d']; continue; }
            // statement
            if (array_key_exists($r['code'], $statementAt) && $statementAt[$r['code']] !== null) {
                $bal = round((float)$statementAt[$r['code']], 2);
                $r['target_d'] = $r['type'] === 'liability' ? -$bal : $bal;
                $r['note'] = 'Statement at Dec 31: $' . number_format($bal, 2);
            } else {
                $r['mode'] = 'keep';
                $r['target_d'] = $r['crm_d'];
                $r['note'] = 'No statement balance at Dec 31 — kept at the CRM figure';
            }
        }
        unset($r);

        // Receiving accounts (except retained earnings, which balances the entry).
        foreach (self::LINES as $line => $cfg) {
            if ($line === 'retained_earnings') continue;
            $acct = $receive[$line];
            if (!$acct) continue;
            $id = (int)$acct['id'];
            $filedD = round($cfg['side'] === 'asset' ? (float)($filed[$line] ?? 0) : -(float)($filed[$line] ?? 0), 2);
            $others = 0.0;
            foreach ($rows as $oid => $o) if ($o['group'] === $line && $oid !== $id) $others += (float)$o['target_d'];
            $rows[$id]['target_d'] = round($filedD - $others, 2);
            if (abs($others) >= self::CENT) {
                $rows[$id]['note'] = 'Filed $' . number_format((float)($filed[$line] ?? 0), 2) . ' less $' .
                    number_format(abs(self::normal($cfg['side'] === 'asset' ? 'asset' : 'liability', $others)), 2) . ' on the other ' . $cfg['label'] . ' accounts';
            }
        }

        // Cash: what's left on 1010 vs its own statement.
        $cashId = $receive['cash'] ? (int)$receive['cash']['id'] : null;
        if ($cashId !== null && isset($statementAt['1010']) && $statementAt['1010'] !== null) {
            $diff = round($rows[$cashId]['target_d'] - (float)$statementAt['1010'], 2);
            $rows[$cashId]['note'] = 'Statement at Dec 31: $' . number_format((float)$statementAt['1010'], 2) .
                (abs($diff) >= self::CENT ? '; filed cash less the savings accounts leaves $' . number_format($rows[$cashId]['target_d'], 2) .
                 ' — the $' . number_format($diff, 2) . ' difference stays on 1010 (ask the accountant: cash in transit / cheques not yet cashed?)' : ' — matches');
            if (abs($diff) >= self::CENT) $notes[] = $rows[$cashId]['note'];
        }

        // Retained earnings balances the entry.
        $reAcct = $receive['retained_earnings'];
        $sumAdj = 0.0;
        foreach ($rows as $id => $r) {
            if ($reAcct && $id === (int)$reAcct['id']) continue;
            $sumAdj += (float)$r['target_d'] - $r['crm_d'];
        }
        if ($reAcct) {
            $reId = (int)$reAcct['id'];
            $rows[$reId]['target_d'] = round($rows[$reId]['crm_d'] - $sumAdj, 2);
        }

        // Lines, groups.
        $lines = [];
        $groups = [];
        $totalDr = 0.0; $totalCr = 0.0;
        foreach (array_merge(array_keys(self::LINES), [self::GROUP_UNMAPPED, self::GROUP_CLOSE]) as $g) {
            $cfg = self::LINES[$g] ?? null;
            $groups[$g] = ['line' => $g,
                           'label' => $cfg ? $cfg['label'] : ($g === self::GROUP_CLOSE ? '2025 revenue & expenses — closed to retained earnings' : 'On the books but not on the filed balance sheet'),
                           'side' => $cfg ? $cfg['side'] : null,
                           'filed' => $cfg ? round((float)($filed[$g] ?? 0), 2) : 0.0,
                           'crm' => 0.0, 'after' => 0.0, 'accounts' => []];
        }
        foreach ($rows as $r) {
            $adj = round((float)$r['target_d'] - $r['crm_d'], 2);
            $g = $r['group'];
            $side = self::LINES[$g]['side'] ?? null;
            $view = $side ?? $r['type'];
            $line = [
                'account_id' => $r['account_id'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'],
                'group' => $g, 'mode' => $r['mode'],
                'crm' => self::normal($view, $r['crm_d']), 'target' => self::normal($view, (float)$r['target_d']),
                'adjustment' => self::normal($view, $adj),
                'debit' => $adj > 0 ? $adj : 0.0, 'credit' => $adj < 0 ? -$adj : 0.0, 'note' => $r['note'],
            ];
            if ($g === self::GROUP_UNMAPPED && abs($r['crm_d']) >= self::CENT) {
                $line['note'] = 'Not on the filed balance sheet — zeroed (ask the accountant which line it is in)';
                $notes[] = $r['code'] . ' ' . $r['name'] . ': $' . number_format(self::normal($r['type'], $r['crm_d']), 2) . ' on the books, not on the filed balance sheet — zeroed';
            }
            $groups[$g]['crm'] = round($groups[$g]['crm'] + $line['crm'], 2);
            $groups[$g]['after'] = round($groups[$g]['after'] + $line['target'], 2);
            $groups[$g]['accounts'][] = $line;
            $totalDr += $line['debit'];
            $totalCr += $line['credit'];
            if (abs($adj) >= self::CENT) $lines[] = $line;
        }
        foreach ($groups as $g => &$grp) {
            // What this line's correction does to retained earnings (asset up → RE up; liability up → RE down).
            if ($g === 'retained_earnings' || $g === self::GROUP_CLOSE) { $grp['re_effect'] = null; continue; }
            $delta = round($grp['after'] - $grp['crm'], 2);
            $grp['re_effect'] = $grp['side'] === 'liability' || $grp['side'] === 'equity' ? -$delta : $delta;
            if ($grp['side'] === null) {   // unmapped: mixed sides — from the lines
                $e = 0.0;
                foreach ($grp['accounts'] as $l) $e += $l['debit'] - $l['credit'];
                $grp['re_effect'] = round($e, 2);
            }
        }
        unset($grp);

        // Retained earnings, explained.
        $re = ['crm_account' => 0.0, 'closing_pl' => 0.0, 'closing_equity' => 0.0, 'implied' => 0.0,
               'filed' => round((float)($filed['retained_earnings'] ?? 0), 2), 'after' => 0.0, 'adjustment' => 0.0, 'unexplained' => 0.0];
        foreach ($rows as $id => $r) {
            if ($reAcct && $id === (int)$reAcct['id']) { $re['crm_account'] = -$r['crm_d']; $re['after'] = -(float)$r['target_d']; continue; }
            if (in_array($r['type'], ['revenue', 'expense'], true)) $re['closing_pl'] -= $r['crm_d'];
            elseif ($r['type'] === 'equity' && $r['group'] === 'retained_earnings') $re['closing_equity'] -= $r['crm_d'];
        }
        foreach ($re as $k => $v) $re[$k] = round($v, 2);
        $re['implied'] = round($re['crm_account'] + $re['closing_pl'] + $re['closing_equity'], 2);
        $re['adjustment'] = round($re['after'] - $re['crm_account'], 2);
        $re['unexplained'] = round($re['filed'] - $re['implied'], 2);
        $re['matches_filed'] = abs($re['after'] - $re['filed']) < 0.01;
        if ($reAcct && !$re['matches_filed']) {
            $problems[] = sprintf('Retained earnings would end at $%s, not the filed $%s — the filed lines don\'t add up.',
                                  number_format($re['after'], 2), number_format($re['filed'], 2));
        }

        $assets = 0.0; $le = 0.0;
        foreach (self::LINES as $l => $cfg) {
            if ($cfg['side'] === 'asset') $assets += (float)($filed[$l] ?? 0); else $le += (float)($filed[$l] ?? 0);
        }
        $sig = [];
        foreach ($lines as $l) $sig[] = [$l['account_id'], round($l['debit'], 2), round($l['credit'], 2)];

        return [
            'groups' => $groups,
            'lines' => $lines,
            'retained' => $re,
            'totals' => ['debit' => round($totalDr, 2), 'credit' => round($totalCr, 2), 'balanced' => abs($totalDr - $totalCr) < 0.01],
            'problems' => $problems,
            'notes' => $notes,
            'signature' => sha1(json_encode($sig)),
            'filed_check' => ['assets' => round($assets, 2), 'liabilities_equity' => round($le, 2), 'balanced' => abs($assets - $le) < 0.01],
        ];
    }

    /**
     * An account's statement balance at the end of $ym, from BankBalanceCheckService::statements()
     * output (closing per month, opening, first_date). Pure.
     */
    public static function balanceAt(array $st, string $ym): ?float
    {
        $best = null; $bestYm = '';
        foreach ($st['closing'] ?? [] as $m => $bal) {
            if ($m <= $ym && $m > $bestYm) { $best = (float)$bal; $bestYm = $m; }
        }
        if ($best !== null) return round($best, 2);
        $end = date('Y-m-t', strtotime($ym . '-01'));
        if (!empty($st['first_date']) && $st['first_date'] > $end && ($st['opening'] ?? null) !== null) return round((float)$st['opening'], 2);
        return null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════════

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM fy_filed_balances LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return list<array{id:int, code:string, name:string, type:string, sub_type:?string}> */
    public function accounts(): array
    {
        try {
            $rows = $this->db->query("SELECT id, code, name, type, sub_type FROM chart_of_accounts ORDER BY code, id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = $this->db->query("SELECT id, code, name, type FROM chart_of_accounts ORDER BY code, id")->fetchAll(PDO::FETCH_ASSOC);
        }
        return array_map(fn($r) => ['id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'],
                                    'type' => (string)$r['type'], 'sub_type' => $r['sub_type'] ?? null], $rows);
    }

    /** account id => Dr − Cr of posted entries dated on/before $asOf. */
    public function balances(string $asOf = self::AS_OF): array
    {
        $s = $this->db->prepare("
            SELECT jl.account_id, COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS net
            FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
            WHERE je.status = 'posted' AND je.entry_date <= ?
            GROUP BY jl.account_id
        ");
        $s->execute([$asOf]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['account_id']] = round((float)$r['net'], 2);
        return $out;
    }

    /** line => filed amount for the fiscal year (table, else the constants). */
    public function filed(int $year = self::FISCAL_YEAR): array
    {
        try {
            $s = $this->db->prepare("SELECT line, amount FROM fy_filed_balances WHERE fiscal_year = ?");
            $s->execute([$year]);
            $rows = $s->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($rows) return array_map(fn($v) => round((float)$v, 2), $rows);
        } catch (Throwable $e) { /* before migration 1238 */ }
        return $year === self::FISCAL_YEAR ? self::FILED_2025 : [];
    }

    /** code => statement balance at Dec 31 for the bank accounts and the card (null = unknown). */
    public function statementBalances(): array
    {
        $out = [];
        try {
            require_once __DIR__ . '/BankBalanceCheckService.php';
            $bbc = new BankBalanceCheckService($this->db, $this->ledger);
            $accounts = $bbc->trackedAccounts();
            foreach ($bbc->statements($accounts) as $aid => $st) {
                if (!isset($accounts[$aid])) continue;
                $out[$accounts[$aid]['code']] = self::balanceAt($st, substr(self::AS_OF, 0, 7));
            }
        } catch (Throwable $e) { /* no statements */ }
        return $out;
    }

    /** The live (not reversed) opening entry, or null. */
    public function existing(): ?array
    {
        try {
            $s = $this->db->prepare("SELECT je.id, je.entry_date, je.memo, COALESCE(SUM(jl.debit), 0) AS total
                                     FROM journal_entries je LEFT JOIN journal_lines jl ON jl.entry_id = je.id
                                     WHERE je.source_type = ? AND je.source_id = ? AND je.reversed_by_entry_id IS NULL
                                     GROUP BY je.id, je.entry_date, je.memo ORDER BY je.id DESC LIMIT 1");
            $s->execute([self::SOURCE_TYPE, self::FISCAL_YEAR]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        return $r ? ['entry_id' => (int)$r['id'], 'entry_date' => substr((string)$r['entry_date'], 0, 10),
                     'memo' => (string)$r['memo'], 'total' => round((float)$r['total'], 2)] : null;
    }

    /** Everything the page shows. Read only. */
    public function preview(): array
    {
        $plan = self::plan($this->accounts(), $this->balances(), $this->filed(), $this->statementBalances());
        $locked = false;
        try { $locked = $this->ledger->isLocked(self::OPENING_DATE); } catch (Throwable $e) { /* no periods */ }
        return $plan + [
            'ready' => $this->ready(),
            'as_of' => self::AS_OF,
            'opening_date' => self::OPENING_DATE,
            'source' => self::SOURCE,
            'booked' => $this->existing(),
            'locked' => $locked,
        ];
    }

    /** Book the opening entry. Rebuilt here; refused when it differs from what Tim looked at. */
    public function book(string $signature, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1238 first (the filed figures and the accounts it needs).'];
        if ($booked = $this->existing()) return ['ok' => false, 'message' => 'The FY2026 opening is already booked (entry #' . $booked['entry_id'] . '). Undo it first to book it again.'];
        if ($this->ledger->isLocked(self::OPENING_DATE)) return ['ok' => false, 'message' => 'January 2026 is locked — unlock it to book the opening.'];
        $plan = self::plan($this->accounts(), $this->balances(), $this->filed(), $this->statementBalances());
        if (!hash_equals($plan['signature'], $signature)) {
            return ['ok' => false, 'message' => 'The books changed since you looked — reload the page and check the lines again.'];
        }
        if ($plan['problems']) return ['ok' => false, 'message' => implode(' ', $plan['problems'])];
        if (!$plan['totals']['balanced'] || count($plan['lines']) < 2) return ['ok' => false, 'message' => 'Nothing to book, or the entry does not balance.'];

        $lines = [];
        foreach ($plan['lines'] as $l) {
            $desc = sprintf('%s: CRM had $%s, %s $%s', $plan['groups'][$l['group']]['label'] ?? $l['group'],
                            number_format($l['crm'], 2), $l['mode'] === 'zero' ? 'closed to' : 'filed/target', number_format($l['target'], 2));
            $lines[] = ['account_id' => $l['account_id'], 'debit' => $l['debit'], 'credit' => $l['credit'],
                        'description' => mb_substr($desc, 0, 255)];
        }
        $id = $this->ledger->postManual([
            'entry_date' => self::OPENING_DATE,
            'memo' => 'FY2026 opening — balances at Dec 31, 2025 per ' . self::SOURCE . '; 2025 revenue & expenses closed to retained earnings',
            'source_type' => self::SOURCE_TYPE, 'source_id' => self::FISCAL_YEAR, 'is_adjusting' => 1,
            'created_by' => $userId, 'proposed_by' => 'owner', 'lines' => $lines,
        ]);
        return ['ok' => true, 'message' => 'FY2026 opening booked (entry #' . $id . ', ' . count($lines) . ' lines). Retained earnings now start at $' .
                number_format($plan['retained']['after'], 2) . '.', 'entry_id' => $id];
    }

    /** Undo = reverse the opening entry (dated 2026-01-01 unless January is locked). */
    public function undo(int $userId): array
    {
        $booked = $this->existing();
        if (!$booked) return ['ok' => false, 'message' => 'There is no FY2026 opening entry to undo.'];
        $rev = $this->ledger->reverseEntry($booked['entry_id'], $userId, 'FY2026 opening undone', 'owner');
        return ['ok' => true, 'message' => 'Opening entry #' . $booked['entry_id'] . ' reversed (entry #' . $rev . ').', 'entry_id' => $rev];
    }

    /** chart id => Dr − Cr of the live opening entry (BankBalanceCheckService reads it). */
    public function openingNetByAccount(): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("SELECT jl.account_id, COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS net
                                     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
                                     WHERE je.source_type = ? AND je.reversed_by_entry_id IS NULL AND je.status = 'posted'
                                     GROUP BY jl.account_id");
            $s->execute([self::SOURCE_TYPE]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['account_id']] = round((float)$r['net'], 2);
        } catch (Throwable $e) { /* none */ }
        return $out;
    }
}
