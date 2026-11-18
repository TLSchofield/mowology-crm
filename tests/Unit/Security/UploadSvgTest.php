<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12 (LOW): SVG logos refused; any SVG in uploads/ is sandboxed + downloaded. */
class UploadSvgTest extends TestCase
{
    private static function root(): string { return dirname(__DIR__, 3) . '/public'; }

    public function test_logo_upload_refuses_svg_and_names_by_detected_type(): void
    {
        $src = (string)file_get_contents(self::root() . '/crm/api/upload-logo.php');
        $this->assertStringContainsString("\$allowed  = ['image/png' => 'png', 'image/jpeg' => 'jpg'];", $src);
        $this->assertStringContainsString('new finfo(FILEINFO_MIME_TYPE)', $src);
        $this->assertStringNotContainsString("PATHINFO_EXTENSION", $src);
        $this->assertStringNotContainsString('sanitizeSvgContent', $src);
    }

    public function test_uploads_serve_svg_sandboxed_as_attachment(): void
    {
        $ht = (string)file_get_contents(self::root() . '/uploads/.htaccess');
        $this->assertMatchesRegularExpression('/<FilesMatch "\(\?i\)\\\\\.svgz\?\$">.*Content-Security-Policy "sandbox".*Content-Disposition "attachment".*<\/FilesMatch>/s', $ht);
    }
}
