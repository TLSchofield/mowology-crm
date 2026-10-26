<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class RobotsParserTest extends TestCase
{
    public function testNoRulesAllowsEverything(): void
    {
        $r = RobotsParser::parse('');
        $this->assertTrue($r->isAllowed('/anything'));
        $this->assertFalse($r->blocksEverything());
    }

    public function testStarGroupAndLongestMatchWins(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /private/\nAllow: /private/public/\nDisallow: /tmp\n");
        $this->assertFalse($r->isAllowed('/private/a'));
        $this->assertTrue($r->isAllowed('/private/public/page'));
        $this->assertFalse($r->isAllowed('/tmp'));
        $this->assertFalse($r->isAllowed('/tmpfile'), 'prefix match');
        $this->assertTrue($r->isAllowed('/other'));
    }

    public function testAllowBeatsDisallowOnEqualLength(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /a\nAllow: /a\n");
        $this->assertTrue($r->isAllowed('/a/b'));
    }

    public function testOurOwnGroupOverridesStar(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /\n\nUser-agent: IttaiMiaBot\nDisallow: /admin\n");
        $this->assertTrue($r->isAllowed('/services'));
        $this->assertFalse($r->isAllowed('/admin/x'));
        $this->assertFalse($r->blocksEverything());
    }

    public function testStarGroupDisallowAllBlocksUs(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /\n");
        $this->assertTrue($r->blocksEverything());
        $this->assertFalse($r->isAllowed('/x'));
        $this->assertTrue($r->isAllowed('/robots.txt'));
    }

    public function testEmptyDisallowMeansAllowAll(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow:\n");
        $this->assertTrue($r->isAllowed('/x'));
    }

    public function testWildcardsAndEndAnchor(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /*.pdf$\nDisallow: /search*?q=\nDisallow: /*/draft/\n");
        $this->assertFalse($r->isAllowed('/files/a.pdf'));
        $this->assertTrue($r->isAllowed('/files/a.pdf.html'));
        $this->assertFalse($r->isAllowed('/search?q=1'));
        $this->assertFalse($r->isAllowed('/blog/draft/x'));
        $this->assertTrue($r->isAllowed('/blog/final/x'));
    }

    public function testSharedGroupsMergeAndStackedAgents(): void
    {
        $r = RobotsParser::parse("User-agent: foo\nUser-agent: ittaimiabot\nDisallow: /a\n\nUser-agent: IttaiMiaBot\nDisallow: /b\n");
        $this->assertFalse($r->isAllowed('/a'));
        $this->assertFalse($r->isAllowed('/b'));
    }

    public function testCommentsCrLfBomCaseAndCrawlDelaySitemaps(): void
    {
        $body = "\xEF\xBB\xBFUSER-AGENT: *  # everyone\r\nDISALLOW: /x # no\r\nCrawl-delay: 4\r\nSitemap: https://a.example/s.xml\r\nSitemap: https://a.example/s.xml\r\n";
        $r = RobotsParser::parse($body);
        $this->assertFalse($r->isAllowed('/x'));
        $this->assertSame(4.0, $r->crawlDelay());
        $this->assertSame(['https://a.example/s.xml'], $r->sitemaps());
    }

    public function testStatusHandling(): void
    {
        $this->assertTrue(RobotsParser::forStatus(404, 'Disallow: /')->isAllowed('/x'), '4xx = no rules');
        $this->assertTrue(RobotsParser::forStatus(410, '')->isAllowed('/x'));
        $this->assertFalse(RobotsParser::forStatus(503, '')->isAllowed('/x'), '5xx = do not crawl');
        $this->assertFalse(RobotsParser::forStatus(500, "User-agent: *\nAllow: /")->isAllowed('/x'));
        $this->assertFalse(RobotsParser::forStatus(200, "User-agent: *\nDisallow: /a")->isAllowed('/a'));
    }

    public function testRuleBeforeAnyUserAgentIsIgnored(): void
    {
        $this->assertTrue(RobotsParser::parse("Disallow: /\n")->isAllowed('/x'));
    }

    public function testQueryStringIsPartOfThePath(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /*?\n");
        $this->assertFalse($r->isAllowed('/page?x=1'));
        $this->assertTrue($r->isAllowed('/page'));
    }

    public function testPercentEncodedUnreservedCharactersAreDecodedBeforeMatching(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /admin\nDisallow: /%7Euser\n");
        $this->assertFalse($r->isAllowed('/%61dmin/login'));
        $this->assertTrue($r->isAllowed('/%41dmin'), '%41 is an upper-case A: a different path from /admin, so it is allowed');
        $this->assertFalse($r->isAllowed('/%7Euser/x'));
        $this->assertFalse($r->isAllowed('/~user/x'));
    }

    public function testReservedEscapesStayEncoded(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /a/b\n");
        $this->assertTrue($r->isAllowed('/a%2Fb'), '%2F is not a slash');
        $this->assertSame('/a%2Fb', RobotsParser::normalizePercent('/a%2fb'));
        $this->assertSame('/~x', RobotsParser::normalizePercent('/%7ex'));
    }

    public function testDisallowRootWithAllowedSubtreeStillCrawlsTheSubtree(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /\nAllow: /public/\n");
        $this->assertFalse($r->blocksEverything());
        $this->assertTrue($r->isAllowed('/public/page'));
        $this->assertFalse($r->isAllowed('/private'));
        $this->assertTrue(RobotsParser::parse("User-agent: *\nDisallow: /\n")->blocksEverything());
    }

    public function testWildcardsDoNotInflateSpecificity(): void
    {
        $r = RobotsParser::parse("User-agent: *\nDisallow: /foo***\nAllow: /foox\n");
        $this->assertTrue($r->isAllowed('/foox'), '/foox has 5 literal characters, /foo*** only 4');
        $r2 = RobotsParser::parse("User-agent: *\nDisallow: /a*b$\nAllow: /ab\n");
        $this->assertTrue($r2->isAllowed('/ab'));
    }

    public function testSitemapLineDoesNotSplitAUserAgentGroup(): void
    {
        $r = RobotsParser::parse("User-agent: IttaiMiaBot\nSitemap: https://a.example/s.xml\nUser-agent: OtherBot\nDisallow: /private\n");
        $this->assertFalse($r->isAllowed('/private'));
        $this->assertSame(['https://a.example/s.xml'], $r->sitemaps());
    }
}
