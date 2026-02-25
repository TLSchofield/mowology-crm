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

$invoice    = null;
$lineItems  = [];

if (!$error) {
    $stmt = $db->prepare("
        SELECT
            i.*,
            c.company_name,
            c.billing_email,
            c.billing_phone,
            COALESCE(ct.first_name, dc.first_name) as contact_first,
            COALESCE(ct.last_name, dc.last_name)   as contact_last,
            COALESCE(ct.email, dc.email)            as contact_email,
            COALESCE(ct.phone, dc.phone)            as contact_phone,
            p.address   as property_address,
            p.city      as property_city,
            p.postal_code as property_postal
        FROM invoices i
        LEFT JOIN companies c  ON i.company_id = c.id
        LEFT JOIN contacts ct  ON c.primary_contact_id = ct.id
        LEFT JOIN contacts dc  ON i.contact_id = dc.id
        LEFT JOIN properties p ON i.property_id = p.id
        WHERE i.access_token = ?
          AND (i.token_expires_at IS NULL OR i.token_expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $error = 'This invoice link is invalid or has expired. Please contact Mowology at (778) 846-9273.';
    } else {
        // Mark as viewed if it was sent
        if ($invoice['status'] === 'sent') {
            $db->prepare("UPDATE invoices SET status = 'viewed' WHERE id = ?")->execute([$invoice['id']]);
            $invoice['status'] = 'viewed';
        }

        // Get line items
        $stmt = $db->prepare("SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order");
        $stmt->execute([$invoice['id']]);
        $lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$isPayable   = $invoice && in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue']);
$contactName = $invoice ? trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? '')) : '';
$displayName = $invoice ? ($invoice['company_name'] ?: $contactName ?: 'Valued Customer') : '';

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
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --green:  #2D8659;
            --dark:   #1A5F4A;
            --forest: #0D3B2E;
            --lime:   #7FD858;
            --light:  #E8F3F0;
            --orange: #e85d04;
            --red:    #dc2626;
        }
        body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f5f7f5; color: #1c2b24; min-height: 100vh; }

        /* ── Header ── */
        .inv-header { background: var(--forest); color: #fff; padding: 18px 24px; display: flex; align-items: center; gap: 14px; }
        .inv-logo { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; color: #fff; text-decoration: none; }
        .inv-logo span { color: var(--lime); }
        .inv-header-right { margin-left: auto; font-size: 13px; opacity: .75; }

        /* ── Layout ── */
        .inv-wrap { max-width: 760px; margin: 32px auto; padding: 0 16px 60px; }

        /* ── Error card ── */
        .inv-error { background: #fff; border-radius: 10px; padding: 48px 32px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .inv-error h2 { color: var(--red); margin-bottom: 12px; }
        .inv-error p { color: #555; }

        /* ── Invoice card ── */
        .inv-card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.08); overflow: hidden; margin-bottom: 20px; }
        .inv-card-header { background: var(--light); padding: 14px 20px; border-bottom: 1px solid #c8e2d8; font-weight: 600; color: var(--forest); font-size: 14px; }
        .inv-card-body { padding: 20px; }

        /* ── Invoice top ── */
        .inv-top { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.08); overflow: hidden; margin-bottom: 20px; }
        .inv-top-inner { padding: 24px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 20px; }
        .inv-number { font-size: 26px; font-weight: 700; color: var(--forest); }
        .inv-number small { display: block; font-size: 13px; font-weight: 400; color: #666; margin-bottom: 4px; }
        .inv-meta { text-align: right; font-size: 14px; color: #555; }
        .inv-meta strong { color: var(--forest); }
        .inv-meta div { margin-bottom: 4px; }

        /* ── Badge ── */
        .inv-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
        .badge-sent    { background: #dbeafe; color: #1d4ed8; }
        .badge-viewed  { background: #ede9fe; color: #6d28d9; }
        .badge-partial { background: #fef3c7; color: #92400e; }
        .badge-overdue { background: #fee2e2; color: #dc2626; }
        .badge-paid    { background: #d1fae5; color: #065f46; }
        .badge-draft   { background: #f3f4f6; color: #374151; }

        /* ── Bill To ── */
        .bill-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 500px) { .bill-grid { grid-template-columns: 1fr; } }
        .bill-section h3 { font-size: 11px; text-transform: uppercase; letter-spacing: .8px; color: #888; margin-bottom: 8px; }
        .bill-name { font-weight: 600; font-size: 16px; color: var(--forest); margin-bottom: 2px; }
        .bill-sub  { font-size: 14px; color: #555; line-height: 1.5; }

        /* ── Line items ── */
        .inv-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .inv-table th { text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: .6px; color: #888; border-bottom: 2px solid var(--light); }
        .inv-table th.right, .inv-table td.right { text-align: right; }
        .inv-table td { padding: 10px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        .inv-table tbody tr:last-child td { border-bottom: none; }

        /* ── Totals ── */
        .inv-totals { margin-top: 16px; border-top: 2px solid var(--light); padding-top: 12px; }
        .inv-total-row { display: flex; justify-content: space-between; font-size: 14px; padding: 4px 0; color: #555; }
        .inv-total-row.grand { font-size: 18px; font-weight: 700; color: var(--forest); padding-top: 8px; border-top: 1px solid var(--light); margin-top: 4px; }
        .inv-total-row.balance { font-size: 18px; font-weight: 700; color: var(--red); }
        .inv-amount { font-variant-numeric: tabular-nums; }

        /* ── Pay button ── */
        .inv-pay-bar { background: var(--green); border-radius: 10px; padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .inv-pay-bar .due-label { color: rgba(255,255,255,.8); font-size: 13px; }
        .inv-pay-bar .due-amount { color: #fff; font-size: 24px; font-weight: 700; }
        .inv-pay-btn { background: #fff; color: var(--dark); border: none; border-radius: 6px; padding: 12px 28px; font-size: 16px; font-weight: 700; cursor: pointer; white-space: nowrap; transition: background .15s; }
        .inv-pay-btn:hover { background: var(--light); }
        .inv-pay-btn:disabled { opacity: .6; cursor: default; }

        /* ── Paid banner ── */
        .inv-paid-banner { background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 10px; padding: 20px 24px; text-align: center; margin-bottom: 20px; }
        .inv-paid-banner h3 { color: #065f46; font-size: 20px; margin-bottom: 4px; }
        .inv-paid-banner p { color: #047857; font-size: 14px; }

        /* ── Modal ── */
        .inv-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; align-items: center; justify-content: center; padding: 16px; }
        .inv-overlay.open { display: flex; }
        .inv-modal { background: #fff; border-radius: 12px; width: 100%; max-width: 480px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
        .inv-modal-hdr { background: var(--forest); color: #fff; padding: 18px 20px; display: flex; justify-content: space-between; align-items: center; }
        .inv-modal-hdr h3 { font-size: 17px; }
        .inv-modal-close { background: none; border: none; color: #fff; font-size: 22px; cursor: pointer; line-height: 1; opacity: .7; }
        .inv-modal-close:hover { opacity: 1; }
        .inv-modal-body { padding: 24px; }
        .inv-modal-footer { padding: 16px 24px; border-top: 1px solid var(--light); display: flex; justify-content: flex-end; gap: 10px; }
        .btn-cancel { background: #f3f4f6; border: none; border-radius: 6px; padding: 10px 20px; font-size: 14px; cursor: pointer; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-pay { background: var(--green); border: none; border-radius: 6px; padding: 10px 24px; font-size: 15px; font-weight: 600; color: #fff; cursor: pointer; }
        .btn-pay:hover { background: var(--dark); }
        .btn-pay:disabled { opacity: .6; cursor: default; }

        /* ── Amount summary in modal ── */
        .modal-amount-row { background: var(--light); border-radius: 6px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-amount-row span { font-size: 13px; color: var(--forest); }
        .modal-amount-row strong { font-size: 22px; color: var(--forest); }

        /* ── Stripe states ── */
        #stripeLoading { text-align: center; padding: 30px 0; color: #555; font-size: 14px; }
        #payment-element { display: none; }
        .alert-danger { background: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px; padding: 10px 14px; color: #dc2626; font-size: 14px; margin-top: 12px; }

        /* ── Notes ── */
        .inv-notes { font-size: 14px; color: #555; white-space: pre-line; line-height: 1.6; }

        /* ── Footer ── */
        .inv-footer { text-align: center; font-size: 12px; color: #aaa; padding-top: 24px; }
    </style>
    <?php if ($isPayable): ?>
        <script src="https://js.stripe.com/v3/" defer></script>
    <?php endif; ?>
</head>
<body>

<header class="inv-header">
    <a class="inv-logo" href="https://mowology.ca">Mow<span>ology</span></a>
    <span class="inv-header-right">Landscaping &amp; Property Maintenance</span>
</header>

<main class="inv-wrap">

<?php if ($error): ?>
    <div class="inv-error">
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

    <!-- Invoice top bar -->
    <div class="inv-top">
        <div class="inv-top-inner">
            <div>
                <div class="inv-number">
                    <small>Invoice Number</small>
                    <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                </div>
                <div style="margin-top:8px;">
                    <span class="inv-badge badge-<?php echo htmlspecialchars($invoice['status']); ?>">
                        <?php echo htmlspecialchars($statusLabel); ?>
                    </span>
                </div>
            </div>
            <div class="inv-meta">
                <div><strong>Issue Date:</strong> <?php echo fmtDate($invoice['issue_date'] ?? $invoice['invoice_date'] ?? ''); ?></div>
                <div><strong>Due Date:</strong> <?php echo fmtDate($invoice['due_date'] ?? ''); ?></div>
            </div>
        </div>
    </div>

    <?php if ($invoice['status'] === 'paid'): ?>
        <div class="inv-paid-banner">
            <h3>&#10003; Invoice Paid</h3>
            <p>Thank you! This invoice has been paid in full.</p>
        </div>
    <?php elseif ($isPayable): ?>
        <!-- Pay now bar -->
        <div class="inv-pay-bar">
            <div>
                <div class="due-label">Amount Due</div>
                <div class="due-amount"><?php echo fmt(floatval($invoice['balance_due'])); ?> CAD</div>
            </div>
            <button class="inv-pay-btn" onclick="openPayModal()">Pay Online Now</button>
        </div>
    <?php endif; ?>

    <!-- Bill To / From -->
    <div class="inv-card">
        <div class="inv-card-header">Billing Information</div>
        <div class="inv-card-body">
            <div class="bill-grid">
                <div class="bill-section">
                    <h3>Bill To</h3>
                    <?php if ($invoice['company_name']): ?>
                        <div class="bill-name"><?php echo htmlspecialchars($invoice['company_name']); ?></div>
                    <?php endif; ?>
                    <?php if ($contactName): ?>
                        <div class="bill-name"><?php echo htmlspecialchars($contactName); ?></div>
                    <?php endif; ?>
                    <?php
                    $email       = $invoice['contact_email'] ?: $invoice['billing_email'] ?: '';
                    $phone       = $invoice['contact_phone'] ?: $invoice['billing_phone'] ?: '';
                    $billAddr    = trim($invoice['billing_address'] ?? '');
                    $billCity    = trim($invoice['billing_city'] ?? '');
                    $billProv    = trim($invoice['billing_province'] ?? '');
                    $billPostal  = trim($invoice['billing_postal_code'] ?? '');
                    $billAddrLine = trim($billAddr . ($billCity ? ', ' . $billCity : '') . ($billProv ? ' ' . $billProv : '') . ($billPostal ? ' ' . $billPostal : ''));
                    ?>
                    <?php if ($billAddrLine): ?>
                        <div class="bill-sub"><?php echo htmlspecialchars($billAddrLine); ?></div>
                    <?php endif; ?>
                    <?php if ($email): ?>
                        <div class="bill-sub"><?php echo htmlspecialchars($email); ?></div>
                    <?php endif; ?>
                    <?php if ($phone): ?>
                        <div class="bill-sub"><?php echo htmlspecialchars($phone); ?></div>
                    <?php endif; ?>
                </div>
                <div class="bill-section">
                    <h3>From</h3>
                    <div class="bill-name">Mowology Landscaping</div>
                    <div class="bill-sub">
                        (778) 846-9273<br>
                        hello@mowology.ca<br>
                        mowology.ca
                    </div>
                    <?php if (!empty($invoice['property_address'])): ?>
                        <div style="margin-top:10px;">
                            <h3 style="margin-top:0;margin-bottom:6px;">Service Address</h3>
                            <div class="bill-sub">
                                <?php echo htmlspecialchars($invoice['property_address']); ?><br>
                                <?php echo htmlspecialchars($invoice['property_city']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Line Items -->
    <div class="inv-card">
        <div class="inv-card-header">Services</div>
        <div class="inv-card-body">
            <table class="inv-table">
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
                            <td class="right inv-amount"><?php echo fmt(floatval($item['unit_price'])); ?></td>
                            <td class="right inv-amount"><?php echo fmt(floatval($item['line_total'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="inv-totals">
                <div class="inv-total-row">
                    <span>Subtotal</span>
                    <span class="inv-amount"><?php echo fmt(floatval($invoice['subtotal'])); ?></span>
                </div>
                <div class="inv-total-row">
                    <span>GST (<?php echo round(($invoice['tax_rate'] ?: 0.05) * 100); ?>%)</span>
                    <span class="inv-amount"><?php echo fmt(floatval($invoice['tax_amount'] ?? 0)); ?></span>
                </div>
                <div class="inv-total-row grand">
                    <span>Total</span>
                    <span class="inv-amount"><?php echo fmt(floatval($invoice['total'] ?: $invoice['total_amount'] ?? 0)); ?></span>
                </div>
                <?php if (floatval($invoice['amount_paid'] ?? 0) > 0): ?>
                    <div class="inv-total-row" style="color: var(--green);">
                        <span>Paid</span>
                        <span class="inv-amount">-<?php echo fmt(floatval($invoice['amount_paid'])); ?></span>
                    </div>
                    <div class="inv-total-row balance">
                        <span>Balance Due</span>
                        <span class="inv-amount"><?php echo fmt(floatval($invoice['balance_due'])); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($invoice['notes'])): ?>
        <div class="inv-card">
            <div class="inv-card-header">Notes</div>
            <div class="inv-card-body">
                <div class="inv-notes"><?php echo htmlspecialchars($invoice['notes']); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Questions -->
    <div class="inv-card">
        <div class="inv-card-header">Questions?</div>
        <div class="inv-card-body" style="font-size:14px;color:#555;">
            <p>Please contact us at <a href="tel:+17788469273" style="color:var(--green);">(778) 846-9273</a> or
            <a href="mailto:hello@mowology.ca" style="color:var(--green);">hello@mowology.ca</a>.
            We're happy to help.</p>
        </div>
    </div>

    <!-- Payment modal -->
    <?php if ($isPayable): ?>
    <div id="payModal" class="inv-overlay" role="dialog" aria-modal="true" aria-labelledby="payModalTitle">
        <div class="inv-modal">
            <div class="inv-modal-hdr">
                <h3 id="payModalTitle">Pay Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></h3>
                <button class="inv-modal-close" onclick="closePayModal()" aria-label="Close">&times;</button>
            </div>
            <div class="inv-modal-body">
                <div class="modal-amount-row">
                    <span>Amount Due</span>
                    <strong><?php echo fmt(floatval($invoice['balance_due'])); ?> CAD</strong>
                </div>
                <div id="stripeLoading">
                    <span>Loading secure payment form&hellip;</span>
                </div>
                <div id="payment-element"></div>
                <div id="stripeError" class="alert-danger" style="display:none;"></div>
            </div>
            <div class="inv-modal-footer" id="stripeFooter" style="display:none;">
                <button class="btn-cancel" onclick="closePayModal()">Cancel</button>
                <button id="stripePay" class="btn-pay" onclick="submitPayment()" disabled>
                    <span id="stripePayLabel">Pay <?php echo fmt(floatval($invoice['balance_due'])); ?></span>
                    <span id="stripePaySpinner" style="display:none;">Processing&hellip;</span>
                </button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var stripe = null, elements = null, paymentEl = null, fetched = false;

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
                body   : JSON.stringify({ token: <?php echo json_encode($token); ?> })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    showError(data.error);
                    document.getElementById('stripeLoading').style.display = 'none';
                    return;
                }
                fetched = true;
                stripe  = Stripe(data.publishable_key);
                elements = stripe.elements({
                    clientSecret: data.client_secret,
                    appearance  : {
                        theme    : 'stripe',
                        variables: {
                            colorPrimary   : '#2D8659',
                            colorText      : '#1A5F4A',
                            borderRadius   : '6px',
                            fontFamily     : 'system-ui, -apple-system, sans-serif',
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
                });
                paymentEl.on('change', function (e) {
                    if (e.error) showError(e.error.message); else clearError();
                });
            })
            .catch(function (err) {
                console.error(err);
                showError('Unable to load payment form. Please refresh and try again.');
                document.getElementById('stripeLoading').style.display = 'none';
            });
        }

        window.submitPayment = function () {
            if (!stripe || !elements) return;
            clearError();
            setLoading(true);
            stripe.confirmPayment({
                elements      : elements,
                confirmParams : {
                    return_url: window.location.href + '&payment=success'
                },
                redirect: 'if_required'
            })
            .then(function (result) {
                setLoading(false);
                if (result.error) {
                    showError(result.error.message);
                } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                    showSuccess();
                }
            })
            .catch(function () {
                setLoading(false);
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
            document.getElementById('stripePay').disabled               = on;
            document.getElementById('stripePayLabel').style.display     = on ? 'none'   : 'inline';
            document.getElementById('stripePaySpinner').style.display   = on ? 'inline' : 'none';
        }
        function showSuccess() {
            document.querySelector('#payModal .inv-modal').innerHTML = [
                '<div style="padding:48px 32px;text-align:center;">',
                '<div style="font-size:56px;color:#2D8659;line-height:1;">&#10003;</div>',
                '<h3 style="color:#0D3B2E;margin:16px 0 8px;">Payment Received!</h3>',
                '<p style="color:#555;font-size:14px;">Thank you. Your payment is being processed and this invoice will be marked paid shortly.</p>',
                '<p style="color:#aaa;font-size:12px;margin-top:12px;">Refreshing in 3 seconds&hellip;</p>',
                '</div>'
            ].join('');
            setTimeout(function () { location.reload(); }, 3000);
        }

        <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
        // Show success message after redirect-based payment (e.g. 3DS)
        document.addEventListener('DOMContentLoaded', function () {
            var bar = document.createElement('div');
            bar.style.cssText = 'background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:16px 20px;margin-bottom:20px;color:#065f46;font-size:15px;';
            bar.textContent = '✓ Payment submitted! Your invoice will be updated once confirmed.';
            document.querySelector('.inv-wrap').insertBefore(bar, document.querySelector('.inv-wrap').firstChild);
        });
        <?php endif; ?>

    }());
    </script>
    <?php endif; ?>

<?php endif; ?>

    <div class="inv-footer">
        &copy; <?php echo date('Y'); ?> Mowology Landscaping &mdash; mowology.ca
    </div>

</main>
</body>
</html>
