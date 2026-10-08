<?php
/**
 * BankBalanceCheckService — do the books' bank balances match the bank statements? (2026-10-07)
 *
 * The trial balance showed 1010 Chequing ~$580K "overdrawn" (a credit balance) while the real
 * Vancity chequing ••6801 closed Sept 2026 at $13,425.61. This service measures that gap and
 * explains it, month by month, for every bank account (1010, 1020, 1025) and the credit card
 * (2400) when its statement lines carry balances:
 *
 *   statement  the statement's closing balance at each month end — the last running balance of
 *              the month in that account's statement chain (bank_import_rows.raw_row → raw_line,
 *              read and ordered exactly as StatementCoverageService does)
 *   books      the journal balance of the account at that month end (posted entries)
 *   drift      books − statement, and its change month over month
 *
 * Attribution (each cause = what the books would change by if it were fixed; "explains" in the
 * page is the opposite sign, i.e. its share of the drift):
 *   part_payments        invoice payments missing from the journal (InvoicePaymentPlanner)   FIX
 *   jobber_deposits      deposits before 2026-04-01 (Jobber billed through March 2026) on a
 *                        revenue account, never in the journal: bankRowToEntryArgs skips
 *                        revenue deposits because revenue is recognised on invoices — but a
 *                        Jobber-era deposit has no CRM invoice                                FIX
 *   opening              statement opening balance at the first statement vs the journal
 *                        balance the day before it                                           FIX
 *   savings_one_sided    a savings statement line whose money never reached the journal on
 *                        either side (no entry of its own, no chequing twin)                  review
 *   unposted_bank_lines  bank lines that should post but have no entry (sync not run / failed) review
 *   orphan_bank_entries  live bank entries whose bank line was deleted / rolled back           review
 *   statement_gaps       breaks in the running balance — lines not imported                  review
 *   remainder            what none of the above explains
 *
 * Fixes are proposals: Tim approves a group (the server rebuilds it and refuses it if it
 * changed since he looked — signature), months that are locked are skipped, every posting /
 * reversal is logged in bank_balance_fix_log (migration 1236) and a batch can be undone
 * (append-only: reversal entries, never deletes).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';
require_once __DIR__ . '/InvoicePaymentPlanner.php';
require_once __DIR__ . '/StatementCoverageService.php';

class BankBalanceCheckService
{
    /** Jobber billed through March 2026 (Tim, 2026-10-07): deposits before this date may be Jobber's. */
    public const JOBBER_UNTIL = '2026-04-01';
    /** First CRM invoice paid (IncomeCleanupService::CUTOVER). From here a deposit may be a CRM invoice's. */
    public const CUTOVER = '2026-02-25';
    public const CODES = ['1010', '1020', '1025', '2400'];
    public const BANK_CODES = ['1010', '1020', '1025'];
    public const EQUITY = '3900';
    public const CENT = 0.01;
    /** A CRM invoice issued up to this many days before a deposit may be what it paid. */
    public const CRM_WINDOW_DAYS = 150;
    /** A chequing twin of a savings line: same amount within this many days. */
    public const TWIN_DAYS = 5;

    public const GROUPS = ['part_payments', 'jobber_deposits', 'opening'];
    public const CAUSES = [
        'part_payments'       => ['label' => 'Invoice payments missing from the journal', 'fix' => true],
        'jobber_deposits'     => ['label' => 'Jobber-era deposits never in the journal', 'fix' => true],
        'opening'             => ['label' => 'Opening balance at the first statement', 'fix' => true],
        'savings_one_sided'   => ['label' => 'Savings transfers on one side only', 'fix' => false],
        'unposted_bank_lines' => ['label' => 'Bank lines the nightly sync hasn\'t posted', 'fix' => false],
        'orphan_bank_entries' => ['label' => 'Entries for bank lines that no longer exist', 'fix' => false],
        'statement_gaps'      => ['label' => 'Statement lines not imported (balance gaps)', 'fix' => false],
    ];

    private PDO $db;
    private LedgerService $ledger;
    private ?string $today;
    private array $lockCache = [];
    private ?array $chart = null;
    private ?array $plans = null;
    private ?array $jobber = null;

    public function __construct(PDO $db, ?LedgerService $ledger = null, ?string $today = null)
    {
        $this->db = $db;
        $this->ledger = $ledger ?? new LedgerService($db);
        $this->today = $today;
    }

    /** Migration 1236 has run (fix log + per-payment sources). */
    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM bank_balance_fix_log LIMIT 0");
            return (new InvoicePaymentPlanner($this->db))->goForwardFrom() !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REPORT (read only)
    // ══════════════════════════════════════════════════════════════════════════

    public function report(): array
    {
        $accounts = $this->trackedAccounts();
        $statements = $this->statements($accounts);
        $groups = $this->fixGroups($accounts, $statements);
        $review = $this->reviewCauses($accounts, $statements);

        $effects = [];   // cause => list of [account_id, date, effect]
        foreach ($groups as $g => $grp) $effects[$g] = $grp['effects'];
        foreach ($review as $c => $rv) $effects[$c] = $rv['effects'];

        $out = [];
        foreach ($accounts as $acct) {
            $aid = (int)$acct['id'];
            $st = $statements[$aid] ?? null;
            if (!$st || !$st['closing']) continue;
            $books = $this->bookBalances($aid, $acct['kind']);
            $byCause = [];
            foreach ($effects as $c => $list) {
                $mine = array_values(array_filter($list, fn($e) => (int)$e[0] === $aid));
                if ($mine) $byCause[$c] = array_map(fn($e) => [$e[1], $e[2]], $mine);
            }
            $months = self::driftTable($st['closing'], $books, $byCause, self::GROUPS);
            $out[] = [
                'account_id' => $aid, 'code' => $acct['code'], 'name' => $acct['name'], 'kind' => $acct['kind'],
                'first_date' => $st['first_date'], 'opening' => $st['opening'],
                'months' => $months, 'latest' => $months ? $months[count($months) - 1] : null,
            ];
        }

        $groupsOut = [];
        foreach ($groups as $g => $grp) {
            $preview = [];
            foreach ($out as $a) {
                if (!$a['latest'] || $a['latest']['drift'] === null) continue;
                $delta = self::cumulative(array_map(fn($e) => [$e[1], $e[2]], array_filter($grp['effects'], fn($e) => (int)$e[0] === $a['account_id'])),
                                          self::monthEnd($a['latest']['ym']));
                if (abs($delta) < self::CENT) continue;
                $preview[] = ['code' => $a['code'], 'before' => $a['latest']['drift'], 'after' => round($a['latest']['drift'] + $delta, 2)];
            }
            unset($grp['effects']);
            $grp['preview'] = $preview;
            $grp['items'] = array_map([self::class, 'forPage'], array_slice($grp['items'], 0, 300));
            $groupsOut[$g] = $grp;
        }
        foreach ($review as $c => &$rv) { unset($rv['effects']); $rv['items'] = array_slice($rv['items'], 0, 200); }
        unset($rv);

        return [
            'ready' => $this->ready(),
            'generated_at' => date('Y-m-d H:i:s'),
            'jobber_until' => self::JOBBER_UNTIL,
            'cutover' => self::CUTOVER,
            'causes' => self::CAUSES,
            'accounts' => $out,
            'groups' => $groupsOut,
            'review' => $review,
            'log' => $this->batches(),
        ];
    }

    /**
     * Month rows for one account. Pure.
     * @param array $closing  ym => statement closing balance
     * @param array $books    ym => journal movement in that month (book sign)
     * @param array $byCause  cause => list of [date, effect]
     * @param array $fixCauses causes counted in 'after' (the approvable groups)
     * @return list<array{ym:string, statement:?float, books:float, drift:?float, delta:?float, causes:array, remainder:?float, after:?float}>
     */
    public static function driftTable(array $closing, array $books, array $byCause, array $fixCauses = []): array
    {
        if (!$closing) return [];
        ksort($closing);
        $yms = array_keys($closing);
        $first = $yms[0];
        $last = $yms[count($yms) - 1];
        ksort($books);
        $running = 0.0;
        foreach ($books as $ym => $mv) if ($ym < $first) $running += (float)$mv;

        $rows = [];
        $prevDrift = null;
        for ($ym = $first; $ym <= $last; $ym = self::nextMonth($ym)) {
            $running += (float)($books[$ym] ?? 0);
            $stmt = array_key_exists($ym, $closing) ? round((float)$closing[$ym], 2) : null;
            $drift = $stmt === null ? null : round($running - $stmt, 2);
            $end = self::monthEnd($ym);
            $causes = [];
            $all = 0.0;
            $fix = 0.0;
            foreach ($byCause as $c => $list) {
                $cum = self::cumulative($list, $end);
                if (abs($cum) < 0.005) continue;
                $causes[$c] = round(-$cum, 2);          // its share of the drift
                $all += $cum;
                if (in_array($c, $fixCauses, true)) $fix += $cum;
            }
            $rows[] = [
                'ym' => $ym, 'statement' => $stmt, 'books' => round($running, 2), 'drift' => $drift,
                'delta' => ($drift !== null && $prevDrift !== null) ? round($drift - $prevDrift, 2) : null,
                'causes' => $causes,
                'remainder' => $drift === null ? null : round($drift + $all, 2),
                'after' => $drift === null ? null : round($drift + $fix, 2),
            ];
            if ($drift !== null) $prevDrift = $drift;
        }
        return $rows;
    }

    /** An item as the page shows it: no journal lines, no plan internals. Pure. */
    public static function forPage(array $it): array
    {
        unset($it['plan']);
        $it['actions'] = array_map(function ($a) {
            unset($a['entry']);
            return $a;
        }, $it['actions'] ?? []);
        return $it;
    }

    /** Sum of [date, amount] pairs dated on/before $date. Pure. */
    public static function cumulative(array $list, string $date): float
    {
        $s = 0.0;
        foreach ($list as $e) if ((string)$e[0] <= $date) $s += (float)$e[1];
        return round($s, 2);
    }

    /**
     * Closing balance per month and the opening balance, from an account's ordered statement
     * lines (StatementCoverageService::orderLines). Pure.
     * @return array{closing: array<string,float>, opening: ?float, first_date: ?string}
     */
    public static function statementBalances(array $ordered): array
    {
        $closing = [];
        $opening = null;
        $first = null;
        $pending = 0.0;
        $pendingUnknown = false;
        foreach ($ordered as $l) {
            if ($first === null && (string)$l['date'] !== '') $first = substr((string)$l['date'], 0, 10);
            if ($l['balance'] === null) {
                if ($opening === null) {
                    if ($l['signed'] === null) $pendingUnknown = true; else $pending += (float)$l['signed'];
                }
                continue;
            }
            if ($opening === null && !$pendingUnknown && $l['signed'] !== null) {
                $opening = round((float)$l['balance'] - (float)$l['signed'] - $pending, 2);
            }
            if ($opening === null) $pendingUnknown = true;   // the first balance can't be unwound — no opening
            $closing[substr((string)$l['date'], 0, 7)] = round((float)$l['balance'], 2);
        }
        return ['closing' => $closing, 'opening' => $opening, 'first_date' => $first];
    }

    public static function monthEnd(string $ym): string
    {
        return date('Y-m-t', strtotime($ym . '-01'));
    }

    private static function nextMonth(string $ym): string
    {
        return date('Y-m', strtotime($ym . '-01 +1 month'));
    }

    private static function dayBefore(string $date): string
    {
        return date('Y-m-d', strtotime($date . ' -1 day'));
    }

    // ── statements ───────────────────────────────────────────────────────────

    /** @return array<int, array{id:int, code:string, name:string, kind:string}> chart id => account */
    public function trackedAccounts(): array
    {
        $in = implode(',', array_fill(0, count(self::CODES), '?'));
        $s = $this->db->prepare("SELECT id, code, name, type FROM chart_of_accounts WHERE code IN ($in) ORDER BY code, id");
        $s->execute(self::CODES);
        $out = [];
        $seen = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($seen[$r['code']])) continue;
            $seen[$r['code']] = true;
            $out[(int)$r['id']] = ['id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'],
                                   'kind' => ($r['code'] === '2400' || $r['type'] === 'liability') ? 'card' : 'bank'];
        }
        return $out;
    }

    /**
     * Each tracked account's statement lines, ordered, with its month-end closings and opening.
     * A line belongs to the account its CRM transaction is on (a savings line printed on the
     * chequing statement and moved by BankAccountSplitService), else its session's account,
     * else the expected account whose bank name the session carries.
     * @return array<int, array{closing:array, opening:?float, first_date:?string, ordered:array}>
     */
    public function statements(array $accounts): array
    {
        $byName = [];
        try {
            foreach ($this->db->query("SELECT account_id, bank_name_match FROM bank_statement_accounts")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $k = strtolower(trim((string)$a['bank_name_match']));
                if ($k !== '') $byName[$k][] = (int)$a['account_id'];
            }
        } catch (Throwable $e) { /* before migration 1231 */ }

        $lines = [];
        try {
            $rows = $this->db->query("
                SELECT r.id, r.session_id, r.transaction_date, r.type, r.amount, r.raw_amount, r.is_duplicate, r.raw_row,
                       s.bank_account_id AS s_bank, s.bank_name, t.bank_account_id AS tx_bank
                FROM bank_import_rows r
                JOIN bank_import_sessions s ON s.id = r.session_id AND s.status = 'imported'
                LEFT JOIN accounting_transactions t ON t.id = r.transaction_id AND t.reference_type = 'bank_import'
                ORDER BY r.session_id, r.id
            ");
        } catch (Throwable $e) {
            return [];
        }
        while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
            $aid = !empty($r['tx_bank']) ? (int)$r['tx_bank'] : (!empty($r['s_bank']) ? (int)$r['s_bank'] : null);
            if ($aid === null) {
                $nm = strtolower(trim((string)$r['bank_name']));
                if (isset($byName[$nm]) && count($byName[$nm]) === 1) $aid = $byName[$nm][0];
            }
            if ($aid === null || !isset($accounts[$aid])) continue;
            $raw = json_decode((string)$r['raw_row'], true) ?: [];
            $lines[$aid][] = StatementCoverageService::lineFromRow($r, $raw, $accounts[$aid]['kind']);
        }
        $out = [];
        foreach ($lines as $aid => $ls) {
            $ordered = StatementCoverageService::orderLines($ls);
            $out[$aid] = self::statementBalances($ordered) + ['ordered' => $ordered];
        }
        return $out;
    }

    /** ym => movement of the account in that month, in book sign (bank: Dr − Cr; card: Cr − Dr). */
    public function bookBalances(int $accountId, string $kind): array
    {
        $s = $this->db->prepare("
            SELECT SUBSTR(je.entry_date, 1, 7) AS ym, SUM(jl.debit) AS d, SUM(jl.credit) AS c
            FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
            WHERE jl.account_id = ? AND je.status = 'posted'
            GROUP BY SUBSTR(je.entry_date, 1, 7)
        ");
        $s->execute([$accountId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mv = (float)$r['d'] - (float)$r['c'];
            $out[(string)$r['ym']] = round($kind === 'card' ? -$mv : $mv, 2);
        }
        return $out;
    }

    /** Journal balance of an account (book sign) for entries dated before $date. */
    public function bookBalanceBefore(int $accountId, string $kind, string $date): float
    {
        $s = $this->db->prepare("
            SELECT COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0)
            FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
            WHERE jl.account_id = ? AND je.status = 'posted' AND je.entry_date < ?
        ");
        $s->execute([$accountId, $date]);
        $v = round((float)$s->fetchColumn(), 2);
        return $kind === 'card' ? -$v : $v;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FIX GROUPS — items, effects, signature
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * @return array<string, array{count:int, locked:int, total:float, signature:string, items:array, effects:array}>
     */
    public function fixGroups(?array $accounts = null, ?array $statements = null): array
    {
        $accounts = $accounts ?? $this->trackedAccounts();
        $statements = $statements ?? $this->statements($accounts);
        return [
            'part_payments'   => $this->summarise($this->partPaymentItems()),
            'jobber_deposits' => $this->summarise($this->jobberItems()['post']),
            'opening'         => $this->summarise($this->openingItems($accounts, $statements)),
        ];
    }

    private function summarise(array $items): array
    {
        $effects = [];
        $total = 0.0;
        $locked = 0;
        $sig = [];
        foreach ($items as $it) {
            if (!empty($it['locked'])) { $locked++; continue; }
            $total += (float)$it['effect'];
            foreach ($it['effects'] as $e) $effects[] = $e;
            $sig[] = [$it['key'], round((float)$it['effect'], 2), array_map(fn($a) => [$a['op'], $a['date'], round((float)$a['amount'], 2)], $it['actions'])];
        }
        foreach ($items as &$it) unset($it['effects']);
        unset($it);
        return ['count' => count($items) - $locked, 'locked' => $locked, 'total' => round($total, 2),
                'signature' => sha1(json_encode($sig)), 'items' => $items, 'effects' => $effects];
    }

    /** Invoices whose payments are missing from the journal (or posted wrong), plus orphaned payment entries. */
    public function partPaymentItems(): array
    {
        $bank = $this->chartId(LedgerService::ACC_BANK);
        $planner = new InvoicePaymentPlanner($this->db);
        $items = [];
        foreach ($this->plans() as $plan) {
            if (!in_array($plan['state'], ['missing', 'redo'], true)) continue;
            $effects = array_map(fn($a) => [$bank, $a['date'], (float)$a['amount']], $plan['actions']);
            $items[] = [
                'key' => 'inv:' . $plan['invoice_id'], 'kind' => $plan['state'],
                'label' => 'Invoice ' . ($plan['invoice_number'] !== '' ? $plan['invoice_number'] : '#' . $plan['invoice_id']),
                'reason' => $plan['reason'] ?: (count($plan['actions']) . ' payment(s) recorded after the first sync never reached the journal'),
                'effect' => $plan['effect'], 'actions' => $plan['actions'], 'plan' => $plan,
                'locked' => $this->anyLocked($plan['actions']), 'effects' => $effects,
            ];
        }
        foreach ($planner->orphanEntries() as $o) {
            $a = ['op' => 'reverse', 'entry_id' => $o['entry_id'], 'date' => $o['date'], 'amount' => -$o['amount'], 'auto' => false];
            $items[] = [
                'key' => 'orphan:' . $o['entry_id'], 'kind' => 'orphan',
                'label' => ucfirst(str_replace('_', ' ', $o['source_type'])) . ' #' . $o['source_id'],
                'reason' => 'The payment record behind this entry is gone (detached / not succeeded) — reverse it',
                'effect' => -$o['amount'], 'actions' => [$a], 'plan' => null,
                'locked' => $this->anyLocked([$a]), 'effects' => [[$bank, $o['date'], -$o['amount']]],
            ];
        }
        return $items;
    }

    /** Invoices whose payments don't add up — listed, never booked. */
    public function paymentReviewItems(): array
    {
        $out = [];
        foreach ($this->plans() as $plan) {
            if ($plan['state'] === 'review') {
                $out[] = ['invoice_id' => $plan['invoice_id'], 'invoice_number' => $plan['invoice_number'], 'reason' => $plan['reason']];
            }
        }
        return $out;
    }

    /**
     * Deposits before JOBBER_UNTIL on a revenue account with no journal cash.
     *   post — no CRM invoice could be what it paid: DR bank / CR the deposit's revenue account
     *          (+ CR GST collected for the row's GST)
     *   crm  — on/after the CRM cutover and a CRM invoice of that amount exists: its cash may
     *          already be in the journal through the invoice's payment → listed, never posted
     *          (link it on the income clean-up page)
     * Never double counts: a deposit tied to invoices (allocations, matched_invoice_id, 'transfer'
     * after reconciliation), booked by the income clean-up, or with any live entry is excluded.
     * @return array{post: array, crm: array}
     */
    public function jobberItems(): array
    {
        if ($this->jobber === null) $this->jobber = $this->loadJobberItems();
        return $this->jobber;
    }

    private function plans(): array
    {
        if ($this->plans === null) $this->plans = (new InvoicePaymentPlanner($this->db))->planAll();
        return $this->plans;
    }

    /** Forget what was read (after booking). */
    public function forget(): void
    {
        $this->plans = null;
        $this->jobber = null;
        $this->lockCache = [];
    }

    private function loadJobberItems(): array
    {
        $s = $this->db->prepare("
            SELECT t.id, t.transaction_date, t.amount, t.gst_amount, t.description, t.account_id, t.bank_account_id,
                   c.code AS account_code, c.name AS account_name
            FROM accounting_transactions t
            JOIN chart_of_accounts c ON c.id = t.account_id
            WHERE t.reference_type = 'bank_import' AND t.type = 'income' AND c.type = 'revenue'
              AND t.amount > 0.005 AND t.transaction_date < ?
              AND (t.status IS NULL OR t.status <> 'void')
              AND t.matched_invoice_id IS NULL AND t.matched_expense_id IS NULL
            ORDER BY t.transaction_date, t.id
        ");
        $s->execute([self::JOBBER_UNTIL]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['post' => [], 'crm' => []];

        $allocated = $this->idSet("SELECT DISTINCT transaction_id FROM invoice_payment_allocations WHERE transaction_id IS NOT NULL");
        $journaled = $this->idSet("SELECT DISTINCT source_id FROM journal_entries WHERE source_type IN ('bank_import', 'bank_deposit')
                                   AND reversed_by_entry_id IS NULL AND source_id IS NOT NULL");
        $cleaned = $this->idSet("SELECT DISTINCT transaction_id FROM income_cleanup_log WHERE status = 'booked'");
        $invoices = [];
        try {
            $q = $this->db->prepare("SELECT id, invoice_number, total, issue_date FROM invoices WHERE COALESCE(total, 0) > 0 AND issue_date >= ?");
            $q->execute([date('Y-m-d', strtotime(self::CUTOVER . ' -' . self::CRM_WINDOW_DAYS . ' days'))]);
            $invoices = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* no issue_date */ }

        $bank1010 = $this->chartId(LedgerService::ACC_BANK);
        $gstId = $this->chartIdOrNull(LedgerService::ACC_GST_COLLECTED);
        $post = []; $crm = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (isset($allocated[$id]) || isset($journaled[$id]) || isset($cleaned[$id])) continue;
            $date = substr((string)$r['transaction_date'], 0, 10);
            $amount = round((float)$r['amount'], 2);
            $bank = !empty($r['bank_account_id']) ? (int)$r['bank_account_id'] : $bank1010;
            if ($date >= self::CUTOVER) {
                $match = self::crmInvoiceFor($amount, $date, $invoices);
                if ($match) {
                    $crm[] = ['id' => $id, 'date' => $date, 'amount' => $amount, 'description' => (string)$r['description'],
                              'invoice_number' => (string)$match['invoice_number']];
                    continue;
                }
            }
            $gst = round(min(max(0.0, (float)($r['gst_amount'] ?? 0)), $amount), 2);
            if ($gst > 0 && $gstId === null) $gst = 0.0;
            $lines = [['account_id' => $bank, 'debit' => $amount, 'credit' => 0]];
            if ($amount - $gst > 0.005) {
                $lines[] = ['account_id' => (int)$r['account_id'], 'debit' => 0, 'credit' => round($amount - $gst, 2),
                            'description' => mb_substr((string)$r['description'], 0, 255)];
            }
            if ($gst > 0) $lines[] = ['account_id' => $gstId, 'debit' => 0, 'credit' => $gst, 'gst_amount' => $gst];
            $action = ['op' => 'post', 'source_type' => 'bank_deposit', 'source_id' => $id, 'date' => $date, 'amount' => $amount,
                       'entry' => ['entry_date' => $date, 'memo' => 'Jobber-era deposit (no CRM invoice) — ' . mb_substr((string)$r['description'], 0, 180),
                                   'source_type' => 'bank_deposit', 'source_id' => $id, 'lines' => $lines]];
            $post[] = [
                'key' => 'tx:' . $id, 'kind' => 'deposit', 'label' => $date . ' ' . mb_substr((string)$r['description'], 0, 60),
                'reason' => 'Deposit to ' . $r['account_code'] . ' ' . $r['account_name'] . ' — no CRM invoice, never in the journal',
                'effect' => $amount, 'actions' => [$action], 'locked' => $this->isLocked($date),
                'effects' => [[$bank, $date, $amount]],
            ];
        }
        return ['post' => $post, 'crm' => $crm];
    }

    /** A CRM invoice this deposit could have paid: total equal to the cent, issued within the window before it. Pure. */
    public static function crmInvoiceFor(float $amount, string $date, array $invoices): ?array
    {
        $from = date('Y-m-d', strtotime($date . ' -' . self::CRM_WINDOW_DAYS . ' days'));
        $to = date('Y-m-d', strtotime($date . ' +5 days'));
        foreach ($invoices as $i) {
            $d = substr((string)($i['issue_date'] ?? ''), 0, 10);
            if ($d === '' || $d < $from || $d > $to) continue;
            if (abs((float)$i['total'] - $amount) < self::CENT) return $i;
        }
        return null;
    }

    /**
     * One opening entry per bank account so the books start where its first statement starts:
     * statement opening − journal balance the day before (FY2024 opening 1067 included), against
     * 3900 Opening Balance Equity, dated the day before the first statement line.
     */
    public function openingItems(array $accounts, array $statements): array
    {
        $equity = $this->chartIdOrNull(self::EQUITY);
        $items = [];
        foreach ($accounts as $aid => $acct) {
            if (!in_array($acct['code'], self::BANK_CODES, true)) continue;
            $st = $statements[$aid] ?? null;
            if (!$st || $st['opening'] === null || !$st['first_date']) continue;
            $date = self::dayBefore($st['first_date']);
            $existing = $this->liveEntry('bank_opening', $aid);
            $before = $this->bookBalanceBefore($aid, $acct['kind'], $st['first_date']);
            $existingNet = $existing ? $existing['net'] : 0.0;
            $amount = round($st['opening'] - ($before - $existingNet), 2);
            if (abs($amount - $existingNet) < self::CENT) continue;
            $actions = [];
            if ($existing) {
                $actions[] = ['op' => 'reverse', 'entry_id' => $existing['entry_id'], 'date' => $existing['entry_date'], 'amount' => -$existingNet];
            }
            if (abs($amount) >= self::CENT) {
                if ($equity === null) continue;   // no 3900 — run migration 1236
                $lines = $amount > 0
                    ? [['account_id' => $aid, 'debit' => $amount, 'credit' => 0], ['account_id' => $equity, 'debit' => 0, 'credit' => $amount]]
                    : [['account_id' => $equity, 'debit' => -$amount, 'credit' => 0], ['account_id' => $aid, 'debit' => 0, 'credit' => -$amount]];
                $actions[] = ['op' => 'post', 'source_type' => 'bank_opening', 'source_id' => $aid, 'date' => $date, 'amount' => $amount,
                              'entry' => ['entry_date' => $date, 'memo' => 'Opening balance per first statement — ' . $acct['code'] . ' ' . $acct['name'],
                                          'source_type' => 'bank_opening', 'source_id' => $aid, 'is_adjusting' => 1, 'lines' => $lines]];
            }
            $effect = round($amount - $existingNet, 2);
            $items[] = [
                'key' => 'acct:' . $aid, 'kind' => 'opening', 'label' => $acct['code'] . ' ' . $acct['name'],
                'reason' => sprintf('First statement %s opens at $%s; the journal had $%s the day before', $st['first_date'],
                                    number_format($st['opening'], 2), number_format($before, 2)),
                'effect' => $effect, 'actions' => $actions, 'locked' => $this->anyLocked($actions),
                'effects' => array_map(fn($a) => [$aid, $a['date'], (float)$a['amount']], $actions),
            ];
        }
        return $items;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REVIEW CAUSES — listed, never booked here
    // ══════════════════════════════════════════════════════════════════════════

    public function reviewCauses(array $accounts, array $statements): array
    {
        $jobber = $this->jobberItems();
        return [
            'savings_one_sided'   => $this->savingsOneSided($accounts, $statements),
            'unposted_bank_lines' => $this->unpostedBankLines($accounts),
            'orphan_bank_entries' => $this->orphanBankEntries($accounts),
            'statement_gaps'      => $this->statementGaps($accounts, $statements),
            'crm_deposits'        => ['items' => $jobber['crm'], 'effects' => [], 'count' => count($jobber['crm']),
                                      'total' => round(array_sum(array_column($jobber['crm'], 'amount')), 2)],
            'payment_review'      => ['items' => $this->paymentReviewItems(), 'effects' => [], 'count' => 0, 'total' => 0.0],
        ];
    }

    /**
     * Savings statement lines whose money reached the journal on neither side: no live entry of
     * their own touching the savings account, and no chequing line filed to that savings account
     * (same amount, within TWIN_DAYS) with a live entry.
     */
    public function savingsOneSided(array $accounts, array $statements): array
    {
        $items = []; $effects = [];
        foreach ($accounts as $aid => $acct) {
            if (!in_array($acct['code'], ['1020', '1025'], true)) continue;
            $s = $this->db->prepare("
                SELECT t.id, t.transaction_date, t.amount, t.description, r.type AS staged_type
                FROM accounting_transactions t
                LEFT JOIN bank_import_rows r ON r.transaction_id = t.id
                WHERE t.reference_type = 'bank_import' AND t.bank_account_id = ?
                  AND (t.status IS NULL OR t.status <> 'void')
                ORDER BY t.transaction_date, t.id
            ");
            $s->execute([$aid]);
            $own = $this->entriesTouching($aid, 'bank_import');
            $t2 = $this->db->prepare("
                SELECT t.id, t.transaction_date, t.amount FROM accounting_transactions t
                WHERE t.reference_type = 'bank_import' AND t.account_id = ?
                  AND (t.bank_account_id IS NULL OR t.bank_account_id <> ?)
                  AND (t.status IS NULL OR t.status <> 'void')
            ");
            $t2->execute([$aid, $aid]);
            $twins = array_values(array_filter($t2->fetchAll(PDO::FETCH_ASSOC), fn($t) => isset($own[(int)$t['id']])));
            $used = [];
            $seen = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int)$r['id'];
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                if (isset($own[$id])) continue;
                $amount = round((float)$r['amount'], 2);
                $date = substr((string)$r['transaction_date'], 0, 10);
                $twin = null;
                foreach ($twins as $k => $t) {
                    if (isset($used[$k]) || abs((float)$t['amount'] - $amount) >= self::CENT) continue;
                    if (abs(strtotime(substr((string)$t['transaction_date'], 0, 10)) - strtotime($date)) > self::TWIN_DAYS * 86400) continue;
                    $twin = $k;
                    break;
                }
                if ($twin !== null) { $used[$twin] = true; continue; }
                $signed = ($r['staged_type'] ?? '') === 'income' ? $amount : -$amount;
                $items[] = ['id' => $id, 'account' => $acct['code'], 'date' => $date, 'amount' => $signed, 'description' => (string)$r['description']];
                $effects[] = [$aid, $date, $signed];
            }
        }
        return ['items' => $items, 'effects' => $effects, 'count' => count($items), 'total' => round(array_sum(array_column($items, 'amount')), 2)];
    }

    /** Bank lines the sync would post but that have no live entry. */
    public function unpostedBankLines(array $accounts): array
    {
        $sync = new LedgerSyncService($this->db, $this->ledger);
        $rows = $this->db->query("
            SELECT at.id, at.transaction_date, at.type, at.account_id,
                   coa.type AS account_type, coa.code AS account_code,
                   at.bank_account_id, at.amount, at.gst_amount, at.pst_amount,
                   at.description, at.job_id, at.contact_id, at.vendor_id
            FROM accounting_transactions at
            JOIN chart_of_accounts coa ON coa.id = at.account_id
            WHERE at.reference_type = 'bank_import'
              AND at.matched_invoice_id IS NULL AND at.matched_expense_id IS NULL
        ")->fetchAll(PDO::FETCH_ASSOC);
        $live = $this->idSet("SELECT DISTINCT source_id FROM journal_entries WHERE source_type IN ('bank_import', 'bank_deposit')
                              AND reversed_by_entry_id IS NULL AND source_id IS NOT NULL");
        $facts = $sync->directionFacts();
        $itc = $this->chartIdOrNull(LedgerService::ACC_GST_ITC) ?? 0;
        $gst = $this->chartIdOrNull(LedgerService::ACC_GST_COLLECTED) ?? 0;
        $bank = $this->chartId(LedgerService::ACC_BANK);
        $items = []; $effects = [];
        foreach ($rows as $row) {
            if (isset($live[(int)$row['id']])) continue;
            $args = $sync->bankRowToEntryArgs(LedgerSyncService::withDirection($row, $facts), $itc, $gst, $bank, []);
            if ($args === null) continue;
            foreach ($args['lines'] as $l) {
                $aid = (int)$l['account_id'];
                if (!isset($accounts[$aid])) continue;
                $net = (float)$l['debit'] - (float)$l['credit'];
                if ($accounts[$aid]['kind'] === 'card') $net = -$net;
                $effects[] = [$aid, $args['entry_date'], round($net, 2)];
                $items[] = ['id' => (int)$row['id'], 'account' => $accounts[$aid]['code'], 'date' => $args['entry_date'],
                            'amount' => round($net, 2), 'description' => (string)$row['description']];
            }
        }
        return ['items' => $items, 'effects' => $effects, 'count' => count($items), 'total' => round(array_sum(array_column($items, 'amount')), 2)];
    }

    /** Live bank entries whose bank line no longer exists (BankImportService::rollback() leaves them). */
    public function orphanBankEntries(array $accounts): array
    {
        $rows = $this->db->query("
            SELECT je.id, je.source_id, je.entry_date, je.memo, jl.account_id, SUM(jl.debit) AS d, SUM(jl.credit) AS c
            FROM journal_entries je
            JOIN journal_lines jl ON jl.entry_id = je.id
            LEFT JOIN accounting_transactions t ON t.id = je.source_id
            WHERE je.source_type = 'bank_import' AND je.reversed_by_entry_id IS NULL AND je.status = 'posted' AND t.id IS NULL
            GROUP BY je.id, je.source_id, je.entry_date, je.memo, jl.account_id
        ")->fetchAll(PDO::FETCH_ASSOC);
        $items = []; $effects = [];
        foreach ($rows as $r) {
            $aid = (int)$r['account_id'];
            if (!isset($accounts[$aid])) continue;
            $net = (float)$r['d'] - (float)$r['c'];
            if ($accounts[$aid]['kind'] === 'card') $net = -$net;
            $date = substr((string)$r['entry_date'], 0, 10);
            $effects[] = [$aid, $date, round(-$net, 2)];
            $items[] = ['entry_id' => (int)$r['id'], 'account' => $accounts[$aid]['code'], 'date' => $date,
                        'amount' => round(-$net, 2), 'description' => (string)$r['memo']];
        }
        return ['items' => $items, 'effects' => $effects, 'count' => count($items), 'total' => round(array_sum(array_column($items, 'amount')), 2)];
    }

    /** Breaks in each account's running balance: lines that were never imported. */
    public function statementGaps(array $accounts, array $statements): array
    {
        $items = []; $effects = [];
        foreach ($statements as $aid => $st) {
            if (!isset($accounts[$aid]) || empty($st['ordered'])) continue;
            $walk = StatementCoverageService::walkChain($st['ordered']);
            foreach ($walk['breaks'] ?? [] as $b) {
                $items[] = ['account' => $accounts[$aid]['code'], 'from' => $b['from'], 'date' => $b['to'], 'amount' => (float)$b['missing']];
                $effects[] = [$aid, substr((string)$b['to'], 0, 10), (float)$b['missing']];
            }
        }
        return ['items' => $items, 'effects' => $effects, 'count' => count($items), 'total' => round(array_sum(array_column($items, 'amount')), 2)];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // APPROVE / UNDO
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Book one group. The group is rebuilt here; refused when its signature differs from what
     * the page showed. Locked months are skipped (they're not in the signature).
     * @return array{ok:bool, message:string, batch_id?:string, booked?:int, failed?:array}
     */
    public function approve(string $group, string $signature, int $userId): array
    {
        if (!in_array($group, self::GROUPS, true)) return ['ok' => false, 'message' => 'Unknown fix group.'];
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1236 first — every fix is logged so it can be undone.'];
        $this->forget();
        $accounts = $this->trackedAccounts();
        $items = [
            'part_payments'   => fn() => $this->partPaymentItems(),
            'jobber_deposits' => fn() => $this->jobberItems()['post'],
            'opening'         => fn() => $this->openingItems($accounts, $this->statements($accounts)),
        ][$group]();
        $grp = $this->summarise($items);
        if (!hash_equals($grp['signature'], $signature)) {
            return ['ok' => false, 'message' => 'This group changed since you looked — reload the page and check it again.'];
        }
        $batch = 'bbc-' . date('YmdHis') . '-' . substr(sha1(uniqid('', true)), 0, 8);
        $booked = 0; $failed = [];
        foreach ($grp['items'] as $it) {
            if (!empty($it['locked'])) continue;
            try {
                $this->bookItem($group, $batch, $it, $userId);
                $booked++;
            } catch (Throwable $e) {
                $failed[] = ['key' => $it['key'], 'message' => $e->getMessage()];
            }
        }
        $this->forget();
        $msg = $booked . ' booked' . ($grp['locked'] ? ', ' . $grp['locked'] . ' in locked months skipped' : '') . ($failed ? ', ' . count($failed) . ' failed' : '') . '.';
        return ['ok' => $booked > 0 || !$failed, 'message' => $msg, 'batch_id' => $batch, 'booked' => $booked, 'failed' => $failed];
    }

    /** Reversals first (the old entry out), then the new postings. Every step logged as it happens. */
    private function bookItem(string $group, string $batch, array $it, int $userId): void
    {
        $why = 'bank balance check: ' . $it['label'];
        foreach ($it['actions'] as $a) {
            if ($a['op'] !== 'reverse') continue;
            $rev = $this->ledger->reverseEntry((int)$a['entry_id'], $userId, $why, 'penny');
            $this->log($batch, $group, $it['key'], 'reverse', $rev, (int)$a['entry_id'], null, null, (float)$a['amount'], $a['date'], $userId);
        }
        foreach ($it['actions'] as $a) {
            if ($a['op'] !== 'post') continue;
            $entry = $a['entry'] ?? InvoicePaymentPlanner::entryFor($a, $it['plan'], $this->ledger);
            $entry['created_by'] = $userId;
            $entry['proposed_by'] = 'penny';
            foreach ($entry['lines'] as &$l) {
                if (!isset($l['account_id']) && isset($l['account'])) $l['account_id'] = $this->chartId((string)$l['account']);
            }
            unset($l);
            $id = $this->ledger->postManual($entry);
            $this->log($batch, $group, $it['key'], 'post', $id, null, $a['source_type'], (int)$a['source_id'], (float)$a['amount'], $a['date'], $userId);
        }
    }

    /** Undo one approval: its postings reversed, the entries it reversed posted again. Locked months skipped. */
    public function undo(string $batchId, int $userId): array
    {
        $s = $this->db->prepare("SELECT * FROM bank_balance_fix_log WHERE batch_id = ? AND undone_at IS NULL ORDER BY id");
        $s->execute([$batchId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['ok' => false, 'message' => 'Nothing to undo in that batch.'];
        $this->forget();
        $done = 0; $skipped = 0; $failed = [];
        $mark = $this->db->prepare("UPDATE bank_balance_fix_log SET undone_at = ?, undone_by = ?, undo_entry_id = ? WHERE id = ?");
        usort($rows, fn($a, $b) => [$a['op'] === 'post' ? 0 : 1, (int)$a['id']] <=> [$b['op'] === 'post' ? 0 : 1, (int)$b['id']]);
        foreach ($rows as $r) {
            if ($this->isLocked((string)$r['entry_date'])) { $skipped++; continue; }
            try {
                if ($r['op'] === 'post') {
                    $undo = $this->ledger->reverseEntry((int)$r['entry_id'], $userId, 'bank balance check undone (' . $batchId . ')', 'owner');
                } else {
                    $undo = $this->repostCopy((int)$r['target_entry_id'], $userId, $batchId);
                }
                $mark->execute([date('Y-m-d H:i:s'), $userId, $undo, (int)$r['id']]);
                $done++;
            } catch (Throwable $e) {
                $failed[] = ['id' => (int)$r['id'], 'message' => $e->getMessage()];
            }
        }
        $this->forget();
        return ['ok' => !$failed, 'message' => $done . ' undone' . ($skipped ? ', ' . $skipped . ' in locked months left' : '') . ($failed ? ', ' . count($failed) . ' failed' : '') . '.',
                'undone' => $done, 'failed' => $failed];
    }

    /** Post a reversed entry again, as it was (same source, date, lines). */
    private function repostCopy(int $entryId, int $userId, string $batchId): int
    {
        $h = $this->db->prepare("SELECT entry_date, memo, source_type, source_id, is_adjusting FROM journal_entries WHERE id = ?");
        $h->execute([$entryId]);
        $hdr = $h->fetch(PDO::FETCH_ASSOC);
        if (!$hdr) throw new RuntimeException("Journal entry $entryId not found");
        $l = $this->db->prepare("SELECT account_id, debit, credit, gst_amount, pst_amount, description, job_id, contact_id, vendor_id,
                                        crew_user_id, cost_type_id, service_type FROM journal_lines WHERE entry_id = ?");
        $l->execute([$entryId]);
        $lines = [];
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $ln) {
            $lines[] = ['account_id' => (int)$ln['account_id'], 'debit' => (float)$ln['debit'], 'credit' => (float)$ln['credit'],
                        'gst_amount' => (float)$ln['gst_amount'], 'pst_amount' => (float)$ln['pst_amount'], 'description' => $ln['description'],
                        'job_id' => $ln['job_id'], 'contact_id' => $ln['contact_id'], 'vendor_id' => $ln['vendor_id'],
                        'crew_user_id' => $ln['crew_user_id'], 'cost_type_id' => $ln['cost_type_id'], 'service_type' => $ln['service_type']];
        }
        return $this->ledger->postManual([
            'entry_date' => substr((string)$hdr['entry_date'], 0, 10),
            'memo' => mb_substr('Re-posted (bank check ' . $batchId . ' undone) — ' . (string)$hdr['memo'], 0, 255),
            'source_type' => (string)$hdr['source_type'], 'source_id' => $hdr['source_id'] !== null ? (int)$hdr['source_id'] : null,
            'is_adjusting' => (int)$hdr['is_adjusting'], 'created_by' => $userId, 'proposed_by' => 'owner', 'lines' => $lines,
        ]);
    }

    /** Approvals, newest first. */
    public function batches(int $limit = 30): array
    {
        try {
            $s = $this->db->prepare("
                SELECT batch_id, fix_group, COUNT(*) AS steps, SUM(amount) AS amount, MIN(created_at) AS created_at,
                       SUM(CASE WHEN undone_at IS NULL THEN 0 ELSE 1 END) AS undone
                FROM bank_balance_fix_log GROUP BY batch_id, fix_group ORDER BY MIN(id) DESC LIMIT " . max(1, min(200, $limit)));
            $s->execute();
            return array_map(fn($r) => ['batch_id' => $r['batch_id'], 'group' => $r['fix_group'], 'steps' => (int)$r['steps'],
                                        'amount' => round((float)$r['amount'], 2), 'created_at' => $r['created_at'],
                                        'undone' => (int)$r['undone'] >= (int)$r['steps']], $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function log(string $batch, string $group, string $key, string $op, int $entryId, ?int $target, ?string $srcType, ?int $srcId,
                         float $amount, string $date, int $userId): void
    {
        $this->db->prepare("INSERT INTO bank_balance_fix_log (batch_id, fix_group, item_key, op, entry_id, target_entry_id, source_type, source_id,
                                                              amount, entry_date, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$batch, $group, $key, $op, $entryId, $target, $srcType, $srcId, round($amount, 2), $date, $userId, date('Y-m-d H:i:s')]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    public function isLocked(string $date): bool
    {
        $ym = substr($date, 0, 7);
        if ($ym === '' || strlen($ym) < 7) return false;
        if (!array_key_exists($ym, $this->lockCache)) {
            try { $this->lockCache[$ym] = $this->ledger->isLocked($ym . '-01'); }
            catch (Throwable $e) { $this->lockCache[$ym] = false; }
        }
        return $this->lockCache[$ym];
    }

    private function anyLocked(array $actions): bool
    {
        foreach ($actions as $a) if ($this->isLocked((string)$a['date'])) return true;
        return false;
    }

    private function chartMap(): array
    {
        if ($this->chart === null) {
            $this->chart = [];
            foreach ($this->db->query("SELECT id, code FROM chart_of_accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!isset($this->chart[(string)$r['code']])) $this->chart[(string)$r['code']] = (int)$r['id'];
            }
        }
        return $this->chart;
    }

    private function chartIdOrNull(string $code): ?int
    {
        return $this->chartMap()[$code] ?? null;
    }

    private function chartId(string $code): int
    {
        $id = $this->chartIdOrNull($code);
        if ($id === null) throw new RuntimeException("Chart of accounts code not found: $code");
        return $id;
    }

    /** @return array<int, true> */
    private function idSet(string $sql): array
    {
        try {
            return array_fill_keys(array_map('intval', $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** source ids of live entries of $sourceType with a line on $accountId. */
    private function entriesTouching(int $accountId, string $sourceType): array
    {
        $s = $this->db->prepare("SELECT DISTINCT je.source_id FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id
                                 WHERE je.source_type = ? AND je.reversed_by_entry_id IS NULL AND jl.account_id = ? AND je.source_id IS NOT NULL");
        $s->execute([$sourceType, $accountId]);
        return array_fill_keys(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** The live entry for a source with its net on the source's account (bank_opening: source_id = account). */
    private function liveEntry(string $sourceType, int $accountId): ?array
    {
        $s = $this->db->prepare("
            SELECT je.id, je.entry_date, COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS net
            FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id AND jl.account_id = ?
            WHERE je.source_type = ? AND je.source_id = ? AND je.reversed_by_entry_id IS NULL
            GROUP BY je.id, je.entry_date
        ");
        $s->execute([$accountId, $sourceType, $accountId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? ['entry_id' => (int)$r['id'], 'entry_date' => substr((string)$r['entry_date'], 0, 10), 'net' => round((float)$r['net'], 2)] : null;
    }
}
