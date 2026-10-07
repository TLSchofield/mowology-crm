<?php
/**
 * The department heads' cards for the iOS Team tab (Charlie, Otto, Mia, Yui) —
 * JWT-authenticated. Penny and Sam have their own endpoints (bookkeeper-mobile,
 * sales-head-mobile).
 *
 * GET  /api/team/team-mobile?mode=brief&head=charlie|otto|mia|yui
 *      → {ok, head, name, role, face_url, headline, waiting, brain, items[≤3]} plus, per head:
 *        charlie: ask {ready, cap, used, left}
 *        otto:    unpinned [{id, address, city, next_visit, geocode_address}]  (no map pin, visit in 14 days)
 *        mia:     post {id, title, body, photo_url, cta_type, cta_url} | null, google_mode live|drafts_only
 * POST {mode: 'act', key, what: open|snooze}           Charlie's learning, as the Action Board's
 *                                                       Open / Not now (owner only).
 * POST {mode: 'geocode', property_id, lat, lng}        Otto: save the pin the phone geocoded —
 *                                                       the same write as /crm/api/geocode-save.php.
 * POST {mode: 'gbp_post', id, what: publish|copied|dismiss, body?}
 *                                                       Mia: this week's Google post — exactly the web's
 *                                                       post_decide (publish posts to Google; live mode only).
 * POST {mode: 'ask', question}                          Charlie answers from CRM data (CharlieAskService,
 *                                                       daily-capped, Tim's tap only).
 *
 * Items are the dashboard Action Board's column for that head: Charlie's ranked today() view
 * (the same call /crm/api/charlie.php?mode=today makes), via TeamCardService::column().
 * Charlie works for the owner only (charlie_owner_user_id); another admin gets the head's own
 * brief, ranked by Charlie's learned order but without touching his memory.
 *
 * Auth: Authorization: Bearer <jwt>; admin/manager. No session, no CSRF token: the user array
 * is built from the JWT (id, role, name) — none of the services read $_SESSION.
 * Yui's replies stay drafts: nothing is sent from here.
 *
 * ?mode=, never ?action= (the /api/ router appends its own action).
 */
declare(strict_types=1);

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

