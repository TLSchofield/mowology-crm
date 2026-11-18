<?php
declare(strict_types=1);

/**
 * EvidenceMinimums — how much data before Mia is allowed an opinion (ad-manager diagnostics + performance-report, 2026-11-03).
 *   new campaign      judge after 7 days, not before
 *   pause anything    30 days or 50 results, whichever first
 *   creative winner   1,000 impressions and 15 results per variant
 *   a trend is real   3 consecutive weeks in one direction; a line needs 8–12 points (under 12 = low confidence)
 *   a test            one variable, one full business cycle
 * "No verdict" is a real status, said out loud. Pure and static.
 */
final class EvidenceMinimums
{
    public static function newCampaign(int $days): array { return $days >= 7 ? ['ok' => true, 'text' => "$days days in: enough to judge"] : ['ok' => false, 'text' => "Only $days day" . ($days === 1 ? '' : 's') . " in — judge a new campaign after 7"]; }
    public static function pause(int $days, int $results): array { return ($days >= 30 || $results >= 50) ? ['ok' => true, 'text' => 'Enough history to pause if it is not working'] : ['ok' => false, 'text' => "No verdict yet: $days days and $results results (pause only after 30 days or 50 results)"]; }
    public static function winner(int $impressions, int $results): array { return ($impressions >= 1000 && $results >= 15) ? ['ok' => true, 'text' => 'Enough to call a winner'] : ['ok' => false, 'text' => "No winner yet: $impressions impressions and $results results (needs 1,000 and 15 per variant)"]; }
    /** @param float[] $series oldest first */
    public static function trend(array $series): array
    {
        $n = count($series);
        if ($n < 3) return ['direction' => 'none', 'real' => false, 'confidence' => 'none', 'text' => "Only $n point" . ($n === 1 ? '' : 's') . ' — no trend can be called'];
        $last = array_slice($series, -3); $up = $last[0] < $last[1] && $last[1] < $last[2]; $down = $last[0] > $last[1] && $last[1] > $last[2];
        $dir = $up ? 'up' : ($down ? 'down' : 'flat');
        $conf = $n >= 12 ? 'normal' : ($n >= 8 ? 'thin' : 'low');
        return ['direction' => $dir, 'real' => $up || $down, 'confidence' => $conf, 'text' => ($up || $down ? "Moving $dir three weeks running" : 'No three-week run either way') . ($n < 12 ? " ($n points — low confidence under 12)" : '')];
    }
}
