<?php
/**
 * Cron: Opt-In "Oops" Resend Sender
 *
 * One-off recovery campaign. The original marketing opt-in email shipped
 * with a broken confirmation link; this resends everyone a fresh email
 * with a working /optin-confirm.php link and a freshly minted token.
 *
 * Rides the existing campaign infrastructure (marketing_campaigns +
 * campaign_sends) but generates a unique token per recipient, which the
 * generic campaign_sender cannot do — so it has its own sender.
 *
 * STATUS-DRIVEN SAFETY GATE:
 *   - no campaign yet      → builds it in 'scheduled' status, sends NOTHING
 *   - status = 'scheduled' → DRY PREVIEW: reports counts, sends NOTHING
 *   - status = 'sending'   → sends, batches of 25, ONLY 10:00–14:00 server time
 *   - status = 'completed' → no-op
 *
 * Going live is a deliberate manual flip: 'scheduled' -> 'sending'.
 *
 * Cron (cPanel — restrict to the window at the cron level too):
 *   0,15,30,45 10-13 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/optin_resend_sender.php
 *
 * Also callable via admin web POST: POST /crm/cron/optin_resend_sender.php
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
require_once APP_ROOT . '/Services/Messaging/TemplateRenderer.php';
require_once APP_ROOT . '/Modules/Marketing/Services/OptinResendService.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }
    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
}

$BATCH_SIZE = 25;

/** Emit a result in the right shape for CLI vs web and exit. */
$emit = static function (array $payload) use ($isCli): void {
    if ($isCli) {
        echo ($payload['message'] ?? json_encode($payload)) . "\n";
        exit(empty($payload['success']) ? 1 : 0);
    }
    echo json_encode($payload);
    exit;
};

try {
    $db = getDB();

    try {
        $db->query("SELECT 1 FROM marketing_campaigns LIMIT 0");
        $db->query("SELECT 1 FROM marketing_optin_tokens LIMIT 0");
    } catch (\Exception $e) {
        $emit(['success' => false, 'message' => 'SKIP: required tables missing (run migrations 604 + 700).']);
    }

    $svc = new OptinResendService($db);

    // ── Ensure the campaign exists (parked in 'scheduled') ──────────────
    $campaign = $svc->findCampaign();
    if (!$campaign) {
        $built = $svc->buildCampaign();
        $emit([
            'success'  => true,
            'mode'     => 'built',
            'message'  => "Built opt-in resend campaign #{$built['campaign_id']} in 'scheduled' (dry-run) status with {$built['queued']} recipients queued. NOTHING SENT. Flip status to 'sending' to go live.",
            'campaign_id' => $built['campaign_id'],
            'queued'   => $built['queued'],
        ]);
    }

    $campaignId = (int)$campaign['id'];
    $status     = (string)$campaign['status'];

    $pending = (int)$db->query(
        "SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = " . $campaignId . " AND status = 'pending'"
    )->fetchColumn();
    $sentSoFar = (int)$db->query(
        "SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = " . $campaignId . " AND status = 'sent'"
    )->fetchColumn();

    // ── 'scheduled' = DRY PREVIEW, never sends ──────────────────────────
    if ($status === 'scheduled' || $status === 'draft' || $status === 'queued') {
        $emit([
            'success'   => true,
            'mode'      => 'preview',
            'message'   => "PREVIEW (status='{$status}', dry-run): campaign #{$campaignId} has {$pending} recipients pending, {$sentSoFar} already sent. NOTHING SENT. Flip status to 'sending' to begin.",
            'campaign_id' => $campaignId,
            'pending'   => $pending,
            'sent'      => $sentSoFar,
        ]);
    }

    if ($status === 'completed') {
        $emit([
            'success'   => true,
            'mode'      => 'done',
            'message'   => "Campaign #{$campaignId} already completed ({$sentSoFar} sent).",
        ]);
    }

    if ($status === 'paused' || $status === 'cancelled') {
        $emit([
            'success'   => true,
            'mode'      => 'inactive',
            'message'   => "Campaign #{$campaignId} is '{$status}' — not sending. {$pending} still pending.",
        ]);
    }

    // ── status === 'sending' : window-gated batch send ──────────────────
    $now = new \DateTimeImmutable('now');
    if (!$svc->isWithinSendWindow($now)) {
        $emit([
            'success'   => true,
            'mode'      => 'outside-window',
            'message'   => "Outside send window (" . OptinResendService::WINDOW_START_HOUR . ":00–" . OptinResendService::WINDOW_END_HOUR . ":00). Now " . $now->format('H:i') . ". {$pending} pending — will resume next window.",
        ]);
    }

    $result = $svc->processBatch($campaignId, $BATCH_SIZE, static function (string $to, string $subject, string $html, array $extraHeaders = []): array {
        return sendEmail($to, $subject, $html, null, 'Mowology', $extraHeaders);
    });

    $emit([
        'success'   => true,
        'mode'      => 'send',
        'message'   => "Opt-in resend: {$result['sent']} sent, {$result['failed']} failed, {$result['suppressed']} suppressed, {$result['remaining']} remaining" . ($result['completed'] ? ' — CAMPAIGN COMPLETE.' : '.'),
        'campaign_id' => $campaignId,
        'sent'      => $result['sent'],
        'failed'    => $result['failed'],
        'suppressed' => $result['suppressed'],
        'remaining' => $result['remaining'],
        'completed' => $result['completed'],
    ]);

} catch (\Throwable $e) {
    $err = 'optin_resend_sender error: ' . $e->getMessage();
    error_log($err);
    if (php_sapi_name() === 'cli') { echo "ERROR: $err\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => $err]);
}
