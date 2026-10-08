<?php
/**
 * Label → product / machine, receipts → products, and Otto's care view.
 *
 * GET  ?mode=penny                 Penny's product proposals (one-tap cards)        expenses.edit
 * GET  ?mode=otto                  Otto's machine proposals + care view:            jobs.edit
 *                                  machines (intervals, due/soon, manual suggestions), low stock,
 *                                  product care notes / SDS, manual-read cap
 * POST multipart mode=capture      label_photo (+ lat, lng, csrf_token)            expenses.edit
 *                                  → never an expense; returns what was read and what was proposed
 * POST multipart mode=manual_read  equipment_id + manual (PDF or photo) + csrf     jobs.edit
 *                                  → "Ask Otto to read the manual" (Claude, daily cap)
 * POST JSON {mode, csrf_token, …}
 *   accept   {id, update_cost?, name?, unit_cost?}   (penny proposals: expenses.edit · otto: jobs.edit)
 *   dismiss  {id}                                     "Not now" — remembered
 *   manual_confirm {read_id, index, task?, every_hours?, every_days?}       jobs.edit
 *   manual_skip    {read_id, index}                                         jobs.edit
 *   save_sds       {product_id, url}                                        jobs.edit
 *   marketing_ok   {product_id, ok}                                         marketing.edit or admin
 *   backfill       {days?, limit?}   scan past receipts for product proposals      admin
 *
 * ?mode=, never ?action= (see the /api/ router note in the vault). Every write is Tim's click.
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
    require_once APP_ROOT . '/Modules/Products/Services/ProductProposalService.php';
    require_once APP_ROOT . '/Modules/Products/Services/ProductCareService.php';
    requireLogin();
    $user = getCurrentUser();
    $uid = (int)($user['id'] ?? 0);

    $method = $_SERVER['REQUEST_METHOD'];
    $isMultipart = $method === 'POST' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
    $input = $method === 'POST' ? ($isMultipart ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?? [])) : [];
    $mode = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'penny');

    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — reload the page and try again.']);
        exit;
    }
    $deny = function (string $why): void {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => $why]);
        exit;
    };
    $canPenny = userHasPermission('expenses.edit');
    $canOtto = userHasPermission('jobs.edit');

    $db = getDB();
    session_write_close();
    $pp = new ProductProposalService($db);

    if ($method === 'GET' && $mode === 'penny') {
        if (!$canPenny) $deny('Penny\'s product cards need expenses.edit.');
        echo json_encode(['ok' => true, 'ready' => $pp->ready(), 'items' => $pp->queue('penny'), 'waiting' => $pp->count('penny')]);
        exit;
    }

    if ($method === 'GET' && $mode === 'otto') {
        if (!$canOtto) $deny('Otto is for people who run the schedule (jobs.edit).');
        require_once APP_ROOT . '/Modules/Operations/Services/OttoManualService.php';
        $care = new ProductCareService($db);
        echo json_encode(['ok' => true, 'ready' => $pp->ready(), 'items' => $pp->queue('otto'), 'machines' => $care->machines(),
                          'low_stock' => $care->lowStock(), 'care' => $care->care(), 'manual' => (new OttoManualService($db))->status()]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
        exit;
    }

    switch ($mode) {
        case 'capture':
            if (!$canPenny) $deny('Label photos need expenses.edit.');
            require_once APP_ROOT . '/Modules/Products/Services/LabelCaptureService.php';
            $lat = isset($input['lat']) && $input['lat'] !== '' ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) && $input['lng'] !== '' ? (float)$input['lng'] : null;
            $r = (new LabelCaptureService($db))->capture($uid, $_FILES['label_photo'] ?? [], $lat, $lng);
            if (!empty($r['http_code'])) { http_response_code((int)$r['http_code']); unset($r['http_code']); }
            echo json_encode(['ok' => $r['success']] + $r);
            exit;

        case 'accept':
        case 'dismiss':
            $p = $pp->get((int)($input['id'] ?? 0));
            if (!$p) { echo json_encode(['ok' => false, 'message' => 'Not found']); exit; }
            if ($p['head'] === 'penny' ? !$canPenny : !$canOtto) $deny('Not allowed.');
            $r = $mode === 'accept'
                ? $pp->accept((int)$p['id'], $uid, ['update_cost' => !isset($input['update_cost']) || (bool)$input['update_cost'],
                                                    'name' => $input['name'] ?? null, 'unit_cost' => $input['unit_cost'] ?? null])
                : $pp->dismiss((int)$p['id'], $uid);
            echo json_encode($r);
            exit;

        case 'manual_read':
            if (!$canOtto) $deny('Otto is for people who run the schedule (jobs.edit).');
            require_once APP_ROOT . '/Modules/Operations/Services/OttoManualService.php';
            $f = $_FILES['manual'] ?? [];
            if (empty($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'message' => 'No file received.']); exit; }
            $mime = (string)mime_content_type($f['tmp_name']);
            $bytes = (string)file_get_contents($f['tmp_name']);
            $mediaId = null;
            // Keep the manual (deny-all folder, served by serve-receipt.php like receipts).
            if (in_array($mime, array_merge(['application/pdf'], OttoManualService::IMAGE_TYPES), true) && strlen($bytes) <= OttoManualService::MAX_PDF) {
                $dir = PUBLIC_ROOT . '/uploads/receipts/';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $stored = 'manual-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . ($mime === 'application/pdf' ? '.pdf' : '.jpg');
                if (@file_put_contents($dir . $stored, $bytes) !== false) {
                    $db->prepare("INSERT INTO media_assets (original_filename, stored_filename, file_path, file_type, mime_type, file_size, sha256, alt_text, context_type, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'Equipment manual', 'manual', ?)")
                       ->execute([(string)$f['name'], $stored, '/uploads/receipts/' . $stored, $mime === 'application/pdf' ? 'document' : 'image', $mime, strlen($bytes), hash('sha256', $bytes), $uid]);
                    $mediaId = (int)$db->lastInsertId();
                }
            }
            if (function_exists('set_time_limit')) @set_time_limit(180);
            echo json_encode((new OttoManualService($db))->read((int)($input['equipment_id'] ?? 0), $bytes, $mime, (string)$f['name'], $mediaId, $uid));
            exit;

        case 'manual_confirm':
        case 'manual_skip':
            if (!$canOtto) $deny('Otto is for people who run the schedule (jobs.edit).');
            require_once APP_ROOT . '/Modules/Operations/Services/OttoManualService.php';
            $m = new OttoManualService($db);
            $in = array_intersect_key($input, array_flip(['task', 'every_hours', 'every_days']));
            echo json_encode($mode === 'manual_confirm'
                ? $m->confirm((int)($input['read_id'] ?? 0), (int)($input['index'] ?? -1), $in, $uid)
                : $m->skip((int)($input['read_id'] ?? 0), (int)($input['index'] ?? -1)));
            exit;

        case 'save_sds':
            if (!$canOtto) $deny('Otto is for people who run the schedule (jobs.edit).');
            echo json_encode((new ProductCareService($db))->saveSds((int)($input['product_id'] ?? 0), (string)($input['url'] ?? '')));
            exit;

        case 'marketing_ok':
            if (!userHasPermission('marketing.edit') && !isAdmin()) $deny('Only someone who runs marketing can release a picture to Mia.');
            echo json_encode((new ProductCareService($db))->setMarketingOk((int)($input['product_id'] ?? 0), !empty($input['ok'])));
            exit;

        case 'backfill':
            if (!isAdmin()) $deny('Admin only.');
            if (function_exists('set_time_limit')) @set_time_limit(180);
            $r = $pp->backfill(max(1, min(365, (int)($input['days'] ?? ProductProposalService::BACKFILL_DAYS))), max(1, min(100, (int)($input['limit'] ?? 25))));
            echo json_encode(['ok' => true] + $r + ['message' => "Read {$r['scanned']} receipt(s), {$r['proposals']} proposal(s) made or grown, {$r['left']} still to read."]);
            exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
} catch (Throwable $e) {
    error_log('label-products: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — ' . $e->getMessage()]);
}
