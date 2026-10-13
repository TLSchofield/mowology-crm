<?php
/**
 * EquipmentPlateParser — reads the OCR text of a machine's model/serial plate. PURE (no DB, no network).
 *
 * Plates print the label and the value either on one line ("MODEL HRC216K3HXCH") or the value on
 * the next line (Honda: "MODEL" / "HRC216K3HXCH" / "SERIAL NO." / "MAKA-2058647"), sometimes in
 * two languages ("MODEL / MODÈLE"). Labels read:
 *   model   MODEL, MODEL NO., MOD., MDL, M/N, TYPE (only when nothing better)
 *   serial  SERIAL, SERIAL NO., SERIAL NUMBER, SER., SER. NO., S/N, SN, N° DE SÉRIE
 * Make: a known maker anywhere on the plate ("American Honda Motor Company, Inc." → Honda). An
 * engine maker (Briggs & Stratton, Kohler, Kawasaki engines) printed beside a machine maker is
 * kept as engine_make, not as the make.
 * Class and power are a GUESS from the maker's model prefixes (Honda HRC… = mower, Stihl FS… =
 * trimmer, EGO = battery) or plate words; the confirm form always shows them for Tim to change.
 *
 * Used by EquipmentService::readPlate() (the Add equipment flow, web + iPhone) and as the
 * model/serial fallback inside LabelReaderService::read(). No namespace: require_once and call statically.
 */
class EquipmentPlateParser
{
    /** maker keyword (lower case, as it appears) => display name */
    public const MAKES = [
        'briggs & stratton' => 'Briggs & Stratton', 'briggs and stratton' => 'Briggs & Stratton', 'briggs' => 'Briggs & Stratton',
        'husqvarna' => 'Husqvarna', 'shindaiwa' => 'Shindaiwa', 'redmax' => 'RedMax', 'red max' => 'RedMax',
        'milwaukee' => 'Milwaukee', 'dewalt' => 'DeWalt', 'makita' => 'Makita', 'kawasaki' => 'Kawasaki',
        'exmark' => 'Exmark', 'kohler' => 'Kohler', 'stihl' => 'Stihl', 'honda' => 'Honda', 'echo' => 'Echo',
        'toro' => 'Toro', 'ego' => 'EGO', 'scag' => 'Scag', 'john deere' => 'John Deere', 'ryobi' => 'Ryobi',
        'greenworks' => 'Greenworks', 'kubota' => 'Kubota', 'yamaha' => 'Yamaha', 'billy goat' => 'Billy Goat',
        'little wonder' => 'Little Wonder', 'tanaka' => 'Tanaka', 'maruyama' => 'Maruyama', 'oregon' => 'Oregon',
        'gravely' => 'Gravely', 'ariens' => 'Ariens', 'cub cadet' => 'Cub Cadet', 'snapper' => 'Snapper', 'bluebird' => 'Bluebird',
        'ryan' => 'Ryan', 'classen' => 'Classen', 'big tex' => 'Big Tex', 'ford' => 'Ford', 'dodge' => 'Dodge', 'ram' => 'Ram',
    ];
    /** Makers that usually supply the ENGINE on someone else's machine. */
    public const ENGINE_MAKES = ['Briggs & Stratton', 'Kohler', 'Kawasaki'];
    public const BATTERY_MAKES = ['EGO', 'Makita', 'DeWalt', 'Milwaukee', 'Greenworks', 'Ryobi'];
    public const GAS_MAKES = ['Honda', 'Briggs & Stratton', 'Kohler', 'Kawasaki', 'Exmark', 'Shindaiwa', 'RedMax', 'Echo', 'Stihl',
                              'Husqvarna', 'Toro', 'Scag', 'Tanaka', 'Maruyama', 'Billy Goat', 'Little Wonder', 'Gravely', 'Ryan', 'Classen'];

