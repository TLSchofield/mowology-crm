<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Embeddable lead form: the Origin check is the guard, the key only names the tenant. */
class EmbedLeadRulesTest extends TestCase
{
    private const ALLOWED = 'https://talkinghands.ca, https://www.talkinghands.ca';

    public function test_exact_origin_is_allowed(): void
    {
        $this->assertTrue(EmbedLeadRules::originAllowed('https://talkinghands.ca', self::ALLOWED));
        $this->assertTrue(EmbedLeadRules::originAllowed('https://WWW.TalkingHands.ca', self::ALLOWED));
    }

    public function test_look_alike_and_wrong_origins_are_refused(): void
    {
        foreach ([
            'https://talkinghands.ca.evil.com', 'https://evil.com', 'http://talkinghands.ca',
            'https://sub.talkinghands.ca', 'https://talkinghands.ca:8443', 'null', '', 'talkinghands.ca',
        ] as $o) {
            $this->assertFalse(EmbedLeadRules::originAllowed($o, self::ALLOWED), $o);
        }
        $this->assertFalse(EmbedLeadRules::originAllowed(null, self::ALLOWED));
    }

    public function test_no_configured_origins_allows_nothing(): void
    {
        $this->assertFalse(EmbedLeadRules::originAllowed('https://talkinghands.ca', ''));
        $this->assertFalse(EmbedLeadRules::originAllowed('https://talkinghands.ca', null));
    }

    public function test_plain_http_only_for_localhost(): void
    {
        $this->assertSame('http://localhost:8888', EmbedLeadRules::normaliseOrigin('http://localhost:8888/page'));
        $this->assertSame('', EmbedLeadRules::normaliseOrigin('http://example.com'));
        $this->assertSame('', EmbedLeadRules::normaliseOrigin('javascript:alert(1)'));
    }

    public function test_key_shape(): void
    {
        $this->assertTrue(EmbedLeadRules::keyLooksValid(str_repeat('a1', 12)));
        foreach (['', 'short', str_repeat('A', 24), str_repeat('g', 24), "x' OR 1=1 --"] as $k) {
            $this->assertFalse(EmbedLeadRules::keyLooksValid($k), $k);
        }
    }

    public function test_site_daily_cap(): void
    {
        $this->assertFalse(EmbedLeadRules::overSiteLimit(99));
        $this->assertTrue(EmbedLeadRules::overSiteLimit(100));
    }

    public function test_notify_address_prefers_lead_address_then_site_email(): void
    {
        $this->assertSame('a@x.ca', EmbedLeadRules::notifyAddress(['lead_notify_email' => 'a@x.ca', 'email' => 'b@x.ca']));
        $this->assertSame('b@x.ca', EmbedLeadRules::notifyAddress(['lead_notify_email' => 'nope', 'email' => 'b@x.ca']));
        $this->assertSame('', EmbedLeadRules::notifyAddress(['lead_notify_email' => '', 'email' => '']));
    }

    public function test_notification_escapes_visitor_text(): void
    {
        $html = EmbedLeadRules::notificationHtml(
            ['name' => '<script>x</script>', 'email' => 'a@b.co', 'phone' => '', 'message' => "hi\n<b>there</b>", 'source_url' => 'https://t.ca/?a=1&b=2'],
            7,
            'Talking <Hands>'
        );
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>there', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Enquiry #7', $html);
    }
}
