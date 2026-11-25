<?php
/**
 * Hydrangea Masterclass — create the course and seed its questions.
 * Idempotent: safe to run again after more modules are added.
 * Admin only. Content lives in app/Modules/Quiz/Data/hydrangea_masterclass.php.
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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

session_write_close();

require_once APP_ROOT . '/Modules/Quiz/Services/HydrangeaMasterclassSeeder.php';

try {
    $content = require APP_ROOT . '/Modules/Quiz/Data/hydrangea_masterclass.php';
    $seeder  = new HydrangeaMasterclassSeeder(getDB());
    $result  = $seeder->seed($content, (int)($user['id'] ?? 0));
    echo json_encode(['ok' => true] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('hydrangea masterclass seed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Seed failed — see server log.']);
}
