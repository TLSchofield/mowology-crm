<?php
/**
 * Email Templates API
 *
 * GET  /crm/api/email-templates.php             → list all templates
 * GET  /crm/api/email-templates.php?preview=KEY → rendered HTML preview with sample data
 * GET  /crm/api/email-templates.php?defaults=KEY → return hardcoded default for a key
 * POST /crm/api/email-templates.php             → update a template { key, subject, body_text }
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
require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

requireLogin();
$user = getCurrentUser();
requirePermission('settings.edit');

$db = getDB();

// ── Preview request (returns HTML, not JSON) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['preview'])) {
    $key = preg_replace('/[^a-z_]/', '', strtolower($_GET['preview']));
    servePreview($db, $key);
    exit;
}

// ── Default text request ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['defaults'])) {
    header('Content-Type: application/json');
    $key = preg_replace('/[^a-z_]/', '', strtolower($_GET['defaults']));
    $defaults = getDefaultTemplates();
    if (isset($defaults[$key])) {
        echo json_encode(['success' => true, 'template' => $defaults[$key]]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Unknown template key']);
    }
    exit;
}

header('Content-Type: application/json');

// ── GET — list all templates ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $rows = $db->query(
            "SELECT template_key, name, subject, body_text, updated_at
             FROM email_templates
             WHERE is_active = 1
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Merge with defaults so we always return every key even if DB is empty
        $defaults  = getDefaultTemplates();
        $byKey     = [];
        foreach ($rows as $row) {
            $byKey[$row['template_key']] = $row;
        }

        $result = [];
        foreach ($defaults as $key => $def) {
            if (isset($byKey[$key])) {
                $result[] = array_merge(['available_vars' => $def['vars']], $byKey[$key]);
            } else {
                $result[] = [
                    'template_key' => $key,
                    'name'         => $def['name'],
                    'subject'      => $def['subject'],
                    'body_text'    => $def['body'],
                    'updated_at'   => null,
                    'available_vars' => $def['vars'],
                ];
            }
        }

        echo json_encode(['success' => true, 'templates' => $result]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load templates']);
    }
    exit;
}

// ── POST — update a template ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }

        if (!isset($input['key'], $input['subject'], $input['body_text'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields: key, subject, body_text']);
            exit;
        }

        $key      = preg_replace('/[^a-z_]/', '', strtolower((string)$input['key']));
        $subject  = trim((string)$input['subject']);
        $bodyText = trim((string)$input['body_text']);

        $allowed = array_keys(getDefaultTemplates());
        if (!in_array($key, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown template key: ' . $key]);
            exit;
        }

        if (strlen($subject) < 3 || strlen($subject) > 255) {
            http_response_code(400);
            echo json_encode(['error' => 'Subject must be 3–255 characters']);
            exit;
        }

        // Upsert — insert on first save, update thereafter
        $stmt = $db->prepare("
            INSERT INTO email_templates (template_key, name, subject, body_text, updated_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                subject    = VALUES(subject),
                body_text  = VALUES(body_text),
                updated_by = VALUES(updated_by)
        ");

        $defaults  = getDefaultTemplates();
        $name      = $defaults[$key]['name'] ?? $key;
        $userId    = (int)($user['id'] ?? 0);

        $stmt->execute([$key, $name, $subject, $bodyText, $userId ?: null]);

        $updated = $db->query(
            "SELECT template_key, name, subject, body_text, updated_at
             FROM email_templates WHERE template_key = " . $db->quote($key) . " LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'template' => $updated]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save template']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);


// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Serve a full rendered HTML preview email.
 * Returns Content-Type: text/html.
 */
function servePreview(PDO $db, string $key): void
{
    $defaults = getDefaultTemplates();
    if (!isset($defaults[$key])) {
        http_response_code(404);
        echo '<p>Unknown template key: ' . htmlspecialchars($key) . '</p>';
        return;
    }

    // Load from DB, fall back to default
    $row = $db->prepare(
        "SELECT subject, body_text FROM email_templates WHERE template_key = ? LIMIT 1"
    );
    $row->execute([$key]);
    $tpl = $row->fetch(PDO::FETCH_ASSOC);

    $subject  = $tpl['subject']   ?? $defaults[$key]['subject'];
    $bodyText = $tpl['body_text'] ?? $defaults[$key]['body'];

    // Sample data for preview
    $sampleVars = [
        '{{customer_first_name}}' => 'Alex',
        '{{customer_name}}'       => 'Alex Johnson',
        '{{quote_number}}'        => 'QUO-2026-0042',
        '{{quote_amount}}'        => '$184.95',
        '{{quote_valid_until}}'   => 'April 15, 2026',
        '{{invoice_number}}'      => 'INV-2026-0021',
        '{{amount_due}}'          => '$184.95',
        '{{amount_paid}}'         => '$184.95',
        '{{due_date}}'            => 'April 1, 2026',
        '{{payment_date}}'        => 'March 15, 2026',
        '{{service_type}}'        => 'Spring Lawn Care',
        '{{job_date}}'            => 'March 15, 2026',
        '{{property_address}}'    => '2526 West 5th Avenue, Vancouver',
        '{{company_name}}'        => 'Mowology Landscaping',
        '{{company_phone}}'       => '(778) 846-9273',
    ];

    $renderedSubject = str_replace(array_keys($sampleVars), array_values($sampleVars), $subject);
    $renderedBody    = str_replace(array_keys($sampleVars), array_values($sampleVars), $bodyText);
    $bodyHtml        = EmailWrapper::textToHtml($renderedBody);

    $ctaLabels = array_map(fn($d) => $d['cta'], $defaults);
    $ctaUrls = [
        'quote_sent'     => 'https://mowology.ca/customer/quote.php?token=PREVIEW',
        'quote_followup' => 'https://mowology.ca/customer/quote.php?token=PREVIEW',
        'invoice_sent'   => 'https://mowology.ca/customer/invoice.php?token=PREVIEW',
        'receipt_sent'   => 'https://mowology.ca/customer/invoice.php?token=PREVIEW',
        'job_complete'   => 'https://mowology.ca/customer/pow.php?token=PREVIEW',
    ];

    // Prepend preview banner inside body
    $previewBanner = '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 16px;margin-bottom:20px;font-family:\'Helvetica Neue\',Arial,sans-serif;font-size:13px;color:#856404;">
        <strong>Preview Mode</strong> — Sample data substituted. Subject: <em>' . htmlspecialchars($renderedSubject) . '</em>
    </div>';

    header('Content-Type: text/html; charset=UTF-8');
    echo EmailWrapper::wrap(
        $previewBanner . $bodyHtml,
        $ctaLabels[$key] ?? 'View Online',
        $ctaUrls[$key]   ?? 'https://mowology.ca',
        EmailWrapper::getCompanyInfo()
    );
}

/**
 * Canonical default templates — one copy, shared with loadEmailTemplate() and the seed.
 * Used for: seeding, "Reset to Default", API GET fallback, validation.
 */
function getDefaultTemplates(): array
{
    require_once APP_ROOT . '/Services/Messaging/EmailTemplateDefaults.php';
    return emailTemplateDefaults();
}
