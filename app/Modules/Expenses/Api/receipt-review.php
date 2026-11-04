<?php
/**
 * Receipt review layer (ReceiptReviewService, 2026-10-20). Office only (expenses.approve).
 *   GET                         → every waiting receipt with its evidence, by vendor; duplicate sets keeper-first
 *   POST {mode:'approve', ids}  → approves ONLY the receipts the person ticked as checked (ExpenseApprovalService —
 *                                 the gate's audited approve; Penny's read is decided as on her card)
 *   POST {mode:'merge', copy_id, keep_id} → the copy is set aside as a duplicate of the keeper (DuplicateReceiptService)
 *   POST {mode:'not_dupe', a, b} → "not a duplicate", remembered
 * Thin controller: no business logic here. `mode`, never `action` (router collision).
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
    if (!userHasPermission('expenses.approve')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Reviewing receipts needs expenses.approve.']);
        exit;
    }
    $user = getCurrentUser();
    $db = getDB();
    require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptReviewService.php';
    require_once APP_ROOT . '/Core/ApiResponse.php';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        session_write_close();
        echo json_encode(['ok' => true] + (new ReceiptReviewService($db))->build());
        exit;
    }

    $in = json_decode((string)file_get_contents('php://input'), true) ?? [];
    if (!verifyCSRFToken((string)($in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid security token — reload the page and try again.']);
        exit;
    }
    session_write_close();
    $mode = (string)($in['mode'] ?? '');

    if ($mode === 'approve') {
        require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseApprovalService.php';
        require_once APP_ROOT . '/Modules/Expenses/Services/BookkeeperDeskService.php';
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])), fn($i) => $i > 0)));
        if (!$ids || count($ids) > 100) throw new InvalidArgumentException('Tick the receipts you checked (at most 100 at a time).');
        $approval = new ExpenseApprovalService($db);
        $desk = new BookkeeperDeskService($db);
        $done = []; $failed = [];
        // Still waiting (a stale tab never re-approves), and never a suspected copy (it would book the purchase twice).
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id, status FROM expenses WHERE id IN ($in)");
        $st->execute($ids);
        $status = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $copies = array_flip((new ReceiptReviewService($db))->copyIds($ids));
        foreach ($ids as $id) {
            if (!in_array($status[$id] ?? '', ReceiptReviewService::WAITING, true)) { $failed[] = ['id' => $id, 'error' => 'no longer waiting']; continue; }
            if (isset($copies[$id])) { $failed[] = ['id' => $id, 'error' => 'a suspected duplicate — merge it or mark it not a duplicate first']; continue; }
            try {
                $r = $approval->approve($id, $user, ['source' => 'receipt_review']);
                if (empty($r['success'])) { $failed[] = ['id' => $id, 'error' => $r['message'] ?? 'Not approved']; continue; }
                $done[] = $id;
                try { $desk->decideOffCard($id, (int)$user['id']); } catch (Throwable $e) { error_log('Review: Penny decision #' . $id . ': ' . $e->getMessage()); }
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'error' => ApiResponse::message($e, 'receipt review approve')];
            }
        }
        echo json_encode(['ok' => true, 'approved' => $done, 'failed' => $failed]);
        exit;
    }

    if ($mode === 'merge' || $mode === 'not_dupe') {
        require_once APP_ROOT . '/Modules/Expenses/Services/DuplicateReceiptService.php';
        $dup = new DuplicateReceiptService($db);
        if ($mode === 'merge') {
            // removeCopies: refused unless they are ONE duplicate group now and the copy still waits; fills the keeper's gaps.
            echo json_encode($dup->removeCopies((int)($in['keep_id'] ?? 0), [(int)($in['copy_id'] ?? 0)], $user));
            exit;
        }
        // "Not a duplicate": every pair the copy is in within its set (a set of 3+ pairs it with the others, not only the keeper).
        $res = ['ok' => false, 'message' => 'Nothing to save'];
        foreach (array_slice((array)($in['pairs'] ?? [[$in['a'] ?? 0, $in['b'] ?? 0]]), 0, 20) as $pr) {
            $res = $dup->dismiss((int)($pr[0] ?? 0), (int)($pr[1] ?? 0), $user);
            if (empty($res['ok'])) break;
        }
        echo json_encode($res);
        exit;
    }

    throw new InvalidArgumentException('Unknown mode');
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => ApiResponse::message($e, 'receipt review')]);
} catch (Throwable $e) {
    error_log('[receipt-review] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — try again.']);
}
