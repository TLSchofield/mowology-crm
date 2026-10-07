<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MiaHubTestDb.php';

/**
 * The watering stage Mia reads from Metro Vancouver's page (fixtures: a trimmed copy of the real
 * page on 2026-10-06, Burnaby's fallback page, and synthetic Stage 2 / 3 / none pages).
 */
class WaterRestrictionTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string)file_get_contents(__DIR__ . '/../../fixtures/water/' . $name);
    }

    public function test_it_reads_each_stage_and_ignores_the_search_script(): void
    {
        $this->assertSame(1, WaterRestrictionService::parse($this->fixture('metro_stage1.html'))['stage'], 'real page: the script says Stage 3, the heading says Stage 1');
        $this->assertSame(2, WaterRestrictionService::parse($this->fixture('metro_stage2.html'))['stage']);
        $this->assertSame(3, WaterRestrictionService::parse($this->fixture('metro_stage3.html'))['stage']);
        $this->assertSame(1, WaterRestrictionService::parse($this->fixture('burnaby_returned_stage1.html'))['stage'], 'Burnaby fallback: "returned to stage 1"');
        $none = WaterRestrictionService::parse($this->fixture('metro_none.html'));
        $this->assertNull($none['stage'], 'a fines table is not a stage');
        $this->assertSame('', $none['snippet']);
        $this->assertStringContainsString('Stage 1 water restrictions in effect', WaterRestrictionService::parse($this->fixture('metro_stage1.html'))['snippet']);
    }

    public function test_outside_may_to_october_15_there_is_no_stage(): void
    {
        $this->assertSame(0, WaterRestrictionService::effective(2, '2026-10-16'));
        $this->assertSame(0, WaterRestrictionService::effective(3, '2026-04-30'));
        $this->assertSame(2, WaterRestrictionService::effective(2, '2026-05-01'));
        $this->assertSame(1, WaterRestrictionService::effective(1, '2026-10-15'));
        $this->assertNull(WaterRestrictionService::effective(null, '2026-07-01'), 'unknown in season stays unknown');
    }

    public function test_the_card_label(): void
    {
        $today = new DateTimeImmutable('2026-10-06');
        $this->assertSame('Stage 1 · checked today', WaterRestrictionService::label(1, '2026-10-06 05:20:00', $today));
        $this->assertSame('Stage 2 · checked 3 days ago', WaterRestrictionService::label(2, '2026-10-03 05:20:00', $today));
        $this->assertSame('Unknown · not checked yet', WaterRestrictionService::label(null, null, $today));
        $this->assertStringStartsWith('None', WaterRestrictionService::label(1, '2026-10-06', new DateTimeImmutable('2026-12-01')));
    }

    public function test_a_failed_fetch_keeps_the_last_good_stage(): void
    {
        $s = WaterRestrictionService::merge([], 3, 'metro', 'Stage 3 …', '2026-06-10 05:20:00');
        $s = WaterRestrictionService::merge($s, null, '', '', '2026-06-11 05:20:00', 'fetch failed');
        $this->assertSame(3, $s['stage']);
        $this->assertSame('2026-06-10 05:20:00', $s['checked_at']);
        $this->assertSame('fetch failed', $s['error']);
        $s = WaterRestrictionService::merge($s, 1, 'metro', 'Stage 1 …', '2026-09-02 05:20:00');
        $this->assertSame(1, $s['stage']);
        $this->assertSame(3, $s['season_max'], 'the summer was a drought year');
        $s = WaterRestrictionService::merge($s, 1, 'metro', '', '2027-05-02 05:20:00');
        $this->assertSame(1, $s['season_max'], 'a new year starts over');
    }

    public function test_refresh_stores_and_falls_back_to_burnaby(): void
    {
        $db = new MiaHubTestDb();
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $pages = [
            WaterRestrictionService::METRO_URL => $this->fixture('metro_none.html'),
            WaterRestrictionService::FALLBACK_URL => $this->fixture('burnaby_returned_stage1.html'),
        ];
        $svc = new class($db, $pages) extends WaterRestrictionService {
            public array $pages;
            public function __construct(PDO $db, array $pages) { parent::__construct($db); $this->pages = $pages; }
            protected function fetch(string $url): ?string { return $this->pages[$url] ?? null; }
        };
        $r = $svc->refresh('2026-10-06 05:20:00');
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['stage']);
        $this->assertSame(WaterRestrictionService::FALLBACK_URL, $r['source']);

        $this->assertSame(1, $svc->current(new DateTimeImmutable('2026-10-06'))['stage']);
        $this->assertSame('Stage 1 · checked today', $svc->current(new DateTimeImmutable('2026-10-06'))['label']);

        $svc->pages = [];
        $r = $svc->refresh('2026-10-07 05:20:00');
        $this->assertFalse($r['ok'], 'both pages down');
        $this->assertStringContainsString('fetch failed', (string)$r['error']);
        $cur = $svc->current(new DateTimeImmutable('2026-10-07'));
        $this->assertSame(1, $cur['stage'], 'kept the last good value');
        $this->assertSame('Stage 1 · checked yesterday', $cur['label']);
        $this->assertSame(0, $svc->current(new DateTimeImmutable('2026-10-20'))['stage'], 'season over');
    }
}
