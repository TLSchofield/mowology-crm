<?php
/**
 * Automation Rules API
 *
 * Actions:
 *   list          — paginated rule list
 *   get           — single rule detail
 *   save          — create / update rule
 *   delete        — delete rule
 *   toggle        — enable / disable rule
 *   logs          — activity log for a rule (or all)
 *   queue-status  — pending queued actions count
 *   tags          — list all contact tags in use
 *   trigger-types — list all supported trigger types with labels
 *
 * @package Mowology\Marketing
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────────
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
require_once CRM_INCLUDES . '/functions.php';

requireLogin();
$user = getCurrentUser();
header('Content-Type: application/json; charset=utf-8');
session_write_close();

$action = $_GET['action'] ?? '';
$db = getDB();

try {
    switch ($action) {

        // ── List rules ───────────────────────────────────────────────────
        case 'list':
            requirePermission('marketing.view');

            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;
            $filter  = $_GET['filter'] ?? '';   // 'enabled' | 'disabled'

            $where  = '1=1';
            $params = [];
            if ($filter === 'enabled')  { $where .= ' AND is_enabled = 1'; }
            if ($filter === 'disabled') { $where .= ' AND is_enabled = 0'; }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM automation_rules WHERE $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT ar.*,
                       u.full_name AS created_by_name,
                       (SELECT COUNT(*) FROM automation_logs al WHERE al.rule_id = ar.id) AS log_count,
                       (SELECT COUNT(*) FROM automation_logs al WHERE al.rule_id = ar.id AND al.status = 'completed' AND al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS completions_30d
                FROM automation_rules ar
                LEFT JOIN users u ON u.id = ar.created_by
                WHERE $where
                ORDER BY ar.is_enabled DESC, ar.updated_at DESC
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute($params);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON fields
            foreach ($rules as &$r) {
                $r['trigger_config'] = json_decode($r['trigger_config'] ?? '{}', true) ?: [];
                $r['conditions']     = json_decode($r['conditions'] ?? '[]', true) ?: [];
                $r['actions']        = json_decode($r['actions'] ?? '[]', true) ?: [];
            }
            unset($r);

            $enabledCount  = (int)$db->query("SELECT COUNT(*) FROM automation_rules WHERE is_enabled = 1")->fetchColumn();
            $disabledCount = (int)$db->query("SELECT COUNT(*) FROM automation_rules WHERE is_enabled = 0")->fetchColumn();
            $pendingQueue  = (int)$db->query("SELECT COUNT(*) FROM queued_actions WHERE status = 'pending'")->fetchColumn();

            echo json_encode([
                'success'       => true,
                'rules'         => $rules,
                'total'         => $total,
                'page'          => $page,
                'pages'         => max(1, (int)ceil($total / $perPage)),
                'enabled_count' => $enabledCount,
                'disabled_count'=> $disabledCount,
                'pending_queue' => $pendingQueue,
            ]);
            break;

        // ── Get single rule ──────────────────────────────────────────────
        case 'get':
            requirePermission('marketing.view');
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID required']); break; }

            $stmt = $db->prepare("
                SELECT ar.*, u.full_name AS created_by_name
                FROM automation_rules ar
                LEFT JOIN users u ON u.id = ar.created_by
                WHERE ar.id = ?
            ");
            $stmt->execute([$id]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rule) { echo json_encode(['success' => false, 'error' => 'Rule not found']); break; }

            $rule['trigger_config'] = json_decode($rule['trigger_config'] ?? '{}', true) ?: [];
            $rule['conditions']     = json_decode($rule['conditions'] ?? '[]', true) ?: [];
            $rule['actions']        = json_decode($rule['actions'] ?? '[]', true) ?: [];

            // Recent logs
            $logStmt = $db->prepare("
                SELECT al.*, c.first_name, c.last_name, c.email
                FROM automation_logs al
                LEFT JOIN contacts c ON c.id = al.contact_id
                WHERE al.rule_id = ?
                ORDER BY al.created_at DESC
                LIMIT 20
            ");
            $logStmt->execute([$id]);
            $rule['recent_logs'] = $logStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'rule' => $rule]);
            break;

        // ── Save (create / update) ───────────────────────────────────────
        case 'save':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) { echo json_encode(['success' => false, 'error' => 'Invalid JSON']); break; }

            verifyCSRFToken($input['csrf_token'] ?? '');

            $id = (int)($input['id'] ?? 0);

            $name = trim($input['name'] ?? '');
            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'Rule name required']); break;
            }

            $triggerType = trim($input['trigger_type'] ?? '');
            if (empty($triggerType)) {
                echo json_encode(['success' => false, 'error' => 'Trigger type required']); break;
            }

            $allowedTriggers = [
                'quote_created', 'quote_viewed', 'quote_accepted', 'job_completed',
                'invoice_overdue', 'client_inactive', 'territory', 'tagged',
                'season_change', 'weather_event', 'product_not_purchased', 'route_density_low',
            ];
            if (!in_array($triggerType, $allowedTriggers)) {
                echo json_encode(['success' => false, 'error' => 'Invalid trigger type']); break;
            }

            $fields = [
                'name'           => $name,
                'description'    => trim($input['description'] ?? ''),
                'trigger_type'   => $triggerType,
                'trigger_config' => json_encode($input['trigger_config'] ?? []),
                'conditions'     => json_encode($input['conditions'] ?? []),
                'actions'        => json_encode($input['actions'] ?? []),
                'cooldown_hours' => max(0, (int)($input['cooldown_hours'] ?? 0)),
                'max_executions' => max(0, (int)($input['max_executions'] ?? 0)),
                'is_enabled'     => (int)($input['is_enabled'] ?? 1),
            ];

            if ($id) {
                $setClauses = [];
                $params = [];
                foreach ($fields as $col => $val) {
                    $setClauses[] = "`$col` = ?";
                    $params[] = $val;
                }
                $params[] = $id;
                $db->prepare("UPDATE automation_rules SET " . implode(', ', $setClauses) . " WHERE id = ?")->execute($params);
            } else {
                $fields['created_by'] = $user['id'];
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                $db->prepare(
                    "INSERT INTO automation_rules (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")"
                )->execute(array_values($fields));
                $id = (int)$db->lastInsertId();
            }

            echo json_encode(['success' => true, 'id' => $id]);
            break;

        // ── Delete rule ──────────────────────────────────────────────────
        case 'delete':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID required']); break; }

            $db->prepare("DELETE FROM automation_rules WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        // ── Toggle enable/disable ────────────────────────────────────────
        case 'toggle':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID required']); break; }

            $stmt = $db->prepare("SELECT is_enabled FROM automation_rules WHERE id = ?");
            $stmt->execute([$id]);
            $current = (int)$stmt->fetchColumn();
            $newVal  = $current ? 0 : 1;

            $db->prepare("UPDATE automation_rules SET is_enabled = ? WHERE id = ?")->execute([$newVal, $id]);
            echo json_encode(['success' => true, 'is_enabled' => $newVal]);
            break;

        // ── Activity logs ────────────────────────────────────────────────
        case 'logs':
            requirePermission('marketing.view');
            $ruleId = (int)($_GET['rule_id'] ?? 0);
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;

            $where  = '1=1';
            $params = [];
            if ($ruleId) { $where .= ' AND al.rule_id = ?'; $params[] = $ruleId; }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM automation_logs al WHERE $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT al.*,
                       ar.name AS rule_name,
                       c.first_name, c.last_name, c.email
                FROM automation_logs al
                LEFT JOIN automation_rules ar ON ar.id = al.rule_id
                LEFT JOIN contacts c ON c.id = al.contact_id
                WHERE $where
                ORDER BY al.created_at DESC
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'logs'    => $logs,
                'total'   => $total,
                'pages'   => max(1, (int)ceil($total / $perPage)),
            ]);
            break;

        // ── Queue status ─────────────────────────────────────────────────
        case 'queue-status':
            requirePermission('marketing.view');

            $statusCounts = $db->query("
                SELECT status, COUNT(*) AS cnt
                FROM queued_actions
                GROUP BY status
            ")->fetchAll(PDO::FETCH_KEY_PAIR);

            $recent = $db->query("
                SELECT qa.*, ar.name AS rule_name, c.first_name, c.last_name
                FROM queued_actions qa
                LEFT JOIN automation_rules ar ON ar.id = qa.rule_id
                LEFT JOIN contacts c ON c.id = qa.contact_id
                ORDER BY qa.created_at DESC
                LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'counts'  => $statusCounts,
                'recent'  => $recent,
            ]);
            break;

        // ── Available tags ───────────────────────────────────────────────
        case 'tags':
            requirePermission('marketing.view');

            $tags = $db->query("
                SELECT tag, COUNT(*) AS contact_count
                FROM contact_tags
                GROUP BY tag
                ORDER BY tag
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'tags' => $tags]);
            break;

        // ── Trigger type definitions ─────────────────────────────────────
        case 'trigger-types':
            requirePermission('marketing.view');

            echo json_encode([
                'success' => true,
                'triggers' => [
                    ['type' => 'quote_created',           'label' => 'Quote Created',                'icon' => 'file-text',  'config_fields' => []],
                    ['type' => 'quote_viewed',            'label' => 'Quote Viewed',                 'icon' => 'eye',        'config_fields' => [['key'=>'hours_since_view','label'=>'Hours since viewed','type'=>'number','default'=>24]]],
                    ['type' => 'quote_accepted',          'label' => 'Quote Accepted',               'icon' => 'check-circle','config_fields' => []],
                    ['type' => 'job_completed',           'label' => 'Job Completed',                'icon' => 'briefcase',  'config_fields' => []],
                    ['type' => 'invoice_overdue',         'label' => 'Invoice Overdue',              'icon' => 'alert-circle','config_fields' => [['key'=>'overdue_days','label'=>'Days overdue','type'=>'number','default'=>7]]],
                    ['type' => 'client_inactive',         'label' => 'Client Inactive X Days',       'icon' => 'clock',      'config_fields' => [['key'=>'inactive_days','label'=>'Days inactive','type'=>'number','default'=>90]]],
                    ['type' => 'territory',               'label' => 'Client in Territory',          'icon' => 'map-pin',    'config_fields' => [['key'=>'territory','label'=>'Territory / Area','type'=>'text']]],
                    ['type' => 'tagged',                  'label' => 'Client Tagged With',           'icon' => 'tag',        'config_fields' => [['key'=>'tag','label'=>'Tag name','type'=>'text']]],
                    ['type' => 'season_change',           'label' => 'Season Change',                'icon' => 'sun',        'config_fields' => [['key'=>'season','label'=>'Season','type'=>'select','options'=>['spring','summer','fall','winter']]]],
                    ['type' => 'weather_event',           'label' => 'Weather Event',                'icon' => 'cloud',      'config_fields' => [['key'=>'event','label'=>'Event type','type'=>'text','placeholder'=>'e.g. frost, heavy_rain']]],
                    ['type' => 'product_not_purchased',   'label' => 'Product Not Purchased',        'icon' => 'package',    'config_fields' => [['key'=>'product_id','label'=>'Product ID','type'=>'number']]],
                    ['type' => 'route_density_low',       'label' => 'Route Density Low in Area',    'icon' => 'trending-down','config_fields' => [['key'=>'area','label'=>'Area / Postal prefix','type'=>'text']]],
                ],
                'conditions' => [
                    ['field' => 'tag',                   'label' => 'Has Tag',              'operators' => ['has','not_has']],
                    ['field' => 'revenue_lifetime',      'label' => 'Lifetime Revenue',     'operators' => ['gt','lt','gte','lte','eq']],
                    ['field' => 'revenue_ytd',           'label' => 'Revenue This Year',    'operators' => ['gt','lt','gte','lte','eq']],
                    ['field' => 'service_count',         'label' => 'Total Services',       'operators' => ['gt','lt','gte','lte','eq']],
                    ['field' => 'days_since_service',    'label' => 'Days Since Last Service','operators' => ['gt','lt','gte','lte','eq']],
                    ['field' => 'email_opened',          'label' => 'Opened Last Campaign', 'operators' => ['eq'],            'value_type' => 'bool'],
                    ['field' => 'email_clicked',         'label' => 'Clicked Last Campaign','operators' => ['eq'],            'value_type' => 'bool'],
                    ['field' => 'receive_marketing',     'label' => 'Marketing Opt-In',     'operators' => ['eq'],            'value_type' => 'bool'],
                    ['field' => 'city',                  'label' => 'City',                 'operators' => ['eq','not_eq','contains']],
                ],
                'actions' => [
                    ['type' => 'send_campaign',   'label' => 'Send Campaign',             'icon' => 'send',           'config_fields' => [['key'=>'campaign_id','label'=>'Campaign ID','type'=>'number'],['key'=>'template_category','label'=>'Or Template Category','type'=>'text']]],
                    ['type' => 'send_email',      'label' => 'Send Single Email',         'icon' => 'mail',           'config_fields' => [['key'=>'subject','label'=>'Subject','type'=>'text'],['key'=>'body','label'=>'Body (HTML)','type'=>'textarea']]],
                    ['type' => 'add_tag',         'label' => 'Add Tag',                   'icon' => 'tag',            'config_fields' => [['key'=>'tag','label'=>'Tag name','type'=>'text']]],
                    ['type' => 'remove_tag',      'label' => 'Remove Tag',                'icon' => 'x',              'config_fields' => [['key'=>'tag','label'=>'Tag name','type'=>'text']]],
                    ['type' => 'create_task',     'label' => 'Create Follow-Up Task',     'icon' => 'check-square',   'config_fields' => [['key'=>'title','label'=>'Task title','type'=>'text'],['key'=>'due_days','label'=>'Due in X days','type'=>'number','default'=>2]]],
                    ['type' => 'add_nurture',     'label' => 'Add to Nurture Sequence',   'icon' => 'repeat',         'config_fields' => [['key'=>'sequence_id','label'=>'Sequence ID','type'=>'number']]],
                    ['type' => 'notify_admin',    'label' => 'Notify Admin',              'icon' => 'bell',           'config_fields' => [['key'=>'message','label'=>'Notification message','type'=>'text']]],
                    ['type' => 'schedule_sms',    'label' => 'Schedule SMS (Future)',      'icon' => 'message-square', 'config_fields' => [['key'=>'message','label'=>'SMS message (max 160 chars)','type'=>'textarea']]],
                ],
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }

} catch (Throwable $e) {
    error_log('automations API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
