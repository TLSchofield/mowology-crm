<?php
/**
 * WebsiteWatchRules — what Mia reads out of Search Console each week. Pure, no DB, no HTTP.
 *
 * Inputs are Search Console rows for the last 28 days and the 28 before
 * (WebsiteWatchRules::periods()). Mia SUGGESTS only — every suggestion carries a link to open
 * the page in the CMS; nothing here ever edits a page.
 *
 *   top_queries        the searches that brought the most clicks, with the change
 *   losing_pages       pages that lost ≥ LOSS_SHARE of their clicks (and ≥ LOSS_MIN clicks)
 *   low_ctr            searches seen often (≥ LOWCTR_MIN_IMPR) on page 1–2 whose click rate
 *                      is under half what that position normally gets → title/meta suggestion
 *   zero_services      service pages that showed up in no search at all
 *   stale_seasonal     pages for the season we're in that haven't been touched since well
 *                      before it started
 *   headline           the one insight for the card and Charlie's brief
 */
class WebsiteWatchRules
{
    public const LOSS_SHARE = 0.3;
    public const LOSS_MIN = 3;
    public const LOWCTR_MIN_IMPR = 100;
    public const LOWCTR_MAX_POS = 15.0;
    /** A seasonal page untouched since this many days before its season began is stale. */
    public const STALE_LEAD_DAYS = 30;

    /** Typical organic CTR by position (rounded industry curve), for "under half of normal". */
    public static function expectedCtr(float $position): float
    {
        $p = max(1.0, $position);
        if ($p < 1.5) return 0.28;
        if ($p < 2.5) return 0.15;
        if ($p < 3.5) return 0.10;
        if ($p < 4.5) return 0.07;
        if ($p < 5.5) return 0.05;
        if ($p < 10.5) return 0.03;
        if ($p < 15.5) return 0.01;
        return 0.005;
    }

    /**
     * Search Console lags ~3 days. Current = 28 days ending 3 days ago; previous = the 28 before.
     * @return array{cur: array{0: string, 1: string}, prev: array{0: string, 1: string}}
     */
    public static function periods(DateTimeImmutable $today): array
    {
        $end = $today->modify('-3 days');
        $start = $end->modify('-27 days');
        $pEnd = $start->modify('-1 day');
        $pStart = $pEnd->modify('-27 days');
        return ['cur' => [$start->format('Y-m-d'), $end->format('Y-m-d')], 'prev' => [$pStart->format('Y-m-d'), $pEnd->format('Y-m-d')]];
    }

