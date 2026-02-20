<?php
/**
 * Field Observations API
 * ──────────────────────
 * CRUD + workflow actions for crew field observations.
 * Crew logs observations during visits → triggers recommendation emails.
 *
 * Actions:
 *   create          — Log a new observation (crew or admin)
 *   list            — Paginated list (admin, filterable by status)
 *   get             — Single observation detail
 *   approve         — Mark as approved, send recommendation email
 *   dismiss         — Mark as dismissed with reason
 *   get-rules       — Return observation_product_rules for auto-suggest
 *   get-types       — Return valid observation types
 *
 * POST /app/Modules/Products/Api/field-observations.php?action=ACTION
 */
declare(strict_types=1);
header('Content-Type: application/json');

// Bootstrap
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

requireLogin();
$user = getCurrentUser();
$db = getDB();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {

    // ── CREATE ─────────────────────────────────────────────────────
    if ($action === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST required');
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        // CSRF validation
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $visitId    = isset($data['visit_id']) ? intval($data['visit_id']) : null;
        $propertyId = isset($data['property_id']) ? intval($data['property_id']) : 0;
        $contactId  = isset($data['contact_id']) ? intval($data['contact_id']) : 0;
        $obsType    = trim($data['observation_type'] ?? '');
        $obsValue   = trim($data['observation_value'] ?? '');
        $notes      = trim($data['notes'] ?? '');
        $photoMediaId = isset($data['photo_media_id']) ? intval($data['photo_media_id']) : null;

        // Validate type
        $validTypes = [
            'ph_test', 'soil_condition', 'pest_damage', 'weed_growth',
            'drainage_issue', 'lawn_disease', 'tree_hazard', 'hedge_overgrowth',
            'hardscape_damage', 'other'
        ];
        if (!in_array($obsType, $validTypes)) {
            throw new Exception('Invalid observation type: ' . htmlspecialchars($obsType));
        }

        // If visit_id provided, resolve property_id and contact_id from it
        if ($visitId) {
            $vStmt = $db->prepare("
                SELECT jp.property_id, p.site_contact_id AS contact_id
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                JOIN properties p ON p.id = jp.property_id
                WHERE jv.id = ?
            ");
            $vStmt->execute([$visitId]);
            $visit = $vStmt->fetch(PDO::FETCH_ASSOC);
            if ($visit) {
                $propertyId = (int)$visit['property_id'];
                $contactId  = (int)$visit['contact_id'];
            }
        }

        if (!$propertyId) throw new Exception('Property ID is required');
        if (!$contactId)  throw new Exception('Contact ID is required');

        // Check for matching rule → auto-suggest product + auto_send flag
        $recommendedProductId = null;
        $autoSend = 0;

        $hasRules = false;
        try {
            $db->query("SELECT 1 FROM observation_product_rules LIMIT 0");
            $hasRules = true;
        } catch (Exception $e) {}

        if ($hasRules) {
            $ruleStmt = $db->prepare("
                SELECT recommended_product_id, auto_send
                FROM observation_product_rules
                WHERE observation_type = ? AND is_active = 1
                ORDER BY priority DESC
                LIMIT 1
            ");
            $ruleStmt->execute([$obsType]);
            $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
            if ($rule) {
                $recommendedProductId = (int)$rule['recommended_product_id'];
                $autoSend = (int)$rule['auto_send'];
            }
        }

        // Allow override from request
        if (isset($data['recommended_product_id']) && intval($data['recommended_product_id']) > 0) {
            $recommendedProductId = intval($data['recommended_product_id']);
        }

        // Insert
        $stmt = $db->prepare("
            INSERT INTO field_observations
                (visit_id, property_id, contact_id, observation_type, observation_value,
                 notes, photo_media_id, recommended_product_id, status, auto_send, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ");
        $stmt->execute([
            $visitId,
            $propertyId,
            $contactId,
            $obsType,
            $obsValue ?: null,
            $notes ?: null,
            $photoMediaId ?: null,
            $recommendedProductId,
            $autoSend,
            $user['id'],
        ]);
        $obsId = (int)$db->lastInsertId();

        // Log to activity_log
        try {
            $db->prepare("
                INSERT INTO activity_log (user_id, contact_id, property_id, visit_id, action, description, created_at)
                VALUES (?, ?, ?, ?, 'field_observation', ?, NOW())
            ")->execute([
                $user['id'],
                $contactId,
                $propertyId,
                $visitId,
                "Logged {$obsType} observation" . ($obsValue ? ": {$obsValue}" : ''),
            ]);
        } catch (Exception $e) {
            // activity_log may have different columns — don't fail
        }

        // If auto_send and contact has marketing consent, send immediately
        if ($autoSend && $recommendedProductId) {
            // Check marketing consent
            $consentStmt = $db->prepare("SELECT receive_marketing, email FROM contacts WHERE id = ?");
            $consentStmt->execute([$contactId]);
            $contact = $consentStmt->fetch(PDO::FETCH_ASSOC);

            if ($contact && (int)($contact['receive_marketing'] ?? 0) === 1 && !empty($contact['email'])) {
                // Trigger email send (will be handled by the recommendation mailer)
                // For now, just mark as approved — the send logic is in the approve action
                $db->prepare("UPDATE field_observations SET status = 'approved' WHERE id = ?")->execute([$obsId]);
            }
        }

        echo json_encode([
            'success' => true,
            'observation_id' => $obsId,
            'recommended_product_id' => $recommendedProductId,
            'auto_send' => (bool)$autoSend,
            'message' => 'Observation logged successfully',
        ]);

    // ── LIST ──────────────────────────────────────────────────────
    } elseif ($action === 'list') {
        $status   = trim($_GET['status'] ?? '');
        $page     = max(1, intval($_GET['page'] ?? 1));
        $perPage  = 25;
        $offset   = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($status) {
            $where[] = 'fo.status = ?';
            $params[] = $status;
        }

        // Non-admin users only see their own observations
        if (($user['role'] ?? '') !== 'admin') {
            $where[] = 'fo.created_by = ?';
            $params[] = $user['id'];
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countStmt = $db->prepare("SELECT COUNT(*) FROM field_observations fo WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch page
        $params[] = $perPage;
        $params[] = $offset;
        $listStmt = $db->prepare("
            SELECT fo.*,
                   c.first_name AS contact_first, c.last_name AS contact_last, c.email AS contact_email,
                   pr.address AS property_address, pr.city AS property_city,
                   p.name AS product_name, p.image_url AS product_image,
                   u.full_name AS crew_name
            FROM field_observations fo
            LEFT JOIN contacts c ON c.id = fo.contact_id
            LEFT JOIN properties pr ON pr.id = fo.property_id
            LEFT JOIN products p ON p.id = fo.recommended_product_id
            LEFT JOIN users u ON u.id = fo.created_by
            WHERE {$whereClause}
            ORDER BY fo.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $listStmt->execute($params);
        $observations = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        // Status counts for tabs
        $countsByStatus = [];
        $csStmt = $db->query("
            SELECT status, COUNT(*) AS cnt FROM field_observations GROUP BY status
        ");
        while ($row = $csStmt->fetch(PDO::FETCH_ASSOC)) {
            $countsByStatus[$row['status']] = (int)$row['cnt'];
        }

        echo json_encode([
            'success' => true,
            'observations' => $observations,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => ceil($total / $perPage),
            'counts_by_status' => $countsByStatus,
        ]);

    // ── GET (single) ──────────────────────────────────────────────
    } elseif ($action === 'get') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) throw new Exception('Observation ID required');

        $stmt = $db->prepare("
            SELECT fo.*,
                   c.first_name AS contact_first, c.last_name AS contact_last, c.email AS contact_email,
                   c.phone AS contact_phone,
                   pr.address AS property_address, pr.city AS property_city,
                   p.name AS product_name, p.selling_price AS product_price,
                   p.short_description AS product_description, p.image_url AS product_image,
                   u.full_name AS crew_name,
                   ma.file_path AS photo_path, ma.thumbnail_path AS photo_thumb
            FROM field_observations fo
            LEFT JOIN contacts c ON c.id = fo.contact_id
            LEFT JOIN properties pr ON pr.id = fo.property_id
            LEFT JOIN products p ON p.id = fo.recommended_product_id
            LEFT JOIN users u ON u.id = fo.created_by
            LEFT JOIN media_assets ma ON ma.id = fo.photo_media_id
            WHERE fo.id = ?
        ");
        $stmt->execute([$id]);
        $obs = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$obs) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Observation not found']);
            exit;
        }

        // Get the matching email template from rules
        $template = null;
        if ($obs['observation_type']) {
            try {
                $tStmt = $db->prepare("
                    SELECT email_subject, email_body_template
                    FROM observation_product_rules
                    WHERE observation_type = ? AND is_active = 1
                    ORDER BY priority DESC LIMIT 1
                ");
                $tStmt->execute([$obs['observation_type']]);
                $template = $tStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }

        echo json_encode([
            'success' => true,
            'observation' => $obs,
            'email_template' => $template,
        ]);

    // ── APPROVE (send recommendation email) ──────────────────────
    } elseif ($action === 'approve') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');
        if (($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $obsId = intval($data['id'] ?? 0);
        if (!$obsId) throw new Exception('Observation ID required');

        // Optional custom subject/body override
        $customSubject = trim($data['email_subject'] ?? '');
        $customBody    = trim($data['email_body'] ?? '');

        // Load observation with all related data
        $stmt = $db->prepare("
            SELECT fo.*,
                   c.first_name, c.last_name, c.email AS contact_email, c.receive_marketing,
                   pr.address AS property_address, pr.city AS property_city,
                   p.name AS product_name, p.selling_price AS product_price,
                   p.short_description AS product_description,
                   ma.file_path AS photo_path
            FROM field_observations fo
            JOIN contacts c ON c.id = fo.contact_id
            LEFT JOIN properties pr ON pr.id = fo.property_id
            LEFT JOIN products p ON p.id = fo.recommended_product_id
            LEFT JOIN media_assets ma ON ma.id = fo.photo_media_id
            WHERE fo.id = ?
        ");
        $stmt->execute([$obsId]);
        $obs = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$obs) throw new Exception('Observation not found');
        if ($obs['status'] === 'email_sent') throw new Exception('Email already sent');

        if (empty($obs['contact_email'])) {
            throw new Exception('Contact has no email address');
        }

        // Get email template from rules (or use custom)
        $subject = $customSubject;
        $bodyTemplate = $customBody;

        if (!$subject || !$bodyTemplate) {
            try {
                $tStmt = $db->prepare("
                    SELECT email_subject, email_body_template
                    FROM observation_product_rules
                    WHERE observation_type = ? AND is_active = 1
                    ORDER BY priority DESC LIMIT 1
                ");
                $tStmt->execute([$obs['observation_type']]);
                $tpl = $tStmt->fetch(PDO::FETCH_ASSOC);
                if ($tpl) {
                    if (!$subject) $subject = $tpl['email_subject'];
                    if (!$bodyTemplate) $bodyTemplate = $tpl['email_body_template'];
                }
            } catch (Exception $e) {}
        }

        // Fallback default template
        if (!$subject) {
            $subject = 'A Recommendation From Your Recent Visit — Mowology';
        }
        if (!$bodyTemplate) {
            $bodyTemplate = '<p>Hi {{first_name}},</p>'
                . '<p>During our recent visit to your property at {{property_address}}, our crew noticed something we wanted to bring to your attention.</p>'
                . '<p><strong>Observation:</strong> {{observation_notes}}</p>'
                . '{{#product}}<p>We recommend: <strong>{{product_name}}</strong> — {{product_description}}</p>{{/product}}'
                . '<p>If you\'d like us to take care of this, just reply to this email or give us a call.</p>'
                . '<p>Thank you for trusting Mowology with your property.</p>'
                . '<p><strong>The Mowology Team</strong></p>';
        }

        // Merge fields
        $mergeData = [
            '{{first_name}}'        => htmlspecialchars($obs['first_name'] ?? ''),
            '{{last_name}}'         => htmlspecialchars($obs['last_name'] ?? ''),
            '{{property_address}}'  => htmlspecialchars(trim(($obs['property_address'] ?? '') . ', ' . ($obs['property_city'] ?? '')), ENT_QUOTES),
            '{{observation_type}}'  => ucwords(str_replace('_', ' ', $obs['observation_type'])),
            '{{observation_value}}' => htmlspecialchars($obs['observation_value'] ?? ''),
            '{{observation_notes}}' => nl2br(htmlspecialchars($obs['notes'] ?? $obs['observation_value'] ?? '')),
            '{{product_name}}'      => htmlspecialchars($obs['product_name'] ?? ''),
            '{{product_price}}'     => $obs['product_price'] ? '$' . number_format((float)$obs['product_price'], 2) : '',
            '{{product_description}}' => htmlspecialchars($obs['product_description'] ?? ''),
            '{{company_name}}'      => 'Mowology Landscaping',
        ];

        $subject = str_replace(array_keys($mergeData), array_values($mergeData), $subject);
        $body    = str_replace(array_keys($mergeData), array_values($mergeData), $bodyTemplate);

        // Handle conditional {{#product}}...{{/product}} blocks
        if ($obs['recommended_product_id']) {
            $body = preg_replace('/\{\{#product\}\}(.*?)\{\{\/product\}\}/s', '$1', $body);
        } else {
            $body = preg_replace('/\{\{#product\}\}.*?\{\{\/product\}\}/s', '', $body);
        }

        // Wrap in branded email template
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
        $year = date('Y');
        $photoHtml = '';
        if (!empty($obs['photo_path'])) {
            $photoUrl = $siteUrl . $obs['photo_path'];
            $photoHtml = '<tr><td style="padding:16px 32px;">'
                . '<img src="' . htmlspecialchars($photoUrl) . '" style="max-width:100%;border-radius:6px;" alt="Field observation photo">'
                . '</td></tr>';
        }

        $htmlEmail = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;">
        <tr><td style="background:#1A5F4A;padding:24px 32px;">
          <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">Mowology Landscaping</h1>
          <p style="color:rgba(255,255,255,0.8);margin:4px 0 0;font-size:13px;">Service Recommendation</p>
        </td></tr>
        <tr><td style="padding:28px 32px;font-size:14px;color:#333;line-height:1.6;">
          {$body}
        </td></tr>
        {$photoHtml}
        <tr><td style="background:#0D3B2E;padding:16px 32px;text-align:center;">
          <p style="color:rgba(255,255,255,0.6);font-size:11px;margin:0;">
            &copy; {$year} Mowology Landscaping &middot; <a href="{$siteUrl}" style="color:#7FD858;">{$siteUrl}</a>
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        // Send email
        require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
        $result = sendEmail($obs['contact_email'], $subject, $htmlEmail, null, 'Mowology Landscaping');

        // Update observation status
        $newStatus = $result['success'] ? 'email_sent' : 'approved';
        $db->prepare("
            UPDATE field_observations
            SET status = ?, email_sent_at = IF(? = 'email_sent', NOW(), NULL), updated_at = NOW()
            WHERE id = ?
        ")->execute([$newStatus, $newStatus, $obsId]);

        // Log to activity_log
        try {
            $db->prepare("
                INSERT INTO activity_log (user_id, contact_id, action, description, created_at)
                VALUES (?, ?, 'recommendation_email', ?, NOW())
            ")->execute([
                $user['id'],
                $obs['contact_id'],
                "Sent recommendation email for " . ucwords(str_replace('_', ' ', $obs['observation_type']))
                    . ($obs['product_name'] ? ": {$obs['product_name']}" : ''),
            ]);
        } catch (Exception $e) {}

        echo json_encode([
            'success' => $result['success'],
            'status' => $newStatus,
            'email_method' => $result['method'] ?? null,
            'error' => $result['error'] ?? null,
            'message' => $result['success']
                ? 'Recommendation email sent to ' . $obs['contact_email']
                : 'Email send failed: ' . ($result['error'] ?? 'Unknown error'),
        ]);

    // ── DISMISS ──────────────────────────────────────────────────
    } elseif ($action === 'dismiss') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');
        if (($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $obsId = intval($data['id'] ?? 0);
        $reason = trim($data['reason'] ?? '');
        if (!$obsId) throw new Exception('Observation ID required');

        $db->prepare("
            UPDATE field_observations SET status = 'dismissed', dismissed_reason = ?, updated_at = NOW() WHERE id = ?
        ")->execute([$reason ?: null, $obsId]);

        echo json_encode(['success' => true, 'message' => 'Observation dismissed']);

    // ── GET-RULES ────────────────────────────────────────────────
    } elseif ($action === 'get-rules') {
        $hasRules = false;
        try {
            $db->query("SELECT 1 FROM observation_product_rules LIMIT 0");
            $hasRules = true;
        } catch (Exception $e) {}

        if (!$hasRules) {
            echo json_encode(['success' => true, 'rules' => []]);
        } else {
            $rules = $db->query("
                SELECT opr.*, p.name AS product_name, p.selling_price AS product_price
                FROM observation_product_rules opr
                LEFT JOIN products p ON p.id = opr.recommended_product_id
                WHERE opr.is_active = 1
                ORDER BY opr.observation_type, opr.priority DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'rules' => $rules]);
        }

    // ── GET-TYPES ────────────────────────────────────────────────
    } elseif ($action === 'get-types') {
        echo json_encode([
            'success' => true,
            'types' => [
                ['value' => 'ph_test',           'label' => 'pH Test',             'icon' => 'thermometer'],
                ['value' => 'soil_condition',     'label' => 'Soil Condition',      'icon' => 'layers'],
                ['value' => 'pest_damage',        'label' => 'Pest Damage',         'icon' => 'alert-triangle'],
                ['value' => 'weed_growth',        'label' => 'Weed Growth',         'icon' => 'trending-up'],
                ['value' => 'drainage_issue',     'label' => 'Drainage Issue',      'icon' => 'droplet'],
                ['value' => 'lawn_disease',       'label' => 'Lawn Disease',        'icon' => 'alert-circle'],
                ['value' => 'tree_hazard',        'label' => 'Tree Hazard',         'icon' => 'alert-octagon'],
                ['value' => 'hedge_overgrowth',   'label' => 'Hedge Overgrowth',    'icon' => 'scissors'],
                ['value' => 'hardscape_damage',   'label' => 'Hardscape Damage',    'icon' => 'tool'],
                ['value' => 'other',              'label' => 'Other',               'icon' => 'edit-3'],
            ],
        ]);

    } else {
        throw new Exception('Invalid action: ' . htmlspecialchars($action));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
