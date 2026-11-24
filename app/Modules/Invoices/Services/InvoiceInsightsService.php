<?php
declare(strict_types=1);

/**
 * The invoice graphs on the Invoices page (the Ittai decision strip shows them when nothing is waiting to be decided).
 * Pure data for the Invoices-page operator insights: money in, payment speed, forecast cash,
 * and who holds the open balance. The legacy aging result stays additive for existing callers.
 * Rows are summed in PHP so the same code runs on any database. Any failure gives empty graphs, never an error.
 */
final class InvoiceInsightsService
{
    public const OPEN_STATUSES = ['sent', 'viewed', 'partial', 'overdue'];

    /**
     * @return array{months: array<int, array{label: string, total: float, now: bool}>, year_total: float, this_month: float,
     *               aging: array<int, array{label: string, count: int, amount: float, late: bool}>, owed_total: float, owed_count: int,
     *               pay_days: array<int, array{label: string, days: ?float, now: bool}>, avg_days: ?float, has_data: bool}
     */
    public static function snapshot(PDO $db, string $today): array
    {
        $paid = [];
        $open = [];
        try {
            $from = date('Y-m-01', strtotime($today . ' -23 months'));
            $s = $db->prepare("SELECT created_at, paid_at, total FROM invoices WHERE status = 'paid' AND paid_at >= ?");
            $s->execute([$from]);
            $paid = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $in = "'" . implode("','", self::OPEN_STATUSES) . "'";
            $open = $db->query("SELECT due_date, balance_due FROM invoices WHERE status IN ($in) AND balance_due > 0.005")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('InvoiceInsightsService: ' . $e->getMessage());
        }
        return self::build($paid, $open, $today);
    }

    /**
     * Pure. $paid rows: created_at, paid_at, total. $open rows: due_date (may be null), balance_due.
     */
    public static function build(array $paid, array $open, string $today): array
    {
        $nowKey = substr($today, 0, 7);
        $lastYearKey = date('Y-m', strtotime($nowKey . '-01 -1 year'));
        $priorYearFrom = date('Y-m', strtotime($nowKey . '-01 -23 months'));
        $priorYearTo = date('Y-m', strtotime($nowKey . '-01 -12 months'));
        $lastYearTotal = 0.0;
        $lastYearCount = 0;
        $priorYearCount = 0;
        foreach ($paid as $r) {
            $k = substr((string)($r['paid_at'] ?? ''), 0, 7);
            if ($k >= $priorYearFrom && $k <= $priorYearTo) $priorYearCount++;
            if ($k === $lastYearKey) { $lastYearTotal += (float)($r['total'] ?? 0); $lastYearCount++; }
        }
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $t = strtotime(substr($today, 0, 7) . '-01 -' . $i . ' months');
            $months[date('Y-m', $t)] = ['label' => date('M', $t), 'total' => 0.0, 'now' => date('Y-m', $t) === $nowKey, 'days' => 0.0, 'n' => 0];
        }
        foreach ($paid as $r) {
            $k = substr((string)($r['paid_at'] ?? ''), 0, 7);
            if (!isset($months[$k])) continue;
            $months[$k]['total'] += (float)($r['total'] ?? 0);
            $c = strtotime((string)($r['created_at'] ?? ''));
            $p = strtotime((string)($r['paid_at'] ?? ''));
            if ($c && $p && $p >= $c) {
                $months[$k]['days'] += ($p - $c) / 86400;
                $months[$k]['n']++;
            }
        }
        $monthRows = [];
        $payRows = [];
        $sumDays = 0.0; $nDays = 0;
        $i = 0;
        foreach ($months as $m) {
            $monthRows[] = ['label' => $m['label'], 'total' => round($m['total'], 2), 'now' => $m['now']];
            if ($i >= 6) {   // the last 6 months
                $payRows[] = ['label' => $m['label'], 'days' => $m['n'] > 0 ? round($m['days'] / $m['n'], 1) : null, 'now' => $m['now']];
                $sumDays += $m['days']; $nDays += $m['n'];
            }
            $i++;
        }

