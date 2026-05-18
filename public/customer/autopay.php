<?php
/**
 * Customer Autopay Enrollment
 * Token-based (no login required) — token from invoice access_token.
 * URL: /customer/autopay.php?token=ABC123
 *
 * Two flows:
 *  1. Card already on file: show card + enable autopay button (no new card entry)
 *  2. No card: SetupIntent flow to save card without charging
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once dirname(__DIR__) . '/app_config/config.php';
require_once dirname(__DIR__) . '/app_config/secrets.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

$db    = getDB();
$error = '';
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    $error = 'Invalid or missing link.';
}

$contact = null;
$invoice = null;

if (!$error) {
    $stmt = $db->prepare("
        SELECT
            i.id            AS invoice_id,
            i.access_token,
            ct.id           AS contact_id,
            ct.first_name,
            ct.last_name,
            ct.email,
            ct.stripe_customer_id,
            ct.stripe_payment_method_id,
            ct.stripe_card_brand,
            ct.stripe_card_last4,
            ct.stripe_card_exp,
            ct.autopay_enabled,
            ct.autopay_enrolled_at
        FROM invoices i
        JOIN contacts ct ON i.contact_id = ct.id
        WHERE i.access_token = ?
          AND (i.token_expires_at IS NULL OR i.token_expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $error = 'This link is invalid or has expired. Please contact Mowology at (778) 846-9273.';
    } else {
        $contact = $row;
        $invoice = ['id' => $row['invoice_id'], 'access_token' => $row['access_token']];
    }
}

$hasSavedCard  = $contact && !empty($contact['stripe_card_last4']);
$isEnrolled    = $contact && !empty($contact['autopay_enrolled_at']);
$firstName     = $contact ? htmlspecialchars($contact['first_name'] ?? 'there') : 'there';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autopay Setup | Mowology</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/customer/portal.css">
    <?php if (!$error && !$isEnrolled): ?>
        <script src="https://js.stripe.com/v3/" defer></script>
    <?php endif; ?>
    <style>
        .autopay-card {
            background:#fff;
            border-radius:12px;
            box-shadow:0 2px 16px rgba(0,0,0,.08);
            padding:32px;
            max-width:520px;
            margin:0 auto 24px;
        }
        .autopay-card h2 { margin:0 0 8px; font-size:1.3rem; color:#1A5F4A; }
        .autopay-card p  { margin:0 0 20px; color:#555; line-height:1.6; }
        .saved-card-box {
            display:flex;align-items:center;gap:14px;
            background:#f0f7f4;border:1px solid #c3ddd5;
            border-radius:8px;padding:14px 18px;margin-bottom:20px;
        }
        .saved-card-box .card-icon { font-size:1.5rem; }
        .saved-card-box .card-info { flex:1; font-size:.95rem; }
        .saved-card-box .card-info strong { display:block; }
        .consent-block {
            background:#f8f9fa;border-radius:8px;
            padding:14px 16px;margin-bottom:20px;
            font-size:.88rem;color:#555;line-height:1.5;
        }
        .consent-block label { display:flex;gap:10px;cursor:pointer; }
        .consent-block input[type=checkbox] { margin-top:2px;flex-shrink:0;width:16px;height:16px; }
        .btn-autopay {
            display:block;width:100%;
            background:#2D8659;color:#fff;border:none;
            padding:14px;font-size:1rem;font-weight:600;
            border-radius:8px;cursor:pointer;transition:background .2s;
        }
        .btn-autopay:hover:not(:disabled) { background:#1A5F4A; }
        .btn-autopay:disabled { background:#aaa;cursor:not-allowed; }
        .enrolled-badge {
            display:inline-flex;align-items:center;gap:8px;
            background:#e8f3f0;color:#1A5F4A;border-radius:20px;
            padding:6px 16px;font-weight:600;font-size:.95rem;margin-bottom:16px;
        }
        .inline-error {
            background:#fff0f0;border:1px solid #f5c0c0;color:#b00;
            border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:.9rem;
        }
        #stripe-element-wrapper { margin-bottom:20px; }
        #stripe-payment-element { min-height:60px; }
    </style>
</head>
<body>

<header class="portal-header">
    <?php if ($contact && !empty($contact['contact_id'])): ?>
        <a href="/customer/portal.php?contact_id=<?php echo (int)$contact['contact_id']; ?>" class="portal-back-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            Portal
        </a>
    <?php endif; ?>
    <div class="portal-logo-text">Mowo<span>logy</span></div>
    <div class="portal-header-divider"></div>
    <span class="portal-header-label">Autopay Setup</span>
</header>

<div class="portal-container">

<?php if ($error): ?>
    <div class="portal-error">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:#cdddd6;margin:0 auto 16px;display:block"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <h2>Link Not Found</h2>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>

<?php elseif ($isEnrolled): ?>
    <div class="autopay-card" style="text-align:center;">
        <div class="enrolled-badge">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Autopay Active
        </div>
        <h2>You&rsquo;re all set, <?php echo $firstName; ?>!</h2>
        <p>Autopay is active on your account. Future invoices will be charged automatically — no action needed.</p>
        <?php if ($hasSavedCard): ?>
        <div class="saved-card-box">
            <span class="card-icon">💳</span>
            <div class="card-info">
                <strong><?php echo htmlspecialchars(ucfirst($contact['stripe_card_brand'] ?? 'Card')); ?> ••••<?php echo htmlspecialchars($contact['stripe_card_last4']); ?></strong>
                Expires <?php echo htmlspecialchars($contact['stripe_card_exp'] ?? ''); ?>
            </div>
        </div>
        <?php endif; ?>
        <a href="/customer/invoice.php?token=<?php echo urlencode($token); ?>" style="color:#2D8659;">← Back to invoice</a>
    </div>

<?php else: ?>

    <div class="autopay-card">
        <h2>Set up autopay</h2>
        <p>Hi <?php echo $firstName; ?>, enable autopay and your card will be charged automatically whenever an invoice is generated for your service plan — no more payment links to track down.</p>

        <div id="autopay-error" class="inline-error" style="display:none;"></div>

        <?php if ($hasSavedCard): ?>
            <!-- Card already on file — just needs consent -->
            <div class="saved-card-box">
                <span class="card-icon">💳</span>
                <div class="card-info">
                    <strong><?php echo htmlspecialchars(ucfirst($contact['stripe_card_brand'] ?? 'Card')); ?> ••••<?php echo htmlspecialchars($contact['stripe_card_last4']); ?></strong>
                    Expires <?php echo htmlspecialchars($contact['stripe_card_exp'] ?? ''); ?>
                </div>
            </div>
            <p style="font-size:.9rem;color:#777;margin-top:-10px;">We&rsquo;ll use this card for autopay. You can update it next time you make a payment.</p>
        <?php else: ?>
            <!-- No card on file — collect via SetupIntent -->
            <p style="font-size:.9rem;color:#777;margin-bottom:16px;">Enter a card to save securely. You won&rsquo;t be charged today.</p>
            <div id="stripe-element-wrapper">
                <div id="stripe-payment-element"></div>
            </div>
        <?php endif; ?>

        <div class="consent-block">
            <label>
                <input type="checkbox" id="consent-check">
                <span>I authorise Mowology Landscaping to charge my card on file for future invoices on my approved service plan(s). I understand I can cancel this at any time by contacting Mowology.</span>
            </label>
        </div>

        <button class="btn-autopay" id="autopay-btn" disabled>Enable Autopay</button>

        <p style="text-align:center;margin-top:16px;font-size:.82rem;color:#aaa;">
            🔒 Payments secured by <strong>Stripe</strong>. Mowology never stores your full card number.
        </p>
    </div>

    <p style="text-align:center;margin-top:0;">
        <a href="/customer/invoice.php?token=<?php echo urlencode($token); ?>" style="color:#2D8659;font-size:.9rem;">← Back to invoice</a>
    </p>

    <script>
    (function () {
        var hasSavedCard = <?php echo $hasSavedCard ? 'true' : 'false'; ?>;
        var token        = <?php echo json_encode($token); ?>;
        var consentCheck = document.getElementById('consent-check');
        var autopayBtn   = document.getElementById('autopay-btn');
        var errorBox     = document.getElementById('autopay-error');
        var stripe, elements, paymentElement;

        function showError(msg) {
            errorBox.textContent = msg;
            errorBox.style.display = 'block';
            autopayBtn.disabled = false;
            autopayBtn.textContent = 'Enable Autopay';
        }

        function hideError() {
            errorBox.style.display = 'none';
        }

        // Enable button only when consent is checked (and, for new-card flow, Stripe is ready)
        var stripeReady = hasSavedCard; // saved-card flow is always ready; new-card waits for Stripe mount

        consentCheck.addEventListener('change', function () {
            autopayBtn.disabled = !(consentCheck.checked && stripeReady);
        });

        if (!hasSavedCard) {
            // Wait for Stripe.js to load, then mount the SetupIntent element
            window.addEventListener('load', function () {
                if (typeof Stripe === 'undefined') {
                    showError('Could not load payment form. Please refresh the page.');
                    return;
                }
                stripe = Stripe(<?php echo json_encode(STRIPE_PUBLISHABLE_KEY); ?>);

                // Fetch SetupIntent client_secret from the server
                fetch('/customer/api/autopay-setup.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: token, action: 'create_setup_intent' })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) { showError(data.error); return; }

                    elements = stripe.elements({ clientSecret: data.client_secret, appearance: { theme: 'stripe' } });
                    paymentElement = elements.create('payment');
                    paymentElement.mount('#stripe-payment-element');
                    paymentElement.on('ready', function () {
                        stripeReady = true;
                        autopayBtn.disabled = !consentCheck.checked;
                    });
                })
                .catch(function () { showError('Could not load payment form. Please try again.'); });
            });
        }

        autopayBtn.addEventListener('click', function () {
            if (!consentCheck.checked) return;
            hideError();
            autopayBtn.disabled = true;
            autopayBtn.textContent = 'Setting up...';

            if (hasSavedCard) {
                // Existing card — just record consent server-side
                fetch('/customer/api/autopay-setup.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: token, action: 'enable_existing_card' })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) { showError(data.error); return; }
                    window.location.reload();
                })
                .catch(function () { showError('Something went wrong. Please try again or call (778) 846-9273.'); });

            } else {
                // New card — confirm SetupIntent via Stripe.js
                stripe.confirmSetup({
                    elements: elements,
                    confirmParams: {
                        return_url: window.location.href + '&enrolled=1'
                    },
                    redirect: 'if_required'
                })
                .then(function (result) {
                    if (result.error) {
                        showError(result.error.message);
                    } else {
                        // SetupIntent succeeded — webhook will update DB.
                        // Redirect to enrolled state.
                        window.location.reload();
                    }
                });
            }
        });
    })();
    </script>

<?php endif; ?>

</div><!-- .portal-container -->

<footer class="portal-footer">
    <p>Questions? Call <a href="tel:+17788469273">(778) 846-9273</a> or email <a href="mailto:office@mowology.ca">office@mowology.ca</a></p>
    <p>&copy; <?php echo date('Y'); ?> Mowology Landscaping</p>
</footer>

</body>
</html>