    /**
     * Search Console API rows → [key => [clicks, impressions, ctr, position]].
     * @param array<int, array{keys: string[], clicks: float, impressions: float, ctr: float, position: float}> $rows
     */
    public static function byKey(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (string)($r['keys'][0] ?? '');
            if ($k === '') continue;
            $out[$k] = [
                'clicks' => (int)round((float)($r['clicks'] ?? 0)),
                'impressions' => (int)round((float)($r['impressions'] ?? 0)),
                'ctr' => (float)($r['ctr'] ?? 0),
                'position' => (float)($r['position'] ?? 0),
            ];
        }
        return $out;
    }

    /** "https://mowology.ca/services/hedge-trimming?x" → "/services/hedge-trimming" */
    public static function path(string $url): string
    {
        $p = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $p = preg_replace('#\.php$#', '', $p);
        $p = rtrim($p, '/');
        return $p === '' ? '/' : $p;
    }

    /** @param array<string, array> $cur @param array<string, array> $prev */
    public static function topQueries(array $cur, array $prev, int $n = 10): array
    {
        $rows = [];
        foreach ($cur as $q => $m) {
            if ($m['clicks'] <= 0) continue;
            $rows[] = ['query' => $q, 'clicks' => $m['clicks'], 'prev_clicks' => (int)($prev[$q]['clicks'] ?? 0),
                       'impressions' => $m['impressions'], 'position' => round($m['position'], 1)];
        }
        usort($rows, fn($a, $b) => [$b['clicks'], $b['impressions']] <=> [$a['clicks'], $a['impressions']]);
        return array_slice($rows, 0, $n);
    }

    public static function losingPages(array $curPages, array $prevPages, int $n = 5): array
    {
        $rows = [];
        foreach ($prevPages as $page => $m) {
            $before = (int)$m['clicks'];
            $now = (int)($curPages[$page]['clicks'] ?? 0);
            $lost = $before - $now;
            if ($before <= 0 || $lost < self::LOSS_MIN || $lost / $before < self::LOSS_SHARE) continue;
            $rows[] = ['page' => $page, 'path' => self::path($page), 'clicks' => $now, 'prev_clicks' => $before, 'lost' => $lost,
                       'share' => round($lost / $before, 2)];
        }
        usort($rows, fn($a, $b) => $b['lost'] <=> $a['lost']);
        return array_slice($rows, 0, $n);
    }

    /**
     * @param array<string, array> $curQueries query => metrics
     * @param array<string, string> $queryTopPage query => the page that ranked for it (optional)
     */
    public static function lowCtr(array $curQueries, array $queryTopPage = [], int $n = 5): array
    {
        $rows = [];
        foreach ($curQueries as $q => $m) {
            if ($m['impressions'] < self::LOWCTR_MIN_IMPR || $m['position'] <= 0 || $m['position'] > self::LOWCTR_MAX_POS) continue;
            $ctr = $m['impressions'] > 0 ? $m['clicks'] / $m['impressions'] : 0.0;
            $exp = self::expectedCtr($m['position']);
            if ($ctr >= $exp * 0.5) continue;
            $page = $queryTopPage[$q] ?? '';
            $rows[] = ['query' => $q, 'impressions' => $m['impressions'], 'clicks' => $m['clicks'], 'ctr' => round($ctr, 4),
                       'expected_ctr' => $exp, 'position' => round($m['position'], 1), 'page' => $page, 'path' => $page !== '' ? self::path($page) : '',
                       'missed' => (int)round($m['impressions'] * $exp - $m['clicks']),
                       'title_idea' => self::titleIdea($q)];
        }
        usort($rows, fn($a, $b) => $b['missed'] <=> $a['missed']);
        return array_slice($rows, 0, $n);
    }

    /** A title that leads with the searcher's own words, under 60 characters. */
    public static function titleIdea(string $query): string
    {
        $q = trim(preg_replace('/\s+/', ' ', $query));
        $t = ucfirst($q);
        if (stripos($q, 'vancouver') === false && mb_strlen($t) + 12 <= 48) $t .= ' in Vancouver';
        $t .= ' | Mowology';
        return mb_strlen($t) > 60 ? mb_substr($t, 0, 57) . '...' : $t;
    }

    /**
     * @param array<int, array{path: string, title?: string}> $servicePages
     * @param array<string, array> $curPages page url => metrics
     */
    public static function zeroImpressionServices(array $servicePages, array $curPages): array
    {
        $seen = [];
        foreach ($curPages as $url => $m) {
            if ((int)$m['impressions'] > 0) $seen[self::path($url)] = true;
        }
        $out = [];
        foreach ($servicePages as $sp) {
            $p = self::path((string)$sp['path']);
            if (!isset($seen[$p])) $out[] = ['path' => $p, 'title' => (string)($sp['title'] ?? $p)];
        }
        return $out;
    }

    /**
     * Seasons in effect today (seo_seasons rows: start_month/day, end_month/day, services_json).
     * The all-year "strata" season is ignored: it makes nothing seasonal.
     */
    public static function currentSeasons(array $seasons, DateTimeImmutable $today): array
    {
        $md = $today->format('m-d');
        $out = [];
        foreach ($seasons as $s) {
            if (isset($s['is_active']) && !(int)$s['is_active']) continue;
            $from = sprintf('%02d-%02d', (int)$s['start_month'], (int)$s['start_day']);
            $to   = sprintf('%02d-%02d', (int)$s['end_month'], (int)$s['end_day']);
            if ($from === '01-01' && $to === '12-31') continue;
            $in = $from <= $to ? ($md >= $from && $md <= $to) : ($md >= $from || $md <= $to);
            if (!$in) continue;
            // When did this season begin (this cycle)?
            $y = (int)$today->format('Y');
            $start = new DateTimeImmutable($y . '-' . $from);
            if ($start > $today) $start = $start->modify('-1 year');
            $services = json_decode((string)($s['services_json'] ?? '[]'), true) ?: [];
            $out[] = ['key' => (string)$s['season_key'], 'label' => (string)$s['label'], 'started' => $start->format('Y-m-d'), 'services' => $services];
        }
        return $out;
    }

    /**
     * @param array<int, array{path: string, title?: string, updated_at?: string}> $pages site pages with their last edit
     */
    public static function staleSeasonal(array $seasons, array $pages, DateTimeImmutable $today): array
    {
        $out = [];
        foreach (self::currentSeasons($seasons, $today) as $s) {
            $cutoff = (new DateTimeImmutable($s['started']))->modify('-' . self::STALE_LEAD_DAYS . ' days')->format('Y-m-d');
            foreach ($pages as $pg) {
                $slug = strtolower(basename(self::path((string)$pg['path'])));
                $match = false;
                foreach ($s['services'] as $svc) {
                    if ($svc !== '' && strpos($slug, strtolower((string)$svc)) !== false) { $match = true; break; }
                }
                if (!$match) continue;
                $upd = substr((string)($pg['updated_at'] ?? ''), 0, 10);
                if ($upd !== '' && $upd >= $cutoff) continue;
                $out[] = ['path' => self::path((string)$pg['path']), 'title' => (string)($pg['title'] ?? $slug), 'season' => $s['label'],
                          'updated_at' => $upd ?: null];
            }
        }
        return $out;
    }

    /**
     * The whole weekly report.
     * @param array $in [cur_queries, prev_queries, cur_pages, prev_pages (byKey maps), query_page (query => page),
     *                   service_pages, site_pages, seasons]
     */
    public static function analyze(array $in, DateTimeImmutable $today): array
    {
        $r = [
            'top_queries'    => self::topQueries($in['cur_queries'] ?? [], $in['prev_queries'] ?? []),
            'losing_pages'   => self::losingPages($in['cur_pages'] ?? [], $in['prev_pages'] ?? []),
            'low_ctr'        => self::lowCtr($in['cur_queries'] ?? [], $in['query_page'] ?? []),
            'zero_services'  => self::zeroImpressionServices($in['service_pages'] ?? [], $in['cur_pages'] ?? []),
            'stale_seasonal' => self::staleSeasonal($in['seasons'] ?? [], $in['site_pages'] ?? [], $today),
            'totals'         => [
                'clicks' => array_sum(array_column($in['cur_pages'] ?? [], 'clicks')),
                'prev_clicks' => array_sum(array_column($in['prev_pages'] ?? [], 'clicks')),
                'impressions' => array_sum(array_column($in['cur_pages'] ?? [], 'impressions')),
            ],
        ];
        $r['suggestions'] = self::suggestions($r);
        $r['headline'] = $r['suggestions'][0]['text'] ?? ($r['top_queries']
            ? 'Top search this month: "' . $r['top_queries'][0]['query'] . '" (' . $r['top_queries'][0]['clicks'] . ' clicks).'
            : 'No search clicks recorded in the last 28 days.');
        return $r;
    }

    /** Ordered fixes, most valuable first. Each: type, text, path. */
    public static function suggestions(array $r): array
    {
        $s = [];
        foreach ($r['losing_pages'] as $p) {
            $s[] = ['type' => 'losing', 'path' => $p['path'],
                    'text' => sprintf('%s lost %d of its %d clicks from Google. Check the page still answers what people search for, and that its title matches.', $p['path'], $p['lost'], $p['prev_clicks'])];
        }
        foreach ($r['low_ctr'] as $q) {
            $s[] = ['type' => 'low_ctr', 'path' => $q['path'],
                    'text' => sprintf('"%s" showed %d times at position %s but got %d clicks. Try the title "%s" and a meta description that answers it.',
                        $q['query'], $q['impressions'], $q['position'], $q['clicks'], $q['title_idea'])];
        }
        foreach ($r['stale_seasonal'] as $p) {
            $s[] = ['type' => 'stale', 'path' => $p['path'],
                    'text' => sprintf('%s is in season (%s) but hasn\'t been updated since %s. Refresh the photos and the opening paragraph.', $p['path'], $p['season'], $p['updated_at'] ?? 'it was made')];
        }
        foreach ($r['zero_services'] as $p) {
            $s[] = ['type' => 'zero', 'path' => $p['path'],
                    'text' => sprintf('%s didn\'t appear in a single Google search in 28 days. Link to it from the home and services pages, and check it\'s in the sitemap.', $p['path'])];
        }
        return $s;
    }

    /** "Open in CMS" for a path: the page's editor when it's a CMS page, else the page list. */
    public static function cmsLink(string $path, array $cmsIdBySlug): string
    {
        $slug = trim(self::path($path), '/');
        $slug = $slug === '' ? 'home' : $slug;
        foreach ([$slug, basename($slug)] as $k) {
            if (isset($cmsIdBySlug[$k])) return '/cms/cms-page-editor.php?id=' . (int)$cmsIdBySlug[$k];
        }
        return '/crm/cms-pages_appstack.php';
    }
}
