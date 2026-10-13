<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Core/StoredPdfPath.php';

/** Fable #8 (2026-10-12): documents/download.php streams only PDFs from the document folders. */
class StoredPdfPathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/spp_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/pdfs/salt-reports', 0777, true);
        mkdir($this->root . '/pdfs/invoices', 0777, true);
        mkdir($this->root . '/cache', 0777, true);
        file_put_contents($this->root . '/pdfs/salt-reports/salt_42_v1.pdf', '%PDF');
        file_put_contents($this->root . '/pdfs/invoices/INV-1.pdf', '%PDF');
        file_put_contents($this->root . '/cache/trackimo_token.json', '{}');
        file_put_contents($this->root . '/pdfs/invoices/notes.txt', 'x');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_document_pdfs_are_served(): void
    {
        $this->assertSame(realpath($this->root . '/pdfs/salt-reports/salt_42_v1.pdf'), StoredPdfPath::resolve('storage/pdfs/salt-reports/salt_42_v1.pdf', $this->root));
        $this->assertNotNull(StoredPdfPath::resolve('/storage/pdfs/invoices/INV-1.pdf', $this->root));
        $this->assertNotNull(StoredPdfPath::resolve('app/Storage/pdfs/invoices/INV-1.pdf', $this->root));
    }

    public static function refused(): array
    {
        return [
            ['storage/cache/trackimo_token.json'], ['storage/pdfs/invoices/notes.txt'],
            ['storage/pdfs/../cache/trackimo_token.json'], ['storage/pdfs/invoices/../../cache/trackimo_token.json'],
            ['storage/pdfs/backups/db.pdf'], ['storage/schema/x.pdf'], ['crm/tmp/jobber1.csv'],
            ['storage/pdfs/invoices/missing.pdf'], ["storage/pdfs/invoices/INV-1.pdf\0"], ['storage/pdfs/invoices\\INV-1.pdf'],
            ['/etc/passwd'], [''],
        ];
    }

    /** @dataProvider refused */
    public function test_everything_else_is_refused(string $path): void
    {
        $this->assertNull(StoredPdfPath::resolve($path, $this->root));
    }

    public function test_download_page_uses_the_allow_list(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../public/crm/documents/download.php');
        $this->assertStringContainsString('StoredPdfPath::resolve(', $src);
        $this->assertStringContainsString("requirePermission('billing.view')", $src);
    }
}
