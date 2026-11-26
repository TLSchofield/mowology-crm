<?php
declare(strict_types=1);

/**
 * TenantBrand — the logo and colours a business's own documents carry (plan 2026-11-26-tenant-branding-from-website, step 1).
 *
 * Why: invoices and quotes embedded Mowology's logo for EVERY business, and every PDF and email hardcoded Mowology's
 * colours. The settings (business_settings.logo_path / brand_color_primary / brand_color_secondary) were saved and never
 * read by anything outgoing except the customer portal.
 *
 * Rules:
 *  - The original business (Tenant::isDefault()) is returned untouched: useDefaultLogo = true, custom = false, so its
 *    templates keep their own markup byte for byte.
 *  - Any other business gets ITS logo (a file under the web root's /uploads/) or NO logo — never Mowology's.
 *  - A business's colours count only when they are valid #RRGGBB values AND differ from the stock Mowology pair that a
 *    settings page shows before anyone chooses (otherwise a business that never chose would print Mowology green).
 *    Otherwise custom = false and the template keeps its own look.
 *  - A custom primary is darkened until white text on it, and it on white, reach 4.5:1 (WCAG AA). Fuller design
 *    rules are step 2; this is the floor so no business prints unreadable headers.
 *
 * Pure functions plus one small loader. No autoloader in production: require_once. Tested in tests/Unit/Settings/TenantBrandTest.php.
 */
final class TenantBrand
{
    /** The stock pair the settings form shows before a business picks its own (business-settings.php:104-105). */
    public const STOCK_PRIMARY   = '#2D8659';
    public const STOCK_SECONDARY = '#7FD858';

    /**
     * @param array<string,mixed> $row       business_settings columns (any may be missing)
     * @param bool                $isDefault the original business
     * @param string              $publicRoot absolute web root, for resolving /uploads/… to a file mPDF can read
     * @param string              $uploadsBase this business's own uploads folder ("uploads/t/<key>"); a logo must sit inside it
     * @return array{useDefaultLogo:bool,logoFile:string,logoAlt:string,custom:bool,primary:string,tint:string}
     */
    public static function resolve(array $row, bool $isDefault, string $publicRoot, string $uploadsBase = 'uploads'): array
    {
        $out = ['useDefaultLogo' => false, 'logoFile' => '', 'logoAlt' => '', 'custom' => false, 'primary' => '', 'tint' => ''];
        if ($isDefault) {
            $out['useDefaultLogo'] = true;
            return $out;
        }
        $out['logoFile'] = self::logoFile((string)($row['logo_path'] ?? ''), $publicRoot, $uploadsBase);
        $out['logoAlt']  = trim((string)($row['logo_alt_text'] ?? ''));

        $primary   = self::hex((string)($row['brand_color_primary'] ?? ''));
        $secondary = self::hex((string)($row['brand_color_secondary'] ?? ''));
        $stock = $primary === strtoupper(self::STOCK_PRIMARY) && ($secondary === '' || $secondary === strtoupper(self::STOCK_SECONDARY));
        if ($primary !== '' && !$stock) {
            $primary = self::readable($primary);
            $out['custom']  = true;
            $out['primary'] = $primary;
            $out['tint']    = self::mix($primary, '#FFFFFF', 0.90);
        }
        return $out;
    }

