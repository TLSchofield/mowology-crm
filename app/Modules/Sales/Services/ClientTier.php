<?php
declare(strict_types=1);

/**
 * ClientTier — fit × engagement, each 0–10, cutoff stated, a motion per tier (sales plugin, account-tiering, 2026-11-03),
 * with the enterprise rows swapped for what a trade business knows:
 *   fit (0–10)         recurring work 3; on a route we already run 3 (same city as 3+ other active clients); pays on time 2
 *                      (no firm tone); property class we price well 2 (a service with an accepted-quote average)
 *   engagement (0–10)  open quote or active plan 3; last activity <30 d 3 / 30–90 d 2 / 90–180 d 1 / older 0;
 *                      inbound message in 90 d 2; more than one contact on record 2
 *   cutoff             CUTOFF (6) and above is "high"; said in the output
 *   motions            A high/high: progress the open work · B high fit, low engagement: reactivate with a trigger ·
 *                      C low fit, high engagement: qualify hard before more time · D low/low: revisit quarterly
 * Pure and static.
 */
final class ClientTier
{
    public const CUTOFF = 6;

    public static function score(array $c, string $today): array
    {
        $fit = (!empty($c['recurring']) ? 3 : 0) + (!empty($c['on_route']) ? 3 : 0) + (empty($c['pays_late']) ? 2 : 0) + (!empty($c['priced_service']) ? 2 : 0);
        $days = isset($c['last_activity']) && $c['last_activity'] ? (int)floor((strtotime($today) - strtotime(substr((string)$c['last_activity'], 0, 10))) / 86400) : null;
        $recency = $days === null ? 0 : ($days < 30 ? 3 : ($days < 90 ? 2 : ($days < 180 ? 1 : 0)));
        $eng = (!empty($c['open_work']) ? 3 : 0) + $recency + (!empty($c['inbound_90d']) ? 2 : 0) + ((int)($c['contacts'] ?? 1) > 1 ? 2 : 0);
        $hiF = $fit >= self::CUTOFF; $hiE = $eng >= self::CUTOFF;
        $tier = $hiF && $hiE ? 'A' : ($hiF ? 'B' : ($hiE ? 'C' : 'D'));
        $motion = ['A' => 'progress the open work', 'B' => 'reactivate with a trigger — a season, a service they have not had, a neighbour we did', 'C' => 'qualify hard before more time goes in', 'D' => 'revisit quarterly'][$tier];
        return ['fit' => $fit, 'engagement' => $eng, 'tier' => $tier, 'motion' => $motion, 'cutoff' => self::CUTOFF, 'gap' => $tier !== 'D' && $tier !== 'C' && $days !== null && $days >= 30];
    }
}