        $buckets = [
            ['label' => 'Not due', 'count' => 0, 'amount' => 0.0, 'late' => false],
            ['label' => '1–30', 'count' => 0, 'amount' => 0.0, 'late' => true],
            ['label' => '31–60', 'count' => 0, 'amount' => 0.0, 'late' => true],
            ['label' => '61+', 'count' => 0, 'amount' => 0.0, 'late' => true],
        ];
        $todayDate = new DateTimeImmutable(substr($today, 0, 10));
        $owed = 0.0; $owedN = 0;
        foreach ($open as $r) {
            $late = 0;
            if (!empty($r['due_date'])) {
                $d = substr((string)$r['due_date'], 0, 10);   // calendar days, so a daylight-saving change can't lose a day
                $late = (int)(new DateTimeImmutable($d))->diff($todayDate)->format('%r%a');   // positive once past due
            }
            $b = $late <= 0 ? 0 : ($late <= 30 ? 1 : ($late <= 60 ? 2 : 3));
            $amt = (float)($r['balance_due'] ?? 0);
            $buckets[$b]['count']++;
            $buckets[$b]['amount'] += $amt;
            $owed += $amt; $owedN++;
        }
        foreach ($buckets as &$bk) $bk['amount'] = round($bk['amount'], 2);
        unset($bk);

        $thisMonth = (float)end($monthRows)['total'];
        $lastYear = $lastYearCount > 0 || $priorYearCount > 0 ? round($lastYearTotal, 2) : null;
        $yoy = $lastYear !== null && $lastYear > 0 ? round(($thisMonth - $lastYear) / $lastYear * 100, 1) : null;
        $moneyHeadline = self::money($thisMonth) . ' this month';
        if ($yoy !== null) {
            $direction = $yoy >= 0 ? 'up' : 'down';
            $moneyHeadline .= ', ' . $direction . ' ' . self::percent(abs($yoy)) . '% on last ' . date('F', strtotime($lastYearKey . '-01'));
        }
        $avgDays = $nDays > 0 ? round($sumDays / $nDays, 1) : null;
        $withPay = array_values(array_filter($payRows, static fn($m) => $m['days'] !== null));
        $payHeadline = $avgDays !== null ? 'Paid in ' . self::number($avgDays) . ' days on average' : 'No payments yet';
        if ($avgDays !== null && count($withPay) >= 2) {
            $latest = $withPay[count($withPay) - 1];
            $change = round((float)$withPay[0]['days'] - (float)$latest['days'], 1);   // positive: the latest month is quicker than the first
            if ($change != 0.0) $payHeadline .= ' — latest month ' . self::number(abs($change)) . ' days ' . ($change > 0 ? 'faster' : 'slower') . ' than in ' . $withPay[0]['label'];
        }

