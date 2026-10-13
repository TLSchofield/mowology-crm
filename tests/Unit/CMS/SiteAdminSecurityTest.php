<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: site-admin custom CSS and login throttle. */
final class SiteAdminSecurityTest extends TestCase
{
    public function testCustomCssRefusesAngleOpen(): void
    {
        $this->assertTrue(cms_customCssAllowed('.a > .b { color: red; }'));
        $this->assertFalse(cms_customCssAllowed('</style><script>alert(1)</script>'));
        $this->assertFalse(cms_customCssAllowed('a{} <!--'));
    }

    public function testBuiltCssCannotCloseStyle(): void
    {
        $css = cms_buildThemeCss(['custom_css' => 'x{}</style><script>alert(1)</script>']);
        $this->assertStringNotContainsString('<', $css);
        $this->assertStringContainsString('.a > .b', cms_buildThemeCss(['custom_css' => '.a > .b{}']));
    }

    public function testSiteLoginCeilings(): void
    {
        $this->assertFalse(cms_siteLoginOverLimit(4, 19, 14));
        $this->assertTrue(cms_siteLoginOverLimit(5, 0, 0));
        $this->assertTrue(cms_siteLoginOverLimit(0, 20, 0));
        $this->assertTrue(cms_siteLoginOverLimit(0, 0, 15));
    }
}
