<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/invoice-create-send.php
 *
 * Mobile Invoice Create + Send API — JWT authenticated, admin/manager only.
 *
 * POST /api/schedule/invoice-create-send
 * Content-Type: application/json
 *
 * Body:
 * {
 *   "visit_id":            42,
 *   "plan_id":             7,       // nullable
 *   "company_id":          3,       // nullable
 *   "contact_id":          9,       // nullable
 *   "property_id":         12,      // nullable
 *   "due_date":            "2026-06-08",
 *   "notes":               "Optional notes.",
 *   "line_items": [
 *     { "description": "Weekly Lawn Mowing", "quantity": 1.0,
 *       "unit_price": 75.00, "line_total": 75.00, "sort_order": 1 }
 *   ],
 *   "recipient_contact_ids": [9],
 *   "send_immediately":    true
 * }
 *
 * Response 200:
 * {
 *   "success": true,
 *   "invoice_id": 88,
 *   "invoice_number": "INV-2026-0088",
 *   "total": 94.50,
 *   "status": "sent",
 *   "payment_portal_url": "https://mowology.ca/customer/invoice.php?token=abc123",
 *   "sent_to": ["jane@oakwood.ca"],
 *   "sms_sent": true
 * }
 *
 * Response 409: already invoiced — { "success": false, "existing_invoice_id": N }
 * Response 403: not admin/manager
 * Response 400: validation error
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Services/CrmFunctions.php';
require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

$jwtUser = requireJwt();
$isAdmin = jwtIsAdmin($jwtUser['role']);

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

// ── Validate required fields ─────────────────────────────────────────────────
$visitId    = (int)($input['visit_id'] ?? 0);
$lineItems  = $input['line_items'] ?? [];
$dueDate    = trim($input['due_date'] ?? '');
$sendNow    = !empty($input['send_immediately']);

if ($visitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'visit_id is required.']);
    exit;
}
if (empty($lineItems) || !is_array($lineItems)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'line_items must be a non-empty array.']);
    exit;
}
if ($dueDate === '') {
    $dueDateTs = strtotime('+30 days');
    $dueDate   = $dueDateTs ? date('Y-m-d', $dueDateTs) : date('Y-m-d');
}

$companyId   = !empty($input['company_id'])   ? (int)$input['company_id']   : null;
$contactId   = !empty($input['contact_id'])   ? (int)$input['contact_id']   : null;
$propertyId  = !empty($input['property_id'])  ? (int)$input['property_id']  : null;
$planId      = !empty($input['plan_id'])      ? (int)$input['plan_id']      : null;
$notes       = trim($input['notes'] ?? '');
$recipientIds = array_map('intval', (array)($input['recipient_contact_ids'] ?? []));

