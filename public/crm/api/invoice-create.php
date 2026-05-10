<?php
/**
 * Invoice Create — CRM AJAX Handler
 * POST /crm/api/invoice-create.php
 *
 * Creates an invoice from a completed job visit using CRM session auth.
 * Admin-only. Returns JSON.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messaging.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json; charset=utf-8');

// ── Method check ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Auth: admin only ──────────────────────────────────────────────────────────
$isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('billing.edit');
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?: [];

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!verifyCSRFToken($body['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$visitId   = (int)($body['visit_id'] ?? 0);
$lineItems = $body['line_items'] ?? [];
$dueDate   = !empty($body['due_date']) ? $body['due_date'] : date('Y-m-d', strtotime('+30 days'));
$sendNow   = !empty($body['send_now']);
$noteText  = trim($body['notes'] ?? '');

if (!$visitId || empty($lineItems)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// ── Validate due date ─────────────────────────────────────────────────────────
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    $dueDate = date('Y-m-d', strtotime('+30 days'));
}

$db = getDB();

// ── Load visit ────────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT v.id, v.status, v.invoice_id, v.plan_id, v.visit_number,
           p.property_id, p.company_id, p.title AS plan_title,
           pr.site_contact_id AS contact_id,
           pr.address AS service_address, pr.city AS service_city,
           pr.province AS service_province, pr.postal_code AS service_postal,
           c.first_name, c.last_name, c.email, c.mobile, c.receive_sms
    FROM job_visits v
    JOIN job_plans p ON v.plan_id = p.id
    LEFT JOIN properties pr ON p.property_id = pr.id
    LEFT JOIN contacts c ON pr.site_contact_id = c.id
    WHERE v.id = ?
");
$stmt->execute([$visitId]);
$visit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$visit) {
    echo json_encode(['success' => false, 'error' => 'Visit not found']);
    exit;
}
if ($visit['status'] !== 'completed') {
    echo json_encode(['success' => false, 'error' => 'Visit must be completed before invoicing']);
    exit;
}
if (!empty($visit['invoice_id'])) {
    echo json_encode([
        'success'             => false,
        'error'               => 'This visit already has an invoice',
        'existing_invoice_id' => (int)$visit['invoice_id'],
    ]);
    exit;
}

// ── Server-side total recalculation (never trust the client total) ────────────
$subtotal   = 0.0;
$cleanItems = [];
foreach ($lineItems as $i => $li) {
    $desc  = substr(trim($li['description'] ?? 'Service'), 0, 255) ?: 'Service';
    $qty   = max(0, (float)($li['qty'] ?? $li['quantity'] ?? 1));
    $price = max(0, (float)($li['unit_price'] ?? 0));
    $total = round($qty * $price, 2);
    $subtotal += $total;
    $cleanItems[] = [
        'description' => $desc,
        'quantity'    => $qty,
        'unit_price'  => $price,
        'line_total'  => $total,
        'sort_order'  => $i + 1,
    ];
}
$subtotal = round($subtotal, 2);

// ── Tax from business_settings ────────────────────────────────────────────────
$taxRate = 0.05;
$gstNum  = null;
try {
    $bsStmt = $db->query("SELECT gst_rate, gst_registration FROM business_settings LIMIT 1");
    $bs = $bsStmt ? $bsStmt->fetch(PDO::FETCH_ASSOC) : [];
    if ($bs && isset($bs['gst_rate'])) $taxRate = round((float)$bs['gst_rate'] / 100, 4);
    if ($bs && !empty($bs['gst_registration'])) $gstNum = $bs['gst_registration'];
} catch (Exception $e) {}

$taxAmount = round($subtotal * $taxRate, 2);
$total     = round($subtotal + $taxAmount, 2);
$issueDate = date('Y-m-d');

// ── Generate invoice number + access token ────────────────────────────────────
$invoiceNumber = generateInvoiceNumber();
$accessToken   = generateAccessToken();

// ── Transaction ───────────────────────────────────────────────────────────────
$db->beginTransaction();
try {
    // Insert invoice
    $insInv = $db->prepare("
        INSERT INTO invoices (
            invoice_number, company_id, contact_id, property_id, plan_id, visit_id,
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
    $insInv->execute([
        $invoiceNumber,
        $visit['company_id'] ?: null,
        $visit['contact_id'] ?: null,
        $visit['property_id'] ?: null,
        $visit['plan_id'] ?: null,
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
        $noteText ?: null,
        $accessToken,
        // service address
        $visit['service_address'] ?? null,
        $visit['service_city'] ?? null,
        $visit['service_province'] ?? null,
        $visit['service_postal'] ?? null,
        // billing = same as service
        $visit['service_address'] ?? null,
        $visit['service_city'] ?? null,
        $visit['service_province'] ?? null,
        $visit['service_postal'] ?? null,
        $sendNow ? 'sent' : 'draft',
        $user['id'],
    ]);
    $invoiceId = (int)$db->lastInsertId();

    // Insert line items
    $insLi = $db->prepare("
        INSERT INTO invoice_line_items (invoice_id, description, quantity, unit_price, line_total, visit_id, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($cleanItems as $li) {
        $insLi->execute([
            $invoiceId,
            $li['description'],
            $li['quantity'],
            $li['unit_price'],
            $li['line_total'],
            $visitId,
            $li['sort_order'],
        ]);
    }

    // Insert recipient contact
    if (!empty($visit['contact_id']) && !empty($visit['email'])) {
        $insIc = $db->prepare("
            INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address)
            VALUES (?, ?, 'primary_recipient', ?)
        ");
        $insIc->execute([$invoiceId, $visit['contact_id'], $visit['email']]);
    }

    // Mark visit as invoiced
    $db->prepare("UPDATE job_visits SET is_invoiced = 1, invoice_id = ? WHERE id = ?")
       ->execute([$invoiceId, $visitId]);

    $db->commit();

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log("invoice-create.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
    exit;
}

// ── Send email + SMS if requested ─────────────────────────────────────────────
$sentTo  = [];
$smsSent = false;

if ($sendNow && !empty($visit['email'])) {
    $clientName = trim(($visit['first_name'] ?? '') . ' ' . ($visit['last_name'] ?? '')) ?: 'Valued Client';
    $portalUrl  = 'https://mowology.ca/customer/invoice.php?token=' . $accessToken;
    $totalFmt   = '$' . number_format($total, 2);
    $dueFmt     = date('F j, Y', strtotime($dueDate));

    $subject = "Invoice {$invoiceNumber} from Mowology — {$totalFmt} due {$dueFmt}";
    $htmlBody = '<p>Hi ' . htmlspecialchars($clientName) . ',</p>'
              . '<p>Thank you for choosing Mowology! Please find your invoice below.</p>'
              . '<table style="border-collapse:collapse;width:100%;max-width:460px;">'
              . '<tr><td style="padding:8px;border:1px solid #eee;color:#666;">Invoice</td><td style="padding:8px;border:1px solid #eee;font-weight:600;">' . htmlspecialchars($invoiceNumber) . '</td></tr>'
              . '<tr><td style="padding:8px;border:1px solid #eee;color:#666;">Amount Due</td><td style="padding:8px;border:1px solid #eee;font-weight:600;color:#2D8659;">' . $totalFmt . '</td></tr>'
              . '<tr><td style="padding:8px;border:1px solid #eee;color:#666;">Due Date</td><td style="padding:8px;border:1px solid #eee;">' . htmlspecialchars($dueFmt) . '</td></tr>'
              . '</table>'
              . '<p style="margin-top:20px;"><a href="' . $portalUrl . '" style="background:#2D8659;color:#fff;padding:10px 22px;border-radius:6px;text-decoration:none;font-weight:600;">View &amp; Pay Invoice</a></p>'
              . '<p style="color:#999;font-size:12px;margin-top:20px;">Questions? Call us at (778) 846-9273 or reply to this email.</p>';

    try {
        $emailResult = sendEmail($visit['email'], $subject, $htmlBody);
        if ($emailResult['success']) {
            $sentTo[] = $visit['email'];
        }
    } catch (Throwable $e) {
        error_log("invoice-create.php email error: " . $e->getMessage());
    }

    // SMS — only if contact opted in
    if (!empty($visit['receive_sms']) && !empty($visit['mobile'])) {
        try {
            $smsResult = sendInvoiceNotificationSms($visit['mobile'], $invoiceNumber, $total);
            $smsSent   = $smsResult['success'];
        } catch (Throwable $e) {
            error_log("invoice-create.php SMS error: " . $e->getMessage());
        }
    }
}

// ── Done ──────────────────────────────────────────────────────────────────────
echo json_encode([
    'success'        => true,
    'invoice_id'     => $invoiceId,
    'invoice_number' => $invoiceNumber,
    'total'          => $total,
    'status'         => $sendNow ? 'sent' : 'draft',
    'sent_to'        => $sentTo,
    'sms_sent'       => $smsSent,
]);
