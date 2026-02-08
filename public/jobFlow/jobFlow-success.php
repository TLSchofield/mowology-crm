<?php
declare(strict_types=1);

// Canonical location: /public/jobFlow/
// dirname(__DIR__) = /public/
require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You - Mowology Ltd.</title>
    <link rel="stylesheet" href="/assets/css/master.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="jobflow-page success-page">
    <div class="container">
        <div class="success-card">
            <div class="success-icon">&#x2705;</div>
            <h1>Thank You!</h1>
            <p>Your quote request has been received. We'll review your information and get back to you within 24 hours.</p>

            <div class="info-box">
                <h2>What Happens Next?</h2>
                <div class="info-row">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    <span>We'll review your property details and service needs</span>
                </div>
                <div class="info-row">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <span>You'll receive a detailed quote via your preferred contact method</span>
                </div>
                <div class="info-row">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>We'll discuss pricing, scheduling, and answer any questions</span>
                </div>
            </div>

            <div class="contact-box">
                <h3>Need Immediate Assistance?</h3>
                <p>Feel free to call us directly:</p>
                <div class="phone">(778) 846-9273</div>
            </div>

            <div>
                <a href="/" class="btn">Return to Homepage</a>
                <a href="jobFlow-getQuote.php" class="btn btn-outline">Submit Another Request</a>
            </div>

            <div class="success-footer">
                <strong>Mowology Ltd.</strong>
                Professional Landscaping & Snow Removal<br>
                Vancouver, BC<br><br>
                <small>&copy; <?php echo date('Y'); ?> Mowology Ltd. All rights reserved.</small>
            </div>
        </div>
    </div>
</body>
</html>
