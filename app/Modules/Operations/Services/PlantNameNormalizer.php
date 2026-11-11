<?php
/**
 * PlantNameNormalizer — one plant, many spellings (owner, 2026-10-12). PURE, no database.
 *
 * SHARED knowledge (Ittai): suppliers write the same plant differently —
 *   "Armeria Dreamaria® 'Vivid Dreams'", "Armeria 'Vivid Dreams' DREAMARIA", "Armeria x Vivid Dreams"
 * This splits a botanical name into genus / species / 'cultivar' / series (trade name), pulls
 * common names in parentheses out as aliases and plant-patent style tags ((PW), (FE)) out as tags,
 * and builds the match keys PlantCatalogService uses. Sizes too: "No.1 Pot", "#1", "1 gal",
 * "1G" → no1.
 *
 * The supplier's raw name and size are never changed — they are stored as given next to the
 * catalogue link; these keys are only for matching.
 */
declare(strict_types=1);

class PlantNameNormalizer
{
    /** Quote characters suppliers use around a cultivar → one straight quote. */
    private const QUOTES = ["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{2032}", '`', "\u{00B4}",
                            "\u{201C}", "\u{201D}", "\u{201E}", "\u{2033}", '"'];

    /** Trade-mark signs: they mark a trade name (series), never part of the key. */
    private const MARKS = ["\u{00AE}", "\u{2122}", "\u{2120}", "\u{00A9}"];

    /** Growing forms: a standard (tree-form) Hydrangea is a different product from the shrub. */
    private const FORMS = ['std' => 'std', 'std.' => 'std', 'standard' => 'std', 'multi-stem' => 'multistem',
                           'multistem' => 'multistem', 'espalier' => 'espalier', 'topiary' => 'topiary'];

