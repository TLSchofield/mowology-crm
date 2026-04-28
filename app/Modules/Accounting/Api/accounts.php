<?php
/**
 * Chart of Accounts API
 *
 * GET  ?action=list[&active_only=1]
 * GET  ?action=get&id=X
 * POST {action: 'create', code, name, type, sub_type, parent_id, description}
 * POST {action: 'update', id, name, description, display_order, is_active}
 * POST {action: 'deactivate', id}
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
    requirePermission('expenses.view');
    $canEdit = userHasPermission('expenses.edit');

    $db = getDB();
    session_write_close();

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
    } else {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';
    }

    switch ($action) {

        // ── Full list ──────────────────────────────────────────────────────
        case 'list':
            $activeOnly = ($_GET['active_only'] ?? '1') !== '0';
            $where      = $activeOnly ? 'WHERE coa.is_active = 1' : '';

            $rows = $db->query("
                SELECT
                    coa.*,
                    p.name AS parent_name,
                    (SELECT COUNT(*) FROM accounting_transactions t
                     WHERE t.account_id = coa.id) AS transaction_count
                FROM chart_of_accounts coa
                LEFT JOIN chart_of_accounts p ON p.id = coa.parent_id
                $where
                ORDER BY coa.display_order, coa.code
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Nest by type for the UI
            $grouped = [];
            foreach ($rows as $r) {
                $grouped[$r['type']][] = $r;
            }

            echo json_encode(['ok' => true, 'accounts' => $rows, 'grouped' => $grouped]);
            break;

        // ── Single account ─────────────────────────────────────────────────
        case 'get':
            $id  = (int)($_GET['id'] ?? 0);
            $row = $db->prepare(
                "SELECT * FROM chart_of_accounts WHERE id = ?"
            );
            $row->execute([$id]);
            $account = $row->fetch(PDO::FETCH_ASSOC);
            if (!$account) throw new Exception('Account not found', 404);
            echo json_encode(['ok' => true, 'account' => $account]);
            break;

        // ── Create ────────────────────────────────────────────────────────
        case 'create':
            if (!$canEdit) throw new Exception('Permission denied');

            foreach (['code', 'name', 'type'] as $f) {
                if (empty($input[$f])) throw new Exception("Missing: $f");
            }

            // Check for duplicate code
            $dup = $db->prepare("SELECT id FROM chart_of_accounts WHERE code = ?");
            $dup->execute([$input['code']]);
            if ($dup->fetch()) throw new Exception("Account code {$input['code']} already exists");

            $stmt = $db->prepare("
                INSERT INTO chart_of_accounts
                    (code, name, type, sub_type, normal_balance, parent_id,
                     description, display_order, expense_category_alias)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $normalBalance = in_array($input['type'], ['revenue', 'liability', 'equity'])
                ? 'credit' : 'debit';

            $stmt->execute([
                strtoupper(trim($input['code'])),
                $input['name'],
                $input['type'],
                $input['sub_type']             ?? null,
                $input['normal_balance']       ?? $normalBalance,
                $input['parent_id']            ? (int)$input['parent_id'] : null,
                $input['description']          ?? null,
                (int)($input['display_order'] ?? 500),
                $input['expense_category_alias'] ?? null,
            ]);

            echo json_encode([
                'ok'      => true,
                'id'      => (int)$db->lastInsertId(),
                'message' => 'Account created',
            ]);
            break;

        // ── Update ────────────────────────────────────────────────────────
        case 'update':
            if (!$canEdit) throw new Exception('Permission denied');
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');

            // system accounts: only allow name/description/display_order changes
            $sys = $db->prepare("SELECT is_system FROM chart_of_accounts WHERE id = ?");
            $sys->execute([$id]);
            $isSystem = (bool)$sys->fetchColumn();

            $sets   = [];
            $params = [];

            if (!$isSystem) {
                if (isset($input['code']))  { $sets[] = 'code = ?';  $params[] = $input['code']; }
                if (isset($input['type']))  { $sets[] = 'type = ?';  $params[] = $input['type']; }
            }

            if (isset($input['name']))          { $sets[] = 'name = ?';          $params[] = $input['name']; }
            if (isset($input['description']))   { $sets[] = 'description = ?';   $params[] = $input['description']; }
            if (isset($input['display_order'])) { $sets[] = 'display_order = ?'; $params[] = (int)$input['display_order']; }
            if (isset($input['is_active']) && !$isSystem) {
                $sets[]   = 'is_active = ?';
                $params[] = (int)$input['is_active'];
            }
            if (isset($input['expense_category_alias'])) {
                $sets[]   = 'expense_category_alias = ?';
                $params[] = $input['expense_category_alias'] ?: null;
            }

            if (empty($sets)) throw new Exception('Nothing to update');

            $params[] = $id;
            $db->prepare('UPDATE chart_of_accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')
               ->execute($params);

            echo json_encode(['ok' => true, 'message' => 'Account updated']);
            break;

        // ── Deactivate (soft delete) ───────────────────────────────────────
        case 'deactivate':
            if (!$canEdit) throw new Exception('Permission denied');
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');

            // Check it's not a system account
            $sys = $db->prepare("SELECT is_system FROM chart_of_accounts WHERE id = ?");
            $sys->execute([$id]);
            if ($sys->fetchColumn()) throw new Exception('Cannot deactivate a system account');

            // Check for existing transactions
            $txCount = $db->prepare("SELECT COUNT(*) FROM accounting_transactions WHERE account_id = ?");
            $txCount->execute([$id]);
            if ((int)$txCount->fetchColumn() > 0) {
                throw new Exception('Account has transactions — deactivate only, cannot delete');
            }

            $db->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'Account deactivated']);
            break;

        default:
            throw new Exception("Unknown action: $action", 400);
    }

} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
