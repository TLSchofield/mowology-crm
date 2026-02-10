<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

// Load service data with marketing integration
$dataFile = dirname(__DIR__) . '/includes/service-data/professional-lawn-mowing-care.php';

// Check if file exists, provide helpful error if missing
if (!file_exists($dataFile)) {
    die('
        <div style="padding: 40px; text-align: center; font-family: sans-serif;">
            <h2>Landing Page Not Yet Deployed</h2>
            <p>The landing page files need to be deployed from GitHub.</p>
            <p>This page will be available after the next deployment.</p>
            <p><a href="/">Return to home page</a></p>
        </div>
    ');
}

$service = require $dataFile;

// If marketing automation is enabled, log the page view
if (defined('MARKETING_AUTOMATION_ENABLED') && MARKETING_AUTOMATION_ENABLED) {
    // Track page view with UTM parameters for analytics
    // This integrates with the automated marketing suite
    if (isset($service['marketing'])) {
        // Store in session for lead capture if user requests quote
        $_SESSION['lead_source'] = $service['marketing']['source_tag'] ?? null;
        $_SESSION['utm_source'] = $service['marketing']['utm_source'] ?? null;
        $_SESSION['utm_medium'] = $service['marketing']['utm_medium'] ?? null;
        $_SESSION['utm_campaign'] = $service['marketing']['utm_campaign'] ?? null;
        $_SESSION['gsc_keywords'] = $service['marketing']['attribution']['gsc_keywords'] ?? [];
    }
}

// Render using the service template system
require dirname(__DIR__) . '/includes/service-template.php';
