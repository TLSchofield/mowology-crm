<?php
/**
 * Otto the Dispatcher — rule tables and equipment register (the two /crm/ops pages).
 *
 * POST {mode, csrf_token, ...}:
 *   save_rule {id?, municipality, area, kind, equipment_class, power_source, day_type,
 *              allowed_start, allowed_end, near_homes_m, status, source_url, note}
 *   delete_rule {id}
 *   save_area {name, municipality, fsa_prefixes, status, note}
 *   save_service_map {service_type, classes: []}
 *   save_training_map {service_type, course_id, min_tier}  → cert_service_type_requirements
 *   save_green_waste {text}
 *   save_equipment {id?, name, equipment_class, ...}
 *   save_interval {equipment_class | equipment_id, task, every_hours, every_days}
 *   delete_interval {id}
 *   log_service {equipment_id, task, done_on}
 *   log_run {equipment_id, runtime_min, run_date, ran_flat}
 *
 * ?mode=, never ?action= (see the /api/ router note in the vault). jobs.edit only.
 * Nothing here moves a visit or messages anyone.
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
        echo json_encode(['ok' => false, 'error' => 'This is for people who run the schedule (jobs.edit).']);
        exit;
    }
    $user = getCurrentUser();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST only']);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?? [];
    if (!verifyCSRFToken((string)($in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — reload the page and try again.']);
        exit;
    }
    $db = getDB();
    session_write_close();
    $mode = (string)($in['mode'] ?? '');
    $actor = (int)$user['id'];

    require_once APP_ROOT . '/Modules/Operations/Services/MunicipalRuleService.php';
    require_once APP_ROOT . '/Modules/Operations/Services/EquipmentService.php';
    $rules = new MunicipalRuleService($db);
    $eq = new EquipmentService($db);

    switch ($mode) {
        case 'save_rule':        $r = $rules->saveRule($in, $actor); break;
        case 'delete_rule':      $r = $rules->deleteRule((int)($in['id'] ?? 0)); break;
        case 'save_area':        $r = $rules->saveArea($in); break;
        case 'save_service_map': $r = $rules->saveServiceMap((string)($in['service_type'] ?? ''), (array)($in['classes'] ?? [])); break;
        case 'save_training_map':
            require_once APP_ROOT . '/Modules/Operations/Services/TrainingService.php';
            $r = (new TrainingService($db))->saveMapping((string)($in['service_type'] ?? ''), !empty($in['course_id']) ? (int)$in['course_id'] : null, (int)($in['min_tier'] ?? 0));
            break;
        case 'save_green_waste':
            $db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description, updated_by) VALUES ('otto_green_waste', ?, 'Where green waste goes: place, hours, fees (Otto the Dispatcher)', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)")
                ->execute([mb_substr(trim((string)($in['text'] ?? '')), 0, 4000), $actor]);
            $r = ['ok' => true, 'message' => 'Saved.'];
            break;
        case 'save_equipment':   $r = $eq->save($in); break;
        case 'save_interval':    $r = $eq->saveInterval($in); break;
        case 'delete_interval':  $eq->deleteInterval((int)($in['id'] ?? 0)); $r = ['ok' => true, 'message' => 'Removed.']; break;
        case 'log_service':      $r = $eq->logService((int)($in['equipment_id'] ?? 0), (string)($in['task'] ?? ''), $in['done_on'] ?? null, $actor); break;
        case 'log_run':          $r = $eq->logRun((int)($in['equipment_id'] ?? 0), (int)($in['runtime_min'] ?? 0), $in['run_date'] ?? null, !empty($in['ran_flat']), $actor); break;
        default:
            http_response_code(400);
            $r = ['ok' => false, 'error' => 'Unknown mode'];
    }
    if (empty($r['ok'])) http_response_code(400);
    echo json_encode($r);
} catch (Throwable $e) {
    error_log('Dispatch API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong. Try again in a minute.']);
}