header('Content-Type: application/json');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    $jwtUser = requireJwt();
    if (!jwtIsAdmin($jwtUser['role'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admins only']);
        exit;
    }
    $user = ['id' => (int)$jwtUser['id'], 'role' => $jwtUser['role'], 'full_name' => $jwtUser['name'], 'name' => $jwtUser['name']];

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'brief');

    $db = getDB();
    if (!function_exists('getWorkQueueItems')) require_once APP_ROOT . '/Services/CrmFunctions.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieForemanService.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/TeamCardService.php';
    $f = new CharlieForemanService($db);
    $desk = $f->desk;
    $name = CharlieVoice::firstName($user);
    $isOwner = $desk->ready() && $desk->isOwner($user);

    switch ($mode) {
        case 'brief': {
            $head = strtolower((string)($_GET['head'] ?? ''));
            if (!TeamCardService::isHead($head)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Unknown head']);
                break;
            }
            if (!$desk->ready()) {
                echo json_encode(['ok' => false, 'error' => 'Migration 1170 has not run']);
                break;
            }
            if ($isOwner) {
                // The Action Board's own call (charlie.php ?mode=today): the same ranked view.
                $view = $f->card($name)['view'];
                $desk->markSeen();
            } else {
                // Another admin: every head's brief ranked by Charlie's learned order — read-only.
                $c = (new CharlieBriefService($db))->collect($name);
                $ranked = CharlieRankService::rank($c['items'], $desk->prefs(), [], date('Y-m-d'));
                $heads = [];
                foreach ($c['heads'] as $slug => $h) $heads[$slug] = ['head' => $slug, 'headline' => $h['headline'], 'waiting' => count($h['items']), 'items' => []];
                $view = ['one' => null, 'rest' => $ranked, 'heads' => $heads];
            }
            $col = TeamCardService::column($view, $head);
            $svc = new TeamCardService($db);
            $out = [
                'ok'       => true,
                'head'     => $head,
                'name'     => TeamCardService::HEADS[$head]['name'],
                'role'     => TeamCardService::HEADS[$head]['role'],
                'face_url' => TeamCardService::faceUrl($head),
                'headline' => $col['headline'],
                'waiting'  => $col['waiting'],
                'owner'    => $isOwner,
                'brain'    => $svc->brain($head),
                'items'    => $col['items'],
            ];
            if ($head === 'charlie') {
                require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieAskService.php';
                $out['ask'] = (new CharlieAskService($db))->status();
            } elseif ($head === 'otto') {
                require_once APP_ROOT . '/Modules/Operations/Services/PropertyReadinessService.php';
                $out['unpinned'] = (new PropertyReadinessService($db))->unpinnedUpcoming(date('Y-m-d'), date('Y-m-d', strtotime('+14 days')), 3);
            } elseif ($head === 'mia') {
                $out['post'] = null;
                $out['google_mode'] = 'drafts_only';
                $out['can_approve'] = jwtUserHasPermission($jwtUser, 'marketing.approve');
                try {
                    require_once APP_ROOT . '/Modules/Marketing/Services/MiaChannelsService.php';
                    $ch = new MiaChannelsService($db);
                    if ($ch->ready()) {
                        $out['post'] = $ch->openPost();
                        $out['google_mode'] = (new GbpService($db))->mode()['mode'];
                    }
                } catch (Throwable $e) {
                    error_log('[team-mobile] Mia post: ' . $e->getMessage());
                }
            }
            echo json_encode($out);
            break;
        }

        case 'act': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            if (!$isOwner) { echo json_encode(['ok' => false, 'message' => 'Charlie learns from the owner only.']); break; }
            $what = (string)($input['what'] ?? '');
            if (!in_array($what, ['open', 'snooze'], true)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Unknown action']); break; }
            echo json_encode($desk->act(substr((string)($input['key'] ?? ''), 0, 120), $what));
            break;
        }

        case 'geocode': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            require_once APP_ROOT . '/Modules/Operations/Services/PropertyReadinessService.php';
            echo json_encode((new PropertyReadinessService($db))->savePin(
                (int)($input['property_id'] ?? 0), (float)($input['lat'] ?? 0), (float)($input['lng'] ?? 0)));
            break;
        }

        case 'gbp_post': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            if (!jwtUserHasPermission($jwtUser, 'marketing.approve')) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Permission denied: marketing.approve required']);
                break;
            }
            require_once APP_ROOT . '/Modules/Marketing/Services/MiaChannelsService.php';
            $ch = new MiaChannelsService($db);
            if (!$ch->ready()) { echo json_encode(['ok' => false, 'error' => 'Migration 1200 has not been run yet.']); break; }
            $what = (string)($input['what'] ?? '');
            if ($what === 'publish' && (new GbpService($db))->mode()['mode'] !== 'live') {
                echo json_encode(['ok' => false, 'error' => 'Google isn\'t connected for posting yet — copy the post instead.']);
                break;
            }
            // Exactly the web's POST mia-channels.php {mode: post_decide}.
            echo json_encode($ch->decidePost((int)($input['id'] ?? 0), $what, (int)$user['id'], isset($input['body']) ? (string)$input['body'] : null));
            break;
        }

        case 'ask': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            if (!$isOwner) { echo json_encode(['ok' => false, 'message' => 'Charlie answers the owner only.', 'left' => 0]); break; }
            require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieAskService.php';
            @set_time_limit(90);
            echo json_encode((new CharlieAskService($db))->ask((string)($input['question'] ?? ''), (int)$user['id']));
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[team-mobile] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The team hit a snag — try again.']);
}
