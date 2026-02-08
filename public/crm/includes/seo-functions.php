<?php
/**
 * /crm/includes/seo-functions.php
 * SEO Recommendations Scoring Engine & Utilities
 *
 * Handles:
 * - Query scoring (impressions, position, CTR, seasonality, targets)
 * - Recommendation type selection
 * - Slug generation
 * - Content generation helpers
 * - Target/season matching logic
 */

declare(strict_types=1);

/**
 * Main scoring engine: calculates priority score for a GSC query
 *
 * @param array $queryData {
 *   query: string,
 *   impressions: int,
 *   clicks: int,
 *   ctr: float (0-1),
 *   position: float,
 *   existing_page: bool (does a matching page exist?),
 *   target_id: int|null (matched seo_target id),
 *   season_id: int|null (current active season)
 * }
 * @param PDO $db Database connection
 * @return array {score: float, reasoning: string}
 */
function scoreRecommendation(array $queryData, PDO $db): array {
    $score = 0;
    $reasons = [];

    $query = $queryData['query'] ?? '';
    $impressions = (int)($queryData['impressions'] ?? 0);
    $clicks = (int)($queryData['clicks'] ?? 0);
    $ctr = (float)($queryData['ctr'] ?? 0);
    $position = (float)($queryData['position'] ?? 999);
    $existingPage = (bool)($queryData['existing_page'] ?? false);
    $targetId = isset($queryData['target_id']) ? (int)$queryData['target_id'] : null;
    $seasonId = isset($queryData['season_id']) ? (int)$queryData['season_id'] : null;

    // ========== BASELINE: IMPRESSIONS (Search Volume) ==========
    if ($impressions >= 100) {
        $score += 35;
        $reasons[] = "High search volume (100+ impr)";
    } elseif ($impressions >= 50) {
        $score += 25;
        $reasons[] = "Moderate search volume (50+ impr)";
    } elseif ($impressions >= 15) {
        $score += 20;
        $reasons[] = "Baseline search volume (15+ impr)";
    } else {
        // Skip recommendations below 15 impressions (too niche)
        return ['score' => 0, 'reason' => 'Insufficient search volume'];
    }

    // ========== POSITION-BASED OPPORTUNITY ==========
    if ($position >= 1 && $position <= 7) {
        $score += 5; // Already ranking well
        $reasons[] = "Strong position ($position)";
    } elseif ($position >= 8 && $position <= 20) {
        $score += 15; // "Near win" — close to first page
        $reasons[] = "Near-win position ($position) — optimize for Page 1";
    } elseif ($position >= 21 && $position <= 40) {
        $score += 25; // "Build" opportunity — needs more work
        $reasons[] = "Build opportunity ($position) — create new page";
    } elseif ($position > 40) {
        $score += 5; // Not currently ranking well
        $reasons[] = "Low position ($position)";
    }

    // ========== CTR SIGNAL ==========
    if ($clicks === 0 && $impressions >= 15) {
        $score += 30; // CRITICAL: query appears but gets no clicks
        $reasons[] = "Zero clicks despite impressions — missing or poor snippet";
    } elseif ($ctr < 0.01 && $position <= 10) {
        $score += 20; // Low CTR at high rank — title/meta issue
        $reasons[] = "Low CTR at high position — improve title/meta";
    } elseif ($ctr < 0.02 && $position <= 5) {
        $score += 10; // Even at top positions, CTR is weak
        $reasons[] = "Low CTR even at top rank";
    }

    // ========== LOCAL INTENT KEYWORDS ==========
    $localIntentBoost = 0;
    $localKeywords = ['vancouver', 'burnaby', 'richmond', 'delta', 'new west', 'surrey', 'coquitlam',
                      'near me', 'local', 'closest', 'near', 'nearby'];
    foreach ($localKeywords as $keyword) {
        if (stripos($query, $keyword) !== false) {
            $localIntentBoost = 15;
            $reasons[] = "Local intent keyword: '$keyword'";
            break;
        }
    }
    $score += $localIntentBoost;

    // ========== POSTCODE PATTERN ==========
    $postcodePattern = '/\b(V\d[A-Z]\s?\d[A-Z]\d|V\d[A-Z])\b/i';
    if (preg_match($postcodePattern, $query)) {
        $score += 10;
        $reasons[] = "Postcode-specific query";
    }

    // ========== NEIGHBOURHOOD KEYWORDS ==========
    $neighbourhoods = ['kitsilano', 'mount pleasant', 'yaletown', 'broadway', 'oak street', 'maplewoods',
                      'south van', 'east van', 'north shore', 'hastings', 'commercial drive'];
    foreach ($neighbourhoods as $hood) {
        if (stripos($query, $hood) !== false) {
            $score += 10;
            $reasons[] = "Neighbourhood match: '$hood'";
            break;
        }
    }

    // ========== TARGET MATCHING ==========
    if ($targetId) {
        $targetStmt = $db->prepare("SELECT target_type, priority_weight FROM seo_targets WHERE id = ? AND is_active = 1");
        $targetStmt->execute([$targetId]);
        $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

        if ($target) {
            $weightBoost = (int)$target['priority_weight'];
            $targetBoost = match($target['target_type']) {
                'city' => 20,
                'postcode' => 15,
                'neighbourhood' => 10,
                default => 0
            };

            $targetBoost = (int)(($targetBoost * $weightBoost) / 100);
            $score += $targetBoost;
            $reasons[] = "Target match boost (+{$targetBoost}% weight)";
        }
    }

    // ========== SEASONAL BOOST ==========
    if ($seasonId) {
        $seasonStmt = $db->prepare("SELECT priority_boost, label FROM seo_seasons WHERE id = ? AND is_active = 1");
        $seasonStmt->execute([$seasonId]);
        $season = $seasonStmt->fetch(PDO::FETCH_ASSOC);

        if ($season) {
            $seasonBoost = (int)$season['priority_boost'];
            $score += $seasonBoost;
            $reasons[] = "Seasonal boost (+{$seasonBoost}% — {$season['label']})";
        }
    }

    // ========== BUSINESS VALUE WEIGHTING ==========
    $businessKeywords = ['strata', 'commercial', 'property manager', 'hoa', 'condo', 'building', 'corporate'];
    foreach ($businessKeywords as $keyword) {
        if (stripos($query, $keyword) !== false) {
            $score += 10;
            $reasons[] = "Business value keyword: '$keyword'";
            break;
        }
    }

    // ========== CAP AT 100 ==========
    $score = min((float)$score, 100.0);

    return [
        'score' => $score,
        'reason' => implode('; ', $reasons)
    ];
}

