<?php
/**
 * Transaction Auto-Categorization Rules API
 *
 * GET  ?action=list
 * GET  ?action=preview&description=...&vendor_name=...&type=expense
 * GET  ?action=apply    — run rules engine against all eligible transactions
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
    session_write_close();

    require_once APP_ROOT . '/Modules/Accounting/Services/RulesEngine.php';

    $engine = new RulesEngine($db);

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
    } else {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';
    }

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
            if (!$canEdit) throw new Exception('Permission denied');
            $force = !empty($_GET['force']) || !empty($input['force']);
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
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
