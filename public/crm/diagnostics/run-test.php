<?php
/**
 * Messaging Diagnostics — POST Handler
 *
 * Processes diagnostic test submissions from the wizard UI.
 * Validates CSRF, runs the requested test, saves results, and redirects back.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/smtp_mailer.php';
require_once dirname(__DIR__) . '/includes/sms_gateway.php';
require_once __DIR__ . '/functions.php';

requireLogin();
$user = getCurrentUser();

// Admin only
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Access denied — admin only.');
}

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// CSRF validation
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('CSRF token invalid. Please go back and try again.');
}

$testType = $_POST['test_type'] ?? '';
$result   = null;

// Rate limiting — 30-second cooldown per test type
if ($testType && isTestRateLimited($testType, 30)) {
    $_SESSION['diagnostic_result'] = [
        'test_type'  => $testType,
        'result'     => [
            'status'      => 'error',
            'message'     => 'Rate limited — please wait 30 seconds between tests.',
            'details'     => [],
            'duration_ms' => 0,
            'error'       => 'Rate limited'
        ],
        'timestamp'  => date('Y-m-d H:i:s')
    ];

    $stepMap = [
        'mail_function'  => 'step1',
        'phpmailer'      => 'step2',
        'sms_gateway'    => 'step3',
        'consent_check'  => 'step4',
        'full_pipeline'  => 'step5',
    ];
    header('Location: index.php?step=' . ($stepMap[$testType] ?? 'overview'));
    exit;
}

// Dispatch test
switch ($testType) {
    case 'mail_function':
        $email  = trim($_POST['email'] ?? '');
        $result = runMailFunctionTest($email);
        break;

    case 'phpmailer':
        $email  = trim($_POST['email'] ?? '');
        $result = runPhpMailerTest($email);
        break;

    case 'sms_gateway':
        $phone   = trim($_POST['phone'] ?? '');
        $carrier = $_POST['carrier'] ?? null;
        if ($carrier === 'all') {
            $carrier = null;
        }
        $result = runSmsGatewayTest($phone, $carrier);
        break;

    case 'consent_check':
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $result    = runConsentVerificationTest($contactId);
        break;

    case 'full_pipeline':
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $contactId = !empty($_POST['contact_id']) ? (int) $_POST['contact_id'] : null;
        $result    = runFullPipelineTest($email, $phone, $contactId);
        break;

    default:
        $result = [
            'status'      => 'error',
            'message'     => 'Unknown test type: ' . htmlspecialchars($testType),
            'details'     => [],
            'duration_ms' => 0,
            'error'       => 'Invalid test_type'
        ];
        break;
}

// Save result to database
if ($result) {
    saveTestResult($testType, $result, (int) $user['id']);
}

// Flash result to session for display after redirect
$_SESSION['diagnostic_result'] = [
    'test_type'  => $testType,
    'result'     => $result,
    'timestamp'  => date('Y-m-d H:i:s')
];

// Redirect back to the appropriate tab
$stepMap = [
    'mail_function'  => 'step1',
    'phpmailer'      => 'step2',
    'sms_gateway'    => 'step3',
    'consent_check'  => 'step4',
    'full_pipeline'  => 'step5',
];

header('Location: index.php?step=' . ($stepMap[$testType] ?? 'overview'));
exit;
