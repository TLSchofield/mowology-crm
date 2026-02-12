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

// ============================================================================
// SEO RECOMMENDATION ENGINE FUNCTIONS
// Used by /crm/cron/seo_recommendations.php
// ============================================================================

/**
 * Get the current active season based on today's date.
 * Returns the season row or an empty array if no season matches.
 */
function getCurrentSeason(PDO $db): array
{
    $month = (int)date('n');
    $day   = (int)date('j');

    $stmt = $db->prepare("
        SELECT id, season_key, label, start_month, start_day, end_month, end_day, priority_boost
        FROM seo_seasons
        WHERE is_active = 1
        ORDER BY priority_boost DESC
    ");
    $stmt->execute();
    $seasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($seasons as $season) {
        $sm = (int)$season['start_month'];
        $sd = (int)$season['start_day'];
        $em = (int)$season['end_month'];
        $ed = (int)$season['end_day'];

        // Handle ranges that wrap around year boundary (e.g. Nov-Mar)
        if ($sm < $em || ($sm === $em && $sd <= $ed)) {
            // Normal range (e.g. Mar 1 - May 31)
            $afterStart  = ($month > $sm || ($month === $sm && $day >= $sd));
            $beforeEnd   = ($month < $em || ($month === $em && $day <= $ed));
            if ($afterStart && $beforeEnd) {
                return $season;
            }
        } else {
            // Wrapping range (e.g. Nov 1 - Mar 31)
            $afterStart = ($month > $sm || ($month === $sm && $day >= $sd));
            $beforeEnd  = ($month < $em || ($month === $em && $day <= $ed));
            if ($afterStart || $beforeEnd) {
                return $season;
            }
        }
    }

    return [];
}

/**
 * Detect if a GSC query matches any active SEO target.
 * Returns the target_id or null if no match.
 */
function detectMatchingTarget(string $query, PDO $db): ?int
{
    static $targets = null;

    if ($targets === null) {
        $stmt = $db->query("
            SELECT id, name, target_type, canonical_slug, city, postcode_prefix, neighbourhood, priority_weight
            FROM seo_targets
            WHERE is_active = 1
        ");
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $queryLower = mb_strtolower(trim($query));
    $bestMatch  = null;
    $bestWeight = 0;

    foreach ($targets as $target) {
        $matched = false;

        // Match against target name keywords
        $nameWords = array_filter(explode(' ', mb_strtolower($target['name'])));
        $nameMatches = 0;
        foreach ($nameWords as $word) {
            if (mb_strlen($word) >= 3 && mb_strpos($queryLower, $word) !== false) {
                $nameMatches++;
            }
        }
        if ($nameMatches > 0 && $nameMatches >= ceil(count($nameWords) / 2)) {
            $matched = true;
        }

        // Match against city
        if (!$matched && !empty($target['city'])) {
            if (mb_strpos($queryLower, mb_strtolower($target['city'])) !== false) {
                $matched = true;
            }
        }

        // Match against neighbourhood
        if (!$matched && !empty($target['neighbourhood'])) {
            if (mb_strpos($queryLower, mb_strtolower($target['neighbourhood'])) !== false) {
                $matched = true;
            }
        }

        // Match against postcode prefix
        if (!$matched && !empty($target['postcode_prefix'])) {
            if (mb_strpos($queryLower, mb_strtolower($target['postcode_prefix'])) !== false) {
                $matched = true;
            }
        }

        if ($matched) {
            $weight = (int)($target['priority_weight'] ?? 100);
            if ($weight > $bestWeight) {
                $bestWeight = $weight;
                $bestMatch  = (int)$target['id'];
            }
        }
    }

    return $bestMatch;
}

/**
 * Score a recommendation based on multiple factors.
 * Returns ['score' => int, 'reason' => string].
 *
 * Scoring factors:
 *  - Impressions volume (higher = more opportunity)
 *  - CTR gap (low CTR on high impressions = optimization opportunity)
 *  - Position (striking distance 4-20 = highest value)
 *  - Target match bonus
 *  - Season bonus
 */
function scoreRecommendation(array $data, PDO $db): array
{
    $impressions = (int)($data['impressions'] ?? 0);
    $clicks      = (int)($data['clicks'] ?? 0);
    $ctr         = (float)($data['ctr'] ?? 0);
    $position    = (float)($data['position'] ?? 0);
    $hasPage     = (bool)($data['existing_page'] ?? false);
    $targetId    = $data['target_id'] ?? null;
    $seasonId    = $data['season_id'] ?? null;

    $score  = 0;
    $reasons = [];

    // 1. Impression volume (0-25 points)
    if ($impressions >= 500) {
        $score += 25;
        $reasons[] = 'High volume';
    } elseif ($impressions >= 200) {
        $score += 20;
        $reasons[] = 'Good volume';
    } elseif ($impressions >= 100) {
        $score += 15;
        $reasons[] = 'Moderate volume';
    } elseif ($impressions >= 50) {
        $score += 10;
        $reasons[] = 'Some volume';
    } else {
        $score += 5;
    }

    // 2. Position — striking distance is most valuable (0-30 points)
    if ($position >= 4 && $position <= 10) {
        $score += 30;
        $reasons[] = 'Striking distance (page 1 edge)';
    } elseif ($position >= 11 && $position <= 20) {
        $score += 25;
        $reasons[] = 'Page 2 — near page 1';
    } elseif ($position >= 1 && $position < 4) {
        $score += 10;
        $reasons[] = 'Already ranking well';
    } elseif ($position > 20 && $position <= 40) {
        $score += 15;
        $reasons[] = 'Moderate position';
    } else {
        $score += 5;
    }

    // 3. CTR gap — low CTR on good position = meta/content issue (0-20 points)
    if ($position <= 10 && $ctr < 0.02) {
        $score += 20;
        $reasons[] = 'Very low CTR for position';
    } elseif ($position <= 20 && $ctr < 0.01) {
        $score += 15;
        $reasons[] = 'Low CTR';
    } elseif ($ctr < 0.03) {
        $score += 10;
    }

    // 4. Target match bonus (0-15 points)
    if ($targetId) {
        $score += 15;
        $reasons[] = 'Matches SEO target';
    }

    // 5. Season bonus (0-10 points)
    if ($seasonId) {
        // Look up the season's priority_boost
        static $seasonBoosts = [];
        if (!isset($seasonBoosts[$seasonId])) {
            $stmt = $db->prepare("SELECT priority_boost FROM seo_seasons WHERE id = ?");
            $stmt->execute([$seasonId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $seasonBoosts[$seasonId] = (int)($row['priority_boost'] ?? 0);
        }
        $boost = min(10, (int)($seasonBoosts[$seasonId] / 10));
        $score += $boost;
        if ($boost > 0) {
            $reasons[] = 'Seasonal relevance';
        }
    }

    return [
        'score'  => min(100, $score),
        'reason' => implode('; ', $reasons)
    ];
}

/**
 * Select the recommendation type based on query data and scoring.
 */
function selectRecType(array $row, int $score, bool $hasExistingPage): string
{
    $position = (float)($row['avg_position'] ?? 0);
    $ctr      = (float)($row['avg_ctr'] ?? 0);

    if (!$hasExistingPage) {
        return 'content_new';
    }

    // Has existing page — what kind of optimization?
    if ($position <= 20 && $ctr < 0.02) {
        return 'meta_optimization';
    }

    if ($position > 20) {
        return 'content_refresh';
    }

    if ((int)($row['page_count'] ?? 1) > 1) {
        return 'internal_link';
    }

    return 'content_refresh';
}

/**
 * Generate a URL-friendly slug from a query string.
 * Optionally incorporates the target's canonical slug.
 */
function generateSlug(string $query, ?int $targetId, PDO $db): string
{
    // If there's a matching target with a canonical_slug, use it as a base
    if ($targetId) {
        static $targetSlugs = [];
        if (!isset($targetSlugs[$targetId])) {
            $stmt = $db->prepare("SELECT canonical_slug FROM seo_targets WHERE id = ?");
            $stmt->execute([$targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $targetSlugs[$targetId] = $row['canonical_slug'] ?? '';
        }
        if (!empty($targetSlugs[$targetId])) {
            return $targetSlugs[$targetId];
        }
    }

    // Generate from query text
    $slug = mb_strtolower(trim($query));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');

    // Cap length at 60 chars, break at word boundary
    if (strlen($slug) > 60) {
        $slug = substr($slug, 0, 60);
        $lastDash = strrpos($slug, '-');
        if ($lastDash > 30) {
            $slug = substr($slug, 0, $lastDash);
        }
    }

    return '/services/' . $slug;
}
