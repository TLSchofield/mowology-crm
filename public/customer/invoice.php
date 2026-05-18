<?php
/**
 * Customer Invoice View & Pay
 * Token-based (no login required).
 * URL: /customer/invoice.php?token=ABC123
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once dirname(__DIR__) . '/app_config/config.php';

$db    = getDB();
$error = '';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    $error = 'Invalid or missing invoice link.';
}

$invoice   = null;
$lineItems = [];

if (!$error) {
    $stmt = $db->prepare("
        SELECT
            i.*,
            c.company_name,
            c.billing_email,
            c.billing_phone,
            COALESCE(ct.first_name, dc.first_name)         as contact_first,
            COALESCE(ct.last_name, dc.last_name)           as contact_last,
            COALESCE(ct.email, dc.email)                   as contact_email,
            COALESCE(ct.phone, dc.phone)                   as contact_phone,
            COALESCE(dc.stripe_card_last4, ct.stripe_card_last4) as contact_card_last4,
            COALESCE(dc.autopay_enrolled_at, ct.autopay_enrolled_at) as contact_autopay_enrolled_at
        FROM invoices i
        LEFT JOIN companies c  ON i.company_id = c.id
        LEFT JOIN contacts ct  ON c.primary_contact_id = ct.id
        LEFT JOIN contacts dc  ON i.contact_id = dc.id
        LEFT JOIN properties p ON i.property_id = p.id
        WHERE i.access_token = ?
          AND (i.token_expires_at IS NULL OR i.token_expires_at > NOW())
        LIMIT 1
    ");
    // Note: property join retained for address data via i.* fallback
    $stmt->execute([$token]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $error = 'This invoice link is invalid or has expired. Please contact Mowology at (778) 846-9273.';
    } else {
        // ── View tracking ────────────────────────────────────────────────
        // Update view_count + timestamps on every portal access,
        // and flip status to 'viewed' on first view after send.
        try {
            $db->prepare("
                UPDATE invoices
                SET viewed_at      = COALESCE(viewed_at, NOW()),
                    last_viewed_at = NOW(),
                    view_count     = view_count + 1,
                    status         = CASE WHEN status = 'sent' THEN 'viewed' ELSE status END
                WHERE id = ?
            ")->execute([$invoice['id']]);

            if ($invoice['status'] === 'sent') {
                $invoice['status'] = 'viewed';
            }

            // Update per-recipient open tracking (match by contact_id)
            if (!empty($invoice['contact_id'])) {
                $db->prepare("
                    UPDATE invoice_contacts
                    SET invoice_opened_at = COALESCE(invoice_opened_at, NOW())
                    WHERE invoice_id = ? AND contact_id = ?
                ")->execute([$invoice['id'], $invoice['contact_id']]);
            }

            // Log portal view to activity_log (first view only)
            if (empty($invoice['viewed_at'])) {
                $viewerName = trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? '')) ?: ($invoice['company_name'] ?: 'Customer');
                $db->prepare("
                    INSERT INTO activity_log (user_id, action, details, invoice_id, created_at)
                    VALUES (NULL, 'Customer viewed invoice', ?, ?, NOW())
                ")->execute([
                    "Invoice viewed via customer portal by {$viewerName}",
                    $invoice['id']
                ]);
            }
        } catch (Exception $e) {
            // Non-critical — silently skip if columns missing
            error_log("Invoice view tracking update failed: " . $e->getMessage());
        }

        // Get line items
        $stmt = $db->prepare("SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order");
        $stmt->execute([$invoice['id']]);
        $lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$isPayable         = $invoice && in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue']);
$contactName       = $invoice ? trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? '')) : '';
$displayName       = $invoice ? ($invoice['company_name'] ?: $contactName ?: 'Valued Customer') : '';
$hasSavedCard      = $invoice && !empty($invoice['contact_card_last4']);
$autopayEnrolled   = $invoice && !empty($invoice['contact_autopay_enrolled_at']);
$showAutopayBanner = $hasSavedCard && !$autopayEnrolled && $invoice; // has a card but not yet enrolled
$autopayUrl        = $invoice ? '/customer/autopay.php?token=' . urlencode($invoice['access_token']) : '#';

// ── helpers ──────────────────────────────────────────────────────────────────
function fmt(float $n): string {
    return '$' . number_format($n, 2);
}
function fmtDate(string $d): string {
    return $d ? date('F j, Y', strtotime($d)) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice<?php echo $invoice ? ' ' . htmlspecialchars($invoice['invoice_number']) : ''; ?> | Mowology</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/customer/portal.css">
    <?php if ($isPayable): ?>
        <script src="https://js.stripe.com/v3/" defer></script>
    <?php endif; ?>
</head>
<body>

<header class="portal-header">
    <?php if ($invoice && !empty($invoice['contact_id'])): ?>
        <a href="/customer/portal.php?contact_id=<?php echo (int)$invoice['contact_id']; ?>" class="portal-back-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            Portal
        </a>
    <?php endif; ?>
    <div class="portal-logo-text">Mowo<span>logy</span></div>
    <div class="portal-header-divider"></div>
    <span class="portal-header-label">Invoice</span>
    <?php if ($invoice): ?>
        <span class="portal-client-name"><?php echo htmlspecialchars($displayName); ?></span>
    <?php endif; ?>
</header>

<div class="portal-container">

<?php if ($error): ?>
    <div class="portal-error">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:#cdddd6;margin:0 auto 16px;display:block"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <h2>Link Not Found</h2>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>

<?php else: ?>

    <?php
    $statusLabels = [
        'draft'   => 'Draft',
        'sent'    => 'Invoice Sent',
        'viewed'  => 'Viewed',
        'partial' => 'Partially Paid',
        'overdue' => 'Overdue',
        'paid'    => 'Paid',
    ];
    $statusLabel = $statusLabels[$invoice['status']] ?? ucfirst($invoice['status']);
    ?>

    <!-- Invoice top card -->
    <div class="portal-invoice-top">
        <div>
            <div class="portal-invoice-number">
                <small>Invoice Number</small>
                <?php echo htmlspecialchars($invoice['invoice_number']); ?>
            </div>
            <div style="margin-top:10px;">
                <span class="portal-status status-<?php echo htmlspecialchars($invoice['status']); ?>">
                    <?php echo htmlspecialchars($statusLabel); ?>
                </span>
            </div>
        </div>
        <div class="portal-invoice-meta">
            <div><strong>Issue Date:</strong> <?php echo fmtDate($invoice['issue_date'] ?? $invoice['invoice_date'] ?? ''); ?></div>
            <div><strong>Due Date:</strong> <?php echo fmtDate($invoice['due_date'] ?? ''); ?></div>
        </div>
    </div>

    <?php if ($invoice['status'] === 'paid'): ?>
        <div class="portal-paid-banner">
            <h3>&#10003; Invoice Paid</h3>
            <p>Thank you! This invoice has been paid in full.</p>
        </div>
    <?php elseif ($isPayable): ?>
        <!-- Pay now bar -->
        <div class="portal-pay-bar">
            <div>
                <div class="due-label">Amount Due</div>
                <div class="due-amount"><?php echo fmt(floatval($invoice['balance_due'])); ?> CAD</div>
            </div>
            <button class="portal-btn" style="background:var(--p-bg);color:var(--p-dark);" onclick="openPayModal()">Pay Online Now</button>
        </div>
    <?php endif; ?>

    <!-- Billing Information -->
    <div class="portal-info-card">
        <div class="portal-info-card-header">Billing Information</div>
        <div class="portal-info-card-body">
            <div class="portal-bill-grid">
                <div class="portal-bill-section">
                    <h4>Bill To</h4>
                    <?php if ($invoice['company_name']): ?>
                        <div class="portal-bill-name"><?php echo htmlspecialchars($invoice['company_name']); ?></div>
                    <?php endif; ?>
                    <?php if ($contactName): ?>
                        <div class="portal-bill-name"><?php echo htmlspecialchars($contactName); ?></div>
                    <?php endif; ?>
                    <?php
                    $email      = $invoice['contact_email'] ?: $invoice['billing_email'] ?: '';
                    $phone      = $invoice['contact_phone'] ?: $invoice['billing_phone'] ?: '';
                    $billAddr   = trim($invoice['billing_address'] ?? '');
                    $billCity   = trim($invoice['billing_city'] ?? '');
                    $billProv   = trim($invoice['billing_province'] ?? '');
                    $billPostal = trim($invoice['billing_postal_code'] ?? '');
                    $billLine   = trim($billAddr . ($billCity ? ', ' . $billCity : '') . ($billProv ? ' ' . $billProv : '') . ($billPostal ? ' ' . $billPostal : ''));
                    ?>
                    <?php if ($billLine): ?>
                        <div class="portal-bill-detail"><?php echo htmlspecialchars($billLine); ?></div>
                    <?php endif; ?>
                    <?php if ($email): ?>
                        <div class="portal-bill-detail"><?php echo htmlspecialchars($email); ?></div>
                    <?php endif; ?>
                    <?php if ($phone): ?>
                        <div class="portal-bill-detail"><?php echo htmlspecialchars($phone); ?></div>
                    <?php endif; ?>
                </div>
                <div class="portal-bill-section">
                    <h4>From</h4>
                    <div class="portal-bill-name">Mowology Landscaping</div>
                    <div class="portal-bill-detail">
                        (778) 846-9273<br>
                        hello@mowology.ca<br>
                        mowology.ca
                    </div>
                    <?php if (!empty($invoice['property_address'])): ?>
                        <div style="margin-top:12px;">
                            <h4>Service Address</h4>
                            <div class="portal-bill-detail">
                                <?php echo htmlspecialchars($invoice['property_address']); ?><br>
                                <?php echo htmlspecialchars($invoice['property_city'] ?? ''); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Line Items -->
    <div class="portal-info-card">
        <div class="portal-info-card-header">Services</div>
        <div class="portal-info-card-body">
            <table class="portal-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="right">Qty</th>
                        <th class="right">Unit Price</th>
                        <th class="right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['description'] ?: 'Services rendered'); ?></td>
                            <td class="right"><?php echo $item['quantity']; ?></td>
                            <td class="right portal-table-num"><?php echo fmt(floatval($item['unit_price'])); ?></td>
                            <td class="right portal-table-num"><?php echo fmt(floatval($item['line_total'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="portal-totals">
                <div class="portal-total-row">
                    <span>Subtotal</span>
                    <span class="portal-table-num"><?php echo fmt(floatval($invoice['subtotal'])); ?></span>
                </div>
                <div class="portal-total-row">
                    <span>GST (<?php echo round(($invoice['tax_rate'] ?: 0.05) * 100); ?>%)<?php if (!empty($invoice['gst_number'])): ?><br><small style="font-weight:400;font-size:0.78em;opacity:0.7;">Reg# <?php echo htmlspecialchars($invoice['gst_number']); ?></small><?php endif; ?></span>
                    <span class="portal-table-num"><?php echo fmt(floatval($invoice['tax_amount'] ?? 0)); ?></span>
                </div>
                <div class="portal-total-row grand">
                    <span>Total</span>
                    <span class="portal-table-num"><?php echo fmt(floatval($invoice['total'] ?: $invoice['total_amount'] ?? 0)); ?></span>
                </div>
                <?php if (floatval($invoice['amount_paid'] ?? 0) > 0): ?>
                    <div class="portal-total-row paid-row">
                        <span>Paid</span>
                        <span class="portal-table-num">-<?php echo fmt(floatval($invoice['amount_paid'])); ?></span>
                    </div>
                    <div class="portal-total-row balance-row">
                        <span>Balance Due</span>
                        <span class="portal-table-num"><?php echo fmt(floatval($invoice['balance_due'])); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($invoice['notes'])): ?>
        <div class="portal-info-card">
            <div class="portal-info-card-header">Notes</div>
            <div class="portal-info-card-body" style="white-space:pre-line;font-size:0.875rem;color:var(--p-text-mid);line-height:1.6;"><?php echo htmlspecialchars($invoice['notes']); ?></div>
        </div>
    <?php endif; ?>

    <!-- Questions -->
    <div class="portal-info-card">
        <div class="portal-info-card-header">Questions?</div>
        <div class="portal-info-card-body" style="font-size:0.875rem;color:var(--p-text-mid);">
            <p>Please contact us at <a href="tel:+17788469273">(778) 846-9273</a> or
            <a href="mailto:hello@mowology.ca">hello@mowology.ca</a>.
            We're happy to help.</p>
        </div>
    </div>

    <!-- Payment modal -->
    <?php if ($isPayable): ?>
    <div id="payModal" class="portal-overlay" role="dialog" aria-modal="true" aria-labelledby="payModalTitle">
        <div class="portal-modal">
            <div class="portal-modal-hdr">
                <h3 id="payModalTitle">Pay Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></h3>
                <button class="portal-modal-close" onclick="closePayModal()" aria-label="Close">&times;</button>
            </div>
            <div class="portal-modal-body">
                <div class="portal-modal-amount">
                    <span>Amount Due</span>
                    <strong><?php echo fmt(floatval($invoice['balance_due'])); ?> CAD</strong>
                </div>

                <!-- Saved card section (shown when customer has a card on file) -->
                <div id="savedCardSection" style="display:none;">
                    <div class="portal-saved-card">
                        <div class="portal-saved-card-icon" id="savedCardIcon">💳</div>
                        <div class="portal-saved-card-info">
                            <strong id="savedCardBrand">Visa</strong>
                            <span id="savedCardDesc">ending in ••••1234 &mdash; expires 12/28</span>
                        </div>
                        <div class="portal-saved-card-actions">
                            <span class="portal-saved-card-badge">Card on file</span>
                            <button class="portal-btn-text" onclick="showNewCardForm()">Use a different card</button>
                        </div>
                    </div>
                    <?php if (!$autopayEnrolled): ?>
                    <div id="autopayWrap" class="portal-save-card-wrap" style="display:none;margin-top:10px;">
                        <input type="checkbox" id="autopayCheck">
                        <label for="autopayCheck">
                            <strong>Enable autopay</strong>
                            Automatically charge this card when future invoices are sent.
                        </label>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="stripeLoading">
                    <span>Loading secure payment form&hellip;</span>
                </div>
                <div id="payment-element" style="display:none;"></div>
                <div id="saveCardWrap" class="portal-save-card-wrap" style="display:none;">
                    <input type="checkbox" id="saveCardCheck" name="save_card">
                    <label for="saveCardCheck">
                        <strong>Save card for future invoices</strong>
                        Securely store your card so you can pay future invoices with one click.
                    </label>
                </div>
                <?php if (!$autopayEnrolled): ?>
                <div id="autopayNewWrap" class="portal-save-card-wrap" style="display:none;margin-top:4px;padding-left:28px;">
                    <input type="checkbox" id="autopayNewCheck">
                    <label for="autopayNewCheck" style="font-size:.85rem;">
                        <strong>Enable autopay</strong>
                        Automatically charge this card for future invoices.
                    </label>
                </div>
                <?php endif; ?>

                <div id="stripeError" class="portal-stripe-error" style="display:none;"></div>
            </div>
            <div class="portal-modal-footer" id="stripeFooter" style="display:none;">
                <button class="portal-btn-outline" onclick="closePayModal()">Cancel</button>
                <button id="stripePay" class="portal-btn" onclick="submitPayment()" disabled>
                    <span id="stripePayLabel">Pay <?php echo fmt(floatval($invoice['balance_due'])); ?></span>
                    <span id="stripePaySpinner" style="display:none;">Processing&hellip;</span>
                </button>
            </div>
            <!-- Saved card quick-pay footer (shown when using card on file) -->
            <div class="portal-modal-footer" id="savedCardFooter" style="display:none;">
                <button class="portal-btn-outline" onclick="closePayModal()">Cancel</button>
                <button id="savedCardPay" class="portal-btn" onclick="submitSavedCardPayment()" disabled>
                    <span id="savedCardPayLabel">Pay <?php echo fmt(floatval($invoice['balance_due'])); ?></span>
                    <span id="savedCardPaySpinner" style="display:none;">Processing&hellip;</span>
                </button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var stripe = null, elements = null, paymentEl = null, fetched = false;
        var intentData = null; // full API response
        var usingNewCard = false; // true when customer chose "use different card"

        // Card brand icons (emoji fallbacks — clean and mobile-friendly)
        var brandIcons = {
            visa: '💳', mastercard: '💳', amex: '💳',
            discover: '💳', jcb: '💳', diners: '💳',
            unionpay: '💳', unknown: '💳'
        };

        window.openPayModal = function () {
            document.getElementById('payModal').classList.add('open');
            if (!fetched) initStripe();
        };
        window.closePayModal = function () {
            document.getElementById('payModal').classList.remove('open');
        };

        document.getElementById('payModal').addEventListener('click', function (e) {
            if (e.target === this) closePayModal();
        });

        function initStripe() {
            fetch('/customer/api/invoice-payment-intent.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body   : JSON.stringify({
                    token    : <?php echo json_encode($token); ?>,
                    save_card: false
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    showError(data.error);
                    document.getElementById('stripeLoading').style.display = 'none';
                    return;
                }
                fetched = true;
                intentData = data;
                stripe = Stripe(data.publishable_key);

                // ── Show saved card UI if customer has one ────────────────────
                if (data.has_saved_card && data.default_payment_method && !usingNewCard) {
                    showSavedCardUI(data);
                } else {
                    mountNewCardForm(data);
                }
            })
            .catch(function (err) {
                console.error(err);
                showError('Unable to load payment form. Please refresh and try again.');
                document.getElementById('stripeLoading').style.display = 'none';
            });
        }

        // ── Show saved card section ───────────────────────────────────────────
        function showSavedCardUI(data) {
            var brand = (data.saved_card_brand  || 'card').toLowerCase();
            var last4 = data.saved_card_last4   || '????';
            var exp   = data.saved_card_exp     || '';

            // Format expiry: "2028-12" → "12/28"  or "12/2028" → "12/28"
            var expFormatted = '';
            if (exp) {
                var parts = exp.split('/');
                if (parts.length === 2) {
                    expFormatted = parts[0] + '/' + parts[1].slice(-2);
                } else if (exp.indexOf('-') !== -1) {
                    var dp = exp.split('-');
                    expFormatted = dp[1] + '/' + dp[0].slice(-2);
                } else {
                    expFormatted = exp;
                }
            }

            document.getElementById('savedCardBrand').textContent = brand.charAt(0).toUpperCase() + brand.slice(1);
            document.getElementById('savedCardDesc').innerHTML     = 'ending in \u2022\u2022\u2022\u2022' + last4 + (expFormatted ? ' &mdash; expires ' + expFormatted : '');
            document.getElementById('savedCardIcon').textContent   = brandIcons[brand] || '💳';

            document.getElementById('savedCardSection').style.display = 'block';
            document.getElementById('stripeLoading').style.display    = 'none';
            document.getElementById('savedCardFooter').style.display  = 'flex';
            document.getElementById('savedCardPay').disabled          = false;

            // Show autopay opt-in for saved card path
            var autopayWrap = document.getElementById('autopayWrap');
            if (autopayWrap) autopayWrap.style.display = 'flex';

            // Hide new card elements
            document.getElementById('payment-element').style.display = 'none';
            document.getElementById('stripeFooter').style.display    = 'none';
            document.getElementById('saveCardWrap').style.display     = 'none';
        }

        // ── Show new card form ────────────────────────────────────────────────
        function mountNewCardForm(data) {
            elements = stripe.elements({
                clientSecret: data.client_secret,
                appearance  : {
                    theme    : 'stripe',
                    variables: {
                        colorPrimary : '#2D8659',
                        colorText    : '#1A5F4A',
                        borderRadius : '6px',
                        fontFamily   : 'Montserrat, system-ui, -apple-system, sans-serif',
                    }
                }
            });
            paymentEl = elements.create('payment', { layout: 'tabs' });
            paymentEl.mount('#payment-element');
            paymentEl.on('ready', function () {
                document.getElementById('stripeLoading').style.display  = 'none';
                document.getElementById('payment-element').style.display = 'block';
                document.getElementById('stripeFooter').style.display   = 'flex';
                document.getElementById('stripePay').disabled            = false;
                // Show "save card" checkbox for new card entry
                document.getElementById('saveCardWrap').style.display   = 'flex';
                // Toggle autopay sub-option when save card is checked
                var saveCardCheck = document.getElementById('saveCardCheck');
                var autopayNewWrap = document.getElementById('autopayNewWrap');
                if (saveCardCheck && autopayNewWrap) {
                    saveCardCheck.addEventListener('change', function () {
                        autopayNewWrap.style.display = this.checked ? 'flex' : 'none';
                        if (!this.checked) {
                            var cb = document.getElementById('autopayNewCheck');
                            if (cb) cb.checked = false;
                        }
                    });
                }
            });
            paymentEl.on('change', function (e) {
                if (e.error) showError(e.error.message); else clearError();
            });
        }

        // ── Customer clicked "Use a different card" ───────────────────────────
        window.showNewCardForm = function () {
            usingNewCard = true;
            document.getElementById('savedCardSection').style.display = 'none';
            document.getElementById('savedCardFooter').style.display  = 'none';
            document.getElementById('stripeLoading').style.display    = 'block';

            if (intentData && elements === null) {
                // Elements not yet created — mount fresh
                mountNewCardForm(intentData);
            } else if (elements !== null) {
                // Already mounted, just show it
                document.getElementById('stripeLoading').style.display  = 'none';
                document.getElementById('payment-element').style.display = 'block';
                document.getElementById('stripeFooter').style.display   = 'flex';
                document.getElementById('saveCardWrap').style.display   = 'flex';
            }
        };

        // ── Pay with new card ─────────────────────────────────────────────────
        window.submitPayment = function () {
            if (!stripe || !elements) return;
            clearError();

            // If customer checked "save card", we need a fresh intent with setup_future_usage
            var wantsSaveCard  = document.getElementById('saveCardCheck').checked;
            var autopayNewCheck = document.getElementById('autopayNewCheck');
            var wantsAutopay   = wantsSaveCard && autopayNewCheck && autopayNewCheck.checked;
            setLoading(true);

            var doConfirm = function () {
                stripe.confirmPayment({
                    elements      : elements,
                    confirmParams : { return_url: window.location.href + '&payment=success' },
                    redirect      : 'if_required'
                })
                .then(function (result) {
                    setLoading(false);
                    if (result.error) {
                        showError(result.error.message);
                    } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                        showSuccess(wantsAutopay);
                    }
                })
                .catch(function () {
                    setLoading(false);
                    showError('An unexpected error occurred. Please try again.');
                });
            };

            if (wantsSaveCard && intentData) {
                // Re-fetch intent with save_card=true (and optionally enable_autopay)
                fetch('/customer/api/invoice-payment-intent.php', {
                    method : 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body   : JSON.stringify({ token: <?php echo json_encode($token); ?>, save_card: true, enable_autopay: wantsAutopay })
                })
                .then(function (r) { return r.json(); })
                .then(function (newData) {
                    if (newData.error) { setLoading(false); showError(newData.error); return; }
                    // Update elements with new client_secret
                    elements.fetchUpdates().then(doConfirm).catch(doConfirm);
                })
                .catch(function () { doConfirm(); }); // fallback: confirm without save
            } else {
                doConfirm();
            }
        };

        // ── Pay with saved card ───────────────────────────────────────────────
        window.submitSavedCardPayment = function () {
            if (!stripe || !intentData || !intentData.default_payment_method) return;
            clearError();
            setSavedCardLoading(true);

            var autopayCheck = document.getElementById('autopayCheck');
            var wantsAutopay = autopayCheck && autopayCheck.checked;

            // Server-side confirmation — avoids confirmCardPayment() incompatibility
            // with automatic_payment_methods PaymentIntents which detaches saved cards.
            fetch('/customer/api/confirm-saved-card.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body   : JSON.stringify({ token: <?php echo json_encode($token); ?> })
            })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                setSavedCardLoading(false);
                if (result.error) {
                    showError(result.error);
                    if (/declined|expired/i.test(result.error)) { showNewCardForm(); }
                } else if (result.status === 'succeeded') {
                    if (wantsAutopay) {
                        fetch('/customer/api/autopay-setup.php', {
                            method : 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body   : JSON.stringify({ token: <?php echo json_encode($token); ?>, action: 'enable_existing_card' })
                        }).then(function () { showSuccess(true); }).catch(function () { showSuccess(true); });
                    } else {
                        showSuccess(false);
                    }
                } else if (result.status === 'requires_action' && stripe) {
                    // 3DS challenge — hand off to Stripe.js
                    stripe.handleNextAction({ clientSecret: result.next_action_client_secret })
                    .then(function (r) {
                        if (r.error) { showError(r.error.message); }
                        else { showSuccess(wantsAutopay); }
                    });
                }
            })
            .catch(function () {
                setSavedCardLoading(false);
                showError('An unexpected error occurred. Please try again.');
            });
        };

        function showError(msg) {
            var el = document.getElementById('stripeError');
            el.textContent = msg;
            el.style.display = 'block';
        }
        function clearError() {
            var el = document.getElementById('stripeError');
            el.textContent = ''; el.style.display = 'none';
        }
        function setLoading(on) {
            document.getElementById('stripePay').disabled             = on;
            document.getElementById('stripePayLabel').style.display   = on ? 'none'   : 'inline';
            document.getElementById('stripePaySpinner').style.display = on ? 'inline' : 'none';
        }
        function setSavedCardLoading(on) {
            document.getElementById('savedCardPay').disabled              = on;
            document.getElementById('savedCardPayLabel').style.display    = on ? 'none'   : 'inline';
            document.getElementById('savedCardPaySpinner').style.display  = on ? 'inline' : 'none';
        }
        function showSuccess(autopayEnabled) {
            var extraMsg = autopayEnabled
                ? '<div style="margin-top:20px;background:#e8f3f0;border:1px solid #b8dbd0;border-radius:8px;padding:12px 16px;">'
                  + '<strong style="color:#1A5F4A;font-size:.92rem;">&#10003; Autopay enabled</strong>'
                  + '<p style="color:#4a6b5d;font-size:.83rem;margin:4px 0 0;">Future invoices will be charged to this card automatically.</p>'
                  + '</div>'
                : '';
            document.querySelector('#payModal .portal-modal').innerHTML = [
                '<div style="padding:48px 32px;text-align:center;">',
                '<div style="font-size:56px;color:#2D8659;line-height:1;">&#10003;</div>',
                '<h3 style="color:#0D3B2E;margin:16px 0 8px;font-family:Montserrat,sans-serif;">Payment Received!</h3>',
                '<p style="color:#4a6b5d;font-size:14px;">Thank you. Your payment is being processed and this invoice will be marked paid shortly.</p>',
                extraMsg,
                '<p style="color:#9ca3af;font-size:12px;margin-top:12px;">Refreshing in 3 seconds&hellip;</p>',
                '</div>'
            ].join('');
            var reloadUrl = location.href.replace(/[?&]payment=[^&]*/,'').replace(/[?&]autopay=[^&]*/,'');
            reloadUrl += (reloadUrl.indexOf('?') === -1 ? '?' : '&') + 'payment=success';
            if (autopayEnabled) reloadUrl += '&autopay=1';
            setTimeout(function () { location.href = reloadUrl; }, 3000);
        }

        <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
        // Show success message after redirect-based payment (e.g. 3DS)
        document.addEventListener('DOMContentLoaded', function () {
            var bar = document.createElement('div');
            bar.className = 'portal-paid-banner';
            <?php if (!empty($_GET['autopay'])): ?>
            bar.innerHTML = '<h3>&#10003; Payment received &amp; autopay enabled</h3><p>Future invoices will be charged to your card automatically &mdash; no action needed.</p>';
            <?php else: ?>
            bar.innerHTML = '<h3>&#10003; Payment Submitted</h3><p>Your invoice will be updated once the payment is confirmed.</p>';
            <?php endif; ?>
            var container = document.querySelector('.portal-container');
            container.insertBefore(bar, container.firstChild);
        });
        <?php endif; ?>

    }());
    </script>
    <?php endif; ?>

<?php endif; ?>

    <?php if ($showAutopayBanner): ?>
    <div style="max-width:680px;margin:0 auto 24px;background:#e8f3f0;border:1px solid #b8dbd0;border-radius:10px;padding:18px 22px;display:flex;align-items:center;gap:16px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#2D8659" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        <div style="flex:1;">
            <strong style="color:#1A5F4A;font-size:.95rem;">Never chase a payment link again</strong>
            <p style="margin:4px 0 0;font-size:.88rem;color:#4a6b5d;">Enable autopay and your card will be charged automatically when invoices are generated.</p>
        </div>
        <a href="<?php echo htmlspecialchars($autopayUrl); ?>"
           style="flex-shrink:0;background:#2D8659;color:#fff;padding:9px 18px;border-radius:6px;text-decoration:none;font-size:.88rem;font-weight:600;white-space:nowrap;">
            Set up autopay
        </a>
    </div>
    <?php endif; ?>

    <div class="portal-footer">
        &copy; <?php echo date('Y'); ?> Mowology Landscaping &mdash; mowology.ca
    </div>

</div>
</body>
</html>
