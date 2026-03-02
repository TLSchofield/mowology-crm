<?php
/**
 * Customer Quote View with Digital Signature
 * Public page - no login required, token-based access
 * Supports admin preview mode: ?_preview=QUOTE_ID (requires active CRM session)
 */

// Allow CORS for signature pad
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/app_config/config.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

$db = getDB();
$error = '';
$success = '';
$isAdminPreview = false;

// ── Admin preview mode ──────────────────────────────────────────────────────
// ?_preview=QUOTE_ID bypasses token check but requires a valid CRM login session
$previewQuoteId = isset($_GET['_preview']) ? intval($_GET['_preview']) : 0;
if ($previewQuoteId > 0) {
    require_once dirname(__DIR__) . '/loginAuth/auth.php';
    if (!isLoggedIn()) {
        header('Location: /loginAuth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    $isAdminPreview = true;

    $stmt = $db->prepare("
        SELECT
            q.*,
            p.address as property_address,
            p.city as property_city,
            p.postal_code as property_postal,
            p.property_type,
            c.company_name,
            c.billing_email,
            c.billing_phone,
            ct.first_name as contact_first,
            ct.last_name as contact_last,
            ct.email as contact_email,
            ct.phone as contact_phone,
            pct.first_name as property_contact_first,
            pct.last_name as property_contact_last,
            pct.email as property_contact_email,
            pct.phone as property_contact_phone
        FROM quotes q
        LEFT JOIN properties p ON q.property_id = p.id
        LEFT JOIN companies c ON q.company_id = c.id
        LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
        LEFT JOIN contacts pct ON p.site_contact_id = pct.id
        WHERE q.id = ?
    ");
    $stmt->execute([$previewQuoteId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($quote)) {
        $error = 'Quote not found.';
    } else {
        // In preview mode force status to 'sent' so line items and full layout render,
        // but the actual status badge still reflects the real status.
        $quote['_real_status'] = $quote['status'];
        if (!in_array($quote['status'], ['sent','accepted','declined'])) {
            $quote['status'] = 'sent'; // show full preview even for draft
        }
        $stmt = $db->prepare("SELECT * FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order");
        $stmt->execute([$previewQuoteId]);
        $lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
// ── Normal token-based access ────────────────────────────────────────────────
else {
// Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Invalid or missing quote link.';
} else {
    // Validate token and get quote
    $stmt = $db->prepare("
        SELECT
            q.*,
            p.address as property_address,
            p.city as property_city,
            p.postal_code as property_postal,
            p.property_type,
            c.company_name,
            c.billing_email,
            c.billing_phone,
            ct.first_name as contact_first,
            ct.last_name as contact_last,
            ct.email as contact_email,
            ct.phone as contact_phone,
            pct.first_name as property_contact_first,
            pct.last_name as property_contact_last,
            pct.email as property_contact_email,
            pct.phone as property_contact_phone
        FROM quotes q
        LEFT JOIN properties p ON q.property_id = p.id
        LEFT JOIN companies c ON q.company_id = c.id
        LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
        LEFT JOIN contacts pct ON p.site_contact_id = pct.id
        WHERE q.access_token = ?
        AND q.token_expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($quote)) {
        $quote = null;
        $error = 'This quote link has expired or is invalid. Please contact us for a new quote.';
    } else {
        // Update view count (wrapped in try/catch — column may not exist on all environments)
        try {
            $stmt = $db->prepare("
                UPDATE quotes
                SET viewed_at = COALESCE(viewed_at, NOW()),
                    view_count = view_count + 1
                WHERE id = ?
            ");
            $stmt->execute([$quote['id']]);
        } catch (Exception $e) {
            // Non-critical — silently skip if column missing
            error_log("Quote view_count update failed: " . $e->getMessage());
        }

        // Get line items
        $stmt = $db->prepare("SELECT * FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order");
        $stmt->execute([$quote['id']]);
        $lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
} // end normal token block

// Handle acceptance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($quote) && $quote['status'] !== 'accepted') {
    $action = $_POST['action'] ?? '';

    if ($action === 'accept') {
        $signatureName = trim($_POST['signature_name'] ?? '');
        $signatureEmail = trim($_POST['signature_email'] ?? '');
        $signatureData = $_POST['signature_data'] ?? '';
        $agreedToTerms = isset($_POST['agree_terms']);

        if (empty($signatureName)) {
            $error = 'Please enter your name.';
        } elseif (empty($signatureData) || $signatureData === 'data:,') {
            $error = 'Please sign the quote using your mouse or finger.';
        } elseif (!$agreedToTerms) {
            $error = 'Please agree to the terms and conditions.';
        } else {
            try {
                $db->beginTransaction();

                // Update quote as accepted
                $stmt = $db->prepare("
                    UPDATE quotes SET
                        status = 'accepted',
                        accepted_at = NOW(),
                        accepted_by_name = ?,
                        accepted_by_email = ?,
                        accepted_ip_address = ?,
                        signature_data = ?,
                        signature_timestamp = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $signatureName,
                    $signatureEmail,
                    $_SERVER['REMOTE_ADDR'],
                    $signatureData,
                    $quote['id']
                ]);

                // Log activity
                try {
                    $stmt = $db->prepare("
                        INSERT INTO activity_log (quote_id, action, details, ip_address, created_at)
                        VALUES (?, 'Quote accepted', ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $quote['id'],
                        "Accepted by {$signatureName}",
                        $_SERVER['REMOTE_ADDR']
                    ]);
                } catch (Exception $e) {
                    // activity_log insert is non-critical — don't block acceptance
                    error_log("Quote acceptance activity log error: " . $e->getMessage());
                }

                $db->commit();

                $quote['status'] = 'accepted';
                $success = 'Thank you! Your quote has been accepted. We will be in touch shortly to schedule your service.';

                // Send notification email to admin
                $emailSubject = "Quote {$quote['quote_number']} Accepted!";
                $emailBody = "
                <html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto;'>
                    <div style='background: linear-gradient(135deg, #1A5F4A 0%, #0D3B2E 100%); padding: 20px; text-align: center;'>
                        <h1 style='color: #7FD858; margin: 0;'>Quote Accepted!</h1>
                    </div>
                    <div style='padding: 30px; background: #f9f9f9;'>
                        <div style='background: white; border-radius: 12px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                            <table style='width: 100%; border-collapse: collapse;'>
                                <tr><td style='padding: 8px 0; font-weight: bold; width: 120px;'>Quote:</td><td>{$quote['quote_number']}</td></tr>
                                <tr><td style='padding: 8px 0; font-weight: bold;'>Customer:</td><td>" . htmlspecialchars($signatureName) . "</td></tr>
                                <tr><td style='padding: 8px 0; font-weight: bold;'>Property:</td><td>" . htmlspecialchars(trim(($quote['property_address'] ?? '') . (($quote['property_city'] ?? '') ? ', ' . $quote['property_city'] : ''))) . "</td></tr>
                                <tr><td style='padding: 8px 0; font-weight: bold;'>Amount:</td><td style='font-size: 18px; font-weight: bold; color: #2D8659;'>$" . number_format(floatval($quote['amount']), 2) . "</td></tr>
                            </table>
                            <div style='text-align: center; margin-top: 25px;'>
                                <a href='https://mowology.ca/crm/quotes/view.php?id={$quote['id']}' style='display: inline-block; background: #2D8659; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-weight: bold;'>View in CRM</a>
                            </div>
                        </div>
                    </div>
                </body></html>";

                sendEmailNotification(NOTIFICATION_EMAIL, $emailSubject, $emailBody);

                // Send SMS notification
                $smsMsg = "QUOTE ACCEPTED: {$quote['quote_number']} - $" . number_format(floatval($quote['amount']), 2) . " - " . htmlspecialchars($signatureName);
                sendSMSNotification($smsMsg);

            } catch (Exception $e) {
                $db->rollBack();
                error_log("Quote acceptance error: " . $e->getMessage());
                $error = 'There was an error processing your acceptance. Please try again.';
            }
        }
    }

    if ($action === 'decline') {
        $declineReason = trim($_POST['decline_reason'] ?? '');

        $stmt = $db->prepare("
            UPDATE quotes SET
                status = 'declined',
                notes_internal = CONCAT(COALESCE(notes_internal, ''), '\n\nDeclined reason: ', ?)
            WHERE id = ?
        ");
        $stmt->execute([$declineReason, $quote['id']]);

        $quote['status'] = 'declined';
        $success = 'Thank you for letting us know. If you change your mind or have questions, please contact us.';
    }
}

function formatCurrency($amount) {
    return '$' . number_format(floatval($amount), 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote <?php echo !empty($quote) ? htmlspecialchars($quote['quote_number'] ?? '') : ''; ?> - Mowology</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --forest-dark: #0D3B2E;
            --forest-main: #1A5F4A;
            --grass-green: #2D8659;
            --lime-accent: #7FD858;
            --snow-white: #F8FFFE;
            --mist-gray: #E8F3F0;
            --danger: #DC2626;
        }

        body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--mist-gray);
            color: var(--forest-dark);
            line-height: 1.6;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(135deg, var(--forest-dark), var(--forest-main));
            color: white;
            padding: 24px 0;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-family: 'Space Mono', monospace;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -1px;
        }

        .quote-number {
            font-size: 14px;
            opacity: 0.9;
        }

        .main-content {
            padding: 40px 0;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(13, 59, 46, 0.1);
            margin-bottom: 24px;
        }

        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--forest-dark);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--mist-gray);
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-sent { background: #3B82F6; color: white; }
        .status-accepted { background: var(--grass-green); color: white; }
        .status-declined { background: var(--danger); color: white; }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        @media (max-width: 600px) {
            .detail-grid { grid-template-columns: 1fr; }
        }

        .detail-group h4 {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--forest-main);
            margin-bottom: 4px;
        }

        .detail-group p {
            font-size: 16px;
        }

        .line-items {
            width: 100%;
            border-collapse: collapse;
        }

        .line-items th,
        .line-items td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid var(--mist-gray);
        }

        .line-items th {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--forest-main);
            background: var(--mist-gray);
        }

        .line-items td.amount {
            text-align: right;
            font-family: 'Space Mono', monospace;
            font-weight: 600;
        }

        .section-header-row td {
            background: var(--mist-gray);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--forest-main);
            padding: 8px 12px;
            border-bottom: 1px solid #d0ddd8;
        }

        .totals {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 2px solid var(--mist-gray);
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 16px;
        }

        .total-row.grand {
            font-size: 24px;
            font-weight: 700;
            padding-top: 16px;
            margin-top: 8px;
            border-top: 2px solid var(--forest-dark);
        }

        .total-value {
            font-family: 'Space Mono', monospace;
        }

        .terms-box {
            background: var(--mist-gray);
            padding: 20px;
            border-radius: 12px;
            font-size: 14px;
            white-space: pre-line;
        }

        .message {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            text-align: center;
        }

        .message.error {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .message.success {
            background: rgba(45, 134, 89, 0.15);
            color: var(--grass-green);
        }

        .acceptance-section {
            margin-top: 32px;
            padding-top: 32px;
            border-top: 2px solid var(--mist-gray);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--mist-gray);
            border-radius: 12px;
            font-size: 16px;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--grass-green);
        }

        .signature-container {
            border: 2px solid var(--mist-gray);
            border-radius: 12px;
            background: white;
            position: relative;
        }

        .signature-pad {
            width: 100%;
            height: 200px;
            display: block;
        }

        .signature-placeholder {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #999;
            pointer-events: none;
            font-size: 14px;
        }

        .signature-actions {
            display: flex;
            justify-content: flex-end;
            padding: 8px;
            background: var(--mist-gray);
            border-radius: 0 0 10px 10px;
        }

        .clear-btn {
            background: none;
            border: none;
            color: var(--forest-main);
            font-size: 14px;
            cursor: pointer;
            padding: 4px 12px;
        }

        .clear-btn:hover {
            color: var(--danger);
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 24px 0;
        }

        .checkbox-group input {
            width: 20px;
            height: 20px;
            margin-top: 2px;
        }

        .checkbox-group label {
            font-size: 14px;
            line-height: 1.4;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px 32px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            font-family: inherit;
            width: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--lime-accent), var(--grass-green));
            color: var(--forest-dark);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(127, 216, 88, 0.4);
        }

        .btn-secondary {
            background: white;
            color: var(--forest-main);
            border: 2px solid var(--mist-gray);
            margin-top: 12px;
        }

        .decline-section {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--mist-gray);
        }

        .decline-toggle {
            color: var(--forest-main);
            font-size: 14px;
            cursor: pointer;
            text-decoration: underline;
        }

        .decline-form {
            display: none;
            margin-top: 16px;
        }

        .decline-form.show {
            display: block;
        }

        .footer {
            text-align: center;
            padding: 40px 0;
            color: var(--forest-main);
            font-size: 14px;
        }

        .footer a {
            color: var(--grass-green);
        }

        .already-accepted {
            text-align: center;
            padding: 40px;
        }

        .already-accepted .icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        /* Upsell Cards */
        .upsell-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
            transition: border-color 0.15s, background 0.15s;
        }
        .upsell-card:hover {
            border-color: var(--forest-main, #2D8659);
            background: #f0faf5;
        }
        .upsell-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            margin: 0;
        }
        .upsell-toggle input[type="checkbox"] {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            accent-color: var(--forest-main, #2D8659);
        }
        .upsell-info {
            flex: 1;
            font-size: 14px;
        }
        .upsell-price {
            font-weight: 600;
            font-size: 15px;
            color: var(--forest-main, #2D8659);
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <?php if ($isAdminPreview): ?>
    <div style="position:sticky;top:0;z-index:9999;background:#1A5F4A;color:#fff;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;font-family:sans-serif;font-size:13px;box-shadow:0 2px 8px rgba(0,0,0,0.3);">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="background:#7FD858;color:#0D3B2E;font-weight:700;padding:3px 8px;border-radius:4px;font-size:11px;letter-spacing:0.05em;">ADMIN PREVIEW</span>
            <span>This is how your client sees quote <strong><?php echo htmlspecialchars($quote['quote_number'] ?? ''); ?></strong>. Signature and acceptance are disabled.</span>
        </div>
        <a href="/crm/quotes/create.php?id=<?php echo $previewQuoteId; ?>" style="color:#7FD858;font-weight:600;text-decoration:none;">← Back to Editor</a>
    </div>
    <?php endif; ?>
    <header class="header">
        <div class="container">
            <div class="header-content">
                <div class="logo">MOWOLOGY</div>
                <?php if (!empty($quote)): ?>
                    <div class="quote-number">Quote #<?php echo htmlspecialchars($quote['quote_number'] ?? ''); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="container">
            <?php if ($error && empty($quote)): ?>
                <div class="message error">
                    <h2 style="margin-bottom: 8px;">Quote Not Found</h2>
                    <p><?php echo htmlspecialchars($error); ?></p>
                    <p style="margin-top: 16px;">Contact us at <a href="tel:7788469273">(778) 846-9273</a></p>
                </div>
            <?php elseif (!empty($quote)): ?>

                <?php if ($success): ?>
                    <div class="message success">
                        <h2 style="margin-bottom: 8px;"><?php echo $quote['status'] === 'accepted' ? 'Quote Accepted!' : 'Quote Declined'; ?></h2>
                        <p><?php echo htmlspecialchars($success); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Quote Header -->
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
                        <div>
                            <?php
                                // For residential properties, show property owner. For commercial, show company name.
                                if (($quote['property_type'] ?? '') === 'residential') {
                                    // Residential: use property contact (owner)
                                    $clientName = trim(($quote['property_contact_first'] ?? '') . ' ' . ($quote['property_contact_last'] ?? '')) ?: 'Valued Customer';
                                } else {
                                    // Commercial: use company name, fallback to property contact, then company contact
                                    $clientName = ($quote['company_name'] ?? null)
                                        ?: (trim(($quote['property_contact_first'] ?? '') . ' ' . ($quote['property_contact_last'] ?? ''))
                                            ?: (trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''))
                                                ?: 'Valued Customer'));
                                }
                            ?>
                            <h2 style="font-size: 24px; margin-bottom: 8px;">Quote for <?php echo htmlspecialchars($clientName); ?></h2>
                            <?php if (!empty($quote['property_address'])): ?>
                            <p style="color: var(--forest-main);">
                                <?php echo htmlspecialchars($quote['property_address'] ?? ''); ?><?php if (!empty($quote['property_city'])): ?>, <?php echo htmlspecialchars($quote['property_city']); ?><?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <span class="status-badge status-<?php echo $quote['status'] ?? ''; ?>">
                            <?php echo ucfirst($quote['status'] ?? ''); ?>
                        </span>
                    </div>

                    <div class="detail-grid" style="margin-top: 24px;">
                        <div class="detail-group">
                            <h4>Quote Date</h4>
                            <p><?php echo date('F j, Y', strtotime($quote['created_at'])); ?></p>
                        </div>
                        <div class="detail-group">
                            <h4>Valid Until</h4>
                            <p><?php echo $quote['valid_until'] ? date('F j, Y', strtotime($quote['valid_until'])) : 'N/A'; ?></p>
                        </div>
                    </div>

                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--mist-gray);">
                        <a href="../crm/documents/generate_pdf.php?type=quote&id=<?php echo (int)$quote['id']; ?>&action=download"
                           style="display: inline-flex; align-items: center; gap: 8px; color: var(--grass-green); text-decoration: none; font-weight: 500;">
                            📄 Download PDF
                        </a>
                    </div>
                </div>

                <!-- Services -->
                <div class="card">
                    <h3 class="card-title">Services</h3>

                    <table class="line-items">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Description</th>
                                <th style="text-align: right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $currentSection = false; // false = no section encountered yet
                            foreach (($lineItems ?? []) as $item):
                                $itemSection = $item['section_name'] ?? null;
                                if ($itemSection !== $currentSection):
                                    $currentSection = $itemSection;
                                    if ($itemSection !== null):
                            ?>
                                <tr class="section-header-row">
                                    <td colspan="3"><?php echo htmlspecialchars($itemSection); ?></td>
                                </tr>
                            <?php endif; endif; ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['service_type']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['description'] ?: '-'); ?></td>
                                    <td class="amount"><?php echo formatCurrency($item['line_total']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="totals">
                        <div class="total-row">
                            <span>Subtotal</span>
                            <span class="total-value"><?php echo formatCurrency($quote['subtotal'] ?: $quote['amount'] / 1.05); ?></span>
                        </div>
                        <div class="total-row">
                            <span>GST (5%)</span>
                            <span class="total-value"><?php echo formatCurrency($quote['tax_amount'] ?: $quote['amount'] - ($quote['amount'] / 1.05)); ?></span>
                        </div>
                        <div class="total-row grand">
                            <span>Total</span>
                            <span class="total-value"><?php echo formatCurrency($quote['amount']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Upsell Section (only shown for sent quotes) -->
                <?php if ($quote['status'] === 'sent'): ?>
                    <div class="card" id="upsellSection" style="display:none;">
                        <h3 class="card-title">Enhance Your Service</h3>
                        <p style="font-size:14px; color:#555; margin-bottom:16px;">
                            Add recommended services to get the most out of your visit.
                        </p>
                        <div id="upsellOptions"></div>
                    </div>
                <?php endif; ?>

                <!-- Terms -->
                <?php if ($quote['terms']): ?>
                    <div class="card">
                        <h3 class="card-title">Terms & Conditions</h3>
                        <div class="terms-box"><?php echo htmlspecialchars($quote['terms']); ?></div>
                    </div>
                <?php endif; ?>

                <!-- Customer Notes -->
                <?php if ($quote['notes_customer']): ?>
                    <div class="card">
                        <h3 class="card-title">Additional Notes</h3>
                        <p style="white-space: pre-line;"><?php echo htmlspecialchars($quote['notes_customer']); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Acceptance Section -->
                <?php if ($quote['status'] === 'sent'): ?>
                    <?php if ($isAdminPreview): ?>
                    <div class="card" style="border: 2px dashed #2D8659; background: #f0f9f4;">
                        <h3 class="card-title" style="color:#1A5F4A;">Accept This Quote</h3>
                        <p style="color:#1A5F4A;font-style:italic;padding:16px;text-align:center;">
                            ✏️ <strong>Preview mode:</strong> The signature and acceptance form appear here for your client. They are disabled in this preview.
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="card">
                        <h3 class="card-title">Accept This Quote</h3>
                        <p style="margin-bottom: 24px; color: var(--forest-main);">
                            To accept this quote, please sign below and confirm your details.
                        </p>

                        <form method="POST" id="acceptForm">
                            <input type="hidden" name="action" value="accept">
                            <input type="hidden" name="signature_data" id="signatureData">

                            <div class="form-group">
                                <label class="form-label">Your Full Name *</label>
                                <input type="text" name="signature_name" class="form-input" required
                                       placeholder="Enter your full name"
                                       value="<?php echo htmlspecialchars(trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''))); ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Your Email</label>
                                <input type="email" name="signature_email" class="form-input"
                                       placeholder="Enter your email"
                                       value="<?php echo htmlspecialchars($quote['contact_email'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Your Signature *</label>
                                <div class="signature-container">
                                    <canvas id="signaturePad" class="signature-pad"></canvas>
                                    <div class="signature-placeholder" id="sigPlaceholder">Sign here with mouse or finger</div>
                                    <div class="signature-actions">
                                        <button type="button" class="clear-btn" id="clearSignature">Clear Signature</button>
                                    </div>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <input type="checkbox" name="agree_terms" id="agreeTerms" required>
                                <label for="agreeTerms">
                                    I have read and agree to the terms and conditions above. I authorize Mowology to perform the services described in this quote.
                                </label>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                ✓ Accept Quote & Schedule Service
                            </button>
                        </form>

                        <div class="decline-section">
                            <span class="decline-toggle" onclick="document.querySelector('.decline-form').classList.toggle('show')">
                                Not interested? Let us know
                            </span>

                            <form method="POST" class="decline-form">
                                <input type="hidden" name="action" value="decline">
                                <div class="form-group">
                                    <label class="form-label">Reason (optional)</label>
                                    <textarea name="decline_reason" class="form-input" rows="3"
                                              placeholder="Let us know why you're declining..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-secondary">
                                    Decline Quote
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; /* end isAdminPreview else */ ?>
                <?php elseif ($quote['status'] === 'accepted'): ?>
                    <div class="card">
                        <div class="already-accepted">
                            <div class="icon">✓</div>
                            <h3 style="color: var(--grass-green); margin-bottom: 8px;">Quote Accepted</h3>
                            <?php if (!empty($quote['accepted_at'])): ?>
                                <p>This quote was accepted on <?php echo date('F j, Y', strtotime($quote['accepted_at'])); ?>.</p>
                            <?php else: ?>
                                <p>This quote has been accepted.</p>
                            <?php endif; ?>
                            <p style="margin-top: 8px;">We will be in touch shortly to schedule your service.</p>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Questions? Call us at <a href="tel:7788469273">(778) 846-9273</a></p>
            <p style="margin-top: 8px;">&copy; <?php echo date('Y'); ?> Mowology. All rights reserved.</p>
        </div>
    </footer>

    <?php if (!empty($quote) && $quote['status'] === 'sent'): ?>
    <script>
        // Signature Pad
        const canvas = document.getElementById('signaturePad');
        const placeholder = document.getElementById('sigPlaceholder');
        const signaturePad = new SignaturePad(canvas, {
            backgroundColor: 'rgb(255, 255, 255)',
            penColor: 'rgb(13, 59, 46)'
        });

        // Resize canvas
        function resizeCanvas() {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const container = canvas.parentElement;
            canvas.width = container.offsetWidth * ratio;
            canvas.height = 200 * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            canvas.style.width = container.offsetWidth + 'px';
            canvas.style.height = '200px';
            signaturePad.clear();
        }

        window.addEventListener('resize', resizeCanvas);
        resizeCanvas();

        // Hide placeholder when signing
        signaturePad.addEventListener('beginStroke', () => {
            placeholder.style.display = 'none';
        });

        // Clear signature
        document.getElementById('clearSignature').addEventListener('click', () => {
            signaturePad.clear();
            placeholder.style.display = 'block';
        });

        // Form submission
        document.getElementById('acceptForm').addEventListener('submit', function(e) {
            if (signaturePad.isEmpty()) {
                e.preventDefault();
                alert('Please sign the quote before submitting.');
                return;
            }

            document.getElementById('signatureData').value = signaturePad.toDataURL();
        });
    </script>
    <?php endif; ?>

    <?php if ($quote && $quote['status'] === 'sent'): ?>
    <script>
    // ── Upsell Management ──────────────────────────────
    (function() {
        var quoteToken = <?php echo json_encode($token); ?>;
        var upsellSection = document.getElementById('upsellSection');
        var upsellContainer = document.getElementById('upsellOptions');
        if (!upsellSection || !upsellContainer) return;

        function formatCurrencyJS(amount) {
            return '$' + parseFloat(amount).toFixed(2);
        }

        fetch('api/quote-upsell.php?action=get-upsells&token=' + encodeURIComponent(quoteToken))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !data.upsells || data.upsells.length === 0) return;

                upsellSection.style.display = '';

                upsellContainer.innerHTML = data.upsells.map(function(up) {
                    var text = up.display_text || up.upsell_product_name;
                    var desc = up.upsell_description || '';
                    var price = up.calculated_price || up.upsell_price;
                    var checked = up.is_added;

                    return '<div class="upsell-card" data-product-id="' + up.upsell_product_id + '">' +
                        '<label class="upsell-toggle">' +
                            '<input type="checkbox" ' + (checked ? 'checked' : '') +
                            ' onchange="toggleUpsell(' + up.upsell_product_id + ', this.checked)">' +
                            '<div class="upsell-info">' +
                                '<strong>' + text + '</strong>' +
                                (desc ? '<br><span style="font-size:12px;color:#666;">' + desc + '</span>' : '') +
                            '</div>' +
                            '<div class="upsell-price">+ ' + formatCurrencyJS(price) + '</div>' +
                        '</label>' +
                    '</div>';
                }).join('');
            })
            .catch(function() { /* silently fail */ });
    })();

    function toggleUpsell(productId, add) {
        var formData = new FormData();
        formData.append('token', <?php echo json_encode($token); ?>);
        formData.append('action', add ? 'add-upsell' : 'remove-upsell');
        formData.append('upsell_product_id', productId);

        fetch('api/quote-upsell.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.totals) {
                    // Update displayed totals
                    var subtotalEls = document.querySelectorAll('.total-row .total-value');
                    if (subtotalEls.length >= 3) {
                        subtotalEls[0].textContent = '$' + parseFloat(data.totals.subtotal).toFixed(2);
                        subtotalEls[1].textContent = '$' + parseFloat(data.totals.tax_amount).toFixed(2);
                        subtotalEls[2].textContent = '$' + parseFloat(data.totals.total).toFixed(2);
                    }
                } else if (!data.success) {
                    alert(data.error || 'Could not update quote');
                    // Revert checkbox
                    var card = document.querySelector('.upsell-card[data-product-id="' + productId + '"] input');
                    if (card) card.checked = !add;
                }
            })
            .catch(function(err) {
                alert('Error: ' + err.message);
                var card = document.querySelector('.upsell-card[data-product-id="' + productId + '"] input');
                if (card) card.checked = !add;
            });
    }
    </script>
    <?php endif; ?>
</body>
</html>