    /**
     * Model prefixes per maker → [class, power or null]. Longest prefix first within a maker.
     * Classes match EquipmentService::CLASSES.
     */
    public const MODEL_PREFIXES = [
        'Honda'     => ['HRC' => ['mower', 'gas'], 'HRX' => ['mower', 'gas'], 'HRN' => ['mower', 'gas'], 'HRS' => ['mower', 'gas'],
                        'HRR' => ['mower', 'gas'], 'HRZ' => ['mower', 'gas'], 'HRB' => ['mower', 'gas'], 'HHT' => ['trimmer', 'gas'],
                        'HHH' => ['hedge_trimmer', 'gas'], 'HHB' => ['blower', 'gas'], 'GCV' => ['other', 'gas'], 'GX' => ['other', 'gas'],
                        'EU' => ['other', 'gas'], 'EB' => ['other', 'gas'], 'WX' => ['other', 'gas'], 'FG' => ['other', 'gas']],
        'Stihl'     => ['FSA' => ['trimmer', 'battery'], 'BGA' => ['blower', 'battery'], 'HSA' => ['hedge_trimmer', 'battery'],
                        'HLA' => ['hedge_trimmer', 'battery'], 'MSA' => ['saw', 'battery'], 'RMA' => ['mower', 'battery'],
                        'FS' => ['trimmer', 'gas'], 'FC' => ['trimmer', 'gas'], 'KM' => ['trimmer', 'gas'], 'BG' => ['blower', 'gas'],
                        'BR' => ['blower', 'gas'], 'HS' => ['hedge_trimmer', 'gas'], 'HL' => ['hedge_trimmer', 'gas'],
                        'MS' => ['saw', 'gas'], 'RM' => ['mower', 'gas']],
        'Echo'      => ['SRM' => ['trimmer', 'gas'], 'PAS' => ['trimmer', 'gas'], 'GT' => ['trimmer', 'gas'], 'PE' => ['trimmer', 'gas'],
                        'PB' => ['blower', 'gas'], 'HCA' => ['hedge_trimmer', 'gas'], 'HC' => ['hedge_trimmer', 'gas'], 'CS' => ['saw', 'gas'],
                        'DSRM' => ['trimmer', 'battery'], 'DPB' => ['blower', 'battery'], 'DHC' => ['hedge_trimmer', 'battery'], 'DCS' => ['saw', 'battery']],
        'EGO'       => ['LMX' => ['mower', 'battery'], 'LM' => ['mower', 'battery'], 'STX' => ['trimmer', 'battery'], 'STA' => ['trimmer', 'battery'],
                        'ST' => ['trimmer', 'battery'], 'LBX' => ['blower', 'battery'], 'LBP' => ['blower', 'battery'], 'LB' => ['blower', 'battery'],
                        'HTX' => ['hedge_trimmer', 'battery'], 'HT' => ['hedge_trimmer', 'battery'], 'CSX' => ['saw', 'battery'],
                        'CS' => ['saw', 'battery'], 'BA' => ['battery_pack', 'battery'], 'CH' => ['other', 'battery']],
        'Shindaiwa' => ['EB' => ['blower', 'gas'], 'HT' => ['hedge_trimmer', 'gas'], 'DH' => ['hedge_trimmer', 'gas'], 'T' => ['trimmer', 'gas']],
        'RedMax'    => ['BCZ' => ['trimmer', 'gas'], 'EBZ' => ['blower', 'gas'], 'HB' => ['blower', 'gas'], 'HTZ' => ['hedge_trimmer', 'gas'],
                        'CHT' => ['hedge_trimmer', 'gas'], 'GZ' => ['saw', 'gas']],
        'Makita'    => ['DUR' => ['trimmer', 'battery'], 'XRU' => ['trimmer', 'battery'], 'DUB' => ['blower', 'battery'], 'XBU' => ['blower', 'battery'],
                        'DUH' => ['hedge_trimmer', 'battery'], 'XHU' => ['hedge_trimmer', 'battery'], 'DUC' => ['saw', 'battery'],
                        'XCU' => ['saw', 'battery'], 'DLM' => ['mower', 'battery'], 'XML' => ['mower', 'battery'], 'BL' => ['battery_pack', 'battery']],
        'DeWalt'    => ['DCST' => ['trimmer', 'battery'], 'DCBL' => ['blower', 'battery'], 'DCHT' => ['hedge_trimmer', 'battery'],
                        'DCCS' => ['saw', 'battery'], 'DCMW' => ['mower', 'battery'], 'DCB' => ['battery_pack', 'battery']],
        'Exmark'    => ['LZ' => ['mower', 'gas'], 'RA' => ['mower', 'gas'], 'RX' => ['mower', 'gas'], 'TT' => ['mower', 'gas'],
                        'QS' => ['mower', 'gas'], 'ECX' => ['mower', 'gas'], 'VT' => ['mower', 'gas'], 'ST' => ['mower', 'gas']],
        'Kawasaki'  => ['FS' => ['other', 'gas'], 'FR' => ['other', 'gas'], 'FX' => ['other', 'gas'], 'FJ' => ['other', 'gas'], 'FH' => ['other', 'gas']],
    ];

