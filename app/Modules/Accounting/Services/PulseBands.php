<?php
declare(strict_types=1);

/**
 * PulseBands — green / yellow / red for the owner's money numbers, the plugin's business-pulse cut-offs (2026-11-03).
 *   runway   months of cash at the recent burn: ≥6 green, 3–6 yellow, <3 red (no burn → no verdict)
 *   revenue  month over month: ≥0% green, −5…0% yellow, below −5% red
 *   ar       nothing past 31 days green; any 31–60 yellow; any 60+ OR the 61+ bucket over 10% of A/R red — and every
 *            invoice over 30 days is NAMED, never just counted
 *   overall  the worst section; forced red when a named client, an amount and a deadline fall within 7 days
 * Absent is not zero: a missing figure returns 'na', never green. Pure and static.
 */
final class PulseBands
{
    public static function runway(?float $cash, ?float $monthlyBurn): array
    {
        if ($cash === null || $monthlyBurn === null || $monthlyBurn <= 0) return ['band' => 'na', 'months' => null, 'text' => 'runway: n/a — no burn figure'];
        $m = $cash / $monthlyBurn;
        $band = $m >= 6 ? 'green' : ($m >= 3 ? 'yellow' : 'red');
        return ['band' => $band, 'months' => round($m, 1), 'text' => 'runway ' . round($m, 1) . ' months at $' . number_format($monthlyBurn, 0) . ' a month'];
    }

    public static function revenue(?float $current, ?float $prior): array
    {
        if ($current === null || $prior === null || $prior <= 0) return ['band' => 'na', 'pct' => null, 'text' => 'revenue trend: n/a — no prior month'];
        $pct = ($current - $prior) / $prior;
        $band = $pct >= 0 ? 'green' : ($pct >= -0.05 ? 'yellow' : 'red');
        return ['band' => $band, 'pct' => round($pct * 100, 1), 'text' => 'revenue ' . ($pct >= 0 ? '+' : '') . round($pct * 100) . '% month over month'];
    }

    /** @param array $invoices open rows {number, who, balance, days_past_due} */
    public static function ar(array $invoices): array
    {
        $total = 0.0; $over60 = 0.0; $max = 0; $named = [];
        foreach ($invoices as $i) {
            $bal = (float)($i['balance'] ?? 0); $d = (int)($i['days_past_due'] ?? 0);
            $total += $bal; $max = max($max, $d);
            if ($d > 60) $over60 += $bal;
            if ($d > 30) $named[] = ($i['number'] ?? '') . ' · ' . ($i['who'] ?? 'client') . ' — $' . number_format($bal, 0) . ' (' . $d . ' days)';
        }
        if (!$invoices) return ['band' => 'na', 'named' => [], 'text' => 'A/R: n/a — no open invoices read'];
        $band = ($max > 60 || ($total > 0 && $over60 / $total > 0.10)) ? 'red' : ($max > 30 ? 'yellow' : 'green');
        return ['band' => $band, 'named' => $named, 'over60_share' => $total > 0 ? round($over60 / $total, 3) : 0.0,
                'text' => $band === 'green' ? 'A/R: nothing past 31 days' : ('A/R: ' . count($named) . ' invoice' . (count($named) === 1 ? '' : 's') . ' past 30 days' . ($over60 > 0 ? ', $' . number_format($over60, 0) . ' past 60' : ''))];
    }

    /** @param array $sections band strings; $namedRisk a client + amount + deadline within 7 days forces red */
    public static function overall(array $sections, bool $namedRisk = false): string
    {
        if ($namedRisk) return 'red';
        $rank = ['red' => 3, 'yellow' => 2, 'green' => 1, 'na' => 0];
        $worst = 'na';
        foreach ($sections as $b) if (($rank[$b] ?? 0) > ($rank[$worst] ?? 0)) $worst = $b;
        return $worst;
    }
}
