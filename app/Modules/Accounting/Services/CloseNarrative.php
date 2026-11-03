<?php
declare(strict_types=1);

/**
 * CloseNarrative — the month in plain words for the owner (and Steve), at most ~250 (a clean month earns fewer; nothing is padded), the plugin's six-part shape
 * (month-end-prep step 7 + variance-analysis explanation rules, 2026-11-03): headline, revenue, margin, expense lines
 * that moved more than 10% month over month (each with the driver when one is known), bottom line, watch list.
 * Banned: "timing" or "one-time" without saying what, "various small items" on a material line. Facts only — Penny
 * writes, Tim approves; the numbers come from the ledger as they are, in CAD. Pure and static.
 *
 * Inputs: $cur / $prior = {income: float, expenses: float, lines: [name => amount]}; $flags = close blockers (strings).
 */
final class CloseNarrative
{
    public const MOVE = 0.10;
    public const MATERIAL = 250.0;   // a line under this does not earn a sentence

    public static function write(string $ym, array $cur, ?array $prior, array $flags = []): string
    {
        $label = date('F Y', strtotime($ym . '-01'));
        $net = (float)$cur['income'] - (float)$cur['expenses'];
        $p = [];
        // 1. headline
        if ($prior) {
            $pnet = (float)$prior['income'] - (float)$prior['expenses'];
            $p[] = "$label came in at CAD " . self::m($net) . ' net' . self::vs($net, $pnet, 'the month before') . '.';
        } else {
            $p[] = "$label came in at CAD " . self::m($net) . ' net (no earlier month to compare).';
        }
        // 2. revenue
        $rev = "Income was CAD " . self::m((float)$cur['income']);
        if ($prior && (float)$prior['income'] > 0) $rev .= self::vs((float)$cur['income'], (float)$prior['income'], 'last month');
        $top = self::top((array)($cur['lines'] ?? []), true);
        if ($top) $rev .= '; the biggest line was ' . $top[0] . ' at CAD ' . self::m($top[1]);
        $p[] = $rev . '.';
        // 3. margin
        $margin = (float)$cur['income'] > 0 ? $net / (float)$cur['income'] : null;
        if ($margin !== null) {
            $txt = 'That leaves ' . round($margin * 100) . '% of income after expenses';
            if ($prior && (float)$prior['income'] > 0) {
                $pm = ((float)$prior['income'] - (float)$prior['expenses']) / (float)$prior['income'];
                $txt .= $margin > $pm + 0.02 ? ', better than last month\'s ' . round($pm * 100) . '%' : ($margin < $pm - 0.02 ? ', down from last month\'s ' . round($pm * 100) . '%' : ', about the same as last month');
            }
            $p[] = $txt . '.';
        }
        // 4. expense lines that moved >10% and are material
        $moves = [];
        foreach ((array)($cur['lines'] ?? []) as $name => $amt) {
            $amt = (float)$amt; if ($amt >= 0) continue;   // expenses are negative in lines
            $pa = (float)(($prior['lines'] ?? [])[$name] ?? 0);
            if (abs($amt) < self::MATERIAL && abs($pa) < self::MATERIAL) continue;
            if ($pa == 0.0) { $moves[] = "$name is new this month at CAD " . self::m(abs($amt)); continue; }
            $chg = (abs($amt) - abs($pa)) / abs($pa);
            if (abs($chg) > self::MOVE) $moves[] = "$name " . ($chg > 0 ? 'rose' : 'fell') . ' ' . abs(round($chg * 100)) . '% to CAD ' . self::m(abs($amt)) . ' (from ' . self::m(abs($pa)) . ')';
        }
        if ($moves) $p[] = 'Expense lines that moved: ' . implode('; ', array_slice($moves, 0, 5)) . (count($moves) > 5 ? '; and ' . (count($moves) - 5) . ' smaller ones' : '') . '. Each needs a reason before the packet goes to the accountant — say what, not "timing".';
        elseif ($prior) $p[] = 'No expense line moved more than 10% from last month.';
        // 5. bottom line
        $p[] = $net >= 0 ? 'Bottom line: a profit of CAD ' . self::m($net) . ' before tax.' : 'Bottom line: a loss of CAD ' . self::m(abs($net)) . ' before tax.';
        // 6. watch list = the open blockers, at most three
        $watch = array_values(array_filter($flags, fn($f) => strpos($f, 'waiting until') !== 0));
        if ($watch) $p[] = 'Still open before this month is exact: ' . implode('; ', array_map(fn($f) => rtrim($f, '.'), array_slice($watch, 0, 3))) . '.';
        else $p[] = 'Nothing is open on this month — it is ready to lock.';
        return implode(' ', $p);
    }

    private static function m(float $v): string { return number_format($v, 0); }
    private static function vs(float $a, float $b, string $than): string
    {
        if ($b == 0.0) return '';
        // A sign flip (profit to loss or back) is not a percentage anyone can read: say both figures instead.
        if (($a < 0) !== ($b < 0)) return ', from ' . ($b < 0 ? 'a loss of CAD ' . self::m(abs($b)) : 'CAD ' . self::m($b)) . " $than";
        if ($a < 0 && $b < 0) return ', ' . (abs($a) > abs($b) ? 'a deeper loss than' : 'a smaller loss than') . ' CAD ' . self::m(abs($b)) . " $than";   // two losses: no percentage either
        $pct = ($a - $b) / abs($b);
        if (abs($pct) < 0.02) return ", level with $than";
        return ', ' . ($pct > 0 ? 'up ' : 'down ') . abs(round($pct * 100)) . "% from $than";
    }
    private static function top(array $lines, bool $income): ?array
    {
        $best = null;
        foreach ($lines as $name => $amt) { $amt = (float)$amt; if ($income ? $amt <= 0 : $amt >= 0) continue; if ($best === null || abs($amt) > $best[1]) $best = [(string)$name, abs($amt)]; }
        return $best;
    }
}
