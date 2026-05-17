<?php
/**
 * SocialPublisher — Core publishing orchestrator.
 *
 * Dispatches individual queue items to the correct platform service.
 * Called by the cron worker (social_publisher.php).
 *
 * Flow per queue item:
 *   1. Load post data + account data
 *   2. Load attached media URLs
 *   3. Ensure token is fresh
 *   4. Dispatch to platform service
 *   5. Mark queue item completed or schedule retry
 *   6. Update social_post_platforms row
 *   7. Check if ALL platforms for the post are done → update social_posts.status
 *   8. Write audit log
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class SocialPublisher
{
    /** Retry delay (seconds) per attempt number: 1h, 4h, 24h */
    private const RETRY_DELAYS = [3600, 14400, 86400];

    /**
     * Process a single social_queue row.
     * Returns ['success' => bool, 'message' => string]
     */
    public static function processQueueItem(array $queueItem): array
    {
        $db     = getDB();
        $postId = (int)$queueItem['post_id'];
        $queueId = (int)$queueItem['id'];

        try {
            // ── Load post ────────────────────────────────────────
            $stmt = $db->prepare("SELECT * FROM social_posts WHERE id = ?");
            $stmt->execute([$postId]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$post) {
                throw new RuntimeException("Post #$postId not found");
            }

            // ── Load account ─────────────────────────────────────
            $stmt = $db->prepare("SELECT * FROM social_accounts WHERE id = ? AND is_active = 1");
            $stmt->execute([$queueItem['account_id']]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                throw new RuntimeException("Account #{$queueItem['account_id']} not found or inactive");
            }

            // ── Load media URLs ──────────────────────────────────
            $mediaUrls = self::getMediaUrls($postId);

            // ── Dispatch to platform ─────────────────────────────
            $platform = $queueItem['platform'];
            $result = self::dispatchToPlatform($platform, $account, $post, $mediaUrls);

            // ── Mark completed ───────────────────────────────────
            self::markQueueCompleted($queueId, $result);
            self::updatePostPlatformRow($postId, (int)$queueItem['account_id'], $platform, $result);

            // ── Check if all platforms done ──────────────────────
            self::syncPostStatus($postId);

            // ── Audit ────────────────────────────────────────────
            GoogleBusinessService::auditLog(
                $post['created_by'],
                'post_published',
                'post',
                $postId,
                "Published to $platform. Platform post ID: " . ($result['post_id'] ?? 'unknown')
            );

            return ['success' => true, 'message' => "Published post #$postId to $platform"];

        } catch (\Throwable $e) {
            $reason = $e->getMessage();
            error_log("SocialPublisher: Post #$postId to {$queueItem['platform']} failed: $reason");

            $attempt  = (int)$queueItem['attempts'] + 1;
            $maxAttempts = (int)$queueItem['max_attempts'];

            self::markQueueFailed($queueId, $reason, $attempt, $maxAttempts);
            self::updatePostPlatformRow(
                $postId,
                (int)$queueItem['account_id'],
                $queueItem['platform'],
                ['success' => false, 'error' => $reason],
                $attempt
            );

            if ($attempt >= $maxAttempts) {
                self::syncPostStatus($postId);
                GoogleBusinessService::auditLog(
                    null,
                    'post_failed',
                    'post',
                    $postId,
                    "Max retries reached on {$queueItem['platform']}: $reason"
                );
            }

            return ['success' => false, 'message' => $reason];
        }
    }

    // ── Platform dispatch ────────────────────────────────────────────

    private static function dispatchToPlatform(
        string $platform,
        array $account,
        array $post,
        array $mediaUrls
    ): array {
        switch ($platform) {
            case 'gbp':
                return GoogleBusinessService::createPost($account, $post, $mediaUrls);

            case 'facebook':
                return MetaService::publishToFacebook($account, $post, $mediaUrls);

            case 'instagram':
                return MetaService::publishToInstagram($account, $post, $mediaUrls);

            default:
                throw new RuntimeException("Unknown platform: $platform");
        }
    }

    // ── Media URLs ───────────────────────────────────────────────────

    /**
     * Get public HTTPS URLs for all media attached to a post.
     * Media must be in /_media/ and publicly accessible.
     */
    private static function getMediaUrls(int $postId): array
    {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT ma.file_path, ma.mime_type
            FROM social_post_media spm
            JOIN media_assets ma ON ma.id = spm.media_id
            WHERE spm.post_id = ?
            ORDER BY spm.sort_order ASC
            LIMIT 10
        ");
        $stmt->execute([$postId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $baseUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://mowology.ca';
        $urls = [];

        foreach ($rows as $row) {
            if (!empty($row['file_path'])) {
                // Ensure path starts with /
                $path = '/' . ltrim($row['file_path'], '/');
                $urls[] = $baseUrl . $path;
            }
        }

        return $urls;
    }

    // ── Queue management ─────────────────────────────────────────────

    private static function markQueueCompleted(int $queueId, array $result): void
    {
        $db = getDB();
        $db->prepare("
            UPDATE social_queue
            SET status         = 'completed',
                locked_at      = NULL,
                locked_by      = NULL,
                result_payload = ?
            WHERE id = ?
        ")->execute([json_encode($result), $queueId]);
    }

    private static function markQueueFailed(
        int $queueId,
        string $reason,
        int $attempt,
        int $maxAttempts
    ): void {
        $db = getDB();

        if ($attempt >= $maxAttempts) {
            $status = 'failed';
            $nextAttempt = null;
        } else {
            $status = 'pending';
            $delaySecs = self::RETRY_DELAYS[min($attempt - 1, count(self::RETRY_DELAYS) - 1)];
            $nextAttempt = date('Y-m-d H:i:s', time() + $delaySecs);
        }

        $db->prepare("
            UPDATE social_queue
            SET status          = ?,
                attempts        = ?,
                next_attempt_at = ?,
                locked_at       = NULL,
                locked_by       = NULL,
                scheduled_at    = COALESCE(?, scheduled_at),
                result_payload  = ?
            WHERE id = ?
        ")->execute([
            $status,
            $attempt,
            $nextAttempt,
            $nextAttempt,
            json_encode(['error' => $reason, 'attempt' => $attempt]),
            $queueId,
        ]);
    }

    // ── Post platform row ────────────────────────────────────────────

    private static function updatePostPlatformRow(
        int $postId,
        int $accountId,
        string $platform,
        array $result,
        int $retryCount = 0
    ): void {
        $db = getDB();

        if ($result['success'] ?? false) {
            $db->prepare("
                UPDATE social_post_platforms
                SET status           = 'published',
                    platform_post_id = ?,
                    platform_url     = ?,
                    response_payload = ?,
                    published_at     = NOW(),
                    fail_reason      = NULL
                WHERE post_id = ? AND account_id = ? AND platform = ?
            ")->execute([
                $result['post_id'] ?? null,
                $result['url']     ?? null,
                json_encode($result['response'] ?? $result),
                $postId, $accountId, $platform,
            ]);
        } else {
            $db->prepare("
                UPDATE social_post_platforms
                SET status      = IF(retry_count >= 2, 'failed', 'pending'),
                    fail_reason = ?,
                    retry_count = retry_count + 1
                WHERE post_id = ? AND account_id = ? AND platform = ?
            ")->execute([
                substr($result['error'] ?? 'Unknown error', 0, 500),
                $postId, $accountId, $platform,
            ]);
        }
    }

    // ── Post status sync ─────────────────────────────────────────────

    /**
     * After all queue items for a post are resolved, update social_posts.status.
     * Rules:
     *   - All platforms published → 'published'
     *   - Any published, some failed → 'published' (partial success)
     *   - All failed → 'failed'
     */
    private static function syncPostStatus(int $postId): void
    {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'published') AS published_count,
                SUM(status = 'failed')    AS failed_count,
                SUM(status = 'pending')   AS pending_count
            FROM social_post_platforms
            WHERE post_id = ?
        ");
        $stmt->execute([$postId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$counts || $counts['total'] == 0) {
            return;
        }

        // Still pending items → stay in current status
        if ($counts['pending_count'] > 0) {
            return;
        }

        if ($counts['published_count'] > 0) {
            $db->prepare("
                UPDATE social_posts
                SET status       = 'published',
                    published_at = COALESCE(published_at, NOW())
                WHERE id = ?
            ")->execute([$postId]);
        } else {
            // All failed — pull the most recent fail reason from platforms into the post row
            // so the dashboard can surface it without joining social_post_platforms
            $reasonStmt = $db->prepare("
                SELECT fail_reason FROM social_post_platforms
                WHERE post_id = ? AND fail_reason IS NOT NULL
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            $reasonStmt->execute([$postId]);
            $failReason = $reasonStmt->fetchColumn();

            $db->prepare("
                UPDATE social_posts
                SET status          = 'failed',
                    fail_count      = fail_count + 1,
                    last_fail_reason = ?
                WHERE id = ?
            ")->execute([$failReason ?: 'Unknown error', $postId]);
        }
    }

    // ── Enqueue helper (called when scheduling a post) ───────────────

    /**
     * Add queue entries for all platforms selected for a post.
     * Called when a post transitions to 'scheduled' status.
     */
    public static function enqueuePost(int $postId, string $scheduledAt): void
    {
        $db = getDB();

        // Get all platforms for this post
        $stmt = $db->prepare("SELECT * FROM social_post_platforms WHERE post_id = ?");
        $stmt->execute([$postId]);
        $platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($platforms as $pp) {
            // Check if already queued
            $existing = $db->prepare("
                SELECT id FROM social_queue
                WHERE post_id = ? AND account_id = ? AND platform = ?
                  AND status IN ('pending','processing')
            ");
            $existing->execute([$postId, $pp['account_id'], $pp['platform']]);
            if ($existing->fetch()) {
                continue; // Already queued
            }

            $db->prepare("
                INSERT INTO social_queue (post_id, account_id, platform, scheduled_at)
                VALUES (?, ?, ?, ?)
            ")->execute([$postId, $pp['account_id'], $pp['platform'], $scheduledAt]);
        }

        // Update post status to 'scheduled'
        $db->prepare("
            UPDATE social_posts
            SET status = 'scheduled', scheduled_at = ?
            WHERE id = ? AND status IN ('approved','draft','pending_approval')
        ")->execute([$scheduledAt, $postId]);
    }
}
