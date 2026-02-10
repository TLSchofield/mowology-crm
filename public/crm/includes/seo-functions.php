<?php
/**
 * SEO Automation Functions
 *
 * Auto-generate SEO metadata with smart defaults, schema markup, and best practices.
 *
 * @package Mowology CRM
 * @subpackage CMS SEO
 */

declare(strict_types=1);

// ============================================================================
// SEO DEFAULTS & CONFIGURATION
// ============================================================================

const SEO_TITLE_TEMPLATE = '{title} in Vancouver | Mowology';
const SEO_DESCRIPTION_MAX = 160;
const SEO_TITLE_MAX = 60;

/**
 * Auto-generate meta title with smart defaults
 */
function seo_getMetaTitle(array $page): string
{
    if (!empty($page['meta_title'])) {
        return $page['meta_title'];
    }

    $title = $page['title'] ?? '';
    if (empty($title)) {
        return SITE_NAME;
    }

    $generated = str_replace('{title}', $title, SEO_TITLE_TEMPLATE);

    if (strlen($generated) > SEO_TITLE_MAX) {
        $generated = substr($generated, 0, SEO_TITLE_MAX - 3) . '...';
    }

    return $generated;
}

/**
 * Auto-generate meta description with smart defaults
 */
function seo_getMetaDescription(array $page, array $blocks = []): string
{
    if (!empty($page['meta_description'])) {
        return $page['meta_description'];
    }

    $description = '';
    foreach ($blocks as $block) {
        if ($block['block_type'] === 'rich_text' && !empty($block['content'])) {
            $text = strip_tags($block['content']);
            $description = mb_substr($text, 0, SEO_DESCRIPTION_MAX);
            break;
        } elseif ($block['block_type'] === 'feature_grid' && !empty($block['config']['description'])) {
            $description = mb_substr($block['config']['description'], 0, SEO_DESCRIPTION_MAX);
            break;
        }
    }

    if (empty($description)) {
        $description = 'Professional landscaping services in Vancouver. Expert care for your landscape.';
    }

    if (strlen($description) > SEO_DESCRIPTION_MAX) {
        $truncated = mb_substr($description, 0, SEO_DESCRIPTION_MAX - 3);
        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace > 100) {
            $description = mb_substr($truncated, 0, $lastSpace) . '...';
        } else {
            $description = $truncated . '...';
        }
    }

    return $description;
}

/**
 * Get canonical URL for page
 */
function seo_getCanonicalUrl(array $page): string
{
    if (!empty($page['canonical_override'])) {
        return $page['canonical_override'];
    }

    return SITE_URL . '/' . trim($page['slug'], '/');
}

/**
 * Generate JSON-LD schema markup for page
 */
function seo_generatePageSchema(array $page, array $blocks = []): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => $page['title'],
        'description' => seo_getMetaDescription($page, $blocks),
        'url' => seo_getCanonicalUrl($page),
        'isPartOf' => [
            '@type' => 'WebSite',
            'name' => SITE_NAME,
            'url' => SITE_URL,
        ],
    ];
}

/**
 * Get robots meta tag value
 */
function seo_getRobotsMetaTag(array $page): string
{
    if (!empty($page['robots_override'])) {
        return $page['robots_override'];
    }

    if ($page['status'] !== 'published') {
        return 'noindex, nofollow';
    }

    if ($page['noindex'] ?? false) {
        return 'noindex';
    }

    return 'index, follow';
}

/**
 * Generate all SEO meta tags as HTML
 */
function seo_renderMetaTags(array $page, array $blocks = []): string
{
    $html = '';

    $html .= '<link rel="canonical" href="' . h(seo_getCanonicalUrl($page)) . '">' . "\n";

    $robots = seo_getRobotsMetaTag($page);
    $html .= '<meta name="robots" content="' . h($robots) . '">' . "\n";

    $metaTitle = seo_getMetaTitle($page);
    $metaDesc = seo_getMetaDescription($page, $blocks);

    $html .= '<meta property="og:title" content="' . h($metaTitle) . '">' . "\n";
    $html .= '<meta property="og:description" content="' . h($metaDesc) . '">' . "\n";
    $html .= '<meta property="og:url" content="' . h(seo_getCanonicalUrl($page)) . '">' . "\n";
    $html .= '<meta property="og:type" content="website">' . "\n";

    if (!empty($page['og_image_path'])) {
        $html .= '<meta property="og:image" content="' . h(SITE_URL . $page['og_image_path']) . '">' . "\n";
    }

    $html .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    $html .= '<meta name="twitter:title" content="' . h($metaTitle) . '">' . "\n";
    $html .= '<meta name="twitter:description" content="' . h($metaDesc) . '">' . "\n";

    return $html;
}

/**
 * Render JSON-LD schema as HTML script tag
 */
function seo_renderSchemaMarkup(array $page, array $blocks = []): string
{
    $schema = seo_generatePageSchema($page, $blocks);
    return '<script type="application/ld+json">' . "\n"
        . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . "\n" . '</script>' . "\n";
}
