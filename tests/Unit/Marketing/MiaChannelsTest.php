<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** In-memory SQLite that answers SHOW TABLES like MySQL. */
class ChannelsTestPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        @$this->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match("/^\s*SHOW TABLES LIKE '([a-z_]+)'/i", $query, $m)) {
            $query = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '{$m[1]}'";
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

/**
 * Mia's channels: Facebook's Graph version + IG metrics, the reconnect state, Search Console
 * rules, NAP comparison, review replies in Tim's voice, the season themes, GBP request shapes,
 * and the brief items Charlie gets.
 */
final class MiaChannelsTest extends TestCase
{
    // ── Facebook / Instagram ─────────────────────────────────────────────

    public function testGraphVersionIsCurrentAndOnlyInOnePlace(): void
    {
        $this->assertMatchesRegularExpression('/^v\d+\.0$/', MetaService::graphVersion());
        $this->assertGreaterThanOrEqual(23, (int)substr(MetaService::graphVersion(), 1));

        $root = dirname(__DIR__, 3);
        $hits = [];
        foreach (['app', 'public/crm', 'public/includes', 'public/cms'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!in_array($f->getExtension(), ['php', 'js'], true)) continue;
                $src = (string)file_get_contents($f->getPathname());
                if (preg_match('#facebook\.com/v\d+\.\d+#', $src)) $hits[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        $this->assertSame([], $hits, 'Graph API version must come from MetaService::graphVersion(), not a literal');
    }

    public function testInstagramMetricsUseViewsNotImpressions(): void
    {
        $this->assertNotContains('impressions', MetaService::INSTAGRAM_METRICS);
        $this->assertContains('views', MetaService::INSTAGRAM_METRICS);
        $this->assertContains('reach', MetaService::INSTAGRAM_METRICS);
        $row = MetaService::mapInstagramInsights(['views' => 120, 'reach' => 80, 'likes' => 9, 'comments' => 2, 'shares' => 1, 'saved' => ['a' => 2, 'b' => 1]]);
        $this->assertSame(['impressions' => 120, 'reach' => 80, 'clicks' => 0, 'likes' => 9, 'comments_count' => 2, 'shares' => 1, 'saves' => 3], $row);
    }

    public function testUndecryptableTokenSaysReconnectFacebook(): void
    {
        $acct = ['platform' => 'facebook', 'account_name' => 'Mowology', 'is_active' => 1, 'is_verified' => 0,
                 'meta_json' => json_encode(['health' => ['status' => 'error', 'reason' => 'decrypt_failed', 'detail' => 'x']])];
        $s = SocialConnectionState::fromAccounts([$acct, ['platform' => 'instagram', 'is_active' => 1, 'meta_json' => '{}', 'account_name' => 'ig']]);
        $this->assertTrue($s['needs_reconnect']);
        $this->assertSame('reconnect', $s['facebook']['status']);
        $this->assertSame('connected', $s['instagram']['status']);
        $this->assertStringStartsWith('Reconnect Facebook', $s['message']);
        $this->assertStringNotContainsStringIgnoringCase('no page token', $s['message']);
        $this->assertStringContainsString('oauth-init&platform=facebook', $s['action_url']);
    }

    public function testHealthyAndMissingAccounts(): void
    {
        $s = SocialConnectionState::fromAccounts([['platform' => 'facebook', 'is_active' => 1, 'account_name' => 'M', 'meta_json' => json_encode(['health' => ['status' => 'ok']])]]);
        $this->assertFalse($s['needs_reconnect']);
        $this->assertSame('connected', $s['facebook']['status']);
        $this->assertSame('not_connected', $s['gbp']['status']);
    }

    // ── Search Console rules ────────────────────────────────────────────

    public function testPeriodsAre28DaysEachAndLagThreeDays(): void
    {
        $p = WebsiteWatchRules::periods(new DateTimeImmutable('2026-10-06'));
        $this->assertSame(['2026-09-06', '2026-10-03'], $p['cur']);
        $this->assertSame(['2026-08-09', '2026-09-05'], $p['prev']);
    }

    public function testLosingPages(): void
    {
        $m = fn(int $c, int $i = 100) => ['clicks' => $c, 'impressions' => $i, 'ctr' => 0, 'position' => 5];
        $prev = ['https://mowology.ca/services/hedge-trimming' => $m(20), 'https://mowology.ca/' => $m(50), 'https://mowology.ca/about' => $m(4)];
        $cur  = ['https://mowology.ca/services/hedge-trimming' => $m(8), 'https://mowology.ca/' => $m(46), 'https://mowology.ca/about' => $m(1)];
        $lost = WebsiteWatchRules::losingPages($cur, $prev);
        $this->assertCount(2, $lost, 'home lost 8% (kept); about lost 3 of 4 (75%) — counts');
        $this->assertSame('/services/hedge-trimming', $lost[0]['path']);
        $this->assertSame(12, $lost[0]['lost']);
    }

    public function testLowCtrFlagsPageOneQueriesWithFewClicks(): void
    {
        $cur = [
            'hedge trimming vancouver' => ['clicks' => 2, 'impressions' => 400, 'ctr' => 0.005, 'position' => 4.2],   // expected 7% → 28
            'lawn mowing'              => ['clicks' => 30, 'impressions' => 400, 'ctr' => 0.075, 'position' => 4.0],  // healthy
            'rare query'               => ['clicks' => 0, 'impressions' => 40, 'ctr' => 0, 'position' => 3.0],       // too few impressions
            'deep page'                => ['clicks' => 0, 'impressions' => 900, 'ctr' => 0, 'position' => 40.0],     // not on page 1-2
        ];
        $low = WebsiteWatchRules::lowCtr($cur, ['hedge trimming vancouver' => 'https://mowology.ca/services/hedge-trimming']);
        $this->assertCount(1, $low);
        $this->assertSame('hedge trimming vancouver', $low[0]['query']);
        $this->assertSame('/services/hedge-trimming', $low[0]['path']);
        $this->assertLessThanOrEqual(60, mb_strlen($low[0]['title_idea']));
        $this->assertStringContainsString('Hedge trimming vancouver', $low[0]['title_idea']);
    }

    public function testZeroImpressionServicesAndStaleSeasonalPages(): void
    {
        $cur = ['https://mowology.ca/services/fall-cleanup.php' => ['clicks' => 1, 'impressions' => 50, 'ctr' => 0, 'position' => 9]];
        $zero = WebsiteWatchRules::zeroImpressionServices([['path' => '/services/fall-cleanup'], ['path' => '/services/snow-removal', 'title' => 'Snow Removal']], $cur);
        $this->assertSame([['path' => '/services/snow-removal', 'title' => 'Snow Removal']], $zero);

        $seasons = [
            ['season_key' => 'fall-cleanup', 'label' => 'Fall Cleanup Season', 'start_month' => 9, 'start_day' => 1, 'end_month' => 11, 'end_day' => 15, 'services_json' => '["leaf-cleanup","fall-cleanup"]', 'is_active' => 1],
            ['season_key' => 'strata', 'label' => 'All year', 'start_month' => 1, 'start_day' => 1, 'end_month' => 12, 'end_day' => 31, 'services_json' => '["fall-cleanup"]', 'is_active' => 1],
            ['season_key' => 'spring-cleanup', 'label' => 'Spring', 'start_month' => 3, 'start_day' => 15, 'end_month' => 5, 'end_day' => 31, 'services_json' => '["spring-cleanup"]', 'is_active' => 1],
        ];
        $pages = [
            ['path' => '/services/fall-cleanup', 'title' => 'Fall Cleanup', 'updated_at' => '2025-10-01'],
            ['path' => '/services/spring-cleanup', 'title' => 'Spring', 'updated_at' => '2024-01-01'],
            ['path' => '/fall-cleanup-burnaby', 'title' => 'Burnaby', 'updated_at' => '2026-08-20'],
        ];
        $stale = WebsiteWatchRules::staleSeasonal($seasons, $pages, new DateTimeImmutable('2026-10-06'));
        $this->assertCount(1, $stale, 'spring is out of season; Burnaby was updated within 30 days of the season start');
        $this->assertSame('/services/fall-cleanup', $stale[0]['path']);
    }

    public function testAnalyzeOrdersSuggestionsAndLinksToCms(): void
    {
        $m = fn(int $c, int $i, float $p = 5) => ['clicks' => $c, 'impressions' => $i, 'ctr' => $i ? $c / $i : 0, 'position' => $p];
        $r = WebsiteWatchRules::analyze([
            'cur_queries' => ['mowology' => $m(40, 60, 1)], 'prev_queries' => ['mowology' => $m(35, 50, 1)],
            'cur_pages' => ['https://mowology.ca/services/hedge-trimming' => $m(2, 300)],
            'prev_pages' => ['https://mowology.ca/services/hedge-trimming' => $m(15, 300)],
            'service_pages' => [], 'site_pages' => [], 'seasons' => [],
        ], new DateTimeImmutable('2026-10-06'));
        $this->assertSame('losing', $r['suggestions'][0]['type']);
        $this->assertSame($r['suggestions'][0]['text'], $r['headline']);
        $this->assertSame('mowology', $r['top_queries'][0]['query']);
        $this->assertSame('/cms/cms-page-editor.php?id=7', WebsiteWatchRules::cmsLink('/services/hedge-trimming', ['hedge-trimming' => 7]));
        $this->assertSame('/cms/cms-page-editor.php?id=1', WebsiteWatchRules::cmsLink('/', ['home' => 1]));
        $this->assertSame('/crm/cms-pages_appstack.php', WebsiteWatchRules::cmsLink('/nowhere', []));
        $this->assertSame('sc-domain:mowology.ca', WebsiteWatchService::apiSite('mowology.ca'));
        $this->assertSame('sc-domain:mowology.ca', WebsiteWatchService::apiSite('sc-domain:mowology.ca'));
    }

    // ── NAP ─────────────────────────────────────────────────────────────

    public function testNapComparison(): void
    {
        $master = ['name' => 'Mowology Lawns & Landscapes Ltd.', 'address' => '123 West 4th Avenue, Vancouver, BC V6K 1A1',
                   'phone' => '(778) 846-9273', 'website' => 'https://mowology.ca'];
        $same = ListingsService::compare($master, ['name' => 'Mowology Lawns and Landscapes', 'address' => '123 W 4th Ave Vancouver BC V6K1A1',
                                                    'phone' => '+1 778-846-9273', 'website' => 'www.mowology.ca/']);
        $this->assertSame('match', $same['status'], implode('; ', $same['issues']));

        $diff = ListingsService::compare($master, ['phone' => '604-555-0100', 'name' => '']);
        $this->assertSame('mismatch', $diff['status']);
        $this->assertSame('differs', $diff['fields']['phone']);
        $this->assertSame('blank', $diff['fields']['name']);

        $this->assertSame('unchecked', ListingsService::compare($master, [])['status']);
        $this->assertSame('Mon–Fri 08:00–16:00', ListingsService::hoursText(json_encode(['hours' => [
            'monday' => ['open' => true, 'start' => '08:00', 'end' => '16:00'], 'tuesday' => ['open' => true, 'start' => '08:00', 'end' => '16:00'],
            'wednesday' => ['open' => true, 'start' => '08:00', 'end' => '16:00'], 'thursday' => ['open' => true, 'start' => '08:00', 'end' => '16:00'],
            'friday' => ['open' => true, 'start' => '08:00', 'end' => '16:00'], 'saturday' => ['open' => false], 'sunday' => ['open' => false]]])));
    }

    // ── Review replies ──────────────────────────────────────────────────

    public function testGoodReviewReplyFollowsTimsRules(): void
    {
        $r = ReviewReplyDrafter::draft(['id' => 1, 'reviewer_name' => 'Jane D.', 'rating' => 5, 'comment' => 'Great hedge work!!! Super tidy!'], ['phone' => '(778) 846-9273']);
        $this->assertStringNotContainsString('!', $r);
        $this->assertStringContainsString('Jane', $r);
        $this->assertStringContainsString('hedge trimming', $r);
        $this->assertStringEndsWith('Tim', $r);
        $this->assertLessThanOrEqual(ReviewReplyDrafter::MAX_WORDS, str_word_count($r));
        $this->assertSame([], ReviewReplyDrafter::violations($r, 'Jane D.'));
    }

    public function testPoorReviewApologisesAndGivesThePhone(): void
    {
        $r = ReviewReplyDrafter::draft(['reviewer_name' => 'Sam', 'rating' => 1, 'comment' => 'They missed the back lawn.'], ['phone' => '(778) 846-9273']);
        $this->assertStringContainsString("I'm sorry", $r);
        $this->assertStringContainsString('(778) 846-9273', $r);
        $this->assertStringContainsString('lawn care', $r);
        $this->assertStringNotContainsString('!', $r);
    }

    public function testAnonymousReviewerAndViolations(): void
    {
        $r = ReviewReplyDrafter::draft(['reviewer_name' => 'A Google User', 'rating' => 4, 'comment' => ''], ['service' => 'fall_cleanup']);
        $this->assertStringNotContainsString('Google User', $r);
        $this->assertStringContainsString('fall cleanup', $r);
        $this->assertSame(['exclamation', 'no_name'], ReviewReplyDrafter::violations('Thanks so much!', 'Jane'));
        $this->assertContains('too_long', ReviewReplyDrafter::violations(str_repeat('word ', 70) . 'Jane', 'Jane'));
        $this->assertContains('banned_word', ReviewReplyDrafter::violations('Jane, we were thrilled.', 'Jane'));
    }

    // ── Season themes ───────────────────────────────────────────────────

    /** @dataProvider themeDates */
    public function testThemePickerByDate(string $date, string $expectFirst, array $mustInclude): void
    {
        $src = new MiaSeasonThemes();
        $keys = array_column($src->themesFor(new DateTimeImmutable($date)), 'key');
        $this->assertSame($expectFirst, $keys[0], $date . ': ' . implode(',', $keys));
        foreach ($mustInclude as $k) $this->assertContains($k, $keys, $date);
    }

    public static function themeDates(): array
    {
        return [
            'January'         => ['2027-01-12', 'spring_early_bird', []],
            'before March 15' => ['2027-03-01', 'hedges_before_nesting', ['spring_cleanup']],
            'after March 15'  => ['2027-03-20', 'spring_cleanup', []],
            'May'             => ['2027-05-10', 'aeration_overseed', ['moss_and_beds']],
            'July'            => ['2027-07-10', 'summer_lawn', ['snow_salt_contracts']],
            'mid-August'      => ['2027-08-20', 'hedges_after_nesting', ['summer_lawn']],
            'early October'   => ['2026-10-06', 'fall_aeration', ['leaf_cleanup', 'bulbs', 'irrigation_blowout']],
            'December'        => ['2026-12-10', 'winter_quiet', ['leaf_cleanup']],
        ];
    }

    public function testNoHedgeCuttingThemeDuringNesting(): void
    {
        $src = new MiaSeasonThemes();
        foreach (['2027-03-15', '2027-05-01', '2027-07-01', '2027-08-14'] as $d) {
            $keys = array_column($src->themesFor(new DateTimeImmutable($d)), 'key');
            $this->assertNotContains('hedges_before_nesting', $keys, $d);
            $this->assertNotContains('hedges_after_nesting', $keys, $d);
        }
    }

    public function testThemeCopyRules(): void
    {
        foreach (MiaSeasonThemes::all() as $t) {
            $all = $t['google_post'] . ' ' . $t['social_line'];
            $this->assertStringNotContainsString('!', $all, $t['key']);
            $this->assertDoesNotMatchRegularExpression('/\b(elevate|thrilled|exceptional|solution)\b/i', $all, $t['key']);
            $this->assertSame($all, str_replace(['http', 'mowology.ca', '846-9273'], '', $all), $t['key'] . ': no links or phone in post text');
        }
        // Summer: never tell anyone to water a lawn (Stage 2/3 restrictions).
        $src = new MiaSeasonThemes();
        foreach ($src->themesFor(new DateTimeImmutable('2027-07-15')) as $t) {
            $this->assertDoesNotMatchRegularExpression('/water (your|the) lawn|watering your lawn|keep .* watered/i', $t['google_post'] . ' ' . $t['social_line'], $t['key']);
        }
    }

    public function testPickSkipsRecentThemesAndFiltersAudience(): void
    {
        $src = new MiaSeasonThemes();
        $d = new DateTimeImmutable('2026-10-06');
        $this->assertSame('fall_aeration', MiaSeasonThemes::pick($src, $d)['key']);
        $this->assertNotSame('fall_aeration', MiaSeasonThemes::pick($src, $d, ['fall_aeration'])['key']);
        $july = new DateTimeImmutable('2027-07-10');
        $this->assertSame('snow_salt_contracts', MiaSeasonThemes::pick($src, $july, ['summer_lawn'], [], 'pm')['key']);
        $this->assertNotSame('snow_salt_contracts', MiaSeasonThemes::pick($src, $july, ['summer_lawn'], [], 'home')['key'] ?? null);
        $this->assertSame(4, MiaSeasonThemes::cadenceFor('0,0,0,0,0,0,0,0,2,4,3,1', 10));
        $this->assertSame(0, MiaSeasonThemes::cadenceFor(null, 10));
        $this->assertTrue(MiaSeasonThemes::matchesService(MiaSeasonThemes::all()[1], 'Hedge Trimming'));
    }

    public function testGooglePostTextStripsPhonesAndLinks(): void
    {
        $t = MiaChannelsService::googlePostText(['google_post' => 'Call (778) 846-9273 or visit https://mowology.ca/x today!']);
        $this->assertSame('Call or visit today.', $t);
    }

    // ── GBP request shapes ──────────────────────────────────────────────

    public function testGbpUrlsAndRows(): void
    {
        $loc = 'accounts/111/locations/222';
        $this->assertSame('locations/222', GbpService::infoName($loc));
        $this->assertSame('https://mybusiness.googleapis.com/v4/accounts/111/locations/222/reviews?pageSize=50', GbpService::reviewsUrl($loc));
        $this->assertSame('https://mybusiness.googleapis.com/v4/accounts/111/locations/222/reviews/abc/reply', GbpService::replyUrl($loc . '/reviews/abc'));
        $this->assertStringStartsWith('https://mybusinessbusinessinformation.googleapis.com/v1/locations/222?readMask=', GbpService::infoUrl($loc));
        $row = GbpService::reviewRow(['name' => $loc . '/reviews/abc', 'reviewer' => ['displayName' => 'Jane'], 'starRating' => 'FOUR', 'comment' => 'Nice', 'createTime' => '2026-10-01T10:00:00Z']);
        $this->assertSame(4, $row['rating']);
        $this->assertSame('2026-10-01', $row['review_date']);
        $this->assertFalse($row['has_reply']);
        $call = GbpService::postBody('Hi', 'CALL', 'https://x');
        $this->assertSame(['actionType' => 'CALL'], $call['callToAction']);
        $this->assertSame(1500, mb_strlen(GbpService::postBody(str_repeat('a', 2000))['summary']));
    }

    public function testGbpIsDraftsOnlyUntilApprovedAndConnected(): void
    {
        $db = new ChannelsTestPdo();
        $db->exec("CREATE TABLE social_accounts (id INTEGER PRIMARY KEY, platform TEXT, account_name TEXT, is_active INT, is_verified INT, meta_json TEXT,
                   location_id_external TEXT, access_token_enc TEXT, refresh_token_enc TEXT, token_expires_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $m = (new GbpService($db))->mode();
        $this->assertSame('drafts_only', $m['mode']);
        $this->assertSame(GbpService::ACCESS_FORM_URL, $m['steps'][0]['url']);
        $db->exec("INSERT INTO ops_settings VALUES ('gbp_api_approved', '1', '')");
        $this->assertSame('drafts_only', (new GbpService($db))->mode()['mode'], 'approved but not connected');
    }

    // ── Charlie's brief ─────────────────────────────────────────────────

    public function testBriefItemsHaveTheAgreedPriorities(): void
    {
        $db = new ChannelsTestPdo();
        $db->exec("CREATE TABLE social_accounts (id INTEGER PRIMARY KEY, platform TEXT, account_name TEXT, is_active INT, is_verified INT, meta_json TEXT, location_id_external TEXT, token_expires_at TEXT)");
        $db->exec("INSERT INTO social_accounts (platform, account_name, is_active, is_verified, meta_json) VALUES ('facebook', 'Mowology', 1, 0, '" . json_encode(['health' => ['status' => 'error', 'reason' => 'decrypt_failed']]) . "')");
        $db->exec("CREATE TABLE gbp_reviews (id INTEGER PRIMARY KEY, source TEXT, external_id TEXT, reviewer_name TEXT, rating INT, comment TEXT, review_date TEXT,
                   reply_draft TEXT, reply_text TEXT, replied_via TEXT, status TEXT DEFAULT 'waiting', replied_by INT, replied_at TEXT, created_by INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE mia_channel_drafts (id INTEGER PRIMARY KEY, kind TEXT, theme_key TEXT, week_start TEXT, title TEXT, body TEXT, cta_type TEXT, cta_url TEXT,
                   social_post_id INT, visit_id INT, media_id INT, photo_url TEXT, status TEXT DEFAULT 'draft', external_id TEXT, decided_by INT, decided_at TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE mia_web_reports (id INTEGER PRIMARY KEY, week_start TEXT, status TEXT, source TEXT, period_json TEXT, report_json TEXT, error TEXT, created_at TEXT)");
        $db->exec("INSERT INTO gbp_reviews (reviewer_name, rating, comment, review_date, reply_draft) VALUES ('Jane', 5, 'Hedges look great', '2026-10-01', 'Thank you, Jane.')");
        $db->exec("INSERT INTO mia_channel_drafts (kind, theme_key, week_start, title, body) VALUES ('gbp_post', 'fall_aeration', '2026-10-05', 'Fall aeration and top-dressing', 'x')");
        $db->exec("INSERT INTO mia_web_reports (week_start, status, report_json) VALUES ('2026-10-05', 'ok', '" . json_encode(['headline' => '/services/hedge-trimming lost 12 of its 20 clicks.']) . "')");

        $items = (new MiaChannelsService($db))->briefItems(new DateTimeImmutable('2026-10-06'));
        $byKind = [];
        foreach ($items as $it) $byKind[$it['kind']] = $it;
        $this->assertSame(1, $byKind['channel_reconnect']['priority']);
        $this->assertStringStartsWith('Reconnect Facebook', $byKind['channel_reconnect']['text']);
        $this->assertSame(1, $byKind['review_reply']['priority']);
        $this->assertSame(2, $byKind['gbp_post']['priority']);
        $this->assertSame(3, $byKind['web_insight']['priority']);
        $this->assertSame('mia:listings:2026-10', $byKind['listings']['key']);
        $this->assertSame('Check Houzz and Yelp details match.', $byKind['listings']['text']);
        foreach ($items as $it) $this->assertNotNull(CharlieRankService::normalize($it, 'mia'), $it['key']);

        $later = (new MiaChannelsService($db))->briefItems(new DateTimeImmutable('2026-10-20'));
        $this->assertNotContains('listings', array_column($later, 'kind'), 'monthly reminder is first week only');
    }
}