/**
 * Determine recommendation type based on query data and scoring
 *
 * @param array $queryData Query stats
 * @param float $score Calculated priority score
 * @param bool $existingPage Whether a matching page already exists
 * @return string Recommendation type
 */
function selectRecType(array $queryData, float $score, bool $existingPage = false): string {
    $position = (float)($queryData['position'] ?? 999);
    $impressions = (int)($queryData['impressions'] ?? 0);
    $ctr = (float)($queryData['ctr'] ?? 0);

    // Ranking too well already — minor optimization only
    if ($position >= 1 && $position <= 7) {
        return 'title_meta';
    }

    // Exists but low performance — improve it
    if ($existingPage && $position >= 8 && $position <= 20 && $ctr < 0.02) {
        return 'improve_page';
    }

    // High impressions, no clicks, doesn't rank — need schema/snippet help
    if ($queryData['clicks'] === 0 && $impressions >= 50) {
        return 'schema';
    }

    // Doesn't rank well, no page — create new landing page
    if (!$existingPage && $position >= 21 && $position <= 40 && $impressions >= 30) {
        return 'create_page';
    }

    // Rank 6-15 with high impressions — internal linking opportunity
    if ($position >= 6 && $position <= 15 && $impressions >= 50) {
        return 'internal_links';
    }

    // Query type matches a service — consider portfolio images
    if (stripos($queryData['query'] ?? '', 'before') !== false ||
        stripos($queryData['query'] ?? '', 'after') !== false ||
        stripos($queryData['query'] ?? '', 'portfolio') !== false) {
        return 'add_photos';
    }

    // Default: create_page if high enough score
    if ($score >= 50 && !$existingPage) {
        return 'create_page';
    }

    return 'title_meta';
}

/**
 * Generate a URL-friendly slug from query + target
 *
 * Pattern: {service}-{target}
 * Examples: spring-cleanup-vancouver, strata-maintenance-v5k
 *
 * @param string $query Search query
 * @param int|null $targetId seo_target id
 * @param PDO $db Database connection
 * @return string Slug (kebab-case)
 */
