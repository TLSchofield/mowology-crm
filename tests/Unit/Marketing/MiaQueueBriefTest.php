<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** MiaQueueBrief: what Search & Website adds to Charlie's morning brief. Quiet unless worth the owner's time. */
class MiaQueueBriefTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $now;

    private string $tz = '';

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/Vancouver');                                    // what config.php sets in production
        $this->now = new DateTimeImmutable('2026-10-26 07:00:00');
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE mia_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, status TEXT DEFAULT 'proposed', title TEXT, score REAL)");
        $db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, host TEXT, status TEXT, verified_at TEXT)");
        $this->db = $db;
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private function action(string $title, ?float $score, string $status = 'proposed', int $site = 1): int
    {
        $this->db->prepare("INSERT INTO mia_actions (site_id, status, title, score) VALUES (?, ?, ?, ?)")->execute([$site, $status, $title, $score]);
        return (int)$this->db->lastInsertId();
    }

    private function site(string $host, string $status, ?string $verifiedAt, int $site = 1): int
    {
        $this->db->prepare("INSERT INTO mia_websites (site_id, host, status, verified_at) VALUES (?, ?, ?, ?)")->execute([$site, $host, $status, $verifiedAt]);
        return (int)$this->db->lastInsertId();
    }

    private function items(int $site = 1): array
    {
        return MiaQueueBrief::items($this->db, $site, $this->now);
    }

    public function testQuietWhenThereIsNothing(): void
    {
        $this->assertSame([], $this->items());
    }

    public function testQuietWhenTheMigrationHasNotRun(): void
    {
        $this->db->exec("DROP TABLE mia_actions");
        $this->db->exec("DROP TABLE mia_websites");
        $this->assertSame([], $this->items());
    }

    public function testWeakSuggestionsNeverNudge(): void
    {
        $this->action('Tiny idea', 24.9);
        $this->action('No score', null);
        $this->assertSame([], $this->items());
    }

    public function testOneStrongSuggestionIsOneLine(): void
    {
        $id = $this->action('Rewrite the title and description of /services/strata-landscaping', 71.5);
        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertSame('mia:actions:top:' . $id, $items[0]['key']);
        $this->assertStringContainsString('Rewrite the title and description of /services/strata-landscaping', $items[0]['text']);
        $this->assertStringContainsString('waiting for your OK', $items[0]['text']);
        $this->assertSame('/crm/marketing/mia.php#growth', $items[0]['url']);
        $this->assertSame('actions', $items[0]['kind']);
        $this->assertSame(1, $items[0]['value']);
    }

    public function testSeveralSuggestionsAreAtMostTwoLinesAndTheBestComesFirst(): void
    {
        $this->action('Second best', 40);
        $best = $this->action('The best one', 80);
        $this->action('Third', 30);
        $this->action('Already decided', 99, 'approved');
        $this->action('Other site', 99, 'proposed', 2);
        $items = $this->items();
        $this->assertCount(2, $items);
        $this->assertSame('mia:actions:top:' . $best, $items[0]['key']);
        $this->assertStringContainsString('The best one', $items[0]['text']);
        $this->assertStringContainsString('best of 3', $items[0]['text']);
        $this->assertSame('2 more website suggestions waiting on the Growth tab.', $items[1]['text']);
        $this->assertSame(2, $items[1]['value']);
    }

    public function testSingularWhenOneMore(): void
    {
        $this->action('A', 60);
        $this->action('B', 50);
        $this->assertSame('1 more website suggestion waiting on the Growth tab.', $this->items()[1]['text']);
    }

    public function testHostileTitleIsClippedAndStaysData(): void
    {
        $this->action('<script>alert(1)</script> ' . str_repeat('x', 400), 70);
        $t = $this->items()[0]['text'];
        $this->assertLessThan(200, mb_strlen($t));
        $this->assertStringContainsString('…', $t);
    }

    public function testSameSituationSameKeyChangedSituationNewKey(): void
    {
        $a = $this->action('A', 60);
        $k1 = $this->items()[0]['key'];
        $this->assertSame($k1, $this->items()[0]['key']);
        $this->action('B', 90);
        $this->assertNotSame($k1, $this->items()[0]['key'], 'a new best suggestion is news');
    }

    public function testWebsiteWhoseProofExpiredIsAnUrgentLine(): void
    {
        $id = $this->site('mowology.ca', 'verified', '2026-07-01 00:00:00');             // 117 days ago
        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertSame('mia:web:expired:' . $id . ':20260701', $items[0]['key']);
        $this->assertSame(2, $items[0]['priority']);
        $this->assertStringContainsString('Mia can no longer look at mowology.ca', $items[0]['text']);
        $this->assertSame('/crm/marketing/mia.php#presence', $items[0]['url']);
    }

    public function testWebsiteAboutToExpireGetsOneWeeklyHeadsUp(): void
    {
        $this->site('mowology.ca', 'verified', '2026-07-30 07:00:00');                    // 88 days ago: 2 days left
        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertStringContainsString('runs out in 2 days', $items[0]['text']);
        $this->assertSame('mia:web:expiring:1:20260730', $items[0]['key']);
        $this->assertSame(3, $items[0]['priority']);
        $this->site('b.example', 'verified', '2026-07-29 07:00:00');                      // exactly 89 days: 1 day left
        $this->assertStringContainsString('runs out in 1 day.', $this->items()[1]['text']);
        $nextWeek = MiaQueueBrief::items($this->db, 1, $this->now->modify('+7 days'));
        $this->assertSame('mia:web:expired:1:20260730', $nextWeek[0]['key'] ?? null, 'expired by next week');
    }

    public function testFreshPendingDisconnectedAndForeignWebsitesAreNotNews(): void
    {
        $this->site('fresh.example', 'verified', '2026-10-20 00:00:00');
        $this->site('pending.example', 'pending', null);
        $this->site('gone.example', 'disconnected', '2026-01-01 00:00:00');
        $this->site('other.example', 'verified', '2026-01-01 00:00:00', 2);
        $this->assertSame([], $this->items());
    }

    public function testEveryItemFollowsCharliesContract(): void
    {
        $this->action('A', 60);
        $this->action('B', 50);
        $this->site('mowology.ca', 'verified', '2026-07-01 00:00:00');
        foreach ($this->items() as $it) {
            $this->assertSame(['key', 'text', 'url', 'priority', 'kind', 'value'], array_keys($it));
            $this->assertIsString($it['key']);
            $this->assertNotSame('', $it['text']);
            $this->assertIsInt($it['priority']);
            $this->assertIsInt($it['value']);
            $this->assertStringStartsWith('/crm/', $it['url']);
            $this->assertSame([], MiaActionService::benefitProblems($it['text']), 'no promises in a notification');
        }
    }

    public function testTheDeskBriefCallsItSafely(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Marketing/Services/MiaDeskService.php');
        $this->assertStringContainsString("MiaQueueBrief::items(\$this->db)", $src);
        $this->assertMatchesRegularExpression('/try \{\s*require_once __DIR__ \. \'\/MiaQueueBrief\.php\';.*?\} catch \(Throwable \$e\)/s', $src, 'a failure here must never break Charlie\'s brief');
        $this->assertStringNotContainsString('INSERT', file_get_contents(__DIR__ . '/../../../app/Modules/Marketing/Services/MiaQueueBrief.php'), 'read-only');
    }

    // ---- Codex round 1 regressions ----

    public function testUnchangedSituationKeepsItsKeysAcrossDaysAndWeeks(): void
    {
        $this->action('A', 60);
        $this->action('B', 50);
        $this->site('mowology.ca', 'verified', '2026-07-30 07:00:00');
        $keys = function (DateTimeImmutable $d) { return array_column(MiaQueueBrief::items($this->db, 1, $d), 'key'); };
        $this->assertSame($keys($this->now), $keys($this->now->modify('+1 day')), 'tomorrow is the same news');
        $sunday = new DateTimeImmutable('2026-11-01 07:00:00');
        $monday = new DateTimeImmutable('2026-11-02 07:00:00');
        $this->action('C', 10);                                                            // weak: not part of the situation
        $this->site('x.example', 'pending', null);
        $this->assertSame(array_values(array_filter($keys($sunday), function ($k) { return strpos($k, 'expiring') === false && strpos($k, 'expired') === false; })),
                          array_values(array_filter($keys($monday), function ($k) { return strpos($k, 'expiring') === false && strpos($k, 'expired') === false; })), 'the week boundary changes nothing');
    }

    public function testRenewedProofThatLaterExpiresAgainIsANewItem(): void
    {
        $this->site('mowology.ca', 'verified', '2026-07-01 00:00:00');
        $first = $this->items()[0]['key'];
        $this->db->exec("UPDATE mia_websites SET verified_at = '2026-10-26 00:00:00'");
        $this->assertSame([], $this->items(), 'renewed: no news');
        $later = MiaQueueBrief::items($this->db, 1, new DateTimeImmutable('2027-02-10 07:00:00'));
        $this->assertSame('mia:web:expired:1:20261026', $later[0]['key']);
        $this->assertNotSame($first, $later[0]['key']);
    }

    public function testExpiryIsInCalendarDaysAcrossTheSpringDstChange(): void
    {
        $tz = new DateTimeZone('America/Vancouver');
        $this->site('mowology.ca', 'verified', '2026-01-01 12:00:00');
        // 80 calendar days later is 2026-03-22 (clocks went forward on 2026-03-08): exactly 10 days left, not 11 and not 9.
        $items = MiaQueueBrief::items($this->db, 1, new DateTimeImmutable('2026-03-22 12:00:00', $tz));
        $this->assertCount(1, $items);
        $this->assertStringContainsString('runs out in 10 days', $items[0]['text']);
        $this->assertSame([], MiaQueueBrief::items($this->db, 1, new DateTimeImmutable('2026-03-21 12:00:00', $tz)), '11 days left: not yet');
        $expired = MiaQueueBrief::items($this->db, 1, new DateTimeImmutable('2026-04-01 12:00:00', $tz));
        $this->assertStringContainsString('can no longer look', $expired[0]['text'], 'day 90 itself is expired, like MiaWebsiteService');
        $today = MiaQueueBrief::items($this->db, 1, new DateTimeImmutable('2026-04-01 09:00:00', $tz));
        $this->assertStringContainsString('runs out today', $today[0]['text']);
    }

    public function testTheCountIsTheRealCountEvenAboveFifty(): void
    {
        for ($i = 0; $i < 60; $i++) { $this->action('Idea ' . $i, 30 + $i / 10); }
        $items = $this->items();
        $this->assertStringContainsString('best of 60', $items[0]['text']);
        $this->assertSame('59 more website suggestions waiting on the Growth tab.', $items[1]['text']);
        $this->assertSame(60, $items[0]['value']);
    }

    public function testMarkupAndLongHostsAreNeutralised(): void
    {
        $this->action('<img src=x onerror=alert(1)> Fix titles', 70);
        $this->site('<script>alert(1)</script>' . str_repeat('a', 300) . '.example', 'verified', '2026-07-01 00:00:00');
        foreach ($this->items() as $it) {
            $this->assertStringNotContainsString('<', $it['text']);
            $this->assertStringNotContainsString('>', $it['text']);
            $this->assertLessThan(260, mb_strlen($it['text']));
        }
    }
}
