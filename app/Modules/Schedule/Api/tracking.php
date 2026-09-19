<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/tracking.php
 *
 * Mobile tracking control plane — policy, consent, geofences.
 * Authorization: Bearer <jwt>
 *
 * GET  ?mode=status      → { policy }                 poll every policy.status_poll_s
 * GET  ?mode=consent     → { disclosure, consent }    the text to show + whether it is agreed
 * GET  ?mode=geofences   → { geofences: [...] }       the caller's OWN visits today, for OS geofencing
 * POST {action:'consent', version, device?}           record agreement to the current disclosure
 * POST {action:'withdraw'}                            withdraw consent (tracking stops)
 *
 * `mode`, not `action`, on GET: the /api/ rewrite owns the `action` query param.
 * Thin controller — rules live in TrackingIngestService / TrackingConsentService.
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';
    require_once APP_ROOT . '/Modules/Team/Services/TrackingIngestService.php';
    require_once APP_ROOT . '/Modules/Team/Services/TrackingConsentService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    $db      = getDB();
    $ingest  = new TrackingIngestService($db);
    $consent = new TrackingConsentService($db);

    $policy = static function () use ($ingest, $userId): array {
        $flags = $ingest->userFlags($userId);
        $timer = getLiveJobTimer($userId);
        return TrackingIngestService::policy(
            $flags['active'], $flags['tracking'], $ingest->consentOk($userId),
            (bool)getActiveClockEntry($userId), $timer ? (int)$timer['visit_id'] : null
        );
    };

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch (trim((string)($_GET['mode'] ?? 'status'))) {

            case 'status':
                echo json_encode(['success' => true, 'policy' => $policy()]);
                break;

            case 'consent':
                $biz = '';
                try {
                    $row = $db->query("SELECT * FROM business_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
                    $biz = (string)($row['company_name'] ?? $row['business_name'] ?? '');
                } catch (Throwable $e) { /* fall back to generic wording */ }
                $latest = $consent->latest($userId);
                echo json_encode([
                    'success'    => true,
                    'disclosure' => TrackingConsentService::disclosure($biz),
                    'consent'    => [
                        'current'      => TrackingConsentService::isCurrent($latest),
                        'consented_at' => TrackingConsentService::isCurrent($latest) ? $latest['consented_at'] : null,
                        'required'     => getTimeClockSetting('tracking_consent_required', '0') === '1',
                    ],
                ]);
                break;

            case 'geofences':
                // ONLY the caller's own scheduled visits — never the whole company's addresses.
                require_once APP_ROOT . '/Modules/Team/Services/ProximityAutoStartService.php';
                $today  = date('Y-m-d');
                $mine   = getStopIdsForCrewMember($userId, $today);
                $radius = (int)getTimeClockSetting('gps_proximity_meters', '150');
                $fences = [];
                foreach (getAllJobsForDate($today) as $v) {
                    if (!ProximityAutoStartService::isOwnVisit($v, $userId, $mine)) continue;
                    if (!$v['property_lat'] || !$v['property_lng']) continue;
                    $fences[] = [
                        'visit_id' => (int)$v['id'],
                        'lat'      => (float)$v['property_lat'],
                        'lng'      => (float)$v['property_lng'],
                        'radius_m' => $radius,
                        'status'   => $v['status'],
                        'label'    => $v['property_address'] ?? '',
                    ];
                }
                echo json_encode(['success' => true, 'date' => $today, 'geofences' => $fences]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown mode']);
        }
        exit;
    }

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = trim((string)($input['action'] ?? ''));

    switch ($action) {
        case 'consent':
            $device = is_array($input['device'] ?? null) ? $input['device'] : [];
            $consent->record($userId, (string)($input['version'] ?? ''), [
                'platform'    => $device['platform'] ?? null,
                'device_id'   => $device['id'] ?? null,
                'app_version' => $device['app_version'] ?? null,
                'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            if (function_exists('logActivity')) {
                logActivity($userId, null, 'Tracking consent', 'Agreed to location disclosure ' . TrackingConsentService::DISCLOSURE_VERSION);
            }
            echo json_encode(['success' => true, 'policy' => $policy()]);
            break;

        case 'withdraw':
            $consent->withdraw($userId);
            if (function_exists('logActivity')) {
                logActivity($userId, null, 'Tracking consent', 'Withdrew location tracking consent');
            }
            echo json_encode(['success' => true, 'policy' => $policy()]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[schedule/tracking] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
