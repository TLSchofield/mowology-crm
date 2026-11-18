<?php
/**
 * SeasonalOutlookService — the winter seasonal outlook that sits behind the
 * 7-day weather forecast.
 *
 * Why this exists: WeatherService answers "what is the weather on Thursday".
 * It cannot answer "how many freeze mornings and snow events should I staff and
 * quote for between November and March", which is the question that drives
 * snow/ice contracts, winter crew hours, and salt/de-icer purchasing. That is a
 * seasonal-probability question, so it is deliberately kept OUT of WeatherService
 * — mixing a 7-day deterministic forecast with a 5-month probabilistic outlook in
 * one API is how people end up treating an outlook as a forecast.
 *
 * The numbers are expected values per month, not predictions of specific days.
 * "1.1 snow days in December" means "budget for about one snow day"; it does not
 * mean a snow day will happen. Every figure carries its 30-winter normal beside
 * it so the reader always sees the anomaly, not just the absolute.
 *
 * Baseline: Environment Canada daily observations for VANCOUVER INT'L A
 * (climate ID 1108447, station 889 → 51442), 28 complete Nov–Mar seasons in
 * 1995/96–2024/25. Winters 2012/13 and 2020/21 were dropped for observation gaps.
 * Definitions match ECCC: a frost night is daily Tmin <= 0.0 °C, an ice day is
 * daily Tmax <= 0.0 °C, a snow day is Total Snow >= 0.2 cm.
 *
 * TWO frost metrics are tracked, because they gate different decisions. Only ONE
 * of them is a measurement; the other is an inference, and they must never be
 * presented as if they were the same kind of number:
 *   frost        Tmin <= 0.0 °C  — a MEASURED count, straight off the station's
 *                daily minimum air temperature at screen height (~1.5 m). No frost
 *                formation is inferred: it is simply the nights that reached zero.
 *                Drives pipe/irrigation freeze and hard-freeze call-outs. The
 *                boundary is <= 0.0 per WMO/ECCC convention rather than < 0.0;
 *                the difference is ~1.1 nights a winter at this station.
 *                Note the station reports a CALENDAR-DAY minimum, so a night that
 *                spans midnight is attributed to the day its low fell in — in
 *                practice the dawn low, which is what "night" means here.
 *   ground_frost Tmin <= 4.0 °C  — an INFERRED proxy for GROUND frost, not an
 *                observation of it. On clear, calm nights
 *                the turf surface radiates and runs 2-5 °C colder than screen
 *                height, so grass frosts while the reported minimum is still
 *                +2 to +4 °C. This is the number that gates mowing, since cutting
 *                frosted turf bruises the blade. It is an UPPER BOUND: it does not
 *                filter for cloud or wind, which suppress radiative frost, and the
 *                station reports no grass-minimum thermometer to check it against.
 *                Roughly 2.5x the air-frost count in this record (97 vs 39/winter).
 *
 * Projection method: 0.6 x (mean of the 4 strong-El-Niño winters in that record:
 * 1997/98, 2009/10, 2015/16, 2023/24) + 0.4 x (30-winter climatology). The blend
 * is deliberate — a 4-winter analog sample is far too small to stand alone, and
 * El Niño shifts probabilities rather than determining outcomes. Do not read the
 * decimals as precision; they are there so the monthly figures sum honestly.
 *
 * Staleness is a first-class state, not a footnote. An outlook issued in August
 * for a season peaking in February MUST be re-checked once the ENSO signal firms
 * up, so every outlook carries review_by and the card visibly flips to a
 * "review overdue" state on that date rather than quietly presenting stale
 * numbers as current.
 *
 * Config override lives in ops_settings under seasonal_outlook_* keys (the same
 * key-value home the weather guard already uses) so next season's numbers can be
 * updated without a code deploy. No migration is required — ops_settings already
 * exists (migration 202). loadOverride() is fail-soft: any DB problem falls back
 * to the bundled outlook rather than blanking the card.
 *
 * The pure helpers (isActive, isStale, upcomingMonths, totals, anomalyLabel) are
 * static and DB-free so they unit-test without a database.
 */

declare(strict_types=1);

