<?php
declare(strict_types=1);

/**
 * Test doubles for the "every invoice email carries the PDF" rule (InvoicePdfGate).
 */

/** Stands in for PdfGenerator: generateInvoicePdf() / getPdfPath() return whatever the test sets. */
final class FakeInvoicePdfGenerator
{
    /** @var array|Throwable */
    public $generate = ['success' => false, 'error' => 'not configured'];
    public ?string $cached = null;
    public int $generateCalls = 0;

    public function generateInvoicePdf(int $invoiceId): array
    {
        $this->generateCalls++;
        if ($this->generate instanceof Throwable) throw $this->generate;
        return $this->generate;
    }

    public function getPdfPath(string $type, int $id): ?string
    {
        return $this->cached;
    }
}

final class InvoicePdfTestKit
{
    /** In-memory SQLite with MySQL's NOW() so production SQL runs unchanged. */
    public static function sqlite(): PDO
    {
        if (class_exists('Pdo\\Sqlite')) {
            $db = new Pdo\Sqlite('sqlite::memory:');
            $db->createFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        } else {
            $db = new PDO('sqlite::memory:');
            $db->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT NULL, invoice_id INT NULL,
                   action VARCHAR(100) NOT NULL, details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        return $db;
    }

    public static function pdfFile(string $dir, string $name, string $body = "%PDF-1.4\n% test\n"): string
    {
        $p = $dir . '/' . $name;
        file_put_contents($p, $body);
        return $p;
    }

    public static function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/mw-pdfgate-' . bin2hex(random_bytes(4));
        mkdir($d);
        return $d;
    }

    public static function rmDir(string $d): void
    {
        array_map('unlink', glob($d . '/*') ?: []);
        @rmdir($d);
    }
}
