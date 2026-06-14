<?php
/**
 * Contract Signatures API
 *
 * GET  ?action=status&contract_id=X  — signature status + history
 * POST action=send                   — send contract for signature
 * POST action=resend                 — revoke pending + send new request
 * POST action=revoke                 — revoke pending request (unsigned)
 *
 * All writes require CSRF token.
 * Requires CRM login + jobs.view permission.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once PUBLIC_ROOT . '/crm/includes/functions.php';
require_once APP_ROOT . '/Modules/Contracts/Services/ContractService.php';

requireLogin();
requirePermission('jobs.view');
$user = getCurrentUser();
session_write_close();

$db     = getDB();
$svc    = new ContractService($db);
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ── GET: status ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
    $contractId = (int) ($_GET['contract_id'] ?? 0);
    if (!$contractId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'contract_id required']);
        exit;
    }

    $active  = $svc->getActiveSignatureRequest($contractId);
    $history = $svc->getSignatureHistory($contractId);
    $versions = $svc->getVersions($contractId);

    echo json_encode([
        'success'  => true,
        'active'   => $active,
        'history'  => $history,
        'versions' => $versions,
    ]);
    exit;
}

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (!verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$contractId = (int) ($input['contract_id'] ?? 0);
if (!$contractId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'contract_id required']);
    exit;
}

try {
    if ($action === 'send' || $action === 'resend') {
        $signerName  = trim($input['signer_name']  ?? '');
        $signerEmail = trim($input['signer_email'] ?? '');

        if (!$signerName || !$signerEmail) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'signer_name and signer_email are required']);
            exit;
        }
        if (!filter_var($signerEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid email address']);
            exit;
        }

        if ($action === 'resend') {
            $svc->revokeSignatureRequest($contractId);
        }

        $signToken = $svc->sendForSignature($contractId, $signerName, $signerEmail, (int) $user['id']);

        // Build the signing URL for the email
        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'mowology.ca';
        $signUrl = "{$scheme}://{$host}/customer/contract-sign.php?token={$signToken}";

        // Send email via CRM messaging system
        if (function_exists('sendCrmEmail')) {
            $contract = getContractById($contractId);
            $subject  = "Your Service Contract — Please Sign";
            $body     = "Hi {$signerName},\n\n"
                . "Please review and digitally sign your service contract with Mowology Landscaping.\n\n"
                . "Contract: " . ($contract['contract_number'] ?? '') . "\n"
                . ($contract['title'] ? "Title: " . $contract['title'] . "\n" : '')
                . "\nSign here (link valid for 14 days):\n{$signUrl}\n\n"
                . "If you have any questions, call us at (778) 846-9273.\n\n"
                . "Thank you,\nMowology Landscaping Team";
            sendCrmEmail($signerEmail, $subject, $body, $signerName);
        }

        echo json_encode([
            'success'   => true,
            'sign_url'  => $signUrl,
            'message'   => "Signature request sent to {$signerEmail}.",
        ]);

    } elseif ($action === 'revoke') {
        $svc->revokeSignatureRequest($contractId);
        echo json_encode(['success' => true, 'message' => 'Signature request revoked.']);

    } elseif ($action === 'amend') {
        $reason = trim($input['reason'] ?? 'Contract amended');
        $svc->amendContract($contractId, (int) $user['id'], $reason);
        echo json_encode([
            'success' => true,
            'message' => 'Contract versioned. Signature status reset — resend for signature when ready.',
        ]);

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (\RuntimeException $e) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error — please try again']);
}
