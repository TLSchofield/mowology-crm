<?php
declare(strict_types=1);

/**
 * MarketingWeek — the shape of Mia's weekly page (marketing-monday + growth-pulse, 2026-11-03): one merged page, every
 * number beside its comparison, exactly three actions ranked by impact (each: what to do, the number that justifies it,
 * what it is worth), a quiet week reported as quiet in three lines, failed sources named. Growth bands: revenue vs prior
 * period yellow 0 to −10% / red below; lead volume yellow 0 to −20% / red below; channel concentration yellow 60–80% / red
 * above 80%; review score green ≥4.3 / red <3.8, trend red if down more than 0.3. Pure and static.
 */
final class MarketingWeek
{
    public static function band(string $metric, ?float $now, ?float $prior): array
    {
        if ($now === null) return ['band' => 'na', 'pct' => null];
        if ($metric === 'concentration') return ['band' => $now > 0.80 ? 'red' : ($now >= 0.60 ? 'yellow' : 'green'), 'pct' => round($now * 100)];
        if ($metric === 'review_score') return ['band' => $now >= 4.3 ? 'green' : ($now < 3.8 ? 'red' : 'yellow'), 'pct' => null, 'trend_red' => $prior !== null && ($prior - $now) > 0.3];
        if ($prior === null || $prior <= 0) return ['band' => 'na', 'pct' => null];
        $chg = ($now - $prior) / $prior;
        $redAt = $metric === 'leads' ? -0.20 : -0.10;
        return ['band' => $chg >= 0 ? 'green' : ($chg >= $redAt ? 'yellow' : 'red'), 'pct' => round($chg * 100)];
    }

    /**
     * @param array $actions candidates {text, number, worth (float)} — ranked by worth, cut to three
     * @param array $failedSources names
     * @return array{headline:string, actions:array, quiet:bool, sources_line:string}
     */
    public static function page(array $bands, array $actions, array $failedSources, string $weekLabel): array
    {
        usort($actions, static fn($a, $b) => (float)($b['worth'] ?? 0) <=> (float)($a['worth'] ?? 0));
        $actions = array_slice($actions, 0, 3);
        $reds = array_keys(array_filter($bands, fn($b) => ($b['band'] ?? '') === 'red'));
        $yellows = array_keys(array_filter($bands, fn($b) => ($b['band'] ?? '') === 'yellow'));
        $quiet = !$reds && !$yellows && !$actions;
        $headline = $quiet ? "A quiet week ($weekLabel): nothing moved outside its normal range and nothing needs doing."
            : "$weekLabel: " . ($reds ? count($reds) . ' thing' . (count($reds) === 1 ? '' : 's') . ' red (' . implode(', ', $reds) . ')' : 'nothing red') . ($yellows ? ', ' . count($yellows) . ' to watch' : '') . ($actions ? ' — ' . count($actions) . ' action' . (count($actions) === 1 ? '' : 's') . ' worth taking.' : '.');
        $sources = $failedSources ? 'Sources not read this week: ' . implode(', ', $failedSources) . ' — n/a, not clean.' : 'Every source read.';
        return ['headline' => $headline, 'actions' => array_map(fn($a) => ['text' => (string)$a['text'], 'because' => (string)($a['number'] ?? ''), 'worth' => '$' . number_format((float)($a['worth'] ?? 0), 0)], $actions), 'quiet' => $quiet, 'sources_line' => $sources];
    }
}
