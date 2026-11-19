<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Core/PdfStore.php';

/** Security review 2026-11-06 #1: each business keeps its PDFs in its own folder; the default business is unchanged. */
class PdfStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!defined('STORAGE_ROOT')) define('STORAGE_ROOT', sys_get_temp_dir() . '/pdfstore_' . bin2hex(random_bytes(4)));
        $this->root = STORAGE_ROOT;
    }

    protected function tearDown(): void
    {
        Tenant::forTests(null);
    }

    private function as(string $key): void
    {
        Tenant::forTests(['key' => $key, 'cfg' => []]);
    }

    public function testDefaultBusinessHasNoSegmentAndKeepsOldShapes(): void
    {
        Tenant::forTests(null);
        $this->assertSame('', PdfStore::segment());
        $this->assertSame(STORAGE_ROOT . '/pdfs/invoices', PdfStore::dir('invoices', false));
        $this->assertSame('app/Storage/pdfs/invoices/INV-2026-0001_v1.pdf', PdfStore::relative('invoices', 'INV-2026-0001_v1.pdf', 'app/Storage'));
        $this->assertSame('storage/pdfs/salt-reports/salt_3_v1.pdf', PdfStore::relative('salt-reports', 'salt_3_v1.pdf'));
    }

    public function testOtherBusinessGetsItsOwnSegment(): void
    {
        $this->as('rehearsal2');
        $this->assertSame('t/rehearsal2/', PdfStore::segment());
        $this->assertSame(STORAGE_ROOT . '/pdfs/t/rehearsal2/invoices', PdfStore::dir('invoices', false));
        $this->assertSame('app/Storage/pdfs/t/rehearsal2/invoices/INV-2026-0001_v1.pdf', PdfStore::relative('invoices', 'INV-2026-0001_v1.pdf', 'app/Storage'));
        $this->assertSame('storage/pdfs/t/rehearsal2/salt-reports/salt_3_v1.pdf', PdfStore::relative('salt-reports', 'salt_3_v1.pdf'));
    }

    public function testSameInvoiceNumberNeverSharesAPathBetweenBusinesses(): void
    {
        Tenant::forTests(null);
        $a = PdfStore::dir('invoices', false) . '/INV-2026-0001_v1.pdf';
        $this->as('rehearsal2');
        $b = PdfStore::dir('invoices', false) . '/INV-2026-0001_v1.pdf';
        $this->as('other-co');
        $c = PdfStore::dir('invoices', false) . '/INV-2026-0001_v1.pdf';
        $this->assertCount(3, array_unique([$a, $b, $c]));
    }

    public function testUnknownFolderRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PdfStore::dir('../etc', false);
    }

    public function testDirCreatesFolderForTenant(): void
    {
        $this->as('rehearsal2');
        $dir = PdfStore::dir('quotes');
        $this->assertDirectoryExists($dir);
        @rmdir($dir); @rmdir(dirname($dir)); @rmdir(dirname($dir, 2)); @rmdir(dirname($dir, 3));
    }

    public function testAbsoluteFromStoredIsPerBusiness(): void
    {
        $this->as('rehearsal2');
        $rel = PdfStore::relative('invoices', 'INV-1_v1.pdf', 'app/Storage');
        $this->assertSame(STORAGE_ROOT . '/pdfs/t/rehearsal2/invoices/INV-1_v1.pdf', PdfStore::absoluteFromStored($rel, false));
        $this->assertNull(PdfStore::absoluteFromStored('app/Storage/pdfs/invoices/INV-1_v1.pdf', false));
        $this->as('other-co');
        $this->assertNull(PdfStore::absoluteFromStored($rel, false));
    }
}
