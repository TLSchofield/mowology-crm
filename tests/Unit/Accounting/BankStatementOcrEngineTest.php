<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Security review #19 (2026-10-12): scanned bank statements never go to a public OCR service. */
class BankStatementOcrEngineTest extends TestCase
{
    public function test_engine_is_vision_then_tesseract_then_none(): void
    {
        $this->assertSame('vision', BankImportService::ocrEngine(true, null));
        $this->assertSame('vision', BankImportService::ocrEngine(true, '/usr/bin/tesseract'));
        $this->assertSame('tesseract', BankImportService::ocrEngine(false, '/usr/bin/tesseract'));
        $this->assertSame('none', BankImportService::ocrEngine(false, null));
    }

    public function test_no_public_ocr_service_or_demo_key(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Accounting/Services/BankImportService.php');
        $this->assertStringNotContainsString('api.ocr.space', $src);
        $this->assertDoesNotMatchRegularExpression("/['\"]helloworld['\"]/", $src);
    }

    public function test_without_any_engine_it_refuses_clearly(): void
    {
        if (defined('GOOGLE_VISION_CREDENTIALS') && GOOGLE_VISION_CREDENTIALS) $this->markTestSkipped('Vision configured here');
        foreach (['/usr/bin/tesseract', '/usr/local/bin/tesseract', '/opt/homebrew/bin/tesseract'] as $p) {
            if (is_executable($p)) $this->markTestSkipped('tesseract installed here');
        }
        $svc = (new ReflectionClass(BankImportService::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(BankImportService::class, 'ocrImageBytes');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Google Vision .* or Tesseract/');
        $m->invoke($svc, 'not an image', 'jpeg');
    }
}
