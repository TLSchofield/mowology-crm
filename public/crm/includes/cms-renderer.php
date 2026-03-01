<?php
/**
 * CMS Renderer Engine
 *
 * Core rendering logic for CMS pages and blocks.
 * Handles layout selection, block rendering, and output escaping.
 *
 * @package Mowology
 * @subpackage CMS
 */

declare(strict_types=1);

/**
 * Main page rendering function
 *
 * Renders CMS pages using the PUBLIC site layout (head.php/header.php/footer.php),
 * NOT the CRM AppStack layout. CMS pages look like the rest of the public website.
 *
 * @param array $page Page record from database
 * @return void Outputs complete HTML page
 */
function cms_renderPage(array $page): void
{
    // Track page view
    cms_trackPageView($page['id']);

    // Build token map for this page (if token engine loaded)
    $tokenMap = function_exists('cms_buildTokenMap')
        ? cms_buildTokenMap($page['id'])
        : [];

    // Set page variables for public site include system
    $rawTitle = $page['meta_title'] ?? $page['title'] . ' | ' . SITE_NAME;
    $rawDesc = $page['meta_description'] ?? '';
    $pageTitle = $tokenMap ? cms_resolveTokens($rawTitle, $tokenMap) : $rawTitle;
    $pageDescription = $tokenMap ? cms_resolveTokens($rawDesc, $tokenMap) : $rawDesc;
    $activeNav = cms_getActiveNav($page);

    // Load blocks early — needed for OG image auto-select and token processing
    $blocks = cms_getBlocksByPageId($page['id']);
    if ($tokenMap) {
        foreach ($blocks as &$block) {
            if (!empty($block['config']) && is_array($block['config'])) {
                $block['config'] = cms_processBlockConfig($block['config'], $tokenMap);
            }
        }
        unset($block);
    }

    // OG image: page-specific → first hero block image → site default (P1-D)
    $ogImagePath = $page['og_image_path'] ?? '';
    if (empty($ogImagePath)) {
        foreach ($blocks as $block) {
            if ($block['block_type'] === 'hero') {
                $cfg = $block['config'] ?? [];
                $ogImagePath = $cfg['background_image'] ?? $cfg['image'] ?? $cfg['image_url'] ?? '';
                if (!empty($ogImagePath)) {
                    break;
                }
            }
        }
    }
    if (empty($ogImagePath)) {
        $ogImagePath = '/assets/images/mowology-share.jpg';
    }
    $pageImage = $ogImagePath;

    // Noindex: send both HTTP header and meta tag for maximum crawler coverage
    $extraHead = '';
    if ($page['status'] !== 'published' || !empty($page['noindex'])) {
        header('X-Robots-Tag: noindex, nofollow');
        $extraHead .= '<meta name="robots" content="noindex, nofollow">' . "\n";
    }
    $extraHead .= cms_renderStructuredData($page, $blocks);

    // Resolve path to public includes (works in both local dev and production)
    // cms-renderer.php is at /public/crm/includes/ → public root is 2 dirs up
    $publicRoot = dirname(dirname(__DIR__));

    // Include PUBLIC site head (outputs <!DOCTYPE> through </head>)
    require $publicRoot . '/includes/head.php';

    // Include PUBLIC site header (outputs <body>, <header>, <nav>)
    require $publicRoot . '/includes/header.php';

    // Load layout template
    $layoutPath = CMS_LAYOUTS_DIR . '/' . ($page['layout_template'] ?? 'default') . '.php';
    if (!file_exists($layoutPath)) {
        $layoutPath = CMS_LAYOUTS_DIR . '/default.php';
    }

    // $blocks already loaded and token-processed above — passed into layout via scope
    // Render layout with blocks
    include $layoutPath;

    // Include PUBLIC site footer (outputs <footer>, scripts, </body>, </html>)
    require $publicRoot . '/includes/footer.php';
}

/**
 * Render a single block
 *
 * @param array $block Block record with config
 * @return string Rendered HTML
 */