try {
    $db = getDB();

    // ── Idempotency: already invoiced? ────────────────────────────────────────
    $chkStmt = $db->prepare("SELECT invoice_id FROM job_visits WHERE id = ?");
    $chkStmt->execute([$visitId]);
    $existingInvoiceId = $chkStmt->fetchColumn();

    if (!empty($existingInvoiceId)) {
        // Return 200 so the iOS client can decode the response (no conflict error case in APIError)
        echo json_encode([
            'success'             => false,
            'existing_invoice_id' => (int)$existingInvoiceId,
            'message'             => 'This visit has already been invoiced.',
        ]);
        exit;
    }

    // ── Business settings ─────────────────────────────────────────────────────
    $bsStmt  = $db->query("SELECT gst_rate, gst_registration FROM business_settings LIMIT 1");
    $bs      = $bsStmt->fetch(PDO::FETCH_ASSOC);
    $taxRate = round(floatval($bs['gst_rate'] ?? 5.00) / 100, 4);
    $gstNum  = $bs['gst_registration'] ?? null;

    // ── Recalculate totals from posted line_items (don't trust client total) ──
    $subtotal = 0.0;
    $cleanItems = [];
    foreach ($lineItems as $li) {
        $qty     = round((float)($li['quantity']   ?? 1), 2);
        $uprice  = round((float)($li['unit_price'] ?? 0), 2);
        $ltotal  = round($qty * $uprice, 2);
        $subtotal += $ltotal;
        $cleanItems[] = [
            'description' => trim($li['description'] ?? ''),
            'quantity'    => $qty,
            'unit_price'  => $uprice,
            'line_total'  => $ltotal,
            'sort_order'  => (int)($li['sort_order'] ?? 0),
        ];
    }
    $subtotal  = round($subtotal, 2);
    $taxAmount = round($subtotal * $taxRate, 2);
    $total     = round($subtotal + $taxAmount, 2);

    if ($subtotal <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invoice total must be greater than zero.']);
        exit;
    }

    // ── Service address from visit ────────────────────────────────────────────
    $addrStmt = $db->prepare("
        SELECT p.address, p.city, p.province, p.postal_code,
               jv.visit_number, jp.title AS plan_title
        FROM   job_visits jv
        JOIN   job_plans  jp ON jv.plan_id = jp.id
        LEFT   JOIN properties p ON jp.property_id = p.id
        WHERE  jv.id = ?
    ");
    $addrStmt->execute([$visitId]);
    $addrRow = $addrStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $serviceAddress  = trim($addrRow['address']  ?? '');
    $serviceCity     = trim($addrRow['city']      ?? '');
    $serviceProvince = trim($addrRow['province']  ?? 'BC');
    $servicePostal   = trim($addrRow['postal_code'] ?? '');
    $visitNumber     = $addrRow['visit_number'] ?? null;

    // ── Recipients: load email + phone from contacts ──────────────────────────
    $recipientRows = [];
    if (!empty($recipientIds)) {
        $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
        $rStmt = $db->prepare("
            SELECT id, first_name, last_name, full_name, email, mobile, receive_sms
            FROM   contacts
            WHERE  id IN ({$placeholders})
        ");
        $rStmt->execute($recipientIds);
        $recipientRows = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Validate at least one email recipient when sending
    $validEmailRecipients = [];
    foreach ($recipientRows as $r) {
        if (!empty($r['email']) && strpos($r['email'], '@') !== false) {
            $validEmailRecipients[] = $r;
        }
    }

    if ($sendNow && empty($validEmailRecipients)) {
        // Downgrade to draft if no valid email — don't fail the invoice creation
        $sendNow = false;
    }

    // ── Dates + tokens ────────────────────────────────────────────────────────
    $issueDate     = date('Y-m-d');
    $invoiceNumber = generateInvoiceNumber();
    $accessToken   = generateAccessToken();
    $invoiceStatus = $sendNow ? 'sent' : 'draft';

    // ── Transaction ───────────────────────────────────────────────────────────
    $db->beginTransaction();

    // INSERT invoices
    $insStmt = $db->prepare("
        INSERT INTO invoices (
            invoice_number, company_id, contact_id, property_id,
            plan_id, visit_id,
            invoice_date, issue_date, due_date,
            subtotal, tax_rate, tax_amount, gst_number,
            total_amount, total, balance_due,
            notes, access_token, token_expires_at,
            service_address, service_city, service_province, service_postal_code,
            billing_address, billing_city, billing_province, billing_postal_code,
            address_differs, status, created_by
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY),
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            0, ?, ?
        )
    ");
    $insStmt->execute([
        $invoiceNumber,
        $companyId,
        $contactId,
        $propertyId,
        $planId,
        $visitId,
        $issueDate,
        $issueDate,
        $dueDate,
        $subtotal,
        $taxRate,
        $taxAmount,
        $gstNum,
        $total,
        $total,
        $total,
        $notes ?: null,
        $accessToken,
        $serviceAddress,
        $serviceCity,
        $serviceProvince,
        $servicePostal,
        $serviceAddress,
        $serviceCity,
        $serviceProvince,
        $servicePostal,
        $invoiceStatus,
        (int)$jwtUser['user_id'],
    ]);
    $invoiceId = (int)$db->lastInsertId();

    // INSERT invoice_line_items
    $liStmt = $db->prepare("
        INSERT INTO invoice_line_items
            (invoice_id, description, quantity, unit_price, line_total, visit_id, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($cleanItems as $item) {
        $liStmt->execute([
            $invoiceId,
            $item['description'],
            $item['quantity'],
            $item['unit_price'],
            $item['line_total'],
            $visitId,
            $item['sort_order'],
        ]);
    }

    // INSERT invoice_contacts
    if (!empty($validEmailRecipients)) {
        $sentAt = $sendNow ? date('Y-m-d H:i:s') : null;
        $icStmt = $db->prepare("
            INSERT INTO invoice_contacts
                (invoice_id, contact_id, contact_role, email_address, invoice_sent_at)
            VALUES (?, ?, 'primary_recipient', ?, ?)
        ");
        foreach ($validEmailRecipients as $r) {
            $icStmt->execute([$invoiceId, (int)$r['id'], $r['email'], $sentAt]);
        }
    }

    // UPDATE job_visits: link invoice + mark as invoiced
    $db->prepare("
        UPDATE job_visits
        SET    invoice_id = ?, is_invoiced = 1
        WHERE  id = ?
    ")->execute([$invoiceId, $visitId]);

    $db->commit();

    // ── Send email + SMS ──────────────────────────────────────────────────────
    $sentTo  = [];
    $smsSent = false;
    $portalUrl = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($accessToken);

    if ($sendNow && !empty($validEmailRecipients)) {
        $companyInfo = EmailWrapper::getCompanyInfo();

        foreach ($validEmailRecipients as $r) {
            $firstName   = !empty($r['first_name']) ? $r['first_name'] : 'there';
            $fullName    = trim($r['first_name'] . ' ' . $r['last_name']);
            if ($fullName === '' && !empty($r['full_name'])) {
                $fullName = $r['full_name'];
            }

            $tplVars = [
                '{{customer_first_name}}' => $firstName,
                '{{customer_name}}'       => $fullName ?: $firstName,
                '{{invoice_number}}'      => $invoiceNumber,
                '{{amount_due}}'          => '$' . number_format($total, 2),
                '{{due_date}}'            => date('M j, Y', strtotime($dueDate)),
                '{{company_name}}'        => $companyInfo['company_name'],
                '{{company_phone}}'       => $companyInfo['company_phone'],
            ];

            $tpl = loadEmailTemplate('invoice_sent', $tplVars);

            $billSummary  = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:460px;margin:0 0 20px;font-size:14px;font-family:\'Helvetica Neue\',Arial,sans-serif;">';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;width:120px;">Invoice #</td><td style="padding:6px 0;color:#0D3B2E;font-weight:700;">' . htmlspecialchars($invoiceNumber) . '</td></tr>';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Amount Due</td><td style="padding:6px 0;color:#0D3B2E;font-size:18px;font-weight:700;">$' . number_format($total, 2) . ' CAD</td></tr>';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Due Date</td><td style="padding:6px 0;color:#0D3B2E;">' . date('M j, Y', strtotime($dueDate)) . '</td></tr>';
            $billSummary .= '</table>';

            $emailBody = EmailWrapper::wrap(
                $billSummary . $tpl['body_html'],
                'View &amp; Pay Invoice Online',
                $portalUrl,
                $companyInfo
            );

            $emailResult = sendCrmEmail($r['email'], $tpl['subject'], $emailBody);
            if ($emailResult) {
                $sentTo[] = $r['email'];
            } else {
                error_log('[invoice-create-send] email failed for invoice ' . $invoiceNumber . ' to ' . $r['email']);
            }

            // SMS (only if consent + phone)
            if (!empty($r['receive_sms']) && !empty($r['mobile'])) {
                $smsResult = sendInvoiceNotificationSms($r['mobile'], $invoiceNumber, $total);
                if ($smsResult['success']) {
                    $smsSent = true;
                }
            }
        }
    }

    echo json_encode([
        'success'             => true,
        'invoice_id'          => $invoiceId,
        'invoice_number'      => $invoiceNumber,
        'total'               => $total,
        'status'              => $invoiceStatus,
        'payment_portal_url'  => $portalUrl,
        'sent_to'             => $sentTo,
        'sms_sent'            => $smsSent,
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[schedule/invoice-create-send] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create invoice.']);
}
