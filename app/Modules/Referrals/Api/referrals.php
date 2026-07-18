<?php
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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Referrals/Services/ReferralRewardService.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
$user = getCurrentUser();

// Must run before session_write_close() below — verifyCSRFToken() reads
// $_SESSION, which session_write_close() clears from memory.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

session_write_close();

requirePermission('marketing.view');
$canEdit = userHasPermission('marketing.edit');

$db     = getDB();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ── Stats dashboard ───────────────────────────────────────────────────
        case 'stats': {
            $stats = $db->query("
                SELECT
                    (SELECT COUNT(DISTINCT contact_id) FROM referral_links WHERE invites_sent > 0) AS total_referrers,
                    (SELECT COUNT(*) FROM referrals)                                               AS total_referrals,
                    (SELECT COUNT(*) FROM referrals WHERE status = 'pending')                      AS pending,
                    (SELECT COUNT(*) FROM referrals WHERE status IN ('converted','rewarded'))      AS converted,
                    (SELECT COUNT(*) FROM referral_rewards WHERE status = 'applied')               AS rewards_applied,
                    (SELECT COALESCE(SUM(reward_value),0) FROM referral_rewards WHERE status = 'applied') AS total_reward_value
            ")->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
        }

        // ── Paginated referrals list ──────────────────────────────────────────
        case 'list': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 25;
            $offset  = ($page - 1) * $perPage;
            $status  = $_GET['status'] ?? '';

            $where  = '1=1';
            $params = [];
            if ($status && in_array($status, ['pending','converted','rewarded','cancelled'])) {
                $where    .= ' AND r.status = ?';
                $params[]  = $status;
            }

            $total = (int)$db->prepare("SELECT COUNT(*) FROM referrals r WHERE {$where}")
                ->execute($params) ? $db->prepare("SELECT COUNT(*) FROM referrals r WHERE {$where}") : 0;
            $countStmt = $db->prepare("SELECT COUNT(*) FROM referrals r WHERE {$where}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT
                    r.*,
                    CONCAT(rc.first_name, ' ', rc.last_name) AS referrer_name,
                    rc.email                                  AS referrer_email,
                    p.name                                    AS reward_product_name,
                    rr.status                                 AS reward_status,
                    rr.reward_value
                FROM referrals r
                LEFT JOIN contacts rc ON rc.id = r.referrer_contact_id
                LEFT JOIN referral_rewards rr ON rr.referral_id = r.id
                LEFT JOIN products p ON p.id = rr.reward_product_id
                WHERE {$where}
                ORDER BY r.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'   => true,
                'referrals' => $referrals,
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'pages'     => (int)ceil($total / $perPage),
            ]);
            break;
        }

        // ── Contacts with referral links (for invite UI) ──────────────────────
        case 'referrers': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 25;
            $offset  = ($page - 1) * $perPage;
            $search  = trim($_GET['q'] ?? '');

            $where  = 'c.is_active = 1';
            $params = [];
            if ($search) {
                $where    .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?)";
                $like      = '%' . $search . '%';
                $params    = array_merge($params, [$like, $like, $like]);
            }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM contacts c WHERE {$where}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT
                    c.id, c.first_name, c.last_name, c.email,
                    rl.referral_code,
                    rl.invites_sent,
                    rl.visits         AS link_visits,
                    rl.last_invite_sent_at,
                    (SELECT COUNT(*) FROM referrals r WHERE r.referrer_contact_id = c.id)                          AS referral_count,
                    (SELECT COUNT(*) FROM referrals r WHERE r.referrer_contact_id = c.id AND r.status IN ('converted','rewarded')) AS converted_count
                FROM contacts c
                LEFT JOIN referral_links rl ON rl.contact_id = c.id
                WHERE {$where}
                ORDER BY converted_count DESC, referral_count DESC, c.first_name ASC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $referrers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'   => true,
                'referrers' => $referrers,
                'total'     => $total,
                'page'      => $page,
                'pages'     => (int)ceil($total / $perPage),
            ]);
            break;
        }

        // ── Get/create referral link for a contact ────────────────────────────
        case 'get-link': {
            $contactId = (int)($_GET['contact_id'] ?? 0);
            if (!$contactId) {
                http_response_code(400);
                echo json_encode(['error' => 'contact_id required']);
                break;
            }

            $link = ReferralRewardService::getOrCreateLink($contactId, $db);
            $link['full_url'] = 'https://mowology.ca/quote?referral_code=' . $link['referral_code'];

            echo json_encode(['success' => true, 'link' => $link]);
            break;
        }

        // ── Send invite email to a contact ────────────────────────────────────
        case 'send-invite': {
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied']);
                break;
            }

            $input     = json_decode(file_get_contents('php://input'), true) ?? [];
            $contactId = (int)($input['contact_id'] ?? 0);
            if (!$contactId) {
                http_response_code(400);
                echo json_encode(['error' => 'contact_id required']);
                break;
            }

            $sent = ReferralRewardService::sendInviteEmail($contactId, $db);

            echo json_encode(['success' => $sent, 'message' => $sent ? 'Invite sent' : 'Failed to send — check contact has an email address']);
            break;
        }

        // ── Program settings ──────────────────────────────────────────────────
        case 'get-settings': {
            $stmt = $db->query("
                SELECT setting_key, setting_value
                FROM ops_settings
                WHERE setting_key IN (
                    'referral_program_enabled',
                    'referral_program_name',
                    'referral_reward_type',
                    'referral_reward_product_id',
                    'referral_referred_reward_value'
                )
            ");
            $rows     = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            // Also return the products list for the product dropdown
            $products = $db->query("SELECT id, name, base_price FROM products WHERE is_active = 1 ORDER BY name ASC")
                ->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'settings' => $settings, 'products' => $products]);
            break;
        }

        case 'save-settings': {
            if (!isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin only']);
                break;
            }

            $input   = json_decode(file_get_contents('php://input'), true) ?? [];
            $allowed = [
                'referral_program_enabled',
                'referral_program_name',
                'referral_reward_type',
                'referral_reward_product_id',
                'referral_referred_reward_value',
            ];

            $stmt = $db->prepare("
                INSERT INTO ops_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");

            foreach ($allowed as $key) {
                if (array_key_exists($key, $input)) {
                    $stmt->execute([$key, (string)$input[$key]]);
                }
            }

            echo json_encode(['success' => true]);
            break;
        }

        // ── Mark reward as applied (when scheduled/invoiced) ─────────────────
        case 'mark-reward-applied': {
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied']);
                break;
            }

            $input    = json_decode(file_get_contents('php://input'), true) ?? [];
            $rewardId = (int)($input['reward_id'] ?? 0);
            $note     = substr(trim($input['note'] ?? ''), 0, 500);

            if (!$rewardId) {
                http_response_code(400);
                echo json_encode(['error' => 'reward_id required']);
                break;
            }

            $db->prepare("
                UPDATE referral_rewards
                SET status = 'applied', admin_note = ?
                WHERE id = ?
            ")->execute([$note ?: null, $rewardId]);

            // Also mark parent referral as rewarded
            $db->prepare("
                UPDATE referrals r
                JOIN referral_rewards rr ON rr.referral_id = r.id
                SET r.status = 'rewarded'
                WHERE rr.id = ?
            ")->execute([$rewardId]);

            echo json_encode(['success' => true]);
            break;
        }

        // ── Cancel a referral ─────────────────────────────────────────────────
        case 'cancel': {
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied']);
                break;
            }

            $input      = json_decode(file_get_contents('php://input'), true) ?? [];
            $referralId = (int)($input['referral_id'] ?? 0);

            if (!$referralId) {
                http_response_code(400);
                echo json_encode(['error' => 'referral_id required']);
                break;
            }

            $db->prepare("UPDATE referrals SET status = 'cancelled' WHERE id = ?")->execute([$referralId]);
            $db->prepare("UPDATE referral_rewards SET status = 'cancelled' WHERE referral_id = ? AND status = 'pending'")->execute([$referralId]);

            echo json_encode(['success' => true]);
            break;
        }

        // ── Link a referred contact when they become a CRM contact ───────────
        case 'link-contact': {
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied']);
                break;
            }

            $input      = json_decode(file_get_contents('php://input'), true) ?? [];
            $referralId = (int)($input['referral_id'] ?? 0);
            $contactId  = (int)($input['contact_id'] ?? 0);

            if (!$referralId || !$contactId) {
                http_response_code(400);
                echo json_encode(['error' => 'referral_id and contact_id required']);
                break;
            }

            $db->prepare("
                UPDATE referrals SET referred_contact_id = ? WHERE id = ? AND status = 'pending'
            ")->execute([$contactId, $referralId]);

            echo json_encode(['success' => true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => "Unknown action: {$action}"]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
