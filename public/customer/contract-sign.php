<?php
/**
 * Customer Contract Signing Page
 * Public page — no login required, token-based access.
 */

header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/app_config/config.php';

// ── Load ContractService via upward search ─────────────────────────────────
$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once APP_ROOT . '/Modules/Contracts/Services/ContractService.php';

$db    = getDB();
$svc   = new ContractService($db);
$error = '';

// ── Resolve the signature request ─────────────────────────────────────────
$token = trim($_GET['token'] ?? '');
$row   = null;

if (empty($token)) {
    $error = 'Invalid or missing contract link.';
} else {
    $row = $svc->getSignatureRequest($token);
    if (!$row) {
        $error = 'This contract link has expired or is invalid. Please contact us for a new link.';
    }
}

// ── Handle POST (sign / decline) ──────────────────────────────────────────
$success = '';
$action  = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
    if ($action === 'sign') {
        $signerName    = trim($_POST['signer_name'] ?? '');
        $signatureData = trim($_POST['signature_data'] ?? '');

        if (empty($signerName)) {
            $error = 'Please enter your full name.';
        } elseif (empty($signatureData) || $signatureData === 'data:,') {
            $error = 'Please draw your signature in the box above.';
        } else {
            $signerIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $saved    = $svc->recordSignature($token, $signatureData, $signerIp);
            if ($saved) {
                $success = 'Contract signed successfully. We will be in touch to confirm your service schedule.';
                $row     = null; // don't re-show the form
            } else {
                $error = 'Could not save signature. The link may have already been used.';
            }
        }
    } elseif ($action === 'decline') {
        $reason   = trim($_POST['decline_reason'] ?? 'No reason given');
        $signerIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $svc->declineSignature($token, $reason, $signerIp);
        $success = 'We have recorded your response. If you have questions, call us at (778) 846-9273.';
        $row     = null;
    }
}