class SeasonalOutlookService
{
    /** ops_settings keys this service reads. */
    public const SETTING_KEY_PREFIX = 'seasonal_outlook_';

    /** Months a winter outlook covers, in season order (Nov → Mar). */
    public const SEASON_MONTHS = [11, 12, 1, 2, 3];

    /**
     * How many days an auto-refreshed payload may go without an update before the
     * card says so. The refresh cron runs daily, so anything past this means it is
     * failing. This exists because the dangerous failure is not a crashed cron —
     * it is a crashed cron nobody notices, leaving the card serving last month's
     * numbers with today's confidence.
     */
    public const REFRESH_MAX_AGE_DAYS = 3;

    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Bundled outlook — the shipped default for the current season
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The outlook shipped with this release. Replace this block each autumn (or
     * override it in ops_settings) rather than editing it mid-season, so that
     * `issued` stays truthful about when the numbers were actually derived.
     */
    public static function bundledOutlook(): array
    {
        return [
            'season'      => '2026-27',
            'label'       => 'Winter 2026-27 Outlook',
            'region'      => 'Vancouver, BC',
            'station'     => 'Vancouver Intl A (YVR)',
            'issued'      => '2026-08-31',
            'valid_from'  => '2026-08-31',
            'valid_to'    => '2027-03-31',
            'review_by'   => '2026-11-15',
            'confidence'  => 'moderate',
            'driver'      => 'Moderate El Niño +1.39, strengthening (MJJ)',
            'driver_note' => 'NOAA CPC has an El Niño Advisory out with a greater than 90% chance of a very '
                           . 'strong event through fall and winter, and a 69% chance the Oct-Dec season sets a '
                           . 'record (RONI at or above +2.5 C). That is the opposite setup from last winter.',
            'headline'    => 'Milder and much less lowland snow than a normal winter '
                           . '- but the freeze mornings do not go away.',
            'baseline'    => '28-winter normal, YVR 1997/98-2025/26',

            // Expected days per month. normal_* are the observed 28-winter means.
            'months' => [
                11 => ['name' => 'November',  'frost' => 7.2, 'ground_frost' => 16.7, 'snow_days' => 0.2, 'snow_days_2cm' => 0.1, 'snow_cm' => 1.1,
                       'normal_frost' => 5.3, 'normal_ground_frost' => 15.3, 'normal_snow_days' => 0.5, 'normal_snow_cm' => 2.9],
                12 => ['name' => 'December',  'frost' => 7.4, 'ground_frost' => 19.7, 'snow_days' => 0.7, 'snow_days_2cm' => 0.5, 'snow_cm' => 4.3,
                       'normal_frost' => 10.2, 'normal_ground_frost' => 22.5, 'normal_snow_days' => 1.8, 'normal_snow_cm' => 10.7],
                1  => ['name' => 'January',   'frost' => 8.5, 'ground_frost' => 18.7, 'snow_days' => 2.8, 'snow_days_2cm' => 1.6, 'snow_cm' => 14.2,
                       'normal_frost' => 8.5, 'normal_ground_frost' => 21.3, 'normal_snow_days' => 2.1, 'normal_snow_cm' => 9.2],
                2  => ['name' => 'February',  'frost' => 7.1, 'ground_frost' => 19.2, 'snow_days' => 1.0, 'snow_days_2cm' => 0.6, 'snow_cm' => 4.5,
                       'normal_frost' => 9.6, 'normal_ground_frost' => 20.5, 'normal_snow_days' => 1.9, 'normal_snow_cm' => 7.8],
                3  => ['name' => 'March',     'frost' => 3.3, 'ground_frost' => 15.3, 'snow_days' => 0.9, 'snow_days_2cm' => 0.4, 'snow_cm' => 1.4,
                       'normal_frost' => 3.7, 'normal_ground_frost' => 17.1, 'normal_snow_days' => 0.6, 'normal_snow_cm' => 1.5],
            ],

            'notes' => [
                'January is the month to hold capacity for - it carries more than half the projected '
                . 'season snowfall on its own (14.2 of 25.5 cm) and is the one month the analog winters '
                . 'do not push below normal.',
                'Nights reaching 0 C barely drop: about 34 vs 37 normal - that is a measured count, '
                . 'not a frost estimate. Nights at or below 4 C, the proxy for turf frost that delays '
                . 'mowing, run about 90 vs 97 normal. Mild does not mean frost-free.',
                'El Niño shifts probabilities, it does not remove cold outbreaks. Lowland snow here comes '
                . 'from short Arctic outflow events that punch through any seasonal pattern - 2023/24 was a '
                . 'strong El Niño and still delivered 40.8 cm at YVR. Keep the snow/ice response crewed.',
                'Farmers\' Almanac calls coastal BC "Rainy. Windy. Brief Thaws." for 2026-27 and gives no '
                . 'month-by-month BC breakdown - it agrees on the pattern but adds no monthly detail.',
            ],

            'sources' => [
                ['label' => 'NOAA CPC ENSO Diagnostic Discussion',
                 'url'   => 'https://www.cpc.ncep.noaa.gov/products/analysis_monitoring/enso_advisory/ensodisc.shtml'],
                ['label' => 'ECCC daily observations, Vancouver Intl A',
                 'url'   => 'https://climate.weather.gc.ca/climate_data/daily_data_e.html?StationID=51442'],
                ['label' => 'Farmers\' Almanac 2026-27 Canada outlook',
                 'url'   => 'https://www.farmersalmanac.com/canada-farmers-almanac-2026-2027-outlook'],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Pure, unit-testable helpers (no DB, no filesystem)
    // ──────────────────────────────────────────────────────────────────────

    /** True while today falls inside the outlook's validity window. */
    public static function isActive(array $outlook, string $today): bool
    {
        $from = (string) ($outlook['valid_from'] ?? '');
        $to   = (string) ($outlook['valid_to'] ?? '');
        if ($from === '' || $to === '') {
            return false;
        }
        return $today >= $from && $today <= $to;
    }

    /**
     * True once the review date has passed. A stale outlook is still shown —
     * silently hiding it would leave the scheduler with no seasonal context at
     * all — but it is shown flagged so nobody mistakes it for a current read.
     */
    public static function isStale(array $outlook, string $today): bool
    {
        $reviewBy = (string) ($outlook['review_by'] ?? '');
        return $reviewBy !== '' && $today > $reviewBy;
    }

    /**
     * Season months that have not fully passed yet, in season order, each tagged
     * with is_current. Once March is over this returns [] and callers should
     * stop rendering the outlook. Months are keyed by calendar month number, so
     * the season year is derived from valid_to rather than from "now".
     */
    public static function upcomingMonths(array $outlook, string $today): array
    {
        $months = $outlook['months'] ?? [];
        if (!$months) {
            return [];
        }

        $endYear = (int) substr((string) ($outlook['valid_to'] ?? ''), 0, 4);
        if ($endYear <= 0) {
            return [];
        }

        $curYm = substr($today, 0, 7);
        $out   = [];
        foreach (self::SEASON_MONTHS as $m) {
            if (!isset($months[$m])) {
                continue;
            }
            // Nov/Dec belong to the year before the season's end year.
            $year = ($m >= 11) ? $endYear - 1 : $endYear;
            $ym   = sprintf('%04d-%02d', $year, $m);
            if ($ym < $curYm) {
                continue;
            }
            $row               = $months[$m];
            $row['month']      = $m;
            $row['ym']         = $ym;
            $row['is_current'] = ($ym === $curYm);
            $out[]             = $row;
        }
        return $out;
    }

    /** Sum a set of month rows into season totals. Accepts the full or remaining set. */
    public static function totals(array $monthRows): array
    {
        $t = ['frost' => 0.0, 'ground_frost' => 0.0, 'snow_days' => 0.0, 'snow_days_2cm' => 0.0,
              'snow_cm' => 0.0, 'normal_frost' => 0.0, 'normal_ground_frost' => 0.0,
              'normal_snow_days' => 0.0, 'normal_snow_cm' => 0.0];
        foreach ($monthRows as $row) {
            foreach ($t as $k => $_) {
                $t[$k] += (float) ($row[$k] ?? 0);
            }
        }
        return array_map(static fn($v) => round($v, 1), $t);
    }

    /**
     * Percentage anomaly vs normal, as a signed int. Returns null when there is
     * no normal to compare against — a 0-baseline month would otherwise render
     * as a meaningless "+100%".
     */
    public static function anomalyPct(float $projected, float $normal): ?int
    {
        if ($normal <= 0.0) {
            return null;
        }
        return (int) round((($projected - $normal) / $normal) * 100);
    }

    /** Short human label for an anomaly, e.g. "53% below normal". */
    public static function anomalyLabel(float $projected, float $normal): string
    {
        $pct = self::anomalyPct($projected, $normal);
        if ($pct === null) {
            return 'no baseline';
        }
        if (abs($pct) < 10) {
            return 'near normal';
        }
        return abs($pct) . '% ' . ($pct < 0 ? 'below' : 'above') . ' normal';
    }

    /**
     * The single month a scheduler should hold capacity for: the highest
     * projected snow-day count among the months still ahead. Ties break toward
     * the earlier month, because the earlier one is the one you must staff first.
     */
    public static function peakRiskMonth(array $monthRows): ?array
    {
        $best = null;
        foreach ($monthRows as $row) {
            if ($best === null || (float) $row['snow_days'] > (float) $best['snow_days']) {
                $best = $row;
            }
        }
        return $best;
    }

    // ──────────────────────────────────────────────────────────────────────
    // DB-aware
    // ──────────────────────────────────────────────────────────────────────

    /** Days since the stored payload was last refreshed, or null if never refreshed. */
    public static function refreshAgeDays(array $outlook, string $today): ?int
    {
        $asOf = (string) ($outlook['data_as_of'] ?? '');
        if ($asOf === '') {
            return null;
        }
        $a = strtotime($asOf);
        $b = strtotime($today);
        if ($a === false || $b === false) {
            return null;
        }
        return (int) floor(($b - $a) / 86400);
    }

    /**
     * True when an auto-refreshed outlook has stopped being refreshed.
     *
     * Only applies to auto_refresh payloads: a hand-authored outlook has no cron
     * behind it, so its age says nothing about whether anything is broken. Kept
     * separate from isStale() on purpose — isStale means "a human should re-read
     * the prose", this means "the machine feeding it has stopped".
     */
    public static function isDataStale(array $outlook, string $today): bool
    {
        if (empty($outlook['auto_refresh'])) {
            return false;
        }
        $age = self::refreshAgeDays($outlook, $today);
        return $age === null || $age > self::REFRESH_MAX_AGE_DAYS;
    }

    /**
     * The outlook to render, or null when there is nothing in season.
     * Adds derived keys: months_ahead, totals, season_totals, is_stale,
     * is_data_stale, refresh_age_days, peak.
     */
    public function activeOutlook(?string $today = null): ?array
    {
        $today   = $today ?: date('Y-m-d');
        $outlook = $this->loadOverride() ?? self::bundledOutlook();

        if (!self::isActive($outlook, $today)) {
            return null;
        }

        $ahead = self::upcomingMonths($outlook, $today);
        if (!$ahead) {
            return null;
        }

        $outlook['months_ahead']   = $ahead;
        $outlook['totals']         = self::totals($ahead);
        $outlook['season_totals']  = self::totals(array_values($outlook['months'] ?? []));
        $outlook['is_stale']         = self::isStale($outlook, $today);
        $outlook['is_data_stale']    = self::isDataStale($outlook, $today);
        $outlook['refresh_age_days'] = self::refreshAgeDays($outlook, $today);
        $outlook['peak']           = self::peakRiskMonth($ahead);
        $outlook['is_partial']     = count($ahead) < count($outlook['months'] ?? []);

        return $outlook;
    }

    /**
     * ops_settings override, or null. Stored as one JSON blob under
     * seasonal_outlook_current so next season is a single settings edit.
     * Fail-soft by design: a malformed blob or a missing table must not blank
     * the card, it must fall through to the bundled outlook.
     */
    public function loadOverride(): ?array
    {
        if (!$this->db instanceof PDO) {
            return null;
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1"
            );
            $stmt->execute([self::SETTING_KEY_PREFIX . 'current']);
            $raw = $stmt->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['months']) || empty($decoded['valid_to'])) {
            return null;
        }
        return $decoded;
    }
}
