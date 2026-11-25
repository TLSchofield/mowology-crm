<?php
declare(strict_types=1);

/**
 * Finds likely copies among already-approved receipts without reading or changing
 * application state. Keeping this pure lets Penny's later workflow decide what to do.
 */
class TwinFinder
{
    /** Active receipts can represent a purchase in the books. */
    public static function active(array $receipt): bool
    {
        return in_array((string)($receipt['status'] ?? ''), ['approved', 'forwarded'], true);
    }

    /**
     * Return likely twins and incorrect bank-line links from plain input rows.
     */
    public static function find(array $receipts, array $facts, array $lines, array $dismissals): array
    {
        if (!$receipts && !$lines) return [];

        $byId = [];
        $active = [];
        foreach ($receipts as $receipt) {
            $id = (int)$receipt['id'];
            $byId[$id] = $receipt;
            if (self::active($receipt)) $active[] = $receipt;
        }
        usort($active, static function (array $a, array $b): int {
            return (int)$a['id'] <=> (int)$b['id'];
        });

        $dismissed = [];
        foreach ($dismissals as $dismissal) {
            $key = self::pairKey((int)$dismissal['expense_a'], (int)$dismissal['expense_b']);
            $dismissed[$key] = array_key_exists('dismissed_by', $dismissal) ? $dismissal['dismissed_by'] : null;
        }

        $finds = [];
        $count = count($active);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $find = self::twin($active[$i], $active[$j], $active, $facts, $lines, $dismissed);
                if ($find !== null) $finds[] = $find;
            }
        }
        foreach ($lines as $line) {
            $find = self::badLink($line, $byId, $active);
            if ($find !== null) $finds[] = $find;
        }

        usort($finds, static function (array $a, array $b): int {
            $amount = $b['amount'] <=> $a['amount'];
            if ($amount !== 0) return $amount;
            $rank = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
            $grade = ($rank[$a['grade'] ?? ''] ?? 4) <=> ($rank[$b['grade'] ?? ''] ?? 4);
            if ($grade !== 0) return $grade;
            return ($a['copy_id'] ?? PHP_INT_MAX) <=> ($b['copy_id'] ?? PHP_INT_MAX);
        });
        return $finds;
    }

    /** Build one twin find when the pair satisfies the approved prototype rules. */
    private static function twin(array $a, array $b, array $active, array $facts, array $lines, array $dismissed): ?array
    {
        $aId = (int)$a['id'];
        $bId = (int)$b['id'];
        $aLine = $a['line_id'] === null ? null : (int)$a['line_id'];
        $bLine = $b['line_id'] === null ? null : (int)$b['line_id'];
        if ($aLine !== null && $bLine !== null) return null;
        if (self::cents($a['total']) <= 0 || self::cents($a['total']) !== self::cents($b['total'])) return null;
        if (self::vendorKey((string)$a['vendor']) !== self::vendorKey((string)$b['vendor'])) return null;
        if (self::days((string)$a['expense_date'], (string)$b['expense_date']) > 3) return null;

        $pairKey = self::pairKey($aId, $bId);
        if (isset($dismissed[$pairKey])) return null;
        $reopens = array_key_exists($pairKey, $dismissed);
        $af = self::factsFor($facts, $aId);
        $bf = self::factsFor($facts, $bId);
        $aDate = self::printedDate($a, $af);
        $bDate = self::printedDate($b, $bf);
        $aFirst = self::value($af, 'time_first');
        $bFirst = self::value($bf, 'time_first');
        $aLast = self::value($af, 'time_last');
        $bLast = self::value($bf, 'time_last');
        $aNumber = self::longNumber($af);
        $bNumber = self::longNumber($bf);

        if ($aFirst !== null && $bFirst !== null && $aLast === null && $bLast === null && $aFirst !== $bFirst) return null;
        if ($aNumber !== null && $bNumber !== null && $aNumber !== $bNumber && !self::oneEditApart($aNumber, $bNumber)) return null;

        $grade = null;
        $evidence = [];
        if ($aFirst !== null && $bFirst !== null && $aDate === $bDate && $aFirst === $bFirst) {
            $grade = 'A';
            $evidence[] = 'same time ' . $aFirst;
        }
        if ($aNumber !== null && $bNumber !== null && $aNumber === $bNumber) {
            $grade = 'A';
            $evidence[] = 'same number ' . $aNumber;
        }
        if ($grade === null) {
            $minutes = self::scaleMinutes($aDate, $aLast, $bDate, $bFirst, $bLast);
            if ($minutes === null) $minutes = self::scaleMinutes($bDate, $bLast, $aDate, $aFirst, $aLast);
            if ($minutes !== null) {
                $grade = 'B';
                $evidence[] = 'slip ' . $minutes . ' min after Time Out';
            }
        }
        if ($grade === null && !($aFirst !== null && $bFirst !== null)
            && $aNumber !== null && $bNumber !== null && self::oneEditApart($aNumber, $bNumber)) {
            $grade = 'C';
            $evidence[] = 'number ' . $aNumber . ' ~ ' . $bNumber;
        }

        if ($grade === null) {
            if ($aFirst !== null && $bFirst !== null) return null;
            $receiptCount = self::nearbyReceiptCount($a, $active);
            $lineCount = self::nearbyLineCount($a, $lines);
            if ($receiptCount <= $lineCount) return null;
            $grade = 'D';
            $evidence[] = $receiptCount . ' receipts vs ' . $lineCount . ' bank line(s)';
        }

        $aLinked = $aLine !== null;
        $bLinked = $bLine !== null;
        $copyId = null;
        if ($aLinked xor $bLinked) {
            $keepId = $aLinked ? $aId : $bId;
            $copyId = $aLinked ? $bId : $aId;
            $state = 'copy_unlinked';
        } else {
            $keepId = min($aId, $bId);
            $state = 'both_unlinked';
        }
        $keep = $keepId === $aId ? $a : $b;

        return [
            'kind' => 'twin', 'grade' => $grade, 'keep_id' => $keepId, 'copy_id' => $copyId,
            'pair_ids' => [min($aId, $bId), max($aId, $bId)],
            'amount' => self::amount($a['total']), 'date' => (string)$a['expense_date'],
            'vendor' => (string)$keep['vendor'], 'evidence' => $evidence, 'state' => $state,
            'reopens_system_dismissal' => $reopens,
        ];
    }

    /** A linked rejected receipt or a material amount mismatch is an incorrect link. */
    private static function badLink(array $line, array $byId, array $active): ?array
    {
        $wrongId = $line['matched_expense_id'] === null ? 0 : (int)$line['matched_expense_id'];
        if (!$wrongId || !isset($byId[$wrongId])) return null;
        $wrong = $byId[$wrongId];
        $difference = self::amount($line['amount']) - self::amount($wrong['total']);
        if ((string)$wrong['status'] === 'rejected') {
            $reason = 'linked receipt is rejected';
        } elseif (abs($difference) > 0.015) {
            $reason = 'receipt total ' . self::money($wrong['total']) . ' vs line ' . self::money($line['amount']);
        } else {
            return null;
        }

        $rightId = self::rightReceipt($line, $active);
        $evidence = [$reason];
        if ($rightId !== null) $evidence[] = 'unlinked receipt #' . $rightId . ' matches';
        return [
            'kind' => 'bad_link', 'line_id' => (int)$line['id'], 'wrong_id' => $wrongId,
            'right_id' => $rightId, 'amount' => self::amount($line['amount']),
            'difference' => $difference, 'reason' => $reason,
            'date' => (string)$line['transaction_date'], 'evidence' => $evidence,
        ];
    }

    /** Prefer the closest-date active, unlinked receipt that balances the bank line. */
    private static function rightReceipt(array $line, array $active): ?int
    {
        $choices = [];
        foreach ($active as $receipt) {
            if ($receipt['line_id'] !== null || self::cents($receipt['total']) !== self::cents($line['amount'])) continue;
            $days = self::days((string)$receipt['expense_date'], (string)$line['transaction_date']);
            if ($days <= 3) $choices[] = ['id' => (int)$receipt['id'], 'days' => $days];
        }
        usort($choices, static function (array $a, array $b): int {
            return ($a['days'] <=> $b['days']) ?: ($a['id'] <=> $b['id']);
        });
        return $choices ? $choices[0]['id'] : null;
    }

    private static function nearbyReceiptCount(array $receipt, array $active): int
    {
        $count = 0;
        foreach ($active as $candidate) {
            if (self::cents($candidate['total']) === self::cents($receipt['total'])
                && self::vendorKey((string)$candidate['vendor']) === self::vendorKey((string)$receipt['vendor'])
                && self::days((string)$candidate['expense_date'], (string)$receipt['expense_date']) <= 3) $count++;
        }
        return $count;
    }

    private static function nearbyLineCount(array $receipt, array $lines): int
    {
        $count = 0;
        foreach ($lines as $line) {
            if (self::cents($line['amount']) === self::cents($receipt['total'])
                && self::days((string)$line['transaction_date'], (string)$receipt['expense_date']) <= 5) $count++;
        }
        return $count;
    }

    private static function scaleMinutes(string $scaleDate, ?string $last, string $slipDate, ?string $first, ?string $slipLast): ?int
    {
        if ($last === null || $first === null || $slipLast !== null || $scaleDate !== $slipDate) return null;
        $minutes = self::clockMinutes($first) - self::clockMinutes($last);
        return $minutes >= 0 && $minutes <= 5 ? $minutes : null;
    }

    private static function oneEditApart(string $a, string $b): bool
    {
        $aLength = strlen($a);
        $bLength = strlen($b);
        if (abs($aLength - $bLength) > 1) return false;
        if ($aLength === $bLength) {
            $different = 0;
            for ($i = 0; $i < $aLength; $i++) if ($a[$i] !== $b[$i]) $different++;
            return $different === 1;
        }
        if ($aLength > $bLength) return self::oneEditApart($b, $a);
        $i = 0;
        $j = 0;
        $skipped = 0;
        while ($i < $aLength && $j < $bLength) {
            if ($a[$i] === $b[$j]) { $i++; $j++; continue; }
            $skipped++;
            $j++;
            if ($skipped > 1) return false;
        }
        return true;
    }

    private static function factsFor(array $facts, int $id): array
    {
        return isset($facts[$id]) && is_array($facts[$id]) ? $facts[$id] : [];
    }

    private static function value(array $facts, string $key): ?string
    {
        if (!isset($facts[$key])) return null;
        $value = trim((string)$facts[$key]);
        return $value === '' ? null : $value;
    }

    private static function longNumber(array $facts): ?string
    {
        $number = self::value($facts, 'doc_number');
        return $number !== null && strlen($number) >= 5 ? $number : null;
    }

    private static function printedDate(array $receipt, array $facts): string
    {
        return self::value($facts, 'printed_date') ?? (string)$receipt['expense_date'];
    }

    private static function vendorKey(string $vendor): string
    {
        return substr((string)preg_replace('/[^a-z0-9]/', '', strtolower($vendor)), 0, 5);
    }

    private static function pairKey(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    private static function cents($amount): int
    {
        return (int)round((float)$amount * 100);
    }

    private static function amount($amount): float
    {
        return self::cents($amount) / 100;
    }

    private static function money($amount): string
    {
        return number_format(self::amount($amount), 2, '.', '');
    }

    private static function days(string $a, string $b): int
    {
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $a);
        $second = DateTimeImmutable::createFromFormat('!Y-m-d', $b);
        if (!$first || !$second) return PHP_INT_MAX;
        return (int)$first->diff($second)->format('%a');
    }

    private static function clockMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return ((int)($parts[0] ?? 0) * 60) + (int)($parts[1] ?? 0);
    }
}
