<?php
declare(strict_types=1);

/**
 * AdDiagnostics — the plugin's ad-manager bands and change playbook (2026-11-03), ready for the day an ads account is read.
 * Bands are relative to the account's own trailing 90 days: Working = cost per result within 15% of average or better and
 * volume steady or rising; Watch = 15–40% worse, or frequency above 3, or volume falling two weeks running; Problem = more
 * than 40% worse, or spend with zero results for 14+ days; No verdict = no conversion tracking or below the evidence minimums.
 * Changes: raise budget at most 20–30% at a time and only with cost stable, frequency under 3 and 30+ days of history; cut
 * when cost is 40%+ worse or frequency above 4 with CTR falling; most weeks the right answer is no change. Every proposed
 * change is an approval block in absolute monthly dollars, never a percentage alone. Pure and static.
 */
final class AdDiagnostics
{
    /** @param array $c {name, days, results, spend, cost_per_result, trailing_cost_per_result, frequency, volume_trend ('up'|'flat'|'down2'), days_without_results, tracking (bool)} */
    public static function band(array $c): array
    {
        if (empty($c['tracking'])) return ['band' => 'no_verdict', 'why' => 'no conversion tracking — spend is known, results are not'];
        $min = EvidenceMinimums::pause((int)($c['days'] ?? 0), (int)($c['results'] ?? 0));
        if (!$min['ok'] && (int)($c['days'] ?? 0) < 7) return ['band' => 'no_verdict', 'why' => $min['text']];
        $cpr = (float)($c['cost_per_result'] ?? 0); $base = (float)($c['trailing_cost_per_result'] ?? 0);
        $worse = $base > 0 ? ($cpr - $base) / $base : 0.0;
        if ((int)($c['days_without_results'] ?? 0) >= 14 && (float)($c['spend'] ?? 0) > 0) return ['band' => 'problem', 'why' => 'spending with no results for ' . (int)$c['days_without_results'] . ' days'];
        if ($worse > 0.40) return ['band' => 'problem', 'why' => 'cost per result ' . round($worse * 100) . '% worse than the trailing 90 days'];
        if ($worse > 0.15) return ['band' => 'watch', 'why' => 'cost per result ' . round($worse * 100) . '% worse than usual'];
        if ((float)($c['frequency'] ?? 0) > 3) return ['band' => 'watch', 'why' => 'frequency ' . $c['frequency'] . ' — the audience is seeing it too often'];
        if (($c['volume_trend'] ?? '') === 'down2') return ['band' => 'watch', 'why' => 'volume falling two weeks running'];
        return ['band' => 'working', 'why' => 'cost per result within 15% of usual' . ($worse < -0.05 ? ' (better)' : '')];
    }

    /** One change, one block, one yes. Dollars first; a percentage only beside them. */
    public static function approvalBlock(string $change, float $monthlyNow, float $monthlyAfter, bool $reversible, string $expectedLoss = ''): array
    {
        $net = $monthlyAfter - $monthlyNow;
        $pct = $monthlyNow > 0 ? round($net / $monthlyNow * 100) : null;
        return ['change' => $change, 'costs_today' => '$' . number_format($monthlyNow, 0) . ' a month', 'after' => '$' . number_format($monthlyAfter, 0) . ' a month',
                'net' => ($net >= 0 ? '+$' : '−$') . number_format(abs($net), 0) . ' a month' . ($pct !== null ? ' (' . ($pct >= 0 ? '+' : '') . $pct . '%)' : '') . ($expectedLoss !== '' ? '; expected loss: ' . $expectedLoss : ''),
                'reversible' => $reversible ? 'yes' : 'no — a restarted campaign re-enters learning', 'proceed' => 'Proceed? A yes expires; one change per yes.'];
    }

    /** Raise? Only with stable cost, frequency < 3 and 30+ days; cap +30%. Cut when 40%+ worse or frequency > 4 with CTR falling. */
    public static function budgetAdvice(array $c): string
    {
        $band = self::band($c)['band'];
        if ($band === 'no_verdict') return 'No change — no verdict yet.';
        if ($band === 'problem') return 'Cut or pause — but never pause the only campaign producing results without saying so.';
        if ((float)($c['frequency'] ?? 0) > 4 && ($c['ctr_trend'] ?? '') === 'down') return 'Fatigue: new creative, not more money.';
        if ($band === 'working' && (int)($c['days'] ?? 0) >= 30 && (float)($c['frequency'] ?? 0) < 3) return 'It can take more: raise by at most 20–30%, then wait a full cycle.';
        return 'No change — most weeks that is the right answer.';
    }
}
