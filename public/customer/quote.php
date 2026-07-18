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
    requirePermission('billing.view');
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
        // Update view tracking — first-view timestamp + rolling last_viewed_at + count
        try {
            $stmt = $db->prepare("
                UPDATE quotes
                SET viewed_at      = COALESCE(viewed_at, NOW()),
                    last_viewed_at = NOW(),
                    view_count     = view_count + 1
                WHERE id = ?
            ");
            $stmt->execute([$quote['id']]);
        } catch (Exception $e) {
            // Non-critical — silently skip if column missing
            error_log("Quote view tracking update failed: " . $e->getMessage());
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
        $signatureName  = trim($_POST['signature_name'] ?? '');
        $signatureEmail = trim($_POST['signature_email'] ?? '');
        $signatureData  = $_POST['signature_data'] ?? '';
        $agreedToTerms  = isset($_POST['agree_terms']);

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

                // Propagate acceptance back to the originating quote_request
                // so the Quote Requests admin page reflects the real status.
                try {
                    $db->prepare("
                        UPDATE quote_requests
                        SET status = 'accepted', updated_at = NOW()
                        WHERE quote_id = ?
                    ")->execute([$quote['id']]);
                } catch (Throwable $e) {
                    // Non-critical — table/column may not exist yet
                    error_log("Quote acceptance → quote_requests propagation error: " . $e->getMessage());
                }

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

        // Propagate decline back to quote_request
        try {
            $db->prepare("
                UPDATE quote_requests
                SET status = 'declined', updated_at = NOW()
                WHERE quote_id = ?
            ")->execute([$quote['id']]);
        } catch (Throwable $e) {
            error_log("Quote decline → quote_requests propagation error: " . $e->getMessage());
        }

        $quote['status'] = 'declined';
        $success = 'Thank you for letting us know. If you change your mind or have questions, please contact us.';
    }
}

function formatCurrency($amount) {
    return '$' . number_format(floatval($amount), 2);
}

// Build display name for header
$clientNameForHeader = '';
if (!empty($quote)) {
    if (($quote['property_type'] ?? '') === 'residential') {
        $clientNameForHeader = trim(($quote['property_contact_first'] ?? '') . ' ' . ($quote['property_contact_last'] ?? '')) ?: '';
    } else {
        $clientNameForHeader = ($quote['company_name'] ?? null)
            ?: trim(($quote['property_contact_first'] ?? '') . ' ' . ($quote['property_contact_last'] ?? ''))
            ?: trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''))
            ?: '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote <?php echo !empty($quote) ? htmlspecialchars($quote['quote_number'] ?? '') : ''; ?> — Mowology</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/customer/portal.css">
    <script src="https://unpkg.com/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
</head>
<body>

<header class="portal-header">
    <img src="/assets/img/logo/mowology-logo.jpg" alt="Mowology" class="portal-logo-img">
    <div class="portal-header-divider"></div>
    <span class="portal-header-label">Quote</span>
    <?php if ($clientNameForHeader): ?>
        <span class="portal-client-name"><?php echo htmlspecialchars($clientNameForHeader); ?></span>
    <?php endif; ?>
</header>

<?php if ($isAdminPreview): ?>
<div class="portal-admin-banner">
    <div style="display:flex;align-items:center;gap:8px;">
        <span class="portal-admin-tag">Admin Preview</span>
        <span>Viewing as client — quote <strong><?php echo htmlspecialchars($quote['quote_number'] ?? ''); ?></strong>. Signature disabled.</span>
    </div>
    <a href="/crm/quotes/create.php?id=<?php echo $previewQuoteId; ?>">← Back to Editor</a>
</div>
<?php endif; ?>

<div class="portal-container">

    <?php if ($error && empty($quote)): ?>
        <div class="portal-error">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:#cdddd6;margin:0 auto 16px;display:block"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <h2>Quote Not Found</h2>
            <p><?php echo htmlspecialchars($error); ?></p>
            <p style="margin-top:12px;">Contact us at <a href="tel:7788469273">(778) 846-9273</a></p>
        </div>

    <?php elseif (!empty($quote)): ?>

        <?php if ($success): ?>
            <div class="portal-message <?php echo $quote['status'] === 'accepted' ? 'success' : 'error'; ?>">
                <h2><?php echo $quote['status'] === 'accepted' ? 'Quote Accepted!' : 'Quote Declined'; ?></h2>
                <p style="margin-top:6px;"><?php echo htmlspecialchars($success); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="portal-message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Quote Header Card -->
        <div class="portal-quote-hdr">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:16px;">
                <div>
                    <?php
                    if (($quote['property_type'] ?? '') === 'residential') {
                        $clientName = trim(($quote['property_contact_first'] ?? '') . ' ' . ($quote['property_contact_last'] ?? '')) ?: 'Valued Customer';
                    } else {
                        $clientName = ($quote['company_name'] ?? null)
                            ?: (trim(($quote['property_contact_first'] ?? '') . ' ' . ($quote['property_contact_last'] ?? ''))
                                ?: (trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''))
                                    ?: 'Valued Customer'));
                    }
                    ?>
                    <div class="portal-quote-for">Quote for <?php echo htmlspecialchars($clientName); ?></div>
                    <?php if (!empty($quote['property_address'])): ?>
                        <div class="portal-quote-address">
                            <?php echo htmlspecialchars($quote['property_address']); ?><?php if (!empty($quote['property_city'])): ?>, <?php echo htmlspecialchars($quote['property_city']); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <span class="portal-status status-<?php echo htmlspecialchars($quote['_real_status'] ?? $quote['status']); ?>">
                    <?php echo ucfirst($quote['_real_status'] ?? $quote['status']); ?>
                </span>
            </div>

            <div class="portal-quote-detail-grid">
                <div>
                    <div class="portal-quote-detail-label">Quote Date</div>
                    <div class="portal-quote-detail-value"><?php echo date('F j, Y', strtotime($quote['created_at'])); ?></div>
                </div>
                <div>
                    <div class="portal-quote-detail-label">Valid Until</div>
                    <div class="portal-quote-detail-value"><?php echo $quote['valid_until'] ? date('F j, Y', strtotime($quote['valid_until'])) : 'N/A'; ?></div>
                </div>
            </div>

            <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--p-border);">
                <a href="../crm/documents/generate_pdf.php?type=quote&id=<?php echo (int)$quote['id']; ?>&action=download"
                   class="portal-btn-outline">
                    📄 Download PDF
                </a>
            </div>
        </div>

        <!-- Services / Line Items -->
        <div class="portal-info-card">
            <div class="portal-info-card-header">Services</div>
            <div class="portal-info-card-body">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Description</th>
                            <th class="right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Pre-calculate subtotal per named section
                        $sectionTotals = [];
                        foreach (($lineItems ?? []) as $li) {
                            $sn = $li['section_name'] ?? null;
                            if ($sn !== null) {
                                $sectionTotals[$sn] = ($sectionTotals[$sn] ?? 0) + floatval($li['line_total']);
                            }
                        }
                        $hasSections = !empty($sectionTotals);

                        $currentSection    = false;
                        $currentSectionSum = 0;

                        foreach (($lineItems ?? []) as $item):
                            $itemSection = $item['section_name'] ?? null;

                            if ($itemSection !== $currentSection):
                                // Emit subtotal for the section we just finished
                                if ($currentSection !== false && $currentSection !== null && $hasSections): ?>
                            <tr class="portal-table-section-subtotal">
                                <td colspan="2"><?php echo htmlspecialchars($currentSection); ?> subtotal</td>
                                <td class="right"><?php echo formatCurrency($currentSectionSum); ?></td>
                            </tr>
                                    <?php endif;
                                $currentSection    = $itemSection;
                                $currentSectionSum = 0;
                                if ($itemSection !== null): ?>
                            <tr class="portal-table-section-hdr">
                                <td colspan="3"><?php echo htmlspecialchars($itemSection); ?></td>
                            </tr>
                                    <?php endif;
                            endif;

                            $currentSectionSum += floatval($item['line_total']);
                        ?>
                            <?php $isObsidian = stripos($item['service_type'] ?? '', 'obsidian') !== false; ?>
                            <tr<?php echo $isObsidian ? ' class="portal-or-row"' : ''; ?>>
                                <td>
                                    <?php if ($isObsidian): ?>
                                        <div class="or-icon or-icon-full" style="width:56px;height:56px;">
                                            <img src="/assets/images/programs/obsidian-root-logo.png" alt="Obsidian Root™ Program" width="56" height="56">
                                        </div>
                                    <?php else: ?>
                                        <strong><?php echo htmlspecialchars($item['service_type']); ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['description'] ?: '—'); ?></td>
                                <td class="right portal-table-num"><?php echo formatCurrency($item['line_total']); ?></td>
                            </tr>
                        <?php endforeach;

                        // Emit subtotal for the last section
                        if ($currentSection !== false && $currentSection !== null && $hasSections): ?>
                            <tr class="portal-table-section-subtotal">
                                <td colspan="2"><?php echo htmlspecialchars($currentSection); ?> subtotal</td>
                                <td class="right"><?php echo formatCurrency($currentSectionSum); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="portal-totals">
                    <div class="portal-total-row">
                        <span>Subtotal</span>
                        <span id="q-subtotal-val"><?php echo formatCurrency($quote['subtotal'] ?: $quote['amount'] / 1.05); ?></span>
                    </div>
                    <div class="portal-total-row">
                        <span>GST (5%)</span>
                        <span id="q-tax-val"><?php echo formatCurrency($quote['tax_amount'] ?: $quote['amount'] - ($quote['amount'] / 1.05)); ?></span>
                    </div>
                    <div class="portal-total-row grand">
                        <span>Total</span>
                        <span id="q-total-val"><?php echo formatCurrency($quote['amount']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upsell Section — 2-col grid on desktop (sidebar), stacked on mobile -->
        <?php if (in_array($quote['status'], ['sent', 'accepted'])): ?>
            <div class="portal-info-card" id="upsellSection" style="display:none;">
                <div class="portal-info-card-header" id="upsellHeader">
                    <?php echo $quote['status'] === 'sent' ? 'Enhance Your Service — Save by Bundling' : 'Add-On Services'; ?>
                </div>
                <div class="portal-info-card-body">
                    <div class="portal-savings-banner" id="savingsBanner">
                        💰 You're saving <strong id="savingsAmount">$0</strong> with bundle pricing!
                    </div>
                    <p style="font-size:0.85rem;color:var(--p-text-mid);margin-bottom:14px;" id="upsellIntro">
                        <?php if ($quote['status'] === 'sent'): ?>
                            Add recommended services now and get bundle pricing. Prices go up if booked after.
                        <?php else: ?>
                            Want to add more? Regular pricing applies after acceptance.
                        <?php endif; ?>
                    </p>
                    <div id="upsellOptions"></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Undo toast (for post-accept adds) -->
        <div class="portal-undo-toast" id="undoToast">
            <span id="undoToastMsg">Added to your order</span>
            <button type="button" onclick="mwUndoLastAdd()">Undo</button>
        </div>

        <!-- Terms & Conditions -->
        <?php if (!empty($quote['terms'])): ?>
            <div class="portal-info-card">
                <div class="portal-info-card-header">Terms &amp; Conditions</div>
                <div class="portal-info-card-body">
                    <div class="portal-terms-box"><?php echo htmlspecialchars($quote['terms']); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Customer Notes -->
        <?php if (!empty($quote['notes_customer'])): ?>
            <div class="portal-info-card">
                <div class="portal-info-card-header">Additional Notes</div>
                <div class="portal-info-card-body" style="white-space:pre-line;font-size:0.875rem;color:var(--p-text-mid);line-height:1.6;"><?php echo htmlspecialchars($quote['notes_customer']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Acceptance Section (sent status only) -->
        <?php if ($quote['status'] === 'sent'): ?>
            <div class="portal-info-card">
                <div class="portal-info-card-header">Accept This Quote</div>
                <div class="portal-info-card-body">
                    <?php if ($isAdminPreview): ?>
                        <div style="text-align:center;padding:24px;color:var(--p-text-mid);font-style:italic;">
                            ✏️ <strong>Preview mode:</strong> The signature form appears here for your client. Disabled in this preview.
                        </div>
                    <?php else: ?>
                        <p style="font-size:0.875rem;color:var(--p-text-mid);margin-bottom:22px;">
                            To accept this quote, please sign below and confirm your details.
                        </p>

                        <form method="POST" id="acceptForm">
                            <input type="hidden" name="action" value="accept">
                            <input type="hidden" name="signature_data" id="signatureData">

                            <div class="portal-form-group">
                                <label class="portal-form-label">Your Full Name *</label>
                                <input type="text" name="signature_name" class="portal-form-input" required
                                       placeholder="Enter your full name"
                                       value="<?php echo htmlspecialchars(trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''))); ?>">
                            </div>

                            <div class="portal-form-group">
                                <label class="portal-form-label">Your Email</label>
                                <input type="email" name="signature_email" class="portal-form-input"
                                       placeholder="Enter your email"
                                       value="<?php echo htmlspecialchars($quote['contact_email'] ?? ''); ?>">
                            </div>

                            <div class="portal-form-group">
                                <label class="portal-form-label">Your Signature *</label>
                                <div class="portal-sig-wrap">
                                    <canvas id="signaturePad" class="portal-sig-canvas"></canvas>
                                    <div class="portal-sig-placeholder" id="sigPlaceholder">Sign here with mouse or finger</div>
                                    <div class="portal-sig-actions">
                                        <button type="button" class="portal-sig-clear" id="clearSignature">Clear Signature</button>
                                    </div>
                                </div>
                            </div>

                            <div class="portal-checkbox-row">
                                <input type="checkbox" name="agree_terms" id="agreeTerms" required>
                                <label for="agreeTerms">
                                    I have read and agree to the terms and conditions above. I authorize Mowology to perform the services described in this quote.
                                </label>
                            </div>

                            <button type="submit" class="portal-btn wide">
                                ✓ Accept Quote &amp; Schedule Service
                            </button>
                        </form>

                        <div class="portal-decline-section">
                            <button class="portal-decline-toggle" onclick="document.getElementById('declineForm').classList.toggle('show')">
                                Not interested? Let us know
                            </button>

                            <form method="POST" class="portal-decline-form" id="declineForm">
                                <input type="hidden" name="action" value="decline">
                                <div class="portal-form-group">
                                    <label class="portal-form-label">Reason (optional)</label>
                                    <textarea name="decline_reason" class="portal-form-input portal-form-textarea"
                                              placeholder="Let us know why you're declining…"></textarea>
                                </div>
                                <button type="submit" class="portal-btn-outline" style="width:100%;padding:11px 18px;">
                                    Decline Quote
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($quote['status'] === 'accepted'): ?>
            <div class="portal-info-card">
                <div class="portal-accepted-state">
                    <div class="portal-accepted-icon">✓</div>
                    <h3 style="color:var(--p-green);font-size:1.1rem;margin-bottom:8px;">Quote Accepted</h3>
                    <?php if (!empty($quote['accepted_at'])): ?>
                        <p style="color:var(--p-text-mid);">This quote was accepted on <?php echo date('F j, Y', strtotime($quote['accepted_at'])); ?>.</p>
                    <?php else: ?>
                        <p style="color:var(--p-text-mid);">This quote has been accepted.</p>
                    <?php endif; ?>
                    <p style="color:var(--p-text-mid);margin-top:6px;">We will be in touch shortly to schedule your service.</p>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <div class="portal-footer">
        Questions? Call <a href="tel:7788469273">(778) 846-9273</a> &mdash; &copy; <?php echo date('Y'); ?> Mowology
    </div>

</div>

<?php if (!empty($quote) && in_array($quote['status'], ['sent', 'accepted'])): ?>
<script>
    // ── Signature Pad ─────────────────────────────────────────────────────────
    <?php if (!$isAdminPreview): ?>
    var canvas = document.getElementById('signaturePad');
    var placeholder = document.getElementById('sigPlaceholder');
    // Skip signature setup on accepted quotes (no pad rendered)
    if (canvas) {
    var signaturePad = new SignaturePad(canvas, {
        backgroundColor: 'rgb(255, 255, 255)',
        penColor: 'rgb(13, 59, 46)'
    });

    function resizeCanvas() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var container = canvas.parentElement;
        canvas.width = container.offsetWidth * ratio;
        canvas.height = 180 * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        canvas.style.width = container.offsetWidth + 'px';
        canvas.style.height = '180px';
        signaturePad.clear();
    }

    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    signaturePad.addEventListener('beginStroke', function() {
        placeholder.style.display = 'none';
    });

    document.getElementById('clearSignature').addEventListener('click', function() {
        signaturePad.clear();
        placeholder.style.display = 'block';
    });

    var acceptForm = document.getElementById('acceptForm');
    if (acceptForm) {
        acceptForm.addEventListener('submit', function(e) {
            if (signaturePad.isEmpty()) {
                e.preventDefault();
                alert('Please sign the quote before submitting.');
                return;
            }
            document.getElementById('signatureData').value = signaturePad.toDataURL();
        });
    }
    } // end: if (canvas)
    <?php endif; ?>

    // ── Upsell Management (FOMO bundle pricing + live savings counter + popular) ──
    (function() {
        var quoteToken = <?php echo json_encode($token ?? ''); ?>;
        var upsellSection = document.getElementById('upsellSection');
        var upsellContainer = document.getElementById('upsellOptions');
        var savingsBanner = document.getElementById('savingsBanner');
        var savingsAmount = document.getElementById('savingsAmount');
        if (!upsellSection || !upsellContainer || !quoteToken) return;

        // Cache of upsell metadata for savings calc
        window._mwUpsells = {};
        window._mwIsPostAccept = false;

        function fmtMoney(amount) {
            var n = parseFloat(amount) || 0;
            return '$' + n.toFixed(2);
        }

        function updateSavingsBanner() {
            if (window._mwIsPostAccept) return; // no banner in post-accept mode
            var total = 0;
            document.querySelectorAll('.portal-upsell-card input[type=checkbox]:checked').forEach(function(cb) {
                var card = cb.closest('.portal-upsell-card');
                var pid = card && card.getAttribute('data-product-id');
                var meta = window._mwUpsells[pid];
                if (meta) total += (parseFloat(meta.savings) || 0);
            });
            if (total > 0) {
                savingsAmount.textContent = fmtMoney(total);
                savingsBanner.classList.add('show');
            } else {
                savingsBanner.classList.remove('show');
            }
        }

        function renderCard(up, isPostAccept) {
            var text    = up.display_text || up.upsell_product_name;
            var desc    = up.upsell_description || '';
            var regular = parseFloat(up.regular_price) || 0;
            var bundled = parseFloat(up.bundled_price) || regular;
            var savings = parseFloat(up.savings) || (regular - bundled);
            var checked = up.is_added;
            var popular = up.is_popular;

            // Unified ID for the DOM — use product id if present, else bundle id with 'b' prefix
            var domId = up.upsell_product_id || ('b' + up.upsell_bundle_id);

            // Cache for savings calc
            window._mwUpsells[domId] = {
                regular: regular, bundled: bundled, savings: savings,
                is_bundle: !!up.is_bundle,
                product_id: up.upsell_product_id || null,
                bundle_id: up.upsell_bundle_id || null
            };

            var escText = String(text).replace(/</g, '&lt;');
            var escDesc = String(desc).replace(/</g, '&lt;');

            // In post-accept mode, render as action-card (Add button instead of checkbox)
            if (isPostAccept) {
                // Skip showing already-added upsells in the post-accept sheet
                if (checked) return '';
                return '<div class="portal-post-accept-card" data-product-id="' + domId + '">' +
                    (popular ? '<span class="portal-upsell-badge">⭐ Popular</span>' : '') +
                    '<div style="flex:1;">' +
                        '<strong>' + escText + (up.is_bundle ? ' <small style="color:#64748b;">(bundle)</small>' : '') + '</strong>' +
                        (desc ? '<span class="portal-post-accept-missed">' + escDesc + '</span>' : '') +
                        (savings > 0 ? '<span class="portal-post-accept-missed">You missed ' + fmtMoney(savings) + ' in bundle savings.</span>' : '') +
                    '</div>' +
                    '<div style="text-align:right;">' +
                        '<div style="font-weight:700;color:var(--p-text-dark, #1a202c);">' + fmtMoney(regular) + '</div>' +
                        '<button type="button" class="portal-post-accept-add-btn" onclick="mwAddUpsell(\'' + domId + '\', this)">+ Add</button>' +
                    '</div>' +
                '</div>';
            }

            // Pre-accept: rich FOMO card with both prices
            return '<div class="portal-upsell-card' + (checked ? ' is-added' : '') + (popular ? ' is-popular' : '') + '" data-product-id="' + domId + '">' +
                (popular ? '<span class="portal-upsell-badge">⭐ Most popular</span>' : '') +
                '<label class="portal-upsell-toggle">' +
                    '<input type="checkbox" ' + (checked ? 'checked' : '') +
                    ' onchange="mwToggleUpsell(\'' + domId + '\', this.checked)">' +
                    '<div class="portal-upsell-info">' +
                        '<strong>' + escText + '</strong>' +
                        (desc ? '<span class="portal-upsell-desc">' + escDesc + '</span>' : '') +
                    '</div>' +
                '</label>' +
                '<div class="portal-upsell-pricing">' +
                    '<span class="portal-upsell-price-bundled">' + fmtMoney(bundled) + '</span>' +
                    (savings > 0.01 ? '<span class="portal-upsell-price-regular">' + fmtMoney(regular) + '</span>' : '') +
                    (savings > 0.01 ? '<span class="portal-upsell-savings-chip">Save ' + fmtMoney(savings) + '</span>' : '') +
                '</div>' +
            '</div>';
        }

        fetch('api/quote-upsell.php?action=get-upsells&token=' + encodeURIComponent(quoteToken))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !data.upsells || data.upsells.length === 0) return;

                window._mwIsPostAccept = !!data.is_post_accept;
                upsellSection.style.display = '';

                var html = data.upsells.map(function(up) { return renderCard(up, window._mwIsPostAccept); }).join('');
                if (!html.trim()) {
                    // Post-accept and all already added — hide section
                    upsellSection.style.display = 'none';
                    return;
                }
                upsellContainer.innerHTML = html;
                updateSavingsBanner();
            })
            .catch(function() { /* silently fail */ });
    })();

    // Pre-accept: toggle via checkbox
    function mwToggleUpsell(domId, add) {
        var meta = window._mwUpsells[domId] || {};
        var formData = new FormData();
        formData.append('token', <?php echo json_encode($token ?? ''); ?>);
        formData.append('action', add ? 'add-upsell' : 'remove-upsell');
        if (meta.is_bundle) formData.append('upsell_bundle_id', meta.bundle_id);
        else formData.append('upsell_product_id', meta.product_id || domId);

        var card = document.querySelector('.portal-upsell-card[data-product-id="' + domId + '"]');
        var cb = card && card.querySelector('input[type=checkbox]');

        fetch('api/quote-upsell.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.totals) {
                    var sub = document.getElementById('q-subtotal-val');
                    var tax = document.getElementById('q-tax-val');
                    var tot = document.getElementById('q-total-val');
                    if (sub) sub.textContent = '$' + parseFloat(data.totals.subtotal).toFixed(2);
                    if (tax) tax.textContent = '$' + parseFloat(data.totals.tax_amount).toFixed(2);
                    if (tot) tot.textContent = '$' + parseFloat(data.totals.total).toFixed(2);
                    if (card) card.classList.toggle('is-added', add);
                    // Update savings banner
                    if (typeof updateSavingsBanner === 'undefined') {
                        // rebuild banner inline
                        var total = 0;
                        document.querySelectorAll('.portal-upsell-card input[type=checkbox]:checked').forEach(function(icb) {
                            var c = icb.closest('.portal-upsell-card');
                            var pid = c && c.getAttribute('data-product-id');
                            var meta = window._mwUpsells[pid];
                            if (meta) total += (parseFloat(meta.savings) || 0);
                        });
                        var banner = document.getElementById('savingsBanner');
                        var amt = document.getElementById('savingsAmount');
                        if (total > 0 && banner && amt) {
                            amt.textContent = '$' + total.toFixed(2);
                            banner.classList.add('show');
                        } else if (banner) {
                            banner.classList.remove('show');
                        }
                    }
                } else if (!data.success) {
                    alert(data.error || 'Could not update quote');
                    if (cb) cb.checked = !add;
                }
            })
            .catch(function(err) {
                alert('Error: ' + err.message);
                if (cb) cb.checked = !add;
            });
    }

    // Post-accept: one-click add with undo toast (no confirmation modal — friction kills conversion)
    window._mwLastAdd = null;
    function mwAddUpsell(domId, btn) {
        if (btn) { btn.disabled = true; btn.textContent = '...'; }
        var meta = window._mwUpsells[domId] || {};
        var formData = new FormData();
        formData.append('token', <?php echo json_encode($token ?? ''); ?>);
        formData.append('action', 'add-upsell');
        if (meta.is_bundle) formData.append('upsell_bundle_id', meta.bundle_id);
        else formData.append('upsell_product_id', meta.product_id || domId);

        fetch('api/quote-upsell.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var sub = document.getElementById('q-subtotal-val');
                    var tax = document.getElementById('q-tax-val');
                    var tot = document.getElementById('q-total-val');
                    if (sub && data.totals) sub.textContent = '$' + parseFloat(data.totals.subtotal).toFixed(2);
                    if (tax && data.totals) tax.textContent = '$' + parseFloat(data.totals.tax_amount).toFixed(2);
                    if (tot && data.totals) tot.textContent = '$' + parseFloat(data.totals.total).toFixed(2);

                    // Remove the card from the post-accept sheet
                    var card = document.querySelector('.portal-post-accept-card[data-product-id="' + domId + '"]');
                    window._mwLastAdd = domId;
                    if (card) {
                        card.style.transition = 'opacity 0.3s';
                        card.style.opacity = '0.3';
                        setTimeout(function() { card.remove(); }, 300);
                    }
                    // Show undo toast for 5s
                    var toast = document.getElementById('undoToast');
                    var msg = document.getElementById('undoToastMsg');
                    if (msg) msg.textContent = 'Added — ' + (data.line_total ? '$' + parseFloat(data.line_total).toFixed(2) : '');
                    if (toast) {
                        toast.classList.add('show');
                        clearTimeout(window._mwToastTimer);
                        window._mwToastTimer = setTimeout(function() { toast.classList.remove('show'); }, 5000);
                    }
                } else {
                    if (btn) { btn.disabled = false; btn.textContent = '+ Add'; }
                    alert(data.error || 'Could not add item');
                }
            })
            .catch(function(err) {
                if (btn) { btn.disabled = false; btn.textContent = '+ Add'; }
                alert('Error: ' + err.message);
            });
    }

    function mwUndoLastAdd() {
        var domId = window._mwLastAdd;
        if (!domId) return;
        var meta = window._mwUpsells[domId] || {};
        var toast = document.getElementById('undoToast');

        var formData = new FormData();
        formData.append('token', <?php echo json_encode($token ?? ''); ?>);
        formData.append('action', 'remove-upsell');
        if (meta.is_bundle) formData.append('upsell_bundle_id', meta.bundle_id);
        else formData.append('upsell_product_id', meta.product_id || domId);

        fetch('api/quote-upsell.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (toast) toast.classList.remove('show');
                if (data.success) {
                    // Reload to re-render the post-accept sheet with the item back
                    location.reload();
                } else {
                    alert(data.error || 'Could not undo');
                }
            });
    }

    // Legacy function name kept for backwards compatibility
    function toggleUpsell(productId, add) { return mwToggleUpsell(productId, add); }
</script>
<?php endif; ?>

</body>
</html>
