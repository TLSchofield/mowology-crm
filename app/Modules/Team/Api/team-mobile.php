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
 *                 otto {items[≤12]: {id, key, kind, priority, text, detail, url, subject_id, propose{}, pin?{lat,lng}},
 *                       total, autolog {line, n, minutes, rows[{id, day, kind, address, minutes, start, end, …}]}|null,
 *                       review_url} | null   (jobs.edit; the web card's otto.php?mode=suggestions — added 2026-10-08)
 * POST {mode: 'otto_decide', suggestion_id, choice, …}  Otto's buttons — OttoActionService::decide, exactly the web
 *                                                       card's otto.php {mode: decide} (jobs.edit).
 * POST {mode: 'otto_undo_auto', id}                     Undo a contract visit Otto logged by himself (jobs.edit).
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
 * POST {mode: 'move', key, to: penny|sam|otto|mia|yui}  "Move to…" on a routed message (InboundRouteService):
 *                                                       re-routes it and learns sender + topic → head.
 * POST {mode: 'done', key}                              The routed message leaves every list.
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

/**
 * Otto's suggestions for the phone — the web card's GET otto.php?mode=suggestions (OpsDeskService,
 * recorded so a decision can be learned from), plus the contract auto-log line and the visits behind
 * it (Undo), and for "pin X m off" the property's current pin so the phone can map both.
 * null when the user lacks jobs.edit or Otto isn't set up (migrations 1150–1152). Never throws.
 */
function tmOttoDesk(PDO $db, array $jwtUser): ?array
{
    if (!jwtUserHasPermission($jwtUser, 'jobs.edit')) return null;
    try {
        if (!is_file(APP_ROOT . '/Modules/Operations/Services/OpsDeskService.php')) return null;
        require_once APP_ROOT . '/Modules/Operations/Services/OpsDeskService.php';
        $desk = new OpsDeskService($db);
        if (!$desk->ready()) return null;
        $items = array_values(array_filter($desk->current(true), fn($i) => ($i['sid'] ?? null) !== null));
        $total = count($items);
        $items = array_slice($items, 0, 12);

        // "pin X m off": where the pin is now (the item carries where the crews work).
        $pinIds = [];
        foreach ($items as $i) if (($i['kind'] ?? '') === 'pin_off') $pinIds[] = (int)($i['subject_id'] ?? 0);
        $pins = [];
        $pinIds = array_values(array_filter(array_unique($pinIds)));
        if ($pinIds) {
            $s = $db->prepare('SELECT id, latitude, longitude FROM properties WHERE id IN (' . implode(',', array_fill(0, count($pinIds), '?')) . ')');
            $s->execute($pinIds);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
                if ($p['latitude'] !== null && $p['longitude'] !== null) $pins[(int)$p['id']] = ['lat' => (float)$p['latitude'], 'lng' => (float)$p['longitude']];
            }
        }

        $out = [];
        foreach ($items as $i) {
            $row = [
                'id' => (int)$i['sid'], 'key' => (string)($i['key'] ?? ''), 'kind' => (string)($i['kind'] ?? ''),
                'priority' => (int)($i['priority'] ?? 2), 'text' => (string)($i['text'] ?? ''), 'detail' => (string)($i['detail'] ?? ''),
                'url' => $i['url'] ?? null, 'subject_id' => (int)($i['subject_id'] ?? 0),
                'propose' => (object)($i['propose'] ?? []),
            ];
            if (($i['kind'] ?? '') === 'pin_off' && isset($pins[(int)($i['subject_id'] ?? 0)])) $row['pin'] = $pins[(int)$i['subject_id']];
            $out[] = $row;
        }

        // Visits Otto logged by himself at contract sites (migration 1267) — the card line + Undo.
        $autolog = null;
        if (is_file(APP_ROOT . '/Modules/Operations/Services/OttoContractLogService.php')) {
            try {
                require_once APP_ROOT . '/Modules/Operations/Services/OttoContractLogService.php';
                $cl = new OttoContractLogService($db);
                $sum = $cl->summary();
                if ($sum) {
                    $rows = [];
                    foreach ($cl->recent(14) as $r) {
                        if (($r['status'] ?? '') !== 'logged') continue;
                        $rows[] = [
                            'id' => (int)$r['id'], 'day' => (string)$r['day'], 'kind' => (string)$r['kind'],
                            'address' => (string)($r['address'] ?? ''), 'minutes' => (int)($r['minutes'] ?? 0),
                            'start' => $r['start_time'] ? substr((string)$r['start_time'], 0, 5) : null,
                            'end' => $r['end_time'] ? substr((string)$r['end_time'], 0, 5) : null,
                            'moved_from' => $r['moved_from'] ?? null,
                            'contract' => (string)($r['contract_number'] ?? ''), 'invoice' => (string)($r['invoice_number'] ?? ''),
                        ];
                        if (count($rows) >= 10) break;
                    }
                    $autolog = ['line' => (string)$sum['line'], 'n' => (int)$sum['n'], 'minutes' => (int)$sum['minutes'], 'rows' => $rows];
                }
            } catch (Throwable $e) { /* migration 1267 not run */ }
        }

        return ['items' => $out, 'total' => $total, 'autolog' => $autolog, 'review_url' => '/crm/ops/otto-review.php'];
    } catch (Throwable $e) {
        error_log('[team-mobile] Otto desk: ' . $e->getMessage());
        return null;
    }
}

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
                $out['otto'] = tmOttoDesk($db, $jwtUser);
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

        case 'move':
        case 'done': {
            // "Move to…" / "Done" on a routed message (keys yui|otto|mia|penny|sam:reply:…) — InboundRouteService.
            if ($method !== 'POST') throw new RuntimeException('POST required');
            require_once APP_ROOT . '/Modules/Comms/Services/InboundRouteService.php';
            $route = new InboundRouteService($db);
            $ref = ['key' => substr((string)($input['key'] ?? ''), 0, 120)];
            echo json_encode($mode === 'move'
                ? $route->move($ref, (string)($input['to'] ?? ''), (int)$user['id'])
                : $route->done($ref, (int)$user['id']));
            break;
        }

        case 'otto_decide': {
            // Otto's suggestion buttons — exactly the web card's POST otto.php {mode: decide}.
            if ($method !== 'POST') throw new RuntimeException('POST required');
            if (!jwtUserHasPermission($jwtUser, 'jobs.edit')) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Otto is for people who run the schedule (jobs.edit).']);
                break;
            }
            require_once APP_ROOT . '/Modules/Operations/Services/OttoActionService.php';
            $r = (new OttoActionService($db))->decide((int)($input['suggestion_id'] ?? 0), $input, (int)$user['id']);
            if (!empty($r['ok'])) {
                try {   // the schedule strip shows the change at once (migration 1286), as otto.php does
                    if (is_file(APP_ROOT . '/Modules/Operations/Services/OttoScheduleService.php')) {
                        require_once APP_ROOT . '/Modules/Operations/Services/OttoScheduleService.php';
                        (new OttoScheduleService($db))->forget();
                    }
                } catch (Throwable $e) { /* the cache is a bonus */ }
            }
            echo json_encode($r);
            break;
        }

        case 'otto_undo_auto': {
            // Undo a visit Otto logged at a contract site — exactly POST otto-unscheduled.php {mode: undo_auto}.
            if ($method !== 'POST') throw new RuntimeException('POST required');
            if (!jwtUserHasPermission($jwtUser, 'jobs.edit')) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Otto is for people who run the schedule (jobs.edit).']);
                break;
            }
            require_once APP_ROOT . '/Modules/Operations/Services/OttoContractLogService.php';
            require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';   // VisitLifecycleService (stop status)
            echo json_encode((new OttoContractLogService($db))->undo((int)($input['id'] ?? 0), (int)$user['id']));
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