function cms_renderBlock(array $block): string
{
    $blockType = $block['block_type'] ?? '';
    $variant   = !empty($block['variant']) ? $block['variant'] : 'default';

    // Variant renderer: blocks/{type}--{variant}.php (P1-C)
    // Falls back to the standard renderer if the variant file doesn't exist.
    $variantPath  = CMS_BLOCKS_RENDERER_DIR . '/' . $blockType . '--' . $variant . '.php';
    $defaultPath  = CMS_BLOCKS_RENDERER_DIR . '/' . $blockType . '.php';
    $rendererPath = ($variant !== 'default' && file_exists($variantPath)) ? $variantPath : $defaultPath;

    if (!file_exists($rendererPath)) {
        return "<!-- Block renderer not found: {$blockType} -->";
    }

    // Check visibility rules
    if (!cms_checkBlockVisibility($block)) {
        return '';
    }

    try {
        ob_start();

        // Extract config/content for use in renderer
        $config = $block['config'] ?? [];
        $content = $block['content'] ?? null;

        // Include renderer (has access to $config, $content, $block)
        include $rendererPath;

        return ob_get_clean() ?: '';
    } catch (Exception $e) {
        error_log("Block render error ({$blockType}): " . $e->getMessage());
        return "<!-- Error rendering block -->";
    }
}

/**
 * Check if block should be rendered (visibility rules)
 *
 * @param array $block Block record
 * @return bool Whether to render
 */
