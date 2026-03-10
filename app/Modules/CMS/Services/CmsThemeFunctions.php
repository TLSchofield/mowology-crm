<?php
/**
 * CmsThemeFunctions.php
 * Theme Engine — per-site CSS custom property overrides.
 *
 * Each site has one active theme row in cms_themes.
 * Theme tokens map 1-to-1 with CSS custom properties injected into <head>.
 *
 * Public API:
 *   cms_getActiveTheme(int $siteId): array
 *   cms_saveTheme(int $siteId, array $data): bool
 *   cms_buildThemeCss(array $theme): string
 *   cms_buildFontUrl(array $theme): string
 */

declare(strict_types=1);

// ─── Available fonts ──────────────────────────────────────────────────────────

const CMS_HEADING_FONTS = [
    'Playfair Display' => 'Playfair+Display:ital,wght@0,600;0,700;1,600',
    'Merriweather'     => 'Merriweather:wght@400;700',
    'Poppins'          => 'Poppins:wght@400;600;700',
    'Raleway'          => 'Raleway:wght@400;600;700',
    'Montserrat'       => 'Montserrat:wght@400;600;700',
    'Cormorant Garamond' => 'Cormorant+Garamond:ital,wght@0,600;1,600',
    'Oswald'           => 'Oswald:wght@400;500;600',
    'Georgia'          => null, // system font — no Google Fonts URL needed
];

const CMS_BODY_FONTS = [
    'DM Sans'     => 'DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400',
    'Inter'       => 'Inter:wght@400;500;600',
    'Open Sans'   => 'Open+Sans:wght@400;600',
    'Lato'        => 'Lato:wght@400;700',
    'Nunito'      => 'Nunito:wght@400;600;700',
    'Source Sans 3' => 'Source+Sans+3:wght@400;600',
    'system-ui'   => null, // system font
];

// ─── Default theme fallback ───────────────────────────────────────────────────

function cms_defaultTheme(): array {
    return [
        'id'            => 0,
        'site_id'       => 1,
        'name'          => 'Default',
        'color_primary' => '#2D8659',
        'color_dark'    => '#1A5F4A',
        'color_accent'  => '#e85d04',
        'color_bg_dark' => '#0d1f12',
        'color_text'    => '#1a1a1a',
        'font_heading'  => 'Playfair Display',
        'font_body'     => 'DM Sans',
        'custom_css'    => '',
    ];
}

// ─── Get active theme ─────────────────────────────────────────────────────────

/**
 * Returns the active theme for a site, falling back to defaults if missing.
 * Static-cached per request.
 */
function cms_getActiveTheme(int $siteId): array {
    static $cache = [];
    if (isset($cache[$siteId])) return $cache[$siteId];

    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT * FROM cms_themes WHERE site_id = ? ORDER BY is_active DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$siteId] = $row ?: cms_defaultTheme();
    } catch (\Throwable $e) {
        $cache[$siteId] = cms_defaultTheme();
    }

    return $cache[$siteId];
}

// ─── Save / upsert theme ──────────────────────────────────────────────────────

/**
 * Saves theme tokens for a site. Creates the row if it doesn't exist yet.
 *
 * $data keys: color_primary, color_dark, color_accent, color_bg_dark,
 *             color_text, font_heading, font_body, custom_css
 */
