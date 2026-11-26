<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * TenantBrand (plan 2026-11-26 step 1): a business's documents carry ITS logo and colours, never Mowology's;
 * the original business is returned untouched.
 */
class TenantBrandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tb_' . uniqid();
        mkdir($this->root . '/uploads/t/acme', 0777, true);
        // a real 2x2 PNG
        $im = imagecreatetruecolor(2, 2);
        imagepng($im, $this->root . '/uploads/t/acme/logo.png');
        file_put_contents($this->root . '/uploads/t/acme/notes.txt', 'not an image');
        file_put_contents($this->root . '/uploads/t/acme/fake.png', 'not really a png');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/uploads/t/acme/*') ?: [] as $f) @unlink($f);
        @rmdir($this->root . '/uploads/t/acme'); @rmdir($this->root . '/uploads/t'); @rmdir($this->root . '/uploads'); @rmdir($this->root);
    }

    public function test_default_business_is_untouched(): void
    {
        $b = TenantBrand::resolve(['logo_path' => '/uploads/x.png', 'brand_color_primary' => '#123456'], true, $this->root);
        $this->assertTrue($b['useDefaultLogo']);
        $this->assertFalse($b['custom']);
        $this->assertSame('', $b['logoFile']);
        $this->assertSame('', TenantBrand::css($b, 'invoice'));
        $this->assertSame('<p style="color:#1A5F4A">x</p>', TenantBrand::recolor('<p style="color:#1A5F4A">x</p>', $b, ['#1A5F4A' => 'primary']));
    }

    public function test_other_business_without_a_logo_gets_no_logo_and_never_mowologys(): void
    {
        $b = TenantBrand::resolve([], false, $this->root, 'uploads/t/acme');
        $this->assertFalse($b['useDefaultLogo']);
        $this->assertSame('', $b['logoFile']);
        $this->assertFalse($b['custom']);
    }

    public function test_other_business_logo_resolves_only_for_a_real_image_under_uploads(): void
    {
        $ok = TenantBrand::resolve(['logo_path' => '/uploads/t/acme/logo.png', 'logo_alt_text' => ' Acme '], false, $this->root, 'uploads/t/acme');
        $this->assertSame($this->root . '/uploads/t/acme/logo.png', $ok['logoFile']);
        $this->assertSame('Acme', $ok['logoAlt']);
        foreach (['/uploads/t/acme/notes.txt', '/uploads/t/acme/fake.png', '/uploads/t/acme/../../../etc/passwd', '/etc/passwd',
                  'uploads/t/acme/logo.png', 'https://example.com/logo.png', '/assets/img/logo/mowology-logo.jpg', '/uploads/receipts/a.png', '/uploads/t/other/logo.png', '/uploads/t/acme/receipts/x.png', ''] as $bad) {
            $this->assertSame('', TenantBrand::logoFile($bad, $this->root, 'uploads/t/acme'), $bad);
        }
    }

    public function test_stock_mowology_colours_do_not_count_as_a_choice(): void
    {
        $b = TenantBrand::resolve(['brand_color_primary' => '#2D8659', 'brand_color_secondary' => '#7FD858'], false, $this->root, 'uploads/t/acme');
        $this->assertFalse($b['custom']);
        $this->assertSame('', TenantBrand::css($b, 'quote'));
        $this->assertFalse(TenantBrand::resolve(['brand_color_primary' => '2d8659'], false, $this->root, 'uploads/t/acme')['custom']);
    }

    public function test_own_colours_are_used_and_made_readable(): void
    {
        $b = TenantBrand::resolve(['brand_color_primary' => '#1F4E79', 'brand_color_secondary' => '#F4B400'], false, $this->root, 'uploads/t/acme');
        $this->assertTrue($b['custom']);
        $this->assertSame('#1F4E79', $b['primary']);
        $this->assertGreaterThanOrEqual(4.5, TenantBrand::contrast($b['primary'], '#FFFFFF'));

        $pale = TenantBrand::resolve(['brand_color_primary' => '#FFE066'], false, $this->root, 'uploads/t/acme');
        $this->assertTrue($pale['custom']);
        $this->assertGreaterThanOrEqual(4.5, TenantBrand::contrast($pale['primary'], '#FFFFFF'));
        $this->assertNotSame('#FFE066', $pale['primary']);

        $this->assertSame('', TenantBrand::resolve(['brand_color_primary' => 'blue'], false, $this->root, 'uploads/t/acme')['primary']);
        $this->assertFalse(TenantBrand::resolve(['brand_color_primary' => 'javascript:x'], false, $this->root, 'uploads/t/acme')['custom']);
    }

    public function test_css_and_recolor_use_the_business_colours_for_every_document(): void
    {
        $b = TenantBrand::resolve(['brand_color_primary' => '#1F4E79'], false, $this->root, 'uploads/t/acme');
        foreach (['invoice', 'quote', 'statement', 'contract'] as $doc) {
            $css = TenantBrand::css($b, $doc);
            $this->assertStringContainsString('#1F4E79', $css, $doc);
            $this->assertStringNotContainsString('#2D8659', $css, $doc);
            $this->assertStringNotContainsString('#0D3B2E', $css, $doc);
        }
        $html = TenantBrand::recolor('a{color:#1a5f4a} b{color:#7FD858} c{background:#0D3B2E}', $b, ['#1A5F4A' => 'primary', '#7FD858' => 'tint', '#0D3B2E' => 'primary']);
        $this->assertStringNotContainsStringIgnoringCase('#1A5F4A', $html);
        $this->assertStringContainsString('#1F4E79', $html);
        $this->assertStringContainsString($b['tint'], $html);
    }

    public function test_logo_data_uri_is_only_for_a_readable_image(): void
    {
        $b = TenantBrand::resolve(['logo_path' => '/uploads/t/acme/logo.png'], false, $this->root, 'uploads/t/acme');
        $this->assertStringStartsWith('data:image/png;base64,', TenantBrand::logoDataUri($b));
        $this->assertSame('', TenantBrand::logoDataUri(['logoFile' => '']));
        $this->assertSame('', TenantBrand::logoDataUri(['logoFile' => $this->root . '/uploads/t/acme/notes.txt']));
    }

    public function test_no_pdf_template_or_generator_still_embeds_mowologys_logo_for_other_businesses(): void
    {
        $root = __DIR__ . '/../../..';
        foreach (['public/crm/templates/pdf/invoice.php', 'public/crm/templates/pdf/quote.php'] as $f) {
            $src = (string)file_get_contents("$root/$f");
            $this->assertStringContainsString("!empty(\$brand['useDefaultLogo'])", $src, $f);
            $this->assertStringContainsString('TenantBrand::css($brand', $src, $f);
        }
        foreach (['public/crm/templates/pdf/statement.php', 'public/crm/templates/pdf/contract.php'] as $f) {
            $this->assertStringContainsString('TenantBrand::css($brand', (string)file_get_contents("$root/$f"), $f);
        }
        foreach (['app/Services/Pow/PowPdfGenerator.php', 'app/Services/Salt/SaltReportPdfGenerator.php'] as $f) {
            $src = (string)file_get_contents("$root/$f");
            $this->assertStringContainsString("!empty(\$brand['useDefaultLogo'])", $src, $f);
            $this->assertStringContainsString('TenantBrand::recolor(', $src, $f);
        }
        $this->assertStringContainsString('TenantBrand::forCurrent($this->db)', (string)file_get_contents("$root/app/Services/Pdf/PdfGenerator.php"));
        $this->assertStringContainsString('TenantBrand::current()', (string)file_get_contents("$root/app/Services/Messaging/EmailWrapper.php"));
        $this->assertStringContainsString('TenantBrand::recolor(', (string)file_get_contents("$root/app/Services/Messaging/TemplateRenderer.php"));
    }
}
