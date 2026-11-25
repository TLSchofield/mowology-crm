<?php
declare(strict_types=1);

/**
 * SamSignals — Sam's digest fires only on a threshold crossing, and an empty digest is the success case
 * (sales plugin, deal-signals, harvested 2026-11-03). Two blocks: act today, this week; everything else is one line
 * ("nothing else crossed a threshold, N swept"). Thresholds flex to our cycle: a quote's "quiet" is its learned wait
 * (SalesDeskService already decides that), so here only the crossings that the queue does not say out loud:
 *   inbound waiting   a client wrote about a quote and nobody answered in INBOUND_DAYS business days → today
 *   expiring          a sent quote whose valid-until is within EXPIRY_DAYS with no reply → this week
 *   hot lead          a hot-bucket lead not yet called → today; qualified lead older than LEAD_WEEK_DAYS → this week
 *   quiet client      a client gone quiet against their own rhythm → this week; gone → today when worth ≥ GONE_WORTH
 * Pure and static.
 */
final class SamSignals
{
    public const INBOUND_DAYS = 3;
    public const EXPIRY_DAYS = 7;
    public const LEAD_WEEK_DAYS = 7;
    public const GONE_WORTH = 1000.0;

    /**
     * @param array $queue     SalesDeskService::queue() cards {kind, name, amount, days, label, last_in?}
     * @param array $unclaimed replies nobody answered {name, days?, since?}
     * @param array $leads     rankLeads rows {name, bucket, age_days, next}
     * @param array $quiet     ClientRhythm::find rows {name, state, spend_12m, reason}
     * @param array $expiring  open quotes {quote_number, name, amount, days_left}
     * @return array{today: string[], week: string[], swept: int, line: string}
     */
    public static function digest(array $queue, array $unclaimed, array $leads, array $quiet, array $expiring): array
    {
        $today = []; $week = [];
        foreach ($queue as $c) if (($c['kind'] ?? '') === 'replied') $today[] = ($c['name'] ?? 'A client') . ' wrote back about ' . ($c['label'] ?? 'a quote') . ' and is waiting on you';
        foreach ($unclaimed as $u) if ((int)($u['days'] ?? 0) >= self::INBOUND_DAYS) $today[] = ($u['name'] ?? 'Someone') . ' has waited ' . (int)$u['days'] . ' days for a reply';
        foreach ($leads as $l) {
            if (($l['bucket'] ?? '') === 'hot') $today[] = 'Hot lead ' . ($l['name'] ?? '') . ' — ' . ($l['next'] ?? 'call today');
            elseif (($l['bucket'] ?? '') === 'qualified' && (int)($l['age_days'] ?? 0) >= self::LEAD_WEEK_DAYS) $week[] = 'Lead ' . ($l['name'] ?? '') . ' is ' . (int)$l['age_days'] . ' days old with no quote';
        }
        foreach ($quiet as $q) {
            $line = ($q['name'] ?? 'A client') . (($q['state'] ?? 'quiet') === 'gone' ? ' looks lost' : ' has gone quiet') . ' (' . ($q['reason'] ?? '') . ')';
            if (($q['state'] ?? '') === 'gone' && (float)($q['spend_12m'] ?? 0) >= self::GONE_WORTH) $today[] = $line; else $week[] = $line;
        }
        foreach ($expiring as $e) if ((int)($e['days_left'] ?? 99) <= self::EXPIRY_DAYS) $week[] = ($e['quote_number'] ?? 'A quote') . ' for ' . ($e['name'] ?? 'a client') . ' ($' . number_format((float)($e['amount'] ?? 0), 0) . ') expires in ' . (int)$e['days_left'] . ' days with no reply';
        $swept = count($queue) + count($unclaimed) + count($leads) + count($quiet) + count($expiring);
        $line = !$today && !$week ? 'Nothing crossed a threshold today — ' . $swept . ' things swept, all inside their normal wait.' : 'Nothing else crossed a threshold; ' . $swept . ' swept.';
        return ['today' => $today, 'week' => $week, 'swept' => $swept, 'line' => $line];
    }
}
