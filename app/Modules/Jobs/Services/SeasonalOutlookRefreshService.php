<?php
/**
 * SeasonalOutlookRefreshService — rebuilds the winter outlook numbers from source.
 *
 * Companion to SeasonalOutlookService, which only READS. This class is the write
 * side: it pulls the two public datasets the outlook rests on, recomputes the
 * projection, and stores the result in ops_settings for the reader to pick up.
 *
 * Two sources, both machine-readable and both free of API keys:
 *   ONI   https://www.cpc.ncep.noaa.gov/data/indices/oni.ascii.txt
 *         NOAA CPC Oceanic Nino Index, every overlapping 3-month season to 1950.
 *   ECCC  climate.weather.gc.ca bulk CSV, Vancouver Intl A (climate ID 1108447),
 *         station 889 to 2013 and 51442 from 2013.
 *
 * WHY A DAILY RUN, when ONI only updates monthly and the climatology changes once
 * a year: the daily value is the SEASON-TO-DATE ACTUALS. Once November starts,
 * every run refreshes how many frost nights and snow days have actually happened
 * against what was projected. That turns a static seasonal guess into something
 * that can be checked against reality while the season is still running — which is
 * the only way anyone finds out the outlook was wrong in time to act on it.
 * The expensive 30-year climatology rebuild is cached and skipped on almost every
 * run (see CLIMATOLOGY_MAX_AGE_DAYS).
 *
 * ANALOG SELECTION IS NOT HARDCODED. Earlier revisions of the outlook shipped a
 * fixed list of four El Nino winters. That list silently becomes wrong the moment
 * the regime changes — a hardcoded El Nino analog set would keep projecting a mild
 * winter straight through a La Nina. Instead, analogs are chosen by comparing the
 * CURRENT 3-month ONI season against the SAME 3-month season in each past year, so
 * the comparison is like-for-like and uses only data that would have been available
 * at this point in the calendar. If the regime flips, the analogs follow it.
 *
 * WHAT THIS DOES NOT DO: it never rewrites the human-authored prose (headline,
 * notes, driver_note). Those encode judgement — "hold capacity for January" is a
 * staffing call, not a number. The refresh recomputes figures and leaves the words
 * alone, and it deliberately does NOT clear review_by. A card that silently
 * rewrote its own numbers underneath unchanged prose would be more dangerous than
 * one that is honestly stale, because nothing would signal that the two no longer
 * agree.
 *
 * FAILURE IS LOUD, NOT SILENT. Every stored payload carries data_as_of. The reader
 * surfaces the age, so a cron that dies is visible on the card within days instead
 * of the card quietly serving last month's numbers as current. A failed fetch
 * leaves the previous payload untouched rather than writing a half-built one.
 *
 * Pure parsers/selectors (parseOniAscii, latestOniSeason, classifyEnso,
 * selectAnalogWinters, parseEcccDaily, aggregateSeasons, blend) are static and
 * side-effect free so they unit-test without network or database.
 */

declare(strict_types=1);

class SeasonalOutlookRefreshService
{
    public const ONI_URL = 'https://www.cpc.ncep.noaa.gov/data/indices/oni.ascii.txt';
    public const ECCC_URL = 'https://climate.weather.gc.ca/climate_data/bulk_data_e.html';

    /** Vancouver Intl A. Station 889 runs to 2013; 51442 takes over from 2013. */
    public const STATION_OLD = 889;
    public const STATION_NEW = 51442;
    public const STATION_SWITCH_YEAR = 2013;

    public const SETTING_OUTLOOK     = 'seasonal_outlook_current';
    public const SETTING_CLIMATOLOGY = 'seasonal_outlook_climatology';

    /** Rebuild the 30-year climatology at most this often — it barely moves. */
    public const CLIMATOLOGY_MAX_AGE_DAYS = 300;

    /** How many seasons back the climatology reaches. */
    public const CLIMATOLOGY_SEASONS = 30;

    /** Analog winters to average. Small enough to stay similar, large enough not to be noise. */
    public const ANALOG_COUNT = 5;

