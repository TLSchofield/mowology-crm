<?php
/**
 * XlsxWriter — write a one-sheet .xlsx with no Composer library (Accountant's Ledger step 2, 2026-10-13).
 *
 * SHARED code (Ittai), the writing half of XlsxReader: ZipArchive + hand-written OOXML, nothing else.
 * (There is no PhpSpreadsheet in this codebase — Operations reads supplier .xlsx files with XlsxReader.)
 *
 * What an accountant's file needs, and nothing more:
 *   - numbers stored as numbers (money with a #,##0.00;(#,##0.00) format, integers plain);
 *   - dates stored as real Excel dates (serial number, yyyy-mm-dd format) so they sort and filter;
 *   - text as inline strings (no shared-string table to keep in step);
 *   - bold rows (titles, column headings, totals), a frozen heading row, column widths;
 *   - NO merged cells, ever.
 * The sheet XML is streamed to a temp file row by row, so a full year's journal never sits in memory.
 *
 * Usage:
 *   $x = new XlsxWriter('Trial balance');
 *   $x->row(['Entity', 'Mowology'], XlsxWriter::BOLD);
 *   $x->row(['Account', 'Debit'], XlsxWriter::BOLD, ['text', 'money']);   // types per column
 *   $x->freezeBelow(7); $x->widths([12, 40, 14]);
 *   $path = $x->save();   // a temp .xlsx path; the caller sends and unlinks it
 *
 * Cell types: 'text', 'money', 'int', 'number', 'date' (Y-m-d string → Excel date), or 'auto'
 * (int/float → number, anything else text). A null or '' cell is left empty.
 *
 * Global-namespace class: require_once the file.
 */
declare(strict_types=1);

class XlsxWriter
{
    public const PLAIN = 0;
    public const BOLD  = 1;

    // Style ids in styles.xml (cellXfs order).
    private const S_TEXT = 0, S_BOLD = 1, S_MONEY = 2, S_MONEY_BOLD = 3, S_DATE = 4, S_DATE_BOLD = 5, S_INT = 6, S_INT_BOLD = 7;

    private string $sheetName;
    private string $tmpSheet;
    /** @var resource */
    private $fh;
    private int $rowNum = 0;
    private int $maxCol = 0;
    private int $freeze = 0;
    private array $widths = [];
    private bool $closed = false;

