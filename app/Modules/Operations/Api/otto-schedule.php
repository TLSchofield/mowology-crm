<?php
/**
 * Otto on the schedule — the strip above the Schedule (day / week), the Territory Map and the
 * dashboard's 7-Day Operations (OttoScheduleService, migration 1286 for the 5-minute cache).
 *
 * GET ?mode=day&date=Y-m-d[&fresh=1]                  Otto's items for that day.
 * GET ?mode=range&from=Y-m-d&to=Y-m-d[&focus=Y-m-d][&fresh=1]
 *                                                     Counts per day (max 14) + the full list for focus.
 *
 * Items with an id act through /crm/api/otto.php mode=decide (the card's buttons); the rest link.
 * Read-only apart from recording Otto's suggestions (as the card does). Never fills the GPS cache.
 * ?mode=, never ?action=. jobs.edit only.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    if (!userHasPermission('jobs.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Otto is for people who run the schedule (jobs.edit).']);
        exit;
    }
    $db = getDB();
    session_write_close();

    $valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    $mode = (string)($_GET['mode'] ?? 'day');
    $fresh = !empty($_GET['fresh']);

    require_once APP_ROOT . '/Modules/Operations/Services/OttoScheduleService.php';
    $svc = new OttoScheduleService($db);

    if ($mode === 'day') {
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!$valid($date)) throw new InvalidArgumentException('date');
        echo json_encode(['ok' => true] + $svc->day($date, $fresh));
        exit;
    }
    if ($mode === 'range') {
        $from = $_GET['from'] ?? '';
        $to = $_GET['to'] ?? '';
        $focus = $_GET['focus'] ?? null;
        if (!$valid($from) || !$valid($to) || $to < $from || ($focus !== null && !$valid($focus))) throw new InvalidArgumentException('range');
        echo json_encode(['ok' => true] + $svc->range($from, $to, $focus, $fresh));
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Give dates as YYYY-MM-DD.']);
} catch (Throwable $e) {
    error_log('Otto schedule API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Otto hit a snag. Try again in a minute.']);
}
