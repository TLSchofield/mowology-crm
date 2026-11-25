<?php
declare(strict_types=1);

/**
 * WeatherSnapshotService — keeps every forecast we pull, and the station readings (Tim, 2026-11-20).
 *
 * Forecasts: one row per run, source and target day (Environment Canada, Open-Meteo, MET Norway), never updated.
 * Observations: the Vancouver airport reading from Environment Canada's own feed, one row per reading time.
 * The salt decision's same-day recheck points at the last Environment Canada snapshot for its day.
 *
 * Writers never throw into the cron that calls them: a failed source is skipped and reported.
 * City is Vancouver for now, like the weather guard (docs/plans/ittai-backlog.md: weather per business).
 */
final class WeatherSnapshotService
{
    public const SRC_EC  = 'Environment Canada';
    public const SRC_OM  = 'Open-Meteo';
    public const SRC_MET = 'MET Norway';
    private const MET_LAT = 49.2827;
    private const MET_LON = -123.1207;
    private const TZ = 'America/Vancouver';

    /**
     * One run: observation always, forecasts when $withForecast. Network fetchers can be injected for tests.
     * @param array<string,callable> $fetchers keys: ec_daily, om_daily, met_raw, ec_current
     * @return array{observations:int,snapshots:int,errors:array<int,string>,ec_days:array<string,array>}
     */
    public static function captureRun(PDO $db, string $runLabel, bool $withForecast, ?array $fetchers = null, ?string $now = null): array
    {
        $f = $fetchers ?? self::liveFetchers();
        $now = $now ?? date('Y-m-d H:i:s');
        $out = ['observations' => 0, 'snapshots' => 0, 'errors' => [], 'ec_days' => []];

        try {
            $cur = self::parseEcCurrent(($f['ec_current'])());
            if ($cur) {
                $out['observations'] += self::storeObservation($db, $cur, $now);
            }
        } catch (Throwable $e) {
            $out['errors'][] = 'observation: ' . $e->getMessage();
        }

        if ($withForecast) {
            $sources = [
                self::SRC_EC  => static function () use ($f): array { return ($f['ec_daily'])(); },
                self::SRC_OM  => static function () use ($f): array { return ($f['om_daily'])(); },
                self::SRC_MET => static function () use ($f): array { return self::parseMetDaily(($f['met_raw'])()); },
            ];
            foreach ($sources as $name => $get) {
                try {
                    $days = $get();
                    $out['snapshots'] += self::storeForecast($db, $runLabel, $name, $days, $now);
                    if ($name === self::SRC_EC) {
                        $out['ec_days'] = $days;   // exactly what was stored, so the recheck can use the same values
                    }
                } catch (Throwable $e) {
                    $out['errors'][] = $name . ': ' . $e->getMessage();
                }
            }
        }
        return $out;
    }

