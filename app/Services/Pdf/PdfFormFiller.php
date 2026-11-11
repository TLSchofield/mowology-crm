<?php
/**
 * PdfFormFiller — fills a fillable PDF form (AcroForm) in plain PHP (owner, 2026-10-10).
 *
 * Why it exists: supplier account applications arrive as fillable PDFs; Otto fills them from the
 * company profile. The CRM's mPDF can't help — the free FPDI parser refuses PDF 1.5+ files with
 * cross-reference streams (most modern forms, incl. Cedar Rim's) — and production has no shell
 * (no qpdf / pdftk / Ghostscript). This needs only zlib.
 *
 * How: reads the file's cross-reference (classic tables and xref streams, with PNG predictors,
 * following /Prev), finds objects (plain or inside object streams), walks the AcroForm fields,
 * then writes an INCREMENTAL UPDATE: the original bytes are untouched and new versions of the
 * changed objects are appended — each field gets /V, each widget a fresh appearance stream
 * (so every viewer shows the value, not only those that honour /NeedAppearances), checkboxes
 * get /V + /AS. The result stays a normal fillable form the supplier can still edit.
 *
 * Text fields and checkboxes only. Signature, choice and radio fields are reported, never filled.
 *
 *   $f = new PdfFormFiller(file_get_contents($path));
 *   $f->fields();                       // [name => ['type' => 'text'|'checkbox'|…, 'value' => …]]
 *   $pdf = $f->fill(['company_name' => 'Mowology', 'newsletter' => true]);
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

class PdfFormFiller
{
    /** Helvetica advance widths (1/1000 em) for ASCII 32–126; anything else counts as 556. */
    private const HELV = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];

    private string $pdf;
    /** obj number => ['type' => 1, 'offset' => int] | ['type' => 2, 'stream' => int, 'index' => int] */
    private array $xref = [];
    private array $trailer = [];   // raw values of the newest trailer: Root, Size, Info, ID
    private array $objCache = [];
    private array $objStmCache = [];

    public function __construct(string $pdf)
    {
        if (strncmp($pdf, '%PDF-', 5) !== 0) throw new InvalidArgumentException('Not a PDF');
        $this->pdf = $pdf;
        $this->readXref();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Public
    // ══════════════════════════════════════════════════════════════════════

    /** @return array<string, array{type:string, value:?string, widgets:int}> */
    public function fields(): array
    {
        $out = [];
        foreach ($this->terminalFields() as $name => $f) {
            $out[$name] = ['type' => $f['type'], 'value' => $f['value'], 'widgets' => count($f['widgets'])];
        }
        return $out;
    }

    /**
     * Fill the given fields. Text: string. Checkbox: true/false. Unknown names are ignored
     * (see fields()). Returns the new PDF bytes.
     */
    public function fill(array $values): string
    {
        $fields = $this->terminalFields();
        $acro = $this->acroForm();
        $dr = $acro['dict'] !== null ? self::dictGet($acro['dict'], 'DR') : null;
        $nextNum = max(array_keys($this->xref) ?: [0]) + 1;
        $size = max($nextNum, (int)($this->trailer['Size'] ?? 0));
        $nextNum = $size;
        $changed = [];   // num => raw object body (dict or full "dict stream…endstream")

        foreach ($values as $name => $value) {
            if (!isset($fields[$name])) continue;
            $f = $fields[$name];
            if ($f['type'] === 'text') {
                $text = (string)$value;
                $fieldDict = $changed[$f['num']] ?? $this->object($f['num'])['dict'];
                $changed[$f['num']] = self::dictSet($fieldDict, 'V', self::pdfString($text));
                foreach ($f['widgets'] as $w) {
                    $wd = $changed[$w] ?? $this->object($w)['dict'];
                    $rect = self::numbers(self::dictGet($wd, 'Rect') ?? '[0 0 0 0]');
                    $da = self::unString(self::dictGet($wd, 'DA') ?? $f['da'] ?? '') ?: '/Helv 0 Tf 0 g';
                    $num = $nextNum++;
                    $changed[$num] = self::appearance($rect, $da, $text, $this->resourcesFor($da, $dr));
                    $changed[$w] = self::dictSet($changed[$w] ?? $wd, 'AP', "<< /N {$num} 0 R >>");
                }
            } elseif ($f['type'] === 'checkbox') {
                $on = (bool)$value;
                foreach ($f['widgets'] as $w) {
                    $wd = $changed[$w] ?? $this->object($w)['dict'];
                    $state = $on ? $this->onState($wd) : 'Off';
                    $changed[$w] = self::dictSet($wd, 'AS', '/' . $state);
                    if ($w === $f['num']) $changed[$w] = self::dictSet($changed[$w], 'V', '/' . $state);
                }
                if (!in_array($f['num'], $f['widgets'], true)) {
                    $fd = $changed[$f['num']] ?? $this->object($f['num'])['dict'];
                    $changed[$f['num']] = self::dictSet($fd, 'V', '/' . ($on ? $this->onState($this->object($f['widgets'][0])['dict']) : 'Off'));
                }
            }
        }
        if (!$changed) return $this->pdf;

        // Viewers that rebuild appearances anyway should keep ours, not blank them: NeedAppearances false.
        if ($acro['num'] !== null) {
            $changed[$acro['num']] = self::dictSet($changed[$acro['num']] ?? $acro['dict'], 'NeedAppearances', 'false');
        } elseif ($acro['dict'] !== null) {
            $root = (int)$this->trailer['Root'];
            $rd = $this->object($root)['dict'];
            $changed[$root] = self::dictSet($rd, 'AcroForm', self::dictSet($acro['dict'], 'NeedAppearances', 'false'));
        }
        return $this->appendUpdate($changed, $nextNum);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Fields
    // ══════════════════════════════════════════════════════════════════════

    private function acroForm(): array
    {
        $root = $this->object((int)$this->trailer['Root'])['dict'];
        $af = self::dictGet($root, 'AcroForm');
        if ($af === null) return ['num' => null, 'dict' => null];
        if (preg_match('/^(\d+)\s+\d+\s+R$/', trim($af), $m)) {
            return ['num' => (int)$m[1], 'dict' => $this->object((int)$m[1])['dict']];
        }
        return ['num' => null, 'dict' => $af];
    }

    /** name => [num, type, value, da, widgets[]] for every field that holds a value. */
    private function terminalFields(): array
    {
        $acro = $this->acroForm();
        if ($acro['dict'] === null) return [];
        $out = [];
        $walk = function (int $num, string $prefix, ?string $ft, ?string $da, int $depth) use (&$walk, &$out) {
            if ($depth > 20) return;
            $d = $this->object($num)['dict'];
            $t = self::dictGet($d, 'T');
            $name = $t !== null ? self::unString($t) : '';
            $full = $t === null ? $prefix : ($prefix === '' ? $name : $prefix . '.' . $name);
            $ft = self::dictGet($d, 'FT') ?? $ft;
            $da = self::dictGet($d, 'DA') ?? $da;
            $kids = self::refs(self::dictGet($d, 'Kids') ?? '[]');
            $kidFields = array_values(array_filter($kids, fn($k) => self::dictGet($this->object($k)['dict'], 'T') !== null));
            if ($kidFields) {
                foreach ($kids as $k) $walk($k, $full, $ft, $da, $depth + 1);
                return;
            }
            if ($full === '') return;
            $widgets = $kids ?: [$num];
            $ff = (int)(self::dictGet($d, 'Ff') ?? 0);
            $type = match (trim((string)$ft)) {
                '/Tx'  => 'text',
                '/Btn' => ($ff & (1 << 15)) ? 'radio' : (($ff & (1 << 16)) ? 'button' : 'checkbox'),
                '/Ch'  => 'choice',
                '/Sig' => 'signature',
                default => 'unknown',
            };
            $v = self::dictGet($d, 'V');
            $out[$full] = ['num' => $num, 'type' => $type, 'value' => $v === null ? null : self::unString($v),
                           'da' => $da, 'widgets' => $widgets];
        };
        foreach (self::refs(self::dictGet($acro['dict'], 'Fields') ?? '[]') as $r) $walk($r, '', null, null, 0);
        return $out;
    }

    private function onState(string $widgetDict): string
    {
        $ap = self::dictGet($widgetDict, 'AP');
        if ($ap !== null) {
            $ap = $this->resolve($ap);
            $n = self::dictGet($ap, 'N');
            if ($n !== null) {
                $n = $this->resolve($n);
                foreach (self::dictKeys($n) as $k) if ($k !== 'Off') return $k;
            }
        }
        return 'Yes';
    }

    /** An indirect reference's dict, or the value itself. */
    private function resolve(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d+)\s+\d+\s+R$/', $raw, $m)) return $this->object((int)$m[1])['dict'];
        return $raw;
    }

    /**
     * Resources for an appearance: the form's own /DR when it defines the /DA font, else a
     * standard Helvetica under that font name (a missing font draws nothing in most viewers).
     */
    private function resourcesFor(string $da, ?string $dr): string
    {
        $font = preg_match('#/([^\s/]+)\s+[\d.]+\s+Tf#', $da, $m) ? $m[1] : 'Helv';
        if ($dr !== null) {
            $fonts = self::dictGet($this->resolve($dr), 'Font');
            if ($fonts !== null && in_array($font, self::dictKeys($this->resolve($fonts)), true)) return trim($dr);
        }
        return "<< /Font << /{$font} << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >> >> >>";
    }

    /** A Form XObject that draws the text the way the field's /DA says. */
    private static function appearance(array $rect, string $da, string $text, string $resources): string
    {
        $w = abs(($rect[2] ?? 0) - ($rect[0] ?? 0));
        $h = abs(($rect[3] ?? 0) - ($rect[1] ?? 0));
        $font = 'Helv';
        $size = 0.0;
        if (preg_match('#/([^\s/]+)\s+([\d.]+)\s+Tf#', $da, $m)) { $font = $m[1]; $size = (float)$m[2]; }
        if ($size <= 0) $size = max(6.0, min(12.0, $h - 3));
        // Shrink to fit the box (never below 6 pt) rather than cut the text off.
        $need = self::textWidth($text, $size);
        if ($need > $w - 4 && $need > 0) $size = max(6.0, $size * ($w - 4) / $need);
        $colour = preg_match('#([\d.]+\s+[\d.]+\s+[\d.]+\s+rg|[\d.]+\s+g)#', $da, $c) ? $c[1] : '0 g';
        $y = max(1.0, ($h - $size) / 2 + $size * 0.22);
        $enc = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        $content = "/Tx BMC\nq\n1 1 " . self::num($w - 2) . ' ' . self::num($h - 2) . " re W n\nBT\n/{$font} " . self::num($size) . " Tf {$colour}\n2 "
                 . self::num($y) . " Td\n" . self::literal($enc === false ? $text : $enc) . " Tj\nET\nQ\nEMC";
        $res = '/Resources ' . $resources;
        return '<< /Type /XObject /Subtype /Form /BBox [0 0 ' . self::num($w) . ' ' . self::num($h) . "] {$res} /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";
    }

    // ══════════════════════════════════════════════════════════════════════
    // Writing the incremental update
    // ══════════════════════════════════════════════════════════════════════

    private function appendUpdate(array $changed, int $size): string
    {
        ksort($changed);
        $out = $this->pdf;
        if (substr($out, -1) !== "\n") $out .= "\n";
        $offsets = [];
        foreach ($changed as $num => $body) {
            $gen = 0;
            $offsets[$num] = strlen($out);
            $out .= "{$num} {$gen} obj\n{$body}\nendobj\n";
        }
        $prev = $this->startxref();
        $xrefAt = strlen($out);
        $tail = ' /Root ' . $this->trailer['Root'] . ' 0 R /Prev ' . $prev;
        if (!empty($this->trailer['Info'])) $tail .= ' /Info ' . $this->trailer['Info'] . ' 0 R';
        if (!empty($this->trailer['ID'])) $tail .= ' /ID ' . $this->trailer['ID'];

        if (substr($this->pdf, $prev, 4) === 'xref') {
            // The file keeps a classic table: update with one.
            $out .= "xref\n";
            foreach ($offsets as $num => $off) {
                $out .= "{$num} 1\n" . sprintf('%010d 00000 n', $off) . "\r\n";
            }
            $out .= "trailer\n<< /Size {$size}{$tail} >>\nstartxref\n{$xrefAt}\n%%EOF\n";
            return $out;
        }

        // The file keeps an xref STREAM: update with one too. Apple's PDF engine (Preview, iPhone)
        // refuses a classic table after a stream ("failed to find start of cross-reference
        // section") — found 2026-10-10 on Cedar Rim's form; PyMuPDF accepted both.
        $self = $size;
        $offsets[$self] = $xrefAt;
        ksort($offsets);
        $index = [];
        $data = '';
        foreach ($offsets as $num => $off) {
            $index[] = "{$num} 1";
            $data .= pack('CNn', 1, $off, 0);
        }
        $out .= "{$self} 0 obj\n<< /Type /XRef /Size " . ($self + 1) . ' /W [1 4 2] /Index [' . implode(' ', $index) . ']'
              . $tail . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream\nendobj\n"
              . "startxref\n{$xrefAt}\n%%EOF\n";
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Reading the file
    // ══════════════════════════════════════════════════════════════════════

    private function startxref(): int
    {
        $tail = substr($this->pdf, -2048);
        if (!preg_match_all('/startxref\s+(\d+)/', $tail, $m)) throw new RuntimeException('No startxref');
        return (int)end($m[1]);
    }

    private function readXref(): void
    {
        $at = $this->startxref();
        $seen = [];
        $first = true;
        while ($at > 0 && !isset($seen[$at])) {
            $seen[$at] = true;
            if (substr($this->pdf, $at, 4) === 'xref') {
                $trailer = $this->readXrefTable($at);
            } else {
                $trailer = $this->readXrefStream($at);
            }
            if ($first) {
                foreach (['Root', 'Info'] as $k) {
                    $v = self::dictGet($trailer, $k);
                    if ($v !== null && preg_match('/^(\d+)/', trim($v), $m)) $this->trailer[$k] = (int)$m[1];
                }
                $this->trailer['Size'] = (int)(self::dictGet($trailer, 'Size') ?? 0);
                $id = self::dictGet($trailer, 'ID');
                if ($id !== null) $this->trailer['ID'] = trim($id);
                $first = false;
            }
            // A hybrid file's table points at its stream too.
            $stm = self::dictGet($trailer, 'XRefStm');
            if ($stm !== null) $this->readXrefStream((int)$stm);
            $prev = self::dictGet($trailer, 'Prev');
            $at = $prev === null ? 0 : (int)$prev;
        }
        if (empty($this->trailer['Root'])) throw new RuntimeException('No document catalog');
    }

    /** Newer sections are read first; an entry already known is never overwritten. */
    private function setEntry(int $num, array $e): void
    {
        if (!isset($this->xref[$num])) $this->xref[$num] = $e;
    }

    private function readXrefTable(int $at): string
    {
        $pos = $at + 4;
        $len = strlen($this->pdf);
        while ($pos < $len) {
            if (!preg_match('/\G\s*(\d+)\s+(\d+)\s*\r?\n/A', $this->pdf, $m, 0, $pos)) break;
            $pos += strlen($m[0]);
            [$start, $count] = [(int)$m[1], (int)$m[2]];
            for ($i = 0; $i < $count; $i++) {
                $line = substr($this->pdf, $pos, 20);
                $pos += 20;
                if (!preg_match('/(\d{10})\s(\d{5})\s([nf])/', $line, $e)) continue;
                if ($e[3] === 'n') $this->setEntry($start + $i, ['type' => 1, 'offset' => (int)$e[1]]);
                else $this->setEntry($start + $i, ['type' => 0]);
            }
        }
        $t = strpos($this->pdf, 'trailer', $pos - 1);
        if ($t === false) throw new RuntimeException('No trailer');
        return self::readDict($this->pdf, $t + 7);
    }

    private function readXrefStream(int $at): string
    {
        $o = $this->parseObjectAt($at);
        $d = $o['dict'];
        $data = self::decodeStream($d, $o['stream']);
        $w = self::numbers(self::dictGet($d, 'W') ?? '[1 2 1]');
        $size = (int)(self::dictGet($d, 'Size') ?? 0);
        $index = self::numbers(self::dictGet($d, 'Index') ?? "[0 {$size}]");
        $row = array_sum($w);
        $p = 0;
        for ($s = 0; $s + 1 < count($index); $s += 2) {
            for ($i = 0; $i < (int)$index[$s + 1]; $i++, $p += $row) {
                $f = [];
                $q = $p;
                foreach ($w as $k => $wn) {
                    $v = 0;
                    for ($b = 0; $b < $wn; $b++) $v = ($v << 8) | ord($data[$q++] ?? "\0");
                    $f[$k] = $wn === 0 ? ($k === 0 ? 1 : 0) : $v;
                }
                $num = (int)$index[$s] + $i;
                if ($f[0] === 1) $this->setEntry($num, ['type' => 1, 'offset' => $f[1]]);
                elseif ($f[0] === 2) $this->setEntry($num, ['type' => 2, 'stream' => $f[1], 'index' => $f[2]]);
                else $this->setEntry($num, ['type' => 0]);
            }
        }
        return $d;
    }

    /** ['dict' => raw dictionary or value, 'stream' => ?raw bytes] */
    private function object(int $num): array
    {
        if (isset($this->objCache[$num])) return $this->objCache[$num];
        $e = $this->xref[$num] ?? null;
        if ($e === null || $e['type'] === 0) throw new RuntimeException("Object {$num} not found");
        if ($e['type'] === 1) {
            $o = $this->parseObjectAt($e['offset']);
        } else {
            $o = ['dict' => $this->fromObjectStream($e['stream'], $num), 'stream' => null];
        }
        return $this->objCache[$num] = $o;
    }

    private function fromObjectStream(int $stmNum, int $num): string
    {
        if (!isset($this->objStmCache[$stmNum])) {
            $o = $this->object($stmNum);
            $data = self::decodeStream($o['dict'], $o['stream']);
            $n = (int)self::dictGet($o['dict'], 'N');
            $first = (int)self::dictGet($o['dict'], 'First');
            $nums = self::numbers('[' . substr($data, 0, $first) . ']');
            $map = [];
            for ($i = 0; $i < $n; $i++) {
                $start = $first + (int)$nums[2 * $i + 1];
                $end = $i + 1 < $n ? $first + (int)$nums[2 * $i + 3] : strlen($data);
                $map[(int)$nums[2 * $i]] = trim(substr($data, $start, $end - $start));
            }
            $this->objStmCache[$stmNum] = $map;
        }
        if (!isset($this->objStmCache[$stmNum][$num])) throw new RuntimeException("Object {$num} missing from stream {$stmNum}");
        return $this->objStmCache[$stmNum][$num];
    }

    private function parseObjectAt(int $offset): array
    {
        if (!preg_match('/\G\s*\d+\s+\d+\s+obj\s*/A', $this->pdf, $m, 0, $offset)) {
            throw new RuntimeException("No object at {$offset}");
        }
        $p = $offset + strlen($m[0]);
        $end = self::valueEnd($this->pdf, $p);
        $dict = substr($this->pdf, $p, $end - $p);
        $stream = null;
        if (preg_match('/\G\s*stream\r?\n/A', $this->pdf, $s, 0, $end)) {
            $start = $end + strlen($s[0]);
            $lenRaw = self::dictGet($dict, 'Length');
            $len = null;
            if ($lenRaw !== null) {
                $lenRaw = trim($lenRaw);
                $len = preg_match('/^(\d+)\s+\d+\s+R$/', $lenRaw, $lm) ? (int)trim($this->object((int)$lm[1])['dict']) : (int)$lenRaw;
            }
            if ($len === null || substr($this->pdf, $start + $len, 20) === '' || strpos(substr($this->pdf, $start + $len, 20), 'endstream') === false) {
                $len = strpos($this->pdf, 'endstream', $start) - $start;
            }
            $stream = substr($this->pdf, $start, $len);
        }
        return ['dict' => trim($dict), 'stream' => $stream];
    }

    private static function decodeStream(string $dict, ?string $raw): string
    {
        $raw = (string)$raw;
        $filter = trim((string)(self::dictGet($dict, 'Filter') ?? ''));
        if ($filter === '') return $raw;
        if (!in_array($filter, ['/FlateDecode', '[/FlateDecode]', '[ /FlateDecode ]'], true)) {
            throw new RuntimeException('Unsupported stream filter ' . $filter);
        }
        $data = @gzuncompress($raw);
        if ($data === false) $data = @gzinflate(substr($raw, 2));
        if ($data === false) throw new RuntimeException('Could not decompress a stream');
        $parms = self::dictGet($dict, 'DecodeParms');
        if ($parms !== null && (int)(self::dictGet($parms, 'Predictor') ?? 1) >= 10) {
            $data = self::unPng($data, (int)(self::dictGet($parms, 'Columns') ?? 1));
        }
        return $data;
    }

    /** PNG predictors (as used by xref streams): each row = 1 filter byte + Columns bytes. */
    private static function unPng(string $data, int $cols): string
    {
        $out = '';
        $prev = array_fill(0, $cols, 0);
        $rowLen = $cols + 1;
        for ($p = 0; $p + $rowLen <= strlen($data); $p += $rowLen) {
            $type = ord($data[$p]);
            $row = [];
            for ($i = 0; $i < $cols; $i++) {
                $x = ord($data[$p + 1 + $i]);
                $a = $i > 0 ? $row[$i - 1] : 0;
                $b = $prev[$i];
                $c = $i > 0 ? $prev[$i - 1] : 0;
                switch ($type) {
                    case 1: $x += $a; break;
                    case 2: $x += $b; break;
                    case 3: $x += intdiv($a + $b, 2); break;
                    case 4:
                        $pa = abs($b - $c); $pb = abs($a - $c); $pc = abs($a + $b - 2 * $c);
                        $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        break;
                }
                $row[$i] = $x & 0xFF;
            }
            $out .= pack('C*', ...$row);
            $prev = $row;
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Tiny PDF syntax helpers (pure, unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** Offset just past the PDF value that starts at $p (skipping leading whitespace). */
    public static function valueEnd(string $s, int $p): int
    {
        $len = strlen($s);
        while ($p < $len && ctype_space($s[$p])) $p++;
        if ($p >= $len) return $p;
        $c = $s[$p];
        if ($c === '<' && ($s[$p + 1] ?? '') === '<') {
            $depth = 0;
            while ($p < $len) {
                if ($s[$p] === '(') { $p = self::stringEnd($s, $p); continue; }
                if ($s[$p] === '<' && ($s[$p + 1] ?? '') === '<') { $depth++; $p += 2; continue; }
                if ($s[$p] === '>' && ($s[$p + 1] ?? '') === '>') { $depth--; $p += 2; if ($depth === 0) return $p; continue; }
                if ($s[$p] === '%') { while ($p < $len && $s[$p] !== "\n" && $s[$p] !== "\r") $p++; continue; }
                $p++;
            }
            return $p;
        }
        if ($c === '[') {
            $depth = 0;
            while ($p < $len) {
                if ($s[$p] === '(') { $p = self::stringEnd($s, $p); continue; }
                if ($s[$p] === '<' && ($s[$p + 1] ?? '') === '<') { $p = self::valueEnd($s, $p); continue; }
                if ($s[$p] === '[') { $depth++; $p++; continue; }
                if ($s[$p] === ']') { $depth--; $p++; if ($depth === 0) return $p; continue; }
                $p++;
            }
            return $p;
        }
        if ($c === '(') return self::stringEnd($s, $p);
        if ($c === '<') { $e = strpos($s, '>', $p); return $e === false ? $len : $e + 1; }
        if ($c === '/') {
            $p++;
            while ($p < $len && !ctype_space($s[$p]) && strpos('/[]<>()%{}', $s[$p]) === false) $p++;
            return $p;
        }
        // number, true/false/null, or "n g R"
        if (preg_match('/\G(\d+\s+\d+\s+R)\b/A', $s, $m, 0, $p)) return $p + strlen($m[1]);
        while ($p < $len && !ctype_space($s[$p]) && strpos('/[]<>()%', $s[$p]) === false) $p++;
        return $p;
    }

    private static function stringEnd(string $s, int $p): int
    {
        $len = strlen($s);
        $depth = 0;
        for (; $p < $len; $p++) {
            $c = $s[$p];
            if ($c === '\\') { $p++; continue; }
            if ($c === '(') $depth++;
            elseif ($c === ')') { $depth--; if ($depth === 0) return $p + 1; }
        }
        return $len;
    }

    private static function readDict(string $s, int $p): string
    {
        while ($p < strlen($s) && ctype_space($s[$p])) $p++;
        return substr($s, $p, self::valueEnd($s, $p) - $p);
    }

    /** Top-level entries of a dictionary: key => [valueStart, valueEnd, keyStart]. */
    private static function entries(string $dict): array
    {
        $dict = trim($dict);
        $out = [];
        if (strncmp($dict, '<<', 2) !== 0) return $out;
        $p = 2;
        $len = strlen($dict) - 2;
        while ($p < $len) {
            while ($p < $len && ctype_space($dict[$p])) $p++;
            if ($p >= $len || $dict[$p] !== '/') { $p++; continue; }
            $ks = $p;
            $ke = self::valueEnd($dict, $p);
            $key = substr($dict, $ks + 1, $ke - $ks - 1);
            $vs = $ke;
            while ($vs < $len && ctype_space($dict[$vs])) $vs++;
            $ve = self::valueEnd($dict, $vs);
            $out[$key] = [$vs, $ve, $ks];
            $p = $ve;
        }
        return $out;
    }

    public static function dictGet(string $dict, string $key): ?string
    {
        $dict = trim($dict);
        $e = self::entries($dict)[$key] ?? null;
        return $e === null ? null : substr($dict, $e[0], $e[1] - $e[0]);
    }

    public static function dictKeys(string $dict): array
    {
        return array_keys(self::entries(trim($dict)));
    }

    public static function dictSet(string $dict, string $key, string $value): string
    {
        $dict = trim($dict);
        $e = self::entries($dict)[$key] ?? null;
        if ($e !== null) return substr($dict, 0, $e[0]) . $value . substr($dict, $e[1]);
        return rtrim(substr($dict, 0, -2)) . ' /' . $key . ' ' . $value . ' >>';
    }

    /** "[1 0 R 2 0 R]" => [1, 2] */
    public static function refs(string $arr): array
    {
        preg_match_all('/(\d+)\s+\d+\s+R/', $arr, $m);
        return array_map('intval', $m[1]);
    }

    public static function numbers(string $arr): array
    {
        preg_match_all('/-?\d+(?:\.\d+)?|-?\.\d+/', $arr, $m);
        return array_map('floatval', $m[0]);
    }

    /** Decodes a PDF string value: (literal) or <hex>, UTF-16BE with BOM → UTF-8. */
    public static function unString(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        if ($raw[0] === '(') {
            $inner = substr($raw, 1, -1);
            $out = preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r?\n)/', function ($m) {
                $c = $m[1];
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
                if (isset($map[$c])) return $map[$c];
                if ($c[0] === "\r" || $c[0] === "\n") return '';
                return chr(octdec($c) & 0xFF);
            }, $inner);
        } elseif ($raw[0] === '<') {
            $out = (string)hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', substr($raw, 1, -1)) . (strlen(preg_replace('/[^0-9A-Fa-f]/', '', $raw)) % 2 ? '0' : ''));
        } else {
            return ltrim($raw, '/');
        }
        if (strncmp($out, "\xFE\xFF", 2) === 0) return (string)iconv('UTF-16BE', 'UTF-8', substr($out, 2));
        return $out;
    }

    /** A field value: plain ASCII as a literal, anything else as UTF-16BE hex with a BOM. */
    public static function pdfString(string $utf8): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $utf8)) return self::literal($utf8);
        return '<FEFF' . strtoupper(bin2hex((string)iconv('UTF-8', 'UTF-16BE', $utf8))) . '>';
    }

    public static function literal(string $s): string
    {
        return '(' . strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']) . ')';
    }

    /** Width in points of $text in Helvetica at $size. */
    public static function textWidth(string $text, float $size): float
    {
        $units = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $o = strlen($ch) === 1 ? ord($ch) : 0;
            $units += ($o >= 32 && $o <= 126) ? self::HELV[$o - 32] : 556;
        }
        return $units * $size / 1000;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0';
    }
}
