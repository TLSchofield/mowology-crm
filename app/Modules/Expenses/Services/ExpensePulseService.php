<?php
/**
 * ExpensePulseService — the one picture of spending a solo operator needs beside Penny's decision (Tim, 2026-11-10):
 * the last six months of approved receipts as bars, this month marked, and the GST on this quarter's receipts
 * (what can be claimed back). Reads approved expenses only; writes nothing. shape() is pure and tested.
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

class ExpensePulseService
{
    public const MONTHS = 6;

    /**
     * @param array<int, array{ym: string, total: float|string, gst: float|string}> $rows  one per month present
     * @return array{months: array<int, array{ym: string, label: string, total: float, gst: float, now: bool}>, max: float, quarter_gst: float, quarter_label: string, this_month: float}
     */
    public static function shape(array $rows, int $now): array
    {
        $byYm = [];
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['ym'])) continue;
            $byYm[(string)$r['ym']] = ['total' => round((float)($r['total'] ?? 0), 2), 'gst' => round((float)($r['gst'] ?? 0), 2)];
        }
        $months = [];
        $max = 0.0;
        $thisYm = date('Y-m', $now);
        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $t = mktime(0, 0, 0, (int)date('n', $now) - $i, 1, (int)date('Y', $now));
            $ym = date('Y-m', $t);
            $row = $byYm[$ym] ?? ['total' => 0.0, 'gst' => 0.0];
            $months[] = ['ym' => $ym, 'label' => date('M', $t), 'total' => $row['total'], 'gst' => $row['gst'], 'now' => $ym === $thisYm];
            if ($row['total'] > $max) $max = $row['total'];
        }
        // The calendar quarter we are in (Jan–Mar, Apr–Jun, …): GST on its receipts is the input tax credit to claim.
        $q = (int)ceil((int)date('n', $now) / 3);
        $qStart = ($q - 1) * 3 + 1;
        $qGst = 0.0;
        foreach ($months as $m) {
            [$y, $mo] = array_map('intval', explode('-', $m['ym']));
            if ($y === (int)date('Y', $now) && $mo >= $qStart && $mo < $qStart + 3) $qGst += $m['gst'];
        }
        return [
            'months'        => $months,
            'max'           => $max,
            'quarter_gst'   => round($qGst, 2),
            'quarter_label' => 'Q' . $q . ' ' . date('Y', $now),
            'this_month'    => $months[count($months) - 1]['total'],
        ];
    }

    /** Approved receipts by month for the window; [] on any failure. */
    public static function monthly(PDO $db, int $now): array
    {
        try {
            $from = date('Y-m-01', mktime(0, 0, 0, (int)date('n', $now) - (self::MONTHS - 1), 1, (int)date('Y', $now)));
            $s = $db->prepare("SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, SUM(total) AS total, SUM(gst_amount) AS gst
                               FROM expenses WHERE status = 'approved' AND expense_date >= ? GROUP BY ym ORDER BY ym");
            $s->execute([$from]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('ExpensePulseService: ' . $e->getMessage());
            return [];
        }
    }

    public static function load(PDO $db, int $now): array
    {
        return self::shape(self::monthly($db, $now), $now);
    }
}