    /** Plate / title words → class (checked before model prefixes). */
    public const CLASS_WORDS = [
        'hedge_trimmer' => '/\b(hedge\s*trimmer|hedge\s*cutter|hedger|hedge)\b/i',
        'mower'         => '/\b(lawn\s*mower|mower|zero[\s-]*turn|walk[\s-]*behind|lawnmower)\b/i',
        'trimmer'       => '/\b(string\s*trimmer|trimmer|brush\s*cutter|brushcutter|edger|weed\s*eater)\b/i',
        'blower'        => '/\b(blower)\b/i',
        'saw'           => '/\b(chain\s*saw|chainsaw|pole\s*saw|cut[\s-]*off\s*saw)\b/i',
        'aerator'       => '/\b(aerator|core\s*aerator|dethatcher|overseeder)\b/i',
        'trailer'       => '/\btrailer\b/i',
        'truck'         => '/\b(truck|pickup|vin|gvwr)\b/i',
    ];

    private const VALUE = '[A-Z0-9][A-Z0-9\-\/.]{1,}';
    private const MODEL_LABEL = '(?:MODELE|MODEL|MOD\.?|MDL\.?|M\s*\/\s*N|MODEL\s*NUMBER)(?:\s*(?:NO\.?|NUMBER|#|N°|NUM\.?))?';
    private const SERIAL_LABEL = '(?:SERIAL\s*(?:NO\.?|NUMBER|#|N°|NUM\.?)?|SER\.?\s*(?:NO\.?|#)?|S\s*\/\s*N|SN|N°\s*DE\s*SERIE|NO\s*DE\s*SERIE)';
    private const TYPE_LABEL = '(?:TYPE|TYP)';

    /**
     * @return array{make: ?string, engine_make: ?string, model: ?string, model_key: ?string, serial: ?string, type: ?string,
     *               model_name: ?string, equipment_class: ?string, power_source: ?string, display_name: ?string,
     *               confidence: float, found: string[]}
     */
    public static function parse(string $text): array
    {
        $lines = self::lines($text);
        $out = ['make' => null, 'engine_make' => null, 'model' => null, 'model_key' => null, 'serial' => null, 'type' => null,
                'model_name' => null, 'equipment_class' => null, 'power_source' => null, 'display_name' => null,
                'confidence' => 0.0, 'found' => []];
        if (!$lines) return $out;
        $all = implode("\n", $lines);

        [$out['make'], $out['engine_make']] = self::makes($all);
        $out['model'] = self::labelled($lines, self::MODEL_LABEL);
        $out['serial'] = self::labelled($lines, self::SERIAL_LABEL);
        $out['type'] = self::labelled($lines, self::TYPE_LABEL);
        if ($out['serial'] !== null && $out['model'] !== null && $out['serial'] === $out['model']) $out['serial'] = null;
        if ($out['model'] !== null) $out['model_key'] = self::modelKey($out['model']);

        // A title line naming the machine ("HRC216 Hydrostatic Commercial") — letters and words, not a label.
        foreach ($lines as $l) {
            if (preg_match('/\b(' . self::MODEL_LABEL . '|' . self::SERIAL_LABEL . '|' . self::TYPE_LABEL . ')\b/i', self::fold($l))) continue;
            if (preg_match('/\b(inc|ltd|corp|company|llc|ag|kg|gmbh|assembled|made in|manufactured|www\.|patent|warning|caution|danger|engine)\b/i', $l)) continue;
            if ($out['engine_make'] !== null && stripos($l, $out['engine_make']) !== false) continue;
            if ($out['model'] !== null && strcasecmp(trim($l), $out['model']) === 0) continue;
            if ($out['serial'] !== null && strcasecmp(trim($l), $out['serial']) === 0) continue;
            if (str_word_count($l) >= 2 && preg_match('/[A-Za-z]{4,}/', $l) && strlen($l) <= 60) { $out['model_name'] = self::tidy($l); break; }
        }

        // Class: plate words first, then the maker's model prefix.
        $prefix = self::prefixGuess($out['make'], $out['model']);
        foreach (self::CLASS_WORDS as $class => $re) {
            if (preg_match($re, $all)) { $out['equipment_class'] = $class; break; }
        }
        if ($out['equipment_class'] === null && $prefix) $out['equipment_class'] = $prefix[0];
        if ($out['equipment_class'] === null && $out['make'] !== null && in_array($out['make'], self::ENGINE_MAKES, true)) $out['equipment_class'] = 'other';
        $out['power_source'] = self::power($all, $out['make'], $prefix);

        $found = [];
        foreach (['make', 'model', 'serial'] as $k) if ($out[$k] !== null) $found[] = $k;
        if ($out['equipment_class'] !== null) $found[] = 'class';
        $out['found'] = $found;
        $out['confidence'] = round((($out['model'] ? 0.4 : 0) + ($out['serial'] ? 0.3 : 0) + ($out['make'] ? 0.2 : 0) + ($out['equipment_class'] ? 0.1 : 0)), 2);
        $out['display_name'] = self::displayName($out);
        return $out;
    }

