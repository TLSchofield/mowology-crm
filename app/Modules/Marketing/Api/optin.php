<?php
/**
 * Marketing Opt-In Refresh API
 *
 * Actions:
 *   stats        — opt-in overview stats
 *   list         — list contacts with opt-in token status
 *   send         — send (or resend) opt-in email to a contact
 *   send-bulk    — send to all contacts who haven't confirmed
 *   confirm      — confirm opt-in via token (public endpoint)
 *   unsubscribe  — unsubscribe via token (public endpoint)
 *   status       — get preferences for a contact
 *   update       — admin: manually update preferences
 *
 * @package Mowology\Marketing
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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/Messaging/TemplateRenderer.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$db = getDB();

// Public actions (no auth required)
$publicActions = ['confirm', 'unsubscribe'];
if (!in_array($action, $publicActions)) {
    session_write_close();
    requireLogin();
    $user = getCurrentUser();
}

try {
    switch ($action) {

        // ── Stats overview ───────────────────────────────────────────────
        case 'stats':
            requirePermission('marketing.view');

            $total      = (int)$db->query("SELECT COUNT(*) FROM contacts WHERE is_active=1 AND email IS NOT NULL AND email != ''")->fetchColumn();
            $confirmed  = (int)$db->query("SELECT COUNT(*) FROM marketing_optin_tokens WHERE status='confirmed'")->fetchColumn();
            $pending    = (int)$db->query("SELECT COUNT(*) FROM marketing_optin_tokens WHERE status='pending'")->fetchColumn();
            $unsubbed   = (int)$db->query("SELECT COUNT(*) FROM marketing_optin_tokens WHERE status='unsubscribed'")->fetchColumn();
            $neverSent  = $total - $confirmed - $pending - $unsubbed;

            // Confirmed in last 30 days
            $recentConf = (int)$db->query("SELECT COUNT(*) FROM marketing_optin_tokens WHERE status='confirmed' AND confirmed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

            echo json_encode([
                'success'           => true,
                'total_contacts'    => $total,
                'confirmed'         => $confirmed,
                'pending'           => $pending,
                'unsubscribed'      => $unsubbed,
                'never_sent'        => max(0, $neverSent),
                'recent_confirmed'  => $recentConf,
                'confirm_rate'      => $total > 0 ? round(($confirmed / $total) * 100, 1) : 0,
            ]);
            break;

        // ── List contacts with opt-in status ─────────────────────────────
        case 'list':
            requirePermission('marketing.view');

            $filter = $_GET['filter'] ?? '';  // 'confirmed' | 'pending' | 'unsubscribed' | 'never_sent'
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 25;
            $offset = ($page - 1) * $perPage;

            $where = "c.is_active = 1 AND c.email IS NOT NULL AND c.email != ''";
            $params = [];

            $join = "LEFT JOIN marketing_optin_tokens mot ON mot.contact_id = c.id AND mot.id = (
                SELECT id FROM marketing_optin_tokens WHERE contact_id = c.id ORDER BY created_at DESC LIMIT 1
            )";

            if ($filter === 'confirmed')   { $where .= ' AND mot.status = ?'; $params[] = 'confirmed'; }
            if ($filter === 'pending')     { $where .= ' AND mot.status = ?'; $params[] = 'pending'; }
            if ($filter === 'unsubscribed'){ $where .= ' AND mot.status = ?'; $params[] = 'unsubscribed'; }
            if ($filter === 'never_sent')  { $where .= ' AND mot.id IS NULL'; }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM contacts c $join WHERE $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT c.id, c.first_name, c.last_name, c.email, c.receive_marketing,
                       mot.status AS optin_status,
                       mot.sent_at, mot.confirmed_at, mot.resent_count, mot.last_resent_at
                FROM contacts c
                $join
                WHERE $where
                ORDER BY c.last_name, c.first_name
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute($params);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'   => true,
                'contacts'  => $contacts,
                'total'     => $total,
                'pages'     => max(1, (int)ceil($total / $perPage)),
            ]);
            break;

        // ── Send opt-in email to one contact ─────────────────────────────
        case 'send':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');

            $contactId = (int)($input['contact_id'] ?? 0);
            if (!$contactId) { echo json_encode(['success' => false, 'error' => 'Contact ID required']); break; }

            $result = sendOptInEmail($db, $contactId);
            echo json_encode($result);
            break;

        // ── Bulk send opt-in emails ───────────────────────────────────────
        case 'send-bulk':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');

            $target = $input['target'] ?? 'never_sent'; // 'never_sent' | 'pending' | 'all'
            $resendAfterDays = (int)($input['resend_after_days'] ?? 7);

            // Find eligible contacts
            $where = "c.is_active = 1 AND c.email IS NOT NULL AND c.email != ''";
            $params = [];

            if ($target === 'never_sent') {
                $where .= " AND c.id NOT IN (SELECT contact_id FROM marketing_optin_tokens)";
            } elseif ($target === 'pending') {
                $where .= " AND c.id IN (
                    SELECT contact_id FROM marketing_optin_tokens
                    WHERE status = 'pending'
                      AND (last_resent_at IS NULL OR last_resent_at < DATE_SUB(NOW(), INTERVAL ? DAY))
                      AND (sent_at IS NULL OR sent_at < DATE_SUB(NOW(), INTERVAL ? DAY))
                )";
                $params[] = $resendAfterDays;
                $params[] = $resendAfterDays;
            }

            $stmt = $db->prepare("SELECT c.id FROM contacts c WHERE $where ORDER BY c.id LIMIT 200");
            $stmt->execute($params);
            $contactIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $sent  = 0;
            $errors = 0;
            foreach ($contactIds as $cid) {
                $r = sendOptInEmail($db, (int)$cid);
                if ($r['success']) $sent++; else $errors++;
            }

            echo json_encode(['success' => true, 'sent' => $sent, 'errors' => $errors, 'total' => count($contactIds)]);
            break;

        // ── Public: Confirm via token ─────────────────────────────────────
        case 'confirm':
            $token = $_GET['token'] ?? '';
            if (empty($token)) {
                echo json_encode(['success' => false, 'error' => 'Token required']); break;
            }

            $stmt = $db->prepare("SELECT * FROM marketing_optin_tokens WHERE token = ? AND status = 'pending'");
            $stmt->execute([hash('sha256', $token)]); // compare stored hash
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                // Try exact token match (v1 format)
                $stmt = $db->prepare("SELECT * FROM marketing_optin_tokens WHERE token = ? AND status = 'pending'");
                $stmt->execute([$token]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$record) {
                echo json_encode(['success' => false, 'error' => 'Invalid or expired token']); break;
            }

            if ($record['expires_at'] && strtotime($record['expires_at']) < time()) {
                $db->prepare("UPDATE marketing_optin_tokens SET status='expired' WHERE id=?")->execute([$record['id']]);
                echo json_encode(['success' => false, 'error' => 'This link has expired. Please request a new one.']); break;
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $db->prepare("UPDATE marketing_optin_tokens SET status='confirmed', confirmed_at=NOW(), ip_address=? WHERE id=?")->execute([$ip, $record['id']]);
            $db->prepare("UPDATE contacts SET receive_marketing=1 WHERE id=?")->execute([$record['contact_id']]);

            // Upsert preferences
            $db->prepare("
                INSERT INTO client_marketing_preferences (contact_id, email_opt_in, confirmed_at, confirmation_method)
                VALUES (?, 1, NOW(), 'optin_email')
                ON DUPLICATE KEY UPDATE email_opt_in=1, confirmed_at=NOW(), confirmation_method='optin_email'
            ")->execute([$record['contact_id']]);

            // Add tag
            $db->prepare("
                INSERT IGNORE INTO contact_tags (contact_id, tag, added_at)
                VALUES (?, 'Marketing Confirmed', NOW())
            ")->execute([$record['contact_id']]);

            echo json_encode(['success' => true, 'message' => 'Thank you! You have been successfully subscribed.']);
            break;

        // ── Public: Unsubscribe via token ─────────────────────────────────
        case 'unsubscribe':
            $token = $_GET['token'] ?? '';
            $email = $_GET['email'] ?? '';
            if (empty($token) || empty($email)) {
                echo json_encode(['success' => false, 'error' => 'Invalid unsubscribe link']); break;
            }

            // Verify HMAC token (same method as TemplateRenderer::generateUnsubscribeUrl)
            $secret = defined('UNSUBSCRIBE_SECRET') ? UNSUBSCRIBE_SECRET : 'mowology-unsub-2026';
            $sendId = (int)($_GET['sid'] ?? 0);
            $expected = hash_hmac('sha256', $email . '|' . $sendId, $secret);
            if (!hash_equals($expected, $token)) {
                echo json_encode(['success' => false, 'error' => 'Invalid token']); break;
            }

            $email = strtolower(trim($email));

            // Mark unsubscribed
            $db->prepare("UPDATE contacts SET receive_marketing=0 WHERE email=?")->execute([$email]);
            $db->prepare("INSERT IGNORE INTO marketing_unsubscribes (email, reason, unsubscribed_at) VALUES (?, 'unsubscribe_link', NOW())")->execute([$email]);
            $db->prepare("UPDATE marketing_optin_tokens SET status='unsubscribed' WHERE email=?")->execute([$email]);

            echo json_encode(['success' => true, 'message' => 'You have been unsubscribed from marketing emails.']);
            break;

        // ── Get preferences for a contact ────────────────────────────────
        case 'status':
            requirePermission('marketing.view');
            $id = (int)($_GET['contact_id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'Contact ID required']); break; }

            $prefs = $db->prepare("SELECT * FROM client_marketing_preferences WHERE contact_id=?");
            $prefs->execute([$id]);
            $preferences = $prefs->fetch(PDO::FETCH_ASSOC);

            $latest = $db->prepare("SELECT * FROM marketing_optin_tokens WHERE contact_id=? ORDER BY created_at DESC LIMIT 1");
            $latest->execute([$id]);
            $token = $latest->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'preferences' => $preferences, 'latest_token' => $token]);
            break;

        // ── Admin: manually update preferences ───────────────────────────
        case 'update':
            requirePermission('marketing.edit');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            verifyCSRFToken($input['csrf_token'] ?? '');

            $contactId = (int)($input['contact_id'] ?? 0);
            if (!$contactId) { echo json_encode(['success' => false, 'error' => 'Contact ID required']); break; }

            $emailOptIn = (int)($input['email_opt_in'] ?? 0);
            $smsOptIn   = (int)($input['sms_opt_in'] ?? 0);

            $db->prepare("
                INSERT INTO client_marketing_preferences (contact_id, email_opt_in, sms_opt_in, confirmed_at, confirmation_method)
                VALUES (?, ?, ?, NOW(), 'admin_manual')
                ON DUPLICATE KEY UPDATE email_opt_in=?, sms_opt_in=?, confirmation_method='admin_manual'
            ")->execute([$contactId, $emailOptIn, $smsOptIn, $emailOptIn, $smsOptIn]);

            $db->prepare("UPDATE contacts SET receive_marketing=? WHERE id=?")->execute([$emailOptIn, $contactId]);

            if ($emailOptIn) {
                $db->prepare("INSERT IGNORE INTO contact_tags (contact_id, tag, added_by, added_at) VALUES (?, 'Marketing Confirmed', ?, NOW())")->execute([$contactId, $user['id']]);
            } else {
                $db->prepare("DELETE FROM contact_tags WHERE contact_id=? AND tag='Marketing Confirmed'")->execute([$contactId]);
                $c = $db->prepare("SELECT email FROM contacts WHERE id=?"); $c->execute([$contactId]);
                $email = $c->fetchColumn();
                if ($email) {
                    $db->prepare("INSERT IGNORE INTO marketing_unsubscribes (email, reason, unsubscribed_at) VALUES (?, 'admin_removed', NOW())")->execute([$email]);
                }
            }

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (Throwable $e) {
    error_log('optin API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}


// ── Helper: send opt-in email to a contact ─────────────────────────────────

function sendOptInEmail(PDO $db, int $contactId): array
{
    $stmt = $db->prepare("SELECT id, first_name, last_name, email FROM contacts WHERE id=? AND is_active=1");
    $stmt->execute([$contactId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact || empty($contact['email'])) {
        return ['success' => false, 'error' => 'Contact not found or no email'];
    }

    // Check for existing unexpired pending token
    $existing = $db->prepare("SELECT id, resent_count FROM marketing_optin_tokens WHERE contact_id=? AND status='pending' ORDER BY created_at DESC LIMIT 1");
    $existing->execute([$contactId]);
    $existingToken = $existing->fetch(PDO::FETCH_ASSOC);

    // Generate a secure token
    $rawToken  = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    if ($existingToken) {
        // Resend: update token and increment count
        $db->prepare("UPDATE marketing_optin_tokens SET token=?, expires_at=?, resent_count=resent_count+1, last_resent_at=NOW() WHERE id=?")
           ->execute([$rawToken, $expiresAt, $existingToken['id']]);
    } else {
        $db->prepare("INSERT INTO marketing_optin_tokens (contact_id, email, token, status, sent_at, expires_at) VALUES (?,?,?,'pending',NOW(),?)")
           ->execute([$contactId, $contact['email'], $rawToken, $expiresAt]);
    }

    $baseUrl   = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
    $confirmUrl = $baseUrl . '/optin-confirm.php?token=' . urlencode($rawToken);
    $firstName  = $contact['first_name'] ?? 'Valued Customer';

    $subject = 'Please confirm your email preferences — Mowology Landscaping';
    $body = '<h2>Hi ' . htmlspecialchars($firstName) . ',</h2>
<p>We\'ve recently upgraded our marketing system and want to make sure you still want to hear from us.</p>
<p>We send occasional updates about seasonal services, special offers, and landscaping tips tailored to your property.</p>
<p style="margin:24px 0;">
  <a href="' . htmlspecialchars($confirmUrl) . '"
     style="background:#2D8659;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:600;display:inline-block;">
    ✓ Yes, keep me subscribed
  </a>
</p>
<p style="color:#666;font-size:13px;">If you do not wish to receive marketing emails, simply ignore this message. You can always unsubscribe at any time.</p>
<p style="color:#666;font-size:13px;">This link expires in 30 days.</p>';

    require_once CRM_INCLUDES . '/messaging.php';
    $sent = sendCrmEmail($contact['email'], $subject, $body, $contact['first_name'] . ' ' . ($contact['last_name'] ?? ''));

    if (!$sent) {
        return ['success' => false, 'error' => 'Failed to send email'];
    }

    $db->prepare("UPDATE marketing_optin_tokens SET sent_at=NOW() WHERE token=?")->execute([$rawToken]);

    return ['success' => true, 'contact_id' => $contactId];
}
