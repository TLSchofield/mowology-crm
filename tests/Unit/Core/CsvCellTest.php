<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Core/Csv.php';

/** Security review #18 (2026-10-12): spreadsheet formula injection through CSV exports. */
class CsvCellTest extends TestCase
{
    public function test_formula_text_is_neutralised(): void
    {
        $this->assertSame("'=1+1", csvCell('=1+1'));
        $this->assertSame("'=HYPERLINK(\"https://evil\",\"x\")", csvCell('=HYPERLINK("https://evil","x")'));
        $this->assertSame("'+cmd|' /C calc'!A0", csvCell("+cmd|' /C calc'!A0"));
        $this->assertSame("'-2+3+cmd|' /C calc'!A0", csvCell("-2+3+cmd|' /C calc'!A0"));
        $this->assertSame("'@SUM(A1)", csvCell('@SUM(A1)'));
        $this->assertSame("'\t=1", csvCell("\t=1"));
        $this->assertSame("'\r=1", csvCell("\r=1"));
    }

    public function test_numbers_and_plain_text_are_unchanged(): void
    {
        $this->assertSame('-12.50', csvCell('-12.50'));
        $this->assertSame('-1,234.00', csvCell('-1,234.00'));
        $this->assertSame('+5', csvCell('+5'));
        $this->assertSame('12.5%', csvCell('12.5%'));
        $this->assertSame(-3.5, csvCell(-3.5));
        $this->assertSame(7, csvCell(7));
        $this->assertNull(csvCell(null));
        $this->assertSame('Smith, Jane', csvCell('Smith, Jane'));
        $this->assertSame('', csvCell(''));
        $this->assertSame("'-", csvCell('-'));
    }

    public function test_csvPut_writes_safe_cells(): void
    {
        $fh = fopen('php://memory', 'w+');
        csvPut($fh, ['=1+1', 'Bob', '-4.00']);
        rewind($fh);
        $this->assertSame("'=1+1,Bob,-4.00\n", stream_get_contents($fh));
    }

    public function test_every_export_uses_csvPut(): void
    {
        $root = dirname(__DIR__, 3);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
        $files = iterator_to_array($it);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public/crm', FilesystemIterator::SKIP_DOTS)) as $f) $files[] = $f;
        $bad = [];
        foreach ($files as $f) {
            $p = (string)$f;
            if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false || substr($p, -13) === '/Core/Csv.php') continue;
            if (preg_match('/\bfputcsv\s*\(/', (string)file_get_contents($p))) $bad[] = substr($p, strlen($root) + 1);
        }
        $this->assertSame([], $bad, 'use csvPut() (app/Core/Csv.php) instead of fputcsv()');
    }
}
