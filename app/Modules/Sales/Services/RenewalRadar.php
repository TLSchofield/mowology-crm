<?php
declare(strict_types=1);

/**
 * RenewalRadar — a renewal started WINDOW_DAYS out is a process; one noticed two weeks out is a discount
 * (sales plugin, renewal-radar, harvested 2026-11-03). For every active contract with a renewal date inside the window:
 *   risk up     no contact activity in QUIET_DAYS+ days; an open complaint; the signer changed (caller passes flags)
 *   risk down   auto-renew terms; an expansion conversation under way
 *   verdict     on_track / needs_attention / at_risk, with the evidence, and the paperwork timeline worked back from the
 *               end date (talk at −90, terms out at −45, signed by −14)
 * Small contracts (under SMALL_AMOUNT a year) use the shorter window. Pure and static.
 */
final class RenewalRadar
{
    public const WINDOW_DAYS = 120;
    public const SMALL_WINDOW_DAYS = 90;
    public const SMALL_AMOUNT = 2000.0;
    public const QUIET_DAYS = 30;

    /**
     * @param array $contracts rows {id, contract_number, name, renewal_date, annual_amount, auto_renew (bool), last_activity (?Y-m-d),
     *                               open_complaint (bool), signer_changed (bool), expansion (bool)}
     * @return array rows inside the window, soonest first, each with verdict, risk[], days_left, timeline{talk, terms, signed}
     */
    public static function scan(array $contracts, string $today): array
    {
        $t = strtotime($today);
        $out = [];
        foreach ($contracts as $c) {
            $renew = substr((string)($c['renewal_date'] ?? ''), 0, 10);
            if ($renew === '') continue;
            $days = (int)floor((strtotime($renew) - $t) / 86400);
            $window = (float)($c['annual_amount'] ?? 0) < self::SMALL_AMOUNT ? self::SMALL_WINDOW_DAYS : self::WINDOW_DAYS;
            if ($days > $window || $days < -30) continue;
            $up = []; $down = [];
            $last = $c['last_activity'] ?? null;
            $silent = $last ? (int)floor(($t - strtotime(substr((string)$last, 0, 10))) / 86400) : null;
            if ($silent === null) $up[] = 'no contact on record';
            elseif ($silent >= self::QUIET_DAYS) $up[] = 'no contact for ' . $silent . ' days';
            if (!empty($c['open_complaint'])) $up[] = 'an open complaint';
            if (!empty($c['signer_changed'])) $up[] = 'the signer changed';
            if (!empty($c['auto_renew'])) $down[] = 'auto-renews';
            if (!empty($c['expansion'])) $down[] = 'an expansion conversation is under way';
            $score = count($up) - count($down);
            $verdict = $score >= 2 || (!empty($c['open_complaint'])) ? 'at_risk' : ($score === 1 ? 'needs_attention' : 'on_track');
            if ($days < 0) { $verdict = 'at_risk'; $up[] = 'the renewal date has passed'; }
            $out[] = ['id' => (int)($c['id'] ?? 0), 'contract_number' => (string)($c['contract_number'] ?? ''), 'name' => (string)($c['name'] ?? ''),
                      'renewal_date' => $renew, 'days_left' => $days, 'annual_amount' => round((float)($c['annual_amount'] ?? 0), 2),
                      'verdict' => $verdict, 'risk_up' => $up, 'risk_down' => $down,
                      'timeline' => ['talk' => date('Y-m-d', strtotime($renew . ' -90 days')), 'terms' => date('Y-m-d', strtotime($renew . ' -45 days')), 'signed' => date('Y-m-d', strtotime($renew . ' -14 days'))],
                      'text' => ($c['contract_number'] ?? 'Contract') . ' · ' . ($c['name'] ?? '') . ' renews ' . date('M j', strtotime($renew)) . ' (' . $days . ' days): ' . str_replace('_', ' ', $verdict)
                               . ($up ? ' — ' . implode(', ', $up) : '') . ($down ? ' (' . implode(', ', $down) . ')' : '')];
        }
        usort($out, static fn($a, $b) => $a['days_left'] <=> $b['days_left']);
        return $out;
    }
}
