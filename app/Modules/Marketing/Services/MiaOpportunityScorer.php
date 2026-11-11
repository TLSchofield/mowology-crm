<?php
/**
 * MiaOpportunityScorer — turns Search Console data and audit findings into ranked, explainable opportunities
 * (Phase 1.9, docs/mia/MIA-ARCHITECTURE.md §2.1 "Opportunity engine").
 *
 * Deterministic. The model never produces a number here: PHP finds the opportunities, PHP scores them from
 * visible factors, and every score is stored with its inputs (score_inputs) so the owner can see why.
 *
 * Opportunity kinds
 *   ctr_gap            a page on page 1 of Google whose click rate is well below what its position typically earns
 *   striking_distance  a page just behind page 1 (positions 11-20) that people already see
 *   declining_page     a page that lost 30%+ of its clicks against the previous period
 *   query_no_page      a search people make where no page of ours ranks (best position beyond 20)
 *   audit_fix          one rule from the site audit (SiteAuditRules), grouped across pages
 *
 * Score 0-100 = 100 x (0.35 impact + 0.25 confidence + 0.25 commercial value + 0.15 urgency)
 *                     x (1 - 0.3 effort) x (1 - 0.4 risk)
 * Commercial value is neutral (0.5) until profit data from Penny is connected (Phase 3); priority terms the owner
 * saved in memory (kind "priority") raise it, and "prohibited" terms remove the opportunity altogether.
 *
 * Honesty about the data (also written into each opportunity's risk note):
 *   - Search Console reports a 25-day window that ends 3 days before the pull, may omit rare (anonymised)
 *     searches, and positions are averages. Click estimates are rough ranges, never promises.
 *   - A decline needs a previous period at least 26 days older; without one no decline is reported.
 *   - Expected click rates by position are typical benchmarks (assumption), not facts about this site.
 *
 * GSC tables are not tenant-keyed (single-tenant legacy), so they are read only for a property whose domain
 * equals the domain of a website the owner has VERIFIED (domain control proved), and only when site_id matches.
 *
 * Every query binds site_id where the table has one. Methods return arrays and do not throw (the constructor
 * refuses site 0). Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/MiaWebsiteService.php';
require_once __DIR__ . '/MiaMemoryService.php';
require_once __DIR__ . '/MiaActionService.php';
require_once __DIR__ . '/Site/SiteAuditRules.php';

final class MiaOpportunityScorer
{
    public const WINDOW_DAYS = 25;
    public const MIN_IMPRESSIONS = 100;
    public const MIN_PRIOR_CLICKS = 20;
    public const DECLINE_RATIO = 0.7;
    public const FRESH_DAYS = 35;
    public const PRIOR_MIN_GAP_DAYS = 26;
    public const PRIOR_MAX_GAP_DAYS = 50;
    public const MAX_PAGES = 5000;

    public const W_IMPACT = 0.35;
    public const W_CONFIDENCE = 0.25;
    public const W_VALUE = 0.25;
    public const W_URGENCY = 0.15;

    /** Typical click-through rate by Google position (assumption: rounded industry benchmarks). */
    public const EXPECTED_CTR = [1 => 0.27, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.018];

    /** Audit rules that become opportunities, with the kind of change they need. info-level rules never do. */
    public const AUDIT_ACTIONS = [
        'title_missing' => 'seo_meta_update', 'title_short' => 'seo_meta_update', 'title_long' => 'seo_meta_update', 'title_duplicate' => 'seo_meta_update',
        'meta_missing' => 'seo_meta_update', 'meta_short' => 'seo_meta_update', 'meta_long' => 'seo_meta_update', 'meta_duplicate' => 'seo_meta_update',
        'orphan_page' => 'internal_link', 'link_to_redirect' => 'internal_link',
        'h1_missing' => 'fix_technical', 'h1_multiple' => 'fix_technical', 'canonical_missing' => 'fix_technical', 'canonical_offsite' => 'fix_technical',
        'canonical_to_broken' => 'fix_technical', 'broken_page' => 'fix_technical', 'server_error' => 'fix_technical', 'not_https' => 'fix_technical',
        'noindex_home' => 'fix_technical', 'viewport_missing' => 'fix_technical', 'duplicate_content' => 'fix_technical',
        'schema_missing_home' => 'fix_technical', 'images_missing_alt' => 'fix_technical', 'thin_content' => 'page_draft', 'lang_missing' => 'fix_technical',
    ];
    public const EFFORT = ['seo_meta_update' => 0.2, 'internal_link' => 0.3, 'fix_technical' => 0.3, 'page_draft' => 0.6];
    private const SEVERITY_IMPACT = ['high' => 0.8, 'medium' => 0.5, 'low' => 0.25, 'info' => 0.1];

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var MiaWebsiteService */
    private $websites;
    /** @var MiaMemoryService */
    private $memory;

    public function __construct(PDO $db, int $siteId, MiaWebsiteService $websites)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaOpportunityScorer needs a site'); }
        $this->db       = $db;
        $this->siteId   = $siteId;
        $this->websites = $websites;
        $this->memory   = new MiaMemoryService($db, $siteId);
    }

    // ---- pure helpers (unit-tested directly) ---------------------------------------------------

    /** Typical click rate at a (fractional) position; 0 beyond 10. */
    public static function expectedCtr(float $position): float
    {
        if ($position < 1) { return self::EXPECTED_CTR[1]; }
        if ($position > 10.49) { return 0.0; }
        return self::EXPECTED_CTR[(int)round($position)];
    }

    /**
     * @param array{impact:float,confidence:float,value:float,urgency:float,effort:float,risk:float} $f  each 0..1
     * @return float 0..100
     */
    public static function score(array $f): float
    {
        $c = function ($v) { return max(0.0, min(1.0, (float)$v)); };
        $raw = self::W_IMPACT * $c($f['impact']) + self::W_CONFIDENCE * $c($f['confidence']) + self::W_VALUE * $c($f['value']) + self::W_URGENCY * $c($f['urgency']);
        return round(100 * $raw * (1 - 0.3 * $c($f['effort'])) * (1 - 0.4 * $c($f['risk'])), 2);
    }

    /** Estimated extra visits as a range over a 30-day month, from a gap measured over WINDOW_DAYS. [lo, hi] */
    public static function clickRange(float $gapClicks): array
    {
        $monthly = max(0.0, $gapClicks) * 30 / self::WINDOW_DAYS;
        return [(int)round($monthly * 0.5), (int)round($monthly * 1.5)];
    }

    /** Coarse buckets so ordinary day-to-day movement does not look like new evidence. */
    public static function bucketCount(int $n): int
    {
        return $n <= 0 ? 0 : (int)pow(2, (int)floor(log($n, 2)));
    }

    public static function benefitText(array $range, string $what): string
    {
        list($lo, $hi) = $range;
        if ($hi < 3) { return "Estimate: a small number of extra visits a month from $what. This is an estimate, not a promise."; }
        return "Estimate: roughly $lo to $hi extra visits a month from $what, if click rates move toward typical levels. This is an estimate, not a promise.";
    }

    // ---- main -----------------------------------------------------------------------------------

    /**
     * Ranked opportunities for one verified website.
     * @return array{ok:bool,code:?string,error:?string,data:array,opportunities:array<int,array>}
     */
    public function opportunities(int $websiteId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $out = ['ok' => false, 'code' => null, 'error' => null,
                'data' => ['gsc' => 'missing', 'prior_period' => false, 'findings' => 0, 'pages_seen' => 0], 'opportunities' => []];
        try {
            $w = $this->websites->requireVerified($websiteId, $now);
            if (!$w) { $out['code'] = 'not_verified'; $out['error'] = 'This website has not been verified'; return $out; }
            $host = strtolower((string)$w['host']);
            $prio = $this->terms($this->memory->all('priority'));
            $ban  = $this->terms($this->memory->all('prohibited'));

            $opps = [];
            $pageStats = [];
            $snap = $this->latestSnapshot($host, $now);
            if ($snap) {
                $out['data']['gsc'] = 'ok';
                $pageStats = $this->pageStats((int)$snap['id'], $host);
                $out['data']['pages_seen'] = count($pageStats);
                $prior = $this->priorSnapshot((int)$snap['property_id'], (string)$snap['snapshot_date']);
                $priorStats = $prior ? $this->pageStats((int)$prior['id'], $host) : [];
                $out['data']['prior_period'] = (bool)$prior;
                $opps = array_merge($opps, $this->pageOpportunities($pageStats, $priorStats, (bool)$prior, (int)$snap['id'], $prio, $ban),
                                    $this->queryOpportunities((int)$snap['id'], $websiteId, $host, $prio, $ban));
            } elseif ($this->propertyExists($host)) {
                $out['data']['gsc'] = 'stale';
            }

            $auditOpps = $this->auditOpportunities($websiteId, $host, $pageStats, $prio, $ban);
            $out['data']['findings'] = array_sum(array_column($auditOpps, 'pages'));
            $opps = array_merge($opps, array_map(function ($o) { unset($o['pages']); return $o; }, $auditOpps));

            usort($opps, function ($a, $b) { return $b['score'] <=> $a['score'] ?: strcmp($a['opportunity_key'], $b['opportunity_key']); });
            $out['opportunities'] = $opps;
            $out['ok'] = true;
            return $out;
        } catch (Throwable $e) {
            error_log('MiaOpportunityScorer: ' . $e->getMessage());
            $out['code'] = 'error';
            $out['error'] = 'The opportunities could not be worked out';
            return $out;
        }
    }

    /**
     * Put the best opportunities in the owner's queue (Phase 1: no AI wording yet - the text is a plain template).
     * @return array{proposed:int,existing:int,held_back:int,failed:int,ids:int[]}
     */
    public function proposeTop(int $websiteId, MiaActionService $actions, int $limit = 5, float $minScore = 20.0, ?DateTimeImmutable $now = null): array
    {
        $r = ['proposed' => 0, 'existing' => 0, 'held_back' => 0, 'failed' => 0, 'ids' => []];
        $res = $this->opportunities($websiteId, $now);
        if (!$res['ok']) { $r['failed']++; return $r; }
        $taken = 0;
        foreach ($res['opportunities'] as $o) {
            if ($taken >= max(0, $limit) || $o['score'] < $minScore) { break; }
            $p = $actions->propose([
                'type' => $o['type'], 'side_effect' => 'none', 'opportunity_key' => $o['opportunity_key'], 'title' => $o['title'], 'why' => $o['why'],
                'expected_benefit' => $o['expected_benefit'], 'risk' => $o['risk'], 'evidence' => $o['evidence'], 'evidence_basis' => $o['evidence_basis'],
                'score' => $o['score'], 'score_inputs' => $o['score_inputs'],
            ], $now);
            if ($p['ok']) {
                $p['existing'] ? $r['existing']++ : $r['proposed']++;
                $r['ids'][] = (int)$p['id'];
                $taken++;
            } elseif (strpos((string)$p['code'], 'suppressed_') === 0) {
                $r['held_back']++;
            } else {
                $r['failed']++;
            }
        }
        return $r;
    }

    // ---- page-level opportunities -------------------------------------------------------------

    /**
     * @param array<string,array{impressions:int,clicks:int,position:float}> $now_
     * @param array<string,array{impressions:int,clicks:int,position:float}> $prior
     */
    private function pageOpportunities(array $now_, array $prior, bool $hasPrior, int $snapshotId, array $prio, array $ban): array
    {
        $out = [];
        foreach ($now_ as $url => $s) {
            if ($this->banned($url, $ban)) { continue; }
            $I = (int)$s['impressions']; $C = (int)$s['clicks']; $P = (float)$s['position'];
            $ctr = $I > 0 ? $C / $I : 0.0;
            $queries = null;                       // fetched lazily
            $value = $this->value($url, $prio);
            $key = 'page:' . sha1($url);
            $short = self::shortUrl($url);

            if ($I >= self::MIN_IMPRESSIONS && $P >= 1 && $P <= 10) {
                $exp = self::expectedCtr($P);
                if ($exp > 0 && $ctr < 0.6 * $exp) {
                    $gap = $I * ($exp - $ctr) * 0.5;
                    $queries = $this->topQueries($snapshotId, $url);
                    $out[] = $this->build('ctr_gap', 'seo_meta_update', $key . ':ctr', $url, [
                        'title' => "Rewrite the title and description of $short",
                        'why' => sprintf('People saw this page %s times in the last %d days (about position %.1f) but only %d clicked (%.1f%%). Pages at that position typically get around %.1f%%. A clearer title and description may help more of them click.', number_format($I), self::WINDOW_DAYS, $P, $C, $ctr * 100, $exp * 100),
                        'expected_benefit' => self::benefitText(self::clickRange($gap), 'a better title and description'),
                        'risk' => 'Changing the title and description changes what Google shows for this page. Google may also rewrite them. Results can take weeks to show and are an estimate.' . ($P < 3.5 ? ' This page already ranks near the top, so a change could also lower clicks.' : ''),
                        'evidence' => [['page' => $url, 'impressions' => $I, 'clicks' => $C, 'avg_position' => round($P, 1), 'typical_ctr' => $exp, 'top_queries' => $queries]],
                        'basis' => ['page' => $url, 'kind' => 'ctr_gap', 'imp' => self::bucketCount($I), 'pos' => (int)round($P)],
                        'impact' => min(1.0, log10(1 + $gap * 30 / self::WINDOW_DAYS) / log10(1 + 200)),
                        'confidence' => min(1.0, $I / 500), 'value' => $value, 'urgency' => 0.3, 'risk_f' => $P < 3.5 ? 0.5 : 0.2,
                        'raw' => ['impressions' => $I, 'clicks' => $C, 'ctr' => round($ctr, 4), 'position' => round($P, 1), 'expected_ctr' => $exp, 'gap_clicks_window' => round($gap, 1)],
                    ]);
                }
            }
            if ($I >= self::MIN_IMPRESSIONS && $P > 10 && $P <= 20) {
                $gap = max(0.0, $I * (self::expectedCtr(8) - $ctr)) * 0.5;
                $queries = $queries ?? $this->topQueries($snapshotId, $url);
                $out[] = $this->build('striking_distance', 'page_draft', $key . ':strike', $url, [
                    'title' => "Strengthen $short so it reaches the first results page",
                    'why' => sprintf('This page was shown %s times in the last %d days at about position %.1f, just behind the first results page. More useful detail, clearer headings and links from related pages may move it up.', number_format($I), self::WINDOW_DAYS, $P),
                    'expected_benefit' => self::benefitText(self::clickRange($gap), 'moving closer to the first results page'),
                    'risk' => 'Rankings depend on many things outside our control, including competitors. Expanding a page can also dilute it if the text is padded. Treat the estimate as a rough guide.',
                    'evidence' => [['page' => $url, 'impressions' => $I, 'clicks' => $C, 'avg_position' => round($P, 1), 'top_queries' => $queries]],
                    'basis' => ['page' => $url, 'kind' => 'striking', 'imp' => self::bucketCount($I), 'pos' => (int)floor($P / 5)],
                    'impact' => min(1.0, log10(1 + $gap * 30 / self::WINDOW_DAYS) / log10(1 + 200)),
                    'confidence' => min(1.0, $I / 500), 'value' => $value, 'urgency' => 0.3, 'risk_f' => 0.2,
                    'raw' => ['impressions' => $I, 'clicks' => $C, 'position' => round($P, 1), 'gap_clicks_window' => round($gap, 1)],
                ]);
            }
            if ($hasPrior && isset($prior[$url])) {
                $C0 = (int)$prior[$url]['clicks'];
                if ($C0 >= self::MIN_PRIOR_CLICKS && $C <= $C0 * self::DECLINE_RATIO) {
                    $drop = (int)round((1 - $C / $C0) * 100);
                    $out[] = $this->build('declining_page', 'page_draft', $key . ':decline', $url, [
                        'title' => "Find out why $short is getting fewer clicks",
                        'why' => sprintf('This page had %d clicks in the earlier period and %d in the latest one, a drop of %d%%. The cause could be a change on the page, a competitor, a seasonal pattern or a change in Google.', $C0, $C, $drop),
                        'expected_benefit' => self::benefitText(self::clickRange(($C0 - $C) * 0.5), 'recovering part of the lost clicks'),
                        'risk' => 'Search traffic moves up and down for reasons that are not always fixable, and some of this drop may be seasonal. We will check the page first and only suggest changes that look justified.',
                        'evidence' => [['page' => $url, 'clicks_earlier' => $C0, 'clicks_latest' => $C, 'drop_percent' => $drop, 'impressions_latest' => $I]],
                        'basis' => ['page' => $url, 'kind' => 'decline', 'drop' => (int)(floor($drop / 10) * 10)],
                        'impact' => min(1.0, log10(1 + ($C0 - $C) * 30 / self::WINDOW_DAYS) / log10(1 + 100)),
                        'confidence' => 0.7, 'value' => $value, 'urgency' => 0.8, 'risk_f' => 0.2,
                        'raw' => ['clicks_earlier' => $C0, 'clicks_latest' => $C, 'drop_percent' => $drop],
                    ]);
                }
            }
        }
        return $out;
    }

    private function queryOpportunities(int $snapshotId, int $websiteId, string $host, array $prio, array $ban): array
    {
        $out = [];
        try {
            // Rows are grouped per query AND page so the page's host can be checked: a domain property also holds
            // other subdomains, and their searches must never show up here.
            $s = $this->db->prepare("SELECT query, page, SUM(impressions) AS i, SUM(clicks) AS c, MIN(position) AS best
                                       FROM gsc_query_page_stats WHERE snapshot_id = ? AND query IS NOT NULL AND query <> '' AND page IS NOT NULL
                                      GROUP BY query, page LIMIT 25000");
            $s->execute([$snapshotId]);
            $byQuery = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (self::canonicalPage((string)$r['page'], $host) === null) { continue; }
                $q = (string)$r['query'];
                if (!isset($byQuery[$q])) { $byQuery[$q] = ['i' => 0, 'c' => 0, 'best' => 999.0]; }
                $byQuery[$q]['i'] += (int)$r['i'];
                $byQuery[$q]['c'] += (int)$r['c'];
                $byQuery[$q]['best'] = min($byQuery[$q]['best'], (float)$r['best']);
            }
            uasort($byQuery, function ($a, $b) { return $b['i'] <=> $a['i']; });
            $n = 0;
            foreach ($byQuery as $full => $r) {
                if ($r['i'] < self::MIN_IMPRESSIONS || $r['best'] <= 20.0 || $this->banned($full, $ban)) { continue; }
                $q = mb_substr(trim($full), 0, 120);
                $I = (int)$r['i'];
                $gap = $I * self::expectedCtr(10) * 0.5;
                $out[] = $this->build('query_no_page', 'page_draft', 'query:' . $websiteId . ':' . sha1(mb_strtolower(trim($full))), $q, [
                    'title' => 'Consider a page for the search "' . $q . '"',
                    'why' => sprintf('People searched for "%s" and saw our site %s times in the last %d days, but no page of ours ranks better than position %d. A page that properly answers this search might earn clicks. Only worth doing if it fits the services you want and the page would say something new.', $q, number_format($I), self::WINDOW_DAYS, (int)floor($r['best'])),
                    'expected_benefit' => self::benefitText(self::clickRange($gap), 'a page that answers this search'),
                    'risk' => 'A new page only helps if it is genuinely useful and different from your other pages. Thin or repetitive pages written for a search term can hurt the whole site, so this is a suggestion to consider, not to rush.',
                    'evidence' => [['query' => $q, 'impressions' => $I, 'clicks' => $r['c'], 'best_position' => round($r['best'], 1)]],
                    'basis' => ['query' => mb_strtolower(trim($full)), 'kind' => 'no_page', 'imp' => self::bucketCount($I)],
                    'impact' => min(1.0, log10(1 + $gap * 30 / self::WINDOW_DAYS) / log10(1 + 200)),
                    'confidence' => min(0.8, $I / 500), 'value' => $this->value($full, $prio), 'urgency' => 0.3, 'risk_f' => 0.4,
                    'raw' => ['impressions' => $I, 'best_position' => round($r['best'], 1)],
                ]);
                if (++$n >= 20) { break; }
            }
        } catch (Throwable $e) {
            error_log('MiaOpportunityScorer queries: ' . $e->getMessage());
        }
        return $out;
    }

    // ---- audit findings -----------------------------------------------------------------------------

    private function auditOpportunities(int $websiteId, string $host, array $pageStats, array $prio, array $ban): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("SELECT f.rule_key, f.severity, f.evidence, p.url
                                       FROM mia_audit_findings f LEFT JOIN mia_site_pages p ON p.id = f.page_id AND p.site_id = f.site_id
                                      WHERE f.site_id = ? AND f.website_id = ? AND f.resolved_at IS NULL AND f.severity <> 'info'
                                      ORDER BY f.rule_key, p.url");
            $s->execute([$this->siteId, $websiteId]);
            $groups = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!isset(self::AUDIT_ACTIONS[$r['rule_key']]) || !isset(SiteAuditRules::RULES[$r['rule_key']])) { continue; }
                $rank = SiteAuditRules::SEVERITY_RANK;
                if (!isset($groups[$r['rule_key']]['sev']) || ($rank[$r['severity']] ?? 9) < ($rank[$groups[$r['rule_key']]['sev']] ?? 9)) {
                    $groups[$r['rule_key']]['sev'] = $r['severity'];            // the worst finding sets the group's severity
                }
                $groups[$r['rule_key']]['rows'][] = $r;
            }
            foreach ($groups as $rule => $g) {
                $rows = array_values(array_filter($g['rows'], function ($r) use ($ban) { return !$r['url'] || !$this->banned((string)$r['url'], $ban); }));
                if (!$rows) { continue; }
                $urls = array_values(array_filter(array_unique(array_column($rows, 'url'))));
                sort($urls);
                $n = count($urls) ?: count($rows);                  // pages affected, not findings (one page can have several)
                $imp = 0;
                foreach ($urls as $u) { $cu = self::canonicalPage($u, $host); $imp += (int)($cu !== null ? ($pageStats[$cu]['impressions'] ?? 0) : 0); }
                $meta = SiteAuditRules::RULES[$rule];
                $type = self::AUDIT_ACTIONS[$rule];
                $value = 0.5;
                foreach ($urls as $u) { $value = max($value, $this->value($u, $prio)); }
                $sev = self::SEVERITY_IMPACT[$g['sev']] ?? 0.25;
                $out[] = array_merge($this->build('audit_fix', $type, 'audit:' . $websiteId . ':' . $rule, $urls[0] ?? '', [
                    'title' => ucfirst(strtolower(self::auditHeadline($rule, $n))),
                    'why' => $meta[1] . '. ' . $meta[2] . ($n > 1 ? " Found on $n pages" : ' Found on 1 page') . ($urls ? ', for example ' . implode(', ', array_slice($urls, 0, 3)) . '.' : '.'),
                    'expected_benefit' => 'Estimate: tidier pages that search engines and visitors can understand more easily. We cannot predict a number of extra visits for this kind of fix.',
                    'risk' => $type === 'fix_technical' ? 'Technical changes should be checked on a copy of the page first. We only prepare the change, and you approve it before anything is touched.' : 'We prepare the wording and you approve it before anything on the site changes.',
                    'evidence' => [['rule' => $rule, 'severity' => $g['sev'], 'pages' => $n, 'examples' => array_slice($urls, 0, 5), 'impressions_on_affected_pages' => $imp]],
                    'basis' => ['rule' => $rule, 'sev' => $g['sev'], 'pages' => self::bucketCount($n)],
                    'impact' => min(1.0, $sev * (0.6 + min($n, 20) / 50) + ($imp > 0 ? min(0.2, log10(1 + $imp) / 20) : 0)),
                    'confidence' => 0.9, 'value' => $value, 'urgency' => $g['sev'] === 'high' ? 0.6 : 0.3, 'risk_f' => $type === 'fix_technical' ? 0.3 : 0.1,
                    'raw' => ['rule' => $rule, 'severity' => $g['sev'], 'pages' => $n, 'impressions_on_pages' => $imp],
                ]), ['pages' => $n]);
            }
        } catch (Throwable $e) {
            error_log('MiaOpportunityScorer audit: ' . $e->getMessage());
        }
        return $out;
    }

    private static function auditHeadline(string $rule, int $n): string
    {
        $p = $n === 1 ? '1 page' : "$n pages";
        $map = [
            'title_missing' => "Add a title to $p", 'title_short' => "Lengthen the title on $p", 'title_long' => "Shorten the title on $p", 'title_duplicate' => "Give $p their own titles",
            'meta_missing' => "Add a description to $p", 'meta_short' => "Lengthen the description on $p", 'meta_long' => "Shorten the description on $p", 'meta_duplicate' => "Give $p their own descriptions",
            'orphan_page' => "Link to $p that nothing links to", 'link_to_redirect' => "Update links that go through a redirect ($p)",
            'h1_missing' => "Add a main heading to $p", 'h1_multiple' => "Use one main heading on $p", 'canonical_missing' => "Name the preferred address of $p",
            'canonical_offsite' => "Fix a canonical pointing to another site ($p)", 'canonical_to_broken' => "Fix a canonical pointing to a broken page ($p)",
            'broken_page' => "Fix $p that cannot be found", 'server_error' => "Fix $p that fail with an error", 'not_https' => 'Move the site to https',
            'noindex_home' => 'Stop the home page telling search engines to ignore it', 'viewport_missing' => "Make $p work properly on phones",
            'duplicate_content' => "Resolve $p with identical content", 'schema_missing_home' => 'Add business details for search engines to the home page',
            'images_missing_alt' => "Describe the images on $p", 'thin_content' => "Add useful detail to $p", 'lang_missing' => "Declare the language on $p",
        ];
        return $map[$rule] ?? "Fix $p";
    }

    // ---- data access --------------------------------------------------------------------------------

    private function latestSnapshot(string $host, DateTimeImmutable $now): ?array
    {
        try {
            $props = $this->db->query("SELECT id, site_url FROM gsc_properties")->fetchAll(PDO::FETCH_ASSOC);
            $ids = [];
            foreach ($props as $p) { if (self::propertyMatchesHost((string)$p['site_url'], $host)) { $ids[] = (int)$p['id']; } }
            if (!$ids) { return null; }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $cut = $now->modify('-' . self::FRESH_DAYS . ' days')->format('Y-m-d');
            $s = $this->db->prepare("SELECT id, property_id, snapshot_date FROM gsc_snapshots WHERE property_id IN ($in) AND snapshot_date >= ? ORDER BY snapshot_date DESC, id DESC LIMIT 1");
            $s->execute(array_merge($ids, [$cut]));
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function propertyExists(string $host): bool
    {
        try {
            foreach ($this->db->query("SELECT site_url FROM gsc_properties")->fetchAll(PDO::FETCH_COLUMN) as $u) {
                if (self::propertyMatchesHost((string)$u, $host)) { return true; }
            }
        } catch (Throwable $e) { /* none */ }
        return false;
    }

    /** A snapshot whose window does not overlap the latest one (>= 26 days older, not older than 50). */
    private function priorSnapshot(int $propertyId, string $latestDate): ?array
    {
        try {
            $d = new DateTimeImmutable($latestDate);
            $s = $this->db->prepare("SELECT id, snapshot_date FROM gsc_snapshots WHERE property_id = ? AND snapshot_date <= ? AND snapshot_date >= ? ORDER BY snapshot_date DESC, id DESC LIMIT 1");
            $s->execute([$propertyId, $d->modify('-' . self::PRIOR_MIN_GAP_DAYS . ' days')->format('Y-m-d'), $d->modify('-' . self::PRIOR_MAX_GAP_DAYS . ' days')->format('Y-m-d')]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<string,array{impressions:int,clicks:int,position:float}> page URL => totals (impression-weighted position) */
    private function pageStats(int $snapshotId, string $host): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("SELECT page, SUM(impressions) AS i, SUM(clicks) AS c, SUM(position * impressions) / NULLIF(SUM(impressions), 0) AS p
                                       FROM gsc_query_page_stats WHERE snapshot_id = ? AND page IS NOT NULL GROUP BY page ORDER BY SUM(impressions) DESC LIMIT " . self::MAX_PAGES);
            $s->execute([$snapshotId]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $url = self::canonicalPage((string)$r['page'], $host);
                if ($url === null) { continue; }
                if (isset($out[$url])) {                          // www and apex forms of one page merge
                    $a = $out[$url]; $i = (int)$r['i'];
                    $tot = $a['impressions'] + $i;
                    $out[$url] = ['impressions' => $tot, 'clicks' => $a['clicks'] + (int)$r['c'],
                                  'position' => $tot > 0 ? ($a['position'] * $a['impressions'] + (float)$r['p'] * $i) / $tot : 0.0];
                } else {
                    $out[$url] = ['impressions' => (int)$r['i'], 'clicks' => (int)$r['c'], 'position' => (float)$r['p']];
                }
            }
        } catch (Throwable $e) {
            error_log('MiaOpportunityScorer pageStats: ' . $e->getMessage());
        }
        return $out;
    }

    private function topQueries(int $snapshotId, string $pageUrl): array
    {
        try {
            $p = parse_url($pageUrl);
            $h = preg_replace('/^www\./', '', strtolower((string)($p['host'] ?? '')));
            $path = $p['path'] ?? '/';
            $alts = [];
            foreach (['', 'www.'] as $w) {
                foreach ([rtrim($path, '/'), rtrim($path, '/') . '/'] as $pp) { $alts['https://' . $w . $h . ($pp === '' ? '/' : $pp)] = true; }
            }
            $alts = array_keys($alts);
            $in = implode(',', array_fill(0, count($alts), '?'));
            $s = $this->db->prepare("SELECT query, SUM(impressions) AS i, SUM(clicks) AS c FROM gsc_query_page_stats
                                      WHERE snapshot_id = ? AND page IN ($in) AND query IS NOT NULL
                                      GROUP BY query ORDER BY SUM(impressions) DESC LIMIT 3");
            $s->execute(array_merge([$snapshotId], $alts));
            return array_map(function ($r) { return ['query' => mb_substr((string)$r['query'], 0, 120), 'impressions' => (int)$r['i'], 'clicks' => (int)$r['c']]; }, $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    // ---- small helpers -------------------------------------------------------------------------------

    /**
     * Does a Search Console property cover this WHOLE website?
     *   sc-domain:x.ca       covers x.ca and every subdomain of it (www, shop, ...);
     *   https://x.ca/        a URL-prefix property covers the host only when the prefix is the root "/" - a property
     *                        for "https://x.ca/customer-b/" is a slice of a site and is not treated as the site.
     * www is ignored on both sides.
     */
    public static function propertyMatchesHost(string $siteUrl, string $host): bool
    {
        $strip = function (string $h): string { return preg_replace('/^www\./', '', strtolower(rtrim(trim($h), '/.'))) ?? $h; };
        $host = $strip($host);
        $siteUrl = trim($siteUrl);
        if (stripos($siteUrl, 'sc-domain:') === 0) {
            $d = $strip(substr($siteUrl, 10));
            return $d !== '' && ($host === $d || substr($host, -strlen($d) - 1) === '.' . $d);
        }
        $p = parse_url($siteUrl);
        if (!is_array($p) || empty($p['host']) || $strip($p['host']) !== $host) { return false; }
        return in_array($p['path'] ?? '/', ['', '/'], true) && !isset($p['query']);
    }

    /** Page URL from GSC -> comparable URL on this host (https, www dropped, no query/fragment), or null if elsewhere. */
    public static function canonicalPage(string $page, string $host): ?string
    {
        $p = parse_url(trim($page));
        if ($p === false || empty($p['host'])) { return null; }
        $strip = function (string $h): string { return preg_replace('/^www\./', '', strtolower($h)) ?? $h; };
        if ($strip($p['host']) !== $strip($host)) { return null; }
        return 'https://' . strtolower($host) . ($p['path'] ?? '/');
    }

    private static function shortUrl(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
        return $path === '/' ? 'the home page' : $path;
    }

    /** @param array<string,string> $memory @return string[] normalised terms (letters/digits separated by single spaces) */
    private function terms(array $memory): array
    {
        $t = [];
        foreach ($memory as $k => $v) {
            foreach (array_merge([$k], explode(',', (string)$v)) as $x) {
                $x = self::norm($x);
                if (mb_strlen($x, 'UTF-8') >= 3) { $t[$x] = true; }
            }
        }
        return array_keys($t);
    }

    /** Lower-case, every run of non-letters/digits becomes one space ("/services/Snow-Removal" -> "services snow removal"). */
    private static function norm(string $s): string
    {
        return trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s, 'UTF-8')));
    }

    /** Whole-word / whole-phrase match: "snow" matches "snow removal" but not "snowdon"; "car" does not match "scar". */
    private static function hasTerm(string $text, string $term): bool
    {
        return strpos(' ' . self::norm($text) . ' ', ' ' . $term . ' ') !== false;
    }

    private function banned(string $text, array $ban): bool
    {
        foreach ($ban as $b) { if (self::hasTerm($text, $b)) { return true; } }
        return false;
    }

    private function value(string $text, array $prio): float
    {
        foreach ($prio as $p) { if (self::hasTerm($text, $p)) { return 1.0; } }
        return 0.5;
    }

    /** Assemble one opportunity with its score and the inputs behind it. */
    private function build(string $kind, string $type, string $key, string $subject, array $x): array
    {
        $factors = ['impact' => round((float)$x['impact'], 3), 'confidence' => round((float)$x['confidence'], 3), 'value' => round((float)$x['value'], 3),
                    'urgency' => round((float)$x['urgency'], 3), 'effort' => self::EFFORT[$type] ?? 0.4, 'risk' => round((float)$x['risk_f'], 3)];
        return [
            'kind' => $kind, 'type' => $type, 'opportunity_key' => $key, 'subject' => $subject,
            'title' => mb_substr($x['title'], 0, 160), 'why' => mb_substr($x['why'], 0, 600),
            'expected_benefit' => mb_substr($x['expected_benefit'], 0, 400), 'risk' => mb_substr($x['risk'], 0, 400),
            'evidence' => $x['evidence'], 'evidence_basis' => $x['basis'],
            'score' => self::score($factors),
            'score_inputs' => ['factors' => $factors, 'weights' => ['impact' => self::W_IMPACT, 'confidence' => self::W_CONFIDENCE, 'value' => self::W_VALUE, 'urgency' => self::W_URGENCY],
                               'value_note' => $factors['value'] >= 1.0 ? 'matches a service you told us to prioritise' : 'neutral until profit data is connected', 'raw' => $x['raw']],
        ];
    }
}