function cms_saveTheme(int $siteId, array $data): bool {
    $db = getDB();

    // Sanitise — only allow known keys
    $allowed = ['color_primary','color_dark','color_accent','color_bg_dark',
                'color_text','font_heading','font_body','custom_css','name'];
    $clean   = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $clean[$key] = $data[$key];
        }
    }
    if (empty($clean)) return false;

    // Validate hex colors
    $colorKeys = ['color_primary','color_dark','color_accent','color_bg_dark','color_text'];
    foreach ($colorKeys as $k) {
        if (isset($clean[$k]) && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $clean[$k])) {
            unset($clean[$k]); // drop invalid values rather than error
        }
    }

    // Validate fonts against whitelist
    if (isset($clean['font_heading']) && !array_key_exists($clean['font_heading'], CMS_HEADING_FONTS)) {
        unset($clean['font_heading']);
    }
    if (isset($clean['font_body']) && !array_key_exists($clean['font_body'], CMS_BODY_FONTS)) {
        unset($clean['font_body']);
    }

    // Check if row exists
    $stmt = $db->prepare('SELECT id FROM cms_themes WHERE site_id = ? LIMIT 1');
    $stmt->execute([$siteId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $sets   = implode(', ', array_map(fn($k) => "$k = ?", array_keys($clean)));
        $values = array_values($clean);
        $values[] = $siteId;
        $db->prepare("UPDATE cms_themes SET $sets, updated_at = NOW() WHERE site_id = ?")
           ->execute($values);
    } else {
        $clean['site_id'] = $siteId;
        $cols   = implode(', ', array_keys($clean));
        $placeholders = implode(', ', array_fill(0, count($clean), '?'));
        $db->prepare("INSERT INTO cms_themes ($cols) VALUES ($placeholders)")
           ->execute(array_values($clean));
    }

    return true;
}

// ─── Build CSS ────────────────────────────────────────────────────────────────

/**
 * Builds the <style> block content that overrides :root custom properties.
 * Returned string is safe to echo inside a <style> tag.
 */
function cms_buildThemeCss(array $theme): string {
    $p  = htmlspecialchars($theme['color_primary'] ?? '#2D8659', ENT_QUOTES);
    $d  = htmlspecialchars($theme['color_dark']    ?? '#1A5F4A', ENT_QUOTES);
    $a  = htmlspecialchars($theme['color_accent']  ?? '#e85d04', ENT_QUOTES);
    $bg = htmlspecialchars($theme['color_bg_dark'] ?? '#0d1f12', ENT_QUOTES);
    $t  = htmlspecialchars($theme['color_text']    ?? '#1a1a1a', ENT_QUOTES);
    $fh = htmlspecialchars($theme['font_heading']  ?? 'Playfair Display', ENT_QUOTES);
    $fb = htmlspecialchars($theme['font_body']     ?? 'DM Sans', ENT_QUOTES);

    // Derive forest-deep from bg-dark (slightly darker) — simple hex blend not available in PHP,
    // so we just reuse bg-dark for now; can be improved later.
    $css = ":root {\n";
    $css .= "  --mowology-green: {$p};\n";
    $css .= "  --mowology-dark: {$d};\n";
    $css .= "  --color-primary: {$p};\n";
    $css .= "  --color-primary-dark: {$d};\n";
    $css .= "  --color-accent: {$a};\n";
    $css .= "  --bg-dark: {$bg};\n";
    $css .= "  --bg-forest: {$bg};\n";
    $css .= "  --text-dark: {$t};\n";
    $css .= "  --font-heading: '{$fh}', Georgia, serif;\n";
    $css .= "  --font-body: '{$fb}', system-ui, sans-serif;\n";
    $css .= "}\n";

    // Custom CSS — strip <script> tags for safety but allow raw CSS
    if (!empty($theme['custom_css'])) {
        $customCss = preg_replace('/<\/?script[^>]*>/i', '', $theme['custom_css']);
        $css .= "\n/* Custom CSS */\n" . $customCss . "\n";
    }

    return $css;
}

// ─── Build Google Fonts URL ───────────────────────────────────────────────────

/**
 * Returns a Google Fonts <link> href for the active theme's fonts,
 * or empty string if both fonts are system fonts.
 */
function cms_buildFontUrl(array $theme): string {
    $families = [];

    $headingFont = $theme['font_heading'] ?? 'Playfair Display';
    $bodyFont    = $theme['font_body']    ?? 'DM Sans';

    if (isset(CMS_HEADING_FONTS[$headingFont]) && CMS_HEADING_FONTS[$headingFont] !== null) {
        $families[] = 'family=' . CMS_HEADING_FONTS[$headingFont];
    }

    if (isset(CMS_BODY_FONTS[$bodyFont]) && CMS_BODY_FONTS[$bodyFont] !== null) {
        $families[] = 'family=' . CMS_BODY_FONTS[$bodyFont];
    }

    if (empty($families)) return '';

    return 'https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap';
}
