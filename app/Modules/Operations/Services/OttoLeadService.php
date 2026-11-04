<?php
declare(strict_types=1);

/**
 * OttoLeadService — what Otto knows about a NEW LEAD the moment it has an address (Tim, 2026-11-03):
 * its arrival border (OttoAutoPlaceService::placeNewLead: pin, then the City of Vancouver's own lot map, then the
 * province's) and the best working day to look at it (LeadRouteDay over the crews' real route days).
 *
 * Reads and suggests only. Booking the visit stays Tim's tap (the quote-visit panel on quote-workflow.php).
 * No namespace / no autoloader: require_once and `new OttoLeadService($db)`.
 */
require_once __DIR__ . '/LeadRouteDay.php';
require_once __DIR__ . '/OttoHarvestService.php';
require_once __DIR__ . '/OttoAutoPlaceService.php';
require_once dirname(__DIR__, 2) . '/Sales/Services/CloserService.php';

class OttoLeadService
{
    private PDO $db;
    private string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    /** The yard Tim set (or the geocoded business address the Closer stored); null when neither exists. */
    public function depot(): ?array
    {
        try {
            $s = $this->db->query("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ('closer_depot_lat', 'closer_depot_lng')");
            $v = [];
            foreach ($s as $r) $v[$r['setting_key']] = (float)$r['setting_value'];
            return !empty($v['closer_depot_lat']) && !empty($v['closer_depot_lng']) ? ['lat' => $v['closer_depot_lat'], 'lng' => $v['closer_depot_lng']] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** date => {rest, wet}: Sundays are blocked in LeadRouteDay; wet = the weather guard flagged a visit that day. */
    public function dayFlags(string $from, string $to): array
    {
        $out = [];
        try {
            $st = $this->db->prepare("SELECT scheduled_date AS d, COUNT(*) AS n FROM job_visits
                                       WHERE scheduled_date BETWEEN ? AND ? AND status = 'scheduled' AND weather_status = 'NOT_OK' GROUP BY scheduled_date");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['d']] = ['wet' => (int)$r['n'] > 0];
        } catch (Throwable $e) { /* no weather column: no wet flags, said nowhere as fact */ }
        return $out;
    }

    public const STATE_KEY = 'otto_lead_place_state';
    public const RETRY_HOURS = 3;       // a lead that could not be placed is tried again after this, not every 15 minutes
    public const PER_RUN = 3;           // a few leads a run: OpenStreetMap allows one request a second, no bulk

    /** Pure: should this open lead's property be tried now? Needs no border yet and was not tried in the last RETRY_HOURS. */
    public static function due(bool $hasBorder, ?string $lastTried, string $now): bool
    {
        if ($hasBorder) return false;
        return $lastTried === null || (strtotime($now) - strtotime($lastTried)) >= self::RETRY_HOURS * 3600;
    }

    private function readState(): array
    {
        $raw = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = '" . self::STATE_KEY . "'")->fetchColumn();
        $a = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($a) ? $a : [];
    }

    /**
     * The catch-up that makes "immediately" true however a lead arrived (website form, the Kitsilano site, email):
     * every 15 minutes (sales_inbox_poll) the open leads that have a property but no arrival border get one.
     * @return array{tried: int, bordered: int, pinned: int, note: string}
     */
    public function placeOpenLeads(?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $r = ['tried' => 0, 'bordered' => 0, 'pinned' => 0, 'note' => ''];
        $placer = new OttoAutoPlaceService($this->db, null, null, substr($now, 0, 10));
        if (!$placer->enabled()) return $r + ['note' => 'switched off'];
        $state = $this->readState();
        $done = []; $cleared = [];
        $has = [];
        foreach ($this->db->query("SELECT DISTINCT property_id FROM job_geofences WHERE zone_type = 'arrival_border'") as $b) $has[(int)$b['property_id']] = true;
        foreach ($placer->leadCandidates() as $pid => $p) {
            if ($r['tried'] >= self::PER_RUN) break;
            if (!self::due(isset($has[$pid]), $state[$pid] ?? null, $now)) continue;
            $r['tried']++;
            $state[$pid] = $now;          // the attempt is recorded BEFORE the work: a lookup that throws still waits its three hours,
            $done[$pid] = true;           // so one bad property can never starve the leads behind it (Codex 2026-11-03)
            try {
                $res = $placer->placeNewLead((int)$pid);
            } catch (Throwable $e) {
                error_log('Otto lead placement #' . $pid . ': ' . $e->getMessage());
                continue;
            }
            if (!empty($res['pinned'])) $r['pinned']++;
            if (!empty($res['ok'])) { $r['bordered']++; $cleared[$pid] = true; }
        }
        // Merge, don't overwrite: re-read the state just before writing and apply only this run's own changes, so two
        // overlapping polls cannot erase each other's timestamps (Codex 2026-11-03).
        $fresh = $this->readState();
        foreach ($done as $pid => $_) $fresh[$pid] = $now;
        foreach ($cleared as $pid => $_) unset($fresh[$pid]);
        $keep = array_filter($fresh, static fn($t) => (strtotime($now) - strtotime((string)$t)) < 14 * 86400);
        $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([self::STATE_KEY, (string)json_encode($keep), 'Otto: open leads he tried to place (pin + arrival border) and when; retried after ' . self::RETRY_HOURS . ' h']);
        return $r;
    }

    /**
     * Best day to look, for each lead that has a pin.
     * @param array $leads [{id, lat, lng}] (lat/lng null or 0 = no pin)
     * @return array<int, array> lead id => LeadRouteDay::rank() result (or {none: reason})
     */
    public function bestDays(array $leads, int $needMin = LeadRouteDay::QUOTE_VISIT_MIN): array
    {
        $out = [];
        $horizon = date('Y-m-d', strtotime($this->today . ' +' . (LeadRouteDay::HORIZON_DAYS + 1) . ' days'));
        $harvest = new OttoHarvestService($this->db, $this->today);
        $loads = $harvest->dayLoads(date('Y-m-d', strtotime($this->today . ' +1 day')), $horizon);
        $crew = $harvest->crewCount();
        $days = array_values(array_filter((new CloserService($this->db))->routeDays(0), fn($d) => (string)$d['date'] > $this->today));
        $flags = $this->dayFlags($this->today, $horizon);
        $depot = $this->depot();
        foreach ($leads as $l) {
            $r = LeadRouteDay::rank((float)($l['lat'] ?? 0), (float)($l['lng'] ?? 0), $days, $loads, $flags, $depot, $crew, $this->today, $needMin);
            if ($r['best'] === null && (float)($l['lat'] ?? 0) == 0.0) $r['none'] = array_key_exists('has_address', $l) && !$l['has_address'] ? 'no address yet — ask for it' : 'no map pin yet';
            $out[(int)$l['id']] = $r;
        }
        return $out;
    }
}