    public function __construct(string $sheetName = 'Sheet1')
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is not available');
        // Excel sheet names: ≤ 31 chars, none of : \ / ? * [ ]
        $name = trim(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $sheetName) ?? 'Sheet1');
        $this->sheetName = mb_substr($name !== '' ? $name : 'Sheet1', 0, 31);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsxw');
        if ($tmp === false) throw new RuntimeException('no temp file');
        $this->tmpSheet = $tmp;
        $fh = fopen($tmp, 'w');
        if ($fh === false) throw new RuntimeException('temp file not writable');
        $this->fh = $fh;
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) fclose($this->fh);
        if (is_file($this->tmpSheet)) @unlink($this->tmpSheet);
    }

    /** Column letters for a 0-based index (0 → A, 26 → AA). Pure. */
    public static function colLetters(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + (($n - 1) % 26)) . $s;
        return $s;
    }

    /** Excel serial day number for a Y-m-d date (1900 date system), or null. Pure. */
    public static function serial(string $ymd): ?int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        $d = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), new DateTimeZone('UTC'));
        $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
        return (int)$base->diff($d)->days;
    }

    /** Text safe for XML 1.0 (control characters removed). Pure. */
    public static function xmlText(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Freeze everything above row $n+1 (e.g. the heading row is $n). */
    public function freezeBelow(int $n): void
    {
        $this->freeze = max(0, $n);
    }

    /** Column widths in characters, by position. */
    public function widths(array $w): void
    {
        $this->widths = array_values($w);
    }

    /** Rows written so far. */
    public function rowCount(): int
    {
        return $this->rowNum;
    }

    /**
     * Append a row.
     * @param array $cells values
     * @param int   $style self::PLAIN or self::BOLD
     * @param array $types per-column type ('text','money','int','number','date','auto'); missing → 'auto'
     */
    public function row(array $cells, int $style = self::PLAIN, array $types = []): void
    {
        if ($this->closed) throw new LogicException('workbook already saved');
        $r = ++$this->rowNum;
        $bold = $style === self::BOLD;
        $xml = '<row r="' . $r . '">';
        $i = 0;
        foreach (array_values($cells) as $v) {
            $ref = self::colLetters($i) . $r;
            $type = $types[$i] ?? 'auto';
            $i++;
            if ($v === null || $v === '') continue;
            if ($type === 'auto') $type = (is_int($v) ? 'int' : (is_float($v) ? 'number' : 'text'));
            if (($type === 'money' || $type === 'number' || $type === 'int') && !is_int($v) && !is_float($v)) {
                $type = is_numeric($v) ? $type : 'text';
            }
            if ($type === 'date') {
                $serial = is_string($v) ? self::serial($v) : null;
                if ($serial === null) { $type = 'text'; }
                else { $xml .= '<c r="' . $ref . '" s="' . ($bold ? self::S_DATE_BOLD : self::S_DATE) . '"><v>' . $serial . '</v></c>'; continue; }
            }
            if ($type === 'money' || $type === 'number') {
                $num = round((float)$v, 2);
                $xml .= '<c r="' . $ref . '" s="' . ($bold ? self::S_MONEY_BOLD : self::S_MONEY) . '"><v>' . self::num($num) . '</v></c>';
            } elseif ($type === 'int') {
                $xml .= '<c r="' . $ref . '" s="' . ($bold ? self::S_INT_BOLD : self::S_INT) . '"><v>' . (int)$v . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"' . ($bold ? ' s="' . self::S_BOLD . '"' : '') . '><is><t xml:space="preserve">'
                      . self::xmlText((string)$v) . '</t></is></c>';
            }
        }
        $this->maxCol = max($this->maxCol, $i);
        fwrite($this->fh, $xml . '</row>');
    }

    /** A number as Excel stores it ('.' decimal, no exponent for money). Pure. */
    private static function num(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }

    /** Write the workbook; returns the path of a temp .xlsx the caller must unlink. */
    public function save(): string
    {
        if ($this->closed) throw new LogicException('workbook already saved');
        $this->closed = true;
        fclose($this->fh);

        $out = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($out === false) throw new RuntimeException('no temp file');
        $zip = new ZipArchive();
        if ($zip->open($out, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('could not create the workbook');

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xmlText($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00;(#,##0.00)"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="8">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="1" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');

        // The sheet: head (views, columns) + streamed rows + tail.
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($this->freeze > 0) {
            $head .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $this->freeze . '" topLeftCell="A' . ($this->freeze + 1)
                   . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if ($this->widths) {
            $head .= '<cols>';
            foreach ($this->widths as $i => $w) {
                $head .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(4, min(80, (float)$w)) . '" customWidth="1"/>';
            }
            $head .= '</cols>';
        }
        $head .= '<sheetData>';
        $sheetFile = tempnam(sys_get_temp_dir(), 'xlsxs');
        if ($sheetFile === false) throw new RuntimeException('no temp file');
        $w = fopen($sheetFile, 'w');
        fwrite($w, $head);
        $r = fopen($this->tmpSheet, 'r');
        stream_copy_to_stream($r, $w);
        fclose($r);
        fwrite($w, '</sheetData><pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>');
        fclose($w);
        $zip->addFile($sheetFile, 'xl/worksheets/sheet1.xml');
        $ok = $zip->close();
        @unlink($sheetFile);
        @unlink($this->tmpSheet);
        if (!$ok) throw new RuntimeException('could not write the workbook');
        return $out;
    }
}
