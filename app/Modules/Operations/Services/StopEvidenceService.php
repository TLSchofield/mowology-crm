<?php
/**
 * StopEvidenceService — Otto asks Penny what a truck stop was, instead of asking Tim.
 *
 * Penny's evidence for a stop, strongest first:
 *   (a) printed_time   a receipt whose PRINTED time (Time In / Time Out on a scale ticket, the till
 *                      time) falls inside the stop window (± WINDOW_SLACK_S), same date. A ticket
 *                      with Time In and Time Out also gives the exact minutes on site — preferred
 *                      over GPS ("12 min (scale ticket)").
 *   (b) photo_at_stop  a receipt the crew photographed while the truck was still stopped there,
 *                      receipt_lat/lng within NEAR_M of the stop.
 *   (d) vendor_location a vendor store location Penny already has coordinates for (vendor_locations:
 *                      typed in, or learned from 2+ receipts — migration 1124) within NEAR_M.
 *   (e) history        learnPlaces(): the vendor's past receipts on days the truck has GPS, each
 *                      matched to a truck stop by (a) or (b); two or more visits on different days
 *                      at the same spot = that vendor is there → ops_places row (source 'penny').
 *   (c) bank_line      a card / debit charge on the bank feed that day whose payee is a supplier and
 *                      isn't another stop of the day — WEAK: offered on Otto's card as Yes / No.
 * Strong evidence (a, b, d, e) on an unnamed stop creates the ops_places row itself when the
 * vendor's kind is dump / supplier / fuel. Nothing found → the stop stays open and is re-checked on
 * every card load and cron run (receipts and bank lines arrive later).
 *
 * No address geocoding: no server-side geocoder exists in the codebase, so (d) only uses vendor
 * locations that already have lat/lng.
 * created_at / printed times / pings are all LOCAL (America/Vancouver) strings.
 *
 * Everything static is pure and unit tested; the instance methods only load and write rows.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/TripSegmentService.php';

class StopEvidenceService
{
    /** A printed time this far outside the GPS window still counts (pings are ~2 min apart). */
    public const WINDOW_SLACK_S = 600;
    /** A receipt photo taken within this of the stop, while the truck was there. */
    public const NEAR_M = 150;
    /** created_at slack around the stop window for a photo at the stop. */
    public const PHOTO_SLACK_S = 120;
    /** A scale ticket longer than this is a misread. */
    public const TICKET_MAX_S = 10800;
    /** Learned spots: distinct days a vendor must line up with the same truck stop. */
    public const LEARN_MIN_VISITS = 2;
    /** A spot 3+ different vendors "are at" is somewhere receipts get photographed, not a store. */
    public const SHARED_SPOT_VENDORS = 3;
    public const LEARN_CATEGORIES = ['Materials', 'Disposal/Dump', 'Fuel', 'Tools/Equipment'];
    public const PLACE_KINDS = ['dump', 'supplier', 'fuel'];

    private PDO $db;
    public TripSegmentService $seg;
    private ?bool $ready1217 = null;
    private array $resolved = [];

    public function __construct(PDO $db, ?TripSegmentService $seg = null)
    {
        $this->db = $db;
        $this->seg = $seg ?? new TripSegmentService($db);
    }

    /** Migration 1217 has run (ops_places.source / vendor_id / evidence, ops_trip_runs.evidence). */
    public function ready1217(): bool
    {
        if ($this->ready1217 !== null) return $this->ready1217;
        try {
            $this->ready1217 = $this->db->query("SHOW COLUMNS FROM ops_places LIKE 'vendor_id'")->rowCount() > 0
                && $this->db->query("SHOW COLUMNS FROM ops_trip_runs LIKE 'onsite_basis'")->rowCount() > 0;
        } catch (Throwable $e) {
            $this->ready1217 = false;
        }
        return $this->ready1217;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure helpers (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Raw OCR as stored (plain text, or Vision JSON) → text. Same rules as ocrTextFromStored(). */
    public static function ocrText(?string $raw): string
    {
        if ($raw === null) return '';
        $t = ltrim($raw);
        if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) return $raw;
        $d = json_decode($t, true);
        if (!is_array($d)) return '';
        if (!empty($d['fullTextAnnotation']['text'])) return (string)$d['fullTextAnnotation']['text'];
        if (!empty($d['responses'][0]['fullTextAnnotation']['text'])) return (string)$d['responses'][0]['fullTextAnnotation']['text'];
        if (!empty($d['text']) && is_string($d['text'])) return $d['text'];
        return '';
    }

    /** "09:49", "9:49 AM", "1:15" (no am/pm, before 6 = afternoon) → 'H:i', or null. */
    private static function hm(string $h, string $m, ?string $ampm): ?string
    {
        $h = (int)$h; $m = (int)$m;
        if ($m > 59) return null;
        if ($ampm !== null && $ampm !== '') {
            if ($h < 1 || $h > 12) return null;
            $pm = stripos($ampm, 'p') !== false;
            $h = $h % 12 + ($pm ? 12 : 0);
        } else {
            if ($h > 23) return null;
            if ($h < 6) $h += 12;   // nobody buys mulch at 3 am: an un-suffixed 1:15 is 13:15
        }
        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * Printed times and dates on a receipt.
     * @return array{times: list<string>, in: ?string, out: ?string, dates: list<string>}
     *   times 'H:i' (every time printed, in/out included), dates 'Y-m-d' candidates (MM/DD and DD/MM)
     */
    public static function parseTimes(string $text): array
    {
        $tm = '(\d{1,2})[:.](\d{2})(?::\d{2})?\s*([AaPp]\.?\s?[Mm]\.?)?';
        $in = $out = null;
        if (preg_match('/time\s*in\b\s*[:\-]?\s*' . $tm . '/i', $text, $m)) $in = self::hm($m[1], $m[2], $m[3] ?? null);
        if (preg_match('/time\s*out\b\s*[:\-]?\s*' . $tm . '/i', $text, $m)) $out = self::hm($m[1], $m[2], $m[3] ?? null);
        $times = [];
        if (preg_match_all('/(?<![\d:])(\d{1,2}):(\d{2})(?::\d{2})?(?![\d:])\s*([AaPp]\.?\s?[Mm]\.?(?![a-z]))?/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $t = self::hm($m[1], $m[2], $m[3] ?? null);
                if ($t !== null) $times[$t] = true;
            }
        }
        foreach ([$in, $out] as $t) if ($t !== null) $times[$t] = true;

        $dates = [];
        $add = function (int $y, int $mo, int $d) use (&$dates) {
            if ($y < 100) $y += 2000;
            if (checkdate($mo, $d, $y)) $dates[sprintf('%04d-%02d-%02d', $y, $mo, $d)] = true;
        };
        if (preg_match_all('/\b(20\d{2})[\-\/.](\d{1,2})[\-\/.](\d{1,2})\b/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) $add((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match_all('/(?<![\d\/\-.])(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4}|\d{2})(?![\d\/\-])/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $add((int)$m[3], (int)$m[1], (int)$m[2]);   // MM/DD/YY
                $add((int)$m[3], (int)$m[2], (int)$m[1]);   // DD/MM/YY
            }
        }
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        if (preg_match_all('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2}),?\s+(20\d{2})\b/i', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) $add((int)$m[3], $months[strtolower($m[1])], (int)$m[2]);
        }
        $t = array_keys($times);
        sort($t);
        return ['times' => $t, 'in' => $in, 'out' => $out, 'dates' => array_keys($dates)];
    }

    /** Compare names loosely: "LAWN BOY", "Lawnboy", "Lawn-Boy" → "lawnboy". */
    public static function squash(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    /**
     * What kind of place a vendor is, or null when it isn't a place Otto prices.
     * Name wins over category for fuel: Tim books gas under $50 as equipment.
     */
    public static function kindFor(?string $category, ?string $gbp = null, string $name = ''): ?string
    {
        $n = strtolower($name);
        $c = (string)$category;
        if ($c === 'Disposal/Dump' || preg_match('/landfill|transfer station|recycl|\bdump\b|waste/', $n)) return 'dump';
        if (preg_match('/chevron|\besso\b|\bshell\b|petro[\s-]?can|husky|mobil|co-?op gas|gas bar|\bfuel\b/', $n)) return 'fuel';
        if ($c === 'Fuel') return 'fuel';
        if (in_array($c, ['Materials', 'Tools/Equipment'], true)) return 'supplier';
        if (preg_match('/nursery|garden|hardware|building material|landscap/i', (string)$gbp)) return 'supplier';
        if (preg_match('/landscap|supply|supplies|nursery|soil|mulch|garden|lumber|home depot|\brona\b|lawn ?boy|bark|aggregate|gravel/', $n)) return 'supplier';
        return null;
    }

    /** A receipt row (expenses + vendor) → the facts Otto uses. */
    public static function receiptFacts(array $r, string $date): array
    {
        $name = trim((string)($r['vendor'] ?? '')) ?: trim((string)($r['vendor_name_raw'] ?? ''));
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $p = self::parseTimes(self::ocrText(isset($r['raw_ocr_json']) ? (string)$r['raw_ocr_json'] : null));
        // A printed date that isn't this day means the times aren't this day's either.
        $dateOk = !$p['dates'] || in_array($date, $p['dates'], true);
        $ts = fn(?string $hm) => $hm === null ? null : strtotime($date . ' ' . $hm . ':00');
        $created = isset($r['created_at']) && $r['created_at'] ? strtotime((string)$r['created_at']) : null;
        return [
            'id'         => (int)$r['id'],
            'vendor_id'  => isset($r['vendor_id']) && $r['vendor_id'] ? (int)$r['vendor_id'] : null,
            'name'       => mb_substr($name, 0, 120),
            'category'   => (string)($r['accounting_category'] ?? ''),
            'kind'       => self::kindFor($r['accounting_category'] ?? null, $r['gbp'] ?? null, $name),
            'total'      => round((float)($r['total'] ?? 0), 2),
            'created_at' => $created ?: null,
            'created_by' => isset($r['created_by']) ? (int)$r['created_by'] : null,
            'lat'        => is_numeric($r['receipt_lat'] ?? null) && (float)$r['receipt_lat'] != 0.0 ? (float)$r['receipt_lat'] : null,
            'lng'        => is_numeric($r['receipt_lng'] ?? null) && (float)$r['receipt_lng'] != 0.0 ? (float)$r['receipt_lng'] : null,
            'times'      => $dateOk ? array_map($ts, $p['times']) : [],
            'in'         => $dateOk ? $ts($p['in']) : null,
            'out'        => $dateOk ? $ts($p['out']) : null,
        ];
    }

    /** Seconds a time is outside [start, end] (0 inside), or null when beyond the slack. */
    public static function outside(int $t, int $start, int $end, int $slack = self::WINDOW_SLACK_S): ?int
    {
        if ($t >= $start && $t <= $end) return 0;
        $d = $t < $start ? $start - $t : $t - $end;
        return $d <= $slack ? $d : null;
    }

    /** A stop a receipt can belong to: a named place that isn't the yard, or an unnamed stop. */
    private static function receiptStop(array $s): bool
    {
        $l = $s['label'];
        return $l['type'] === 'unnamed' || ($l['type'] === 'place' && $l['kind'] !== 'yard');
    }

    /** Scale ticket: Time In and Time Out both printed, in order, under 3 h. */
    public static function ticket(array $rc): ?array
    {
        if ($rc['in'] === null || $rc['out'] === null || $rc['out'] <= $rc['in'] || $rc['out'] - $rc['in'] > self::TICKET_MAX_S) return null;
        return ['in' => $rc['in'], 'out' => $rc['out'], 'minutes' => round(($rc['out'] - $rc['in']) / 60, 1)];
    }

    /**
     * Each receipt to the one stop it is evidence for: (a) printed time, else (b) photo at the stop.
     * @param list<array> $stops    stop segments (TripSegmentService::segments, type 'stop')
     * @param list<array> $receipts receiptFacts()
     * @return array<int, list<array>> [stop start => evidence list, tickets first]
     *   evidence: {basis, strength, receipt_id, vendor_id, name, kind, total, printed: ?int, ticket: ?array}
     */
    public static function assignReceipts(array $stops, array $receipts): array
    {
        $out = [];
        foreach ($receipts as $rc) {
            $best = null; $basis = null;
            $times = $rc['in'] !== null || $rc['out'] !== null ? array_values(array_filter([$rc['in'], $rc['out']])) : $rc['times'];
            foreach ($stops as $s) {
                if (!self::receiptStop($s)) continue;
                foreach ($times as $t) {
                    $d = self::outside($t, $s['start'], $s['end']);
                    if ($d !== null && ($best === null || $d < $best[0])) $best = [$d, $s, $t];
                }
            }
            if ($best) {
                $basis = 'printed_time';
            } elseif ($rc['created_at'] !== null && $rc['lat'] !== null) {
                foreach ($stops as $s) {
                    if (!self::receiptStop($s)) continue;
                    if (self::outside($rc['created_at'], $s['start'], $s['end'], self::PHOTO_SLACK_S) === null) continue;
                    $m = TripSegmentService::meters($rc['lat'], $rc['lng'], (float)$s['lat'], (float)$s['lng']);
                    if ($m <= self::NEAR_M && ($best === null || $m < $best[0])) $best = [$m, $s, null];
                }
                if ($best) $basis = 'photo_at_stop';
            }
            if (!$best) continue;
            $tk = $basis === 'printed_time' ? self::ticket($rc) : null;
            $out[$best[1]['start']][] = [
                'basis' => $basis, 'strength' => 'strong', 'receipt_id' => $rc['id'], 'vendor_id' => $rc['vendor_id'],
                'name' => $rc['name'], 'kind' => $rc['kind'], 'total' => $rc['total'],
                'printed' => $basis === 'printed_time' ? $best[2] : null, 'ticket' => $tk,
            ];
        }
        foreach ($out as &$list) {
            usort($list, fn($a, $b) => [$a['ticket'] === null, $a['basis'] !== 'printed_time', $a['receipt_id']]
                <=> [$b['ticket'] === null, $b['basis'] !== 'printed_time', $b['receipt_id']]);
        }
        unset($list);
        return $out;
    }

    /**
     * (d) the nearest vendor store Penny has coordinates for. Learned spots count from 2 receipts.
     * @param list<array{vendor_id, name, category, gbp, lat, lng, source, receipts_seen}> $spots
     */
    public static function vendorSpotFor(float $lat, float $lng, array $spots): ?array
    {
        $best = null;
        foreach ($spots as $v) {
            if (!is_numeric($v['lat'] ?? null) || !is_numeric($v['lng'] ?? null)) continue;
            if (($v['source'] ?? null) === 'learned' && (int)($v['receipts_seen'] ?? 0) < 2) continue;
            $kind = self::kindFor($v['category'] ?? null, $v['gbp'] ?? null, (string)$v['name']);
            if ($kind === null) continue;
            $m = TripSegmentService::meters($lat, $lng, (float)$v['lat'], (float)$v['lng']);
            if ($m <= self::NEAR_M && ($best === null || $m < $best[0])) $best = [$m, $v, $kind];
        }
        if (!$best) return null;
        return ['basis' => 'vendor_location', 'strength' => 'strong', 'receipt_id' => null, 'vendor_id' => (int)$best[1]['vendor_id'],
                'name' => (string)$best[1]['name'], 'kind' => $best[2], 'total' => null, 'printed' => null, 'ticket' => null];
    }

    /** "Point of sale LAWN BOY #2 VANCOUVER" → "LAWN BOY #2 VANCOUVER" */
    public static function payee(string $description): string
    {
        $d = trim(preg_replace('/\s+/', ' ', $description));
        for ($i = 0; $i < 3; $i++) {
            $d = preg_replace('/^(point of sale|pos|interac|purchase|debit|visa|mastercard|mc|card|retail|-|:)\b[\s\-:]*/i', '', $d);
        }
        return trim($d, " -:");
    }

    /**
     * (c) a supplier charge on the bank feed that day — WEAK.
     * @param list<array{id, amount, description, vendor_id}> $lines
     * @param list<array{id, name, aliases, category, gbp}>   $vendors
     * @param string[] $taken   names of other stops that day (places, strong evidence) — a charge from one of them isn't this stop
     * @param int[]    $usedLines bank line ids already offered for another stop
     */
    public static function bankGuess(array $lines, array $vendors, array $taken, array $usedLines = []): ?array
    {
        $takenSq = array_values(array_filter(array_map([self::class, 'squash'], $taken), fn($s) => strlen($s) >= 4));
        foreach ($lines as $ln) {
            if (in_array((int)$ln['id'], $usedLines, true)) continue;
            $payee = self::payee((string)$ln['description']);
            $pSq = self::squash($payee);
            if ($pSq === '') continue;
            $hit = null;
            foreach ($vendors as $v) {
                if ($ln['vendor_id'] && (int)$ln['vendor_id'] === (int)$v['id']) { $hit = $v; break; }
                $names = array_merge([(string)$v['name']], array_map('trim', explode(',', (string)($v['aliases'] ?? ''))));
                foreach ($names as $n) {
                    $nSq = self::squash($n);
                    if (strlen($nSq) >= 4 && strpos($pSq, $nSq) !== false) { $hit = $v; break 2; }
                }
            }
            $name = $hit ? (string)$hit['name'] : $payee;
            $kind = $hit ? self::kindFor($hit['category'] ?? null, $hit['gbp'] ?? null, $name) : self::kindFor(null, null, $payee);
            if ($kind !== 'supplier') continue;
            $nSq = self::squash($name);
            if ($nSq === '') continue;
            foreach ($takenSq as $t) {
                if (strpos($pSq, $t) !== false || strpos($t, $nSq) !== false || strpos($nSq, $t) !== false) continue 2;
            }
            return ['basis' => 'bank_line', 'strength' => 'weak', 'receipt_id' => null, 'txn_id' => (int)$ln['id'],
                    'vendor_id' => $hit ? (int)$hit['id'] : null, 'name' => mb_substr($name, 0, 120), 'kind' => 'supplier',
                    'total' => round((float)$ln['amount'], 2), 'printed' => null, 'ticket' => null];
        }
        return null;
    }

    /** The owner already said "No" to this name at this spot. */
    public static function rejected(float $lat, float $lng, string $name, array $rejections): bool
    {
        $sq = self::squash($name);
        foreach ($rejections as $r) {
            if (self::squash((string)$r['name']) === $sq && TripSegmentService::meters($lat, $lng, (float)$r['lat'], (float)$r['lng']) <= self::NEAR_M) return true;
        }
        return false;
    }

    /**
     * The day's evidence.
     * @return array{stops: array<int, list<array>>, strong: array<int, array>, weak: array<int, array>}
     *   stops:  [stop start => receipt evidence] for every receipt-able stop (named or not)
     *   strong: [stop start => the evidence that names an unnamed stop]
     *   weak:   [stop start => bank-line guess] for unnamed stops of 5+ min with nothing strong
     */
    public static function evaluate(array $segments, array $receipts, array $vendorSpots = [], array $bankLines = [], array $vendors = [], array $rejections = []): array
    {
        $stops = array_values(array_filter($segments, fn($s) => $s['type'] === 'stop'));
        $byStop = self::assignReceipts($stops, $receipts);
        $strong = [];
        $taken = [];
        foreach ($stops as $s) {
            if ($s['label']['type'] === 'place') $taken[] = $s['label']['name'];
            if ($s['label']['type'] !== 'unnamed') continue;
            $ev = null;
            foreach ($byStop[$s['start']] ?? [] as $e) {
                if (in_array($e['kind'], self::PLACE_KINDS, true) && $e['name'] !== '') { $ev = $e; break; }
            }
            if (!$ev) $ev = self::vendorSpotFor((float)$s['lat'], (float)$s['lng'], $vendorSpots);
            if ($ev) { $strong[$s['start']] = $ev; $taken[] = $ev['name']; }
        }
        // Every receipt of the day is "another stop" for the bank guess: a charge Penny already has a receipt for is explained.
        foreach ($receipts as $rc) if ($rc['name'] !== '') $taken[] = $rc['name'];
        $weak = [];
        $used = [];
        foreach ($stops as $s) {
            if ($s['label']['type'] !== 'unnamed' || isset($strong[$s['start']])) continue;
            if ($s['end'] - $s['start'] < TripSegmentService::UNNAMED_MIN_SECONDS) continue;
            $g = self::bankGuess($bankLines, $vendors, $taken, $used);
            while ($g && self::rejected((float)$s['lat'], (float)$s['lng'], $g['name'], $rejections)) {
                $used[] = $g['txn_id'];
                $g = self::bankGuess($bankLines, $vendors, $taken, $used);
            }
            if ($g) { $weak[$s['start']] = $g; $used[] = $g['txn_id']; }
        }
        return ['stops' => $byStop, 'strong' => $strong, 'weak' => $weak];
    }

    /**
     * (e) Where each vendor is, from past visits: observations of "this vendor's receipt lined up
     * with a truck stop here", clustered per vendor. A cluster with LEARN_MIN_VISITS distinct days is
     * that vendor's place; a spot SHARED_SPOT_VENDORS+ vendors claim is skipped.
     * @param list<array{vendor_id: ?int, name: string, kind: string, lat: float, lng: float, date: string, receipt_id: int}> $obs
     * @return list<array{vendor_id: ?int, name: string, kind: string, lat: float, lng: float, visits: int, receipt_ids: int[]}>
     */
    public static function clusterObservations(array $obs, int $minVisits = self::LEARN_MIN_VISITS): array
    {
        $clusters = [];   // vendor key => list of {lat, lng, n, dates, ids, name, kind, vendor_id}
        foreach ($obs as $o) {
            $key = $o['vendor_id'] ? 'v' . $o['vendor_id'] : 'n' . self::squash($o['name']);
            $hit = false;
            foreach ($clusters[$key] ?? [] as $i => $c) {
                if (TripSegmentService::meters($c['lat'], $c['lng'], (float)$o['lat'], (float)$o['lng']) <= self::NEAR_M) {
                    $n = $c['n'] + 1;
                    $clusters[$key][$i]['lat'] = ($c['lat'] * $c['n'] + (float)$o['lat']) / $n;
                    $clusters[$key][$i]['lng'] = ($c['lng'] * $c['n'] + (float)$o['lng']) / $n;
                    $clusters[$key][$i]['n'] = $n;
                    $clusters[$key][$i]['dates'][$o['date']] = true;
                    $clusters[$key][$i]['ids'][] = (int)$o['receipt_id'];
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $clusters[$key][] = ['lat' => (float)$o['lat'], 'lng' => (float)$o['lng'], 'n' => 1, 'dates' => [$o['date'] => true],
                    'ids' => [(int)$o['receipt_id']], 'name' => $o['name'], 'kind' => $o['kind'], 'vendor_id' => $o['vendor_id']];
            }
        }
        $found = [];
        foreach ($clusters as $list) {
            foreach ($list as $c) {
                if (count($c['dates']) >= $minVisits) $found[] = $c;
            }
        }
        // Spots several vendors claim (where receipts get photographed) are not stores.
        $all = [];
        foreach ($clusters as $key => $list) foreach ($list as $c) $all[] = $c + ['key' => $key];
        $out = [];
        foreach ($found as $c) {
            $keys = [];
            foreach ($all as $a) {
                if (TripSegmentService::meters($c['lat'], $c['lng'], $a['lat'], $a['lng']) <= self::NEAR_M) $keys[$a['key']] = true;
            }
            if (count($keys) >= self::SHARED_SPOT_VENDORS) continue;
            $out[] = ['vendor_id' => $c['vendor_id'], 'name' => $c['name'], 'kind' => $c['kind'], 'lat' => round($c['lat'], 7), 'lng' => round($c['lng'], 7),
                      'visits' => count($c['dates']), 'receipt_ids' => $c['ids']];
        }
        usort($out, fn($a, $b) => $b['visits'] <=> $a['visits']);
        return $out;
    }

    /** "scale ticket #411 9:49–10:01", "receipt #412 printed 10:18", … */
    public static function evidenceLine(?array $e, bool $ticketTimes = true): string
    {
        if (!$e) return '';
        $hm = fn(int $t) => date('g:i', $t);
        switch ($e['basis']) {
            case 'printed_time':
                if (!empty($e['ticket'])) {
                    return ($e['kind'] === 'dump' ? 'scale ticket' : 'ticket') . ' #' . $e['receipt_id']
                        . ($ticketTimes ? ' ' . $hm($e['ticket']['in']) . '–' . $hm($e['ticket']['out']) : '');
                }
                return 'receipt #' . $e['receipt_id'] . ' printed ' . $hm((int)$e['printed']);
            case 'photo_at_stop':
                return 'receipt #' . $e['receipt_id'] . ' photographed here';
            case 'vendor_location':
                return 'Penny has this store on file';
            case 'history':
                return $e['visits'] . ' past receipts line up here';
            case 'bank_line':
                return 'card charge $' . number_format((float)$e['total'], 2);
        }
        return '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Loaders
    // ─────────────────────────────────────────────────────────────────────────

    private const RECEIPT_SELECT = "
        SELECT e.id, e.expense_date, e.vendor_id, COALESCE(v.name, '') AS vendor, e.vendor_name_raw, e.accounting_category,
               v.default_gbp_category AS gbp, e.total, e.created_at, e.created_by, e.receipt_lat, e.receipt_lng, e.raw_ocr_json
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
    ";

    /** That day's non-rejected receipts as receiptFacts(). */
    public function receipts(string $date): array
    {
        try {
            $s = $this->db->prepare(self::RECEIPT_SELECT . " WHERE e.expense_date = ? AND e.status <> 'rejected' ORDER BY e.id");
            $s->execute([$date]);
            return array_map(fn($r) => self::receiptFacts($r, $date), $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('StopEvidence receipts: ' . $e->getMessage());
            return [];
        }
    }

    /** Vendor store locations with coordinates. */
    public function vendorSpots(): array
    {
        try {
            $learned = $this->db->query("SHOW COLUMNS FROM vendor_locations LIKE 'receipts_seen'")->rowCount() > 0;
            return $this->db->query("
                SELECT vl.vendor_id, v.name, v.default_accounting_category AS category, v.default_gbp_category AS gbp, vl.lat, vl.lng, "
                . ($learned ? "vl.source, vl.receipts_seen" : "NULL AS source, 0 AS receipts_seen") . "
                FROM vendor_locations vl JOIN vendors v ON v.id = vl.vendor_id
                WHERE vl.lat IS NOT NULL AND vl.lng IS NOT NULL AND COALESCE(v.is_active, 1) = 1
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('StopEvidence vendorSpots: ' . $e->getMessage());
            return [];
        }
    }

    public function vendors(): array
    {
        try {
            return $this->db->query("
                SELECT id, name, aliases, default_accounting_category AS category, default_gbp_category AS gbp
                FROM vendors WHERE COALESCE(is_active, 1) = 1
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** That day's money-out lines from the bank feed. */
    public function bankLines(string $date): array
    {
        try {
            $s = $this->db->prepare("
                SELECT id, amount, description, vendor_id FROM accounting_transactions
                WHERE reference_type = 'bank_import' AND type = 'expense' AND transaction_date = ?
                ORDER BY id
            ");
            $s->execute([$date]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('StopEvidence bankLines: ' . $e->getMessage());
            return [];
        }
    }

    public function rejections(): array
    {
        try {
            return $this->db->query("SELECT lat, lng, name FROM ops_place_rejections")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // migration 1217 not run
        }
    }

    /** Places Penny named: [place id => evidence text]. */
    public function pennyPlaces(): array
    {
        if (!$this->ready1217()) return [];
        try {
            $out = [];
            foreach ($this->db->query("SELECT id, source, evidence FROM ops_places WHERE source IN ('penny', 'confirmed')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['id']] = ['source' => (string)$r['source'], 'evidence' => (string)($r['evidence'] ?? '')];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Writing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * New ops_places row (radius 150) unless the spot is already a place. A name already used
     * elsewhere (a second Home Depot) gets " 2", " 3".
     * @return ?int the new place id, or null when the spot is already known
     */
    public function createPlace(string $name, string $kind, float $lat, float $lng, ?int $vendorId, string $evidence, string $source = 'penny', ?int $userId = null): ?int
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '' || !in_array($kind, ['dump', 'supplier', 'yard', 'fuel', 'other'], true)) return null;
        foreach ($this->seg->places() as $p) {
            if (TripSegmentService::meters($lat, $lng, (float)$p['lat'], (float)$p['lng']) <= (float)$p['radius_m']) return null;
        }
        $match = strtolower($name);
        if ($vendorId) {
            try {
                $s = $this->db->prepare("SELECT name, aliases FROM vendors WHERE id = ?");
                $s->execute([$vendorId]);
                if ($v = $s->fetch(PDO::FETCH_ASSOC)) {
                    $words = array_merge([(string)$v['name']], explode(',', (string)($v['aliases'] ?? '')));
                    $words = array_values(array_unique(array_filter(array_map(fn($w) => strtolower(trim($w)), $words), fn($w) => strlen($w) >= 3)));
                    if ($words) $match = mb_substr(implode('|', $words), 0, 255);
                }
            } catch (Throwable $e) { /* name only */ }
        }
        for ($n = 1; $n <= 9; $n++) {
            $try = mb_substr($n === 1 ? $name : $name . ' ' . $n, 0, 120);
            try {
                if ($this->ready1217()) {
                    $this->db->prepare("INSERT INTO ops_places (name, kind, lat, lng, radius_m, vendor_match, source, vendor_id, evidence, created_by) VALUES (?, ?, ?, ?, 150, ?, ?, ?, ?, ?)")
                             ->execute([$try, $kind, round($lat, 7), round($lng, 7), $match, $source, $vendorId, mb_substr($evidence, 0, 255), $userId]);
                } else {
                    $this->db->prepare("INSERT INTO ops_places (name, kind, lat, lng, radius_m, vendor_match, created_by) VALUES (?, ?, ?, ?, 150, ?, ?)")
                             ->execute([$try, $kind, round($lat, 7), round($lng, 7), $match, $userId]);
                }
                return (int)$this->db->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
            }
        }
        return null;
    }

    public function reject(string $date, float $lat, float $lng, string $name, ?int $userId): bool
    {
        try {
            $this->db->prepare("INSERT INTO ops_place_rejections (stop_date, lat, lng, name, created_by) VALUES (?, ?, ?, ?, ?)")
                     ->execute([$date, round($lat, 7), round($lng, 7), mb_substr(trim($name), 0, 120), $userId]);
            return true;
        } catch (Throwable $e) {
            error_log('StopEvidence reject: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The day
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ask Penny about a day: strong evidence names unnamed stops (creates places), weak evidence is
     * returned as proposals. Cached per date for the request.
     * @return array{stops: array, weak: array, created: list<array>, penny_places: array}
     */
    public function resolveDay(string $date): array
    {
        if (isset($this->resolved[$date])) return $this->resolved[$date];
        $empty = ['stops' => [], 'weak' => [], 'created' => [], 'penny_places' => []];
        $pings = $this->seg->pings($date);
        if (!$pings) return $this->resolved[$date] = $empty;
        $props = $this->seg->propertiesNear($pings);
        $receipts = $this->receipts($date);
        $spots = $this->vendorSpots();
        $bank = $this->bankLines($date);
        $vendors = $bank ? $this->vendors() : [];
        $rej = $this->rejections();

        $segments = TripSegmentService::segments($pings, $props, $this->seg->places());
        $ev = self::evaluate($segments, $receipts, $spots, $bank, $vendors, $rej);
        $created = [];
        foreach ($ev['strong'] as $start => $e) {
            $seg = null;
            foreach ($segments as $s) if ($s['type'] === 'stop' && $s['start'] === $start) $seg = $s;
            if (!$seg) continue;
            $id = $this->createPlace($e['name'], $e['kind'], (float)$seg['lat'], (float)$seg['lng'], $e['vendor_id'],
                self::evidenceLine($e) . ' (' . $date . ' ' . date('H:i', $start) . ')');
            if ($id) $created[] = ['place_id' => $id, 'name' => $e['name'], 'kind' => $e['kind'], 'start' => $start, 'evidence' => $e];
        }
        if ($created) {
            $segments = TripSegmentService::segments($pings, $props, $this->seg->places());
            $ev = self::evaluate($segments, $receipts, $spots, $bank, $vendors, $rej);
        }
        return $this->resolved[$date] = ['stops' => $ev['stops'], 'weak' => $ev['weak'], 'created' => $created, 'penny_places' => $this->pennyPlaces()];
    }

    /** Time-matched receipt evidence for a stored run window (Penny's re-check), or null. */
    public function receiptForWindow(string $date, int $arrived, int $departed, float $lat, float $lng): ?array
    {
        $stop = ['type' => 'stop', 'start' => $arrived, 'end' => $departed, 'lat' => $lat, 'lng' => $lng,
                 'label' => ['type' => 'unnamed', 'id' => null, 'name' => '', 'kind' => null]];
        $by = self::assignReceipts([$stop], $this->receipts($date));
        return $by[$arrived][0] ?? null;
    }

    /**
     * (e) Walk the last $days days of supplier / dump / fuel receipts, line each up with the truck
     * stop it came from, and create a place for every vendor seen at the same spot on 2+ days.
     * @return array{days: int, receipts: int, observations: int, created: list<array>}
     */
    public function learnPlaces(int $days = 120): array
    {
        $days = max(1, min(120, $days));
        $from = date('Y-m-d', strtotime("-{$days} days"));
        $in = implode(',', array_fill(0, count(self::LEARN_CATEGORIES), '?'));
        $s = $this->db->prepare(self::RECEIPT_SELECT . "
            WHERE e.expense_date >= ? AND e.status <> 'rejected' AND e.accounting_category IN ({$in})
            ORDER BY e.expense_date, e.id
        ");
        $s->execute(array_merge([$from], self::LEARN_CATEGORIES));
        $byDate = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $byDate[(string)$r['expense_date']][] = $r;

        $obs = [];
        $nReceipts = 0; $nDays = 0;
        $places = $this->seg->places();
        foreach ($byDate as $date => $rows) {
            $pings = $this->seg->pings($date);
            if (!$pings) continue;
            $nDays++;
            $receipts = array_map(fn($r) => self::receiptFacts($r, $date), $rows);
            $nReceipts += count($receipts);
            $segments = TripSegmentService::segments($pings, $this->seg->propertiesNear($pings), $places);
            $stops = [];
            foreach ($segments as $sg) if ($sg['type'] === 'stop') $stops[$sg['start']] = $sg;
            foreach (self::assignReceipts(array_values($stops), $receipts) as $start => $list) {
                $st = $stops[$start];
                if ($st['label']['type'] !== 'unnamed') continue;   // already a place
                foreach ($list as $e) {
                    if (!in_array($e['kind'], self::PLACE_KINDS, true) || $e['name'] === '') continue;
                    $obs[] = ['vendor_id' => $e['vendor_id'], 'name' => $e['name'], 'kind' => $e['kind'], 'lat' => (float)$st['lat'],
                              'lng' => (float)$st['lng'], 'date' => $date, 'receipt_id' => $e['receipt_id']];
                }
            }
        }
        $created = [];
        foreach (self::clusterObservations($obs) as $c) {
            $evidence = $c['visits'] . ' past receipts line up here (#' . implode(', #', array_slice($c['receipt_ids'], 0, 6)) . ')';
            $id = $this->createPlace($c['name'], $c['kind'], $c['lat'], $c['lng'], $c['vendor_id'], $evidence);
            if ($id) $created[] = ['place_id' => $id, 'name' => $c['name'], 'kind' => $c['kind'], 'visits' => $c['visits']];
        }
        $this->resolved = [];
        return ['days' => $nDays, 'receipts' => $nReceipts, 'observations' => count($obs), 'created' => $created];
    }
}
