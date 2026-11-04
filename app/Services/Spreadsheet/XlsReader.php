<?php
/**
 * XlsReader — the first sheet of an old Excel file (.xls, BIFF8 inside an OLE2 compound file).
 * SHARED code (Ittai). Same output as XlsxReader::firstSheet: [rowNumber => [columnLetters => value]],
 * row 1 = first row, numbers as int (whole) or float, text as string. Formulas give their cached result.
 *
 * Why hand-written (owner's supplier lists, 2026-10-19): Specimen Trees mails its monthly availability as
 * .xls. A full spreadsheet library is thousands of files over FTP; reading values only is small:
 * the compound-file container (FAT chains, the "Workbook" stream) and seven BIFF record types.
 * Checked cell by cell against Python's xlrd on the real Specimen file (tests/Unit/Spreadsheet).
 *
 * Global-namespace class: require_once the file.
 */
class XlsReader
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** @throws RuntimeException */
    public static function firstSheet(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_BYTES) throw new RuntimeException('xls: file too large');
        $wb = self::workbookStream($bytes);
        return self::readSheet($wb);
    }

    // ── OLE2 compound file ─────────────────────────────────────────────────────────────────────

    private static function u16(string $b, int $o): int { return unpack('v', substr($b, $o, 2))[1]; }
    private static function u32(string $b, int $o): int { return unpack('V', substr($b, $o, 4))[1]; }

    /** The Workbook (or old "Book") stream's bytes. */
    public static function workbookStream(string $b): string
    {
        if (strncmp($b, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) !== 0) throw new RuntimeException('xls: not a compound file');
        $ssz = 1 << self::u16($b, 0x1E);
        $mssz = 1 << self::u16($b, 0x20);
        $firstDir = self::u32($b, 0x30);
        $cutoff = self::u32($b, 0x38);
        $firstMiniFat = self::u32($b, 0x3C);
        $nDifat = self::u32($b, 0x48);
        $firstDifat = self::u32($b, 0x44);
        $sector = static function (int $sid) use ($b, $ssz): string { return (string)substr($b, ($sid + 1) * $ssz, $ssz); };

        // FAT sector list: 109 in the header, the rest in DIFAT sectors.
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $sid = self::u32($b, 0x4C + 4 * $i);
            if ($sid < 0xFFFFFFFA) $fatSectors[] = $sid;
        }
        $d = $firstDifat;
        for ($n = 0; $n < $nDifat && $d < 0xFFFFFFFA; $n++) {
            $s = $sector($d);
            $per = intdiv($ssz, 4) - 1;
            for ($i = 0; $i < $per; $i++) {
                $sid = self::u32($s, 4 * $i);
                if ($sid < 0xFFFFFFFA) $fatSectors[] = $sid;
            }
            $d = self::u32($s, 4 * $per);
        }
        $fat = [];
        foreach ($fatSectors as $sid) {
            $s = $sector($sid);
            foreach (array_values(unpack('V*', $s)) as $v) $fat[] = $v;
        }
        $chain = static function (int $start, array $table) {
            $out = [];
            $seen = [];
            for ($sid = $start; $sid < 0xFFFFFFFA && isset($table[$sid]) && !isset($seen[$sid]); $sid = $table[$sid]) {
                $seen[$sid] = true;
                $out[] = $sid;
            }
            return $out;
        };
        $read = static function (int $start) use ($chain, $fat, $sector): string {
            $buf = '';
            foreach ($chain($start, $fat) as $sid) $buf .= $sector($sid);
            return $buf;
        };

        $dir = $read($firstDir);
        $root = null;
        $book = null;
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $nameLen = self::u16($dir, $o + 64);
            $type = ord($dir[$o + 66]);
            if ($nameLen < 2 || $type === 0) continue;
            $name = (string)@iconv('UTF-16LE', 'UTF-8', substr($dir, $o, $nameLen - 2));
            $entry = ['type' => $type, 'start' => self::u32($dir, $o + 116), 'size' => self::u32($dir, $o + 120)];
            if ($type === 5) $root = $entry;
            if ($type === 2 && ($name === 'Workbook' || ($name === 'Book' && $book === null))) $book = $entry;
        }
        if ($book === null) throw new RuntimeException('xls: no Workbook stream');

        if ($book['size'] >= $cutoff) return (string)substr($read($book['start']), 0, $book['size']);

        // Small stream: in the mini stream (root's data), sectors chained by the mini FAT.
        if ($root === null) throw new RuntimeException('xls: no root entry');
        $mini = $read($root['start']);
        $miniFat = array_values(unpack('V*', $read($firstMiniFat)) ?: []);
        $buf = '';
        foreach ($chain($book['start'], $miniFat) as $sid) $buf .= substr($mini, $sid * $mssz, $mssz);
        return (string)substr($buf, 0, $book['size']);
    }

    // ── BIFF8 ──────────────────────────────────────────────────────────────────────────────────

    /** @return array<int, array{type: int, data: string}> records in order */
    private static function records(string $wb): array
    {
        $out = [];
        $n = strlen($wb);
        for ($o = 0; $o + 4 <= $n; ) {
            $type = self::u16($wb, $o);
            $len = self::u16($wb, $o + 2);
            $out[] = ['type' => $type, 'data' => (string)substr($wb, $o + 4, $len), 'at' => $o];
            $o += 4 + $len;
        }
        return $out;
    }

    private static function readSheet(string $wb): array
    {
        $recs = self::records($wb);
        $sheetAt = null;
        $sstParts = [];
        $inSst = false;
        foreach ($recs as $r) {
            if ($r['type'] === 0x0085 && $sheetAt === null) $sheetAt = self::u32($r['data'], 0);   // BOUNDSHEET: first sheet's BOF
            if ($r['type'] === 0x00FC) { $sstParts = [$r['data']]; $inSst = true; continue; }
            if ($inSst && $r['type'] === 0x003C) { $sstParts[] = $r['data']; continue; }          // CONTINUE
            $inSst = false;
        }
        $sst = $sstParts ? self::sst($sstParts) : [];
        if ($sheetAt === null) throw new RuntimeException('xls: no sheet');

        $cells = [];
        $put = static function (int $row, int $col, $v) use (&$cells): void {
            if ($v === null || $v === '') return;
            $cells[$row + 1][SheetReader::letters($col + 1)] = $v;
        };
        $pendingFormula = null;
        $started = false;
        foreach ($recs as $r) {
            if (!$started) { if ($r['at'] === $sheetAt) $started = true; else continue; }
            $t = $r['type'];
            $d = $r['data'];
            if ($t === 0x000A) break;                                                             // EOF of the sheet
            if ($t === 0x00FD && strlen($d) >= 10) {                                                // LABELSST
                $put(self::u16($d, 0), self::u16($d, 2), $sst[self::u32($d, 6)] ?? null);
            } elseif ($t === 0x0203 && strlen($d) >= 14) {                                         // NUMBER
                $put(self::u16($d, 0), self::u16($d, 2), self::num(unpack('e', substr($d, 6, 8))[1]));
            } elseif ($t === 0x027E && strlen($d) >= 10) {                                         // RK
                $put(self::u16($d, 0), self::u16($d, 2), self::rk(self::u32($d, 6)));
            } elseif ($t === 0x00BD && strlen($d) >= 6) {                                          // MULRK
                $row = self::u16($d, 0);
                $col = self::u16($d, 2);
                for ($o = 4; $o + 6 <= strlen($d) - 2; $o += 6, $col++) $put($row, $col, self::rk(self::u32($d, $o + 2)));
            } elseif ($t === 0x0204 && strlen($d) >= 9) {                                          // LABEL
                $put(self::u16($d, 0), self::u16($d, 2), self::shortString($d, 6));
            } elseif ($t === 0x0205 && strlen($d) >= 8) {                                          // BOOLERR
                if (ord($d[7]) === 0) $put(self::u16($d, 0), self::u16($d, 2), ord($d[6]) ? 1 : 0);
            } elseif ($t === 0x0006 && strlen($d) >= 14) {                                         // FORMULA: cached result
                $row = self::u16($d, 0);
                $col = self::u16($d, 2);
                if (substr($d, 12, 2) === "\xFF\xFF") {
                    $kind = ord($d[6]);
                    if ($kind === 0) $pendingFormula = [$row, $col];                                // text follows in STRING
                    elseif ($kind === 1) $put($row, $col, ord($d[8]) ? 1 : 0);
                } else {
                    $put($row, $col, self::num(unpack('e', substr($d, 6, 8))[1]));
                }
            } elseif ($t === 0x0207 && $pendingFormula !== null) {                                 // STRING (formula text)
                $put($pendingFormula[0], $pendingFormula[1], self::shortString($d, 0));
                $pendingFormula = null;
            }
        }
        ksort($cells);
        return $cells;
    }

    private static function num(float $v)
    {
        return (floor($v) === $v && abs($v) < 1e15) ? (int)$v : $v;
    }

    /** RK number: a 30-bit integer or the top 30 bits of a double, optionally ÷100. PURE. */
    public static function rk(int $rk)
    {
        if ($rk & 0x02) {
            $v = $rk >> 2;
            if ($v & 0x20000000) $v -= 0x40000000;                                                  // sign of the 30-bit integer
            $v = (float)$v;
        } else {
            $v = unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];
        }
        if ($rk & 0x01) $v /= 100;
        return self::num((float)$v);
    }

    /** A string with a 16-bit length (LABEL / STRING records). */
    private static function shortString(string $d, int $o): string
    {
        $cch = self::u16($d, $o);
        $flags = ord($d[$o + 2] ?? "\0");
        $o += 3;
        if ($flags & 0x08) $o += 2;
        if ($flags & 0x04) $o += 4;
        $raw = ($flags & 0x01) ? substr($d, $o, 2 * $cch) : substr($d, $o, $cch);
        return ($flags & 0x01) ? (string)@iconv('UTF-16LE', 'UTF-8', $raw) : (string)@iconv('ISO-8859-1', 'UTF-8', $raw);
    }

    /**
     * The shared string table, across its CONTINUE records. A string's characters may break at a
     * record boundary; the continuation starts with a fresh flags byte (8- or 16-bit chars). PURE.
     * @param string[] $parts SST data then each CONTINUE's data
     */
    public static function sst(array $parts): array
    {
        $p = 0;
        $buf = $parts[0];
        $o = 8;                                                                                     // total + unique counts
        $unique = self::u32($buf, 4);
        $next = static function () use (&$p, &$buf, &$o, $parts): bool {
            if ($p + 1 >= count($parts)) return false;
            $p++;
            $buf = $parts[$p];
            $o = 0;
            return true;
        };
        $out = [];
        for ($n = 0; $n < $unique; $n++) {
            if ($o >= strlen($buf) && !$next()) break;
            if ($o + 3 > strlen($buf)) { if (!$next()) break; }
            $cch = self::u16($buf, $o);
            $flags = ord($buf[$o + 2]);
            $o += 3;
            $runs = 0;
            $ext = 0;
            if ($flags & 0x08) { $runs = self::u16($buf, $o); $o += 2; }
            if ($flags & 0x04) { $ext = self::u32($buf, $o); $o += 4; }
            $wide = (bool)($flags & 0x01);
            $s = '';
            $left = $cch;
            while ($left > 0) {
                $avail = strlen($buf) - $o;
                if ($avail <= 0) {
                    if (!$next()) break 2;
                    $wide = (bool)(ord($buf[0]) & 0x01);                                            // continuation's own flags byte
                    $o = 1;
                    continue;
                }
                $take = min($left, $wide ? intdiv($avail, 2) : $avail);
                $raw = substr($buf, $o, $wide ? 2 * $take : $take);
                $s .= $wide ? (string)@iconv('UTF-16LE', 'UTF-8', $raw) : (string)@iconv('ISO-8859-1', 'UTF-8', $raw);
                $o += $wide ? 2 * $take : $take;
                $left -= $take;
            }
            // Formatting runs and extended data: skipped, possibly across a boundary.
            $skip = 4 * $runs + $ext;
            while ($skip > 0) {
                $avail = strlen($buf) - $o;
                if ($avail <= 0) { if (!$next()) break; continue; }
                $t = min($skip, $avail);
                $o += $t;
                $skip -= $t;
            }
            $out[] = $s;
        }
        return $out;
    }
}
