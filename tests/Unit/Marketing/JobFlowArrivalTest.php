<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../public/jobFlow/helpers/arrival.php';

/** First-touch capture for the quote form (2026-10-26: all 35 stored landing pages were the confirm page). */
final class JobFlowArrivalTest extends TestCase
{
    private const OWN = 'mowology.ca';

    public function testAnOwnPageKeepsOnlyItsPathNeverTheQueryString(): void
    {
        $a = jfArrivalFromReferer('https://www.mowology.ca/services/strata-landscaping-maintenance?utm_source=x&email=a@b.c#top', self::OWN);
        $this->assertSame(['from_page' => '/services/strata-landscaping-maintenance', 'ref_host' => null], $a);
    }

    public function testAnOutsideSiteKeepsOnlyTheHostNeverTheSearchQuery(): void
    {
        $a = jfArrivalFromReferer('https://www.google.com/search?q=lawn+care+near+me+john+smith', self::OWN);
        $this->assertSame(['from_page' => null, 'ref_host' => 'google.com'], $a);
    }

    public function testTheFormItselfIsNotASourceSoAReloadOrStepBackCannotReplaceIt(): void
    {
        foreach (['https://mowology.ca/jobFlow/jobFlow-confirm.php', 'https://mowology.ca/jobFlow/jobFlow-getQuote.php', 'https://mowology.ca/quote'] as $r) {
            $this->assertSame(['from_page' => null, 'ref_host' => null], jfArrivalFromReferer($r, self::OWN), $r);
        }
    }

    public function testJunkIsIgnored(): void
    {
        foreach ([null, '', 'not a url', 'javascript:alert(1)', 'ftp://x.com/a', 'https://mowology.ca/<script>', 'https://' . str_repeat('a', 200) . '.com/'] as $r) {
            $this->assertSame(['from_page' => null, 'ref_host' => null], jfArrivalFromReferer($r, self::OWN), (string)$r);
        }
    }

    public function testTheLandingPageIsTheOriginatingPageElseTheFallback(): void
    {
        $this->assertSame('https://mowology.ca/services/lawn-care', jfLandingFor(['from_page' => '/services/lawn-care'], 'www.mowology.ca', 'https://mowology.ca/jobFlow/jobFlow-confirm.php'));
        $this->assertSame('https://mowology.ca/jobFlow/jobFlow-confirm.php', jfLandingFor([], 'mowology.ca', 'https://mowology.ca/jobFlow/jobFlow-confirm.php?x=1'));
        $this->assertSame('', jfLandingFor(['from_page' => '/a b<'], 'mowology.ca', 'fallback'), 'a bad stored path is not trusted and a non-URL fallback is dropped');
        $this->assertSame('https://mowology.ca/jobFlow/jobFlow-confirm.php', jfLandingFor(['from_page' => '/ok'], '', 'https://mowology.ca/jobFlow/jobFlow-confirm.php?email=jane@x.com#a'), 'the fallback never keeps a query string');
        $this->assertSame('https://evil.com/x', jfCleanUrl('https://user:pw@evil.com:8080/x?q=1'), 'user info, port and query are dropped');
    }

    public function testSourceIsUtmThenOutsideSiteThenDefault(): void
    {
        $this->assertSame('newsletter', jfSourceFor(['utm_source' => 'newsletter', 'ref_host' => 'google.com'], 'website'));
        $this->assertSame('google.com', jfSourceFor(['ref_host' => 'google.com'], 'website'));
        $this->assertSame('website', jfSourceFor([], 'website'));
        $this->assertSame('website', jfSourceFor(['ref_host' => 'bad host!'], 'website'));
    }

    public function testBothPagesLoadTheHelperAndTheConfirmPageStopsLoggingItsOwnReferer(): void
    {
        $get = (string)file_get_contents(__DIR__ . '/../../../public/jobFlow/jobFlow-getQuote.php');
        $con = (string)file_get_contents(__DIR__ . '/../../../public/jobFlow/jobFlow-confirm.php');
        $this->assertStringContainsString("helpers/arrival.php", $get);
        $this->assertStringContainsString("is_file(__DIR__ . '/helpers/arrival.php')", $con, 'the form still works if the helper file is missing');
        $this->assertStringContainsString("arrival_done", $get);
        $this->assertStringContainsString('jfArrivalFromReferer(', $get);
        $this->assertStringContainsString('jfLandingFor($data[\'tracking\']', $con);
        $this->assertStringNotContainsString("logLeadEvent(\n                    \$_SERVER['HTTP_REFERER']", $con);
    }
}
