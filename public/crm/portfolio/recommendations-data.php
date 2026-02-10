<?php
/**
 * /crm/portfolio/recommendations-data.php
 * Data provider for Recommendations tab
 *
 * Returns filtered/paginated recommendation data with related stats
 * Included by index.php when tab === 'recommendations'
 */

declare(strict_types=1);

/**
 * This file is included by index.php and must RETURN an array
 * Expects $user and $db to be available from parent scope
 * index.php has already called requireLogin() and set $user
 */

// Verify admin access (using parent scope variables)
// Note: $user and $db are passed from index.php context via include
if (empty($user) || $user['role'] !== 'admin') {
    return [
        'recommendations' => [],
        'pagination' => ['page' => 1, 'per_page' => 25, 'total_count' => 0, 'total_pages' => 0],
        'filters' => [],
        'sort' => ['by' => 'priority_score', 'dir' => 'DESC'],
        'targets' => [],
        'seasons' => []
    ];
}

// $db is already available from parent scope (index.php)

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

// Get total count FIRST (MySQL 5.7 compatible - no window functions)
$countSql = "SELECT COUNT(*) as total FROM seo_recommendations sr";
if (!empty($whereConditions)) {
    $countSql .= " WHERE " . implode(' AND ', $whereConditions);
}

try {
    $countStmt = $db->prepare($countSql);
    if (!$countStmt) {
        error_log('Failed to prepare count query');
        $totalCount = 0;
    } else {
        $countStmt->execute($params);
        $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $totalCount = (int)($countResult['total'] ?? 0);
    }
} catch (PDOException $e) {
    error_log('PDO Exception in count query: ' . $e->getMessage());
    error_log('Count SQL: ' . $countSql);
    $totalCount = 0;
}

// Query recommendations with related data (MySQL 5.7 compatible - no window functions)
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
        ss.label as season_label
    FROM seo_recommendations sr
    LEFT JOIN seo_targets st ON sr.target_id = st.id
    LEFT JOIN seo_seasons ss ON sr.season_id = ss.id
    $whereClause
    ORDER BY sr.{$sortBy} {$sortDir}
    LIMIT {$perPage} OFFSET {$offset}
";

try {
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('Failed to prepare recommendations query');
        $recommendations = [];
    } else {
        $executeSuccess = $stmt->execute($params);
        if (!$executeSuccess) {
            error_log('Failed to execute recommendations query: ' . json_encode($stmt->errorInfo()));
            $recommendations = [];
        } else {
            $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
} catch (PDOException $e) {
    error_log('PDO Exception in recommendations query: ' . $e->getMessage());
    error_log('SQL: ' . $sql);
    error_log('Params: ' . json_encode($params));
    $recommendations = [];
}
$totalPages = ceil($totalCount / $perPage);

// Get all targets for filter dropdown
$targetsStmt = $db->query("SELECT id, name, target_type FROM seo_targets WHERE is_active = 1 ORDER BY target_type, name");
$targets = $targetsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all seasons for filter dropdown
$seasonsStmt = $db->query("SELECT id, season_key, label FROM seo_seasons WHERE is_active = 1 ORDER BY priority_boost DESC");
$seasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

// If no filters applied and no page specified, auto-load top 10 by priority_score
$isDefaultView = empty($filterStatus) && empty($filterType) && empty($filterTargetId) && empty($filterSeasonId) && $page === 1;
if ($isDefaultView && empty($recommendations)) {
    // Try to load top 10 recommendations sorted by priority_score DESC
    try {
        $topTenSql = "
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
                ss.label as season_label
            FROM seo_recommendations sr
            LEFT JOIN seo_targets st ON sr.target_id = st.id
            LEFT JOIN seo_seasons ss ON sr.season_id = ss.id
            ORDER BY sr.priority_score DESC, sr.created_at DESC
            LIMIT 10
        ";
        $topTenStmt = $db->prepare($topTenSql);
        if ($topTenStmt) {
            $topTenStmt->execute();
            $recommendations = $topTenStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $e) {
        // Silent fail - recommendations remain empty
        error_log('Failed to load top 10 recommendations: ' . $e->getMessage());
    }
}

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
