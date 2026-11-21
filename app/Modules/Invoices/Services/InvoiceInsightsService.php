<?php
declare(strict_types=1);

/**
 * The invoice graphs on the Invoices page (the Ittai decision strip shows them when nothing is waiting to be decided).
 * Three pictures, all from the invoices table and so the same for any trade:
 *   months   money in: paid invoices by the month they were paid, last 12 months
 *   aging    what is still owed, by how late it is: not yet due, 1-30, 31-60, 61+ days
 *   pay_days average days from invoice to payment, by month paid, last 6 months
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
            $from = date('Y-m-01', strtotime($today . ' -11 months'));
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

        return [
            'months'     => $monthRows,
            'year_total' => round(array_sum(array_column($monthRows, 'total')), 2),
            'this_month' => (float)end($monthRows)['total'],
            'aging'      => $buckets,
            'owed_total' => round($owed, 2),
            'owed_count' => $owedN,
            'pay_days'   => $payRows,
            'avg_days'   => $nDays > 0 ? round($sumDays / $nDays, 1) : null,
            'has_data'   => (bool)$paid || (bool)$open,
        ];
    }
}