    /** "FS 91 R", "fs-91r" → "FS91R" — the catalogue's part-number key (same rule as PartNumberMatcher::mpn). */
    public static function modelKey(?string $model): ?string
    {
        $v = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '', (string)$model));
        return $v === '' ? null : substr($v, 0, 80);
    }

    /** A short name for the register: "Honda HRC216 mower", from what the plate gave. */
    public static function displayName(array $p): ?string
    {
        $labels = ['mower' => 'mower', 'trimmer' => 'trimmer', 'blower' => 'blower', 'hedge_trimmer' => 'hedge trimmer', 'saw' => 'saw',
                   'aerator' => 'aerator', 'trailer' => 'trailer', 'truck' => 'truck', 'battery_pack' => 'battery'];
        $model = $p['model'] ?? null;
        $name = $p['model_name'] ?? null;
        // The family on the machine ("HRC216") reads better than the full plate model ("HRC216K3HXCH").
        $short = $name && preg_match('/^([A-Z]{1,5}[- ]?\d{2,4}[A-Z]?)\b/i', $name, $m) ? strtoupper($m[1]) : $model;
        $what = $labels[$p['equipment_class'] ?? ''] ?? null;
        $s = trim(implode(' ', array_filter([$p['make'] ?? null, $short, $what])));
        return $s !== '' ? mb_substr($s, 0, 120) : null;
    }

    // ── internals ───────────────────────────────────────────────────────────

    private static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) as $l) {
            $l = trim((string)preg_replace('/\s+/u', ' ', $l));
            // Bilingual plates: "MODEL / MODÈLE", "SERIAL NO. / NO DE SÉRIE" — keep the English half.
            $l = trim((string)preg_replace('/\s*\/\s*(MOD[EÈ]LE|N[O°]\.?\s*DE\s*S[EÉ]RIE|S[EÉ]RIE)\b\.?/iu', '', $l));
            if ($l !== '') $out[] = $l;
        }
        return $out;
    }

    /** Upper case, accents off — labels compare on this. */
    private static function fold(string $s): string
    {
        $s = strtr($s, ['È' => 'E', 'É' => 'E', 'è' => 'E', 'é' => 'E', 'Ê' => 'E', 'ê' => 'E']);
        return strtoupper($s);
    }

    /**
     * The value printed after a label on the same line, else on the next line that is not itself a label.
     * A value must hold a digit (so "NO." and "NUMBER" are never values).
     */
    private static function labelled(array $lines, string $label): ?string
    {
        $re = '/(?<![A-Z0-9])' . $label . '(?![A-Z])\s*[:.#]?\s*(.*)$/u';
        $anyLabel = '/^(?:' . self::MODEL_LABEL . '|' . self::SERIAL_LABEL . '|' . self::TYPE_LABEL . ')\b/u';
        foreach ($lines as $i => $l) {
            $f = self::fold($l);
            if (!preg_match($re, $f, $m, PREG_OFFSET_CAPTURE)) continue;
            // "Mod./Type: FS 91 R" — a second label word after a slash is part of the label.
            $rest = trim((string)preg_replace('/^[\/\s]*(?:TYPE|TYP|NO\.?)(?![A-Z])\s*[:.#]?/', '', trim($m[1][0])));
            $v = self::firstValue($rest);
            if ($v !== null) return $v;
            if ($rest !== '' && !preg_match($anyLabel, $rest) && !preg_match('/^[:.#\s]*$/', $rest)) continue;
            for ($j = $i + 1; $j <= min($i + 2, count($lines) - 1); $j++) {
                $next = self::fold($lines[$j]);
                if (preg_match($anyLabel, $next)) break;
                $v = self::firstValue($next);
                if ($v !== null) return $v;
            }
        }
        return null;
    }

    /** The first value-looking token at the start of a string; Stihl-style "FS 91 R" kept together. */
    private static function firstValue(string $s): ?string
    {
        $s = ltrim($s, " :.#");
        if ($s === '') return null;
        // Short letter prefix + number + optional letter suffix with spaces: FS 91 R, BG 86, MS 271.
        // Serial numbers printed in digit groups: "2203 1234 5678".
        if (preg_match('/^(\d{3,6}(?: \d{3,6}){1,3})(?=\s|$)/', $s, $m)) return $m[1];
        if (preg_match('/^([A-Z]{1,4}) (\d{2,4})(?: ([A-Z]{1,3}))?(?=\s|$)/', $s, $m)) {
            return trim($m[1] . ' ' . $m[2] . (isset($m[3]) ? ' ' . $m[3] : ''));
        }
        if (!preg_match('/^(' . self::VALUE . ')/', $s, $m)) return null;
        $v = rtrim($m[1], '.-/');
        if (!preg_match('/\d/', $v) || strlen($v) < 3) return null;
        if (preg_match('/^(NO|NUMBER|MODEL|SERIAL|TYPE)$/', $v)) return null;
        return substr($v, 0, 60);
    }

    /** [make, engine_make] — machine makers win over engine makers. */
    private static function makes(string $all): array
    {
        $lower = mb_strtolower($all);
        $seen = [];
        foreach (self::MAKES as $kw => $name) {
            if (isset($seen[$name])) continue;
            if (preg_match('/(?<![a-z])' . preg_quote($kw, '/') . '(?![a-z])/u', $lower, $m, PREG_OFFSET_CAPTURE)) $seen[$name] = $m[0][1];
        }
        // "Ram" / "Ford" / "Dodge" only count on a vehicle plate.
        foreach (['Ram', 'Ford', 'Dodge'] as $v) if (isset($seen[$v]) && !preg_match('/\b(vin|gvwr|gawr|vehicle|truck)\b/i', $all)) unset($seen[$v]);
        if (!$seen) return [null, null];
        asort($seen);
        $names = array_keys($seen);
        $machine = array_values(array_filter($names, fn($n) => !in_array($n, self::ENGINE_MAKES, true)));
        $engine = array_values(array_filter($names, fn($n) => in_array($n, self::ENGINE_MAKES, true)));
        if ($machine) return [$machine[0], $engine[0] ?? null];
        return [$engine[0], null];
    }

    /** [class, power|null] from the maker's model prefix table, or null. */
    private static function prefixGuess(?string $make, ?string $model): ?array
    {
        if ($make === null || $model === null || !isset(self::MODEL_PREFIXES[$make])) return null;
        $key = self::modelKey($model) ?? '';
        $table = self::MODEL_PREFIXES[$make];
        uksort($table, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($table as $p => $guess) {
            if (strncmp($key, (string)$p, strlen((string)$p)) === 0) return $guess;
        }
        return null;
    }

    private static function power(string $all, ?string $make, ?array $prefix): ?string
    {
        if (preg_match('/\bdiesel\b/i', $all)) return 'diesel';
        if (preg_match('/\b(\d{2,3}(?:\.\d)?\s*v(?:olts?|dc| max)?|\d+(?:\.\d+)?\s*ah|lithium|li-ion|battery|cordless)\b/i', $all)) return 'battery';
        if (preg_match('/\b(\d{2,4}\s*cc|cm3|gasoline|petrol|unleaded|2-cycle|4-cycle|2-stroke|4-stroke|engine oil|spark plug)\b/i', $all)) return 'gas';
        if ($prefix && $prefix[1]) return $prefix[1];
        if ($make && in_array($make, self::BATTERY_MAKES, true)) return 'battery';
        if ($make && in_array($make, self::GAS_MAKES, true)) return 'gas';
        if (preg_match('/\b120\s*v\s*ac|corded\b/i', $all)) return 'electric';
        return null;
    }

    private static function tidy(string $s): string
    {
        return trim((string)preg_replace('/\s+/', ' ', trim($s, " \t·•-:")));
    }
}
