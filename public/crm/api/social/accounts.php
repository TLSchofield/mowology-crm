<?php
/**
 * Social Accounts API
 *
 * Actions (GET):
 *   list         — all connected accounts
 *   oauth-init   — start OAuth flow (redirects to platform)
 *   locations    — list GBP locations after OAuth (uses session pending token)
 *
 * Actions (POST JSON):
 *   connect      — save a new account after OAuth + location selection
 *   disconnect   — revoke and remove an account
 *   toggle       — enable/disable an account
 *   templates    — list social_templates
 *   save-template — create/update a template
 *   delete-template — remove a template
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────
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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Social/Services/SocialEncryption.php';
require_once APP_ROOT . '/Modules/Social/Services/GoogleBusinessService.php';
require_once APP_ROOT . '/Modules/Social/Services/MetaService.php';

$action = $_GET['action'] ?? '';

// OAuth init is a redirect, not JSON
if ($action === 'oauth-init') {
    requireLogin();
    $user = getCurrentUser();
    requirePermission('marketing.approve');

    $platform = $_GET['platform'] ?? '';
    $state    = hash_hmac('sha256', session_id() . time(), defined('DB_PASS') ? DB_PASS : 'mowology');

    // Store state in session for callback verification
    $_SESSION['social_oauth_state']    = $state;
    $_SESSION['social_oauth_platform'] = $platform;
    $_SESSION['social_oauth_user_id']  = $user['id'];

    try {
        if ($platform === 'gbp') {
            $url = GoogleBusinessService::getAuthUrl($state);
        } elseif ($platform === 'facebook' || $platform === 'instagram') {
            $url = MetaService::getAuthUrl($state);
        } else {
            die('Unknown platform');
        }
        header('Location: ' . $url);
        exit;
    } catch (\Throwable $e) {
        $msg = urlencode('Could not start OAuth: ' . $e->getMessage());
        header('Location: /crm/marketing/social-accounts.php?error=' . $msg);
        exit;
    }
}

// All other actions: return JSON
header('Content-Type: application/json; charset=utf-8');
requireLogin();
$user = getCurrentUser();
session_write_close();

$db = getDB();

try {
    switch ($action) {

        // ── List connected accounts ──────────────────────────────────
        case 'list': {
            requirePermission('marketing.view');
            $stmt = $db->query("
                SELECT sa.id, sa.platform, sa.account_name, sa.location_name_display,
                       sa.is_active, sa.is_verified, sa.token_expires_at, sa.last_sync_at,
                       sa.connected_at, u.full_name AS connected_by_name
                FROM social_accounts sa
                LEFT JOIN users u ON u.id = sa.connected_by
                WHERE sa.is_active >= 0
                ORDER BY sa.platform ASC, sa.account_name ASC
            ");
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute token health
            foreach ($accounts as &$a) {
                $exp = $a['token_expires_at'] ? strtotime($a['token_expires_at']) : null;
                if (!$exp) {
                    // Meta page tokens never expire — NULL expiry means permanent, not unknown
                    $a['token_health'] = in_array($a['platform'], ['facebook', 'instagram'], true)
                        ? 'good'
                        : 'unknown';
                } elseif ($exp < time()) {
                    $a['token_health'] = 'expired';
                } elseif ($exp < time() + 86400 * 7) {
                    $a['token_health'] = 'expiring';
                } else {
                    $a['token_health'] = 'good';
                }
                unset($a['token_expires_at']); // Don't expose to frontend
            }
            unset($a);

            echo json_encode(['success' => true, 'accounts' => $accounts]);
            break;
        }

        // ── List GBP locations (uses pending token in session) ───────
        case 'locations': {
            requirePermission('marketing.approve');
            // Reload session to read pending OAuth data
            session_start();
            $pendingToken    = $_SESSION['social_pending_token'] ?? null;
            $pendingPlatform = $_SESSION['social_oauth_platform'] ?? '';
            session_write_close();

            if (!$pendingToken || $pendingPlatform !== 'gbp') {
                throw new RuntimeException('No pending GBP OAuth token. Start the OAuth flow first.');
            }

            $accounts  = GoogleBusinessService::listAccounts($pendingToken['access_token']);
            $locations = [];

            foreach ($accounts as $acct) {
                $locs = GoogleBusinessService::listLocations($pendingToken['access_token'], $acct['name']);
                foreach ($locs as $loc) {
                    $locations[] = [
                        'account_name'    => $acct['accountName'],
                        'account_resource' => $acct['name'],
                        'location_name'   => $loc['name'],
                        'title'           => $loc['title'],
                        'address'         => $loc['address'],
                    ];
                }
            }

            echo json_encode(['success' => true, 'locations' => $locations]);
            break;
        }

        // ── List Facebook Pages (uses pending token in session) ──────
        case 'pages': {
            requirePermission('marketing.approve');
            // Reload session to read pending OAuth data
            session_start();
            $pendingToken    = $_SESSION['social_pending_token'] ?? null;
            $pendingPlatform = $_SESSION['social_oauth_platform'] ?? '';
            session_write_close();

            if (!$pendingToken || !in_array($pendingPlatform, ['facebook', 'instagram'], true)) {
                throw new RuntimeException('No pending Meta OAuth token. Start the OAuth flow first.');
            }

            $pages = MetaService::listPages($pendingToken['access_token']);
            echo json_encode(['success' => true, 'pages' => $pages]);
            break;
        }

        // ── Save/connect account ─────────────────────────────────────
        case 'connect': {
            requirePermission('marketing.approve');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $platform     = $input['platform'] ?? '';
            $accountName  = trim($input['account_name'] ?? '');
            $locationName = trim($input['location_name'] ?? ''); // GBP resource name
            $locationDisplay = trim($input['location_display'] ?? '');
            $accountId    = trim($input['account_id_external'] ?? '');

            if (!$platform || !$accountName) {
                throw new InvalidArgumentException('platform and account_name required');
            }

            // Reload session for pending token
            session_start();
            $pendingToken = $_SESSION['social_pending_token'] ?? null;
            unset(
                $_SESSION['social_pending_token'],
                $_SESSION['social_oauth_state'],
                $_SESSION['social_oauth_platform'],
                $_SESSION['social_oauth_user_id']
            );
            session_write_close();

            if (!$pendingToken) {
                throw new RuntimeException('No pending OAuth token. Authenticate first.');
            }

            // ── Meta (Facebook / Instagram) ──────────────────────────
            // For Meta: the page token comes from the frontend (returned by listPages),
            // NOT from the user token stored in the session. Page tokens never expire.
            if (in_array($platform, ['facebook', 'instagram'], true)) {
                $pageToken = trim($input['page_token'] ?? '');
                $pageId    = trim($input['page_id'] ?? '');
                $igUserId  = trim($input['ig_user_id'] ?? '') ?: null;

                if (!$pageToken || !$pageId) {
                    throw new InvalidArgumentException('page_token and page_id are required for Meta accounts');
                }

                $metaJson = json_encode([
                    'page_id'    => $pageId,
                    'page_name'  => $accountName,
                    'ig_user_id' => $igUserId,
                ]);

                $db->prepare("
                    INSERT INTO social_accounts
                        (platform, account_name, account_id_external,
                         access_token_enc, refresh_token_enc, token_expires_at, token_scope,
                         meta_json, is_active, is_verified, connected_by, connected_at)
                    VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, 1, 1, ?, NOW())
                ")->execute([
                    $platform,
                    $accountName,
                    $pageId,
                    SocialEncryption::encrypt($pageToken),
                    $pendingToken['scope'] ?? null,
                    $metaJson,
                    $user['id'],
                ]);

                $newId = (int)$db->lastInsertId();
                GoogleBusinessService::auditLog($user['id'], 'account_connected', 'account', $newId,
                    "Connected {$platform} account: {$accountName} (Page ID: {$pageId})");

                echo json_encode(['success' => true, 'id' => $newId,
                    'message' => "{$accountName} connected successfully!"]);
                break;
            }

            // ── Google Business Profile (and other future platforms) ──
            $expiry = $pendingToken['expires_in']
                ? date('Y-m-d H:i:s', time() + (int)$pendingToken['expires_in'])
                : null;

            $db->prepare("
                INSERT INTO social_accounts
                    (platform, account_name, account_id_external, location_id_external,
                     location_name_display, access_token_enc, refresh_token_enc,
                     token_expires_at, token_scope, is_active, is_verified,
                     connected_by, connected_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, NOW())
            ")->execute([
                $platform,
                $accountName,
                $accountId ?: null,
                $locationName ?: null,
                $locationDisplay ?: $accountName,
                SocialEncryption::encrypt($pendingToken['access_token']),
                isset($pendingToken['refresh_token'])
                    ? SocialEncryption::encrypt($pendingToken['refresh_token'])
                    : null,
                $expiry,
                $pendingToken['scope'] ?? null,
                $user['id'],
            ]);
            $newId = (int)$db->lastInsertId();

            GoogleBusinessService::auditLog($user['id'], 'account_connected', 'account', $newId,
                "Connected $platform account: $accountName");

            echo json_encode(['success' => true, 'id' => $newId,
                'message' => "$accountName connected successfully!"]);
            break;
        }

        // ── Disconnect account ───────────────────────────────────────
        case 'disconnect': {
            requirePermission('marketing.approve');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id = (int)($input['id'] ?? 0);
            if (!$id) { throw new InvalidArgumentException('Missing id'); }

            $stmt = $db->prepare("SELECT * FROM social_accounts WHERE id = ?");
            $stmt->execute([$id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account) { throw new RuntimeException('Account not found'); }

            // Mark as removed (is_active = -1) — hidden from list but kept for
            // audit history and published post references.
            $db->prepare("UPDATE social_accounts SET is_active = -1, updated_at = NOW() WHERE id = ?")
               ->execute([$id]);

            GoogleBusinessService::auditLog($user['id'], 'account_disconnected', 'account', $id,
                "Disconnected: {$account['account_name']}");

            echo json_encode(['success' => true, 'message' => 'Account disconnected']);
            break;
        }

        // ── Toggle active state ──────────────────────────────────────
        case 'toggle': {
            requirePermission('marketing.approve');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id = (int)($input['id'] ?? 0);
            $db->prepare("UPDATE social_accounts SET is_active = NOT is_active WHERE id = ?")
               ->execute([$id]);

            echo json_encode(['success' => true]);
            break;
        }

        // ── List templates ───────────────────────────────────────────
        case 'templates': {
            requirePermission('marketing.view');
            $category = $_GET['category'] ?? '';
            $where    = 'st.is_active = 1';
            $params   = [];
            if ($category) {
                $where .= ' AND st.category = ?';
                $params[] = $category;
            }

            $stmt = $db->prepare("
                SELECT st.*, u.full_name AS created_by_name
                FROM social_templates st
                LEFT JOIN users u ON u.id = st.created_by
                WHERE $where
                ORDER BY st.category ASC, st.name ASC
            ");
            $stmt->execute($params);
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'templates' => $templates]);
            break;
        }

        // ── Save template ────────────────────────────────────────────
        case 'save-template': {
            requirePermission('marketing.edit');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id       = (int)($input['id'] ?? 0);
            $name     = trim($input['name'] ?? '');
            $category = trim($input['category'] ?? '');
            $caption  = trim($input['caption_template'] ?? '');
            $hashtags = trim($input['hashtag_preset'] ?? '');
            $cta      = trim($input['cta_preset'] ?? '');
            $ctaUrl   = trim($input['cta_url_preset'] ?? '');
            $targets  = trim($input['platform_targets'] ?? 'gbp,facebook,instagram');

            if (!$name || !$caption) {
                throw new InvalidArgumentException('Name and caption are required');
            }

            $validCategories = ['seasonal', 'upsell', 'proof_of_work', 'review_request', 'announcement'];
            if (!in_array($category, $validCategories, true)) {
                $category = 'announcement';
            }

            if ($id) {
                $db->prepare("
                    UPDATE social_templates
                    SET name = ?, category = ?, caption_template = ?, hashtag_preset = ?,
                        cta_preset = ?, cta_url_preset = ?, platform_targets = ?
                    WHERE id = ?
                ")->execute([$name, $category, $caption, $hashtags ?: null,
                    $cta ?: null, $ctaUrl ?: null, $targets, $id]);
            } else {
                $db->prepare("
                    INSERT INTO social_templates
                        (name, category, caption_template, hashtag_preset, cta_preset, cta_url_preset, platform_targets, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$name, $category, $caption, $hashtags ?: null,
                    $cta ?: null, $ctaUrl ?: null, $targets, $user['id']]);
                $id = (int)$db->lastInsertId();
            }

            echo json_encode(['success' => true, 'id' => $id]);
            break;
        }

        // ── Delete template ──────────────────────────────────────────
        case 'delete-template': {
            requirePermission('marketing.edit');
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            verifyCSRFToken($input['csrf_token'] ?? '');

            $id = (int)($input['id'] ?? 0);
            $db->prepare("UPDATE social_templates SET is_active = 0 WHERE id = ?")->execute([$id]);

            echo json_encode(['success' => true]);
            break;
        }

        // ── Audit log ────────────────────────────────────────────────
        case 'audit': {
            requirePermission('marketing.approve');
            $stmt = $db->query("
                SELECT sal.*, u.full_name AS user_name
                FROM social_audit_log sal
                LEFT JOIN users u ON u.id = sal.user_id
                ORDER BY sal.created_at DESC
                LIMIT 50
            ");
            $log = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'log' => $log]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (\Throwable $e) {
    error_log('social/accounts.php error: ' . $e->getMessage());
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
