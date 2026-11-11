<?php
/**
 * Transaction Auto-Categorization Rules API
 *
 * GET  ?action=list
 * GET  ?action=preview&description=...&vendor_name=...&type=expense
 * POST {action: 'apply', force?}  — run rules engine against all eligible transactions (POST since 2026-10-12)
 * POST {action: 'create', name, priority, applies_to, condition_field,
 *               condition_operator, condition_value, account_id, transaction_type}
 * POST {action: 'update', id, ...fields}
 * POST {action: 'delete', id}
 * POST {action: 'toggle', id, is_active}
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
    $user = getCurrentUser();
    requirePermission('expenses.view');
    $canEdit = userHasPermission('expenses.edit');

    $db = getDB();

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
    } else {
        // Must run before session_write_close() below — verifyCSRFToken() reads
        // $_SESSION, which session_write_close() clears from memory.
        require_once APP_ROOT . '/Core/ApiResponse.php';
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!is_array($input)) $input = [];
        if (!verifyCSRFToken(ApiResponse::csrfToken($input))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
        $action = $input['action'] ?? '';
    }

    session_write_close();

    require_once APP_ROOT . '/Modules/Accounting/Services/RulesEngine.php';

    $engine = new RulesEngine($db);

    switch ($action) {

        case 'list':
            $rules = $engine->getAllRules();
            echo json_encode(['ok' => true, 'rules' => $rules]);
            break;

        case 'preview':
            $match = $engine->previewMatch(
                $_GET['description']  ?? '',
                $_GET['vendor_name']  ?? '',
                $_GET['type']         ?? 'expense'
            );
            echo json_encode(['ok' => true, 'match' => $match]);
            break;

        case 'apply':
            // Re-categorises the books: never on a GET (a link or prefetch could run it).
            if ($method !== 'POST') throw new Exception('Apply changes transactions — send it as a POST.', 405);
            if (!$canEdit) throw new Exception('Permission denied', 403);
            $force = !empty($input['force']);
            $count = $engine->applyAll((bool)$force);
            echo json_encode(['ok' => true, 'updated' => $count, 'message' => "$count transactions recategorized"]);
            break;

        case 'create':
            if (!$canEdit) throw new Exception('Permission denied');
            foreach (['name', 'condition_field', 'condition_value', 'account_id'] as $f) {
                if (!isset($input[$f]) || $input[$f] === '') {
                    throw new Exception("Missing required field: $f");
                }
            }
            $id = $engine->createRule($input, (int)$user['id']);
            echo json_encode(['ok' => true, 'id' => $id, 'message' => 'Rule created']);
            break;

        case 'update':
            if (!$canEdit) throw new Exception('Permission denied');
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $engine->updateRule($id, $input);
            echo json_encode(['ok' => true, 'message' => 'Rule updated']);
            break;

        case 'delete':
            if (!$canEdit) throw new Exception('Permission denied');
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $engine->deleteRule($id);
            echo json_encode(['ok' => true, 'message' => 'Rule deleted']);
            break;

        case 'toggle':
            if (!$canEdit) throw new Exception('Permission denied');
            $id       = (int)($input['id']        ?? 0);
            $isActive = (int)($input['is_active']  ?? 1);
            if (!$id) throw new Exception('Missing id');
            $db->prepare("UPDATE transaction_rules SET is_active = ? WHERE id = ?")
               ->execute([$isActive, $id]);
            echo json_encode(['ok' => true, 'is_active' => (bool)$isActive]);
            break;

        default:
            throw new Exception("Unknown action: $action", 400);
    }

} catch (Throwable $e) {
    require_once APP_ROOT . '/Core/ApiResponse.php';
    http_response_code(ApiResponse::status($e));
    echo json_encode(['ok' => false, 'error' => ApiResponse::message($e, 'rules.php')]);
}
