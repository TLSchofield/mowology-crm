<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: no open redirect, no unsigned opens/clicks. */
class TrackingLinksTest extends TestCase
{
    private string $k = 'track|0123456789abcdef0123456789abcdef';

    public function test_signature_binds_send_and_url(): void
    {
        $sig = TrackingLinks::sign(5, 'https://example.com/a', $this->k);
        $this->assertTrue(TrackingLinks::verify(5, 'https://example.com/a', $sig, $this->k));
        $this->assertFalse(TrackingLinks::verify(6, 'https://example.com/a', $sig, $this->k));
        $this->assertFalse(TrackingLinks::verify(5, 'https://evil.example/a', $sig, $this->k));
        $this->assertFalse(TrackingLinks::verify(0, 'https://example.com/a', '', $this->k));
        $this->assertSame('', TrackingLinks::sign(5, 'x', ''));
    }

    public function test_unsigned_redirects_stay_on_mowology(): void
    {
        $this->assertSame('https://mowology.ca/quote', TrackingLinks::redirectTarget('https://mowology.ca/quote', false));
        $this->assertSame('https://www.mowology.ca/x', TrackingLinks::redirectTarget('https://www.mowology.ca/x', false));
        foreach (['https://evil.com', 'https://mowology.ca.evil.com/', 'https://mowology.ca@evil.com/', 'https://evil.com\\.mowology.ca/', 'javascript:alert(1)', "https://mowology.ca/\r\nX: y", '//evil.com'] as $bad) {
            $this->assertSame(TrackingLinks::HOME, TrackingLinks::redirectTarget($bad, false), $bad);
        }
        $this->assertSame('https://partner.example/x', TrackingLinks::redirectTarget('https://partner.example/x', true));
        $this->assertSame(TrackingLinks::HOME, TrackingLinks::redirectTarget('javascript:alert(1)', true));
    }

    public function test_click_url_round_trips(): void
    {
        $u = 'https://mowology.ca/quote?src=campaign-4&x=1';
        $click = TrackingLinks::clickUrl(9, $u, $this->k);
        parse_str((string)parse_url($click, PHP_URL_QUERY), $q);
        $this->assertSame($u, TrackingLinks::decodeUrl($q['url']));
        $this->assertTrue(TrackingLinks::verify((int)$q['sid'], TrackingLinks::decodeUrl($q['url']), $q['s'], $this->k));
    }
}
