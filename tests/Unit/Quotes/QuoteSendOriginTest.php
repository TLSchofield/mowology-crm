<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/TestIdentity.php';

/** The client's quote link is built on the business's own address, never the request's Host (Codex 2026-10-15). */
class QuoteSendOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestIdentity::mowology();
    }

    protected function tearDown(): void
    {
        BusinessIdentity::reset();
        parent::tearDown();
    }

    public function test_origin_from_the_business_website(): void
    {
        $this->assertSame('https://mowology.ca', QuoteSendService::origin('mowology.ca'));
        $this->assertSame('https://mowology.ca', QuoteSendService::origin('https://mowology.ca/about'));
        $this->assertSame('https://www.example.com', QuoteSendService::origin('http://WWW.Example.com'));
    }

    public function test_a_bad_or_missing_website_falls_back(): void
    {
        $fallback = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : 'https://mowology.ca';
        $this->assertSame($fallback, QuoteSendService::origin(''));
        $this->assertSame($fallback, QuoteSendService::origin(null));
        $this->assertSame($fallback, QuoteSendService::origin('not a site'));
    }
}
