<?php
declare(strict_types=1);

/**
 * BankLineAge — what kind of reconciling item an unsorted bank line is, and how old it has got.
 * Harvested from the finance plugin's reconciliation method (2026-11-03): every open item is one of
 *   timing        will clear on its own within the processing cycle (a transfer between our accounts, a cheque in transit,
 *                 a line younger than TIMING_DAYS) — no entry needed, nothing to ask
 *   adjustment    needs a small entry: bank fees, interest, NSF/returned items, a known duplicate
 *   investigate   unidentified, disputed, or simply old — somebody has to look
 * and sits in an age bucket: current (0–30), aging (31–60), overdue (61–90), stale (90+ — write off or adjust).
 * Any unreconciled difference blocks the close, whatever its size; the buckets say how loudly. Pure and static.
 */
final class BankLineAge
{
    public const TIMING_DAYS = 5;
    public const BUCKETS = ['current' => 30, 'aging' => 60, 'overdue' => 90, 'stale' => PHP_INT_MAX];
    private const ADJUSTMENT_RE = '/\b(fee|service charge|monthly plan|interest|nsf|returned|reversal|chargeback|overdraft|adjust)/i';
    private const TIMING_RE = '/\b(transfer|tfr|e-?transfer sent|internal|between accounts|cheque|check)\b/i';

    /** @param array $line {transaction_date, amount, description, type?} @return array{class:string, bucket:string, age:int, why:string} */
    public static function classify(array $line, string $today): array
    {
        $age = max(0, (int)floor((strtotime($today) - strtotime(substr((string)$line['transaction_date'], 0, 10))) / 86400));
        $desc = (string)($line['description'] ?? '');
        $bucket = 'stale';
        foreach (self::BUCKETS as $name => $max) { if ($age <= $max) { $bucket = $name; break; } }
        if (preg_match(self::ADJUSTMENT_RE, $desc)) {
            $class = 'adjustment'; $why = 'a bank charge, interest or reversal — a small entry, not a mystery';
        } elseif ($age <= self::TIMING_DAYS || preg_match(self::TIMING_RE, $desc)) {
            $class = 'timing'; $why = $age <= self::TIMING_DAYS ? 'posted in the last ' . self::TIMING_DAYS . ' days — give it a cycle' : 'looks like a transfer or cheque clearing';
        } else {
            $class = 'investigate'; $why = $age > 90 ? 'over 90 days unsorted — write it off or adjust it, but decide' : 'unidentified after ' . $age . ' days';
        }
        return ['class' => $class, 'bucket' => $bucket, 'age' => $age, 'why' => $why];
    }

    /** Counts by class and bucket, plus the close-page sentences. @param array $lines rows for one month */
    public static function summarise(array $lines, string $today): array
    {
        $by = ['class' => ['timing' => 0, 'adjustment' => 0, 'investigate' => 0], 'bucket' => ['current' => 0, 'aging' => 0, 'overdue' => 0, 'stale' => 0], 'amount_stale' => 0.0];
        foreach ($lines as $l) {
            $c = self::classify($l, $today);
            $by['class'][$c['class']]++; $by['bucket'][$c['bucket']]++;
            if ($c['bucket'] === 'stale') $by['amount_stale'] += abs((float)($l['amount'] ?? 0));
        }
        $say = [];
        if ($by['bucket']['stale'] > 0) $say[] = $by['bucket']['stale'] . ' bank line' . ($by['bucket']['stale'] === 1 ? '' : 's') . ' over 90 days unsorted ($' . number_format($by['amount_stale'], 2) . ') — write off or adjust, but decide';
        if ($by['bucket']['overdue'] > 0) $say[] = $by['bucket']['overdue'] . ' bank line' . ($by['bucket']['overdue'] === 1 ? '' : 's') . ' 61–90 days old — needs a look this week';
        if ($by['class']['adjustment'] > 0) $say[] = $by['class']['adjustment'] . ' look' . ($by['class']['adjustment'] === 1 ? 's' : '') . ' like a fee, interest or reversal — a small entry each';
        return $by + ['say' => $say];
    }
}
