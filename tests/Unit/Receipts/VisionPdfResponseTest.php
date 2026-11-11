<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('APP_ROOT')) require_once __DIR__ . '/../../../app/Core/paths.php';
require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';

/** Scanned PDFs read through Vision files:annotate (2026-11-06): the answer's shape. Invented text. */
final class VisionPdfResponseTest extends TestCase
{
    public function test_pages_are_joined_and_page_one_keeps_the_photo_shape(): void
    {
        $p1 = ['fullTextAnnotation' => ['text' => "SUPPLY CO\nGRAVEL 2 YD 80.00"], 'textAnnotations' => []];
        $p2 = ['fullTextAnnotation' => ['text' => "TOTAL 84.00"]];
        $r = visionPdfResponseText(['responses' => [['responses' => [$p1, $p2], 'totalPages' => 2]]]);
        $this->assertTrue($r['success']);
        $this->assertSame("SUPPLY CO\nGRAVEL 2 YD 80.00\nTOTAL 84.00", $r['text']);
        $this->assertSame(['responses' => [$p1]], $r['raw_response']);
    }

    public function test_a_page_with_an_error_or_no_text_is_skipped(): void
    {
        $r = visionPdfResponseText(['responses' => [['responses' => [['error' => ['message' => 'bad page']], [], ['fullTextAnnotation' => ['text' => 'ONLY']]]]]]);
        $this->assertSame('ONLY', $r['text']);
        $this->assertSame('ONLY', $r['raw_response']['responses'][0]['fullTextAnnotation']['text']);
        $this->assertSame('', visionPdfResponseText(['responses' => []])['text']);
        $this->assertNull(visionPdfResponseText(['responses' => []])['raw_response']);
    }
}