    /** The current business's brand, read from business_settings. A missing table or column means "no custom brand". */
    public static function forCurrent(PDO $db): array
    {
        if (!class_exists('Tenant', false)) require_once dirname(__DIR__, 3) . '/Core/Tenant.php';
        $row = [];
        try {
            $row = $db->query('SELECT logo_path, logo_alt_text, brand_color_primary, brand_color_secondary
                               FROM business_settings ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }
        $root = defined('PUBLIC_ROOT') ? (string)PUBLIC_ROOT : dirname(__DIR__, 4) . '/public';
        if (!function_exists('uploadsBase')) require_once dirname(__DIR__, 3) . '/Core/Uploads.php';
        return self::resolve($row, Tenant::isDefault(), $root, uploadsBase());
    }

    /** forCurrent() with the app's own connection; "no custom brand" when none is available (CLI scripts, tests). */
    public static function current(): array
    {
        try {
            if (function_exists('getDB')) return self::forCurrent(getDB());
        } catch (Throwable $e) {
            // fall through
        }
        return ['useDefaultLogo' => true, 'logoFile' => '', 'logoAlt' => '', 'custom' => false, 'primary' => '', 'tint' => ''];
    }

    /**
     * A stored web path (/uploads/…) → the absolute file, only when it sits under THIS business's uploads folder, is not a
     * receipt, climbs nowhere and is an image. '' otherwise. (Full URLs are never fetched here.)
     */
    public static function logoFile(string $logoPath, string $publicRoot, string $uploadsBase = 'uploads'): string
    {
        $p = trim(str_replace('\\', '/', $logoPath));
        $base = '/' . trim($uploadsBase, '/') . '/';
        // Only this business's own uploads folder, and never its receipts (they are deliberately not served).
        if ($p === '' || $p[0] !== '/' || strpos($p, $base) !== 0 || strpos($p, '..') !== false || strpos($p, "\0") !== false
            || stripos($p, '/receipts/') !== false) return '';
        $file = rtrim($publicRoot, '/') . $p;
        if (!is_file($file) || !is_readable($file)) return '';
        $info = @getimagesize($file);
        return ($info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) ? $file : '';
    }

    /**
     * Extra CSS for a template, empty unless the business has its own colours. Appended AFTER the template's own rules.
     * $doc: 'invoice' | 'quote' | 'statement' | 'contract'.
     */
    public static function css(array $brand, string $doc): string
    {
        if (empty($brand['custom'])) return '';
        $p = (string)$brand['primary'];
        $t = (string)$brand['tint'];
        switch ($doc) {
            case 'invoice':
                return ".brand-name, .doc-number, .invoice-messaging .block .label, .notes-section .section-title { color: $p; }\n"
                     . ".section-title.primary { color: $p; border-bottom-color: $p; }\n"
                     . ".total-callout { background: $p; }\n"
                     . ".total-callout.overdue { background: #982A1D; }\n"
                     . ".total-callout.paid { background: #1A5A39; }\n";
            case 'quote':
                return ".brand-name, .meta-num, .items-table td.service-name, .terms-title, .client-name { color: $p; }\n"
                     . ".section-title { color: $p; border-bottom-color: $p; }\n"
                     . ".meta-band { background: $t; }\n"
                     . ".totals-table tr.grand td { color: $p; border-top-color: $p; }\n"
                     . ".divider, .footer { border-top-color: $t; }\n";
            case 'statement':
                return ".brand-name { color: $p; }\n"
                     . ".doc-title { color: $p; }\n"
                     . "table.ledger thead th { background: $p; color: #ffffff; }\n"
                     . "table.ledger tr.opening td, .payinstr { background: $t; color: #131D17; }\n"
                     . "table.ledger tr.pay td { color: $p; }\n"
                     . ".totals tr.due td { background: $p; color: #ffffff; }\n"
                     . ".payinstr { border-left-color: $p; }\n";
            case 'contract':
                return ".header { border-bottom-color: $p; }\n"
                     . ".company-name, .section-title { color: $p; }\n"
                     . ".section-title { border-bottom-color: $t; }\n";
        }
        return '';
    }

    /**
     * For the report generators that build one big HTML string: swap Mowology's stock hex values for the business's
     * colours. $map is [stockHex => 'primary'|'tint']. No-op unless the business has its own colours.
     * @param array<string,string> $map
     */
    public static function recolor(string $html, array $brand, array $map): string
    {
        if (empty($brand['custom'])) return $html;
        $from = [];
        $to = [];
        foreach ($map as $stock => $role) {
            $from[] = $stock;
            $to[]   = $role === 'tint' ? (string)$brand['tint'] : (string)$brand['primary'];
        }
        return str_ireplace($from, $to, $html);
    }

    /** The business's own logo as a data: URI (mPDF/HTML safe), or '' when it has none. */
    public static function logoDataUri(array $brand): string
    {
        $f = (string)($brand['logoFile'] ?? '');
        if ($f === '' || !is_file($f)) return '';
        $info = @getimagesize($f);
        if (!$info) return '';
        $mime = $info[2] === IMAGETYPE_JPEG ? 'image/jpeg' : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($f));
    }

    // ── colour maths ─────────────────────────────────────────────────────────

    /** "#abc" / "#aabbcc" / "aabbcc" → "#AABBCC"; '' when not a colour. */
    public static function hex(string $v): string
    {
        $v = strtoupper(ltrim(trim($v), '#'));
        if (preg_match('/^[0-9A-F]{3}$/', $v)) $v = $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
        return preg_match('/^[0-9A-F]{6}$/', $v) ? '#' . $v : '';
    }

    /** WCAG relative luminance of #RRGGBB. */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $f = static function (int $c): float {
            $s = $c / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    /** WCAG contrast ratio between two colours (1..21). */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Darken a colour in small steps until it has at least 4.5:1 against white. */
    public static function readable(string $hex): string
    {
        $c = $hex;
        for ($i = 0; $i < 30 && self::contrast($c, '#FFFFFF') < 4.5; $i++) {
            $c = self::mix($c, '#000000', 0.08);
        }
        return $c;
    }

    /** Blend $a toward $b by $t (0..1). */
    public static function mix(string $a, string $b, float $t): string
    {
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);
        $m = static fn(int $x, int $y): int => (int)round($x + ($y - $x) * $t);
        return sprintf('#%02X%02X%02X', $m($ar, $br), $m($ag, $bg), $m($ab, $bb));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }
}
