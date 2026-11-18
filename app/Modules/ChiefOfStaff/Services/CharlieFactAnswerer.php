<?php
/**
 * CharlieFactAnswerer — Ask Charlie answers from the other heads' data with plain SQL first.
 *
 * "How many visits to Lawnboy today?" is a count, not a judgement call, so it never goes to
 * Claude. Intent is matched with plain rules; every read is a prepared statement:
 *
 *   Otto's truck log   visits / time at a named place (ops_places, fuzzy: "lawn boy" = LAWNBOY;
 *                      or a kind: "the dump", "supplier"). Today is read live from the trail
 *                      (TripSegmentService::day), past days from ops_trip_runs.
 *   Otto's cost facts  "what does a dump run cost", "average time at <place>" (ops_cost_facts,
 *                      with the sample size; "not enough runs yet (n/3)" when thin).
 *   Penny's receipts   "what did we spend at <vendor> <period>", "how many receipts from <vendor>"
 *                      — rejected (incl. removed duplicates) and deleted receipts are left out;
 *                      approved and waiting-for-approval are shown separately.
 *   The schedule       "how many visits today", "how many visits did Nigel do today" (job_visits).
 *
 * answer() returns null whenever it isn't sure — CharlieAskService then takes its Claude path,
 * and summary() hands Claude the same counts and totals (never raw rows) so it can reason over
 * them. Neither path lets the model write SQL. Answers end with where they came from.
 *
 * Periods: today, yesterday, this/last week (Mon–Sun), this/last month, this/last year, in
 * <month>, on/since <date>, on <weekday> — Vancouver local dates.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 2) . '/Operations/Services/TripSegmentService.php';
require_once dirname(__DIR__, 2) . '/Operations/Services/CostFactsService.php';

class CharlieFactAnswerer
{
    public const TZ = 'America/Vancouver';
    /** One-man runs needed before a cost fact is quoted (as Sam's TripLineSuggester). */
    public const MIN_SAMPLE = 3;
    /** Past days of a non-overhead place (yard, fuel) are re-read from the trail, at most this many. */
    public const LIVE_DAYS_MAX = 7;
    /** List each visit when there are at most this many; otherwise summarise. */
    public const LIST_MAX = 6;

    public const FROM = [
        'otto'     => "Otto's truck log",
        'facts'    => "Otto's cost facts",
        'penny'    => "Penny's receipts",
        'schedule' => 'the schedule',
        'products' => "Penny's products",      // ProductFactAnswerer
        'equipment' => "Otto's equipment",     // ProductFactAnswerer
        'sam'      => "Sam's mulch pricing",   // MulchPricingService
    ];

    /** Words that never name a place, vendor or person (function words, intents, periods). */
    public const NOISE = [
        'a', 'an', 'the', 'to', 'at', 'in', 'on', 'of', 'for', 'from', 'by', 'with', 'and', 'or', 'is', 'was', 'were', 'are', 'be', 'been',
        'how', 'many', 'much', 'what', 'when', 'where', 'who', 'did', 'does', 'do', 'done', 'has', 'have', 'had', 'we', 'us', 'our', 'i', 'me',
        'my', 'there', 'they', 'he', 'she', 'it', 'times', 'time', 'visit', 'visits', 'visited', 'trip', 'trips', 'run', 'runs', 'go', 'goes',
        'went', 'gone', 'going', 'stop', 'stops', 'stopped', 'spend', 'spent', 'spending', 'receipt', 'receipts', 'cost', 'costs', 'costed',
        'average', 'avg', 'usually', 'typically', 'long', 'today', 'yesterday', 'this', 'last', 'week', 'month', 'year', 'since', 'so', 'far',
        'all', 'total', 'often', 'buy', 'bought', 'paid', 'pay', 'charlie', 'dump', 'supplier', 'suppliers', 'supply', 'yard', 'fuel', 'gas',
        'jan', 'january', 'feb', 'february', 'mar', 'march', 'apr', 'april', 'may', 'jun', 'june', 'jul', 'july', 'aug', 'august', 'sep',
        'sept', 'september', 'oct', 'october', 'nov', 'november', 'dec', 'december', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday',
        'saturday', 'sunday', 'get', 'got', 'take', 'takes', 'took', 'whats', 'hows', 'please', 'tell', 'show', 'again',
    ];

    /** Kind words → ops_places.kind */
    public const KIND_WORDS = [
        'dump' => 'dump', 'dumps' => 'dump', 'landfill' => 'dump', 'transferstation' => 'dump',
        'supplier' => 'supplier', 'suppliers' => 'supplier', 'supplyrun' => 'supplier', 'supplyruns' => 'supplier',
        'yard' => 'yard', 'fuel' => 'fuel', 'gasstation' => 'fuel',
    ];
    public const KIND_LABEL = ['dump' => 'the dump', 'supplier' => 'a supplier', 'yard' => 'the yard', 'fuel' => 'a fuel stop', 'other' => 'that place'];
    public const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    public const WEEKDAYS = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];

    private PDO $db;
    private ?string $today;
    private ?TripSegmentService $seg;
    private ?array $placesCache = null;
    private ?array $usersCache = null;
    private ?array $vendorsCache = null;

    public function __construct(PDO $db, ?string $today = null, ?TripSegmentService $seg = null)
    {
        $this->db = $db;
        $this->today = $today;
        $this->seg = $seg;
    }

    public function today(): string
    {
        if ($this->today !== null) return $this->today;
        return (new DateTime('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    private function seg(): TripSegmentService
    {
        if (!$this->seg) $this->seg = new TripSegmentService($this->db);
        return $this->seg;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Entry points
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A deterministic answer, or null to fall through to Claude.
     * @return array{answer: string, head: string, about: string}|null
     */
    public function answer(string $question): ?array
    {
        $q = self::norm($question);
        $tokens = self::tokens($q);
        if (!$tokens) return null;
        // "What should I charge for mulch at <address / postcode>" (Sam's mulch pricing) — before
        // product costs, which would otherwise answer "price of mulch" with the bare product cost.
        $mp = $this->mulchPrice($question);
        if ($mp !== null) return $mp;
        // Stock, product costs, last purchase, service due, the kit (Penny's products / Otto's equipment).
        $pf = $this->productFacts($question, $tokens);
        if ($pf !== null) return $pf;
        $today = $this->today();
        $period = self::period($q, $today);
        $intent = self::intent($q);
        if ($intent === null) return null;

        if ($intent === 'spend' || $intent === 'receipts') {
            $v = $this->findVendor($question);
            if (!$v) return null;
            $p = $period ?? self::periodOf('this year', $today);
            $sum = $this->receipts($v['term'], $p['from'], $p['to']);
            if ($sum === null) return null;
            return ['answer' => self::spendLine($intent, $v['name'], $p['label'], $sum) . self::suffix(['penny']), 'head' => 'penny', 'about' => $v['name']];
        }

        $places = $this->findPlaces($tokens);
        $crew = $this->findCrew($tokens);

        if ($intent === 'cost') {
            if (!$places) return null;
            $kind = self::sharedKind($places['places']);
            if (!in_array($kind, CostFactsService::KINDS, true)) return null;
            if ($period !== null) {
                $c = $this->runCosts($places['places'], $period['from'], $period['to']);
                if ($c === null) return null;
                return ['answer' => self::runCostLine($kind, $places['label'], $period, $c, $today) . self::suffix(['otto']), 'head' => 'otto', 'about' => $places['label']];
            }
            $fact = $this->costFact($kind, $places['places']);
            return ['answer' => self::costFactLine($kind, $places['label'], $fact) . self::suffix(['facts']), 'head' => 'otto', 'about' => $places['label']];
        }

        if ($intent === 'time') {
            if (!$places) return null;
            $kind = self::sharedKind($places['places']);
            $typical = (bool)preg_match('/\b(average|avg|usually|typically|normally|typical)\b/', $q);
            if ($typical || $period === null) {
                if (!in_array($kind, CostFactsService::KINDS, true)) return null;
                $fact = $this->costFact($kind, $places['places']);
                return ['answer' => self::timeFactLine($places['label'], $fact) . self::suffix(['facts']), 'head' => 'otto', 'about' => $places['label']];
            }
            $v = $this->placeVisits($places['places'], $period['from'], $period['to']);
            if ($v === null) return null;
            return ['answer' => self::timeLine($places['label'], $period, $v, $crew) . self::suffix(['otto']), 'head' => 'otto', 'about' => $places['label']];
        }

        if ($intent === 'visits') {
            if ($places) {
                $p = $period ?? self::periodOf('today', $today);
                $v = $this->placeVisits($places['places'], $p['from'], $p['to']);
                if ($v === null) return null;
                return ['answer' => self::visitsLine($places['label'], $p, $v, $crew, $this->names()) . self::suffix(['otto']), 'head' => 'otto', 'about' => $places['label']];
            }
            // Visits on the schedule — only when nothing else in the question names someone.
            if (!preg_match('/\b(visits?|jobs?|stops?|properties)\b/', $q) || !preg_match('/\bhow many\b/', $q)) return null;
            if (self::leftover($question, $crew ? [$crew['first']] : []) !== []) return null;
            $p = $period ?? self::periodOf('today', $today);
            $w = $this->workVisits($p['from'], $p['to'], $crew ? $crew['id'] : null);
            if ($w === null) return null;
            return ['answer' => self::workLine($p, $w, $crew, $today) . self::suffix(['schedule']), 'head' => 'otto', 'about' => $crew ? $crew['first'] : 'visits'];
        }
        return null;
    }

    /**
     * Products and equipment (ProductFactAnswerer, migration 1225 data): null when unsure, and
     * never for a product-ish question that names a known place ("what does a dump run cost").
     */
    /** Sam's installed price per yard for mulch / soil / compost at an address (MulchPricingService). */
    private function mulchPrice(string $question): ?array
    {
        $f = dirname(__DIR__, 2) . '/Sales/Services/MulchPricingService.php';
        if (!is_file($f)) return null;
        require_once $f;
        if (MulchPricingService::intent($question) === null) return null;
        try {
            return (new MulchPricingService($this->db))->answer($question);
        } catch (Throwable $e) {
            error_log('Charlie mulch price: ' . $e->getMessage());
            return null;
        }
    }

    private function productFacts(string $question, array $tokens): ?array
    {
        $f = dirname(__DIR__, 2) . '/Products/Services/ProductFactAnswerer.php';
        if (!is_file($f)) return null;
        require_once $f;
        $pi = ProductFactAnswerer::intent($question);
        if ($pi === null) return null;
        if (in_array($pi, ['cost', 'stock', 'last_bought'], true) && $this->findPlaces($tokens)) return null;
        return (new ProductFactAnswerer($this->db, $this->today()))->answer($question);
    }

    /**
     * Counts and totals for Claude when the question names a known place, vendor or a period.
     * @return array{lines: string[], heads: string[]}|null
     */
    public function summary(string $question): ?array
    {
        try {
            $q = self::norm($question);
            $tokens = self::tokens($q);
            if (!$tokens) return null;
            $today = $this->today();
            $period = self::period($q, $today);
            $places = $this->findPlaces($tokens);
            $vendor = $this->findVendor($question);
            if (!$places && !$vendor && !$period) return null;
            $p = $period ?? self::periodOf('this month', $today);
            $lines = [];
            $heads = [];
            if ($places) {
                $v = $this->placeVisits($places['places'], $p['from'], $p['to']);
                if ($v !== null) {
                    $min = array_sum(array_column($v['visits'], 'minutes'));
                    $lines[] = "Truck log (Otto): {$places['label']} {$p['label']} ({$p['from']} to {$p['to']}) — " . count($v['visits'])
                        . ' ' . self::plural(count($v['visits']), 'visit') . ", {$min} min on site in all"
                        . ($v['no_pings_today'] ? '; no truck pings today yet' : '');
                    $heads['otto'] = 1;
                }
                $kind = self::sharedKind($places['places']);
                if (in_array($kind, CostFactsService::KINDS, true)) {
                    $f = $this->costFact($kind, $places['places']);
                    if ($f) {
                        $lines[] = "Cost facts (Otto): {$f['label']} — {$f['sample_n']} one-man runs, median cost $" . self::money($f['median_cost'])
                            . ", median {$f['median_onsite_min']} min on site, {$f['median_round_trip_min']} min round trip"
                            . ($f['sample_n'] < self::MIN_SAMPLE ? ' (too few runs to rely on)' : '');
                        $heads['facts'] = 1;
                    }
                }
            }
            if ($vendor) {
                $r = $this->receipts($vendor['term'], $p['from'], $p['to']);
                if ($r !== null) {
                    $lines[] = "Receipts (Penny): {$vendor['name']} {$p['label']} — approved $" . self::money($r['approved_total']) . " ({$r['approved_n']}), waiting for approval $"
                        . self::money($r['pending_total']) . " ({$r['pending_n']})";
                    $heads['penny'] = 1;
                }
            }
            if (!$places && !$vendor) {
                $w = $this->workVisits($p['from'], $p['to'], null);
                if ($w !== null) {
                    $parts = [];
                    foreach ($w['by_status'] as $s => $n) $parts[] = "{$s} {$n}";
                    $lines[] = "Schedule: visits {$p['label']} ({$p['from']} to {$p['to']}) — " . ($parts ? implode(', ', $parts) : 'none');
                    $heads['schedule'] = 1;
                }
                $r = $this->receipts('', $p['from'], $p['to']);
                if ($r !== null) {
                    $lines[] = "Receipts (Penny): all vendors {$p['label']} — approved $" . self::money($r['approved_total']) . " ({$r['approved_n']}), waiting for approval $"
                        . self::money($r['pending_total']) . " ({$r['pending_n']})";
                    $heads['penny'] = 1;
                }
            }
            return $lines ? ['lines' => $lines, 'heads' => array_keys($heads)] : null;
        } catch (Throwable $e) {
            error_log('Charlie facts summary: ' . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lookups (prepared statements; a missing table means "not sure" → null)
    // ─────────────────────────────────────────────────────────────────────────

    public function places(): array
    {
        if ($this->placesCache !== null) return $this->placesCache;
        try {
            $rows = $this->db->query("SELECT id, name, kind, vendor_match FROM ops_places WHERE active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = [];
        }
        return $this->placesCache = $rows;
    }

    /** @return array<int, string> user id => full name */
    public function names(): array
    {
        if ($this->usersCache !== null) return $this->usersCache;
        $out = [];
        foreach (["SELECT id, full_name FROM users WHERE is_active = 1", "SELECT id, full_name FROM users"] as $sql) {
            try {
                foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = trim((string)$r['full_name']);
                break;
            } catch (Throwable $e) {
                continue;
            }
        }
        return $this->usersCache = $out;
    }

    private function vendors(): array
    {
        if ($this->vendorsCache !== null) return $this->vendorsCache;
        $rows = [];
        foreach (["SELECT id, name, aliases FROM vendors", "SELECT id, name, '' AS aliases FROM vendors"] as $sql) {
            try {
                $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                break;
            } catch (Throwable $e) {
                continue;
            }
        }
        return $this->vendorsCache = $rows;
    }

    /** @return array{places: array, label: string}|null */
    public function findPlaces(array $tokens): ?array
    {
        return self::matchPlaces($tokens, $this->places());
    }

    /** @return array{id: int, first: string, name: string}|null */
    public function findCrew(array $tokens): ?array
    {
        return self::matchCrew($tokens, $this->names());
    }

    /**
     * The vendor the question is about: a vendors row, a place's receipt words, or the words after
     * "at / from" when receipts carry them.
     * @return array{term: string, name: string}|null term is normalised (lowercase letters/digits)
     */
    public function findVendor(string $question): ?array
    {
        $q = self::norm($question);
        $tokens = self::tokens($q);
        $v = self::matchVendor($tokens, $this->vendors());
        if ($v) return $v;
        $pl = self::matchPlaces($tokens, $this->places());
        if ($pl && count($pl['places']) === 1) {
            return ['term' => self::squash((string)$pl['places'][0]['name']), 'name' => (string)$pl['places'][0]['name']];
        }
        // "spend at <words>" — trust it only when some receipt carries those words.
        if (preg_match('/\b(?:at|from|with)\s+(.+?)(?:\s+(?:this|last|today|yesterday|in|on|since|so|during|for)\b|$)/', $q, $m)) {
            $words = array_values(array_filter(self::tokens($m[1]), fn($t) => !in_array($t, self::NOISE, true)));
            $term = self::squash(implode('', $words));
            if (strlen($term) >= 4 && $this->receiptsExist($term)) return ['term' => $term, 'name' => implode(' ', array_map('ucfirst', $words))];
        }
        return null;
    }

    private function receiptsExist(string $term): bool
    {
        try {
            $s = $this->db->prepare("SELECT 1 FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
                                     WHERE REPLACE(LOWER(COALESCE(v.name, '')), ' ', '') LIKE ? OR REPLACE(LOWER(COALESCE(e.vendor_name_raw, '')), ' ', '') LIKE ? LIMIT 1");
            $s->execute(['%' . $term . '%', '%' . $term . '%']);
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Receipts at a vendor in a period (term '' = every vendor). Rejected — which includes removed
     * duplicates ("Duplicate of receipt #X") — and deleted receipts are left out.
     * @return array{approved_total: float, approved_n: int, pending_total: float, pending_n: int}|null
     */
    public function receipts(string $term, string $from, string $to): ?array
    {
        $sql = "SELECT e.status, e.total FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE e.expense_date BETWEEN ? AND ? AND e.status NOT IN ('rejected', 'deleted')";
        $args = [$from, $to];
        if ($term !== '') {
            $sql .= " AND (REPLACE(LOWER(COALESCE(v.name, '')), ' ', '') LIKE ? OR REPLACE(LOWER(COALESCE(e.vendor_name_raw, '')), ' ', '') LIKE ?)";
            $args[] = '%' . $term . '%';
            $args[] = '%' . $term . '%';
        }
        try {
            $s = $this->db->prepare($sql);
            $s->execute($args);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Charlie facts receipts: ' . $e->getMessage());
            return null;
        }
        return self::splitReceipts($rows);
    }

    /**
     * Stops at these places between two dates: today live from the trail, earlier days from
     * ops_trip_runs (overhead kinds) or the trail (other kinds, short ranges only).
     * @return array{visits: list<array{date: string, start: int, end: int, minutes: int, driver_id: ?int, from: ?string}>, no_pings_today: bool}|null
     */
    public function placeVisits(array $places, string $from, string $to): ?array
    {
        $today = $this->today();
        $ids = array_map(fn($p) => (int)$p['id'], $places);
        $overhead = !array_diff(array_map(fn($p) => (string)$p['kind'], $places), TripSegmentService::OVERHEAD_KINDS);
        $visits = [];
        $noPings = false;
        $pastTo = $to >= $today ? date('Y-m-d', strtotime($today . ' -1 day')) : $to;

        if ($from <= $pastTo) {
            if ($overhead) {
                $rows = $this->tripRuns($ids, $from, $pastTo);
                if ($rows === null) return null;
                $visits = array_merge($visits, $rows);
            } else {
                $days = (int)round((strtotime($pastTo) - strtotime($from)) / 86400) + 1;
                if ($days > self::LIVE_DAYS_MAX) return null;
                for ($d = $from; $d <= $pastTo; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    $day = $this->liveVisits($ids, $d);
                    if ($day === null) return null;
                    $visits = array_merge($visits, $day['visits']);
                }
            }
        }
        if ($from <= $today && $to >= $today) {
            $day = $this->liveVisits($ids, $today);
            if ($day === null) return null;
            $visits = array_merge($visits, $day['visits']);
            $noPings = $day['pings'] === 0;
        }
        usort($visits, fn($a, $b) => $a['start'] <=> $b['start']);
        return ['visits' => $visits, 'no_pings_today' => $noPings];
    }

    /** @return array{visits: array, pings: int}|null */
    private function liveVisits(array $placeIds, string $date): ?array
    {
        try {
            $day = $this->seg()->day($date);
        } catch (Throwable $e) {
            error_log('Charlie facts trail: ' . $e->getMessage());
            return null;
        }
        $out = self::visitsFromDay($day, $placeIds);
        // A stop outside any run: whoever was the only one clocked in drove.
        foreach ($out as &$v) {
            if ($v['driver_id'] === null) {
                $who = $this->seg()->clockedIn(date('Y-m-d H:i:s', $v['start']), date('Y-m-d H:i:s', $v['end']));
                if (count($who) === 1) $v['driver_id'] = $who[0];
            }
        }
        unset($v);
        return ['visits' => $out, 'pings' => (int)$day['pings']];
    }

    private function tripRuns(array $placeIds, string $from, string $to): ?array
    {
        if (!$placeIds) return [];
        $in = implode(',', array_fill(0, count($placeIds), '?'));
        try {
            $s = $this->db->prepare("SELECT r.run_date, r.arrived_at, r.departed_at, r.onsite_min, r.user_id, r.from_property_id,
                                            p.property_name, p.address
                                     FROM ops_trip_runs r LEFT JOIN properties p ON p.id = r.from_property_id
                                     WHERE r.place_id IN ({$in}) AND r.run_date BETWEEN ? AND ?
                                     ORDER BY r.arrived_at");
            $s->execute(array_merge($placeIds, [$from, $to]));
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Charlie facts trip runs: ' . $e->getMessage());
            return null;
        }
        $out = [];
        foreach ($rows as $r) {
            $left = trim((string)($r['property_name'] ?? '')) ?: trim((string)($r['address'] ?? ''));
            $out[] = [
                'date' => (string)$r['run_date'], 'start' => (int)strtotime((string)$r['arrived_at']), 'end' => (int)strtotime((string)$r['departed_at']),
                'minutes' => (int)round((float)$r['onsite_min']), 'driver_id' => $r['user_id'] !== null ? (int)$r['user_id'] : null,
                'from' => $left !== '' ? $left : null,
            ];
        }
        return $out;
    }

    /** @return array{n: int, priced_n: int, total: float, minutes: float, unpriced: int}|null */
    private function runCosts(array $places, string $from, string $to): ?array
    {
        $ids = array_map(fn($p) => (int)$p['id'], $places);
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $s = $this->db->prepare("SELECT total, onsite_min, drive_min FROM ops_trip_runs WHERE place_id IN ({$in}) AND run_date BETWEEN ? AND ?");
            $s->execute(array_merge($ids, [$from, $to]));
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        $out = ['n' => count($rows), 'priced_n' => 0, 'total' => 0.0, 'minutes' => 0.0, 'unpriced' => 0];
        foreach ($rows as $r) {
            $out['minutes'] += (float)$r['onsite_min'] + (float)$r['drive_min'];
            if ($r['total'] === null) { $out['unpriced']++; continue; }
            $out['priced_n']++;
            $out['total'] += (float)$r['total'];
        }
        $out['total'] = round($out['total'], 2);
        return $out;
    }

    private function costFact(string $kind, array $places): ?array
    {
        $f = new CostFactsService($this->db);
        if (count($places) === 1) {
            $fact = $f->get("run:{$kind}:place:" . (int)$places[0]['id']);
            if ($fact) return $fact;
        }
        return $f->get("run:{$kind}:any");
    }

    /** @return array{by_status: array<string, int>, total: int}|null */
    public function workVisits(string $from, string $to, ?int $crewId): ?array
    {
        $base = "SELECT v.status, COUNT(*) AS n FROM job_visits v WHERE v.scheduled_date BETWEEN ? AND ? AND v.status <> 'cancelled'";
        $tries = [];
        if ($crewId) {
            $tries[] = [$base . " AND (v.assigned_crew_id = ? OR v.stop_id IN (SELECT c.stop_id FROM calendar_stop_crew c WHERE c.user_id = ?)
                                       OR v.stop_id IN (SELECT s.id FROM calendar_stops s WHERE s.crew_id = ?)) GROUP BY v.status",
                        [$from, $to, $crewId, $crewId, $crewId]];
            $tries[] = [$base . " AND v.assigned_crew_id = ? GROUP BY v.status", [$from, $to, $crewId]];
        } else {
            $tries[] = [$base . " GROUP BY v.status", [$from, $to]];
        }
        foreach ($tries as [$sql, $args]) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute($args);
                $by = [];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(string)$r['status']] = (int)$r['n'];
                return ['by_status' => $by, 'total' => array_sum($by)];
            } catch (Throwable $e) {
                continue;
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Lowercase, curly quotes straightened, possessives dropped. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(str_replace(['’', '‘'], "'", $s));
        $s = preg_replace("/'s\b/u", '', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** @return string[] lowercase words */
    public static function tokens(string $q): array
    {
        preg_match_all('/[\p{L}\d]+/u', mb_strtolower($q), $m);
        return $m[0];
    }

    /** Letters and digits only: "Lawn Boy" → "lawnboy". */
    public static function squash(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
    }

    /** Every run of 1–4 consecutive tokens with no noise word, squashed: [squashed => words]. */
    public static function ngrams(array $tokens, int $max = 4): array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $words = [];
            for ($k = $i; $k < $n && $k < $i + $max; $k++) {
                if (in_array($tokens[$k], self::NOISE, true)) break;
                $words[] = $tokens[$k];
                $out[self::squash(implode('', $words))] = implode(' ', $words);
            }
        }
        unset($out['']);
        return $out;
    }

    /**
     * What the question asks for: visits | time | cost | spend | receipts, or null.
     */
    public static function intent(string $q): ?string
    {
        $q = self::norm($q);
        if (preg_match('/\bhow many receipts\b|\breceipts? (from|at|for)\b/', $q)) return 'receipts';
        if (preg_match('/\b(spend|spent|spending)\b|\bhow much (did|have) we (pay|paid|buy|bought)\b/', $q)) return 'spend';
        if (preg_match('/\b(cost|costs|cost us|price|expensive)\b/', $q)) return 'cost';
        if (preg_match('/\bhow long\b|\b(average|avg|usual|typical) time\b|\btime (at|spent)\b|\bminutes at\b/', $q)) return 'time';
        if (preg_match('/\bhow (many|often)\b|\b(visits?|trips?|runs?|went|go|gone)\b|\b(been|stopp?ed) (to|at|by)\b/', $q)) return 'visits';
        return null;
    }

    /**
     * The place(s) named in the question: a place by name or receipt word (exact, space- and
     * case-insensitive), else every place of a kind ("the dump").
     * @return array{places: array, label: string}|null
     */
    public static function matchPlaces(array $tokens, array $places): ?array
    {
        if (!$places) return null;
        $grams = self::ngrams($tokens);
        $hit = [];
        $best = 0;
        foreach ($places as $p) {
            $keys = [self::squash((string)$p['name'])];
            foreach (explode('|', (string)($p['vendor_match'] ?? '')) as $w) {
                if (strlen(self::squash($w)) >= 4) $keys[] = self::squash($w);
            }
            foreach ($keys as $k) {
                if ($k !== '' && isset($grams[$k]) && !isset(self::KIND_WORDS[$k])) {
                    if (strlen($k) > $best) { $best = strlen($k); $hit = [$p]; }
                    elseif (strlen($k) === $best && !in_array($p, $hit, true)) $hit[] = $p;
                }
            }
        }
        if ($hit) return ['places' => $hit, 'label' => count($hit) === 1 ? (string)$hit[0]['name'] : implode(' / ', array_column($hit, 'name'))];

        // A kind word: "the dump", "supplier", "supply run".
        $kind = null;
        for ($i = 0; $i < count($tokens); $i++) {
            foreach ([self::squash($tokens[$i] . ($tokens[$i + 1] ?? '')), $tokens[$i]] as $k) {
                if (isset(self::KIND_WORDS[$k])) { $kind = self::KIND_WORDS[$k]; break 2; }
            }
        }
        if ($kind === null) return null;
        $of = array_values(array_filter($places, fn($p) => (string)$p['kind'] === $kind));
        if (!$of) return null;
        return ['places' => $of, 'label' => count($of) === 1 ? (string)$of[0]['name'] : self::KIND_LABEL[$kind]];
    }

    /**
     * A vendors row named in the question (name or alias; exact, or the start of a longer name).
     * @return array{term: string, name: string}|null
     */
    public static function matchVendor(array $tokens, array $vendors): ?array
    {
        $grams = self::ngrams($tokens);
        $best = null;
        foreach ($vendors as $v) {
            $names = array_merge([(string)$v['name']], array_filter(array_map('trim', preg_split('/[,|;]/', (string)($v['aliases'] ?? '')) ?: [])));
            foreach ($names as $n) {
                $k = self::squash($n);
                $k2 = preg_replace('/^the/', '', $k);
                if (strlen($k) < 4) continue;
                foreach ($grams as $g => $words) {
                    if (strlen($g) < 4) continue;
                    $ok = $g === $k || $g === $k2 || (strlen($g) >= 5 && (strpos($k, $g) === 0 || strpos($k2, $g) === 0));
                    if ($ok && ($best === null || strlen($g) > strlen($best['term']))) $best = ['term' => $g, 'name' => (string)$v['name']];
                }
            }
        }
        return $best;
    }

    /** @return array{id: int, first: string, name: string}|null a crew member named by first name */
    public static function matchCrew(array $tokens, array $names): ?array
    {
        foreach ($names as $id => $full) {
            $first = mb_strtolower((string)strtok($full, ' '));
            if (mb_strlen($first) >= 3 && in_array($first, $tokens, true)) {
                return ['id' => (int)$id, 'first' => ucfirst($first), 'name' => $full];
            }
        }
        return null;
    }

    /** Words in the question CharlieAskService would still look up (names, streets) — besides these. */
    public static function leftover(string $question, array $ignore = []): array
    {
        $extra = ['stops', 'times', 'trips', 'runs', 'went', 'done', 'were', 'crew', 'work', 'complete', 'completed', 'finished', 'many',
                  'there', 'did', 'have', 'got', 'left', 'remaining', 'far', 'how'];
        $ignore = array_map('mb_strtolower', $ignore);
        return array_values(array_filter(CharlieAskService::terms($question), function ($t) use ($extra, $ignore) {
            $l = mb_strtolower($t);
            return !in_array($l, $extra, true) && !in_array($l, $ignore, true) && !in_array($l, self::NOISE, true);
        }));
    }

    /** One kind when every place shares it, else ''. */
    public static function sharedKind(array $places): string
    {
        $k = array_values(array_unique(array_map(fn($p) => (string)$p['kind'], $places)));
        return count($k) === 1 ? $k[0] : '';
    }

    /**
     * The period the question names, or null.
     * @return array{from: string, to: string, label: string}|null
     */
    public static function period(string $q, string $today): ?array
    {
        $q = self::norm($q);
        foreach (['today', 'yesterday', 'this week', 'last week', 'this month', 'last month', 'this year', 'last year'] as $w) {
            if (preg_match('/\b' . str_replace(' ', '\s+', $w) . '\b/', $q)) return self::periodOf($w, $today);
        }

        $since = (bool)preg_match('/\bsince\b/', $q);
        $d = self::explicitDate($q, $today);
        if ($d !== null) {
            if ($since) return ['from' => $d, 'to' => $today, 'label' => 'since ' . self::dayLabel($d, $today)];
            return ['from' => $d, 'to' => $d, 'label' => 'on ' . self::dayLabel($d, $today)];
        }
        // "in October"
        if (preg_match('/\bin (jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b/', $q, $m)) {
            $y = (int)substr($today, 0, 4);
            $mon = self::MONTHS[$m[1]];
            if (sprintf('%04d-%02d-01', $y, $mon) > $today) $y--;
            $from = sprintf('%04d-%02d-01', $y, $mon);
            $to = date('Y-m-t', strtotime($from));
            return ['from' => $from, 'to' => min($to, $today), 'label' => 'in ' . date('F', strtotime($from))];
        }
        return null;
    }

    /** A named period → dates. 'this …' periods end today. */
    public static function periodOf(string $w, string $today): array
    {
        $t = strtotime($today);
        $dow = (int)date('N', $t);
        switch ($w) {
            case 'yesterday':
                $d = date('Y-m-d', strtotime('-1 day', $t));
                return ['from' => $d, 'to' => $d, 'label' => 'yesterday'];
            case 'this week':
                return ['from' => date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $t)), 'to' => $today, 'label' => 'this week'];
            case 'last week':
                $mon = strtotime('-' . ($dow - 1 + 7) . ' days', $t);
                return ['from' => date('Y-m-d', $mon), 'to' => date('Y-m-d', strtotime('+6 days', $mon)), 'label' => 'last week'];
            case 'this month':
                return ['from' => date('Y-m-01', $t), 'to' => $today, 'label' => 'this month'];
            case 'last month':
                $first = strtotime(date('Y-m-01', $t) . ' -1 month');
                return ['from' => date('Y-m-01', $first), 'to' => date('Y-m-t', $first), 'label' => 'last month'];
            case 'this year':
                return ['from' => date('Y-01-01', $t), 'to' => $today, 'label' => 'this year'];
            case 'last year':
                $y = (int)date('Y', $t) - 1;
                return ['from' => "{$y}-01-01", 'to' => "{$y}-12-31", 'label' => 'last year'];
            default:
                return ['from' => $today, 'to' => $today, 'label' => 'today'];
        }
    }

    /** "2026-10-03", "Oct 3", "October 3rd", "3 Oct", "monday" / "last monday" → Y-m-d (never in the future). */
    public static function explicitDate(string $q, string $today): ?string
    {
        $y = (int)substr($today, 0, 4);
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $q, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        $mon = $day = null;
        $mre = '(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?';
        if (preg_match('/\b' . $mre . '\s+(\d{1,2})(?:st|nd|rd|th)?\b/', $q, $m)) { $mon = self::MONTHS[$m[1]]; $day = (int)$m[2]; }
        elseif (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?' . $mre . '/', $q, $m)) { $mon = self::MONTHS[$m[2]]; $day = (int)$m[1]; }
        if ($mon !== null) {
            if (!checkdate($mon, $day, $y)) return null;
            $d = sprintf('%04d-%02d-%02d', $y, $mon, $day);
            return $d > $today ? sprintf('%04d-%02d-%02d', $y - 1, $mon, $day) : $d;
        }
        if (preg_match('/\b(?:on|last)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $q, $m)) {
            $want = self::WEEKDAYS[$m[1]];
            $t = strtotime($today);
            $back = ((int)date('N', $t) - $want + 7) % 7;
            if ($back === 0) $back = 7;   // "on Monday" asked on a Monday means last Monday
            return date('Y-m-d', strtotime("-{$back} days", $t));
        }
        return null;
    }

    public static function dayLabel(string $d, string $today): string
    {
        $t = strtotime($d);
        return date('D M j', $t) . (substr($d, 0, 4) !== substr($today, 0, 4) ? ', ' . date('Y', $t) : '');
    }

    /**
     * The stops at these places in one day's TripSegmentService::day(), with the run's driver and
     * the property it left from when the stop was part of a run.
     */
    public static function visitsFromDay(array $day, array $placeIds): array
    {
        $byArrival = [];
        foreach ((array)($day['runs'] ?? []) as $run) {
            foreach ($run['legs'] as $leg) {
                $byArrival[(int)$leg['arrived_at']] = [
                    'driver_id' => isset($run['crew']['driver_id']) && $run['crew']['driver_id'] !== null ? (int)$run['crew']['driver_id'] : null,
                    'from'      => $run['from']['name'] ?? null,
                ];
            }
        }
        $out = [];
        foreach ((array)($day['segments'] ?? []) as $s) {
            if ($s['type'] !== 'stop' || $s['label']['type'] !== 'place' || !in_array((int)$s['label']['id'], $placeIds, true)) continue;
            $run = $byArrival[(int)$s['start']] ?? ['driver_id' => null, 'from' => null];
            $out[] = ['date' => (string)$day['date'], 'start' => (int)$s['start'], 'end' => (int)$s['end'],
                      'minutes' => (int)round(($s['end'] - $s['start']) / 60), 'driver_id' => $run['driver_id'], 'from' => $run['from']];
        }
        return $out;
    }

    /** @return array{approved_total: float, approved_n: int, pending_total: float, pending_n: int} */
    public static function splitReceipts(array $rows): array
    {
        $o = ['approved_total' => 0.0, 'approved_n' => 0, 'pending_total' => 0.0, 'pending_n' => 0];
        foreach ($rows as $r) {
            $k = in_array((string)$r['status'], ['approved', 'forwarded'], true) ? 'approved' : 'pending';
            $o[$k . '_total'] += (float)$r['total'];
            $o[$k . '_n']++;
        }
        $o['approved_total'] = round($o['approved_total'], 2);
        $o['pending_total'] = round($o['pending_total'], 2);
        return $o;
    }

    // ── Wording ─────────────────────────────────────────────────────────────

    public static function suffix(array $heads): string
    {
        $from = array_map(fn($h) => self::FROM[$h] ?? $h, $heads);
        return "\n— from " . implode(' and ', $from);
    }

    public static function plural(int $n, string $word): string
    {
        return $n === 1 ? $word : $word . 's';
    }

    public static function money(?float $v): string
    {
        $v = (float)$v;
        return abs($v - round($v)) < 0.005 ? number_format($v, 0) : number_format($v, 2);
    }

    private static function span(array $v, bool $withDay): string
    {
        return ($withDay ? date('D M j', $v['start']) . ' ' : '') . date('G:i', $v['start']) . '–' . date('G:i', $v['end']) . " ({$v['minutes']} min)";
    }

    private static function firstName(?int $id, array $names): ?string
    {
        if ($id === null || !isset($names[$id]) || $names[$id] === '') return null;
        return (string)strtok($names[$id], ' ');
    }

    /** "3 visits to LAWNBOY today: 10:15–10:24 (9 min), … — all Nigel from Oakridge Gardens." */
    public static function visitsLine(string $label, array $p, array $v, ?array $crew, array $names): string
    {
        $all = $v['visits'];
        if (!$all && $v['no_pings_today'] && $p['from'] === $p['to']) {
            return "I have no truck pings for {$p['label']} yet, so I can't tell whether it went to {$label}.";
        }
        $unknown = 0;
        if ($crew) {
            $mine = array_values(array_filter($all, fn($x) => $x['driver_id'] === $crew['id']));
            $unknown = count(array_filter($all, fn($x) => $x['driver_id'] === null));
            $all = $mine;
        }
        $n = count($all);
        $lead = $crew
            ? ($n ? "{$crew['first']} went to {$label} {$n} " . self::plural($n, 'time') . " {$p['label']}" : "{$crew['first']} didn't go to {$label} {$p['label']}")
            : ($n ? "{$n} " . self::plural($n, 'visit') . " to {$label} {$p['label']}" : "No visits to {$label} {$p['label']}");
        if (!$n) {
            $out = $lead . '.';
        } elseif ($n <= self::LIST_MAX) {
            $multiDay = count(array_unique(array_column($all, 'date'))) > 1;
            $drivers = array_values(array_unique(array_map(fn($x) => self::firstName($x['driver_id'], $names) ?? '?', $all)));
            $froms = array_values(array_unique(array_map(fn($x) => $x['from'] ?? '', $all)));
            $one = !$crew && count($drivers) === 1 && $drivers[0] !== '?';
            $items = [];
            foreach ($all as $x) {
                $who = self::firstName($x['driver_id'], $names);
                $items[] = self::span($x, $multiDay) . (!$crew && !$one && $who ? ", {$who}" : '');
            }
            $tail = '';
            if ($one || ($crew && count($froms) === 1 && $froms[0] !== '')) {
                $tail = ' — ' . ($n > 1 ? 'all ' : '') . ($crew ? '' : $drivers[0]) . (count($froms) === 1 && $froms[0] !== '' ? ($crew ? 'from ' : ' from ') . $froms[0] : '');
            }
            $out = $lead . ': ' . implode(', ', $items) . rtrim($tail) . '.';
        } else {
            $min = array_sum(array_column($all, 'minutes'));
            $days = count(array_unique(array_column($all, 'date')));
            $out = $lead . " on {$days} " . self::plural($days, 'day') . ', ' . (int)round($min / $n) . " min on site on average ({$min} min in all).";
        }
        if ($crew && $unknown) $out .= " Plus {$unknown} where I can't tell who drove.";
        if ($v['no_pings_today'] && $p['to'] >= $p['from'] && $p['from'] !== $p['to']) $out .= ' No truck pings today yet.';
        return $out;
    }

    public static function timeLine(string $label, array $p, array $v, ?array $crew): string
    {
        $all = $crew ? array_values(array_filter($v['visits'], fn($x) => $x['driver_id'] === $crew['id'])) : $v['visits'];
        $n = count($all);
        if (!$n) {
            if ($v['no_pings_today'] && $p['from'] === $p['to']) return "I have no truck pings for {$p['label']} yet.";
            return 'No time at ' . $label . ' ' . $p['label'] . ($crew ? " for {$crew['first']}" : '') . '.';
        }
        $min = array_sum(array_column($all, 'minutes'));
        $multiDay = count(array_unique(array_column($all, 'date'))) > 1;
        $out = "{$min} min on site at {$label} {$p['label']} over {$n} " . self::plural($n, 'visit');
        if ($n <= self::LIST_MAX) $out .= ': ' . implode(', ', array_map(fn($x) => self::span($x, $multiDay), $all));
        else $out .= ', ' . (int)round($min / $n) . ' min each on average';
        return $out . '.';
    }

    public static function runCostLine(string $kind, string $label, array $p, array $c, string $today): string
    {
        $what = CostFactsService::LABEL[$kind] . 's';
        if ($c['n'] === 0) {
            return $p['to'] >= $today && $p['from'] === $today
                ? "No {$what} to {$label} priced today yet — runs are priced overnight, ask me tomorrow."
                : "No {$what} to {$label} {$p['label']}.";
        }
        $out = "{$what} to {$label} {$p['label']}: {$c['n']} " . self::plural($c['n'], 'run') . ', $' . self::money($c['total'])
            . ($kind === 'dump' ? ' in all (time, truck and dump fees)' : ' in all (time, truck and the receipts)');
        if ($c['unpriced']) $out .= ", {$c['unpriced']} not priced (no pay rate)";
        if ($p['to'] >= $today) $out .= "; today's runs are priced overnight";
        return $out . '.';
    }

    public static function costFactLine(string $kind, string $label, ?array $f): string
    {
        $n = $f ? (int)$f['sample_n'] : 0;
        $what = strtolower(CostFactsService::LABEL[$kind]);
        if (!$f || $n < self::MIN_SAMPLE || $f['median_cost'] === null) {
            return "Not enough runs yet to cost a {$what} to {$label} ({$n}/" . self::MIN_SAMPLE . ' one-man runs with a pay rate).';
        }
        $out = "A {$what} to {$label} costs us about $" . self::money($f['median_cost']) . " (median of {$n} one-man runs): "
            . "{$f['median_round_trip_min']} min round trip, {$f['median_onsite_min']} min on site";
        if ($f['median_km'] !== null) $out .= ', ' . rtrim(rtrim(number_format((float)$f['median_km'], 1), '0'), '.') . ' km';
        if ($kind === 'dump' && $f['median_receipt'] !== null) $out .= ', dump fee about $' . self::money($f['median_receipt']);
        return $out . '.';
    }

    public static function timeFactLine(string $label, ?array $f): string
    {
        $n = $f ? (int)$f['sample_n'] : 0;
        if (!$f || $n < self::MIN_SAMPLE || $f['median_onsite_min'] === null) {
            return "Not enough runs yet to say how long {$label} usually takes ({$n}/" . self::MIN_SAMPLE . ' one-man runs).';
        }
        return "Usually about " . (int)round($f['median_onsite_min']) . " min on site at {$label} and " . (int)round((float)$f['median_round_trip_min'])
            . " min round trip (median of {$n} one-man runs).";
    }

    public static function spendLine(string $intent, string $name, string $label, array $s): string
    {
        $n = $s['approved_n'] + $s['pending_n'];
        $total = $s['approved_total'] + $s['pending_total'];
        if ($n === 0) return "No receipts from {$name} {$label}.";
        $parts = [];
        if ($s['approved_n']) $parts[] = '$' . self::money($s['approved_total']) . " approved ({$s['approved_n']} " . self::plural($s['approved_n'], 'receipt') . ')';
        if ($s['pending_n']) $parts[] = '$' . self::money($s['pending_total']) . " waiting for approval ({$s['pending_n']})";
        if ($intent === 'receipts') {
            return "{$n} " . self::plural($n, 'receipt') . " from {$name} {$label}, $" . self::money($total) . ' in all: ' . implode(', ', $parts) . '.';
        }
        return "We spent $" . self::money($total) . " at {$name} {$label}: " . implode(', ', $parts) . '.';
    }

    public static function workLine(array $p, array $w, ?array $crew, string $today): string
    {
        $by = $w['by_status'];
        $n = $w['total'];
        $who = $crew ? $crew['first'] . ' has' : 'There ' . ($n === 1 ? 'is' : 'are');
        if ($p['to'] < $today) $who = $crew ? $crew['first'] . ' had' : 'There ' . ($n === 1 ? 'was' : 'were');
        if ($n === 0) return ($crew ? "{$crew['first']} has no visits" : 'No visits') . " on the schedule {$p['label']}.";
        $parts = [];
        $names = ['completed' => 'completed', 'in_progress' => 'in progress', 'scheduled' => $p['to'] < $today ? 'not done' : 'still to do',
                  'skipped' => 'skipped', 'weather' => 'weather'];
        foreach ($names as $k => $word) if (!empty($by[$k])) $parts[] = "{$by[$k]} {$word}";
        foreach ($by as $k => $c) if (!isset($names[$k]) && $c) $parts[] = "{$c} {$k}";
        return "{$who} {$n} " . self::plural($n, 'visit') . " {$p['label']}: " . implode(', ', $parts) . '.';
    }
}
