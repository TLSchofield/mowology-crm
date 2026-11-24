<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Ittai colours meet WCAG AAA (owner, 2026-10-19: "the design master needs to ensure buttons etc are
 * AAA standard"). Every text/background pairing the screens use, in light, dark and field mode, from the
 * single source (docs/design/ittai-v1.2/tokens.json + the generated tenant colours). 7:1 for text;
 * focus rings 4.5:1. scripts/ittai-contrast.py runs the same check on the iPhone's ItTokens.swift.
 */
class IttaiContrastAaaTest extends TestCase
{
    private const PAIRS = [
        ['text', 'page', 7.0], ['text', 'surface', 7.0], ['text', 'sunk', 7.0], ['text', 'selected', 7.0], ['text', 'tenant-soft', 7.0],
        ['meta', 'page', 7.0], ['meta', 'surface', 7.0], ['meta', 'sunk', 7.0],
        ['on-tenant', 'tenant', 7.0], ['tenant', 'surface', 7.0], ['tenant', 'page', 7.0], ['tenant', 'sunk', 7.0],
        ['status-attention', 'surface', 7.0], ['status-blocked', 'surface', 7.0], ['status-progress', 'surface', 7.0], ['status-complete', 'surface', 7.0],
        ['focus', 'surface', 4.5],
    ];

    private static function palettes(): array
    {
        $dir = __DIR__ . '/../../../docs/design/ittai-v1.2/';
        $t = json_decode((string)file_get_contents($dir . 'tokens.json'), true);
        $out = ['light' => [], 'dark' => [], 'field' => []];
        foreach ((array)($t['color']['tokens'] ?? []) as $c) {
            if (!is_array($c) || !isset($c['value'])) continue;
            $vals = is_array($c['value']) ? $c['value'] : array_fill_keys(array_keys($out), $c['value']);   // one value for every mode
            foreach ($vals as $mode => $v) {
                if (isset($out[$mode]) && is_string($v)) $out[$mode][$c['name']] = $v;
            }
        }
        $g = json_decode((string)file_get_contents($dir . 'tenants.generated.json'), true);
        foreach ($out as $mode => $_) foreach ((array)($g['mowology'][$mode] ?? []) as $k => $v) $out[$mode][$k] = $v;
        // "{status-progress}" style references → the colour they name.
        foreach ($out as $mode => $vals) {
            foreach ($vals as $k => $v) {
                for ($i = 0; $i < 5 && preg_match('/^\{([\w-]+)\}$/', trim((string)$v), $m); $i++) $v = $vals[$m[1]] ?? '';
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$v)) $out[$mode][$k] = $v; else unset($out[$mode][$k]);
            }
        }
        return $out;
    }

    private static function lum(string $hex): float
    {
        $f = static function (int $c): float { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    private static function ratio(string $a, string $b): float
    {
        $x = self::lum($a); $y = self::lum($b);
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    public function test_every_text_pairing_meets_aaa_in_every_mode(): void
    {
        $fails = [];
        foreach (self::palettes() as $mode => $p) {
            foreach (self::PAIRS as [$fg, $bg, $need]) {
                if (!isset($p[$fg], $p[$bg])) { $fails[] = "$mode: missing $fg or $bg"; continue; }
                $r = self::ratio($p[$fg], $p[$bg]);
                if ($r < $need) $fails[] = sprintf('%s: %s on %s is %.2f:1, needs %.1f:1', $mode, $fg, $bg, $r, $need);
            }
        }
        $this->assertSame([], $fails, "Ittai colours below AAA:\n" . implode("\n", $fails));
    }
}
