<?php
/**
 * WavePayrollImportService — the monthly Wave "Wage & Tax Report" into the books (2026-10-07).
 *
 * The accountant needs payroll in the books every month. Wave runs payroll; its report
 * ("Wages & taxes", for all paydays in a date range) has an Employer Summary and one block per
 * employee (Federal Tax / Provincial Tax / EI / CPP / CPP2 rows: wages, taxed wages, employee
 * withheld, employer expense, tax liability) followed by a payday table of HOURS. It has no net
 * pay and no dollars per payday, so the books are kept MONTHLY from a report run for one month:
 *   net pay  = wages − employee withholdings (fed + prov + EI + CPP + CPP2)
 *   remit    = employee withholdings + employer EI / CPP / CPP2   (owed to CRA)
 * A report covering several months is split by each employee's paid hours per payday month —
 * an ESTIMATE (income tax isn't linear), flagged, and booked only when Tim ticks "book anyway".
 *
 * One journal entry per month (source_type 'payroll_run', source_id = payroll_runs.id), dated the
 * month's last payday, 2026 only, never into a locked month:
 *   DR 5100 Labour — Crew Wages              gross wages
 *   DR 5110 Employer CPP & EI                employer EI + CPP + CPP2
 *   CR 2310 Source deductions payable — CRA  employee withholdings + employer share
 *   CR 2520 Net pay clearing — Wave          net pay
 *   (+ the shareholder sweep, below)
 *
 * How the money really moves (Tim, 2026-10-07): Wave debits the bank for the CRA remittance
 * ("WAVE PYRL", every pay run) and employees are paid by e-Transfer. Both sat on 5100 as
 * wages, so wages were counted twice once this entry exists. The match moves them (Penny
 * proposes, Tim approves; BankLineMoveService = reversal + repost):
 *   WAVE PYRL debits            → 2310 (subset that adds up to the month's remittance)
 *   e-Transfers to an employee  → 2520 (subset that adds up to that employee's net)
 *   WAVE payroll fee charges    → 6800 Bank Charges & Fees
 *   the shareholder's transfers to himself in the month → 2520, and the part beyond his
 *     net pay is swept DR 1300 Due from Shareholder / CR 2520 (a shortfall the other way)
 * After booking, 2310 and 2520 should be ~0 for the month; what isn't is shown for Tim.
 *
 * Also: hours per pay period against the CRM time clock (read only), and Penny's brief item
 * "<Month> payroll report not imported" from the 5th.
 *
 * Tables: migration 1255 (payroll_runs, payroll_run_employees, payroll_run_paydays,
 * payroll_bank_moves). No SINs or addresses are kept — the report has none per employee.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';
require_once __DIR__ . '/BankLineMoveService.php';

class WavePayrollImportService
{
    public const ACC_WAGES = '5100';
    public const ACC_EMPLOYER = '5110';
    public const ACC_SOURCE_DEDUCTIONS = '2310';
    public const ACC_NET_PAY = '2520';
    public const ACC_SHAREHOLDER = LedgerService::ACC_DUE_FROM_SH;   // 1300
    public const ACC_FEES = '6800';
    public const BOOK_YEAR = 2026;
    /** Exact match (cents of rounding). */
    public const TOL = 0.05;
    /** Close enough to propose, flagged and not ticked. */
    public const CLOSE_TOL = 1.00;
    public const BRIEF_FROM_DAY = 5;
    public const URL = '/crm/accounting/payroll-import.php';
    /** Most bank lines a subset search looks at. */
    private const SUBSET_MAX = 22;

    /** Report row label → key. */
    public const TAX_ROWS = [
        'Second additional CPP contribution' => 'cpp2',
        'Federal Tax' => 'fed',
        'Provincial Tax' => 'prov',
        'Employment Insurance' => 'ei',
        'Canada Pension Plan' => 'cpp',
    ];
    /** What each payroll account must be called — a code already used for something else is refused. */
    public const ACCOUNT_NAME_RE = [
        self::ACC_WAGES => '/wage|labour|labor/i',
        self::ACC_EMPLOYER => '/cpp|\bei\b|employer/i',
        self::ACC_SOURCE_DEDUCTIONS => '/source deduction/i',
        self::ACC_NET_PAY => '/net pay/i',
        self::ACC_SHAREHOLDER => '/shareholder/i',
    ];
    public const WAVE_REMIT_RE = '/\bWAVE\b.*\bPYRL\b|\bPYRL\b.*\bWAVE\b/i';
    public const WAVE_FEE_RE = '/\bWAVE\b.*PAYROLL\s*FEE/i';

    private PDO $db;
    private LedgerService $ledger;

    public function __construct(PDO $db, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->ledger = $ledger ?? new LedgerService($db);
    }

    public function ready(): bool
    {
        return $this->hasTable('payroll_runs') && $this->hasTable('payroll_bank_moves');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Parse (pure)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * The report's text (smalot/pdfparser, or pasted) → range, employer summary, employees.
     * @return array{from: ?string, to: ?string, summary: array, employees: array, warnings: string[]}
     */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/Wage and Tax Report generated by Wave Financial/i', "\n", $text);
        $flat = preg_replace('/\s+/', ' ', $text);
        $from = $to = null;
        if (preg_match('#paydays\s+during\s+(\d{1,2}/\d{1,2}/\d{4})\s+to\s+(\d{1,2}/\d{1,2}/\d{4})#i', $flat, $m)) {
            $from = self::ymd($m[1]);
            $to = self::ymd($m[2]);
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
        $summary = [];
        $employees = [];
        $cur = null;
        $section = null;
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $l = $lines[$i];
            if (preg_match('/^Employer Summary\b/i', $l)) {
                if ($cur !== null) $employees[] = $cur;
                $cur = null;
                $section = 'summary';
                continue;
            }
            if (preg_match('/^([^\d$,\t]+),\s*([^\d$,\t]+)$/u', $l, $m) && isset($lines[$i + 1]) && preg_match('/^Name\b/i', $lines[$i + 1])) {
                if ($cur !== null) $employees[] = $cur;
                $first = trim($m[2]);
                $last = trim($m[1]);
                $cur = ['first' => $first, 'last' => $last, 'name' => $first . ' ' . $last, 'taxes' => [], 'paydays' => []];
                $section = 'employee';
                continue;
            }
            $row = self::taxRow($l);
            if ($row) {
                if ($section === 'summary') $summary[$row[0]] = $row[1];
                elseif ($cur !== null) $cur['taxes'][$row[0]] = $row[1];
                continue;
            }
            if ($cur !== null && preg_match('#^(\d{1,2}/\d{1,2}/\d{4})\s+(\d{1,2}/\d{1,2}/\d{4})\s+to\s+(\d{1,2}/\d{1,2}/\d{4})\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)#', $l, $m)) {
                $cur['paydays'][] = ['payday' => self::ymd($m[1]), 'period_start' => self::ymd($m[2]), 'period_end' => self::ymd($m[3]),
                                     'regular' => (float)$m[4], 'overtime' => (float)$m[5], 'double_time' => (float)$m[6],
                                     'vacation' => (float)$m[7], 'sick' => (float)$m[8]];
            }
        }
        if ($cur !== null) $employees[] = $cur;

        foreach ($employees as &$e) $e['totals'] = self::employeeTotals($e['taxes']);
        unset($e);
        return ['from' => $from, 'to' => $to, 'summary' => $summary, 'employees' => $employees,
                'warnings' => self::checkParsed($summary, $employees)];
    }

    /** "Federal Tax $1.00 $1.00 $0.10 $0.00 $0.10" → ['fed', {wages, taxed, ee, er, liability}] */
    public static function taxRow(string $line): ?array
    {
        foreach (self::TAX_ROWS as $label => $key) {
            if (stripos($line, $label) !== 0) continue;
            preg_match_all('/\(?-?\$-?[\d,]+\.\d{2}\)?/', substr($line, strlen($label)), $mm);
            if (count($mm[0]) < 5) return null;
            $v = array_map([self::class, 'money'], array_slice($mm[0], 0, 5));
            return [$key, ['wages' => $v[0], 'taxed' => $v[1], 'ee' => $v[2], 'er' => $v[3], 'liability' => $v[4]]];
        }
        return null;
    }

    public static function money(string $s): float
    {
        $neg = strpos($s, '-') !== false || strpos($s, '(') !== false;
        $v = (float)str_replace([',', '$', '(', ')', '-'], '', $s);
        return round($neg ? -$v : $v, 2);
    }

    private static function ymd(string $mdy): string
    {
        [$m, $d, $y] = array_map('intval', explode('/', $mdy));
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** One employee's totals from their five rows. */
    public static function employeeTotals(array $taxes): array
    {
        $t = ['wages' => 0.0];
        foreach (self::TAX_ROWS as $key) {
            $t[$key] = round((float)($taxes[$key]['ee'] ?? 0), 2);
            $t['er_' . $key] = round((float)($taxes[$key]['er'] ?? 0), 2);
            $t['wages'] = max($t['wages'], (float)($taxes[$key]['wages'] ?? 0));
        }
        return self::withNet($t);
    }

    /** wages + the ten tax amounts → + withholdings, employer, net, remittance. */
    public static function withNet(array $t): array
    {
        $t['withholdings'] = round($t['fed'] + $t['prov'] + $t['ei'] + $t['cpp'] + $t['cpp2'], 2);
        $t['employer'] = round($t['er_fed'] + $t['er_prov'] + $t['er_ei'] + $t['er_cpp'] + $t['er_cpp2'], 2);
        $t['net'] = round($t['wages'] - $t['withholdings'], 2);
        $t['remittance'] = round($t['withholdings'] + $t['employer'], 2);
        return $t;
    }

    /** The employees must add up to the Employer Summary, and each row ee + er = liability. */
    public static function checkParsed(array $summary, array $employees): array
    {
        $w = [];
        if (!$employees) $w[] = 'No employees were found in the report.';
        if (!$summary) $w[] = 'The Employer Summary was not found.';
        foreach ($summary as $key => $row) {
            $ee = 0.0; $er = 0.0; $wages = 0.0;
            foreach ($employees as $e) {
                $ee += (float)($e['taxes'][$key]['ee'] ?? 0);
                $er += (float)($e['taxes'][$key]['er'] ?? 0);
                $wages += (float)($e['taxes'][$key]['wages'] ?? 0);
            }
            $label = array_search($key, self::TAX_ROWS, true);
            if (abs($ee - $row['ee']) > 0.02 || abs($er - $row['er']) > 0.02) {
                $w[] = sprintf('%s: employees add up to $%s withheld / $%s employer, the summary says $%s / $%s.',
                               $label, number_format($ee, 2), number_format($er, 2), number_format($row['ee'], 2), number_format($row['er'], 2));
            }
            if ($key === 'fed' && abs($wages - $row['wages']) > 0.02) {
                $w[] = sprintf('Wages: employees add up to $%s, the summary says $%s.', number_format($wages, 2), number_format($row['wages'], 2));
            }
        }
        foreach ($employees as $e) {
            foreach ($e['taxes'] as $key => $r) {
                if (abs($r['ee'] + $r['er'] - $r['liability']) > 0.02) {
                    $w[] = $e['name'] . ': ' . array_search($key, self::TAX_ROWS, true) . ' withheld + employer is not the tax liability.';
                }
            }
            if (count($e['taxes']) < 5) $w[] = $e['name'] . ': only ' . count($e['taxes']) . ' of the 5 tax rows were read.';
        }
        return $w;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Months (pure)
    // ═════════════════════════════════════════════════════════════════════════

    /** Paid-hours weight of a payday (overtime ×1.5, double time ×2). */
    public static function weight(array $p): float
    {
        return (float)$p['regular'] + 1.5 * (float)$p['overtime'] + 2 * (float)$p['double_time'] + (float)$p['vacation'] + (float)$p['sick'];
    }

    /**
     * Parsed report → months keyed 'YYYY-MM' (by payday). One payday month = exact; several =
     * each employee's dollars split by their paid hours per month (estimate).
     * @return array<string, array{month: string, estimate: bool, last_payday: string, employees: array, totals: array}>
     */
    public static function months(array $parsed): array
    {
        $all = [];
        foreach ($parsed['employees'] as $e) foreach ($e['paydays'] as $p) $all[substr($p['payday'], 0, 7)] = true;
        if (!$all && !empty($parsed['to'])) $all[substr($parsed['to'], 0, 7)] = true;
        ksort($all);
        $yms = array_keys($all);
        $estimate = count($yms) > 1;
        $keys = array_merge(['wages'], array_values(self::TAX_ROWS), array_map(fn($k) => 'er_' . $k, array_values(self::TAX_ROWS)));

        $out = [];
        foreach ($yms as $ym) $out[$ym] = ['month' => $ym, 'estimate' => $estimate, 'last_payday' => '', 'employees' => [], 'totals' => []];
        foreach ($parsed['employees'] as $e) {
            $byMonth = [];
            foreach ($e['paydays'] as $p) $byMonth[substr($p['payday'], 0, 7)][] = $p;
            if (!$byMonth) $byMonth[end($yms)] = [];
            $weights = [];
            foreach ($byMonth as $ym => $ps) $weights[$ym] = array_sum(array_map([self::class, 'weight'], $ps));
            $total = array_sum($weights);
            if ($total <= 0) {
                foreach ($byMonth as $ym => $ps) $weights[$ym] = max(1, count($ps));
                $total = array_sum($weights);
            }
            ksort($weights);
            $last = array_key_last($weights);
            $left = $e['totals'];
            foreach ($weights as $ym => $wt) {
                $t = [];
                foreach ($keys as $k) {
                    $t[$k] = $ym === $last ? round($left[$k], 2) : round($e['totals'][$k] * $wt / $total, 2);
                    $left[$k] = round($left[$k] - $t[$k], 2);
                }
                $t = self::withNet($t);
                if (abs($t['wages']) < 0.005 && abs($t['remittance']) < 0.005) continue;   // nothing paid that month
                $ps = $byMonth[$ym] ?? [];
                $t['hours'] = round(array_sum(array_map(fn($p) => (float)$p['regular'] + (float)$p['overtime'] + (float)$p['double_time'], $ps)), 2);
                $out[$ym]['employees'][] = ['name' => $e['name'], 'first' => $e['first'], 'last' => $e['last'], 'totals' => $t, 'paydays' => $ps];
                foreach ($ps as $p) if ($p['payday'] > $out[$ym]['last_payday']) $out[$ym]['last_payday'] = $p['payday'];
            }
        }
        foreach ($out as $ym => &$mo) {
            $t = array_fill_keys($keys, 0.0);
            foreach ($mo['employees'] as $e) foreach ($keys as $k) $t[$k] = round($t[$k] + $e['totals'][$k], 2);
            $mo['totals'] = self::withNet($t);
            if ($mo['last_payday'] === '') $mo['last_payday'] = date('Y-m-t', strtotime($ym . '-01'));
        }
        unset($mo);
        return array_filter($out, fn($m) => $m['employees']);
    }

    /**
     * The month's journal entry (account CODES; LedgerService resolves them). $sweep = the
     * shareholder's transfers beyond his net pay (>0: DR 1300 / CR 2520; <0 the other way).
     */
    public static function buildEntry(array $totals, float $sweep, string $date, string $memo): array
    {
        $lines = [];
        $add = function (string $code, float $dr, float $cr, string $desc) use (&$lines) {
            $dr = round($dr, 2); $cr = round($cr, 2);
            if ($dr < 0) { $cr -= $dr; $dr = 0; }
            if ($cr < 0) { $dr -= $cr; $cr = 0; }
            if ($dr < 0.005 && $cr < 0.005) return;
            $lines[] = ['account' => $code, 'debit' => $dr, 'credit' => $cr, 'description' => $desc];
        };
        $add(self::ACC_WAGES, $totals['wages'], 0, 'Gross wages');
        $add(self::ACC_EMPLOYER, $totals['employer'], 0, 'Employer EI + CPP');
        $add(self::ACC_SOURCE_DEDUCTIONS, 0, $totals['remittance'], 'Source deductions owed to CRA');
        $add(self::ACC_NET_PAY, 0, $totals['net'], 'Net pay to employees');
        if (abs($sweep) >= 0.005) {
            $add(self::ACC_SHAREHOLDER, $sweep > 0 ? $sweep : 0, $sweep < 0 ? -$sweep : 0, 'Shareholder transfers beyond net pay');
            $add(self::ACC_NET_PAY, $sweep < 0 ? -$sweep : 0, $sweep > 0 ? $sweep : 0, 'Shareholder transfers beyond net pay');
        }
        return ['entry_date' => $date, 'memo' => $memo, 'lines' => $lines];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Bank matching (pure)
    // ═════════════════════════════════════════════════════════════════════════

    /** Uppercase words of a bank description (non-letters → spaces). */
    private static function words(string $s): string
    {
        return ' ' . trim(preg_replace('/[^A-Z]+/', ' ', strtoupper($s))) . ' ';
    }

    /**
     * Is this description a payment to $emp? First + last name; or one of them alone when no
     * other employee shares it (bank descriptions get truncated).
     */
    public static function nameMatches(string $description, array $emp, array $all): bool
    {
        $d = self::words($description);
        $first = trim(self::words($emp['first']));
        $last = trim(self::words($emp['last']));
        $hasFirst = $first !== '' && strpos($d, ' ' . $first . ' ') !== false;
        $hasLast = $last !== '' && strpos($d, ' ' . $last . ' ') !== false;
        if ($hasFirst && $hasLast) return true;
        $shares = function (string $field, string $v) use ($all, $emp) {
            foreach ($all as $o) {
                if ($o['name'] === $emp['name']) continue;
                if (trim(self::words($o[$field])) === $v) return true;
            }
            return false;
        };
        if ($hasFirst && strlen($first) >= 3 && !$shares('first', $first)) return true;
        if ($hasLast && strlen($last) >= 4 && !$shares('last', $last)) return true;
        return false;
    }

    /**
     * Ids of lines whose amounts add up to $target (within $tol), or null. Lines: id => amount.
     */
    public static function subsetSum(array $lines, float $target, float $tol = self::TOL): ?array
    {
        if ($target <= 0) return null;
        arsort($lines);
        $lines = array_slice($lines, 0, self::SUBSET_MAX, true);
        $ids = array_keys($lines);
        $cents = array_map(fn($a) => (int)round($a * 100), array_values($lines));
        $t = (int)round($target * 100);
        $tl = (int)round($tol * 100);
        $n = count($cents);
        $suffix = array_fill(0, $n + 1, 0);
        for ($i = $n - 1; $i >= 0; $i--) $suffix[$i] = $suffix[$i + 1] + $cents[$i];
        $pick = [];
        $found = null;
        $steps = 0;
        $go = function (int $i, int $sum) use (&$go, &$pick, &$found, &$steps, $cents, $ids, $suffix, $t, $tl, $n) {
            if ($found !== null || ++$steps > 400000) return;
            if (abs($sum - $t) <= $tl && $pick) { $found = $pick; return; }
            if ($i >= $n || $sum - $tl > $t || $sum + $suffix[$i] + $tl < $t) return;
            $pick[] = $ids[$i];
            $go($i + 1, $sum + $cents[$i]);
            array_pop($pick);
            $go($i + 1, $sum);
        };
        $go(0, 0);
        return $found;
    }

    private static function addDays(string $date, int $days): string
    {
        return date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
    }

    /**
     * Penny's proposal for one month. $lines: bank lines [id, date, amount, description,
     * direction ('in'|'out'), account_code]. $claimed: ids taken by earlier months (updated).
     * $shareholder: the employee name who is the shareholder (his transfers → net pay + sweep).
     * @return array{remittance: array, employees: array, shareholder: ?array, fees: array, moves: array, clearing: array}
     */
    public static function matchMonth(array $month, array $lines, ?string $shareholder, array &$claimed): array
    {
        $ym = $month['month'];
        $start = $ym . '-01';
        $end = date('Y-m-t', strtotime($start));
        $out = array_values(array_filter($lines, fn($l) => ($l['direction'] ?? 'out') === 'out' && !isset($claimed[$l['id']])));
        $moves = [];
        $mk = function (array $l, string $role, string $code, bool $checked, ?string $emp = null) {
            return ['id' => (int)$l['id'], 'date' => $l['date'], 'amount' => round((float)$l['amount'], 2), 'description' => $l['description'],
                    'role' => $role, 'from' => $l['account_code'] ?? null, 'to' => $code, 'checked' => $checked, 'employee' => $emp,
                    'already' => ($l['account_code'] ?? null) === $code];
        };

        // ── CRA remittance: Wave's PYRL debits ──
        $t = $month['totals'];
        $wave = [];
        foreach ($out as $l) {
            if (!preg_match(self::WAVE_REMIT_RE, $l['description']) || preg_match(self::WAVE_FEE_RE, $l['description'])) continue;
            if ($l['date'] < self::addDays($start, -10) || $l['date'] > self::addDays($end, 5)) continue;
            $wave[$l['id']] = $l;
        }
        $ids = self::subsetSum(array_map(fn($l) => (float)$l['amount'], $wave), $t['remittance']);
        $remit = ['target' => $t['remittance'], 'status' => 'none', 'found' => 0.0, 'ids' => []];
        if ($ids !== null) {
            $remit['status'] = 'match';
            foreach ($ids as $id) { $moves[] = $mk($wave[$id], 'remittance', self::ACC_SOURCE_DEDUCTIONS, true); $claimed[$id] = true; }
        } else {
            // Not exact: offer the month's own Wave debits, unticked.
            foreach ($wave as $id => $l) {
                if ($l['date'] < $start || $l['date'] > $end) continue;
                $moves[] = $mk($l, 'remittance', self::ACC_SOURCE_DEDUCTIONS, false);
                $claimed[$id] = true;
            }
            $remit['status'] = $wave ? 'differs' : 'none';
        }
        $remit['ids'] = array_values(array_map(fn($m) => $m['id'], array_filter($moves, fn($m) => $m['role'] === 'remittance')));
        $remit['found'] = round(array_sum(array_map(fn($m) => $m['amount'], array_filter($moves, fn($m) => $m['role'] === 'remittance'))), 2);
        $remit['diff'] = round($remit['target'] - $remit['found'], 2);

        // ── Wave's payroll fees → bank charges ──
        $fees = [];
        foreach ($out as $l) {
            if (isset($claimed[$l['id']]) || !preg_match(self::WAVE_FEE_RE, $l['description'])) continue;
            if ($l['date'] < $start || $l['date'] > $end) continue;
            $m = $mk($l, 'fee', self::ACC_FEES, true);
            $fees[] = $m['id'];
            $moves[] = $m;
            $claimed[$l['id']] = true;
        }

        // ── Net pay: e-Transfers to each employee ──
        $emps = [];
        $sh = null;
        foreach ($month['employees'] as $e) {
            $mine = [];
            $isSh = $shareholder !== null && strcasecmp(trim($e['name']), trim($shareholder)) === 0;
            foreach ($out as $l) {
                if (isset($claimed[$l['id']]) || preg_match(self::WAVE_REMIT_RE, $l['description'])) continue;
                $lo = $isSh ? $start : self::addDays($start, -3);
                $hi = $isSh ? $end : self::addDays($end, 5);
                if ($l['date'] < $lo || $l['date'] > $hi) continue;
                if (!self::nameMatches($l['description'], $e, $month['employees'])) continue;
                $mine[$l['id']] = $l;
            }
            $net = (float)$e['totals']['net'];
            if ($isSh) {
                // Every transfer to himself that month: net pay first, the rest is a shareholder withdrawal.
                foreach ($mine as $id => $l) { $moves[] = $mk($l, 'shareholder', self::ACC_NET_PAY, true, $e['name']); $claimed[$id] = true; }
                $total = round(array_sum(array_map(fn($l) => (float)$l['amount'], $mine)), 2);
                $sh = ['name' => $e['name'], 'net' => round($net, 2), 'transfers' => $total, 'sweep' => $mine ? round($total - $net, 2) : 0.0,
                       'ids' => array_keys($mine)];
                continue;
            }
            $row = ['name' => $e['name'], 'target' => round($net, 2), 'status' => 'none', 'found' => 0.0, 'ids' => [], 'candidates' => count($mine)];
            $ids = self::subsetSum(array_map(fn($l) => (float)$l['amount'], $mine), $net);
            if ($ids === null) {
                $close = self::subsetSum(array_map(fn($l) => (float)$l['amount'], $mine), $net, self::CLOSE_TOL);
                if ($close !== null) { $ids = $close; $row['status'] = 'close'; }
            } else {
                $row['status'] = 'match';
            }
            foreach ($ids ?? [] as $id) {
                $moves[] = $mk($mine[$id], 'net_pay', self::ACC_NET_PAY, $row['status'] === 'match', $e['name']);
                $claimed[$id] = true;
                $row['ids'][] = $id;
                $row['found'] += (float)$mine[$id]['amount'];
            }
            $row['found'] = round($row['found'], 2);
            $row['diff'] = round($row['target'] - $row['found'], 2);
            if ($row['status'] === 'none' && $mine) $row['status'] = 'differs';
            $emps[] = $row;
        }

        return ['remittance' => $remit, 'employees' => $emps, 'shareholder' => $sh, 'fees' => $fees, 'moves' => $moves,
                'clearing' => self::clearing($t, $moves, $sh)];
    }

    /**
     * What 2310 / 2520 hold for the month once the entry and the ticked moves are booked
     * (credit balances; ~0 is right).
     */
    public static function clearing(array $totals, array $moves, ?array $sh, ?array $tickedIds = null): array
    {
        $isTicked = fn($m) => $tickedIds === null ? $m['checked'] : in_array($m['id'], $tickedIds, true);
        $remitPaid = 0.0; $netPaid = 0.0; $shPaid = 0.0;
        foreach ($moves as $m) {
            if (!$isTicked($m)) continue;
            if ($m['role'] === 'remittance') $remitPaid += $m['amount'];
            if ($m['role'] === 'net_pay') $netPaid += $m['amount'];
            if ($m['role'] === 'shareholder') $shPaid += $m['amount'];
        }
        $sweep = ($sh && $shPaid > 0) ? round($shPaid - $sh['net'], 2) : 0.0;
        return [
            'source_deductions' => round($totals['remittance'] - $remitPaid, 2),
            'net_pay' => round($totals['net'] - $netPaid - $shPaid + $sweep, 2),
            'sweep' => $sweep,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Brief (pure)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * "<Month> payroll report isn't imported" from the 5th, for last month (2026 on), when the
     * business runs payroll (any run imported, or Wave debits last month).
     * @param string[] $bookedMonths 'YYYY-MM'
     */
    public static function briefItems(array $bookedMonths, bool $inUse, string $today): array
    {
        if (!$inUse || (int)substr($today, 8, 2) < self::BRIEF_FROM_DAY) return [];
        $ym = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -1 month'));
        if ((int)substr($ym, 0, 4) < self::BOOK_YEAR || in_array($ym, $bookedMonths, true)) return [];
        $month = date('F', strtotime($ym . '-01'));
        return [['key' => 'penny:payroll:' . $ym, 'kind' => 'penny:payroll_missing', 'priority' => 2, 'value' => null,
                 'since' => substr($today, 0, 7) . '-' . sprintf('%02d', self::BRIEF_FROM_DAY),
                 'text' => "The {$month} payroll report isn't imported — download Wave's Wages & taxes for {$month} and drop it in",
                 'url' => self::URL]];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Import (DB)
    // ═════════════════════════════════════════════════════════════════════════

    /** Text out of an uploaded PDF (smalot/pdfparser — the bank statement importer's parser). */
    public static function pdfText(string $path): string
    {
        if (defined('VENDOR_ROOT') && is_file(VENDOR_ROOT . '/autoload.php')) require_once VENDOR_ROOT . '/autoload.php';
        if (!class_exists('\Smalot\PdfParser\Parser')) throw new RuntimeException('The PDF reader (smalot/pdfparser) is not installed — paste the report text instead.');
        return (new \Smalot\PdfParser\Parser())->parseFile($path)->getText();
    }

    /**
     * Store a report as one preview run per month. The same report again returns its runs.
     * @return array{ok: bool, message: string, run_ids?: int[], warnings?: string[]}
     */
    public function import(string $text, string $sourceName, int $userId): array
    {
        $parsed = self::parse($text);
        if (!$parsed['employees']) return ['ok' => false, 'message' => 'This doesn\'t read as a Wave Wage & Tax Report — no employees found.'];
        $months = self::months($parsed);
        if (!$months) return ['ok' => false, 'message' => 'No paydays with wages were found in the report.'];
        $hash = hash('sha256', preg_replace('/\s+/', ' ', $text));

        $s = $this->db->prepare("SELECT id FROM payroll_runs WHERE source_hash = ? AND status IN ('preview','booked') ORDER BY period_month");
        $s->execute([$hash]);
        $same = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        if ($same) return ['ok' => true, 'message' => 'This report is already here.', 'run_ids' => $same, 'warnings' => $parsed['warnings']];

        $ids = [];
        foreach ($months as $ym => $mo) {
            // A newer preview of the month replaces the old one (booked runs stay; booking checks).
            $old = $this->db->prepare("SELECT id FROM payroll_runs WHERE period_month = ? AND status = 'preview'");
            $old->execute([$ym]);
            foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oid) $this->deleteRun((int)$oid);

            $t = $mo['totals'];
            $this->db->prepare("INSERT INTO payroll_runs (period_month, report_from, report_to, last_payday, source_hash, source_name, is_estimate,
                                    employee_count, gross_wages, ee_fed, ee_prov, ee_ei, ee_cpp, ee_cpp2, er_ei, er_cpp, er_cpp2,
                                    withholdings, employer_cost, net_pay, remittance, warnings, status, created_by, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'preview', ?, NOW())")
                ->execute([$ym, $parsed['from'], $parsed['to'], $mo['last_payday'], $hash, mb_substr($sourceName, 0, 190), $mo['estimate'] ? 1 : 0,
                           count($mo['employees']), $t['wages'], $t['fed'], $t['prov'], $t['ei'], $t['cpp'], $t['cpp2'], $t['er_ei'], $t['er_cpp'], $t['er_cpp2'],
                           $t['withholdings'], $t['employer'], $t['net'], $t['remittance'],
                           $parsed['warnings'] ? mb_substr(implode("\n", $parsed['warnings']), 0, 2000) : null, $userId ?: null]);
            $runId = (int)$this->db->lastInsertId();
            foreach ($mo['employees'] as $e) {
                $et = $e['totals'];
                $this->db->prepare("INSERT INTO payroll_run_employees (run_id, employee_name, first_name, last_name, user_id, gross_wages,
                                        ee_fed, ee_prov, ee_ei, ee_cpp, ee_cpp2, er_ei, er_cpp, er_cpp2, net_pay, hours)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$runId, $e['name'], $e['first'], $e['last'], $this->userIdFor($e['first'], $e['last']), $et['wages'],
                               $et['fed'], $et['prov'], $et['ei'], $et['cpp'], $et['cpp2'], $et['er_ei'], $et['er_cpp'], $et['er_cpp2'], $et['net'], $et['hours']]);
                $empId = (int)$this->db->lastInsertId();
                foreach ($e['paydays'] as $p) {
                    $this->db->prepare("INSERT INTO payroll_run_paydays (run_employee_id, payday, period_start, period_end, regular_hours,
                                            overtime_hours, double_time_hours, vacation_hours, sick_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$empId, $p['payday'], $p['period_start'], $p['period_end'], $p['regular'], $p['overtime'], $p['double_time'], $p['vacation'], $p['sick']]);
                }
            }
            $ids[] = $runId;
        }
        return ['ok' => true, 'message' => count($ids) . ' month' . (count($ids) === 1 ? '' : 's') . ' read from the report.', 'run_ids' => $ids, 'warnings' => $parsed['warnings']];
    }

    /** Drop a preview (never a booked run). */
    public function discard(int $runId): array
    {
        $s = $this->db->prepare("SELECT status FROM payroll_runs WHERE id = ?");
        $s->execute([$runId]);
        $st = $s->fetchColumn();
        if ($st === false) return ['ok' => false, 'message' => 'Not found'];
        if ($st === 'booked') return ['ok' => false, 'message' => 'That month is booked — undo it first.'];
        $this->deleteRun($runId);
        return ['ok' => true, 'message' => 'Removed.'];
    }

    private function deleteRun(int $runId): void
    {
        $this->db->prepare("DELETE FROM payroll_run_paydays WHERE run_employee_id IN (SELECT id FROM payroll_run_employees WHERE run_id = ?)")->execute([$runId]);
        $this->db->prepare("DELETE FROM payroll_run_employees WHERE run_id = ?")->execute([$runId]);
        $this->db->prepare("DELETE FROM payroll_runs WHERE id = ? AND status <> 'booked'")->execute([$runId]);
    }

    /** users.id for an employee by full name (case-insensitive), or null. */
    private function userIdFor(string $first, string $last): ?int
    {
        try {
            $s = $this->db->prepare("SELECT id FROM users WHERE LOWER(TRIM(full_name)) = ? ORDER BY id LIMIT 1");
            $s->execute([strtolower(trim($first . ' ' . $last))]);
            $id = $s->fetchColumn();
            return $id === false ? null : (int)$id;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Report (DB)
    // ═════════════════════════════════════════════════════════════════════════

    /** A run as the matcher and the page see it (month shape of months()). */
    public function loadRun(int $runId): ?array
    {
        $s = $this->db->prepare("SELECT * FROM payroll_runs WHERE id = ?");
        $s->execute([$runId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $e = $this->db->prepare("SELECT * FROM payroll_run_employees WHERE run_id = ? ORDER BY last_name, first_name");
        $e->execute([$runId]);
        $emps = [];
        foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $p = $this->db->prepare("SELECT payday, period_start, period_end, regular_hours AS regular, overtime_hours AS overtime,
                                            double_time_hours AS double_time, vacation_hours AS vacation, sick_hours AS sick
                                     FROM payroll_run_paydays WHERE run_employee_id = ? ORDER BY payday, period_start");
            $p->execute([(int)$row['id']]);
            $t = ['wages' => (float)$row['gross_wages'], 'fed' => (float)$row['ee_fed'], 'prov' => (float)$row['ee_prov'], 'ei' => (float)$row['ee_ei'],
                  'cpp' => (float)$row['ee_cpp'], 'cpp2' => (float)$row['ee_cpp2'], 'er_fed' => 0.0, 'er_prov' => 0.0, 'er_ei' => (float)$row['er_ei'],
                  'er_cpp' => (float)$row['er_cpp'], 'er_cpp2' => (float)$row['er_cpp2']];
            $t = self::withNet($t);
            $t['hours'] = (float)$row['hours'];
            $emps[] = ['id' => (int)$row['id'], 'name' => $row['employee_name'], 'first' => $row['first_name'], 'last' => $row['last_name'],
                       'user_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null, 'totals' => $t,
                       'paydays' => array_map(function ($x) {
                           foreach (['regular', 'overtime', 'double_time', 'vacation', 'sick'] as $k) $x[$k] = (float)$x[$k];
                           return $x;
                       }, $p->fetchAll(PDO::FETCH_ASSOC))];
        }
        $tot = ['wages' => (float)$r['gross_wages'], 'fed' => (float)$r['ee_fed'], 'prov' => (float)$r['ee_prov'], 'ei' => (float)$r['ee_ei'],
                'cpp' => (float)$r['ee_cpp'], 'cpp2' => (float)$r['ee_cpp2'], 'er_fed' => 0.0, 'er_prov' => 0.0, 'er_ei' => (float)$r['er_ei'],
                'er_cpp' => (float)$r['er_cpp'], 'er_cpp2' => (float)$r['er_cpp2']];
        return ['id' => (int)$r['id'], 'month' => $r['period_month'], 'estimate' => (bool)$r['is_estimate'], 'last_payday' => $r['last_payday'],
                'status' => $r['status'], 'journal_entry_id' => $r['journal_entry_id'] !== null ? (int)$r['journal_entry_id'] : null,
                'source_name' => $r['source_name'], 'report_from' => $r['report_from'], 'report_to' => $r['report_to'],
                'warnings' => $r['warnings'] ? explode("\n", (string)$r['warnings']) : [], 'booked_at' => $r['booked_at'] ?? null,
                'sweep' => round((float)($r['sweep_amount'] ?? 0), 2),
                'totals' => self::withNet($tot), 'employees' => $emps];
    }

    /** Runs to show: the live (preview or booked) run of each month, oldest first. */
    public function runIds(): array
    {
        return array_map('intval', $this->db->query("SELECT id FROM payroll_runs WHERE status IN ('preview','booked') ORDER BY period_month, id")
                                           ->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The shareholder employee (ops_settings 'payroll_shareholder_name'), or null. */
    public function shareholderName(): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'payroll_shareholder_name'");
            $s->execute();
            $v = trim((string)$s->fetchColumn());
            return $v === '' ? null : $v;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Bank lines for matching between two dates: money out and in, not tied to a receipt or
     * invoice, not void, and not already moved by a live payroll / shareholder move.
     */
    public function bankLines(string $from, string $to): array
    {
        $inv = $this->hasColumn('accounting_transactions', 'matched_invoice_id') ? 'AND t.matched_invoice_id IS NULL' : '';
        $s = $this->db->prepare("
            SELECT t.id, t.transaction_date, t.type, t.amount, t.description, t.account_id, c.code AS account_code
            FROM accounting_transactions t
            LEFT JOIN chart_of_accounts c ON c.id = t.account_id
            WHERE t.reference_type = 'bank_import' AND t.transaction_date BETWEEN ? AND ?
              AND t.matched_expense_id IS NULL {$inv}
              AND (t.status IS NULL OR t.status NOT IN ('void','deleted'))
              AND NOT EXISTS (SELECT 1 FROM payroll_bank_moves m WHERE m.transaction_id = t.id AND m.undone_at IS NULL)
            ORDER BY t.transaction_date, t.id");
        $s->execute([$from, $to]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $facts = $rows ? (new LedgerSyncService($this->db, $this->ledger))->directionFacts() : [];
        $out = [];
        foreach ($rows as $r) {
            $type = (string)$r['type'];
            $dir = $type === 'income' ? 'in' : ($type === 'transfer' ? LedgerSyncService::transferDirection($facts[(int)$r['id']] ?? []) : 'out');
            $out[] = ['id' => (int)$r['id'], 'date' => substr((string)$r['transaction_date'], 0, 10), 'amount' => round((float)$r['amount'], 2),
                      'description' => (string)$r['description'], 'direction' => $dir, 'account_id' => $r['account_id'] !== null ? (int)$r['account_id'] : null,
                      'account_code' => $r['account_code']];
        }
        return $out;
    }

    /**
     * Proposals for every preview run, oldest month first (a line goes to the first month
     * that can use it). Booked runs report their stored moves. runId => proposal.
     */
    public function proposals(array $runs): array
    {
        $preview = array_values(array_filter($runs, fn($r) => $r['status'] === 'preview'));
        if (!$preview) return [];
        $from = self::addDays($preview[0]['month'] . '-01', -15);
        $to = self::addDays(date('Y-m-t', strtotime(end($preview)['month'] . '-01')), 10);
        $locked = $this->lockedMonths();
        $lines = array_values(array_filter($this->bankLines($from, $to), fn($l) => !in_array(substr($l['date'], 0, 7), $locked, true)));
        $sh = $this->shareholderName();
        $claimed = [];
        $out = [];
        foreach ($preview as $r) $out[$r['id']] = self::matchMonth($r, $lines, $sh, $claimed);
        return $out;
    }

    /** Everything the page shows. */
    public function report(): array
    {
        $runs = array_values(array_filter(array_map([$this, 'loadRun'], $this->runIds())));
        $props = $this->proposals($runs);
        $names = $this->accountNames();
        foreach ($runs as &$r) {
            $r['locked'] = $this->ledger->isLocked($r['last_payday']);
            $r['bookable'] = self::bookableReason($r, $r['locked']);
            if ($r['status'] === 'preview') {
                $r['proposal'] = $props[$r['id']] ?? null;
            } else {
                $r['booked_moves'] = $this->movesFor($r['id']);
                $r['clearing_ledger'] = ['source_deductions' => $this->monthMovement(self::ACC_SOURCE_DEDUCTIONS, $r['month']),
                                         'net_pay' => $this->monthMovement(self::ACC_NET_PAY, $r['month'])];
            }
            $sweep = $r['status'] === 'preview' ? (float)($r['proposal']['clearing']['sweep'] ?? 0) : $r['sweep'];
            $r['entry'] = self::buildEntry($r['totals'], $sweep, $r['last_payday'], self::memo($r));
            $r['hours'] = $this->hoursCheck($r['employees']);
        }
        unset($r);
        $booked = array_values(array_map(fn($r) => $r['month'], array_filter($runs, fn($r) => $r['status'] === 'booked')));
        return ['runs' => $runs, 'accounts' => $names, 'account_problems' => self::accountProblems($names),
                'shareholder' => $this->shareholderName(), 'booked_months' => $booked,
                'employees' => array_values(array_unique(array_merge(...array_map(fn($r) => array_column($r['employees'], 'name'), $runs ?: [['employees' => []]]))))];
    }

    public static function memo(array $run): string
    {
        return 'Wave payroll — ' . date('F Y', strtotime($run['month'] . '-01')) . ' (' . count($run['employees']) . ' employee'
            . (count($run['employees']) === 1 ? '' : 's') . ($run['estimate'] ? ', split by hours — estimate' : '') . ')';
    }

    /** Why a run can't be booked, or null. */
    public static function bookableReason(array $run, bool $locked): ?string
    {
        if ((int)substr($run['month'], 0, 4) !== self::BOOK_YEAR) return 'Only ' . self::BOOK_YEAR . ' is booked here — ' . substr($run['month'], 0, 4) . ' is filed.';
        if ($locked) return date('F Y', strtotime($run['month'] . '-01')) . ' is locked.';
        return null;
    }

    /** code => name of the payroll accounts (null when missing). */
    public function accountNames(): array
    {
        $out = [];
        foreach (array_merge(array_keys(self::ACCOUNT_NAME_RE), [self::ACC_FEES]) as $code) {
            $s = $this->db->prepare("SELECT name FROM chart_of_accounts WHERE code = ? LIMIT 1");
            $s->execute([(string)$code]);
            $n = $s->fetchColumn();
            $out[(string)$code] = $n === false ? null : (string)$n;
        }
        return $out;
    }

    /** Accounts missing or named for something else (then nothing is booked). */
    public static function accountProblems(array $names): array
    {
        $p = [];
        foreach (self::ACCOUNT_NAME_RE as $code => $re) {
            $n = $names[(string)$code] ?? null;
            if ($n === null) $p[] = "Account {$code} doesn't exist yet — run migration 1255.";
            elseif (!preg_match($re, $n)) $p[] = "Account {$code} is \"{$n}\" — not the payroll account this expects. Nothing is booked until it's sorted.";
        }
        if (($names[self::ACC_FEES] ?? null) === null) $p[] = 'Account ' . self::ACC_FEES . ' (bank charges) is missing.';
        return $p;
    }

    /** Credit balance movement of an account inside a month (posted entries). */
    private function monthMovement(string $code, string $ym): float
    {
        $s = $this->db->prepare("SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM journal_lines l
                                 JOIN journal_entries e ON e.id = l.entry_id JOIN chart_of_accounts c ON c.id = l.account_id
                                 WHERE c.code = ? AND e.entry_date BETWEEN ? AND ?");
        $s->execute([$code, $ym . '-01', date('Y-m-t', strtotime($ym . '-01'))]);
        return round((float)$s->fetchColumn(), 2);
    }

    private function movesFor(int $runId): array
    {
        $s = $this->db->prepare("SELECT m.transaction_id AS id, m.role, m.employee_name AS employee, m.amount, t.transaction_date AS date, t.description,
                                        m.undone_at FROM payroll_bank_moves m LEFT JOIN accounting_transactions t ON t.id = m.transaction_id
                                 WHERE m.run_id = ? AND m.undone_at IS NULL ORDER BY t.transaction_date, m.id");
        $s->execute([$runId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Hours vs the CRM time clock (read only)
    // ═════════════════════════════════════════════════════════════════════════

    /** Wave's paid hours per pay period against time_clock_entries for the matched user. */
    public function hoursCheck(array $employees): array
    {
        $out = [];
        foreach ($employees as $e) {
            $uid = $e['user_id'] ?? null;
            $rows = [];
            $entries = $uid ? $this->clockEntries($uid, min(array_column($e['paydays'], 'period_start') ?: ['9999']),
                                                   max(array_column($e['paydays'], 'period_end') ?: ['0000'])) : [];
            foreach ($e['paydays'] as $p) {
                $wave = round($p['regular'] + $p['overtime'] + $p['double_time'], 2);
                $crm = $uid ? self::clockHours($entries, $p['period_start'], $p['period_end']) : null;
                $rows[] = ['payday' => $p['payday'], 'period' => $p['period_start'] . ' – ' . $p['period_end'], 'wave' => $wave, 'crm' => $crm,
                           'diff' => $crm === null ? null : round($wave - $crm, 2)];
            }
            $out[] = ['name' => $e['name'], 'user_id' => $uid, 'rows' => $rows];
        }
        return $out;
    }

    private function clockEntries(int $userId, string $from, string $to): array
    {
        try {
            $s = $this->db->prepare("SELECT clock_in, clock_out, total_minutes FROM time_clock_entries
                                     WHERE user_id = ? AND status IN ('completed','edited') AND clock_in >= ? AND clock_in < ?");
            $s->execute([$userId, $from . ' 00:00:00', self::addDays($to, 1) . ' 00:00:00']);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Pure: clocked hours with clock-in inside [from, to]. */
    public static function clockHours(array $entries, string $from, string $to): float
    {
        $min = 0;
        foreach ($entries as $c) {
            $d = substr((string)$c['clock_in'], 0, 10);
            if ($d < $from || $d > $to) continue;
            $m = $c['total_minutes'] !== null && $c['total_minutes'] !== ''
                ? (int)$c['total_minutes']
                : (!empty($c['clock_out']) ? (int)round((strtotime((string)$c['clock_out']) - strtotime((string)$c['clock_in'])) / 60) : 0);
            $min += max(0, $m);
        }
        return round($min / 60, 2);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Approve / undo (DB)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Book one month: the journal entry, then the ticked bank lines moved (each its own
     * reversal + repost). The proposal is rebuilt here; only ids it contains are moved.
     * @param int[] $tickIds bank lines Tim ticked
     */
    public function approve(int $runId, array $tickIds, int $userId, bool $bookEstimate = false): array
    {
        $run = $this->loadRun($runId);
        if (!$run) return ['ok' => false, 'message' => 'Not found'];
        if ($run['status'] !== 'preview') return ['ok' => false, 'message' => 'That month is already booked.'];
        $why = self::bookableReason($run, $this->ledger->isLocked($run['last_payday']));
        if ($why) return ['ok' => false, 'message' => $why];
        if ($run['estimate'] && !$bookEstimate) {
            return ['ok' => false, 'message' => 'This month is split out of a multi-month report by hours — an estimate. Download the month on its own, or tick "book the estimate".'];
        }
        $problems = self::accountProblems($this->accountNames());
        if ($problems) return ['ok' => false, 'message' => implode(' ', $problems)];
        $s = $this->db->prepare("SELECT COUNT(*) FROM payroll_runs WHERE period_month = ? AND status = 'booked'");
        $s->execute([$run['month']]);
        if ((int)$s->fetchColumn() > 0) return ['ok' => false, 'message' => date('F Y', strtotime($run['month'] . '-01')) . ' is already booked from another report — undo that first.'];

        $runs = array_values(array_filter(array_map([$this, 'loadRun'], $this->runIds())));
        $prop = $this->proposals($runs)[$runId] ?? null;
        if (!$prop) return ['ok' => false, 'message' => 'Could not rebuild the bank matches — reload the page.'];
        $tick = array_map('intval', $tickIds);
        $moves = array_values(array_filter($prop['moves'], fn($m) => in_array($m['id'], $tick, true)));
        $clear = self::clearing($run['totals'], $prop['moves'], $prop['shareholder'], array_column($moves, 'id'));

        $entry = self::buildEntry($run['totals'], $clear['sweep'], $run['last_payday'], self::memo($run));
        $entry += ['source_type' => 'payroll_run', 'source_id' => $runId, 'created_by' => $userId, 'proposed_by' => 'penny'];
        $entryId = $this->ledger->postManual($entry);

        $codes = [];
        $moved = 0; $failed = [];
        $mover = new BankLineMoveService($this->db, $this->ledger);
        foreach ($moves as $m) {
            $acctId = $codes[$m['to']] ??= $this->ledger->accountId($m['to']);
            $cur = $this->db->prepare("SELECT account_id, type FROM accounting_transactions WHERE id = ?");
            $cur->execute([$m['id']]);
            $was = $cur->fetch(PDO::FETCH_ASSOC) ?: ['account_id' => null, 'type' => null];
            if ((int)$was['account_id'] !== $acctId) {
                $res = $mover->move($m['id'], $acctId, $userId, 'penny');
                if (empty($res['ok'])) { $failed[] = $m['id'] . ': ' . $res['message']; continue; }
            }
            $this->logMove($runId, null, $m, $was, $acctId, $userId);
            $moved++;
        }
        $this->db->prepare("UPDATE payroll_runs SET status = 'booked', journal_entry_id = ?, sweep_amount = ?, booked_by = ?, booked_at = NOW() WHERE id = ?")
           ->execute([$entryId, $clear['sweep'], $userId ?: null, $runId]);
        $msg = date('F Y', strtotime($run['month'] . '-01')) . ' booked (entry #' . $entryId . '), ' . $moved . ' bank line' . ($moved === 1 ? '' : 's') . ' moved.';
        if ($failed) $msg .= ' Not moved: ' . implode('; ', $failed);
        return ['ok' => true, 'message' => $msg, 'entry_id' => $entryId, 'moved' => $moved, 'failed' => $failed, 'clearing' => $clear];
    }

    /** Undo a booked month: reverse its entry, move every bank line back. */
    public function undo(int $runId, int $userId): array
    {
        $run = $this->loadRun($runId);
        if (!$run || $run['status'] !== 'booked') return ['ok' => false, 'message' => 'That month isn\'t booked.'];
        if ($this->ledger->isLocked($run['last_payday'])) return ['ok' => false, 'message' => date('F Y', strtotime($run['month'] . '-01')) . ' is locked.'];
        $rev = $run['journal_entry_id'] ? $this->ledger->reverseEntry($run['journal_entry_id'], $userId, 'payroll month undone', 'owner') : null;
        $back = $this->undoMoves('run_id = ?', [$runId], $userId);
        $this->db->prepare("UPDATE payroll_runs SET status = 'preview', journal_entry_id = NULL, sweep_amount = NULL, last_reversal_entry_id = ?,
                                   undone_by = ?, undone_at = NOW() WHERE id = ?")->execute([$rev, $userId ?: null, $runId]);
        return ['ok' => true, 'message' => 'Undone: entry reversed' . ($rev ? ' (#' . $rev . ')' : '') . ', ' . $back['moved'] . ' bank line'
                . ($back['moved'] === 1 ? '' : 's') . ' moved back.' . ($back['failed'] ? ' Not moved back: ' . implode('; ', $back['failed']) : '')];
    }

    /** Log one move (payroll run or shareholder batch). */
    public function logMove(?int $runId, ?string $batch, array $m, array $was, int $toAccountId, int $userId): void
    {
        $this->db->prepare("INSERT INTO payroll_bank_moves (run_id, batch_id, transaction_id, role, employee_name, amount, old_account_id, old_type,
                                new_account_id, moved_by, moved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$runId, $batch, $m['id'], $m['role'], $m['employee'] ?? null, $m['amount'],
                       $was['account_id'] !== null ? (int)$was['account_id'] : null, $was['type'], $toAccountId, $userId ?: null]);
    }

    /** Move logged lines back to where they were. */
    public function undoMoves(string $where, array $args, int $userId): array
    {
        $s = $this->db->prepare("SELECT * FROM payroll_bank_moves WHERE {$where} AND undone_at IS NULL ORDER BY id DESC");
        $s->execute($args);
        $mover = new BankLineMoveService($this->db, $this->ledger);
        $moved = 0; $failed = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if ($m['old_account_id'] !== null && (int)$m['old_account_id'] !== (int)$m['new_account_id']) {
                $res = $mover->move((int)$m['transaction_id'], (int)$m['old_account_id'], $userId, 'owner');
                if (empty($res['ok'])) { $failed[] = $m['transaction_id'] . ': ' . $res['message']; continue; }
            }
            $this->db->prepare("UPDATE payroll_bank_moves SET undone_at = NOW(), undone_by = ? WHERE id = ?")->execute([$userId ?: null, (int)$m['id']]);
            $moved++;
        }
        return ['moved' => $moved, 'failed' => $failed];
    }

    /** Brief items for Penny (read only). */
    public function brief(string $today): array
    {
        if (!$this->ready()) return [];
        $booked = array_map('strval', $this->db->query("SELECT DISTINCT period_month FROM payroll_runs WHERE status = 'booked'")->fetchAll(PDO::FETCH_COLUMN));
        $inUse = (int)$this->db->query("SELECT COUNT(*) FROM payroll_runs")->fetchColumn() > 0;
        if (!$inUse) {
            $ym = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -1 month'));
            $s = $this->db->prepare("SELECT COUNT(*) FROM accounting_transactions WHERE reference_type = 'bank_import' AND description LIKE '%PYRL%' AND transaction_date BETWEEN ? AND ?");
            $s->execute([$ym . '-01', date('Y-m-t', strtotime($ym . '-01'))]);
            $inUse = (int)$s->fetchColumn() > 0;
        }
        return self::briefItems($booked, $inUse, $today);
    }

    /** 'YYYY-MM' of every locked month. */
    public function lockedMonths(): array
    {
        try {
            return array_map(fn($p) => sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']),
                             $this->db->query("SELECT year, month FROM accounting_periods WHERE status = 'locked'")->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function hasTable(string $t): bool
    {
        try { $this->db->query("SELECT 1 FROM {$t} LIMIT 0"); return true; } catch (Throwable $e) { return false; }
    }

    private function hasColumn(string $t, string $c): bool
    {
        try { $this->db->query("SELECT {$c} FROM {$t} LIMIT 0"); return true; } catch (Throwable $e) { return false; }
    }
}
