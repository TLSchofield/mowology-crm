<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: invoice open pixels are signed; sequential ids alone record nothing. */
class InvoiceOpenPixelTest extends TestCase
{
    private string $k = 'track|0123456789abcdef0123456789abcdef';

    public function test_the_pixel_url_carries_a_signature_for_its_own_id(): void
    {
        $url = TrackingLinks::invoiceOpenUrl(41, $this->k);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('41', $q['rid']);
        $this->assertTrue(TrackingLinks::verify(41, 'invoice-open', $q['s'], $this->k));
        $this->assertFalse(TrackingLinks::verify(42, 'invoice-open', $q['s'], $this->k), 'not valid for the next id');
        $this->assertFalse(TrackingLinks::verify(41, 'open', $q['s'], $this->k), 'not a campaign-open signature');
    }
}
