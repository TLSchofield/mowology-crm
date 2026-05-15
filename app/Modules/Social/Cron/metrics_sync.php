<?php
/**
 * Cron: Social Metrics Sync
 *
 * Fetches daily engagement metrics from Meta Graph API for all published
 * Facebook and Instagram posts from the last 30 days and upserts them
 * into social_metrics_daily.
 *
 * Non-fatal per row — API errors are logged and skipped, not aborted.
 *
 * Run once daily at 3 AM (metrics lag ~12 hours on Meta):
 *   0 3 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Social/Cron/metrics_sync.php
 *
 * Also callable via web POST (admin only):
 *   POST /crm/cron/social_metrics_sync.php
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
require_once APP_ROOT . '/Modules/Social/Services/MetaService.php';

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
    session_write_close();
}

$batchSize = 50;

try {
    $db = getDB();

    // ── Verify tables exist ─────────────────────────────────────────
    try {
        $db->query("SELECT 1 FROM social_metrics_daily LIMIT 0");
    } catch (\Exception $e) {
        $msg = 'social_metrics_daily table missing. Run migration 605 first.';
        if ($isCli) { echo "SKIP: $msg\n"; exit(0); }
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    // ── Find published Meta posts needing today's sync ──────────────
    // Fetches posts published within the last 30 days that do not yet
    // have a metrics row for today (or whose row was last fetched > 20h ago).
    $stmt = $db->prepare("
        SELECT spp.id         AS post_platform_id,
               spp.platform_post_id,
               spp.platform,
               spp.account_id,
               sa.access_token_enc,
               sa.meta_json
        FROM social_post_platforms spp
        JOIN social_accounts sa ON sa.id = spp.account_id AND sa.is_active = 1
        WHERE spp.platform        IN ('facebook', 'instagram')
          AND spp.status          = 'published'
          AND spp.platform_post_id IS NOT NULL
          AND spp.published_at    >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          AND spp.id NOT IN (
              SELECT post_platform_id
              FROM social_metrics_daily
              WHERE metric_date = CURDATE()
                AND fetched_at  >= DATE_SUB(NOW(), INTERVAL 20 HOUR)
          )
        ORDER BY spp.published_at DESC
        LIMIT ?
    ");
    $stmt->execute([$batchSize]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $msg = 'All metrics up to date.';
        if ($isCli) { echo "$msg\n"; exit(0); }
        echo json_encode(['success' => true, 'message' => $msg, 'synced' => 0, 'errors' => 0]);
        exit;
    }

    if ($isCli) {
        echo 'Found ' . count($rows) . " posts to sync.\n";
    }

    $synced      = 0;
    $errors      = 0;
    $accountsSynced = [];

    $upsert = $db->prepare("
        INSERT INTO social_metrics_daily
            (post_platform_id, metric_date, impressions, reach, clicks,
             likes, comments_count, shares, saves, fetched_at)
        VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            impressions    = VALUES(impressions),
            reach          = VALUES(reach),
            clicks         = VALUES(clicks),
            likes          = VALUES(likes),
            comments_count = VALUES(comments_count),
            shares         = VALUES(shares),
            saves          = VALUES(saves),
            fetched_at     = NOW()
    ");

    foreach ($rows as $row) {
        $postPlatformId = (int)$row['post_platform_id'];
        $platformPostId = $row['platform_post_id'];
        $platform       = $row['platform'];

        try {
            $pageToken = SocialEncryption::decrypt($row['access_token_enc'] ?? '');
            if (!$pageToken) {
                throw new RuntimeException('Could not decrypt page token for account ' . $row['account_id']);
            }

            if ($platform === 'facebook') {
                $metrics = MetaService::fetchFacebookMetrics($platformPostId, $pageToken);
            } else {
                $metrics = MetaService::fetchInstagramMetrics($platformPostId, $pageToken);
            }

            $upsert->execute([
                $postPlatformId,
                $metrics['impressions'],
                $metrics['reach'],
                $metrics['clicks'],
                $metrics['likes'],
                $metrics['comments_count'],
                $metrics['shares'],
                $metrics['saves'],
            ]);

            $accountsSynced[$row['account_id']] = true;
            $synced++;

            if ($isCli) {
                echo "  [{$platform}] post_platform_id={$postPlatformId}: impressions={$metrics['impressions']}, reach={$metrics['reach']}\n";
            }
        } catch (\Throwable $e) {
            $errors++;
            error_log("metrics_sync: failed post_platform_id={$postPlatformId} [{$platform}]: " . $e->getMessage());
            if ($isCli) {
                echo "  ERROR [{$platform}] post_platform_id={$postPlatformId}: " . $e->getMessage() . "\n";
            }
        }
    }

    // ── Update last_sync_at for processed accounts ──────────────────
    if (!empty($accountsSynced)) {
        $ids          = array_keys($accountsSynced);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE social_accounts SET last_sync_at = NOW() WHERE id IN ({$placeholders})")
           ->execute($ids);
    }

    $summary = "Synced {$synced} rows, {$errors} errors.";
    if ($isCli) {
        echo $summary . "\n";
        exit(0);
    }

    echo json_encode([
        'success' => true,
        'message' => $summary,
        'synced'  => $synced,
        'errors'  => $errors,
    ]);

} catch (\Throwable $e) {
    $errMsg = 'metrics_sync fatal error: ' . $e->getMessage();
    error_log($errMsg);
    if ($isCli) { echo "FATAL: $errMsg\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => $errMsg]);
}