    /** Projection = ANALOG_WEIGHT x analog mean + (1 - ANALOG_WEIGHT) x climatology. */
    public const ANALOG_WEIGHT = 0.6;

    /** A season month is dropped from the climatology if it is missing this many observations. */
    public const MAX_MISSING_DAYS = 8;

    /**
     * Screen-height minimum at or below which GROUND frost is likely. Not 0.0:
     * on clear, calm nights the turf surface radiates and runs 2-5 °C colder than
     * the thermometer, so grass frosts while the reported minimum reads +2 to +4.
     * Deliberately an upper bound — there is no cloud/wind filter here, and the
     * station carries no grass-minimum thermometer to calibrate against.
     */
    public const GROUND_FROST_MAX_C = 4.0;

    private ?PDO $db;
    private array $log = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Pure parsers — no network, no DB
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Parse the CPC ONI table into [season => [year => anomaly]].
     * Format is fixed-width-ish: "  DJF 1998  28.85   2.22". Anything that does
     * not match that shape is skipped rather than throwing — a footer line or a
     * stray blank must not take the whole refresh down.
     */
    public static function parseOniAscii(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            if (!preg_match('/^\s*([A-Z]{3})\s+(\d{4})\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s*$/', $line, $m)) {
                continue;
            }
            $out[$m[1]][(int) $m[2]] = (float) $m[4];
        }
        return $out;
    }

    /**
     * The most recent season present in the table, as ['seas','year','value'].
     * "Most recent" is resolved by calendar position, not file order, so a
     * reordered or appended file cannot produce a stale reading.
     */
    public static function latestOniSeason(array $oni): ?array
    {
        // Overlapping 3-month season codes in calendar order; index = centre month.
        $order = ['DJF' => 1, 'JFM' => 2, 'FMA' => 3, 'MAM' => 4, 'AMJ' => 5, 'MJJ' => 6,
                  'JJA' => 7, 'JAS' => 8, 'ASO' => 9, 'SON' => 10, 'OND' => 11, 'NDJ' => 12];
        $best = null;
        foreach ($oni as $seas => $byYear) {
            if (!isset($order[$seas])) {
                continue;
            }
            foreach ($byYear as $year => $value) {
                $rank = $year * 100 + $order[$seas];
                if ($best === null || $rank > $best['rank']) {
                    $best = ['seas' => $seas, 'year' => (int) $year, 'value' => (float) $value, 'rank' => $rank];
                }
            }
        }
        if ($best === null) {
            return null;
        }
        unset($best['rank']);
        return $best;
    }

    /**
     * Direction of travel across the last three overlapping ONI seasons.
     *
     * This exists because a mid-year ONI reading is NOT the winter it leads into:
     * in August 2026 the latest value was +1.39 ("moderate"), while the winter it
     * precedes was forecast to peak far higher. Labelling the card "Moderate El
     * Niño" off that reading would understate the season and quietly contradict
     * the prose beside it. Reporting the trend alongside the value keeps the badge
     * honest about what it is: the latest observation, and which way it is going.
     */
    public static function ensoTrend(array $oni, string $seas, int $year): string
    {
        $order = ['DJF','JFM','FMA','MAM','AMJ','MJJ','JJA','JAS','ASO','SON','OND','NDJ'];
        $pos = array_search($seas, $order, true);
        if ($pos === false) {
            return 'steady';
        }
        $series = [];
        for ($back = 2; $back >= 0; $back--) {
            $p = $pos - $back;
            $y = $year;
            while ($p < 0) { $p += 12; $y--; }
            $v = $oni[$order[$p]][$y] ?? null;
            if ($v === null) {
                return 'steady';
            }
            $series[] = (float) $v;
        }
        $delta = $series[2] - $series[0];
        if (abs($delta) < 0.2) {
            return 'steady';
        }
        // "Strengthening" means moving away from neutral, in whichever phase.
        $awayFromNeutral = ($series[2] >= 0) ? $delta > 0 : $delta < 0;
        return $awayFromNeutral ? 'strengthening' : 'weakening';
    }

    /**
     * ENSO regime label for an ONI value, using the CPC strength bands.
     * Returned as data (not a formatted string) so the caller decides wording.
     */
    public static function classifyEnso(float $oni): array
    {
        $a = abs($oni);
        if ($a < 0.5) {
            return ['code' => 'neutral', 'label' => 'ENSO-neutral', 'phase' => 'neutral', 'strength' => 'neutral'];
        }
        $phase = $oni > 0 ? 'el_nino' : 'la_nina';
        $name  = $oni > 0 ? 'El Niño' : 'La Niña';
        if ($a >= 2.0)      { $s = 'very strong'; }
        elseif ($a >= 1.5)  { $s = 'strong'; }
        elseif ($a >= 1.0)  { $s = 'moderate'; }
        else                { $s = 'weak'; }
        return [
            'code'     => $s === 'very strong' ? "very_strong_{$phase}" : "{$s}_{$phase}",
            'label'    => ucfirst($s) . ' ' . $name,
            'phase'    => $phase,
            'strength' => $s,
        ];
    }

    /**
     * The K past winters most similar to this one, compared at the SAME point in
     * the calendar.
     *
     * A winter is labelled by the year its January falls in, so winter 2026/27 is
     * "2027". The ONI season we currently have (say MJJ 2026) belongs to the year
     * BEFORE the label whenever that season sits in the second half of the
     * calendar year — which is exactly the off-by-one that makes hand-written
     * analog lists wrong.
     *
     * @param array $oni       parseOniAscii() output
     * @param array $available Winter labels the station record actually covers
     * @return array{winters:int[],ranked:array}
     */
    public static function selectAnalogWinters(
        array $oni,
        string $seas,
        int $year,
        array $available,
        int $k = self::ANALOG_COUNT
    ): array {
        $table = $oni[$seas] ?? [];
        $current = $table[$year] ?? null;
        if ($current === null || !$available) {
            return ['winters' => [], 'ranked' => []];
        }

        // Seasons centred in Jul-Dec precede the winter they lead into.
        $leadsIntoNextYear = in_array($seas, ['JJA', 'JAS', 'ASO', 'SON', 'OND', 'NDJ', 'MJJ'], true);
        $offset = $leadsIntoNextYear ? 1 : 0;

        $ranked = [];
        foreach ($available as $winter) {
            $winter = (int) $winter;
            if ($winter === $year + $offset) {
                continue; // never use the current winter as its own analog
            }
            $srcYear = $winter - $offset;
            if (!isset($table[$srcYear])) {
                continue;
            }
            $ranked[] = [
                'winter'   => $winter,
                'oni'      => (float) $table[$srcYear],
                'distance' => abs((float) $table[$srcYear] - (float) $current),
            ];
        }

        // Sort by closeness, then by recency — a tie should prefer the more recent
        // analog, whose observations are the more comparable record.
        usort($ranked, static function ($a, $b) {
            return $a['distance'] <=> $b['distance'] ?: $b['winter'] <=> $a['winter'];
        });

        $ranked = array_slice($ranked, 0, max(1, $k));
        return ['winters' => array_column($ranked, 'winter'), 'ranked' => $ranked];
    }

    /**
     * Parse an ECCC bulk daily CSV into [Y-m-d => ['min'=>?float,'max'=>?float,'snow'=>?float]].
     * Blank/flagged cells become null (not 0.0) — a missing observation and a real
     * zero are different facts, and conflating them would silently invent dry days.
     */
    public static function parseEcccDaily(string $csv): array
    {
        $rows = [];

        // Strip the UTF-8 BOM from the RAW string, before fgetcsv sees it. ECCC
        // emits the BOM ahead of the opening quote of the first field, so fgetcsv
        // reads that field as unquoted and hands back `"Longitude (x)"` with the
        // quote characters still attached. Stripping it off $header[0] afterwards
        // is too late — the quotes are already baked into every first-column key.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $csv);
        rewind($fh);

        $header = fgetcsv($fh, 0, ',', '"', '');
        if (!$header) {
            fclose($fh);
            return [];
        }
        $idx = array_flip($header);

        // A year with no data, or an ECCC error page, comes back without the
        // expected columns. Skip the whole file rather than emitting a warning
        // per row — a malformed year must be a quiet no-op, not log spam.
        if (!isset($idx['Date/Time'])) {
            fclose($fh);
            return [];
        }

        $col = static function (array $r, array $idx, string $name) {
            if (!isset($idx[$name])) {
                return null;
            }
            $v = trim((string) ($r[$idx[$name]] ?? ''));
            return ($v === '' || !is_numeric($v)) ? null : (float) $v;
        };

        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $date = trim((string) ($r[$idx['Date/Time']] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $rows[$date] = [
                'min'  => $col($r, $idx, 'Min Temp (°C)'),
                'max'  => $col($r, $idx, 'Max Temp (°C)'),
                'snow' => $col($r, $idx, 'Total Snow (cm)'),
            ];
        }
        fclose($fh);
        return $rows;
    }

    /**
     * Fold daily observations into per-winter, per-month counts.
     * Winters are labelled by the year of their January. Definitions follow ECCC:
     * frost night = Tmin <= 0.0, ice day = Tmax <= 0.0, snow day = snow >= 0.2 cm.
     *
     * @return array [winter => [month => ['frost','ice','snow_days','snow_days_2cm','snow_cm','missing','observed']]]
     */
    public static function aggregateSeasons(array $daily): array
    {
        $out = [];
        foreach ($daily as $date => $obs) {
            [$y, $m] = array_map('intval', explode('-', $date));
            if (!in_array($m, SeasonalOutlookService::SEASON_MONTHS, true)) {
                continue;
            }
            $winter = ($m >= 11) ? $y + 1 : $y;
            if (!isset($out[$winter][$m])) {
                $out[$winter][$m] = ['frost' => 0, 'ground_frost' => 0, 'ice' => 0, 'snow_days' => 0,
                                     'snow_days_2cm' => 0, 'snow_cm' => 0.0, 'missing' => 0, 'observed' => 0];
            }
            $r = &$out[$winter][$m];
            if ($obs['min'] === null || $obs['snow'] === null) {
                $r['missing']++;
            }
            if ($obs['min'] !== null || $obs['snow'] !== null) {
                $r['observed']++;
            }
            if ($obs['min'] !== null && $obs['min'] <= 0.0)  { $r['frost']++; }
            // Ground-frost proxy: turf frosts while screen-height air is still above zero.
            if ($obs['min'] !== null && $obs['min'] <= self::GROUND_FROST_MAX_C) { $r['ground_frost']++; }
            if ($obs['max'] !== null && $obs['max'] <= 0.0)  { $r['ice']++; }
            if ($obs['snow'] !== null) {
                if ($obs['snow'] >= 0.2) { $r['snow_days']++; }
                if ($obs['snow'] >= 2.0) { $r['snow_days_2cm']++; }
                $r['snow_cm'] += $obs['snow'];
            }
            unset($r);
        }
        return $out;
    }

    /**
     * Mean of the given winters, per month. Months with too many missing
     * observations are excluded from their month's mean rather than dragging it
     * down — a half-observed February is not a mild February.
     */
    public static function meanOverWinters(array $seasons, array $winters): array
    {
        $out = [];
        foreach (SeasonalOutlookService::SEASON_MONTHS as $m) {
            $acc = ['frost' => 0.0, 'ground_frost' => 0.0, 'snow_days' => 0.0,
                    'snow_days_2cm' => 0.0, 'snow_cm' => 0.0];
            $n = 0;
            foreach ($winters as $w) {
                $row = $seasons[$w][$m] ?? null;
                if ($row === null || $row['missing'] > self::MAX_MISSING_DAYS) {
                    continue;
                }
                foreach ($acc as $k => $_) {
                    $acc[$k] += (float) $row[$k];
                }
                $n++;
            }
            if ($n === 0) {
                continue;
            }
            $out[$m] = array_map(static fn($v) => round($v / $n, 2), $acc);
            $out[$m]['n'] = $n;
        }
        return $out;
    }

    /** Weighted blend of two per-month tables. Months absent from either are skipped. */
    public static function blend(array $analog, array $climo, float $w = self::ANALOG_WEIGHT): array
    {
        $out = [];
        foreach (SeasonalOutlookService::SEASON_MONTHS as $m) {
            if (!isset($analog[$m], $climo[$m])) {
                continue;
            }
            foreach (['frost', 'ground_frost', 'snow_days', 'snow_days_2cm', 'snow_cm'] as $k) {
                $out[$m][$k] = round($w * (float) $analog[$m][$k] + (1 - $w) * (float) $climo[$m][$k], 1);
            }
        }
        return $out;
    }

    /** The winter label (year of its January) that the given date belongs to. */
    public static function winterLabelFor(string $date): int
    {
        [$y, $m] = array_map('intval', explode('-', $date));
        return ($m >= 7) ? $y + 1 : $y;
    }

    public function logLines(): array
    {
        return $this->log;
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Network
    // ──────────────────────────────────────────────────────────────────────

    /** GET a URL. Returns null on any failure — callers must treat null as "skip", never as "empty". */
    protected function fetch(string $url, int $timeout = 45): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'Mowology-CRM/1.0 (seasonal outlook refresh)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        // No curl_close(): it is a no-op since PHP 8.0 and deprecated in 8.5.

        if ($body === false || $code !== 200 || $body === '') {
            $this->note("fetch failed ({$code}" . ($err ? ", {$err}" : '') . "): {$url}");
            return null;
        }
        return (string) $body;
    }

    /** One calendar year of ECCC daily observations, or null. */
    protected function fetchStationYear(int $year): ?string
    {
        $station = $year < self::STATION_SWITCH_YEAR ? self::STATION_OLD : self::STATION_NEW;
        $url = self::ECCC_URL . '?' . http_build_query([
            'format'    => 'csv',
            'stationID' => $station,
            'Year'      => $year,
            'Month'     => 1,
            'Day'       => 1,
            'timeframe' => 2,
            'submit'    => 'Download Data',
        ]);
        return $this->fetch($url);
    }

    /** Daily observations across a calendar-year range, merged. */
    protected function fetchDailyRange(int $fromYear, int $toYear): array
    {
        $daily = [];
        for ($y = $fromYear; $y <= $toYear; $y++) {
            $csv = $this->fetchStationYear($y);
            if ($csv === null) {
                continue;
            }
            foreach (self::parseEcccDaily($csv) as $d => $row) {
                $daily[$d] = $row;
            }
        }
        return $daily;
    }

    // ──────────────────────────────────────────────────────────────────────
    // ops_settings
    // ──────────────────────────────────────────────────────────────────────

    public function readSetting(string $key): ?array
    {
        if (!$this->db instanceof PDO) {
            return null;
        }
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $raw = $stmt->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function writeSetting(string $key, array $value, string $description): bool
    {
        if (!$this->db instanceof PDO) {
            return false;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->note('refusing to write: payload failed to encode');
            return false;
        }
        try {
            // ops_settings has a UNIQUE key on setting_key (migration 202).
            $stmt = $this->db->prepare(
                "INSERT INTO ops_settings (setting_key, setting_value, description)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            return $stmt->execute([$key, $json, $description]);
        } catch (Throwable $e) {
            $this->note('write failed: ' . $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // The refresh itself
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Rebuild and store the outlook.
     *
     * @param string|null $today  Injectable for tests.
     * @param bool $forceClimatology Rebuild the 30-year baseline even if cached.
     * @return array{ok:bool,...} Never throws for an expected failure (network,
     *   parse); returns ok=false with a reason so the cron can report and exit 1
     *   while leaving the previous payload in place.
     */
    public function refresh(?string $today = null, bool $forceClimatology = false): array
    {
        $today  = $today ?: date('Y-m-d');
        $winter = self::winterLabelFor($today);

        // ── 1. ENSO state ────────────────────────────────────────────────
        $oniRaw = $this->fetch(self::ONI_URL);
        if ($oniRaw === null) {
            return ['ok' => false, 'reason' => 'oni_fetch_failed', 'log' => $this->log];
        }
        $oni = self::parseOniAscii($oniRaw);
        $latest = self::latestOniSeason($oni);
        if ($latest === null) {
            return ['ok' => false, 'reason' => 'oni_parse_failed', 'log' => $this->log];
        }
        $enso = self::classifyEnso($latest['value']);
        $enso['trend'] = self::ensoTrend($oni, $latest['seas'], $latest['year']);
        $this->note("ONI {$latest['seas']} {$latest['year']} = {$latest['value']} "
                  . "({$enso['label']}, {$enso['trend']})");

        // ── 2. Climatology (cached — rebuilt at most yearly) ──────────────
        $climoCache = $this->readSetting(self::SETTING_CLIMATOLOGY);
        $needsClimo = $forceClimatology
            || !$climoCache
            || empty($climoCache['months'])
            || (strtotime($today) - strtotime((string) ($climoCache['computed_at'] ?? '1970-01-01')))
               > self::CLIMATOLOGY_MAX_AGE_DAYS * 86400;

        if ($needsClimo) {
            $this->note('rebuilding 30-winter climatology (slow path)');
            $from  = $winter - self::CLIMATOLOGY_SEASONS;
            $daily = $this->fetchDailyRange($from, $winter);
            if (!$daily) {
                return ['ok' => false, 'reason' => 'eccc_fetch_failed', 'log' => $this->log];
            }
            $seasons = self::aggregateSeasons($daily);
            // Only complete, well-observed past winters belong in a baseline.
            $complete = [];
            foreach ($seasons as $w => $months) {
                if ($w >= $winter || count($months) < count(SeasonalOutlookService::SEASON_MONTHS)) {
                    continue;
                }
                $gaps = array_sum(array_column($months, 'missing'));
                if ($gaps > self::MAX_MISSING_DAYS) {
                    continue;
                }
                $complete[] = $w;
            }
            sort($complete);
            if (count($complete) < 10) {
                return ['ok' => false, 'reason' => 'insufficient_climatology', 'log' => $this->log];
            }
            $climoCache = [
                'computed_at' => $today,
                'winters'     => $complete,
                'months'      => self::meanOverWinters($seasons, $complete),
                'seasons'     => $seasons,
            ];
            $this->writeSetting(self::SETTING_CLIMATOLOGY, $climoCache,
                'Cached YVR Nov-Mar climatology + per-winter observations (seasonal outlook)');
            $this->note('climatology rebuilt from ' . count($complete) . ' winters');
        } else {
            $this->note('climatology cache hit (' . ($climoCache['computed_at'] ?? '?') . ')');
        }

        $seasons = $climoCache['seasons'] ?? [];
        $climo   = $climoCache['months'] ?? [];
        if (!$climo) {
            return ['ok' => false, 'reason' => 'no_climatology', 'log' => $this->log];
        }

        // ── 3. Analog winters, chosen from the data ──────────────────────
        $analogPick = self::selectAnalogWinters(
            $oni, $latest['seas'], $latest['year'], $climoCache['winters'] ?? []
        );
        if (!$analogPick['winters']) {
            return ['ok' => false, 'reason' => 'no_analogs', 'log' => $this->log];
        }
        $analog = self::meanOverWinters($seasons, $analogPick['winters']);
        $this->note('analogs: ' . implode(', ', array_map(
            static fn($r) => ($r['winter'] - 1) . '/' . substr((string) $r['winter'], 2) . ' (ONI ' . $r['oni'] . ')',
            $analogPick['ranked']
        )));

        // ── 4. Projection ────────────────────────────────────────────────
        $projected = self::blend($analog, $climo);
        if (!$projected) {
            return ['ok' => false, 'reason' => 'blend_empty', 'log' => $this->log];
        }

        // ── 5. Season-to-date actuals — the reason this runs daily ───────
        $actuals = [];
        $currentDaily = $this->fetchDailyRange($winter - 1, $winter);
        if ($currentDaily) {
            $actuals = self::aggregateSeasons($currentDaily)[$winter] ?? [];
            $this->note('season-to-date actuals for ' . count($actuals) . ' month(s)');
        } else {
            $this->note('season-to-date actuals unavailable this run');
        }

        // ── 6. Merge, preserving human-authored prose ────────────────────
        $previous = $this->readSetting(self::SETTING_OUTLOOK) ?: SeasonalOutlookService::bundledOutlook();
        $monthNames = [11 => 'November', 12 => 'December', 1 => 'January', 2 => 'February', 3 => 'March'];

        $months = [];
        foreach (SeasonalOutlookService::SEASON_MONTHS as $m) {
            if (!isset($projected[$m], $climo[$m])) {
                continue;
            }
            $row = [
                'name'             => $monthNames[$m],
                'frost'            => $projected[$m]['frost'],
                'ground_frost'     => $projected[$m]['ground_frost'],
                'snow_days'        => $projected[$m]['snow_days'],
                'snow_days_2cm'    => $projected[$m]['snow_days_2cm'],
                'snow_cm'          => $projected[$m]['snow_cm'],
                'normal_frost'     => round((float) $climo[$m]['frost'], 1),
                'normal_ground_frost' => round((float) $climo[$m]['ground_frost'], 1),
                'normal_snow_days' => round((float) $climo[$m]['snow_days'], 1),
                'normal_snow_cm'   => round((float) $climo[$m]['snow_cm'], 1),
            ];
            if (isset($actuals[$m]) && ($actuals[$m]['observed'] ?? 0) > 0) {
                $row['actual_frost']        = (int) $actuals[$m]['frost'];
                $row['actual_ground_frost'] = (int) $actuals[$m]['ground_frost'];
                $row['actual_snow_days'] = (int) $actuals[$m]['snow_days'];
                $row['actual_snow_cm']   = round((float) $actuals[$m]['snow_cm'], 1);
                $row['actual_days']      = (int) $actuals[$m]['observed'];
            }
            $months[$m] = $row;
        }

        $outlook = $previous;
        $outlook['months']       = $months;
        // Badge text states the observation, not a winter forecast — see ensoTrend().
        $outlook['driver'] = $enso['code'] === 'neutral'
            ? sprintf('ENSO-neutral %+.2f (%s)', $latest['value'], $latest['seas'])
            : sprintf('%s %+.2f%s (%s)',
                $enso['label'],
                $latest['value'],
                $enso['trend'] === 'steady' ? '' : ', ' . $enso['trend'],
                $latest['seas']);
        $outlook['enso']         = $enso + ['oni' => $latest['value'], 'season' => $latest['seas'], 'year' => $latest['year']];
        $outlook['baseline']     = count($climoCache['winters'] ?? []) . '-winter normal, YVR '
                                 . (min($climoCache['winters']) - 1) . '/' . substr((string) min($climoCache['winters']), 2)
                                 . '-' . (max($climoCache['winters']) - 1) . '/' . substr((string) max($climoCache['winters']), 2);
        $outlook['analogs']      = $analogPick['ranked'];
        $outlook['data_as_of']   = $today;
        $outlook['auto_refresh'] = true;

        if (!$this->writeSetting(self::SETTING_OUTLOOK, $outlook,
                'Auto-refreshed winter seasonal outlook (seasonal_outlook_refresh cron)')) {
            return ['ok' => false, 'reason' => 'write_failed', 'log' => $this->log];
        }

        return [
            'ok'          => true,
            'winter'      => $winter,
            'enso'        => $enso['label'],
            'oni'         => $latest['value'],
            'analogs'     => $analogPick['winters'],
            'months'      => count($months),
            'actuals'     => count($actuals),
            'climatology' => $needsClimo ? 'rebuilt' : 'cached',
            'log'         => $this->log,
        ];
    }
}
