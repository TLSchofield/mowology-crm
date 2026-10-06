<?php
/**
 * Receipt bookkeeper — admin endpoint.
 *
 * GET  ?mode=status    Is the bookkeeper usable? Key present / accepted by Anthropic
 *                      (a free model lookup — the key is never echoed), migration 1125.
 * GET  ?mode=report    Backtest accuracy per field and cost, per variant (photo / text).
 * POST {mode: 'backtest', limit, image, csrf_token}
 *                      Run the bookkeeper over approved receipts not yet backtested,
 *                      with their final values hidden, and store the scored suggestions.
 * Dashboard card (BookkeeperDeskService):
 * GET  ?mode=stats     The card's numbers.
 * GET  ?mode=queue     Prepared receipts for the carousel.
 * POST {mode: 'decide', suggestion_id, overrides?: {field: value}, save_draft?: bool, csrf_token}
 * POST {mode: 'prepare', max?, csrf_token}   Prepare the next receipts (daily-capped).
 * POST {mode: 'recheck', suggestion_id, csrf_token}  Penny reads one receipt again, with the photo.
 * POST {mode: 'reject', suggestion_id, reason, csrf_token}  Reject a receipt from the card.
 * GET  ?mode=bank_queue  Imported bank lines on the default account, with Penny's suggestion.
 * POST {mode: 'bank_decide', transaction_id, action: approve|keep, account_id?, suggested_id?, csrf_token}
 * GET  ?mode=dismissed_dupes  Pairs marked "not a duplicate" (the receipts page reads these).
 * POST {mode: 'not_dupe', pairs: [[a, b], ...], csrf_token}  "Not a duplicate" — remembered (migration 1127).
 * GET  ?mode=questions  Penny's open questions (scans for unbilled materials first).
 * POST {mode: 'answer', question_id, answer: invoice|contract|not_billable, csrf_token}
 *
 * ?mode=, not ?action= (see the /api/ router note in the vault). Owner/admin only:
 * every backtest call spends API credit.
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
    requirePermission('expenses.approve');
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? ($input['mode'] ?? '') : ($_GET['mode'] ?? 'status');

    if ($method === 'POST' && !verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptBookkeeperService.php';
    $svc = new ReceiptBookkeeperService($db);

    switch ($mode) {
        case 'status': {
            $keyPresent = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '';
            $keyAccepted = null;
            if ($keyPresent) {
                $ch = curl_init('https://api.anthropic.com/v1/models/' . rawurlencode(ReceiptBookkeeperService::model()));
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 20,
                    CURLOPT_HTTPHEADER     => ['x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
                ]);
                curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $keyAccepted = $code === 200;
            }
            $table = $db->query("SHOW TABLES LIKE 'expense_suggestions'")->rowCount() > 0;
            echo json_encode([
                'ok'            => true,
                'key_present'   => $keyPresent,
                'key_accepted'  => $keyAccepted,
                'key_check_http'=> $code ?? null,
                'model'         => ReceiptBookkeeperService::model(),
                'migration_1125'=> $table,
                'ready'         => $keyPresent && $keyAccepted && $table,
            ]);
            break;
        }

        case 'backtest': {
            if (!$svc->ready()) {
                throw new RuntimeException('Not ready — check ?mode=status');
            }
            set_time_limit(300);
            $limit = max(1, min(5, (int)($input['limit'] ?? 2)));
            $image = !empty($input['image']);
            $stmt = $db->prepare("
                SELECT e.id FROM expenses e
                WHERE e.status IN ('approved', 'forwarded')
                  AND e.raw_ocr_json IS NOT NULL AND e.raw_ocr_json <> ''
                  AND NOT EXISTS (SELECT 1 FROM expense_suggestions s
                                  WHERE s.expense_id = e.id AND s.source = 'backtest' AND s.used_image = ?)
                ORDER BY e.expense_date DESC, e.id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$image ? 1 : 0]);
            $done = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $r = $svc->suggest((int)$id, 'backtest', $image);
                $done[] = ['expense_id' => (int)$id, 'error' => $r['error'] ?? null];
            }
            $left = $db->prepare("
                SELECT COUNT(*) FROM expenses e
                WHERE e.status IN ('approved', 'forwarded') AND e.raw_ocr_json IS NOT NULL AND e.raw_ocr_json <> ''
                  AND NOT EXISTS (SELECT 1 FROM expense_suggestions s WHERE s.expense_id = e.id AND s.source = 'backtest' AND s.used_image = ?)
            ");
            $left->execute([$image ? 1 : 0]);
            echo json_encode(['ok' => true, 'ran' => $done, 'remaining' => (int)$left->fetchColumn()]);
            break;
        }

        case 'report': {
            $rows = $db->query("
                SELECT s.expense_id, s.used_image, s.suggestion_json, s.checks_json, s.input_tokens, s.output_tokens, s.error,
                       e.accounting_category, e.total, e.gst_amount, e.pst_amount, e.amount, e.job_id,
                       " . ($db->query("SHOW COLUMNS FROM expenses LIKE 'asset_tag'")->rowCount() ? 'e.asset_tag' : 'NULL AS asset_tag') . "
                FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
                WHERE s.source = 'backtest'
                ORDER BY s.id
            ")->fetchAll(PDO::FETCH_ASSOC);
            $items = $db->prepare("SELECT name FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");

            $variants = [];
            foreach ($rows as $r) {
                $v = $r['used_image'] ? 'photo+text' : 'text only';
                $variants[$v] ??= ['receipts' => 0, 'errors' => 0, 'fields' => [], 'in' => 0, 'out' => 0, 'failed_checks' => 0];
                $agg = &$variants[$v];
                $agg['receipts']++;
                $agg['in']  += (int)$r['input_tokens'];
                $agg['out'] += (int)$r['output_tokens'];
                if ($r['error']) { $agg['errors']++; unset($agg); continue; }
                $items->execute([(int)$r['expense_id']]);
                $score = ReceiptBookkeeperService::score(json_decode($r['suggestion_json'], true) ?: [], $r, $items->fetchAll(PDO::FETCH_COLUMN));
                foreach ($score as $field => $hit) {
                    if ($hit === 'n/a') continue;
                    $agg['fields'][$field] ??= ['right' => 0, 'of' => 0];
                    $agg['fields'][$field]['of']++;
                    if ($hit) $agg['fields'][$field]['right']++;
                }
                foreach (json_decode((string)$r['checks_json'], true) ?: [] as $c) {
                    if (empty($c['ok'])) $agg['failed_checks']++;
                }
                unset($agg);
            }
            foreach ($variants as &$agg) {
                foreach ($agg['fields'] as &$f) {
                    $f['pct'] = $f['of'] ? (int)round($f['right'] / $f['of'] * 100) : null;
                }
                unset($f);
                // Claude Opus 5.5 list prices: $4 / $20 per million input / output tokens.
                $agg['cost_usd'] = round($agg['in'] / 1e6 * 4 + $agg['out'] / 1e6 * 20, 2);
                $done = max(1, $agg['receipts']);
                $agg['cost_per_receipt_usd'] = round($agg['cost_usd'] / $done, 3);
                unset($agg['in'], $agg['out']);
            }
            unset($agg);
            echo json_encode(['ok' => true, 'variants' => $variants]);
            break;
        }

        case 'stats':
        case 'queue':
        case 'decide':
        case 'recheck':
        case 'reject':
        case 'prepare': {
            require_once APP_ROOT . '/Modules/Expenses/Services/BookkeeperDeskService.php';
            $desk = new BookkeeperDeskService($db, $svc);
            require_once APP_ROOT . '/Modules/Expenses/Services/DuplicateReceiptService.php';
            $dupSvc = new DuplicateReceiptService($db);
            if (!$desk->ready()) {
                throw new RuntimeException('Bookkeeper not set up (migration 1125)');
            }
            if ($mode === 'stats') {
                $rate = null;
                try {
                    require_once APP_ROOT . '/Modules/Accounting/Services/OwnerFreedomService.php';
                    $rate = (float)((new OwnerFreedomService($db))->settings()['owner_rate'] ?? 0) ?: null;
                } catch (Throwable $e) { /* no rate → no net saving */ }
                echo json_encode(['ok' => true, 'stats' => $desk->stats($rate)]);
            } elseif ($mode === 'queue') {
                // Possible duplicates are sorted first and never offered for approval.
                $dupes = $dupSvc->pairsInLine(60);
                echo json_encode(['ok' => true, 'dupes' => DuplicateReceiptService::groups($dupes),
                                  'queue' => $desk->queue((int)($_GET['limit'] ?? 10), DuplicateReceiptService::heldIds($dupes))]);
            } elseif ($mode === 'decide') {
                if ($method !== 'POST') throw new RuntimeException('POST required');
                $overrides = is_array($input['overrides'] ?? null) ? $input['overrides'] : [];
                $res = $desk->decide((int)($input['suggestion_id'] ?? 0), $overrides, $user, empty($input['save_draft']));
                echo json_encode($res);
            } elseif ($mode === 'reject') {
                if ($method !== 'POST') throw new RuntimeException('POST required');
                echo json_encode($desk->reject((int)($input['suggestion_id'] ?? 0), (string)($input['reason'] ?? ''), $user));
            } elseif ($mode === 'recheck') {
                if ($method !== 'POST') throw new RuntimeException('POST required');
                if (!$svc->ready()) throw new RuntimeException('Not ready — check ?mode=status');
                set_time_limit(180);
                echo json_encode($desk->recheck((int)($input['suggestion_id'] ?? 0)));
            } else {
                if ($method !== 'POST') throw new RuntimeException('POST required');
                if (!$svc->ready()) throw new RuntimeException('Not ready — check ?mode=status');
                set_time_limit(240);
                echo json_encode(['ok' => true] + $desk->prepare((int)($input['max'] ?? 2), DuplicateReceiptService::heldIds($dupSvc->pairsInLine(60))));
            }
            break;
        }

        case 'close_status': {
            require_once APP_ROOT . '/Modules/Accounting/Services/StatementCloseService.php';
            $close = new StatementCloseService($db);
            echo json_encode(['ok' => true, 'ready' => $close->ready(), 'accounts' => $close->status()]);
            break;
        }

        case 'bank_queue':
        case 'bank_decide': {
            require_once APP_ROOT . '/Modules/Accounting/Services/BankDeskService.php';
            $bank = new BankDeskService($db);
            if ($mode === 'bank_queue') {
                echo json_encode(['ok' => true, 'ready' => $bank->ready(), 'waiting' => $bank->waiting(),
                                  'lines' => $bank->queue((int)($_GET['limit'] ?? 10)), 'accounts' => $bank->ready() ? $bank->accounts() : []]);
            } else {
                if ($method !== 'POST') throw new RuntimeException('POST required');
                if (!userHasPermission('expenses.edit')) throw new RuntimeException('Permission denied: expenses.edit required');
                echo json_encode($bank->decide((int)($input['transaction_id'] ?? 0), (string)($input['action'] ?? ''),
                    isset($input['account_id']) ? (int)$input['account_id'] : null,
                    isset($input['suggested_id']) ? (int)$input['suggested_id'] : null, $user,
                    !empty($input['expense_id']) ? (int)$input['expense_id'] : null));
            }
            break;
        }

        case 'dismissed_dupes': {
            // For the receipts page: pairs marked "not a duplicate", so both places agree.
            require_once APP_ROOT . '/Modules/Expenses/Services/DuplicateReceiptService.php';
            $pairs = array_map(fn($k) => array_map('intval', explode('-', $k)), array_keys((new DuplicateReceiptService($db))->dismissed()));
            echo json_encode(['ok' => true, 'pairs' => $pairs]);
            break;
        }

        case 'not_dupe': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            require_once APP_ROOT . '/Modules/Expenses/Services/DuplicateReceiptService.php';
            // One or many pairs: [[a, b], ...] — "none of these are duplicates" for a group.
            $dup = new DuplicateReceiptService($db);
            $res = ['ok' => false, 'message' => 'Nothing to save'];
            foreach ((array)($input['pairs'] ?? [[$input['a'] ?? 0, $input['b'] ?? 0]]) as $pr) {
                $res = $dup->dismiss((int)($pr[0] ?? 0), (int)($pr[1] ?? 0), $user);
                if (!$res['ok']) break;
            }
            echo json_encode($res);
            break;
        }

        case 'questions':
        case 'answer': {
            require_once APP_ROOT . '/Modules/Expenses/Services/PennyQuestionService.php';
            $pq = new PennyQuestionService($db);
            if ($mode === 'questions') {
                $pq->scan(10);
                $pq->scanServiceAccounts();
                echo json_encode(['ok' => true, 'questions' => $pq->open(5, PennyQuestionService::firstName($user)), 'found_to_bill_month' => $pq->foundToBillMonth()]);
            } else {
                if ($method !== 'POST') throw new RuntimeException('POST required');
                echo json_encode($pq->answer((int)($input['question_id'] ?? 0), (string)($input['answer'] ?? ''), (int)$user['id'],
                    PennyQuestionService::firstName($user), isset($input['account_id']) ? (int)$input['account_id'] : null));
            }
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('bookkeeper.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
