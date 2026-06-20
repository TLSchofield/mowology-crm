<?php
/**
 * Bank Import API
 *
 * POST (multipart) action=preview  — parse CSV, return rows for review
 * POST (JSON)      action=commit   — finalize import of previewed rows
 * POST (JSON)      action=rollback — undo an import session
 * GET              action=sessions — list import history
 * GET              action=session_rows&session_id=X — rows in a session
 * GET              action=presets  — available bank presets
 * GET              action=alerts   — get/run alert engine
 * POST (JSON)      action=dismiss_alert, id=X
 * POST (JSON)      action=dismiss_all_alerts
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
    unset($__dir, $__i);
}


try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    $user = getCurrentUser();
    requirePermission('expenses.edit');


    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Accounting/Services/BankImportService.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/RulesEngine.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/AlertEngine.php';

    $importer = new BankImportService($db);
    $alertEng = new AlertEngine($db);

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'sessions';
    } else {
        // May be multipart (file upload) or JSON
        if (!empty($_FILES)) {
            $action = $_POST['action'] ?? 'preview';
        } else {
            $input  = json_decode(file_get_contents('php://input'), true) ?? [];
            $action = $input['action'] ?? '';
        }
    }

    switch ($action) {

        // ── Debug OCR (admin only) ─────────────────────────────────────────────
        // Returns raw per-page OCR text + per-line parse rejections.
        // Use this when the parsed row count is lower than expected.
        case 'debug_ocr':
            if (($user['role'] ?? '') !== 'admin') throw new Exception('Admin only', 403);
            $fileKey = !empty($_FILES['csv']) ? 'csv' : (!empty($_FILES['file']) ? 'file' : null);
            if (!$fileKey) throw new Exception('No file uploaded');
            $file = $_FILES[$fileKey];
            if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload error: ' . $file['error']);
            if ($file['size'] > 20 * 1024 * 1024) throw new Exception('File too large (max 20MB)');
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') throw new Exception('debug_ocr only supports PDF files');
            $bankName = htmlspecialchars($_POST['bank_name'] ?? '');
            $debug = $importer->previewPdfDebug($file['tmp_name'], $bankName);
            echo json_encode(['ok' => true, 'debug' => $debug], JSON_PRETTY_PRINT);
            break;

        // ── Bank presets ──────────────────────────────────────────────────────
        case 'presets':
            echo json_encode(['ok' => true, 'presets' => $importer->getPresets()]);
            break;

        // ── Preview (file upload — CSV or PDF) ───────────────────────────────
        case 'preview':
            $fileKey = !empty($_FILES['csv']) ? 'csv' : (!empty($_FILES['file']) ? 'file' : null);
            if (!$fileKey) throw new Exception('No file uploaded');
            $file = $_FILES[$fileKey];

            if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload error: ' . $file['error']);
            if ($file['size'] > 20 * 1024 * 1024) throw new Exception('File too large (max 20MB)');

            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $bankName = htmlspecialchars($_POST['bank_name'] ?? $_POST['preset'] ?? '');
            $presetIn = $_POST['preset'] ?? '';
            // 'bank' | 'credit_card' — drives credit-card routing. Derived from the
            // selected preset; 'auto' / unknown defaults to bank.
            $kind     = $importer->getPresetKind($presetIn);

            if ($ext === 'pdf') {
                // PDF path — extract text and parse transactions automatically
                $result = $importer->previewPdf($file['tmp_name'], $bankName, $kind);
                echo json_encode(['ok' => true, 'preview' => $result]);
                break;
            }

            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) {
                // Image path — photo or scan of a bank statement
                $mime   = $file['type'] ?: 'image/jpeg';
                $result = $importer->previewImage($file['tmp_name'], $mime, $bankName, $kind);
                echo json_encode(['ok' => true, 'preview' => $result]);
                break;
            }

            if (!in_array($ext, ['csv', 'txt'], true)) {
                throw new Exception('Supported formats: CSV (.csv, .txt), PDF (.pdf), or image (.jpg, .png)');
            }

            $content  = file_get_contents($file['tmp_name']);
            $preset   = $presetIn;

            // Auto-detect statement type (Vancity bank / TD CC / Vancity CC / …)
            // from the CSV header row when the user left the format on "Auto-detect".
            if ($preset === '' || $preset === 'auto') {
                $firstLine = strtok($content, "\r\n") ?: '';
                $detected  = $importer->detectStatementType($firstLine, $file['name']);
                $preset    = $detected !== '' ? $detected : $importer->detectPreset($file['name']);
            }
            $kind = $importer->getPresetKind($preset);
            if (!$bankName) $bankName = htmlspecialchars($preset);

            // Build column mapping from preset or POST params
            $presetData = $importer->getPreset($preset);
            $mapping    = [];

            if ($presetData) {
                foreach (['date', 'description', 'description2', 'debit', 'credit', 'amount'] as $k) {
                    if (isset($presetData[$k])) $mapping[$k] = $presetData[$k];
                }
                $skipRows = $presetData['skip'] ?? 1;
            } else {
                // Custom mapping from POST
                foreach (['date', 'description', 'debit', 'credit', 'amount'] as $k) {
                    if (isset($_POST["col_$k"])) $mapping[$k] = (int)$_POST["col_$k"];
                }
                $skipRows = (int)($_POST['skip_rows'] ?? 1);
                // Custom CSV can still be a credit-card statement.
                if (($_POST['kind'] ?? '') === 'credit_card') $kind = 'credit_card';
            }

            if (empty($mapping['date']) && !isset($mapping['date'])) {
                throw new Exception('Column mapping must include at least: date, description, and amount or debit/credit');
            }

            $result = $importer->preview($content, $mapping, $skipRows, $bankName, $kind);

            echo json_encode(['ok' => true, 'preview' => $result, 'preset' => $preset, 'kind' => $kind]);
            break;

        // ── Commit import ──────────────────────────────────────────────────────
        case 'commit':
            $rows          = $input['rows']            ?? [];
            $bankName      = $input['bank_name']       ?? '';
            $accountName   = $input['account_name']    ?? '';
            $bankAccountId = (int)($input['bank_account_id'] ?? 0);
            $skipDupes     = (bool)($input['skip_duplicates'] ?? true);

            if (empty($rows)) throw new Exception('No rows to import');

            // Re-validate: ensure account_id is set on every non-duplicate row
            foreach ($rows as &$row) {
                if (empty($row['account_id'])) {
                    $row['account_id'] = null;
                    $row['auto_cat']   = false;
                }
            }
            unset($row);

            $balanceCheck = isset($input['balance_check']) && is_array($input['balance_check']) ? $input['balance_check'] : null;
            $result = $importer->commit($rows, (int)$user['id'], $bankName, $accountName, $skipDupes, $bankAccountId, $balanceCheck);
            echo json_encode(['ok' => true, 'result' => $result]);
            break;

        // ── Rollback session ───────────────────────────────────────────────────
        case 'rollback':
            $sessionId = (int)($input['session_id'] ?? 0);
            if (!$sessionId) throw new Exception('Missing session_id');
            $deleted = $importer->rollback($sessionId);
            echo json_encode(['ok' => true, 'deleted' => $deleted]);
            break;

        // ── Session list ───────────────────────────────────────────────────────
        case 'sessions':
            $sessions = $importer->getSessions();
            echo json_encode(['ok' => true, 'sessions' => $sessions]);
            break;

        // ── Session rows ───────────────────────────────────────────────────────
        case 'session_rows':
            $sessionId = (int)($_GET['session_id'] ?? 0);
            if (!$sessionId) throw new Exception('Missing session_id');
            $rows = $importer->getSessionRows($sessionId);
            echo json_encode(['ok' => true, 'rows' => $rows]);
            break;

        // ── Alerts ─────────────────────────────────────────────────────────────
        // Response shape is always:
        //   { ok: true, alerts: [ ...alert rows ], summary: { counts... } }
        // so the dashboard JS can call `alerts.filter(...)` uniformly.
        case 'alerts':
            $run = ($_GET['run'] ?? '0') === '1';
            if ($run) {
                $alertEng->runAll();
            }
            $summary = $alertEng->getDashboardSummary();
            $alerts  = $summary['alerts'] ?? [];
            unset($summary['alerts']);
            $summary['total_detected'] = count($alerts);
            echo json_encode([
                'ok'      => true,
                'alerts'  => $alerts,
                'summary' => $summary,
            ]);
            break;

        case 'dismiss_alert':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $alertEng->dismissAlert($id, (int)$user['id']);
            echo json_encode(['ok' => true]);
            break;

        case 'dismiss_all_alerts':
            $count = $alertEng->dismissAll((int)$user['id']);
            echo json_encode(['ok' => true, 'dismissed' => $count]);
            break;

        // ── Budget vs Actual ────────────────────────────────────────────────────
        case 'budget_vs_actual':
            $month = $_GET['month'] ?? date('Y-m');
            $data  = $alertEng->getBudgetVsActual($month);
            echo json_encode(['ok' => true, 'budget' => $data, 'month' => $month]);
            break;

        // ── Crew Profitability ──────────────────────────────────────────────────
        case 'crew_profitability':
            $dateFrom = $_GET['date_from'] ?? date('Y-01-01');
            $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
            $data     = $alertEng->getCrewProfitability($dateFrom, $dateTo);
            echo json_encode(['ok' => true, 'crew' => $data]);
            break;

        default:
            throw new Exception("Unknown action: $action", 400);
    }

} catch (Throwable $e) {
    $rawCode = (int)$e->getCode(); // PDOException::getCode() returns SQLSTATE string; cast to int first
    $code    = ($rawCode >= 400 && $rawCode < 600) ? $rawCode : 500;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
