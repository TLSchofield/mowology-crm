<?php
/**
 * Pipeline API
 *
 * GET  ?action=stages          — get pipeline stage definitions
 * GET  ?action=stats           — get pipeline aggregate stats
 * POST ?action=move            — move a quote to a new pipeline stage
 * POST ?action=manage_stages   — CRUD for pipeline stages (admin)
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once PUBLIC_ROOT . '/crm/includes/functions.php';
requireLogin();
$user = getCurrentUser();
session_write_close();

$db     = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'stages':
            $stmt = $db->query("SELECT * FROM pipeline_stages WHERE is_active = 1 ORDER BY stage_order ASC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'stats':
            // Total pipeline value (non-closed quotes)
            $totalValue = (float)$db->query("
                SELECT COALESCE(SUM(total_amount), 0) FROM quotes
                WHERE status NOT IN ('declined','expired') AND pipeline_stage NOT IN ('closed_won','closed_lost')
            ")->fetchColumn();

            // Weighted forecast
            $weightedForecast = (float)$db->query("
                SELECT COALESCE(SUM(total_amount * COALESCE(probability, 0) / 100), 0) FROM quotes
                WHERE status NOT IN ('declined','expired') AND pipeline_stage NOT IN ('closed_won','closed_lost')
            ")->fetchColumn();

            // Win rate
            $won  = (int)$db->query("SELECT COUNT(*) FROM quotes WHERE pipeline_stage = 'closed_won'")->fetchColumn();
            $lost = (int)$db->query("SELECT COUNT(*) FROM quotes WHERE pipeline_stage = 'closed_lost'")->fetchColumn();
            $winRate = ($won + $lost) > 0 ? round($won / ($won + $lost) * 100) : 0;

            // Avg days in stage
            $avgDays = (float)$db->query("
                SELECT COALESCE(AVG(DATEDIFF(NOW(), stage_entered_at)), 0) FROM quotes
                WHERE stage_entered_at IS NOT NULL AND status NOT IN ('declined','expired')
                  AND pipeline_stage NOT IN ('closed_won','closed_lost')
            ")->fetchColumn();

            echo json_encode(['success' => true, 'data' => [
                'total_value'       => $totalValue,
                'weighted_forecast' => $weightedForecast,
                'win_rate'          => $winRate,
                'avg_days_in_stage' => round($avgDays, 1),
            ]]);
            break;

        case 'move':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST required']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                exit;
            }

            $quoteId  = (int)($input['quote_id'] ?? 0);
            $newStage = trim($input['stage'] ?? '');
            if (!$quoteId || !$newStage) {
                http_response_code(400);
                echo json_encode(['error' => 'quote_id and stage required']);
                exit;
            }

            // Get stage details
            $stageStmt = $db->prepare("SELECT * FROM pipeline_stages WHERE stage_key = ? AND is_active = 1");
            $stageStmt->execute([$newStage]);
            $stage = $stageStmt->fetch(PDO::FETCH_ASSOC);
            if (!$stage) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid stage']);
                exit;
            }

            // Get old values
            $oldStmt = $db->prepare("SELECT pipeline_stage, probability, status FROM quotes WHERE id = ?");
            $oldStmt->execute([$quoteId]);
            $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                http_response_code(404);
                echo json_encode(['error' => 'Quote not found']);
                exit;
            }

            // Determine new status based on stage
            $newStatus = $old['status'];
            if ($newStage === 'closed_won') $newStatus = 'accepted';
            elseif ($newStage === 'closed_lost') $newStatus = 'declined';

            $newProb = (int)($input['probability'] ?? $stage['default_probability']);

            $db->prepare("
                UPDATE quotes SET
                    pipeline_stage = ?,
                    probability = ?,
                    stage_entered_at = NOW(),
                    status = ?,
                    lost_reason = ?
                WHERE id = ?
            ")->execute([
                $newStage,
                $newProb,
                $newStatus,
                $newStage === 'closed_lost' ? ($input['lost_reason'] ?? null) : null,
                $quoteId
            ]);

            trackFieldChange('quote', $quoteId, 'pipeline_stage', $old['pipeline_stage'], $newStage, $user['id']);
            trackFieldChange('quote', $quoteId, 'probability', $old['probability'], (string)$newProb, $user['id']);
            if ($newStatus !== $old['status']) {
                trackFieldChange('quote', $quoteId, 'status', $old['status'], $newStatus, $user['id']);
            }

            logActivityExtended($user['id'], 'Pipeline stage changed', "Moved to {$stage['stage_label']}", null, null, $quoteId);

            echo json_encode(['success' => true]);
            break;

        case 'manage_stages':
            if (!function_exists('userHasPermission') || !userHasPermission('settings.edit')) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin only']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                exit;
            }

            $subAction = $input['sub_action'] ?? '';
            if ($subAction === 'add') {
                $db->prepare("INSERT INTO pipeline_stages (stage_key, stage_label, stage_order, stage_color, default_probability) VALUES (?, ?, ?, ?, ?)")
                   ->execute([
                       $input['stage_key'], $input['stage_label'],
                       (int)($input['stage_order'] ?? 0), $input['stage_color'] ?? '#6B7280',
                       (int)($input['default_probability'] ?? 0)
                   ]);
                echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
            } elseif ($subAction === 'update') {
                $db->prepare("UPDATE pipeline_stages SET stage_label = ?, stage_order = ?, stage_color = ?, default_probability = ? WHERE id = ?")
                   ->execute([$input['stage_label'], (int)$input['stage_order'], $input['stage_color'], (int)$input['default_probability'], (int)$input['id']]);
                echo json_encode(['success' => true]);
            } elseif ($subAction === 'delete') {
                $db->prepare("UPDATE pipeline_stages SET is_active = 0 WHERE id = ?")->execute([(int)$input['id']]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Unknown sub_action']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log("Pipeline API error: " . $e->getMessage());
}
