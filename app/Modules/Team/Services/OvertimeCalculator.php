<?php
declare(strict_types=1);

/**
 * BC Overtime Pay Calculator
 *
 * BC Employment Standards Act overtime rules:
 *   - Daily:  first 8h regular | 8–12h at 1.5× | >12h at 2×
 *   - Weekly: cumulative hours not already paid at a daily OT rate,
 *             up to 40h total regular; excess at 1.5×
 */
class OvertimeCalculator
{
    private const DAILY_REG_MAX    = 480;  // 8 h in minutes
    private const DAILY_OT15_MAX   = 720;  // 12 h in minutes
    private const WEEKLY_REG_MAX   = 2400; // 40 h in minutes

    /**
     * Calculate pay breakdown for a week.
     *
     * @param array<string,int> $dailyMinutes  Keyed by 'Y-m-d', values are total shift minutes for that day.
     * @param float             $hourlyRate    Employee's hourly rate in dollars.
     *
     * @return array{
     *   regular_minutes:  int,
     *   ot_1_5_minutes:   int,
     *   ot_2_0_minutes:   int,
     *   gross_pay:        float,
     *   regular_pay:      float,
     *   ot_1_5_pay:       float,
     *   ot_2_0_pay:       float,
     *   has_overtime:     bool,
     *   daily_breakdown:  array
     * }
     */
    public static function calculate(array $dailyMinutes, float $hourlyRate): array
    {
        $weekRegMin  = 0;  // running total of minutes paid at regular rate
        $weekOt15Min = 0;  // running total of minutes paid at 1.5×
        $weekOt20Min = 0;  // running total of minutes paid at 2×

        $dailyBreakdown = [];

        foreach ($dailyMinutes as $date => $totalMin) {
            $totalMin = max(0, (int)$totalMin);
            $dayReg  = 0;
            $dayOt15 = 0;
            $dayOt20 = 0;

            if ($totalMin > self::DAILY_OT15_MAX) {
                // >12 h: split into reg / 1.5× / 2×
                $dayReg  = self::DAILY_REG_MAX;
                $dayOt15 = self::DAILY_OT15_MAX - self::DAILY_REG_MAX;
                $dayOt20 = $totalMin - self::DAILY_OT15_MAX;
            } elseif ($totalMin > self::DAILY_REG_MAX) {
                // 8–12 h: split into reg / 1.5×
                $dayReg  = self::DAILY_REG_MAX;
                $dayOt15 = $totalMin - self::DAILY_REG_MAX;
            } else {
                // ≤8 h: all regular
                $dayReg = $totalMin;
            }

            $weekRegMin  += $dayReg;
            $weekOt15Min += $dayOt15;
            $weekOt20Min += $dayOt20;

            $dailyBreakdown[$date] = [
                'total_minutes'   => $totalMin,
                'regular_minutes' => $dayReg,
                'ot_1_5_minutes'  => $dayOt15,
                'ot_2_0_minutes'  => $dayOt20,
                'has_daily_ot'    => ($dayOt15 > 0 || $dayOt20 > 0),
            ];
        }

        // Weekly overtime: regular minutes beyond 40 h (2400 min) shift to 1.5×
        // Only the "regular" bucket is subject to weekly OT; daily OT is already priced above.
        if ($weekRegMin > self::WEEKLY_REG_MAX) {
            $weeklyOtMin  = $weekRegMin - self::WEEKLY_REG_MAX;
            $weekOt15Min += $weeklyOtMin;
            $weekRegMin   = self::WEEKLY_REG_MAX;
        }

        $ratePerMin     = $hourlyRate / 60.0;
        $regularPay     = round($weekRegMin  * $ratePerMin, 2);
        $ot15Pay        = round($weekOt15Min * $ratePerMin * 1.5, 2);
        $ot20Pay        = round($weekOt20Min * $ratePerMin * 2.0, 2);
        $grossPay       = round($regularPay + $ot15Pay + $ot20Pay, 2);

        return [
            'regular_minutes' => $weekRegMin,
            'ot_1_5_minutes'  => $weekOt15Min,
            'ot_2_0_minutes'  => $weekOt20Min,
            'gross_pay'       => $grossPay,
            'regular_pay'     => $regularPay,
            'ot_1_5_pay'      => $ot15Pay,
            'ot_2_0_pay'      => $ot20Pay,
            'has_overtime'    => ($weekOt15Min > 0 || $weekOt20Min > 0),
            'daily_breakdown' => $dailyBreakdown,
        ];
    }

    /**
     * Format minutes as decimal hours string, e.g. "8.50h".
     */
    public static function minutesToHours(int $minutes): string
    {
        if ($minutes <= 0) return '0h';
        $h = floor($minutes / 60);
        $m = $minutes % 60;
        if ($m === 0) return "{$h}h";
        $dec = round($m / 60, 2);
        return number_format($h + $dec, 2) . 'h';
    }

    /**
     * Format a dollar amount as CAD currency string.
     */
    public static function formatPay(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}
