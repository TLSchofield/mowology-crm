<?php
/**
 * /app/Modules/Marketing/Api/apply.php
 * AJAX Endpoint: Apply a recommendation (create draft page)
 *
 * POST /crm/api/seo/apply.php
 * Data:
 *   recommendation_id: int
 *   csrf_token: string
 *
 * Response: {success: bool, draft_id: int, content: {...}}
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json');

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/seo-functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Admin access required']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'POST required']));
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
}

$recId = (int)($_POST['recommendation_id'] ?? 0);
if (!$recId) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'recommendation_id required']));
}

$db = getDB();

try {
    // Get recommendation
    $recStmt = $db->prepare("
        SELECT id, query_text, suggested_slug, rec_type, priority_score, target_id, season_id
        FROM seo_recommendations
        WHERE id = ?
    ");
    $recStmt->execute([$recId]);
    $rec = $recStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'Recommendation not found']));
    }

    // Get target if applicable
    $target = null;
    if ($rec['target_id']) {
        $targetStmt = $db->prepare("SELECT * FROM seo_targets WHERE id = ?");
        $targetStmt->execute([$rec['target_id']]);
        $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Generate content
    $seoContent = generateSEOContent($rec['query_text'], $rec['suggested_slug'], $target);
    $pageContent = generatePageContent($rec['query_text'], $target);
    $portolioImages = selectPortfolioImages($db, $target, $rec['query_text']);
    $schema = generateSchema($target ?? [], $seoContent['title'], "https://mowology.ca/" . $rec['suggested_slug'], [
        'phone' => '(778) 999-0000', // Get from settings
        'email' => 'office@mowology.ca'
    ]);

    // Build sections JSON
    $sections = [
        [
            'type' => 'hero',
            'heading' => $pageContent['h1'],
            'content' => $pageContent['intro_text'],
            'cta_text' => 'Get Free Quote',
            'cta_url' => '/quote?service=' . urlencode($rec['query_text'])
        ],
        [
            'type' => 'features',
            'heading' => 'Why Choose Mowology?',
            'items' => [
                'Professional service in ' . ($target['city'] ?? 'your area'),
                'Free, no-obligation quotes',
                '25+ years of experience',
                'Fully licensed and insured'
            ]
        ],
        [
            'type' => 'portfolio',
            'heading' => 'Recent Projects',
            'images' => array_map(fn($img) => [
                'id' => $img['id'],
                'url' => $img['web_path'],
                'alt' => $img['alt_text'] ?? 'Before and after project'
            ], array_slice($portolioImages, 0, 6))
        ]
    ];

    // Build internal links JSON
    $internalLinks = [
        ['anchor' => 'Home', 'url' => '/', 'context' => 'Primary navigation'],
        ['anchor' => 'Services', 'url' => '/services', 'context' => 'Service hub'],
        ['anchor' => 'Schedule Service', 'url' => '/quote', 'context' => 'Call to action']
    ];

    if ($target && $target['target_type'] === 'city') {
        $internalLinks[] = [
            'anchor' => ucfirst($target['city']) . ' Services',
            'url' => '/services/' . $target['canonical_slug'],
            'context' => 'Location-specific services'
        ];
    }

    // Create draft page
    $draftStmt = $db->prepare("
        INSERT INTO seo_page_drafts
        (recommendation_id, slug, title, meta_description, h1, intro_text, sections_json, internal_links_json, images_json, schema_json, target_id, season_id, status, seo_score, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 75, ?)
    ");

    $draftStmt->execute([
        $recId,
        $rec['suggested_slug'],
        $seoContent['title'],
        $seoContent['meta_description'],
        $pageContent['h1'],
        $pageContent['intro_text'],
        json_encode($sections),
        json_encode($internalLinks),
        json_encode(array_slice($portolioImages, 0, 6)),
        $schema,
        $rec['target_id'],
        $rec['season_id'],
        $user['id']
    ]);

    $draftId = $db->lastInsertId();

    // Update recommendation status
    $updateStmt = $db->prepare("
        UPDATE seo_recommendations
        SET status = 'applied', applied_at = NOW(), applied_by = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$user['id'], $recId]);

    // Log action
    logRecommendationAction(
        $recId,
        'applied',
        $user['id'],
        'new',
        'applied',
        'Draft page generated',
        $_SERVER['REMOTE_ADDR'] ?? null,
        $db
    );

    // Response
    http_response_code(200);
    die(json_encode([
        'success' => true,
        'message' => 'Draft page created successfully',
        'draft_id' => $draftId,
        'content' => [
            'title' => $seoContent['title'],
            'meta_description' => $seoContent['meta_description'],
            'h1' => $pageContent['h1'],
            'slug' => $rec['suggested_slug'],
            'sections_count' => count($sections),
            'images_count' => count($portolioImages),
            'seo_score' => 75
        ]
    ]));

} catch (Throwable $e) {
    error_log("SEO Apply Error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Failed to apply recommendation: ' . $e->getMessage()
    ]));
}
