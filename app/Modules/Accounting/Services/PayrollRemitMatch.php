<?php
declare(strict_types=1);

/**
 * PayrollRemitMatch — is last month's CRA payroll remittance paid? (Tim, 2026-11-03)
 *
 * Wave remits source deductions to CRA itself by debiting the bank on pay day ("WAVE PYRL"). The calendar reminder
 * "Payroll remittance to CRA" only knew the date, so an un-ticked reminder made Kaname's whole brief RED. The truth is
 * in two places that already exist: the month's Wave Wage & Tax report (payroll_runs.remittance) and the bank lines.
 * Three honest states, in this order (ask first, alarm last):
 *   no_report  no payroll run for the month is imported      -> ASK for the report (never "overdue", never red)
 *   matched    the run's remittance equals Wave debits       -> closed automatically, evidence in the note
 *   unchecked  report in, no match yet, bank lines stop before the due date -> ASK for the statement (not red)
 *   pending    report in, no match yet, not due yet          -> nothing to say yet
 *   short      report in, bank lines cover the due date, still no match -> the only real overdue (red)
 *   na         payroll is not run through Wave here          -> the plain calendar reminder, unchanged
 * Pure and static. The matcher is the importer's own subsetSum() (same tolerance), not a second one.
 */
final class PayrollRemitMatch
{
    public const NO_REPORT = 'no_report';
    public const MATCHED   = 'matched';
    public const UNCHECKED = 'unchecked';
    public const PENDING   = 'pending';
    public const SHORT     = 'short';
    public const NA        = 'na';

    /** Wave's CRA remittance debit (same patterns as the importer; its payroll FEE line is not a remittance). */
    public static function isWaveDebit(string $description): bool
    {
        return (bool)preg_match(WavePayrollImportService::WAVE_REMIT_RE, $description)
            && !preg_match(WavePayrollImportService::WAVE_FEE_RE, $description);
    }

    /** 'YYYY-MM' the remittance due on $due covers: the month before. */
    public static function monthFor(string $due): string
    {
        return date('Y-m', strtotime(substr($due, 0, 7) . '-01 -1 month'));
    }

    /** The date window in which that month's Wave debits sit (the importer's own: 10 days before to 5 after). */
    public static function window(string $month, string $due): array
    {
        $from = date('Y-m-d', strtotime($month . '-01 -10 days'));
        $to = date('Y-m-d', strtotime(date('Y-m-t', strtotime($month . '-01')) . ' +5 days'));
        return [$from, $to < $due ? $to : $due];
    }

    /**
     * @param ?array $run   payroll_runs row for the month (needs 'remittance'), or null when none is imported
     * @param array  $debits Wave debits near the month: each ['id', 'date', 'amount']
     * @param ?string $bankCoveredTo newest bank-import transaction date (null = no bank lines at all)
     * @param bool $inUse   payroll is run through Wave here (a run exists, or Wave debits were seen)
     * @param int[] $claimed transaction ids another month's remittance already used (never reused here)
     * @return array{state: string, month: string, remittance: ?float, found: float, ids: array, lines: array, text: ?string, note: ?string}
     */
    public static function assess(?array $run, array $debits, string $due, string $today, ?string $bankCoveredTo, bool $inUse, array $claimed = []): array
    {
        $month = self::monthFor($due);
        $label = date('F', strtotime($month . '-01'));
        $base = ['state' => self::NA, 'month' => $month, 'remittance' => null, 'found' => 0.0, 'ids' => [], 'lines' => [], 'text' => null, 'note' => null];
        if (!$inUse && !$run) return $base;

        if (!$run) {
            $base['state'] = self::NO_REPORT;
            $base['text'] = "Upload {$label}'s Wave payroll report in Penny (Wages & taxes), so the CRA remittance can be checked";
            return $base;
        }

        // A report with no usable figure is not "nothing to pay": no remittance value, or $0, asks for a re-import.
        // A $0 report never closes the reminder by itself (an empty or half-read import looks exactly like that); Tim presses Done.
        $hasFigure = isset($run['remittance']) && is_numeric($run['remittance']);
        $target = $hasFigure ? round((float)$run['remittance'], 2) : 0.0;
        if (!$hasFigure || $target <= 0.0) {
            $base['state'] = self::NO_REPORT;
            $base['text'] = "The {$label} Wave payroll report has no usable CRA remittance figure. Import it again in Penny (Wages & taxes), or mark this Done if there was no payroll";
            return $base;
        }
        $base['remittance'] = $target;

        [$from, $to] = self::window($month, $due);
        $cands = [];
        $byId = [];
        foreach ($debits as $d) {
            $date = substr((string)($d['date'] ?? ''), 0, 10);
            if ($date < $from || $date > $to || (float)($d['amount'] ?? 0) <= 0) continue;
            $cands[(int)$d['id']] = round((float)$d['amount'], 2);
            $byId[(int)$d['id']] = ['id' => (int)$d['id'], 'date' => $date, 'amount' => round((float)$d['amount'], 2)];
        }
        $skip = array_flip(array_map('intval', $claimed));
        $cands = array_diff_key($cands, $skip);
        // Pass 1: only debits dated inside the month itself. Pass 2 (pay day slipped a few days over the month end): the importer's wider window.
        $inMonth = array_filter($cands, fn($amt, $id) => $byId[$id]['date'] >= $month . '-01' && $byId[$id]['date'] <= date('Y-m-t', strtotime($month . '-01')), ARRAY_FILTER_USE_BOTH);
        $ids = $inMonth ? WavePayrollImportService::subsetSum($inMonth, $target) : null;
        if ($ids === null && $cands) $ids = WavePayrollImportService::subsetSum($cands, $target);
        if ($ids !== null) {
            $lines = array_values(array_map(fn($id) => $byId[$id], $ids));
            usort($lines, fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            $parts = array_map(fn($l) => date('M j', strtotime($l['date'])) . ' $' . number_format($l['amount'], 2), $lines);
            $found = round(array_sum(array_column($lines, 'amount')), 2);
            return array_merge($base, [
                'state' => self::MATCHED, 'found' => $found, 'ids' => $ids, 'lines' => $lines,
                'note' => 'Paid by Wave: ' . implode(' + ', $parts) . ' = $' . number_format($found, 2) . ", matched to the {$label} report",
            ]);
        }

        $amount = '$' . number_format($target, 2);
        if ($today <= $due) return array_merge($base, ['state' => self::PENDING]);
        if ($bankCoveredTo === null || $bankCoveredTo < $due) {
            $upto = $bankCoveredTo ? date('M j', strtotime($bankCoveredTo)) : 'none yet';
            return array_merge($base, [
                'state' => self::UNCHECKED,
                'text' => "Payroll remittance for {$label} ({$amount}): the bank lines are only in up to {$upto}, so Wave's debit can't be checked. Import the bank statement",
            ]);
        }
        return array_merge($base, [
            'state' => self::SHORT,
            'text' => "{$amount} CRA payroll remittance for {$label}: no matching Wave debit found",
        ]);
    }
}
