<?php
/**
 * jobFlow — Step 3: Success Page
 *
 * Phase 1: Session gate — requires quote_submitted flag set by confirm.php.
 *          Direct URL access is rejected and redirected to Step 1.
 * Phase 2: SMS opt-in reminder, referral incentive, next steps.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Session gate ────────────────────────────────────────────────────────────
// Must have been redirected here by confirm.php after a successful DB write.
if (empty($_SESSION['quote_submitted'])) {
    header('Location: jobFlow-getQuote.php');
    exit();
}

// ── Read one-time session data ──────────────────────────────────────────────
$submittedName = (string)($_SESSION['submitted_name'] ?? '');
$hasSmsConsent = !empty($_SESSION['submitted_sms']);
$firstName     = explode(' ', trim($submittedName))[0] ?? '';

// Clear success flag so refresh sends them back to start (intentional)
unset($_SESSION['quote_submitted']);
unset($_SESSION['submitted_name']);
unset($_SESSION['submitted_sms']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You — Mowology</title>
    <link rel="stylesheet" href="/assets/css/master.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="jobflow-page success-page">
<div class="container">
    <div class="success-card">

        <div class="success-icon">&#x2705;</div>

        <h1>
            <?php if ($firstName !== ''): ?>
                Thank you, <?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>!
            <?php else: ?>
                Thank You!
            <?php endif; ?>
        </h1>

        <p class="success-lead">Your quote request has been received. We'll review your property details and get back to you within 24 hours — usually much sooner.</p>

        <!-- Next steps -->
        <div class="info-box">
            <h2>What Happens Next?</h2>
            <div class="info-row">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
                <div>
                    <strong>We review your details</strong>
                    Our team looks over your property needs and prepares a tailored quote.
                </div>
            </div>
            <div class="info-row">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <div>
                    <strong>You receive your quote</strong>
                    We'll contact you via your preferred method with a detailed, transparent price.
                </div>
            </div>
            <div class="info-row">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <div>
                    <strong>We schedule your service</strong>
                    Once you're happy with the quote, we book a time that works for you — no pressure.
                </div>
            </div>
        </div>

        <!-- SMS opt-in reminder (only if they didn't already consent) -->
        <?php if (!$hasSmsConsent): ?>
        <div class="sms-nudge">
            <p><strong>&#x1F4F1; Want faster updates?</strong> When you're ready, reply to our message and ask us to add you to SMS scheduling updates. It's the fastest way to confirm your appointment and get crew arrival notices.</p>
        </div>
        <?php endif; ?>

        <!-- Referral incentive -->
        <div class="referral-box">
            <h3>&#x1F91D; Refer a Neighbour, Earn a Credit</h3>
            <p>Know someone who needs landscaping or snow removal? Refer them and we'll apply a <strong>$25 credit</strong> to your account when they complete their first service. Just mention your name when they call or fill out the form.</p>
        </div>

        <!-- Contact box -->
        <div class="contact-box">
            <h3>Need Immediate Assistance?</h3>
            <p>Call us directly:</p>
            <div class="phone">(778) 846-9273</div>
        </div>

        <!-- Actions -->
        <div class="success-actions">
            <a href="/" class="btn">Return to Homepage</a>
            <a href="jobFlow-getQuote.php" class="btn btn-outline">Submit Another Request</a>
        </div>

        <div class="success-footer">
            <strong>Mowology Ltd.</strong><br>
            Professional Landscaping &amp; Snow Removal — Vancouver, BC<br>
            <small>&copy; <?php echo date('Y'); ?> Mowology Ltd. All rights reserved.</small>
        </div>

    </div>
</div>
</body>
</html>
