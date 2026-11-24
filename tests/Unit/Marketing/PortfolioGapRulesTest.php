<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Marketing/Services/MiaChannelsService.php';

/** Mia notices a portfolio project that shows as a bare card (2026-11-24: "Lawn Cutting" had no description and nothing said so). */
final class PortfolioGapRulesTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE portfolio_projects (id INTEGER PRIMARY KEY, project_name TEXT, description TEXT, location TEXT, status TEXT, display_order INTEGER DEFAULT 0)");
        $ins = $db->prepare("INSERT INTO portfolio_projects (id, project_name, description, location, status) VALUES (?, ?, ?, ?, ?)");
        foreach ([[1, 'Lawn Cutting', '', 'Kitsilano Vancouver BC', 'published'],
                  [2, 'Station Place', 'Weekly maintenance for a 71-unit strata with multi-level access.', 'Mount Pleasant', 'published'],
                  [3, 'Draft job', '', '', 'draft'],
                  [4, 'Short note', 'tiny', 'Burnaby', 'published']] as $r) {
            $ins->execute($r);
        }
        return $db;
    }

    public function testAnEmptyOrTooShortDescriptionAndAnEmptyLocationAreGaps(): void
    {
        $gaps = PortfolioGapRules::find([
            ['id' => 1, 'project_name' => 'Lawn Cutting', 'description' => '', 'location' => 'Kitsilano'],
            ['id' => 2, 'project_name' => 'Fine', 'description' => 'A full description of the work that was done here.', 'location' => 'Burnaby'],
            ['id' => 3, 'project_name' => 'No place', 'description' => 'A full description of the work that was done here.', 'location' => '  '],
            ['id' => 4, 'project_name' => '', 'description' => null, 'location' => null],
        ]);
        $this->assertSame([1, 3, 4], array_column($gaps, 'id'));
        $this->assertSame(['description'], $gaps[0]['missing']);
        $this->assertSame(['location'], $gaps[1]['missing']);
        $this->assertSame(['description', 'location'], $gaps[2]['missing']);
        $this->assertSame('Untitled project', $gaps[2]['name']);
    }

    public function testNothingIsReportedWhenEveryProjectIsComplete(): void
    {
        $this->assertSame([], PortfolioGapRules::find([['id' => 1, 'project_name' => 'A', 'description' => str_repeat('x', 30), 'location' => 'Here']]));
        $this->assertSame([], PortfolioGapRules::suggestions([]));
        $this->assertSame('', PortfolioGapRules::text([]));
    }

    public function testTheTextNamesTheProjectsAndStaysTradeNeutral(): void
    {
        $t = PortfolioGapRules::text([['id' => 1, 'name' => 'Lawn Cutting', 'missing' => ['description']]]);
        $this->assertStringContainsString('"Lawn Cutting" (no description)', $t);
        $this->assertStringContainsString('1 portfolio project looks unfinished', $t);
        $many = array_map(static fn($i) => ['id' => $i, 'name' => "P$i", 'missing' => ['description']], range(1, 5));
        $this->assertStringContainsString('and 2 more', PortfolioGapRules::text($many));
        $this->assertDoesNotMatchRegularExpression('/lawn|garden|mow|landscap/i', PortfolioGapRules::text([['id' => 1, 'name' => 'X', 'missing' => ['description']]]));
    }

    public function testTheServiceReadsPublishedProjectsOnly(): void
    {
        $gaps = (new MiaChannelsService($this->db()))->portfolioGaps();
        $this->assertSame([1, 4], array_column($gaps, 'id'));                       // the draft is not on the website, so it is not a gap
    }

    public function testAMissingTableNeverBreaksTheCard(): void
    {
        $db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->assertSame([], (new MiaChannelsService($db))->portfolioGaps());
    }

    public function testTheGapShowsEvenWhenSearchConsoleIsNotConnected(): void
    {
        $gaps = [['id' => 1, 'name' => 'Lawn Cutting', 'missing' => ['description']]];
        $v = MiaChannelsService::webView(['status' => 'not_connected', 'week_start' => '2026-11-23', 'report' => []], $gaps);
        $this->assertSame('not_connected', $v['status']);
        $this->assertCount(1, $v['suggestions']);
        $this->assertSame('/crm/portfolio/index.php', $v['suggestions'][0]['cms_url']);
        $this->assertSame([], MiaChannelsService::webView(null)['suggestions']);
        $ok = MiaChannelsService::webView(['status' => 'ok', 'week_start' => '2026-11-23', 'report' => ['headline' => 'h', 'suggestions' => [['type' => 'low_ctr', 'text' => 'x']]]], $gaps);
        $this->assertSame('portfolio', $ok['suggestions'][0]['type']);               // ours first, Search Console's after
        $this->assertSame('low_ctr', $ok['suggestions'][1]['type']);
    }
}
