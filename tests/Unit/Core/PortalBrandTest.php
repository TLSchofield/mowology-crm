<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Customer pages show each business's own name, and never someone else's phone (2026-11-09). */
class PortalBrandTest extends TestCase
{
    private function identity(array $over = []): array
    {
        return $over + ['name' => 'Greenleaf', 'display_name' => 'Greenleaf Gardens Ltd', 'phone' => '(905) 555-1234', 'email' => 'hi@greenleaf.ca',
                        'website' => 'greenleaf.ca', 'app_url' => 'https://greenleaf.ittai.ca'];
    }

    protected function setUp(): void
    {
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => []]);
    }

    protected function tearDown(): void
    {
        BusinessIdentity::reset();
        Tenant::forTests(null);
    }

    public function testABusinessSeesItsOwnDetails(): void
    {
        BusinessIdentity::use($this->identity());
        $this->assertSame('Greenleaf Gardens Ltd', PortalBrand::logoHtml());
        $this->assertSame('Please contact Greenleaf at (905) 555-1234.', PortalBrand::contactSentence());
        $this->assertSame('call us at (905) 555-1234', PortalBrand::callPhrase());
        $this->assertSame('https://greenleaf.ca', PortalBrand::homeUrl());
        $this->assertSame('Questions? Call <a href="tel:+19055551234">(905) 555-1234</a> or email <a href="mailto:hi@greenleaf.ca">hi@greenleaf.ca</a>', PortalBrand::questionsHtml());
    }

    public function testNoPhoneMeansNoCallWording(): void
    {
        BusinessIdentity::use($this->identity(['phone' => '']));
        $this->assertSame('email us at hi@greenleaf.ca', PortalBrand::callPhrase());
        $this->assertStringNotContainsString('Call', PortalBrand::questionsHtml());
        $this->assertStringNotContainsString('tel:', PortalBrand::contactHtml());
    }

    public function testNoContactDetailsAtAll(): void
    {
        BusinessIdentity::use($this->identity(['phone' => '', 'email' => '']));
        $this->assertSame('', PortalBrand::questionsHtml());
        $this->assertSame('', PortalBrand::contactHtml());
        $this->assertSame('contact us', PortalBrand::callPhrase());
        $this->assertSame('Please contact Greenleaf.', PortalBrand::contactSentence());
    }

    public function testANameIsEscaped(): void
    {
        BusinessIdentity::use($this->identity(['display_name' => '<b>A&B</b>']));
        $this->assertSame('&lt;b&gt;A&amp;B&lt;/b&gt;', PortalBrand::logoHtml());
    }

    public function testNoWebsiteUsesItsIttaiAddress(): void
    {
        BusinessIdentity::use($this->identity(['website' => '']));
        $this->assertSame('https://greenleaf.ittai.ca', PortalBrand::homeUrl());
        $this->assertSame('Greenleaf', PortalBrand::websiteLabel());
    }

    public function testNoSavedAddressUsesTheBusinesssRegisteredOne(): void
    {
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => ['hosts' => ['greenleaf.ittai.ca']]]);
        BusinessIdentity::use($this->identity(['website' => '', 'app_url' => '']));
        $this->assertSame('https://greenleaf.ittai.ca', PortalBrand::appUrl());
        $this->assertSame('https://greenleaf.ittai.ca', PortalBrand::homeUrl());
    }

    public function testWebsiteLabelHasNoScheme(): void
    {
        BusinessIdentity::use($this->identity(['website' => 'https://greenleaf.ca/']));
        $this->assertSame('greenleaf.ca', PortalBrand::websiteLabel());
    }

    public function testNoLogoFallsBackToTheNameAndOutsideAddressesAreRefused(): void
    {
        BusinessIdentity::use($this->identity());
        $this->assertStringNotContainsString('<img', PortalBrand::logoImgHtml(''));
        $this->assertStringNotContainsString('<img', PortalBrand::logoImgHtml('https://evil.example/x.png'));
        $this->assertStringNotContainsString('<img', PortalBrand::logoImgHtml('//evil.example/x.png'));
        $this->assertStringContainsString('src="/uploads/logo.png"', PortalBrand::logoImgHtml('/uploads/logo.png'));
    }

    public function testMowologyKeepsItsLogo(): void
    {
        Tenant::forTests(['key' => Tenant::DEFAULT, 'cfg' => []]);
        BusinessIdentity::use($this->identity(['name' => 'Mowology']));
        $this->assertSame('Mowo<span>logy</span>', PortalBrand::logoHtml());
        $this->assertStringContainsString('mowology-logo.jpg', PortalBrand::logoImgHtml());
    }

    /** A customer page must not name the original business, except lines only it can reach (cards are off for others). */
    public function testNoCustomerPageNamesTheOriginalBusiness(): void
    {
        $root = dirname(__DIR__, 3) . '/public/customer';
        $onlyTheOriginalReaches = ['api/invoice-payment-intent.php', 'api/autopay-setup.php', 'api/confirm-saved-card.php'];
        $allowedLine = '/I authorise|secured by|This link is invalid or has expired\. Please contact|tempnam\(/';
        $bad = [];
        foreach (array_merge(glob($root . '/*.php') ?: [], glob($root . '/api/*.php') ?: []) as $file) {
            $rel = substr($file, strlen($root) + 1);
            if (in_array($rel, $onlyTheOriginalReaches, true)) continue;
            foreach (file($file) ?: [] as $n => $line) {
                if (preg_match('#^\s*(\*|//|/\*)#', $line)) continue;
                $code = (string)preg_replace('#\s//\s.*$#', '', $line);
                if (preg_match('/mowology|mowo<span|778.{0,2}846|7788469273/i', $code) && !preg_match($allowedLine, $code) && strpos($code, 'autopay.php') === false) {
                    $bad[] = $rel . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $bad);
    }
}
