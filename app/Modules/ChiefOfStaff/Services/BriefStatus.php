<?php
declare(strict_types=1);

/**
 * BriefStatus — ONE status for the whole brief: the worst head, with the line that decided it
 * (harvested from Anthropic's small-business plugin, business-pulse "rollup = worst section; forced red", 2026-11-03).
 *
 *   red      a deadline is overdue or due within RED_DEADLINE_DAYS, or a priority-1 line with an amount of at least
 *            MONEY_RED that is overdue, failed or past due (a specific amount with a date that has passed or is near)
 *   amber    any other priority-1 line
 *   green    answered, nothing urgent
 *   unknown  the head could not be reached: never green (absent is not zero), and never worse than amber for the rollup
 *   quiet    answered with no lines at all: said as "nothing to report", not as failed
 * Overall = the worst head; the deciding line is quoted. Thresholds sit in ops_settings charlie_status_money_red /
 * charlie_status_deadline_days and are on their DEFAULTS until TUNE_DAYS after first use (the plugin: "revisit after 4 weeks").
 * Pure and static.
 */
final class BriefStatus
{
    public const MONEY_RED = 500.0;
    public const RED_DEADLINE_DAYS = 7;
    public const TUNE_DAYS = 28;
    private const ORDER = ['green' => 0, 'quiet' => 0, 'unknown' => 1, 'amber' => 2, 'red' => 3];
    private const BAD_WORDS = '/\b(?:overdue|past due|failed|declined|bounced|unpaid|outstanding|owed|owes|behind|short)\b/i';

    /** Days until a deadline item's due date (negative = overdue), from its key 'charlie:deadline:<category>:YYYY-MM-DD'; null when it is not one. */
    public static function deadlineDays(array $item, string $today): ?int
    {
        if (!preg_match('/^charlie:deadline:[a-z_]+:(\d{4}-\d{2}-\d{2})/', (string)($item['key'] ?? ''), $m)) return null;
        return (int)floor((strtotime($m[1]) - strtotime($today)) / 86400);
    }

    /** Why this line forces red, or null. */
    public static function forcedRed(array $item, string $today, float $moneyRed = self::MONEY_RED, int $deadlineDays = self::RED_DEADLINE_DAYS): ?string
    {
        $d = self::deadlineDays($item, $today);
        if ($d !== null) {
            if ($d < 0 || preg_match('/overdue|was due (?:yesterday|today)/i', (string)($item['text'] ?? ''))) return 'deadline overdue';
            if ($d <= $deadlineDays) return "deadline in {$d} day" . ($d === 1 ? '' : 's');
            return null;
        }
        if ((int)($item['priority'] ?? 2) === 1 && (float)($item['value'] ?? 0) >= $moneyRed && preg_match(self::BAD_WORDS, (string)($item['text'] ?? ''))) {
            return 'an amount of $' . number_format((float)$item['value'], 0) . ' past due';
        }
        return null;
    }

    /**
     * @param array<string, array> $byHead head => lines (normalized, original priorities)
     * @param array<string, string> $failed head => error
     * @param array<string, string> $names head => shown name
     * @return array{overall: string, line: ?string, why: ?string, heads: array<string, array>, defaults: bool, note: ?string}
     */
    public static function assess(array $byHead, array $failed, array $names, string $today, ?string $since = null,
                                  float $moneyRed = self::MONEY_RED, int $deadlineDays = self::RED_DEADLINE_DAYS): array
    {
        $heads = [];
        $worst = 'green';
        $decider = null;
        foreach ($byHead as $head => $items) {
            $st = 'green'; $why = null; $line = null; $p1 = 0;
            if (!$items) { $heads[$head] = ['status' => 'quiet', 'why' => 'nothing to report', 'p1' => 0, 'n' => 0]; continue; }
            foreach ($items as $it) {
                if ((int)($it['priority'] ?? 2) === 1) $p1++;
                $r = self::forcedRed($it, $today, $moneyRed, $deadlineDays);
                if ($r !== null && ($st !== 'red' || self::older($it, $line))) { $st = 'red'; $why = $r; $line = $it; }
            }
            if ($st !== 'red' && $p1 > 0) { $st = 'amber'; $why = $p1 . ' urgent line' . ($p1 === 1 ? '' : 's'); }
            $heads[$head] = ['status' => $st, 'why' => $why, 'p1' => $p1, 'n' => count($items), 'line' => $line['text'] ?? null];
            if (self::ORDER[$st] > self::ORDER[$worst] || ($st === 'red' && $worst === 'red' && self::older($line, $decider))) {
                $worst = $st;
                if ($st === 'red') $decider = $line;
            }
        }
        foreach ($failed as $head => $err) {
            $heads[$head] = ['status' => 'unknown', 'why' => 'could not be reached', 'p1' => 0, 'n' => 0, 'name' => $names[$head] ?? ucfirst((string)$head)];
            if (self::ORDER['unknown'] > self::ORDER[$worst]) $worst = 'unknown';
        }
        $defaults = self::onDefaults($since, $today);
        return [
            'overall'  => $worst,
            'line'     => $worst === 'red' && $decider ? (string)$decider['text'] : null,
            'why'      => $worst === 'red' && $decider ? self::forcedRed($decider, $today, $moneyRed, $deadlineDays) : null,
            'heads'    => $heads,
            'defaults' => $defaults,
            'note'     => $defaults ? 'The colours use the starting thresholds; review them after ' . date('M j', strtotime(($since ?? $today) . ' +' . self::TUNE_DAYS . ' days')) . '.' : null,
        ];
    }

    /** The older of two lines (a deadline longer overdue, or an older amount) decides a tie between reds. */
    private static function older(?array $a, ?array $b): bool
    {
        if ($b === null) return true;
        if ($a === null) return false;
        return ((string)($a['since'] ?? '9999') < (string)($b['since'] ?? '9999'));
    }

    public static function onDefaults(?string $since, string $today): bool
    {
        if ($since === null || $since === '') return true;
        return strtotime($today) < strtotime($since . ' +' . self::TUNE_DAYS . ' days');
    }

    /** One sentence: "RED — Payroll remittance to CRA … overdue since Oct 15." */
    public static function sentence(array $s): string
    {
        $w = ['red' => 'RED', 'amber' => 'AMBER', 'green' => 'GREEN', 'unknown' => 'AMBER'][$s['overall']] ?? 'AMBER';
        if ($s['overall'] === 'red') return "{$w} — " . rtrim((string)$s['line'], '.') . '.';
        if ($s['overall'] === 'unknown') return "{$w} — a head could not be reached, so part of this is unchecked.";
        if ($s['overall'] === 'amber') return "{$w} — nothing is overdue; some lines are urgent.";
        return "{$w} — nothing is overdue and nothing is urgent.";
    }
}
