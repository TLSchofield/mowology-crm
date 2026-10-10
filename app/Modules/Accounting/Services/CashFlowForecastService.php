<?php
/**
 * CashFlowForecastService — Penny's 13-week cash-flow forecast (owner, 2026-10-10).
 *
 * Starts from the last bank statement balance and walks forward week by week:
 *   money in  — open invoices on the day each client really pays (their own lateness),
 *               scheduled visits not yet invoiced (visit + 30-day terms + lateness);
 *   money out — recurring monthly bills and biweekly payments seen in the bank,
 *               tax instalments (settings), and "everyday spending" (the last 8 weeks'
 *               average bank spending that isn't one of those bills or a transfer).
 * Snow & salt route stops are left out on purpose: a daily stop bills only when serviced.
 * Invoices more than 120 days past due are listed as doubtful, not counted.
 *
 * Every number on the page has a line that says where it came from — a forecast nobody can
 * check is worse than none. Settings (ops_settings): cashflow_floor, cashflow_terms_days,
 * cashflow_rc_instalment, cashflow_rc_day, cashflow_gst_instalment, cashflow_gst_day.
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class CashFlowForecastService
{
    public const WEEKS = 13;
    public const DOUBTFUL_DAYS = 120;
    public const DEFAULTS = [
        'cashflow_floor'          => '2000',
        'cashflow_terms_days'     => '30',
        'cashflow_rc_instalment'  => '875',
        'cashflow_rc_day'         => '15',
        'cashflow_gst_instalment' => '3500',
        'cashflow_gst_day'        => '15',
        'cashflow_everyday_weekly' => '',
    ];

    private PDO $db;
    private ?array $settings = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** Monday of the week a date falls in. */
    public static function weekStart(string $date): string
    {
        $t = strtotime($date);
        return date('Y-m-d', strtotime('-' . ((int)date('N', $t) - 1) . ' days', $t));
    }

    /**
     * The forecast. $events: [date, amount (+ in / − out), kind, label, ref?]. Events before
     * $today fall into the first week (money already due / overdue). Returns weekly buckets
     * with running balance, the low point and whether it dips under the floor.
     */
    public static function build(float $opening, string $today, array $events, int $weeks = self::WEEKS, float $floor = 0.0): array
    {
        $first = self::weekStart($today);
        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $s = date('Y-m-d', strtotime($first . " +{$i} weeks"));
            $buckets[$s] = ['start' => $s, 'end' => date('Y-m-d', strtotime($s . ' +6 days')), 'in' => 0.0, 'out' => 0.0, 'items' => []];
        }
        $last = date('Y-m-d', strtotime($first . ' +' . ($weeks * 7 - 1) . ' days'));
        foreach ($events as $e) {
            $d = (string)$e['date'];
            if ($d > $last) continue;
            $w = $d < $first ? $first : self::weekStart($d);
            if (!isset($buckets[$w])) continue;
            $amt = round((float)$e['amount'], 2);
            if ($amt >= 0) $buckets[$w]['in'] += $amt; else $buckets[$w]['out'] += -$amt;
            $buckets[$w]['items'][] = $e + ['amount' => $amt];
        }
        $bal = round($opening, 2);
        $low = null;
        $out = [];
        foreach ($buckets as $b) {
            $b['in'] = round($b['in'], 2);
            $b['out'] = round($b['out'], 2);
            $b['net'] = round($b['in'] - $b['out'], 2);
            $bal = round($bal + $b['net'], 2);
            $b['closing'] = $bal;
            usort($b['items'], fn($x, $y) => abs($y['amount']) <=> abs($x['amount']));
            if ($low === null || $bal < $low['closing']) $low = ['start' => $b['start'], 'closing' => $bal];
            $out[] = $b;
        }
        return ['opening' => round($opening, 2), 'weeks' => $out, 'low' => $low,
                'below_floor' => $low !== null && $low['closing'] < $floor, 'floor' => $floor];
    }

    /** Median of numbers (null when empty). */
    public static function median(array $v): ?float
    {
        $v = array_values(array_map('floatval', $v));
        if (!$v) return null;
        sort($v);
        $n = count($v);
        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    /** Days late a client usually pays (median of due → paid), clamped 0–90; null without history. */
    public static function lateness(array $daysLate): ?int
    {
        $m = self::median($daysLate);
        return $m === null ? null : (int)max(0, min(90, round($m)));
    }

    /** When an open invoice is expected: due date + the client's lateness, never before today. */
    public static function expectedPayDate(string $due, int $lateDays, string $today): string
    {
        $d = date('Y-m-d', strtotime($due . " +{$lateDays} days"));
        return $d < $today ? $today : $d;
    }

    /**
     * Biweekly payments in bank lines (the truck loan): a payee seen 4+ times, ~14 days apart,
     * steady amount. Returns [label, amount, last, next] — monthly bills come from
     * RecurringBillService. $lines: [transaction_date, amount, description].
     */
    public static function biweekly(array $lines, string $today): array
    {
        $by = [];
        foreach ($lines as $l) {
            if (!class_exists('RecurringBillService')) break;
            $k = RecurringBillService::payeeKey((string)$l['description']);
            if (strlen($k) < 4 || RecurringBillService::isTransfer((string)$l['description']) || (float)$l['amount'] < 1) continue;
            $by[$k][] = $l;
        }
        $out = [];
        foreach ($by as $ls) {
            usort($ls, fn($a, $b) => strcmp((string)$a['transaction_date'], (string)$b['transaction_date']));
            if (count($ls) < 4) continue;
            $gaps = [];
            for ($i = 1; $i < count($ls); $i++) $gaps[] = (strtotime($ls[$i]['transaction_date']) - strtotime($ls[$i - 1]['transaction_date'])) / 86400;
            $gap = self::median($gaps);
            if ($gap === null || $gap < 12 || $gap > 16) continue;
            $amounts = array_map(fn($l) => (float)$l['amount'], $ls);
            $usual = self::median($amounts);
            $steady = count(array_filter($amounts, fn($a) => abs($a - $usual) <= max(2, $usual * 0.1))) >= count($amounts) * 0.75;
            if (!$steady) continue;
            $lastL = end($ls);
            $next = date('Y-m-d', strtotime($lastL['transaction_date'] . ' +14 days'));
            while ($next < $today) $next = date('Y-m-d', strtotime($next . ' +14 days'));
            $out[] = ['label' => RecurringBillService::label((string)$lastL['description']), 'amount' => round($usual, 2),
                      'last' => $lastL['transaction_date'], 'next' => $next, 'key' => RecurringBillService::payeeKey((string)$lastL['description'])];
        }
        return $out;
    }

    /**
     * Everyday spending for a forecast week: the same month last year when the bank history has
     * it (winter is cheaper than August), else the recent 8-week average.
     * $monthly: ['YYYY-MM' => weekly average that month].
     */
    public static function everydayForWeek(string $weekStart, array $monthly, float $recent): array
    {
        $ly = date('Y-m', strtotime($weekStart . ' -1 year'));
        if (isset($monthly[$ly]) && $monthly[$ly] > 0) {
            return [round($monthly[$ly], 2), 'same month last year (' . date('M Y', strtotime($ly . '-01')) . ')'];
        }
        return [round($recent, 2), 'average of the last 8 weeks'];
    }

    /**
     * Visits a recurring plan will have after the last one on the calendar: the plan's own
     * spacing (median gap between its scheduled visits, else every 14 days), up to its end date.
     * @return string[] dates
     */
    public static function projectVisits(array $scheduledDates, ?string $planEnd, string $until): array
    {
        sort($scheduledDates);
        if (!$scheduledDates) return [];
        $gaps = [];
        for ($i = 1; $i < count($scheduledDates); $i++) $gaps[] = (strtotime($scheduledDates[$i]) - strtotime($scheduledDates[$i - 1])) / 86400;
        $gap = (int)round(self::median($gaps) ?? 14);
        if ($gap < 1) return [];
        $stop = $planEnd && $planEnd < $until ? $planEnd : $until;
        $out = [];
        $d = date('Y-m-d', strtotime(end($scheduledDates) . " +{$gap} days"));
        while ($d <= $stop && count($out) < 60) { $out[] = $d; $d = date('Y-m-d', strtotime($d . " +{$gap} days")); }
        return $out;
    }

    /** CRA payments are forecast from the instalment settings, so the bank's copies don't count twice. */
    public static function isTaxPayment(string $description): bool
    {
        return (bool)preg_match('/\b(CRA|RECEIVER ?GENERAL|CANADA REVENUE|REV(ENUE)? CANADA|GST ?(\/HST)? ?(PAYMENT|INSTAL|REMIT)|TAX ?(PAYMENT|INSTAL))/i', $description);
    }

    /** Repeat a dated amount every N days (or monthly) from $first while inside the window. */
    public static function repeat(string $first, string $until, string $every): array
    {
        $out = [];
        $d = $first;
        $guard = 0;
        while ($d <= $until && $guard++ < 60) {
            $out[] = $d;
            $d = $every === 'month' ? date('Y-m-d', strtotime($d . ' +1 month')) : date('Y-m-d', strtotime($d . ' +' . (int)$every . ' days'));
        }
        return $out;
    }

    /** The next date on a given day of the month, from today (day clamped to 28). */
    public static function nextMonthDay(string $today, int $day): string
    {
        $day = max(1, min(28, $day));
        $d = date('Y-m-', strtotime($today)) . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
        return $d < $today ? date('Y-m-d', strtotime($d . ' +1 month')) : $d;
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    public function setting(string $key): string
    {
        if ($this->settings === null) {
            $this->settings = self::DEFAULTS;
            try {
                $in = implode(',', array_fill(0, count(self::DEFAULTS), '?'));
                $st = $this->db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ($in)");
                $st->execute(array_keys(self::DEFAULTS));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ($r['setting_value'] !== null && $r['setting_value'] !== '') $this->settings[$r['setting_key']] = (string)$r['setting_value'];
                }
            } catch (Throwable $e) { /* defaults */ }
        }
        return $this->settings[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    /** The whole forecast with its sources. */
    public function forecast(?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $weeks = self::WEEKS;
        $until = date('Y-m-d', strtotime(self::weekStart($today) . ' +' . ($weeks * 7 - 1) . ' days'));
        $opening = $this->openingBalance();
        $events = [];
        $notes = [];
        $doubtful = [];

        // ── Money in: open invoices ─────────────────────────────────────────
        [$late, $globalLate] = $this->latenessByClient();
        $terms = (int)$this->setting('cashflow_terms_days');
        foreach ($this->openInvoices() as $inv) {
            $key = $this->clientKey($inv);
            $lateDays = $late[$key] ?? $globalLate;
            $due = (string)($inv['due_date'] ?: date('Y-m-d', strtotime(($inv['invoice_date'] ?: $today) . " +{$terms} days")));
            $pastDue = (int)floor((strtotime($today) - strtotime($due)) / 86400);
            $label = $inv['invoice_number'] . ' · ' . ($inv['who'] ?: 'client');
            if ($pastDue > self::DOUBTFUL_DAYS) {
                $doubtful[] = ['label' => $label, 'amount' => round((float)$inv['balance_due'], 2), 'days' => $pastDue, 'id' => (int)$inv['id']];
                continue;
            }
            $events[] = ['date' => self::expectedPayDate($due, $lateDays, $today), 'amount' => (float)$inv['balance_due'],
                         'kind' => 'invoice', 'label' => $label, 'id' => (int)$inv['id'],
                         'why' => 'due ' . $due . ($lateDays ? ', usually pays ' . $lateDays . ' days late' : '') . ($pastDue > 0 ? ' (' . $pastDue . ' days overdue)' : '')];
        }

        // ── Money in: visits scheduled but not invoiced ─────────────────────
        $visitTotal = 0.0;
        foreach ($this->upcomingVisits($today, $until) as $v) {
            $key = $this->clientKey($v);
            $lateDays = $late[$key] ?? $globalLate;
            $date = date('Y-m-d', strtotime($v['scheduled_date'] . ' +' . ($terms + $lateDays) . ' days'));
            if ($date > $until) continue;
            $visitTotal += (float)$v['price'];
            $events[] = ['date' => $date, 'amount' => (float)$v['price'], 'kind' => 'visit',
                         'label' => 'Visit ' . date('M j', strtotime($v['scheduled_date'])) . ' · ' . ($v['address'] ?: 'job'),
                         'why' => 'invoiced after the visit, paid ' . ($terms + $lateDays) . ' days later'];
        }
        foreach ($this->projectedVisits($until) as $v) {
            $key = $this->clientKey($v);
            $lateDays = $late[$key] ?? $globalLate;
            $date = date('Y-m-d', strtotime($v['date'] . ' +' . ($terms + $lateDays) . ' days'));
            if ($date > $until) continue;
            $events[] = ['date' => $date, 'amount' => (float)$v['price'], 'kind' => 'visit_projected',
                         'label' => 'Expected visit ' . date('M j', strtotime($v['date'])) . ' · ' . ($v['address'] ?: 'job'),
                         'why' => "projected from the plan's schedule (not on the calendar yet)"];
        }
        $notes[] = 'Salt & snow route stops are not counted: a daily stop is only billed when serviced. The "like last year" line covers winter income.';

        // ── Money out: bills ────────────────────────────────────────────────
        $billKeys = [];
        try {
            if (!class_exists('RecurringBillService')) require_once __DIR__ . '/RecurringBillService.php';
            foreach ((new RecurringBillService($this->db))->bills($today) as $b) {
                if (in_array('stopped', (array)$b['status'], true)) continue;
                $billKeys[$b['key']] = true;
                $first = (string)$b['next'];
                while ($first < $today) $first = date('Y-m-d', strtotime($first . ' +1 month'));
                foreach (self::repeat($first, $until, 'month') as $d) {
                    $events[] = ['date' => $d, 'amount' => -(float)$b['usual'], 'kind' => 'bill', 'label' => $b['payee'], 'why' => 'monthly, usually $' . number_format((float)$b['usual'], 2)];
                }
            }
            foreach (self::biweekly($this->bankSpending(180), $today) as $b) {
                if (isset($billKeys[$b['key']])) continue;
                $billKeys[$b['key']] = true;
                foreach (self::repeat($b['next'], $until, '14') as $d) {
                    $events[] = ['date' => $d, 'amount' => -$b['amount'], 'kind' => 'bill', 'label' => $b['label'], 'why' => 'every two weeks, $' . number_format($b['amount'], 2)];
                }
            }
        } catch (Throwable $e) {
            error_log('[cashflow] bills: ' . $e->getMessage());
            $notes[] = 'Recurring bills could not be read.';
        }

        // ── Money out: tax instalments (settings) ───────────────────────────
        foreach ([['cashflow_rc_instalment', 'cashflow_rc_day', 'CRA income tax instalment'], ['cashflow_gst_instalment', 'cashflow_gst_day', 'GST instalment']] as [$amtK, $dayK, $label]) {
            $amt = (float)$this->setting($amtK);
            if ($amt <= 0) continue;
            foreach (self::repeat(self::nextMonthDay($today, (int)$this->setting($dayK)), $until, 'month') as $d) {
                $events[] = ['date' => $d, 'amount' => -$amt, 'kind' => 'tax', 'label' => $label, 'why' => 'setting: $' . number_format($amt, 2) . ' a month'];
            }
        }

        // ── Money out: everyday spending ────────────────────────────────────
        $ev = $this->everyday($billKeys);
        $override = (float)$this->setting('cashflow_everyday_weekly');
        foreach (self::repeat(self::weekStart($today), $until, '7') as $d) {
            [$amt, $why] = $override > 0 ? [$override, 'your setting'] : self::everydayForWeek($d, $ev['monthly'], $ev['recent']);
            if ($amt <= 0) continue;
            $events[] = ['date' => $d, 'amount' => -$amt, 'kind' => 'everyday', 'label' => 'Everyday spending', 'why' => $why . ': fuel, materials, wages, other'];
        }
        $weekly = $ev['recent'];

        $floor = (float)$this->setting('cashflow_floor');
        $f = self::build((float)$opening['balance'], $today, $events, $weeks, $floor);
        // Second view: money in as it actually came in the same weeks last year (snow, new work and all).
        $ly = $this->incomeLastYear($today, $until);
        $outOnly = array_values(array_filter($events, fn($e) => $e['amount'] < 0));
        $lyEvents = $outOnly;
        foreach ($ly as $wk => $amt) $lyEvents[] = ['date' => $wk, 'amount' => $amt, 'kind' => 'last_year', 'label' => 'Deposits, same week last year', 'why' => 'bank deposits the same week last year'];
        $f['like_last_year'] = $ly ? array_map(fn($w) => ['start' => $w['start'], 'in' => $w['in'], 'closing' => $w['closing']], self::build((float)$opening['balance'], $today, $lyEvents, $weeks, $floor)['weeks']) : [];
        $f['opening_as_of'] = $opening['as_of'];
        $f['opening_accounts'] = $opening['accounts'];
        $f['doubtful'] = $doubtful;
        $f['everyday_weekly'] = round($weekly, 2);
        $f['everyday_top'] = $ev['top'];
        $f['notes'] = $notes;
        $f['chase'] = $this->chaseList($events, $f);
        return $f;
    }

    /** Penny's line for Charlie when the forecast dips under the floor. */
    public function briefItems(?string $today = null): array
    {
        try {
            $f = $this->forecast($today);
        } catch (Throwable $e) {
            error_log('[cashflow] brief: ' . $e->getMessage());
            return [];
        }
        if (!$f['below_floor'] || !$f['low']) return [];
        $chase = array_slice($f['chase'], 0, 3);
        $text = 'Cash dips to $' . number_format($f['low']['closing'], 0) . ' the week of ' . date('M j', strtotime($f['low']['start']))
              . ($chase ? '. Chase ' . implode(', ', array_map(fn($c) => $c['label'] . ' ($' . number_format($c['amount'], 0) . ')', $chase)) : '') . '.';
        return [['key' => 'penny:cashflow:' . $f['low']['start'], 'kind' => 'penny:cashflow', 'priority' => $f['low']['closing'] < 0 ? 1 : 2,
                 'text' => $text, 'url' => '/crm/accounting/cashflow.php']];
    }

    // ──────────────────────────────────────────────────────────────────────

    /** The biggest overdue / soonest invoices that would lift the low point. */
    private function chaseList(array $events, array $f): array
    {
        $low = $f['low']['start'] ?? null;
        $inv = array_values(array_filter($events, fn($e) => $e['kind'] === 'invoice' && (!$low || $e['date'] > $low || str_contains((string)$e['why'], 'overdue'))));
        usort($inv, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return array_map(fn($e) => ['label' => $e['label'], 'amount' => round($e['amount'], 2), 'id' => $e['id'] ?? null], array_slice($inv, 0, 5));
    }

    /** Last statement balance of each bank account (chequing / savings), and when. */
    private function openingBalance(): array
    {
        $out = ['balance' => 0.0, 'as_of' => null, 'accounts' => []];
        try {
            if (!class_exists('BankBalanceCheckService')) require_once __DIR__ . '/BankBalanceCheckService.php';
            if (!class_exists('StatementCoverageService')) require_once __DIR__ . '/StatementCoverageService.php';
            $svc = new BankBalanceCheckService($this->db);
            $accounts = $svc->trackedAccounts();
            foreach ($svc->statements($accounts) as $aid => $st) {
                if (($accounts[$aid]['kind'] ?? '') !== 'bank') continue;
                $bal = null; $date = null;
                foreach (array_reverse((array)$st['ordered']) as $l) {
                    if ($l['balance'] !== null) { $bal = (float)$l['balance']; $date = substr((string)$l['date'], 0, 10); break; }
                }
                if ($bal === null) continue;
                // A statement older than 45 days isn't today's money — shown, not counted.
                $fresh = $date >= date('Y-m-d', strtotime('-45 days'));
                $out['accounts'][] = ['name' => $accounts[$aid]['name'], 'balance' => round($bal, 2), 'as_of' => $date, 'counted' => $fresh];
                if (!$fresh) continue;
                $out['balance'] += $bal;
                if ($out['as_of'] === null || $date < $out['as_of']) $out['as_of'] = $date;
            }
        } catch (Throwable $e) {
            error_log('[cashflow] opening: ' . $e->getMessage());
        }
        $out['balance'] = round($out['balance'], 2);
        return $out;
    }

    private function openInvoices(): array
    {
        return $this->db->query("
            SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.balance_due, i.company_id, i.contact_id, i.client_id,
                   COALESCE(NULLIF(co.company_name, ''), TRIM(CONCAT(COALESCE(ct.first_name, ''), ' ', COALESCE(ct.last_name, '')))) AS who
            FROM invoices i
            LEFT JOIN companies co ON co.id = i.company_id
            LEFT JOIN contacts ct ON ct.id = i.contact_id
            WHERE i.balance_due > 0.009 AND i.status IN ('sent', 'viewed', 'overdue', 'partial', 'partially_paid')
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** [client key => days late, company-wide median] from the last 12 months of paid invoices. */
    private function latenessByClient(): array
    {
        $rows = $this->db->query("
            SELECT company_id, contact_id, client_id, DATEDIFF(COALESCE(paid_at, payment_date), due_date) AS late
            FROM invoices
            WHERE status = 'paid' AND due_date IS NOT NULL AND COALESCE(paid_at, payment_date) IS NOT NULL
              AND COALESCE(paid_at, payment_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $by = [];
        $all = [];
        foreach ($rows as $r) {
            $by[$this->clientKey($r)][] = (int)$r['late'];
            $all[] = (int)$r['late'];
        }
        $out = [];
        foreach ($by as $k => $v) {
            if (count($v) >= 2) $out[$k] = self::lateness($v);   // two payments before trusting a habit
        }
        return [$out, self::lateness($all) ?? 0];
    }

    private function clientKey(array $r): string
    {
        if (!empty($r['company_id'])) return 'co' . (int)$r['company_id'];
        if (!empty($r['client_id'])) return 'cl' . (int)$r['client_id'];
        return 'ct' . (int)($r['contact_id'] ?? 0);
    }

    /** Scheduled visits on the calendar, not invoiced, not snow routes, with their price. */
    private function upcomingVisits(string $today, string $until): array
    {
        $snow = '';
        try {
            $this->db->query('SELECT 1 FROM snow_route_rates LIMIT 0');
            $snow = ' AND NOT EXISTS (SELECT 1 FROM snow_route_rates r WHERE r.plan_id = jp.id)';
        } catch (Throwable $e) { /* migration 1310 not run */ }
        $st = $this->db->prepare("
            SELECT jv.id, jv.scheduled_date, jp.price_per_visit AS price, jp.company_id, jp.client_id, p.site_contact_id AS contact_id, p.address
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            LEFT JOIN properties p ON p.id = jp.property_id
            WHERE jv.status = 'scheduled' AND jv.stop_id IS NOT NULL AND COALESCE(jv.is_invoiced, 0) = 0
              AND jp.status = 'active' AND jp.price_per_visit > 0
              AND jv.scheduled_date BETWEEN ? AND ?{$snow}
        ");
        $st->execute([$today, $until]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Recurring plans' visits after the last one generated, priced, not snow routes. */
    private function projectedVisits(string $until): array
    {
        $snow = '';
        try { $this->db->query('SELECT 1 FROM snow_route_rates LIMIT 0'); $snow = ' AND NOT EXISTS (SELECT 1 FROM snow_route_rates r WHERE r.plan_id = jp.id)'; } catch (Throwable $e) {}
        $plans = $this->db->query("
            SELECT jp.id, jp.price_per_visit AS price, jp.plan_end_date, jp.company_id, jp.client_id, p.site_contact_id AS contact_id, p.address
            FROM job_plans jp LEFT JOIN properties p ON p.id = jp.property_id
            WHERE jp.status = 'active' AND jp.is_recurring = 1 AND jp.price_per_visit > 0{$snow}
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $st = $this->db->prepare("SELECT scheduled_date FROM job_visits WHERE plan_id = ? AND status IN ('scheduled', 'completed', 'in_progress') AND scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) ORDER BY scheduled_date");
        $out = [];
        foreach ($plans as $pl) {
            $st->execute([(int)$pl['id']]);
            foreach (self::projectVisits($st->fetchAll(PDO::FETCH_COLUMN) ?: [], $pl['plan_end_date'] ?: null, $until) as $d) {
                $out[] = $pl + ['date' => $d];
            }
        }
        return $out;
    }

    /** Bank deposits per forecast week, taken from the same week last year (week start => amount). */
    private function incomeLastYear(string $today, string $until): array
    {
        $from = date('Y-m-d', strtotime(self::weekStart($today) . ' -1 year'));
        $to = date('Y-m-d', strtotime($until . ' -1 year'));
        $st = $this->db->prepare("
            SELECT transaction_date, amount, description FROM accounting_transactions
            WHERE reference_type = 'bank_import' AND amount > 0 AND type IN ('income', 'transfer') AND transaction_date BETWEEN ? AND ?
        ");
        $st->execute([$from, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (RecurringBillService::isTransfer((string)$r['description'])) continue;   // money moved between our own accounts
            $wk = self::weekStart(date('Y-m-d', strtotime($r['transaction_date'] . ' +1 year')));
            $out[$wk] = ($out[$wk] ?? 0) + (float)$r['amount'];
        }
        return array_map(fn($v) => round($v, 2), $out);
    }

    private function bankSpending(int $days): array
    {
        $st = $this->db->prepare("
            SELECT transaction_date, amount, description FROM accounting_transactions
            WHERE reference_type = 'bank_import' AND type = 'expense' AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY transaction_date
        ");
        $st->execute([$days]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Bank spending that isn't a bill, a transfer or tax: the recent 8-week weekly average, a
     * weekly average for each of the last 15 months, and the biggest payees in the 8 weeks.
     */
    private function everyday(array $billKeys): array
    {
        $out = ['recent' => 0.0, 'monthly' => [], 'top' => []];
        $last = $this->db->query("SELECT MAX(transaction_date) FROM accounting_transactions WHERE reference_type = 'bank_import'")->fetchColumn();
        if (!$last) return $out;
        $st = $this->db->prepare("
            SELECT transaction_date, amount, description FROM accounting_transactions
            WHERE reference_type = 'bank_import' AND type = 'expense' AND transaction_date > DATE_SUB(?, INTERVAL 15 MONTH)
        ");
        $st->execute([$last]);
        $recentFrom = date('Y-m-d', strtotime($last . ' -56 days'));
        $byMonth = []; $recent = 0.0; $payees = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = (string)$r['description'];
            if (RecurringBillService::isTransfer($d) || isset($billKeys[RecurringBillService::payeeKey($d)]) || self::isTaxPayment($d)) continue;
            $m = substr((string)$r['transaction_date'], 0, 7);
            $byMonth[$m] = ($byMonth[$m] ?? 0) + (float)$r['amount'];
            if ($r['transaction_date'] > $recentFrom) {
                $recent += (float)$r['amount'];
                $k = RecurringBillService::label($d);
                $payees[$k] = ($payees[$k] ?? 0) + (float)$r['amount'];
            }
        }
        $lastMonth = substr((string)$last, 0, 7);
        foreach ($byMonth as $m => $sum) {
            if ($m === $lastMonth) continue;   // a part month isn't a month
            $out['monthly'][$m] = round($sum / ((int)date('t', strtotime($m . '-01')) / 7), 2);
        }
        arsort($payees);
        foreach (array_slice($payees, 0, 8, true) as $k => $v) $out['top'][] = ['payee' => $k, 'amount' => round($v, 2)];
        $out['recent'] = round($recent / 8, 2);
        return $out;
    }
}
