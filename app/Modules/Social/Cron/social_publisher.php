<?php
/**
 * Cron: Social Post Publisher
 *
 * Picks due items from social_queue, publishes to each platform,
 * handles retries with exponential backoff.
 *
 * Run every 5 minutes:
 *   * /5 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Social/Cron/social_publisher.php
 *
 * Also callable via web POST (admin only):
 *   POST /crm/cron/social_publisher.php
 *
 * Processes up to 10 posts per run to prevent shared-hosting timeout.
 * Applies a process-level lock to prevent overlapping runs.
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Social/Services/SocialEncryption.php';
require_once APP_ROOT . '/Modules/Social/Services/GoogleBusinessService.php';
require_once APP_ROOT . '/Modules/Social/Services/MetaService.php';
require_once APP_ROOT . '/Modules/Social/Services/SocialPublisher.php';

$isCli      = php_sapi_name() === 'cli';
$fromWeb    = !$isCli;
$startMs    = (int) round(microtime(true) * 1000);
$cronStatus = 'success';
$cronError  = null;

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
    // Release session lock immediately — this job can take a while
    session_write_close();
}

$batchSize  = 10;
$lockerId   = (string)getmypid() . '@' . gethostname();

try {
    $db = getDB();

    // ── Verify tables exist ─────────────────────────────────────────
    try {
        $db->query("SELECT 1 FROM social_queue LIMIT 0");
    } catch (\Exception $e) {
        $msg = 'social_queue table missing. Run migration 605 first.';
        if ($isCli) { echo "SKIP: $msg\n"; exit(0); }
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    // ── Release stale locks (items locked > 5 minutes ago) ─────────
    $db->prepare("
        UPDATE social_queue
        SET locked_at  = NULL,
            locked_by  = NULL,
            status     = 'pending'
        WHERE status     = 'processing'
          AND locked_at  < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ")->execute();

    // ── Auto-enqueue approved/scheduled posts missing queue entries ──
    // Catches posts saved before the enqueue fix, or approved via admin.
    $db->prepare("
        INSERT INTO social_queue (post_id, account_id, platform, scheduled_at, status)
        SELECT spp.post_id, spp.account_id, spp.platform,
               COALESCE(sp.scheduled_at, NOW()), 'pending'
        FROM social_post_platforms spp
        JOIN social_posts sp ON sp.id = spp.post_id
        WHERE sp.status IN ('approved', 'scheduled')
          AND spp.status = 'pending'
          AND NOT EXISTS (
              SELECT 1 FROM social_queue sq
              WHERE sq.post_id = spp.post_id
                AND sq.platform = spp.platform
                AND sq.status IN ('pending', 'processing', 'completed')
          )
    ")->execute();

    // ── Fetch due items ─────────────────────────────────────────────
    // Items due now, not locked, pending status
    $stmt = $db->prepare("
        SELECT sq.*
        FROM social_queue sq
        JOIN social_accounts sa ON sa.id = sq.account_id AND sa.is_active = 1
        WHERE sq.status       = 'pending'
          AND sq.scheduled_at <= NOW()
          AND sq.locked_at    IS NULL
          AND sq.attempts     < sq.max_attempts
        ORDER BY sq.scheduled_at ASC
        LIMIT ?
    ");
    $stmt->execute([$batchSize]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        $msg = 'No posts due for publishing.';
        if ($isCli) { echo "$msg\n"; exit(0); }
        echo json_encode(['success' => true, 'message' => $msg, 'processed' => 0]);
        exit;
    }

    if ($isCli) {
        echo 'Found ' . count($items) . " items to publish.\n";
    }

    $published = 0;
    $failed    = 0;
    $results   = [];

    foreach ($items as $item) {
        $queueId = (int)$item['id'];

        // ── Lock the item ─────────────────────────────────────────
        $locked = $db->prepare("
            UPDATE social_queue
            SET status    = 'processing',
                locked_at = NOW(),
                locked_by = ?,
                attempts  = attempts + 1
            WHERE id     = ?
              AND status  = 'pending'
              AND locked_at IS NULL
        ");
        $locked->execute([$lockerId, $queueId]);

        if ($locked->rowCount() === 0) {
            // Another worker grabbed it
            continue;
        }

        // Mark post as 'publishing' if not already published
        $db->prepare("
            UPDATE social_posts
            SET status = 'publishing'
            WHERE id = ? AND status IN ('scheduled', 'approved')
        ")->execute([$item['post_id']]);

        // ── Process ───────────────────────────────────────────────
        $result = SocialPublisher::processQueueItem($item);

        if ($result['success']) {
            $published++;
        } else {
            $failed++;
        }

        $results[] = [
            'queue_id' => $queueId,
            'post_id'  => $item['post_id'],
            'platform' => $item['platform'],
            'success'  => $result['success'],
            'message'  => $result['message'],
        ];

        if ($isCli) {
            $icon = $result['success'] ? '✓' : '✗';
            echo "  $icon Post #{$item['post_id']} → {$item['platform']}: {$result['message']}\n";
        }

        // Brief pause between API calls
        usleep(500000); // 500ms
    }

    // ── Summary ─────────────────────────────────────────────────────
    $summary = [
        'success'   => true,
        'message'   => "Published: $published, Failed: $failed, Total: " . count($items),
        'published' => $published,
        'failed'    => $failed,
        'results'   => $results,
    ];

    $durationMs = (int) round(microtime(true) * 1000) - $startMs;
    recordCronRun(
        'social_publisher',
        $cronStatus,
        "Published: {$published}, Failed: {$failed}, Total: " . count($items),
        $durationMs,
        $cronError,
        $fromWeb
    );

    if ($isCli) {
        echo "\n" . $summary['message'] . "\n";
    } else {
        echo json_encode($summary);
    }

} catch (\Throwable $e) {
    $error = 'social_publisher error: ' . $e->getMessage();
    error_log($error);
    $durationMs = (int) round(microtime(true) * 1000) - $startMs;
    recordCronRun('social_publisher', 'error', null, $durationMs, $e->getMessage(), $fromWeb);
    if ($isCli) { echo "ERROR: $error\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => $error]);
}
