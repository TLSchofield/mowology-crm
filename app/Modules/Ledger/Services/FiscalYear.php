<?php
declare(strict_types=1);

/**
 * FiscalYear — fiscal years from the business's year end (business_settings.fiscal_year_end, MM-DD,
 * migration 1356; default 12-31), and the Ledger's date cleaning (Accountant's Ledger review, 2026-10-13).
 *
 * A fiscal year is named by the calendar year it ENDS in (CRA's convention): with a June 30 year end,
 * FY2026 runs 2025-07-01 to 2026-06-30. Mowology's year ends December 31, so FY2026 = calendar 2026.
 *
 * Pure apart from setting(). Tested in tests/Unit/Ledger/FiscalYearTest.php.
 */
final class FiscalYear
{
    public const DEFAULT_END = '12-31';

    /** Is this a usable MM-DD year end? Feb 29 is refused (the year end must exist every year). Pure. */
    public static function validEnd(?string $mmdd): bool
    {
        if (!is_string($mmdd) || !preg_match('/^(\d{2})-(\d{2})$/', $mmdd, $m)) return false;
        return checkdate((int)$m[1], (int)$m[2], 2023);
    }

    /** The business's year end (MM-DD), or 12-31 when the setting is missing or unreadable. */
    public static function setting(PDO $db): string
    {
        try {
            $v = $db->query("SELECT fiscal_year_end FROM business_settings ORDER BY id LIMIT 1")->fetchColumn();
            return self::validEnd(is_string($v) ? $v : null) ? (string)$v : self::DEFAULT_END;
        } catch (Throwable $e) {
            return self::DEFAULT_END;   // before migration 1356
        }
    }

    /** [first day, last day] of fiscal year $fy (the calendar year it ends in). Pure. */
    public static function bounds(int $fy, string $end = self::DEFAULT_END): array
    {
        if (!self::validEnd($end)) $end = self::DEFAULT_END;
        $last = sprintf('%04d-%s', $fy, $end);
        $first = (new DateTimeImmutable($last))->modify('-1 year')->modify('+1 day')->format('Y-m-d');
        return [$first, $last];
    }

    /** The fiscal year a date falls in. Pure. */
    public static function of(string $date, string $end = self::DEFAULT_END): int
    {
        if (!self::validEnd($end)) $end = self::DEFAULT_END;
        $y = (int)substr($date, 0, 4);
        return substr($date, 5, 5) > $end ? $y + 1 : $y;
    }

    /** Every month of fiscal year $fy as 'YYYY-MM', first to last. Pure. */
    public static function months(int $fy, string $end = self::DEFAULT_END): array
    {
        [$first, $last] = self::bounds($fy, $end);
        $out = [];
        $d = new DateTimeImmutable(substr($first, 0, 7) . '-01');
        $stop = substr($last, 0, 7);
        while ($d->format('Y-m') <= $stop && count($out) < 13) {
            $out[] = $d->format('Y-m');
            $d = $d->modify('+1 month');
        }
        return $out;
    }

    /** "December 31" for a year end. Pure. */
    public static function endLabel(string $end = self::DEFAULT_END): string
    {
        if (!self::validEnd($end)) $end = self::DEFAULT_END;
        return (new DateTimeImmutable('2023-' . $end))->format('F j');
    }

    /**
     * Is a fiscal year closed in the books — every one of its months locked? (The overview says
     * "filed and locked" only then; a partly locked year says how many months are locked.) Pure.
     * @param array $lockedMonths 'YYYY-MM' => anything (LockExceptionService::lockedPeriods())
     * @return array{locked: int, months: int, closed: bool}
     */
    public static function lockState(int $fy, array $lockedMonths, string $end = self::DEFAULT_END): array
    {
        $months = self::months($fy, $end);
        $n = 0;
        foreach ($months as $ym) if (isset($lockedMonths[$ym])) $n++;
        return ['locked' => $n, 'months' => count($months), 'closed' => $n === count($months) && $n > 0];
    }

    /** A YYYY-MM-DD date that really exists, or $fallback (default today). Pure apart from today. */
    public static function cleanDate($v, ?string $fallback = null): string
    {
        $v = is_string($v) ? trim($v) : '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])
            && (int)$m[1] >= 1990 && (int)$m[1] <= 2100) {
            return $v;
        }
        return $fallback ?? date('Y-m-d');
    }
}
