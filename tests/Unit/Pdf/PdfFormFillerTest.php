<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fixtures (tests/fixtures/pdf) were made with PyMuPDF: the same 4-field form saved two ways —
 * a classic xref table, and object streams + an xref stream (the kind free FPDI can't open).
 * Both were also checked by hand in Apple PDFKit and PyMuPDF after filling (2026-10-10).
 */
final class PdfFormFillerTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string)file_get_contents(__DIR__ . '/../../fixtures/pdf/' . $name);
    }

    public static function forms(): array
    {
        return ['classic table' => ['form-classic.pdf'], 'xref stream' => ['form-objstm.pdf']];
    }

    /** @dataProvider forms */
    public function testReadsFields(string $file): void
    {
        $f = (new PdfFormFiller(self::fixture($file)))->fields();
        $this->assertSame(['company_name', 'address', 'gst_number', 'newsletter'], array_keys($f));
        $this->assertSame('text', $f['company_name']['type']);
        $this->assertSame('checkbox', $f['newsletter']['type']);
        $this->assertNull($f['company_name']['value']);
    }

    /** @dataProvider forms */
    public function testFillReadsBackAndKeepsOriginalBytes(string $file): void
    {
        $src = self::fixture($file);
        $out = (new PdfFormFiller($src))->fill([
            'company_name' => 'Mowology Lawns & Landscapes Ltd (Mowology)',
            'address'      => '2845 West 15th Ave',
            'gst_number'   => '811374909RT0001',
            'newsletter'   => true,
            'not_a_field'  => 'ignored',
        ]);
        $this->assertStringStartsWith($src, $out, 'incremental update: original bytes untouched');
        $f = (new PdfFormFiller($out))->fields();
        $this->assertSame('Mowology Lawns & Landscapes Ltd (Mowology)', $f['company_name']['value']);
        $this->assertSame('811374909RT0001', $f['gst_number']['value']);
        $this->assertNotSame('Off', $f['newsletter']['value']);
        // The update is written in the original's own xref format (Apple refuses a mix).
        $tail = substr($out, strlen($src));
        $this->assertSame(strpos($src, '/XRef') !== false, strpos($tail, '/Type /XRef') !== false);
        $this->assertSame(strpos($src, '/XRef') === false, strpos($tail, "\nxref\n") !== false);
    }

    /** @dataProvider forms */
    public function testFillTwiceAndUnicode(string $file): void
    {
        $once = (new PdfFormFiller(self::fixture($file)))->fill(['company_name' => 'First', 'newsletter' => true]);
        $twice = (new PdfFormFiller($once))->fill(['company_name' => 'Café Élan', 'newsletter' => false]);
        $f = (new PdfFormFiller($twice))->fields();
        $this->assertSame('Café Élan', $f['company_name']['value']);
        $this->assertSame('Off', $f['newsletter']['value']);
        $this->assertNull($f['address']['value']);
    }

    public function testNothingToFillReturnsTheSameFile(): void
    {
        $src = self::fixture('form-classic.pdf');
        $this->assertSame($src, (new PdfFormFiller($src))->fill(['nope' => 'x']));
    }

    public function testRejectsNonPdf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PdfFormFiller('hello');
    }

    public function testSyntaxHelpers(): void
    {
        $d = '<< /T (a\\)b) /Kids [1 0 R 2 0 R] /MK << /BC [0] >> /V /Off >>';
        $this->assertSame('(a\\)b)', PdfFormFiller::dictGet($d, 'T'));
        $this->assertSame('a)b', PdfFormFiller::unString('(a\\)b)'));
        $this->assertSame([1, 2], PdfFormFiller::refs((string)PdfFormFiller::dictGet($d, 'Kids')));
        $this->assertSame('<< /BC [0] >>', PdfFormFiller::dictGet($d, 'MK'));
        $this->assertNull(PdfFormFiller::dictGet($d, 'BC'), 'nested keys are not top-level');
        $set = PdfFormFiller::dictSet($d, 'V', '/Yes');
        $this->assertSame('/Yes', PdfFormFiller::dictGet($set, 'V'));
        $add = PdfFormFiller::dictSet($d, 'AS', '/Yes');
        $this->assertSame('/Yes', PdfFormFiller::dictGet($add, 'AS'));
        $this->assertSame('(a\\)b)', PdfFormFiller::dictGet($add, 'T'));
        $this->assertSame('(x\\(y\\))', PdfFormFiller::pdfString('x(y)'));
        $this->assertSame('é', PdfFormFiller::unString(PdfFormFiller::pdfString('é')));
        $this->assertEqualsWithDelta(21.11, PdfFormFiller::textWidth('Mow', 10), 0.01); // M 833 + o 556 + w 722
    }
}