    /** @param array<string,array> $days keyed Y-m-d: temp_low, temp_high, condition */
    public static function storeForecast(PDO $db, string $runLabel, string $source, array $days, string $now): int
    {
        $n = 0;
        $st = $db->prepare('INSERT INTO weather_forecast_snapshots (captured_at, run_label, source, target_date, low_c, high_c, condition_text, raw_json)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($days as $date => $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) || !is_array($d)) {
                continue;
            }
            $low  = isset($d['temp_low'])  && is_numeric($d['temp_low'])  ? (float)$d['temp_low']  : null;
            $high = isset($d['temp_high']) && is_numeric($d['temp_high']) ? (float)$d['temp_high'] : null;
            if ($low === null && $high === null) {
                continue;
            }
            $st->execute([$now, $runLabel, $source, $date, $low, $high,
                isset($d['condition']) ? mb_substr((string)$d['condition'], 0, 150) : null,
                json_encode($d)]);
            $n++;
        }
        return $n;
    }

    /** @param array{observed_at:string,temp_c:float,station:?string,condition:?string,source:string} $o observed_at in UTC */
    public static function storeObservation(PDO $db, array $o, string $now): int
    {
        $chk = $db->prepare('SELECT 1 FROM weather_observations WHERE source = ? AND observed_at = ?');
        $chk->execute([$o['source'], $o['observed_at']]);
        if ($chk->fetchColumn()) {
            return 0;   // same reading already kept (the feed updates hourly, we ask more often)
        }
        try {
            $db->prepare('INSERT INTO weather_observations (observed_at, captured_at, source, station, temp_c, condition_text) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$o['observed_at'], $now, $o['source'], $o['station'], $o['temp_c'], $o['condition']]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                return 0;   // a parallel run stored the same reading between our check and insert (unique key)
            }
            throw $e;
        }
        return 1;
    }

    /** Environment Canada city feed → the current station reading, or null. */
    public static function parseEcCurrent(?string $json): ?array
    {
        if (!$json) {
            return null;
        }
        $p = json_decode($json, true)['features'][0]['properties'] ?? null;
        $cc = is_array($p) ? ($p['currentConditions'] ?? null) : null;
        $temp = $cc['temperature']['value']['en'] ?? null;
        $ts = $cc['timestamp']['en'] ?? null;
        if (!is_array($cc) || !is_numeric($temp) || !$ts) {
            return null;
        }
        $t = strtotime((string)$ts);
        if (!$t) {
            return null;
        }
        $cond = $cc['condition']['en'] ?? null;
        return [
            'source'      => self::SRC_EC,
            'observed_at' => gmdate('Y-m-d H:i:s', $t),
            'temp_c'      => (float)$temp,
            'station'     => isset($cc['station']['value']['en']) ? (string)$cc['station']['value']['en'] : null,
            'condition'   => is_string($cond) ? $cond : null,
        ];
    }

    /**
     * MET Norway locationforecast (compact) → per local day low/high from its hourly air temperature.
     * Days are America/Vancouver calendar days; a day with fewer than 4 readings (today's tail) is dropped. Later days are 6-hourly, so their low is coarser.
     * @return array<string,array{temp_low:float,temp_high:float,condition:?string}>
     */
    public static function parseMetDaily(?string $json): array
    {
        if (!$json) {
            return [];
        }
        $series = json_decode($json, true)['properties']['timeseries'] ?? [];
        $tz = new DateTimeZone(self::TZ);
        $byDay = [];
        foreach ($series as $row) {
            $t = $row['data']['instant']['details']['air_temperature'] ?? null;
            if (!is_numeric($t) || empty($row['time'])) {
                continue;
            }
            $date = (new DateTime((string)$row['time']))->setTimezone($tz)->format('Y-m-d');
            $byDay[$date]['t'][] = (float)$t;
            if (!isset($byDay[$date]['sym']) && isset($row['data']['next_6_hours']['summary']['symbol_code'])) {
                $byDay[$date]['sym'] = (string)$row['data']['next_6_hours']['summary']['symbol_code'];
            }
        }
        $days = [];
        foreach ($byDay as $date => $d) {
            if (count($d['t']) < 4) {
                continue;
            }
            $days[$date] = ['temp_low' => min($d['t']), 'temp_high' => max($d['t']),
                            'condition' => isset($d['sym']) ? str_replace('_', ' ', $d['sym']) : null];
        }
        ksort($days);
        return $days;
    }

    /**
     * The newest Environment Canada snapshot for a day taken at or before $before (default now) and, when given,
     * not before $notBefore (the start of the run that wrote the recheck, so an older snapshot is never linked to it).
     */
    public static function decisiveSnapshotId(PDO $db, string $targetDate, ?string $before = null, ?string $notBefore = null): ?int
    {
        $st = $db->prepare('SELECT id FROM weather_forecast_snapshots WHERE source = ? AND target_date = ? AND captured_at <= ? AND captured_at >= ?
                            ORDER BY captured_at DESC, id DESC LIMIT 1');
        $st->execute([self::SRC_EC, $targetDate, $before ?? date('Y-m-d H:i:s'), $notBefore ?? '1970-01-01 00:00:00']);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Lowest station reading for a service day: from 18:00 the evening before to the noon cut-off.
     * @return array{low:float,count:int,station:?string}|null
     */
    public static function observedLow(PDO $db, string $serviceDate): ?array
    {
        $tz = new DateTimeZone(self::TZ);
        $utc = new DateTimeZone('UTC');
        $from = (new DateTime($serviceDate . ' 18:00:00', $tz))->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $to   = (new DateTime($serviceDate . ' 12:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
        $st = $db->prepare('SELECT MIN(temp_c) AS low, COUNT(*) AS n, MAX(station) AS station FROM weather_observations
                            WHERE source = ? AND observed_at >= ? AND observed_at <= ?');
        $st->execute([self::SRC_EC, $from, $to]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['n'] === 0 || $r['low'] === null) {
            return null;
        }
        return ['low' => (float)$r['low'], 'count' => (int)$r['n'], 'station' => $r['station'] ?: null];
    }

    /** @return array<string,callable> */
    private static function liveFetchers(): array
    {
        require_once APP_ROOT . '/Services/Weather/WeatherService.php';
        $ecUrl = 'https://api.weather.gc.ca/collections/citypageweather-realtime/items?f=json&lang=en-CA&identifier='
               . (getEnvironmentCanadaIdentifier('Vancouver', 'BC') ?? 'bc-74');
        return [
            'ec_current' => static function () use ($ecUrl): ?string { return httpGetRequest($ecUrl, 15); },
            'ec_daily'   => static function (): array { return fetchEnvironmentCanadaData('Vancouver', 'BC')['daily'] ?? []; },
            'om_daily'   => static function (): array { return fetchWeatherDataFromAPI('Vancouver', 'BC'); },
            'met_raw'    => static function (): ?string { return self::httpGetMet(); },
        ];
    }

    /** MET Norway asks for an identifying User-Agent with a site or contact. */
    private static function httpGetMet(): ?string
    {
        $ch = curl_init('https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=' . self::MET_LAT . '&lon=' . self::MET_LON);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mowology CRM Weather Service https://mowology.ca']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body && $code >= 200 && $code < 300) ? (string)$body : null;
    }
}
