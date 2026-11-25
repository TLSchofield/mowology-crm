<?php
declare(strict_types=1);

/**
 * TaxInstalmentNote — what the year's tax is shaping up to be, said honestly (plugin tax-season-organizer patterns that
 * travel across the border, 2026-11-03): annualise YTD ÷ months × 12 ONLY alongside a seasonal caveat (landscaping earns
 * its year in seven months); a missed instalment is named on its own line; the year and every assumption are stated;
 * penalty and interest are never estimated. The RATE itself is never invented here — it comes from the setting Steve
 * confirmed, and the note says so. Pure and static.
 */
final class TaxInstalmentNote
{
    /**
     * @param array $instalments [{due: 'Y-m-d', amount: float, paid_on: ?string}]
     * @param ?float $rate combined small-business rate as a fraction (e.g. 0.11), null = unknown
     */
    public static function build(int $year, float $ytdNet, int $monthsElapsed, bool $seasonal, array $instalments, ?float $rate, string $today): array
    {
        $lines = [];
        $annual = $monthsElapsed > 0 ? $ytdNet / $monthsElapsed * 12 : null;
        $lines[] = "Tax year $year, figures from the books as of $today.";
        $lines[] = 'Net so far: CAD ' . number_format($ytdNet, 0) . " after $monthsElapsed month" . ($monthsElapsed === 1 ? '' : 's') . '.';
        if ($annual !== null) {
            $lines[] = 'Straight-line annualised: CAD ' . number_format($annual, 0) . ($seasonal ? ' — but this is a seasonal business, so treat the straight line as a ceiling, not a forecast; last year\'s shape is the better guide.' : '.');
        }
        if ($rate !== null && $annual !== null) {
            $lines[] = 'At the ' . round($rate * 100, 1) . '% rate on file that would be about CAD ' . number_format($annual * $rate, 0) . ' for the year (rate from Settings, confirmed with the accountant; not recalculated here).';
        } else {
            $lines[] = 'No tax rate on file — the tax figure is not estimated.';
        }
        $missed = []; $paid = 0.0; $due = 0.0;
        foreach ($instalments as $i) {
            $due += (float)$i['amount'];
            if (!empty($i['paid_on'])) { $paid += (float)$i['amount']; continue; }
            if ((string)$i['due'] < $today) $missed[] = $i;
        }
        if ($instalments) $lines[] = 'Instalments: CAD ' . number_format($paid, 0) . ' paid of CAD ' . number_format($due, 0) . ' scheduled.';
        foreach ($missed as $i) $lines[] = 'Missed instalment: CAD ' . number_format((float)$i['amount'], 0) . ' was due ' . $i['due'] . ' — catch it up as its own payment. Interest or penalty, if any, is for the CRA notice to say, not for this note to guess.';
        $lines[] = 'Assumptions: books as entered; no year-end adjustments yet; GST is outside this figure.';
        return ['annualised' => $annual !== null ? round($annual, 2) : null, 'missed' => count($missed), 'text' => implode(' ', $lines)];
    }
}
