<?php
/**
 * LOCAL TEST ONLY — extract the STRUCTURE (no rows) from a production backup.
 *
 *   php scripts/local/extract-schema.php <backup.sql.gz> <out.sql>
 *
 * The backup (scripts/prod-backup.sh) holds real customer data. This streams it and keeps
 * only SET / DROP TABLE / CREATE TABLE / DROP VIEW / CREATE VIEW statements. Every INSERT is
 * discarded unread-into-memory; no row ever reaches the output file or the local database.
 * AUTO_INCREMENT=N counters are stripped too (they leak row counts).
 *
 * It is a real tokenizer (single/double-quoted strings with backslash escapes, backticks,
 * comments), so a ';' inside a string value can never split a statement.
 */
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "usage: php extract-schema.php <backup.sql.gz> <out.sql>\n");
    exit(2);
}
[$_, $in, $out] = $argv;

$gz = gzopen($in, 'rb');
if (!$gz) { fwrite(STDERR, "cannot open $in\n"); exit(1); }
$fo = fopen($out, 'wb');
if (!$fo) { fwrite(STDERR, "cannot write $out\n"); exit(1); }

$tables = 0; $views = 0; $dropped = 0;
$stmt = '';          // current statement text (only accumulated while it might be kept)
$keep = null;        // null = undecided, true = keep, false = discard
$head = '';          // first bytes of the statement, to decide keep/discard
$quote = '';         // '' | "'" | '"' | '`'
$escape = false;
$lineComment = false;

$emit = function (string $s) use ($fo, &$tables, &$views): void {
    $t = ltrim($s);
    if (preg_match('/^CREATE\s+TABLE/i', $t)) {
        $tables++;
        $s = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $s);
    } elseif (preg_match('/^CREATE\s+(ALGORITHM|DEFINER|SQL\s+SECURITY|VIEW|OR\s+REPLACE)/i', $t) && stripos($t, ' VIEW ') !== false) {
        $views++;
    }
    fwrite($fo, rtrim($s) . ";\n");
};

$decide = function (string $h): ?bool {
    $h = ltrim($h);
    if (strlen($h) < 12) return null;
    if (preg_match('/^INSERT\b/i', $h)) return false;
    if (preg_match('/^(SET\b|DROP\s+TABLE|DROP\s+VIEW|CREATE\s)/i', $h)) return true;
    return false;   // anything unknown is discarded (fail closed)
};

while (!gzeof($gz)) {
    $buf = gzread($gz, 1 << 20);
    $len = strlen($buf);
    for ($i = 0; $i < $len; $i++) {
        $c = $buf[$i];

        if ($lineComment) {
            if ($c === "\n") $lineComment = false;
            continue;
        }

        if ($quote !== '') {
            if ($keep === true) $stmt .= $c; elseif ($keep === null) $head .= $c;
            if ($escape) { $escape = false; continue; }
            if ($c === '\\' && $quote !== '`') { $escape = true; continue; }
            if ($c === $quote) $quote = '';
            continue;
        }

        // Outside quotes. Fast-skip runs of discarded statement text.
        if ($keep === false) {
            $j = strcspn($buf, "'\"`;", $i);
            $i += $j;
            if ($i >= $len) break;
            $c = $buf[$i];
            if ($c === ';') { $keep = null; $head = ''; $stmt = ''; $dropped++; continue; }
            $quote = $c;
            continue;
        }

        if ($keep === null && trim($head) === '' && $c === '-' && ($buf[$i + 1] ?? '') === '-') {
            $lineComment = true;
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') $quote = $c;

        if ($c === ';') {
            if ($keep === true) $emit($stmt);
            elseif ($keep === null && trim($head) !== '' && $decide($head . str_repeat(' ', 12)) === true) $emit($head);
            $keep = null; $head = ''; $stmt = '';
            continue;
        }

        if ($keep === true) { $stmt .= $c; continue; }
        $head .= $c;
        $d = $decide($head);
        if ($d === true) { $keep = true; $stmt = $head; $head = ''; }
        elseif ($d === false) { $keep = false; $head = ''; }
    }
}
gzclose($gz);
fclose($fo);

fwrite(STDOUT, json_encode(['tables' => $tables, 'views' => $views, 'discarded_statements' => $dropped]) . "\n");
