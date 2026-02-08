<?php
/**
 * /crm/portfolio/recommendations-data.php
 * Data provider for Recommendations tab
 *
 * Returns filtered/paginated recommendation data with related stats
 * Included by index.php when tab === 'recommendations'
 */

declare(strict_types=1);

requireLogin();
$user = getCurrentUser();

// Only admins can view/manage recommendations
if (!$user || $user['role'] !== 'admin') {
    die(json_encode(['error' => 'Admin access required']));
}

$db = getDB();

// Parse filters from query params
$filterStatus = $_GET['status'] ?? '';
$filterType = $_GET['rec_type'] ?? '';
$filterTargetId = isset($_GET['target_id']) ? (int)$_GET['target_id'] : null;
$filterSeasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : null;
$sortBy = $_GET['sort'] ?? 'priority_score';
$sortDir = $_GET['dir'] ?? 'DESC';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Validate sort to prevent injection
$allowedSorts = ['priority_score', 'query_text', 'search_volume', 'avg_position', 'status', 'created_at'];
$sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'priority_score';
$sortDir = in_array(strtoupper($sortDir), ['ASC', 'DESC']) ? strtoupper($sortDir) : 'DESC';

// Build WHERE clause
$whereConditions = [];
$params = [];

if ($filterStatus) {
    $whereConditions[] = "sr.status = ?";
    $params[] = $filterStatus;
}

if ($filterType) {
    $whereConditions[] = "sr.rec_type = ?";
    $params[] = $filterType;
}

if ($filterTargetId) {
    $whereConditions[] = "sr.target_id = ?";
    $params[] = $filterTargetId;
}

if ($filterSeasonId) {
    $whereConditions[] = "sr.season_id = ?";
    $params[] = $filterSeasonId;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Query recommendations with related data
$sql = "
    SELECT
        sr.id,
        sr.query_text,
        sr.search_volume as impressions,
        sr.clicks,
        sr.ctr,
        sr.avg_position,
        sr.suggested_slug,
        sr.rec_type,
        sr.priority_score,
        sr.status,
        sr.target_id,
        sr.season_id,
        sr.reason,
        sr.created_at,
        sr.updated_at,
        st.name as target_name,
        st.target_type,
        st.canonical_slug as target_slug,
        ss.label as season_label,
        COUNT(*) OVER() as total_count
    FROM seo_recommendations sr
    LEFT JOIN seo_targets st ON sr.target_id = st.id
    LEFT JOIN seo_seasons ss ON sr.season_id = ss.id
    $whereClause
    ORDER BY sr.$sortBy $sortDir
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count from first row (window function)
$totalCount = !empty($recommendations) ? (int)$recommendations[0]['total_count'] : 0;
$totalPages = ceil($totalCount / $perPage);

// Get all targets for filter dropdown
$targetsStmt = $db->query("SELECT id, name, target_type FROM seo_targets WHERE is_active = 1 ORDER BY target_type, name");
$targets = $targetsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all seasons for filter dropdown
$seasonsStmt = $db->query("SELECT id, season_key, label FROM seo_seasons WHERE is_active = 1 ORDER BY priority_boost DESC");
$seasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

// Return data structure
return [
    'recommendations' => $recommendations,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total_count' => $totalCount,
        'total_pages' => $totalPages
    ],
    'filters' => [
        'status' => $filterStatus,
        'rec_type' => $filterType,
        'target_id' => $filterTargetId,
        'season_id' => $filterSeasonId
    ],
    'sort' => [
        'by' => $sortBy,
        'dir' => $sortDir
    ],
    'targets' => $targets,
    'seasons' => $seasons
];
