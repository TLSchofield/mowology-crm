<?php
declare(strict_types=1);

/**
 * LeadRouteDay — the best working day for the crew to look at (or first do) a new lead's property, from where
 * the crews already are (Tim, 2026-11-03: "work out where they would be on a route — best day to visit for a
 * quote/service"). Pure and static: OttoLeadService gathers the days, this class reasons about them.
 *
 *   cost     = the extra truck minutes the stop adds on that day's route (DriveMinutesAdded::added: cheapest slot
 *              between neighbouring stops, depot-aware; straight line × 1.4 at 40 km/h, the Closer's own numbers);
 *   blocked  = Sunday or a rest day, or the day cannot take the visit (planned load + the visit > 100% of crew capacity);
 *   noted    = a day at 80%+ ("no room for rain"), a day whose plan lengths look wrong (load unknown), a wet day;
 *   rank     = dry before wet, then fewest extra minutes, then the earlier day.
 * No route that day falls back to a round trip from the yard, labelled as such; no yard and no route is "n/a".
 */
require_once dirname(__DIR__, 2) . '/Sales/Services/DriveMinutesAdded.php';
require_once __DIR__ . '/WeekLoadRules.php';

final class LeadRouteDay
{
    public const HORIZON_DAYS = 14;        // working days are looked for within this many calendar days
    public const SHOWN = 3;                // best + two alternatives
    public const QUOTE_VISIT_MIN = 30;     // a look at the lot: measure, talk, leave

    /**
     * @param array $routeDays  [{date, crew_id, stops: [{lat, lng, property_id}]}] — CloserService::routeDays()
     * @param array $loads      date => {minutes, visits, untimed, biggest} — OttoHarvestService::dayLoads()
     * @param array $flags      date => {rest: bool, wet: bool}
     * @return array{best: ?array, alternatives: array, blocked: array<string,string>, none: ?string}
     */
    public static function rank(float $lat, float $lng, array $routeDays, array $loads, array $flags, ?array $depot,
                                int $crew, string $today, int $needMin = self::QUOTE_VISIT_MIN): array
    {
        $out = ['best' => null, 'alternatives' => [], 'blocked' => [], 'none' => null];
        if ($lat == 0.0 || $lng == 0.0) { $out['none'] = 'no map pin yet'; return $out; }
        $cap = WeekLoadRules::capacityMinutes($crew);
        if ($cap <= 0) { $out['none'] = 'no crew count set (ops_settings otto_crew_count) — no day can be judged'; return $out; }
        // A plan whose length is a full day or more for one person is a wrong length (plan #99 was 8,009 min): set it aside
        // and say so, rather than letting it make every day look unknowable or booked solid.
        $ignored = [];
        foreach ($loads as $date => $l) {
            $big = $l['biggest'] ?? null;
            if ($big && (int)$big['minutes'] >= WeekLoadRules::HOURS_PER_DAY * 60) {
                $loads[$date]['minutes'] = max(0, (int)$l['minutes'] - (int)$big['minutes'] * max(1, (int)($big['crew'] ?? 1)));
                $loads[$date]['biggest'] = null;
                $ignored[$date] = (int)$big['plan_id'];
            }
        }
        $byDate = [];
        foreach ($routeDays as $d) $byDate[(string)$d['date']][] = $d;
        $states = WeekLoadRules::days($loads, $crew);
        $stateOf = [];
        foreach ($states as $s) $stateOf[$s['date']] = $s;

        $cands = [];
        for ($i = 1; $i <= self::HORIZON_DAYS; $i++) {
            $date = date('Y-m-d', strtotime($today . " +{$i} days"));
            $label = date('D M j', strtotime($date));
            $f = $flags[$date] ?? [];
            if ((int)date('N', strtotime($date)) === 7 || !empty($f['rest'])) { $out['blocked'][$date] = 'Sunday or a rest day'; continue; }
            $load = (int)($loads[$date]['minutes'] ?? 0);
            $st = $stateOf[$date] ?? null;
            $suspect = $st && $st['state'] === 'suspect';
            if (!$suspect && ($load + $needMin) > $cap) { $out['blocked'][$date] = 'booked solid'; continue; }
            $added = DriveMinutesAdded::added($lat, $lng, $byDate[$date] ?? [], $depot);
            if ($added === null) { $out['blocked'][$date] = 'no route that day and no yard set'; continue; }
            $notes = [];
            if (!$suspect && ($load + $needMin) / $cap >= WeekLoadRules::FULL) $notes[] = 'a full day, no room for rain';
            if ($suspect) $notes[] = 'its load is unknown (a plan length looks wrong)';
            if (isset($ignored[$date])) $notes[] = "plan #{$ignored[$date]}'s length looks wrong and is left out";
            if (!empty($loads[$date]['untimed'])) $notes[] = (int)$loads[$date]['untimed'] . ' visit(s) with no length';
            if (!empty($f['wet'])) $notes[] = 'the weather guard flags it';
            $route = $added['basis'] === 'route';
            $near = $added['nearest_km'];
            $text = $label . ': ' . ($route
                ? ((int)$added['neighbours'] > 0 ? (int)$added['neighbours'] . ' stop' . ((int)$added['neighbours'] === 1 ? '' : 's') . ' within 500 m' : 'the crew is ' . $near . ' km away')
                    . ', adds ' . self::mins($added['minutes'])
                : 'no route that day; a trip from the yard is ' . self::mins($added['minutes']));
            $cands[] = ['date' => $date, 'label' => $label, 'minutes' => (float)$added['minutes'], 'basis' => $added['basis'],
                        'nearest_km' => $near, 'neighbours' => (int)$added['neighbours'], 'crew_id' => $added['crew_id'],
                        'wet' => !empty($f['wet']), 'notes' => $notes, 'text' => $text . ($notes ? ' (' . implode('; ', $notes) . ')' : '')];
        }
        if (!$cands) { $out['none'] = $out['blocked'] ? 'no day in the next ' . self::HORIZON_DAYS . ' fits' : 'no working days to look at'; return $out; }
        // Whole minutes, so two days within a minute of each other go to the earlier one.
        usort($cands, static fn($a, $b) => [(int)$a['wet'], (int)round($a['minutes']), $a['date']] <=> [(int)$b['wet'], (int)round($b['minutes']), $b['date']]);
        $out['best'] = $cands[0];
        $out['alternatives'] = array_slice($cands, 1, self::SHOWN - 1);
        return $out;
    }

    /** "under 1 min", "4 min" — never "0 min" for a stop that is merely very close. */
    public static function mins(float $m): string
    {
        return $m < 0.5 ? 'under 1 min' : (int)max(1, round($m)) . ' min';
    }

    /** The one line shown on Sam's lead row. */
    public static function line(array $r, string $kind = 'look'): string
    {
        if ($r['best'] === null) return 'Best day to ' . ($kind === 'look' ? 'look' : 'start') . ': n/a, ' . ($r['none'] ?? 'unknown');
        $b = $r['best'];
        return 'Best day to ' . ($kind === 'look' ? 'look' : 'start') . ': ' . $b['label'] . ' (' . ($b['basis'] === 'route' ? 'adds ' . self::mins($b['minutes']) : 'no route, ' . self::mins($b['minutes']) . ' from the yard') . ')';
    }
}
