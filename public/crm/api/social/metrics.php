<?php
/**
 * Social Metrics API
 *
 * Actions:
 *   dashboard   — KPI tiles + summary stats for the social dashboard
 *   post-stats  — detailed stats for a single post across all platforms
 *   top-posts   — top performing posts by engagement
 *   by-platform — aggregate metrics grouped by platform
 *   by-template — which templates generate the most engagement
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();
session_write_close();
requirePermission('marketing.view');

$db     = getDB();
$action = $_GET['action'] ?? 'dashboard';

try {
    switch ($action) {

        // ── Dashboard KPIs ───────────────────────────────────────────
        case 'dashboard': {
            $monthStart = date('Y-m-01 00:00:00');
            $monthEnd   = date('Y-m-t 23:59:59');

            // Posts this month
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM social_posts
                WHERE created_at BETWEEN ? AND ?
                  AND status != 'cancelled'
            ");
            $stmt->execute([$monthStart, $monthEnd]);
            $postsThisMonth = (int)$stmt->fetchColumn();

            // Published this month
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM social_posts
                WHERE published_at BETWEEN ? AND ?
                  AND status = 'published'
            ");
            $stmt->execute([$monthStart, $monthEnd]);
            $publishedThisMonth = (int)$stmt->fetchColumn();

            // Pending approval
            $stmt = $db->query("SELECT COUNT(*) FROM social_posts WHERE status = 'pending_approval'");
            $pendingApproval = (int)$stmt->fetchColumn();

            // Failed posts
            $stmt = $db->query("SELECT COUNT(*) FROM social_posts WHERE status = 'failed'");
            $failedPosts = (int)$stmt->fetchColumn();

            // Total engagement this month (from metrics table)
            $stmt = $db->prepare("
                SELECT
                    COALESCE(SUM(smd.impressions), 0)    AS total_impressions,
                    COALESCE(SUM(smd.clicks), 0)         AS total_clicks,
                    COALESCE(SUM(smd.likes), 0)          AS total_likes,
                    COALESCE(SUM(smd.comments_count), 0) AS total_comments,
                    COALESCE(SUM(smd.reach), 0)          AS total_reach
                FROM social_metrics_daily smd
                WHERE smd.metric_date BETWEEN DATE(?) AND DATE(?)
            ");
            $stmt->execute([$monthStart, $monthEnd]);
            $engagement = $stmt->fetch(PDO::FETCH_ASSOC);

            // Avg engagement per published post
            $avgEngagement = 0;
            if ($publishedThisMonth > 0) {
                $totalEng = ($engagement['total_likes'] ?? 0)
                          + ($engagement['total_comments'] ?? 0)
                          + ($engagement['total_clicks'] ?? 0);
                $avgEngagement = round($totalEng / $publishedThisMonth, 1);
            }

            // Scheduled (upcoming)
            $stmt = $db->query("
                SELECT COUNT(*) FROM social_posts
                WHERE status = 'scheduled' AND scheduled_at > NOW()
            ");
            $upcomingScheduled = (int)$stmt->fetchColumn();

            // Connected accounts
            $stmt = $db->query("SELECT COUNT(*) FROM social_accounts WHERE is_active = 1");
            $connectedAccounts = (int)$stmt->fetchColumn();

            // ── Previous month comparisons ───────────────────────────
            $prevMonthStart = date('Y-m-01 00:00:00', strtotime('first day of last month'));
            $prevMonthEnd   = date('Y-m-t 23:59:59',  strtotime('last day of last month'));

            $stmt = $db->prepare("
                SELECT COUNT(*) FROM social_posts
                WHERE published_at BETWEEN ? AND ? AND status = 'published'
            ");
            $stmt->execute([$prevMonthStart, $prevMonthEnd]);
            $prevPublished = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT
                    COALESCE(SUM(smd.impressions), 0) AS prev_impressions,
                    COALESCE(SUM(smd.reach),       0) AS prev_reach,
                    COALESCE(SUM(smd.likes),       0) AS prev_likes
                FROM social_metrics_daily smd
                WHERE smd.metric_date BETWEEN DATE(?) AND DATE(?)
            ");
            $stmt->execute([$prevMonthStart, $prevMonthEnd]);
            $prevEng = $stmt->fetch(PDO::FETCH_ASSOC);

            // ── Activity pulse: current week Sun→Sat ─────────────────
            $dayOfWeek = (int)date('w'); // 0=Sun, 6=Sat
            $weekSunday = date('Y-m-d', strtotime("-{$dayOfWeek} days"));
            $pulseRows = [];
            for ($i = 0; $i < 7; $i++) {
                $pulseRows[] = date('Y-m-d', strtotime("{$weekSunday} +{$i} days"));
            }
            $pulsePlaceholders = implode(',', array_fill(0, 7, '?'));

            $pubStmt = $db->prepare("
                SELECT DATE(published_at) AS dy, COUNT(*) AS cnt
                FROM social_posts
                WHERE DATE(published_at) IN ({$pulsePlaceholders}) AND status = 'published'
                GROUP BY DATE(published_at)
            ");
            $pubStmt->execute($pulseRows);
            $pubByDate = [];
            foreach ($pubStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pubByDate[$r['dy']] = (int)$r['cnt'];
            }

            $schStmt = $db->prepare("
                SELECT DATE(scheduled_at) AS dy, COUNT(*) AS cnt
                FROM social_posts
                WHERE DATE(scheduled_at) IN ({$pulsePlaceholders}) AND status IN ('scheduled','approved')
                GROUP BY DATE(scheduled_at)
            ");
            $schStmt->execute($pulseRows);
            $schByDate = [];
            foreach ($schStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $schByDate[$r['dy']] = (int)$r['cnt'];
            }

            $activityPulse = [];
            foreach ($pulseRows as $d) {
                $activityPulse[] = [
                    'date'      => $d,
                    'published' => isset($pubByDate[$d]) ? $pubByDate[$d] : 0,
                    'scheduled' => isset($schByDate[$d]) ? $schByDate[$d] : 0,
                ];
            }

            echo json_encode([
                'success' => true,
                'stats' => [
                    'posts_this_month'    => $postsThisMonth,
                    'published_month'     => $publishedThisMonth,
                    'pending_approval'    => $pendingApproval,
                    'failed_posts'        => $failedPosts,
                    'avg_engagement'      => $avgEngagement,
                    'upcoming_scheduled'  => $upcomingScheduled,
                    'connected_accounts'  => $connectedAccounts,
                    'total_impressions'   => (int)($engagement['total_impressions'] ?? 0),
                    'total_clicks'        => (int)($engagement['total_clicks']      ?? 0),
                    'total_likes'         => (int)($engagement['total_likes']       ?? 0),
                    'total_reach'         => (int)($engagement['total_reach']       ?? 0),
                    'prev_published'      => $prevPublished,
                    'prev_impressions'    => (int)($prevEng['prev_impressions'] ?? 0),
                    'prev_reach'          => (int)($prevEng['prev_reach']       ?? 0),
                    'prev_likes'          => (int)($prevEng['prev_likes']       ?? 0),
                ],
                'activity_pulse' => $activityPulse,
            ]);
            break;
        }

        // ── Top performing posts ─────────────────────────────────────
        case 'top-posts': {
            $limit = min(20, (int)($_GET['limit'] ?? 10));
            $stmt  = $db->prepare("
                SELECT sp.id, sp.title, sp.caption, sp.published_at,
                       sp.neighborhood, sp.service_type,
                       COALESCE(SUM(smd.impressions), 0)     AS total_impressions,
                       COALESCE(SUM(smd.clicks), 0)          AS total_clicks,
                       COALESCE(SUM(smd.likes), 0)           AS total_likes,
                       COALESCE(SUM(smd.comments_count), 0)  AS total_comments,
                       (COALESCE(SUM(smd.likes), 0) + COALESCE(SUM(smd.comments_count), 0)
                        + COALESCE(SUM(smd.clicks), 0))      AS total_engagement,
                       (SELECT GROUP_CONCAT(platform ORDER BY platform SEPARATOR ',')
                        FROM social_post_platforms WHERE post_id = sp.id) AS platforms_csv
                FROM social_posts sp
                LEFT JOIN social_post_platforms spp ON spp.post_id = sp.id
                LEFT JOIN social_metrics_daily smd   ON smd.post_platform_id = spp.id
                WHERE sp.status = 'published'
                GROUP BY sp.id
                ORDER BY total_engagement DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($posts as &$p) {
                $p['platforms'] = !empty($p['platforms_csv']) ? explode(',', $p['platforms_csv']) : [];
                unset($p['platforms_csv']);
            }
            unset($p);

            echo json_encode(['success' => true, 'posts' => $posts]);
            break;
        }

        // ── By platform ──────────────────────────────────────────────
        case 'by-platform': {
            $stmt = $db->query("
                SELECT spp.platform,
                       COUNT(DISTINCT sp.id)             AS post_count,
                       COALESCE(SUM(smd.impressions), 0) AS total_impressions,
                       COALESCE(SUM(smd.clicks), 0)      AS total_clicks,
                       COALESCE(SUM(smd.likes), 0)       AS total_likes
                FROM social_post_platforms spp
                JOIN social_posts sp ON sp.id = spp.post_id AND sp.status = 'published'
                LEFT JOIN social_metrics_daily smd ON smd.post_platform_id = spp.id
                GROUP BY spp.platform
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'by_platform' => $data]);
            break;
        }

        // ── By template ──────────────────────────────────────────────
        case 'by-template': {
            $stmt = $db->query("
                SELECT st.id, st.name, st.category, st.usage_count,
                       COUNT(DISTINCT sp.id)             AS published_count,
                       COALESCE(SUM(smd.likes), 0)       AS total_likes,
                       COALESCE(SUM(smd.clicks), 0)      AS total_clicks
                FROM social_templates st
                LEFT JOIN social_posts sp ON sp.template_id = st.id AND sp.status = 'published'
                LEFT JOIN social_post_platforms spp ON spp.post_id = sp.id
                LEFT JOIN social_metrics_daily smd ON smd.post_platform_id = spp.id
                WHERE st.is_active = 1
                GROUP BY st.id
                ORDER BY total_likes DESC, published_count DESC
                LIMIT 10
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'templates' => $data]);
            break;
        }

        // ── Per-post stats ───────────────────────────────────────────
        case 'post-stats': {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { throw new InvalidArgumentException('Missing id'); }

            $stmt = $db->prepare("
                SELECT spp.platform, spp.status, spp.platform_url, spp.published_at,
                       COALESCE(SUM(smd.impressions), 0)    AS impressions,
                       COALESCE(SUM(smd.clicks), 0)         AS clicks,
                       COALESCE(SUM(smd.likes), 0)          AS likes,
                       COALESCE(SUM(smd.comments_count), 0) AS comments
                FROM social_post_platforms spp
                LEFT JOIN social_metrics_daily smd ON smd.post_platform_id = spp.id
                WHERE spp.post_id = ?
                GROUP BY spp.id
            ");
            $stmt->execute([$id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'platforms' => $data]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (\Throwable $e) {
    error_log('social/metrics.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
