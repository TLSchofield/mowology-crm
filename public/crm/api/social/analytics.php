<?php
/**
 * Social Analytics API — first-party (organic) post analytics.
 *
 * Thin controller. All logic lives in SocialAnalyticsService.
 *
 * Actions:
 *   overview     — summary + activity + content + attribution (default, one call)
 *   summary      — KPI headline numbers
 *   activity     — posting cadence / status / platform mix
 *   content      — template / service / city breakdown
 *   attribution  — UTM → quote-request conversion per post
 *
 * Query params: days (attribution window, default 90), months (cadence, default 6)
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
require_once APP_ROOT . '/Modules/Social/Services/SocialAnalyticsService.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();
session_write_close();
requirePermission('marketing.view');

$action = $_GET['action'] ?? 'overview';
$days   = max(7, min(365, (int)($_GET['days']   ?? 90)));
$months = max(1, min(24,  (int)($_GET['months'] ?? 6)));

try {
    switch ($action) {
        case 'summary':
            echo json_encode(['success' => true, 'summary' => SocialAnalyticsService::summary($days)]);
            break;
        case 'activity':
            echo json_encode(['success' => true, 'activity' => SocialAnalyticsService::activity($months)]);
            break;
        case 'content':
            echo json_encode(['success' => true, 'content' => SocialAnalyticsService::content()]);
            break;
        case 'attribution':
            echo json_encode(['success' => true, 'attribution' => SocialAnalyticsService::attribution($days)]);
            break;
        case 'overview':
            echo json_encode(['success' => true, 'data' => SocialAnalyticsService::overview($days, $months)]);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    error_log('social/analytics.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
