<?php
declare(strict_types=1);

/**
 * app/Services/Push/PushDispatcher.php
 *
 * High-level push notification API for application code.
 *
 * Usage — queue a push for a single user:
 *   PushDispatcher::notifyUser($userId, 'New Job Assigned', 'Lawn care at 123 Oak St — 9:00 AM');
 *
 * Usage — send to multiple users (e.g. all admins):
 *   PushDispatcher::notifyUsers([1, 2, 3], 'Schedule Updated', 'Friday route has changed.');
 *
 * Queuing is safe to call inline in request handlers — the actual send happens
 * in the push-drain cron. If the cron is unavailable, pushes accumulate in
 * push_queue and are sent on the next cron run (up to 3 attempts, then marked failed).
 *
 * drainQueue() is called by the push-drain cron only — do NOT call it from web requests.
 * It sends up to $batchSize pending items per run to stay within cPanel PHP time limits.
 */

if (!class_exists('PushDispatcher')) {

class PushDispatcher
{
    private const MAX_ATTEMPTS   = 3;
    private const BATCH_SIZE     = 50;   // pushes per cron run (stay under PHP time limit)
    private const EXPIRE_HOURS   = 24;   // abandon unsent pushes older than this
    private const DEDUP_MINUTES  = 2;    // suppress an identical repeat push within this window

    // MARK: - Public: queue for a user

    /**
     * Queue a push notification for all active iOS tokens belonging to $userId.
     * Safe to call from any PHP context (web request, cron, etc.).
     *
     * @param int    $userId   Recipient's user ID
     * @param string $title    Notification title (max 200 chars)
     * @param string $body     Notification body (max 500 chars)
     * @param array  $data     Extra payload key-values (deep-linked screen, IDs, etc.)
     * @param int    $badge    App icon badge number (1 = increment, 0 = clear)
     */
    public static function notifyUser(
        int    $userId,
        string $title,
        string $body,
        array  $data  = [],
        int    $badge = 1
    ): void {
        try {
            $db     = getDB();
            $tokens = self::getActiveTokens($db, $userId);

            foreach ($tokens as $row) {
                self::enqueue($db, $row['user_id'], $row['device_token'], $row['platform'], $title, $body, $data, $badge);
            }
        } catch (Throwable $e) {
            error_log('[PushDispatcher] notifyUser error: ' . $e->getMessage());
        }
    }

    /**
     * Queue a push for multiple users. Efficient — single DB query for all tokens.
     *
     * @param int[]  $userIds  Array of recipient user IDs
     */
    public static function notifyUsers(
        array  $userIds,
        string $title,
        string $body,
        array  $data  = [],
        int    $badge = 1
    ): void {
        if (empty($userIds)) return;

        try {
            $db = getDB();
            // Fetch all active tokens for all recipients in one query
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $db->prepare(
                "SELECT user_id, device_token, platform
                   FROM device_tokens
                  WHERE user_id IN ({$placeholders}) AND is_active = 1"
            );
            $stmt->execute($userIds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                self::enqueue($db, (int)$row['user_id'], $row['device_token'], $row['platform'], $title, $body, $data, $badge);
            }
        } catch (Throwable $e) {
            error_log('[PushDispatcher] notifyUsers error: ' . $e->getMessage());
        }
    }

    // MARK: - Public: cron drain

    /**
     * Drain the push_queue — called by push-drain cron only.
     *
     * Processes up to BATCH_SIZE pending items. Marks sent/failed/expired accordingly.
     * Deactivates device tokens that APNs reports as unregistered (HTTP 410).
     *
     * @return array ['sent'=>int, 'failed'=>int, 'expired'=>int]
     */
    public static function drainQueue(): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'expired' => 0];

        try {
            $db = getDB();

            // Expire stale pending items before processing
            $db->prepare("
                UPDATE push_queue
                   SET status = 'expired'
                 WHERE status = 'pending'
                   AND created_at < NOW() - INTERVAL ? HOUR
            ")->execute([self::EXPIRE_HOURS]);
            $counts['expired'] = (int)$db->query("SELECT ROW_COUNT()")->fetchColumn();

            // Fetch batch of pending items
            $stmt = $db->prepare("
                SELECT id, user_id, device_token, platform, title, body,
                       data_payload, badge, apns_topic, attempts
                  FROM push_queue
                 WHERE status = 'pending'
                   AND scheduled_at <= NOW()
                 ORDER BY scheduled_at ASC
                 LIMIT " . self::BATCH_SIZE
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $result = self::sendOne($row);

                if ($result['success']) {
                    $db->prepare("UPDATE push_queue SET status='sent', sent_at=NOW(), attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
                    $counts['sent']++;
                } elseif ($result['unregistered']) {
                    // Device permanently unregistered — deactivate token, mark failed
                    $db->prepare("UPDATE device_tokens SET is_active=0 WHERE device_token=?")->execute([$row['device_token']]);
                    $db->prepare("UPDATE push_queue SET status='failed', attempts=attempts+1, error_message=? WHERE id=?")->execute(['Device unregistered (410)', $row['id']]);
                    $counts['failed']++;
                } else {
                    $newAttempts = (int)$row['attempts'] + 1;
                    if ($newAttempts >= self::MAX_ATTEMPTS) {
                        $db->prepare("UPDATE push_queue SET status='failed', attempts=?, error_message=? WHERE id=?")->execute([$newAttempts, $result['error'], $row['id']]);
                        $counts['failed']++;
                    } else {
                        // Leave as pending for retry
                        $db->prepare("UPDATE push_queue SET attempts=?, error_message=? WHERE id=?")->execute([$newAttempts, $result['error'], $row['id']]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[PushDispatcher] drainQueue error: ' . $e->getMessage());
        }

        return $counts;
    }

    // MARK: - Private helpers

    private static function sendOne(array $row): array
    {
        $data = [];
        if (!empty($row['data_payload'])) {
            $decoded = json_decode($row['data_payload'], true);
            if (is_array($decoded)) $data = $decoded;
        }

        if ($row['platform'] === 'android') {
            if (!class_exists('FcmService')) {
                require_once __DIR__ . '/FcmService.php';
            }
            return FcmService::send(
                $row['device_token'],
                $row['title'],
                $row['body'],
                $data,
                (int)$row['badge']
            );
        }

        return ApnsService::send(
            $row['device_token'],
            $row['title'],
            $row['body'],
            $data,
            (int)$row['badge'],
            $row['apns_topic'] ?: null
        );
    }

    private static function enqueue(
        PDO    $db,
        int    $userId,
        string $deviceToken,
        string $platform,
        string $title,
        string $body,
        array  $data,
        int    $badge
    ): void {
        $title = mb_substr($title, 0, 200);
        $body  = mb_substr($body, 0, 500);

        // Suppress an identical repeat within the dedup window — protects against a
        // client retrying a request that actually succeeded server-side (flaky field
        // connectivity), which would otherwise queue a second, redundant push. The
        // underlying write this push announces (e.g. a crew assignment) is itself
        // idempotent under retry; the notification wasn't, so this closes that gap.
        $dupCheck = $db->prepare("
            SELECT id FROM push_queue
             WHERE user_id = ? AND device_token = ? AND title = ? AND body = ?
               AND status IN ('pending', 'sent')
               AND created_at >= NOW() - INTERVAL ? MINUTE
             LIMIT 1
        ");
        $dupCheck->execute([$userId, $deviceToken, $title, $body, self::DEDUP_MINUTES]);
        if ($dupCheck->fetch()) {
            return;
        }

        $dataPayload = !empty($data) ? json_encode($data) : null;

        $db->prepare("
            INSERT INTO push_queue (user_id, device_token, platform, title, body, data_payload, badge)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$userId, $deviceToken, $platform,
            $title,
            $body,
            $dataPayload,
            $badge,
        ]);
    }

    private static function getActiveTokens(PDO $db, int $userId): array
    {
        $stmt = $db->prepare(
            "SELECT user_id, device_token, platform
               FROM device_tokens
              WHERE user_id = ? AND is_active = 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

} // end class_exists guard