    /**
     * Split a supplier's plant name.
     * @return array{genus: string, species: string, cultivar: string, series: string, hybrid: bool,
     *               aliases: string[], tags: string[], key: string, cultivar_key: ?string,
     *               genus_cultivar_key: ?string, display: string}
     */
    public static function parse(string $raw): array
    {
        $s = str_replace(self::QUOTES, "'", $raw);
        $s = str_replace("\u{00D7}", ' x ', $s);                       // × hybrid sign
        $s = (string)preg_replace('/\((R|TM|C)\)/i', "\u{00AE}", $s);   // (R) / (TM) written out

        // Parentheses: (PW) (FE) (BE) are tags; anything else is a common name → alias.
        $aliases = [];
        $tags = [];
        $s = (string)preg_replace_callback('/\(([^()]*)\)/u', function ($m) use (&$aliases, &$tags) {
            $in = trim(str_replace(self::MARKS, '', $m[1]));
            if ($in === '') return ' ';
            if (preg_match('/^[A-Z]{1,4}$/', $in)) { $tags[] = $in; return ' '; }
            $a = self::fold($in);
            if ($a !== '') $aliases[] = $a;
            return ' ';
        }, $s);

        $marked = false;
        foreach (self::MARKS as $mk) if (strpos($s, $mk) !== false) $marked = true;
        $s = str_replace(self::MARKS, ' ', $s);
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));

        // Cultivar: between the first and the last straight quote ('Scarlet O'Hara' keeps its apostrophe).
        $cultivar = '';
        $prefix = $s;
        $suffix = '';
        $a = strpos($s, "'");
        $b = strrpos($s, "'");
        if ($a !== false && $b !== false && $b > $a) {
            $inner = trim(substr($s, $a + 1, $b - $a - 1));
            // A lone possessive ("Hick's Yew") is not a quoted cultivar: needs the opening quote at a word start.
            if ($inner !== '' && ($a === 0 || $s[$a - 1] === ' ')) {
                $cultivar = $inner;
                $prefix = trim(substr($s, 0, $a));
                $suffix = trim(substr($s, $b + 1));
            }
        }

        $tokens = $prefix === '' ? [] : explode(' ', $prefix);
        $genus = $tokens ? array_shift($tokens) : '';
        $species = [];
        $series = [];
        $hybrid = false;
        $form = '';
        foreach ($tags as $tg) if (isset(self::FORMS[strtolower($tg)])) $form = self::FORMS[strtolower($tg)];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $lt = strtolower(rtrim($t, ','));
            if ($lt === 'x') { $hybrid = true; continue; }
            if (isset(self::FORMS[$lt])) { $form = self::FORMS[$lt]; continue; }
            if (in_array($lt, ['var.', 'var', 'ssp.', 'subsp.', 'ssp', 'subsp', 'f.', 'forma'], true)) {
                $rank = in_array($lt, ['var.', 'var'], true) ? 'var' : (in_array($lt, ['f.', 'forma'], true) ? 'f' : 'subsp');
                if (isset($tokens[$i + 1])) { $species[] = $rank . '. ' . $tokens[$i + 1]; $i++; }
                continue;
            }
            if (preg_match('/^\p{Ll}/u', $t)) {
                if ($series) { $series[] = $t; } else { $species[] = $t; }
            } else {
                $series[] = $t;
            }
        }
        foreach ($suffix === '' ? [] : explode(' ', $suffix) as $t) {
            if (strtolower($t) === 'x') continue;
            if (isset(self::FORMS[strtolower($t)])) { $form = self::FORMS[strtolower($t)]; continue; }
            $series[] = $t;
        }
        // No quotes and no ®/™: the capitalised words are the cultivar written without quotes.
        if ($cultivar === '' && $series && !$marked) {
            $cultivar = implode(' ', $series);
            $series = [];
        }

        $g = self::fold($genus);
        $sp = self::fold(implode(' ', $species));
        $cv = self::fold($cultivar);
        $se = self::fold(implode(' ', $series));
        $display = trim(ucfirst(strtolower($genus)) . ($hybrid ? ' x' : '') . ($species ? ' ' . implode(' ', $species) : '')
                 . ($series ? ' ' . implode(' ', $series) : '') . ($cultivar !== '' ? " '" . $cultivar . "'" : '')
                 . ($form !== '' ? ' ' . strtoupper($form) : ''));
        $f = $form !== '' ? '|' . $form : '';

        return [
            'genus' => $g, 'species' => $sp, 'cultivar' => $cv, 'series' => $se, 'hybrid' => $hybrid, 'form' => $form,
            'aliases' => array_values(array_unique($aliases)), 'tags' => array_values(array_unique($tags)),
            'key' => $g . '|' . $sp . '|' . $cv . '|' . $se . $f,
            'cultivar_key' => ($g !== '' && $cv !== '') ? $g . '|' . $sp . '|' . $cv . $f : null,
            'genus_cultivar_key' => ($g !== '' && $cv !== '') ? $g . '||' . $cv . $f : null,
            'display' => $display,
        ];
    }

    /**
     * Fold text for a key: case-fold, accents off, apostrophes and punctuation out, hyphens → space,
     * spaces collapsed. "Scarlet O'Hara" → "scarlet ohara". PURE.
     */
    public static function fold(string $s): string
    {
        $s = str_replace(array_merge(self::QUOTES, ["'"], self::MARKS), '', $s);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
        $s = strtolower($s);
        $s = str_replace(["'", '"', '`', '^', '~'], '', $s);           // translit leftovers
        $s = (string)preg_replace('/[^a-z0-9.]+/', ' ', $s);
        $s = (string)preg_replace('/(?<![0-9])\.|\.(?![0-9])/', ' ', $s); // keep decimal points only
        return trim((string)preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Normalise a container size. "No.1 Pot", "#1", "1 gal", "1G" → no1; "11cm Round" → 11cm;
     * "Quart Pot" → qt; "No.5 Planter" → no5-planter; "5-6ft No.5 Pot" → no5 + height 5-6ft.
     * @return array{code: string, height: ?string}
     */
    public static function size(string $raw): array
    {
        $s = str_replace(["\u{2018}", "\u{2019}", "\u{2032}", '`', "\u{00B4}"], "'", trim($raw));
        $s = strtolower(str_replace(["\u{201C}", "\u{201D}", "\u{2033}"], '"', $s));
        $height = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:-|to)\s*(\d+(?:\.\d+)?)\s*(ft|feet|\'|cm|m)\b/', $s, $m)) {
            $u = in_array($m[3], ['ft', 'feet', "'"], true) ? 'ft' : $m[3];
            $height = $m[1] . '-' . $m[2] . $u;
            $s = str_replace($m[0], ' ', $s);
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(ft|feet|\')\s*\+/', $s, $m)) {
            $height = $m[1] . 'ft+';
            $s = str_replace($m[0], ' ', $s);
        }
        $planter = strpos($s, 'planter') !== false;
        $code = null;
        if (preg_match('/(?:\bno\.?|#)\s*(\d+)/', $s, $m)) {
            $code = 'no' . (int)$m[1];
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:gal(?:lon)?s?|g)\b/', $s, $m)) {
            $code = 'no' . (float)$m[1];
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*cm\b/', $s, $m)) {
            $code = ((float)$m[1]) . 'cm';
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:inch(?:es)?|in\b|")/', $s, $m)) {
            $code = ((float)$m[1]) . 'in';
        } elseif (preg_match('/\b(quart|qt)\b/', $s)) {
            $code = 'qt';
        } elseif (preg_match('/\bplugs?\b/', $s)) {
            $code = 'plug';
        } elseif (preg_match('/\bliners?\b/', $s)) {
            $code = 'liner';
        } elseif (preg_match('/\b(b\s*&\s*b|balled)\b/', $s)) {
            $code = 'bb';
        } elseif (preg_match('/\bbare\s*root\b|\bbr\b/', $s)) {
            $code = 'bareroot';
        }
        if ($code === null) {
            $code = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
            return ['code' => $code === '' ? 'unknown' : substr($code, 0, 30), 'height' => $height];
        }
        return ['code' => $code . ($planter ? '-planter' : ''), 'height' => $height];
    }

    /** Parsed parts back from a stored key (genus|species|cultivar|series[|form]). PURE. */
    public static function fromKey(string $key): array
    {
        $p = array_pad(explode('|', $key), 5, '');
        return ['genus' => $p[0], 'species' => $p[1], 'cultivar' => $p[2], 'series' => $p[3], 'form' => $p[4]];
    }

    /** Same series, or written differently ("Ka-Pow" / "KaPow"), or left out on one side. PURE. */
    public static function seriesCompatible(array $a, array $b): bool
    {
        $x = str_replace(' ', '', $a['series']);
        $y = str_replace(' ', '', $b['series']);
        return $x === '' || $y === '' || $x === $y;
    }

    /**
     * How alike two parsed names are, 0..1, for SUGGESTIONS only (never an automatic link).
     * Built from what went wrong on a real list: colour variants ('Sunseekers Red' / 'Orange'),
     * sister cultivars ('Little Lime' / 'Little Lime Punch'), another series with the same
     * cultivar word ('Milly Rock 'Rose'' / 'New Vintage 'Rose'') and a tree form vs the shrub are
     * all DIFFERENT plants. What is alike: the same cultivar with species or series left out, or a
     * spelling slip in one word ('Frances' / 'Francis').
     */
    public static function similarity(array $a, array $b): float
    {
        if ($a['genus'] === '' || $a['genus'] !== $b['genus']) return 0.0;
        if (($a['form'] ?? '') !== ($b['form'] ?? '')) return 0.0;
        if (!self::seriesCompatible($a, $b)) return 0.0;
        $speciesClash = $a['species'] !== '' && $b['species'] !== '' && $a['species'] !== $b['species'];
        $ca = $a['cultivar'];
        $cb = $b['cultivar'];
        if (($ca === '') !== ($cb === '')) return 0.0;          // a species and one of its cultivars
        if ($ca === '') {
            if ($a['species'] === '' || $b['species'] === '') return 0.0;
            return self::typoLike($a['species'], $b['species']) ? 0.85 : 0.0;
        }
        if ($speciesClash) return 0.0;
        if ($ca === $cb) return 0.9;
        return self::typoLike($ca, $cb) ? 0.85 : 0.0;
    }

    /** Same words, at most a small slip in some of them; numbers must match exactly. PURE. */
    public static function typoLike(string $x, string $y): bool
    {
        $a = explode(' ', $x);
        $b = explode(' ', $y);
        if (count($a) !== count($b) || $x === $y) return $x === $y;
        foreach ($a as $i => $w) {
            $v = $b[$i];
            if ($w === $v) continue;
            if (preg_match('/\d/', $w . $v)) return false;
            $len = max(strlen($w), strlen($v));
            if ($len < 5 || levenshtein($w, $v) > max(1, intdiv($len, 4))) return false;
        }
        return true;
    }

}