        return [
            'months'     => $monthRows,
            'year_total' => round(array_sum(array_column($monthRows, 'total')), 2),
            'this_month' => $thisMonth,
            'last_year_same_month' => $lastYear,
            'yoy_pct'     => $yoy,
            'money_headline' => $moneyHeadline,
            'aging'      => $buckets,
            'owed_total' => round($owed, 2),
            'owed_count' => $owedN,
            'pay_days'   => $payRows,
            'avg_days'   => $avgDays,
            'pay_headline' => $payHeadline,
            'has_data'   => array_sum(array_column($monthRows, 'total')) > 0 || (bool)$open,   // the 12-month window, as before the 24-month fetch
        ];
    }

    public static function cashLine(?array $forecast, string $today): ?array
    {
        if (!$forecast || empty($forecast['weeks']) || empty($forecast['opening_as_of'])) return null;   // no fresh bank balance: the line would start from $0, so say nothing
        $points = [];
        $low = null;
        foreach ($forecast['weeks'] as $week) {
            $point = ['start' => (string)$week['start'], 'closing' => (float)$week['closing'],
                      'low' => (float)($week['closing_low'] ?? $week['closing']),
                      'high' => (float)($week['closing_high'] ?? $week['closing'])];
            $points[] = $point;
            if ($low === null || $point['closing'] < $low['closing']) $low = ['start' => $point['start'], 'closing' => $point['closing']];
        }
        $floor = (float)($forecast['floor'] ?? 0);
        $below = $low['closing'] < $floor;
        $when = date('M j', strtotime($low['start']));
        $headline = $below
            ? 'Cash dips to ' . self::money($low['closing']) . ' the week of ' . $when . ' — under your ' . self::money($floor) . ' floor'
            : 'Stays above your ' . self::money($floor) . ' floor; lowest is ' . self::money($low['closing']) . ' the week of ' . $when;
        return ['points' => $points, 'floor' => $floor, 'low' => $low, 'below_floor' => $below,
                'as_of' => (string)$forecast['opening_as_of'],
                'band_pct' => (float)($forecast['band']['pct'] ?? 0), 'thin' => !empty($forecast['band']['thin']),
                'headline' => $headline];
    }

    public static function holders(array $invoiceEvents, string $today, int $limit = 4): array
    {
        unset($today);   // Kept in the pure-function contract so date-relative wording can be added without changing callers.
        $groups = [];
        $lateTotal = 0.0;
        $lateNames = [];
        foreach ($invoiceEvents as $event) {
            $who = trim((string)($event['who'] ?? '')) ?: 'client';
            if (!isset($groups[$who])) $groups[$who] = ['who' => $who, 'amount' => 0.0, 'late_amount' => 0.0, 'count' => 0, 'expect' => '', 'late' => false];
            $amount = (float)($event['amount'] ?? 0);
            $groups[$who]['amount'] += $amount;
            $groups[$who]['count']++;
            $date = substr((string)($event['date'] ?? ''), 0, 10);
            if ($date !== '' && ($groups[$who]['expect'] === '' || $date < $groups[$who]['expect'])) $groups[$who]['expect'] = $date;
            if (!empty($event['late'])) {
                $groups[$who]['late'] = true;
                $groups[$who]['late_amount'] += $amount;
                $lateTotal += $amount;
                $lateNames[$who] = true;
            }
        }
        usort($groups, static fn($a, $b) => $b['amount'] <=> $a['amount']);
        $rows = array_slice($groups, 0, max(0, $limit));
        $lateTopTotal = 0.0;
        foreach ($rows as &$row) {
            $row['amount'] = round($row['amount'], 2);
            $lateTopTotal += $row['late_amount'];
            unset($row['late_amount']);
            $row['expect'] = $row['expect'] !== '' ? date('M j', strtotime($row['expect'])) : '';
        }
        unset($row);
        $total = array_sum(array_column($groups, 'amount'));
        $topLate = count(array_filter($rows, static fn($r) => !empty($r['late'])));
        $plural = static fn(int $n): string => $n . ' client' . ($n === 1 ? '' : 's');
        $headline = $lateTotal > 0
            ? (abs($lateTotal - $lateTopTotal) < 0.005
                ? self::money($lateTotal) . ' is late, across ' . $plural(count($lateNames))
                : self::money($lateTotal) . ' is late; ' . self::money($lateTopTotal) . ' of it is ' . $plural($topLate))
            : 'Nothing late — ' . self::money($total) . ' due in over the next weeks';
        return ['rows' => $rows, 'late_total' => round($lateTotal, 2), 'late_clients' => count($lateNames),
                'late_top_total' => round($lateTopTotal, 2), 'total' => round($total, 2), 'headline' => $headline];
    }

    private static function money(float $value): string
    {
        return '$' . number_format($value, abs($value) >= 1000 ? 0 : 2);
    }

    private static function number(float $value): string
    {
        return number_format($value, floor($value) == $value ? 0 : 1);
    }

    private static function percent(float $value): string
    {
        return number_format($value, floor($value) == $value ? 0 : 1);
    }
}