function cms_checkBlockVisibility(array $block): bool
{
    $visibility = $block['visibility'] ?? null;
    if (!$visibility) {
        return true;
    }

    // Device targeting
    if (!empty($visibility['devices'])) {
        $isMobile = (
            preg_match('/mobile|android|iphone|ipad/i', $_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        $allowedDevices = $visibility['devices'];
        $shouldShow = false;

        if ($isMobile && in_array('mobile', $allowedDevices)) {
            $shouldShow = true;
        }
        if (!$isMobile && in_array('desktop', $allowedDevices)) {
            $shouldShow = true;
        }

        if (!$shouldShow) {
            return false;
        }
    }

    // Role targeting (if admin/user system exists)
    if (!empty($visibility['roles'])) {
        if (!function_exists('getCurrentUser')) {
            return in_array('all', $visibility['roles']);
        }

        try {
            $user = getCurrentUser();
            $userRole = $user['role'] ?? 'guest';

            if (!in_array('all', $visibility['roles']) && !in_array($userRole, $visibility['roles'])) {
                return false;
            }
        } catch (Exception $e) {
            // If auth fails, treat as guest
            return in_array('all', $visibility['roles']);
        }
    }

    return true;
}

/**
 * Get active nav item based on page
 *
 * @param array $page Page record
 * @return string Active nav key
 */
function cms_getActiveNav(array $page): string
{
    $mapping = [
        'home' => 'home',
        'about' => 'about',
        'services' => 'services',
        'service_landing' => 'services',
        'portfolio' => 'portfolio',
        'contact' => 'contact',
    ];

    return $mapping[$page['page_type']] ?? '';
}

/**
 * Track page view in database
 *
 * @param int $pageId Page ID
 * @return void
 */
function cms_trackPageView(int $pageId): void
{
    try {
        $db = getDB();
        $db->prepare("
            UPDATE cms_pages
            SET view_count = view_count + 1
            WHERE id = ?
        ")->execute([$pageId]);
    } catch (Exception $e) {
        // Silently fail - don't break page rendering for analytics
    }
}

/**
 * Render breadcrumbs for a page
 *
 * @param array $page Page record
 * @return string HTML breadcrumb markup
 */
function cms_renderBreadcrumbs(array $page): string
{
    $breadcrumbs = [['url' => '/', 'label' => 'Home']];

    // Add parent pages based on slug hierarchy
    $slugParts = explode('/', trim($page['slug'], '/'));
    $currentPath = '';

    for ($i = 0; $i < count($slugParts) - 1; $i++) {
        $currentPath .= '/' . $slugParts[$i];
        $breadcrumbs[] = [
            'url' => $currentPath,
            'label' => ucwords(str_replace('-', ' ', $slugParts[$i])),
        ];
    }

    // Add current page
    $breadcrumbs[] = [
        'url' => null,
        'label' => $page['title'],
    ];

    // Render
    $html = '<nav class="breadcrumb" aria-label="breadcrumb">';
    $html .= '<ol class="breadcrumb-list">';

    foreach ($breadcrumbs as $crumb) {
        if ($crumb['url']) {
            $html .= '<li><a href="' . h($crumb['url']) . '">' . h($crumb['label']) . '</a></li>';
        } else {
            $html .= '<li class="active" aria-current="page">' . h($crumb['label']) . '</li>';
        }
    }

    $html .= '</ol></nav>';

    return $html;
}

/**
 * Render structured data (JSON-LD) for a page — page-type aware (P1-D).
 *
 * Emits appropriate schema.org types:
 *   home            → LocalBusiness + WebSite
 *   service_landing → Service + LocalBusiness
 *   portfolio       → ImageGallery
 *   default         → WebPage
 *
 * @param array $page   Page record
 * @param array $blocks Already-loaded blocks (used to pull first image if no og_image_path)
 * @return string One or more JSON-LD <script> tags
 */
function cms_renderStructuredData(array $page, array $blocks = []): string
{
    $siteUrl  = defined('SITE_URL')  ? SITE_URL  : 'https://mowology.ca';
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Mowology';
    $phone    = defined('SITE_PHONE_DISPLAY') ? SITE_PHONE_DISPLAY : '(778) 846-9273';
    $email    = defined('SITE_EMAIL') ? SITE_EMAIL : '';

    $pageUrl = $page['canonical_url'] ?? $siteUrl . '/' . ltrim($page['slug'] ?? '', '/');
    $pageDesc = $page['meta_description'] ?? '';
    $pageTitle = $page['title'] ?? $siteName;

    // Resolve best available image for schema
    $imageUrl = '';
    if (!empty($page['og_image_path'])) {
        $imageUrl = $siteUrl . $page['og_image_path'];
    } else {
        foreach ($blocks as $block) {
            if ($block['block_type'] === 'hero') {
                $cfg = $block['config'] ?? [];
                $img = $cfg['background_image'] ?? $cfg['image'] ?? $cfg['image_url'] ?? '';
                if ($img) { $imageUrl = $siteUrl . $img; break; }
            }
        }
    }

    // Shared LocalBusiness node (referenced by other schemas)
    $localBusiness = [
        '@type'       => 'LocalBusiness',
        '@id'         => $siteUrl . '/#business',
        'name'        => $siteName,
        'url'         => $siteUrl,
        'telephone'   => $phone,
        'email'       => $email,
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Vancouver',
            'addressRegion'   => 'BC',
            'addressCountry'  => 'CA',
        ],
        'areaServed'  => [
            'Vancouver', 'Burnaby', 'Richmond',
            'North Vancouver', 'West Vancouver', 'Surrey',
        ],
        'priceRange'  => '$$',
    ];
    if ($imageUrl) {
        $localBusiness['image'] = $imageUrl;
    }

    $schemas = [];

    switch ($page['page_type'] ?? 'custom') {

        case 'home':
            $schemas[] = array_merge(
                ['@context' => 'https://schema.org'],
                $localBusiness,
                ['@type' => ['LocalBusiness', 'LandscapingBusiness']]
            );
            $schemas[] = [
                '@context'        => 'https://schema.org',
                '@type'           => 'WebSite',
                'name'            => $siteName,
                'url'             => $siteUrl,
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => $siteUrl . '/search?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ];
            break;

        case 'service_landing':
        case 'services':
            $schemas[] = [
                '@context'    => 'https://schema.org',
                '@type'       => 'Service',
                'name'        => $pageTitle,
                'description' => $pageDesc,
                'url'         => $pageUrl,
                'provider'    => ['@id' => $siteUrl . '/#business'],
                'areaServed'  => ['Vancouver', 'Burnaby', 'Richmond', 'North Vancouver'],
                'serviceType' => $pageTitle,
            ];
            if ($imageUrl) {
                $schemas[0]['image'] = $imageUrl;
            }
            $schemas[] = array_merge(
                ['@context' => 'https://schema.org'],
                $localBusiness
            );
            break;

        case 'portfolio':
            $schema = [
                '@context'    => 'https://schema.org',
                '@type'       => 'ImageGallery',
                'name'        => $pageTitle,
                'description' => $pageDesc,
                'url'         => $pageUrl,
            ];
            if ($imageUrl) {
                $schema['image'] = $imageUrl;
            }
            $schemas[] = $schema;
            break;

        default:
            $schema = [
                '@context'    => 'https://schema.org',
                '@type'       => 'WebPage',
                'name'        => $pageTitle,
                'description' => $pageDesc,
                'url'         => $pageUrl,
                'isPartOf'    => [
                    '@type' => 'WebSite',
                    'name'  => $siteName,
                    'url'   => $siteUrl,
                ],
            ];
            if ($imageUrl) {
                $schema['image'] = $imageUrl;
            }
            $schemas[] = $schema;
            break;
    }

    $output = '';
    foreach ($schemas as $schema) {
        try {
            $json    = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $output .= '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
        } catch (\Throwable $e) {
            // Skip broken schema rather than breaking the page
        }
    }

    return $output;
}

/**
 * Get canonical URL for a page
 *
 * @param array $page Page record
 * @return string Canonical URL
 */
function cms_getCanonicalUrl(array $page): string
{
    return $page['canonical_url'] ?? SITE_URL . '/' . trim($page['slug'], '/');
}

/**
 * Render page into HTML (used by layouts)
 *
 * @param array $blocks Blocks for this page
 * @return string HTML content
 */
function cms_renderPageContent(array $blocks): string
{
    $html = '';

    foreach ($blocks as $block) {
        $html .= cms_renderBlock($block);
    }

    return $html;
}

/**
 * Get a block's rendered content (for use in layouts)
 *
 * @param array $blocks All page blocks
 * @param string $blockType Block type to find and render
 * @param int $position Optional: specific block position
 * @return string Rendered HTML
 */
function cms_getBlockContent(array $blocks, string $blockType, ?int $position = null): string
{
    foreach ($blocks as $block) {
        if ($block['block_type'] === $blockType && ($position === null || $block['position'] === $position)) {
            return cms_renderBlock($block);
        }
    }

    return '';
}

/**
 * Render all blocks of a certain type
 *
 * @param array $blocks All page blocks
 * @param string $blockType Block type to render
 * @return string Concatenated HTML
 */
function cms_getBlocksOfType(array $blocks, string $blockType): string
{
    $html = '';

    foreach ($blocks as $block) {
        if ($block['block_type'] === $blockType) {
            $html .= cms_renderBlock($block);
        }
    }

    return $html;
}

/**
 * Render hero block (convenience function for layouts)
 *
 * @param array $blocks Page blocks
 * @return string Rendered hero or empty string
 */
function cms_renderHero(array $blocks): string
{
    return cms_getBlockContent($blocks, 'hero', 0) ?: '';
}

/**
 * Render page sections (all non-hero blocks)
 *
 * @param array $blocks Page blocks
 * @param int $startPosition Position to start from (usually 1, after hero)
 * @return string Concatenated HTML
 */
function cms_renderSections(array $blocks, int $startPosition = 1): string
{
    $html = '';

    foreach ($blocks as $block) {
        if ($block['position'] >= $startPosition) {
            $html .= cms_renderBlock($block);
        }
    }

    return $html;
}

/**
 * Get page meta tags for head section
 *
 * @param array $page Page record
 * @return string Meta tag markup
 */
function cms_getMetaTags(array $page): string
{
    $tags = '';

    // Canonical
    $tags .= '<link rel="canonical" href="' . h(cms_getCanonicalUrl($page)) . '">' . "\n";

    // OpenGraph
    if ($page['meta_title']) {
        $tags .= '<meta property="og:title" content="' . h($page['meta_title']) . '">' . "\n";
    }
    if ($page['meta_description']) {
        $tags .= '<meta property="og:description" content="' . h($page['meta_description']) . '">' . "\n";
    }
    if ($page['og_image_path']) {
        $tags .= '<meta property="og:image" content="' . h(SITE_URL . $page['og_image_path']) . '">' . "\n";
    }

    // Robots
    if ($page['noindex']) {
        $tags .= '<meta name="robots" content="noindex">' . "\n";
    }

    return $tags;
}
