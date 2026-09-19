<?php
/**
 * Offline Schedule API
 *
 * Returns the current user's schedule for today + 6 days as compact JSON
 * suitable for IndexedDB storage (offline-first crew access).
 *
 * Auth: JWT Bearer token OR PHP session (requireLoginOrJwt).
 * GET /crm/api/offline-schedule.php
 *
 * Response:
 *   { ok: true, generated_at: unix_ts, user: {...}, days: [{date, stops:[]},...] }
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/plan-functions.php';

    $user = requireLoginOrJwt();
    session_write_close();

    $db     = getDB();
    $userId = (int)$user['id'];
    $isAdmin = in_array($user['role'] ?? '', ['admin', 'manager'], true);

    $start = date('Y-m-d');
    $end   = date('Y-m-d', strtotime('+6 days'));

    // Crew members only see their own stops; admins/managers see all
    $crewFilter = $isAdmin ? null : $userId;

    // Use the existing getCalendarStops() from PlanFunctions — already optimised
    $calendarData = getCalendarStops($start, $end, $crewFilter);

    // Build compact day array suitable for IDB storage
    $days = [];
    $cursor = new DateTime($start);
    for ($i = 0; $i < 7; $i++) {
        $dateStr = $cursor->format('Y-m-d');
        $rawStops = $calendarData[$dateStr] ?? [];

        $stops = [];
        foreach ($rawStops as $stop) {
            $visits = [];
            foreach ($stop['visits'] ?? [] as $v) {
                $visits[] = [
                    'visit_id'        => (int)($v['visit_id'] ?? 0),
                    'visit_status'    => $v['visit_status'] ?? '',
                    'plan_title'      => $v['plan_title'] ?? '',
                    'service_type'    => $v['service_type'] ?? '',
                    'duration_min'    => (int)($v['estimated_duration'] ?? 0),
                    'photos_required' => !empty($v['photos_block_completion']),
                    'photo_types'     => $v['photo_types_required'] ?? '',
                ];
            }

            $stops[] = [
                'stop_id'           => (int)($stop['stop_id'] ?? 0),
                'route_order'       => (int)($stop['route_order'] ?? 0),
                'stop_status'       => $stop['stop_status'] ?? '',
                'estimated_arrival' => $stop['estimated_arrival'] ?? null,
                'property_id'       => (int)($stop['property_id'] ?? 0),
                'property_name'     => $stop['property_name'] ?? '',
                'property_address'  => $stop['property_address'] ?? '',
                'property_city'     => $stop['property_city'] ?? '',
                'latitude'          => $stop['latitude']  ? (float)$stop['latitude']  : null,
                'longitude'         => $stop['longitude'] ? (float)$stop['longitude'] : null,
                'contact_name'      => $stop['contact_name'] ?? '',
                'crew_ids'          => $stop['crew_ids'] ?? [],
                'crew_names'        => $stop['crew_names'] ?? [],
                'visits'            => $visits,
            ];
        }

        $days[] = ['date' => $dateStr, 'stops' => $stops];
        $cursor->modify('+1 day');
    }

    echo json_encode([
        'ok'           => true,
        'generated_at' => time(),
        'user'         => [
            'id'   => $userId,
            'name' => $user['name'] ?? '',
            'role' => $user['role'] ?? '',
        ],
        'days' => $days,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
