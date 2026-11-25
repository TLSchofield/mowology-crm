<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/service-before-after.php';

$dataFile = dirname(__DIR__) . '/includes/service-data/lawn-installation-vancouver.php';
if (!file_exists($dataFile)) {
    http_response_code(404);
    exit('Page not found.');
}
$service = require $dataFile;

// Real, manager-approved before/after pairs from the crew (see BeforeAfterService).
// Omitted entirely when there is nothing approved yet.
$ba = serviceBeforeAfterSection(['lawn', 'garden'], 'Recent Lawn Installations and Repairs');
if ($ba) {
    $service['proof_sections'][] = $ba;
}

if (defined('MARKETING_AUTOMATION_ENABLED') && MARKETING_AUTOMATION_ENABLED && isset($service['marketing'])) {
    $_SESSION['lead_source']  = $service['marketing']['source_tag'] ?? null;
    $_SESSION['utm_source']   = $service['marketing']['utm_source'] ?? null;
    $_SESSION['utm_medium']   = $service['marketing']['utm_medium'] ?? null;
    $_SESSION['utm_campaign'] = $service['marketing']['utm_campaign'] ?? null;
    $_SESSION['gsc_keywords'] = $service['marketing']['attribution']['gsc_keywords'] ?? [];
}

require dirname(__DIR__) . '/includes/service-template.php';