function generateSlug(string $query, ?int $targetId, PDO $db): string {
    // Extract service type (first noun-like word or longest word)
    $words = preg_split('/\s+/', trim($query));
    $serviceWords = [];

    foreach ($words as $word) {
        // Skip small words and postcode patterns
        if (strlen($word) >= 4 && !preg_match('/^V\d/', $word)) {
            $serviceWords[] = strtolower($word);
        }
    }

    // Use first 2 words as service hint
    $servicePart = implode('-', array_slice($serviceWords, 0, 2)) ?: 'service';

    // Get target slug if applicable
    $targetPart = '';
    if ($targetId) {
        $stmt = $db->prepare("SELECT canonical_slug FROM seo_targets WHERE id = ? LIMIT 1");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        $targetPart = $target ? $target['canonical_slug'] : '';
    }

    // Combine: service-target or just service
    $slug = $targetPart ? "$servicePart-$targetPart" : $servicePart;

    // Clean up: lowercase, remove special chars, collapse dashes
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
    $slug = preg_replace('/\-+/', '-', $slug);
    $slug = trim($slug, '-');

    return $slug;
}

/**
 * Detect current active season
 *
 * @param PDO $db Database connection
 * @return array|null {id, season_key, label} or null if no season active
 */
function getCurrentSeason(PDO $db): ?array {
    $now = new DateTime();
    $month = (int)$now->format('n'); // 1-12
    $day = (int)$now->format('d');

    $stmt = $db->prepare("
        SELECT id, season_key, label
        FROM seo_seasons
        WHERE is_active = 1
        AND (
            (start_month < end_month AND month = ? AND day >= start_day AND month < end_month)
            OR (start_month < end_month AND month > start_month AND month < end_month)
            OR (start_month < end_month AND month = end_month AND day <= end_day)
            OR (start_month > end_month AND (month >= start_month OR month <= end_month))
        )
        ORDER BY priority_boost DESC
        LIMIT 1
    ");

    // Simpler logic: check if month is in range
    $stmt = $db->prepare("
        SELECT id, season_key, label
        FROM seo_seasons
        WHERE is_active = 1
        AND (
            (start_month <= end_month AND ? >= start_month AND ? <= end_month)
            OR (start_month > end_month AND (? >= start_month OR ? <= end_month))
        )
        ORDER BY priority_boost DESC
        LIMIT 1
    ");
    $stmt->execute([$month, $month, $month, $month]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Detect matching target(s) from query text
 *
 * @param string $query Search query
 * @param PDO $db Database connection
 * @return int|null First matching seo_target id
 */
function detectMatchingTarget(string $query, PDO $db): ?int {
    // Try city match
    $stmt = $db->prepare("
        SELECT id FROM seo_targets
        WHERE is_active = 1 AND target_type = 'city'
        AND (city LIKE CONCAT('%', ?, '%') OR canonical_slug = ?)
        LIMIT 1
    ");
    $queryLower = strtolower($query);
    $stmt->execute([$queryLower, $queryLower]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($match) return (int)$match['id'];

    // Try postcode match
    if (preg_match('/\b(V\d[A-Z])\b/i', $query, $m)) {
        $postcode = strtoupper($m[1]);
        $stmt = $db->prepare("
            SELECT id FROM seo_targets
            WHERE is_active = 1 AND target_type = 'postcode'
            AND postcode_prefix = ?
            LIMIT 1
        ");
        $stmt->execute([$postcode]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($match) return (int)$match['id'];
    }

    // Try neighbourhood match
    $stmt = $db->prepare("
        SELECT id FROM seo_targets
        WHERE is_active = 1 AND target_type = 'neighbourhood'
        AND neighbourhood LIKE CONCAT('%', ?, '%')
        LIMIT 1
    ");
    $stmt->execute([$queryLower]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($match) return (int)$match['id'];

    return null;
}

/**
 * Generate SEO title + meta description
 *
 * @param string $query Search query
 * @param string $slug Suggested URL slug
 * @param array $target seo_target row (optional)
 * @return array {title, meta_description}
 */
function generateSEOContent(string $query, string $slug, ?array $target = null): array {
    // Simple title: capitalize query + target if available
    $titleParts = [];
    $titleParts[] = ucwords($query);

    if ($target && $target['city']) {
        $titleParts[] = "in {$target['city']}";
    }

    $title = implode(' ', $titleParts);
    $title = substr($title, 0, 60); // SEO best practice

    // Meta: longer description with CTA
    $metaParts = [];
    $metaParts[] = "Professional " . strtolower($query);

    if ($target) {
        if ($target['target_type'] === 'city') {
            $metaParts[] = "services in {$target['city']}";
        } elseif ($target['target_type'] === 'postcode') {
            $metaParts[] = "in postal code {$target['postcode_prefix']}";
        } elseif ($target['target_type'] === 'neighbourhood') {
            $metaParts[] = "in {$target['neighbourhood']}, Vancouver";
        }
    }

    $metaParts[] = "Get a free quote today!";
    $meta = implode(' ', $metaParts);
    $meta = substr($meta, 0, 160); // Google display limit

    return [
        'title' => $title,
        'meta_description' => $meta
    ];
}

/**
 * Generate H1 + opening paragraph
 *
 * @param string $query Search query
 * @param array $target seo_target row (optional)
 * @return array {h1, intro_text}
 */
function generatePageContent(string $query, ?array $target = null): array {
    $h1 = "Expert " . ucfirst($query);
    if ($target && $target['city']) {
        $h1 .= " in " . $target['city'];
    }

    $intro = "Looking for professional " . strtolower($query) . "?";
    if ($target) {
        if ($target['target_type'] === 'city') {
            $intro .= " We serve " . $target['city'] . " and surrounding areas.";
        } elseif ($target['target_type'] === 'neighbourhood') {
            $intro .= " We're proud to serve the " . $target['neighbourhood'] . " community.";
        }
    }
    $intro .= " Our experienced team is ready to help with a free, no-obligation quote.";

    return [
        'h1' => $h1,
        'intro_text' => $intro
    ];
}

/**
 * Select portfolio images for a recommendation
 * Filters by service type/city/postcode when possible
 *
 * @param array $target seo_target row (optional)
 * @param string $query Search query (for service matching)
 * @param PDO $db Database connection
 * @param int $limit How many to select (default 6)
 * @return array Array of media_files rows
 */
function selectPortfolioImages(PDO $db, ?array $target = null, string $query = '', int $limit = 6): array {
    // Query favorite media with recent/ready status
    $sql = "
        SELECT id, web_path, thumb_path, title, alt_text
        FROM media_files
        WHERE is_favorite = 1
        AND status = 'ready'
        AND is_public = 1 OR privacy_level IN ('internal', 'public')
        ORDER BY uploaded_at DESC
        LIMIT " . (int)$limit;

    $stmt = $db->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Generate JSON-LD LocalBusiness schema
 *
 * @param array $target seo_target row
 * @param string $title Page title
 * @param string $url Absolute URL of page
 * @param array $contact Optional {phone, email, address}
 * @return string JSON-LD as string
 */
function generateSchema(array $target, string $title, string $url, ?array $contact = null): string {
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => $title,
        'url' => $url,
        'areaServed' => [],
    ];

    // Add service area
    if ($target['target_type'] === 'city' && $target['city']) {
        $schema['areaServed'][] = $target['city'];
    } elseif ($target['target_type'] === 'neighbourhood' && $target['neighbourhood']) {
        $schema['areaServed'][] = $target['neighbourhood'] . ', Vancouver, BC';
    } elseif ($target['target_type'] === 'postcode' && $target['postcode_prefix']) {
        $schema['areaServed'][] = $target['postcode_prefix'] . " area";
    }

    // Add contact if provided
    if ($contact) {
        if (isset($contact['phone'])) {
            $schema['telephone'] = $contact['phone'];
        }
        if (isset($contact['email'])) {
            $schema['email'] = $contact['email'];
        }
        if (isset($contact['address'])) {
            $schema['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $contact['address']['street'] ?? '',
                'addressLocality' => $contact['address']['city'] ?? '',
                'addressRegion' => 'BC',
                'postalCode' => $contact['address']['postal'] ?? '',
            ];
        }
    }

    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Log recommendation action to audit table
 *
 * @param int $recId Recommendation ID
 * @param string $action Action type (generated, accepted, applied, done, ignored)
 * @param int|null $userId User ID
 * @param string|null $oldStatus Previous status
 * @param string|null $newStatus New status
 * @param string|null $notes User notes
 * @param string|null $ipAddress IP address
 * @param PDO $db Database connection
 * @return bool Success
 */
function logRecommendationAction(
    int $recId,
    string $action,
    ?int $userId,
    ?string $oldStatus = null,
    ?string $newStatus = null,
    ?string $notes = null,
    ?string $ipAddress = null,
    PDO $db = null
): bool {
    if (!$db) {
        $db = getDB();
    }

    $stmt = $db->prepare("
        INSERT INTO seo_recommendations_audit
        (recommendation_id, action, user_id, old_status, new_status, notes, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    return $stmt->execute([
        $recId,
        $action,
        $userId,
        $oldStatus,
        $newStatus,
        $notes,
        $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null)
    ]);
}