$clientName = '';
if ($row) {
    $clientName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))
               ?: $row['signer_name']
               ?: '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Contract — Mowology</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/customer/portal.css">
    <script src="https://unpkg.com/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <style>
        .ctr-sign-meta { font-size:.82rem; color:var(--p-text-mid,#6b7280); margin-bottom:6px; }
        .ctr-sign-meta strong { color:var(--p-text,#1f2937); }
        .ctr-billing-row { display:flex; justify-content:space-between; padding:6px 0;
            border-bottom:1px solid var(--p-border,#e5e7eb); font-size:.88rem; }
        .ctr-billing-row:last-child { border-bottom:none; }
        .ctr-billing-label { color:var(--p-text-mid,#6b7280); }
        .ctr-billing-value { font-weight:600; color:var(--p-text,#1f2937); }
        .ctr-sig-note { font-size:.78rem; color:var(--p-text-mid,#6b7280); margin-top:6px; }
    </style>
</head>
<body>

<header class="portal-header">
    <img src="/assets/img/logo/mowology-logo.jpg" alt="Mowology" class="portal-logo-img">
    <div class="portal-header-divider"></div>
    <span class="portal-header-label">Service Contract</span>
    <?php if ($clientName): ?>
        <span class="portal-client-name"><?php echo htmlspecialchars($clientName); ?></span>
    <?php endif; ?>
</header>

<div class="portal-container">

    <?php if ($success): ?>
        <!-- ── Success state ───────────────────────────────────────────────── -->
        <div style="text-align:center;padding:40px 24px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 24 24"
                 fill="none" stroke="#2D8659" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                 style="margin:0 auto 20px;display:block;">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="7 12 10 15 17 9"/>
            </svg>
            <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin-bottom:10px;">Thank You!</h2>
            <p style="color:#6b7280;line-height:1.6;max-width:360px;margin:0 auto;">
                <?php echo htmlspecialchars($success); ?>
            </p>
            <p style="margin-top:20px;font-size:.85rem;color:#6b7280;">
                Questions? Call <a href="tel:7788469273">(778) 846-9273</a>
            </p>
        </div>

    <?php elseif ($error && !$row): ?>
        <!-- ── Error / invalid token ───────────────────────────────────────── -->
        <div class="portal-error">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                 stroke-linejoin="round" style="color:#cdddd6;margin:0 auto 16px;display:block;">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <h2>Link Not Valid</h2>
            <p><?php echo htmlspecialchars($error); ?></p>
            <p style="margin-top:12px;">Contact us at <a href="tel:7788469273">(778) 846-9273</a></p>
        </div>

    <?php elseif ($row): ?>
        <!-- ── Contract details + signature form ──────────────────────────── -->

        <?php if ($error): ?>
            <div class="portal-error" style="margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:.88rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Contract summary card -->
        <div class="portal-info-card">
            <div class="portal-info-card-header">
                <?php echo htmlspecialchars($row['contract_number']); ?>
                <?php if ($row['title']): ?>
                    &mdash; <?php echo htmlspecialchars($row['title']); ?>
                <?php endif; ?>
            </div>
            <div class="portal-info-card-body">

                <div class="ctr-sign-meta" style="margin-bottom:14px;">
                    <strong>Property:</strong>
                    <?php echo htmlspecialchars($row['property_address']); ?>,
                    <?php echo htmlspecialchars($row['property_city']); ?>,
                    <?php echo htmlspecialchars($row['property_province']); ?>
                    <?php echo htmlspecialchars($row['property_postal']); ?>
                </div>

                <?php
                $billingCycleLabels = [
                    'monthly'   => 'Monthly',
                    'per_visit' => 'Per Visit',
                    'seasonal'  => 'Seasonal',
                    'annual'    => 'Annual',
                    'custom'    => 'Custom',
                ];
                $cycle = $billingCycleLabels[$row['billing_cycle'] ?? ''] ?? ucfirst($row['billing_cycle'] ?? '');
                ?>

                <?php if ($row['billing_amount']): ?>
                    <div class="ctr-billing-row">
                        <span class="ctr-billing-label">Service Value</span>
                        <span class="ctr-billing-value">
                            $<?php echo number_format((float)$row['billing_amount'], 2); ?>
                            <?php if ($cycle): ?>
                                <small style="font-weight:400;color:#6b7280;"> / <?php echo htmlspecialchars($cycle); ?></small>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ($row['start_date']): ?>
                    <div class="ctr-billing-row">
                        <span class="ctr-billing-label">Start Date</span>
                        <span class="ctr-billing-value"><?php echo date('F j, Y', strtotime($row['start_date'])); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($row['end_date']): ?>
                    <div class="ctr-billing-row">
                        <span class="ctr-billing-label">End Date</span>
                        <span class="ctr-billing-value"><?php echo date('F j, Y', strtotime($row['end_date'])); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($row['auto_renew'] ?? 0): ?>
                    <div class="ctr-billing-row">
                        <span class="ctr-billing-label">Auto-Renew</span>
                        <span class="ctr-billing-value" style="color:#2D8659;">Enabled<?php echo (($row['renewal_increase_pct'] ?? 0) > 0) ? ' (+' . number_format((float)$row['renewal_increase_pct'], 1) . '% annual)' : ''; ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($row['notes']): ?>
                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--p-border,#e5e7eb);
                                font-size:.86rem;color:#6b7280;line-height:1.55;white-space:pre-line;">
                        <?php echo htmlspecialchars($row['notes']); ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Signature form -->
        <div class="portal-info-card">
            <div class="portal-info-card-header">Sign This Contract</div>
            <div class="portal-info-card-body">
                <p style="font-size:.875rem;color:#6b7280;margin-bottom:20px;line-height:1.55;">
                    By signing below you agree to the terms of this service contract with Mowology Landscaping.
                    Your digital signature is legally binding.
                </p>

                <form method="POST" id="signForm">
                    <input type="hidden" name="action" value="sign">
                    <input type="hidden" name="signature_data" id="signatureData">

                    <div class="portal-form-group">
                        <label class="portal-form-label">Full Name *</label>
                        <input type="text" name="signer_name" class="portal-form-input" required
                               placeholder="Type your full name to confirm"
                               value="<?php echo htmlspecialchars($row['signer_name'] ?? ''); ?>">
                    </div>

                    <div class="portal-form-group">
                        <label class="portal-form-label">Your Signature *</label>
                        <div class="portal-sig-wrap">
                            <canvas id="signaturePad" class="portal-sig-canvas"></canvas>
                            <div class="portal-sig-placeholder" id="sigPlaceholder">Sign here with mouse or finger</div>
                            <div class="portal-sig-actions">
                                <button type="button" class="portal-sig-clear" id="clearSignature">Clear</button>
                            </div>
                        </div>
                        <p class="ctr-sig-note">
                            Link valid until <?php echo date('F j, Y', strtotime($row['token_expires_at'])); ?>.
                        </p>
                    </div>

                    <button type="submit" class="portal-btn wide" id="submitBtn">
                        ✓ Sign Contract
                    </button>
                </form>

                <!-- Decline section -->
                <div class="portal-decline-section" style="margin-top:24px;">
                    <button class="portal-decline-toggle"
                            onclick="document.getElementById('declineForm').classList.toggle('show')">
                        Questions or want to decline? Let us know
                    </button>
                    <form method="POST" class="portal-decline-form" id="declineForm">
                        <input type="hidden" name="action" value="decline">
                        <textarea name="decline_reason" class="portal-form-input" rows="3"
                                  placeholder="Optional: tell us why…"
                                  style="width:100%;resize:vertical;"></textarea>
                        <button type="submit" class="portal-btn" style="background:#6b7280;margin-top:8px;">
                            Decline This Contract
                        </button>
                        <p style="font-size:.78rem;color:#9ca3af;margin-top:8px;">
                            Or call us: <a href="tel:7788469273">(778) 846-9273</a>
                        </p>
                    </form>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div><!-- /portal-container -->

<footer style="text-align:center;padding:24px;font-size:.78rem;color:#9ca3af;">
    &copy; <?php echo date('Y'); ?> Mowology Landscaping &mdash; (778) 846-9273
</footer>

<script>
(function () {
    var canvas = document.getElementById('signaturePad');
    if (!canvas) return;

    var placeholder = document.getElementById('sigPlaceholder');
    var pad = new SignaturePad(canvas, { backgroundColor: 'rgb(255,255,255)' });

    function resizeCanvas() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var data  = pad.toData();
        canvas.width  = canvas.offsetWidth  * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        pad.clear();
        if (data.length) pad.fromData(data);
    }

    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    pad.addEventListener('beginStroke', function () {
        if (placeholder) placeholder.style.display = 'none';
    });

    document.getElementById('clearSignature').addEventListener('click', function () {
        pad.clear();
        if (placeholder) placeholder.style.display = 'block';
    });

    document.getElementById('signForm').addEventListener('submit', function (e) {
        if (pad.isEmpty()) {
            e.preventDefault();
            alert('Please draw your signature before submitting.');
            return;
        }
        document.getElementById('signatureData').value = pad.toDataURL('image/png');
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('submitBtn').textContent = 'Submitting…';
    });
}());
</script>

</body>
</html>
