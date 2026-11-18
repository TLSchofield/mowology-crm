<?php
/**
 * WebServiceCatalog — CRM service packages as the source for public service listings.
 *
 * A package is shown on the website only when web_visible = 1 AND is_active = 1. The public
 * service page for a package is named by web_slug. Before migration 1458 has run, every read
 * returns null / [] so the public site keeps its hand-written copy.
 *
 * Required: explicit require_once (service classes have no autoloader).
 */

class WebServiceCatalog
{
    /** Words shown after the price, keyed by service_packages.unit_type. */
    private const UNIT_LABELS = [
        'visit'  => 'per visit',
        'month'  => 'per month',
        'season' => 'per season',
        'hour'   => 'per hour',
        'sqft'   => 'per sq ft',
        'each'   => 'each',
    ];

    /**
     * The public package for a page slug, or null if none is web-visible.
     *
     * @return array{id:int,package_name:string,web_slug:string,web_headline:?string,web_bullets:array<int,string>,show_web_price:bool,base_price:float,unit_type:string}|null
     */
    public static function findByWebSlug(PDO $db, string $slug): ?array
    {
        try {
            $stmt = $db->prepare("
                SELECT id, package_name, web_slug, web_headline, web_bullets, show_web_price, base_price, unit_type
                  FROM service_packages
                 WHERE web_slug = ? AND web_visible = 1 AND is_active = 1
                 LIMIT 1
            ");
            $stmt->execute([$slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null; // migration 1458 not run yet — keep hand-written copy
        }
        return $row ? self::normalise($row) : null;
    }

    /**
     * Every public package, for the services index and the CRM editor preview.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function listWeb(PDO $db): array
    {
        try {
            $rows = $db->query("
                SELECT id, package_name, web_slug, web_headline, web_bullets, show_web_price, base_price, unit_type
                  FROM service_packages
                 WHERE web_visible = 1 AND is_active = 1 AND web_slug IS NOT NULL AND web_slug <> ''
                 ORDER BY package_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
        return array_map([self::class, 'normalise'], $rows);
    }

    /**
     * Save the web fields for one package. Returns a list of validation errors (empty = saved).
     *
     * @param array<string,mixed> $in  web_visible, web_slug, web_headline, web_bullets, show_web_price
     * @return string[]
     */
    public static function save(PDO $db, int $packageId, array $in): array
    {
        $visible = !empty($in['web_visible']) ? 1 : 0;
        $slug    = strtolower(trim((string)($in['web_slug'] ?? '')));
        $headline = trim((string)($in['web_headline'] ?? ''));
        $bullets  = self::cleanBullets((string)($in['web_bullets'] ?? ''));
        $showPrice = !empty($in['show_web_price']) ? 1 : 0;

        $errors = [];
        if ($visible && !preg_match('/^[a-z0-9][a-z0-9-]{0,98}$/', $slug)) {
            $errors[] = 'Web page name must be lowercase letters, numbers and hyphens (e.g. spring-cleanup).';
        }
        if (mb_strlen($headline) > 255) {
            $errors[] = 'Headline must be 255 characters or fewer.';
        }
        if ($visible && $slug !== '') {
            $dup = $db->prepare("SELECT id FROM service_packages WHERE web_slug = ? AND id <> ? LIMIT 1");
            $dup->execute([$slug, $packageId]);
            if ($dup->fetchColumn()) {
                $errors[] = "Another package already uses the web page name '$slug'.";
            }
        }
        if ($errors) {
            return $errors;
        }

        try {
            $db->prepare("
                UPDATE service_packages
                   SET web_visible = ?, web_slug = ?, web_headline = ?, web_bullets = ?, show_web_price = ?
                 WHERE id = ?
            ")->execute([
                $visible,
                $slug !== '' ? $slug : null,
                $headline !== '' ? $headline : null,
                $bullets !== '' ? $bullets : null,
                $showPrice,
                $packageId,
            ]);
        } catch (PDOException $e) {
            // 23000 = integrity violation: the unique web_slug index caught a concurrent duplicate.
            if ($e->getCode() === '23000') {
                return ["Another package already uses the web page name '$slug'."];
            }
            // Column missing (migration 1458 not run) or other DB failure: report, don't 500.
            return ['The website fields are not available yet. Run migration 1458, then try again.'];
        }
        return [];
    }

    /** "From $65 per visit" — or '' when the package price is not shown. */
    public static function priceLabel(array $pkg): string
    {
        if (empty($pkg['show_web_price']) || (float)($pkg['base_price'] ?? 0) <= 0) {
            return '';
        }
        $price = (float)$pkg['base_price'];
        $amount = fmod($price, 1.0) === 0.0 ? number_format($price, 0) : number_format($price, 2);
        $unit = self::UNIT_LABELS[$pkg['unit_type'] ?? 'visit'] ?? 'per visit';
        return 'From $' . $amount . ' ' . $unit;
    }

    /** Trim, drop blanks, cap at 12 lines; returns the newline-joined text stored in web_bullets. */
    public static function cleanBullets(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn($l) => $l !== ''));
        return implode("\n", array_slice($lines, 0, 12));
    }

    /** @return array<string,mixed> */
    private static function normalise(array $row): array
    {
        $bullets = preg_split('/\r\n|\r|\n/', (string)($row['web_bullets'] ?? '')) ?: [];
        return [
            'id'             => (int)$row['id'],
            'package_name'   => (string)$row['package_name'],
            'web_slug'       => (string)$row['web_slug'],
            'web_headline'   => ($row['web_headline'] ?? null) !== null && $row['web_headline'] !== '' ? (string)$row['web_headline'] : null,
            'web_bullets'    => array_values(array_filter(array_map('trim', $bullets), static fn($b) => $b !== '')),
            'show_web_price' => (bool)(int)($row['show_web_price'] ?? 0),
            'base_price'     => (float)($row['base_price'] ?? 0),
            'unit_type'      => (string)($row['unit_type'] ?? 'visit'),
        ];
    }
}
