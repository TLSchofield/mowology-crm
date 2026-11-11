<?php
/**
 * MiaJobAdapter — Mia's decisions on the Social Posts page as one queue (the strip across the top of the page).
 *
 * Nothing is decided here and nothing is written: this reads the same tables the page reads and shapes each decision as
 * a card whose buttons carry the SAME request the page's own buttons send (posts.php approve / retry / schedule, the
 * Facebook reconnect link). Order: reconnect first (nothing can post until it is done), then failed posts, then a post
 * waiting for approval, then drafts that are ready to schedule.
 *
 * Her confidence (the four-state triangle) comes from her record where there is one: approvals and rejections in
 * social_approvals, by kind of post (crew-photo draft or written by hand). Edits made before an approval are not
 * recorded, so this is an approximation. A failed post has no record to read: retry clears fail_reason, so the
 * triangle for reconnect and failed jobs stays "forming" rather than pretending.
 *
 * Pure shapers (queue, classify, sure, ...) are unit tested; jobs() is the only method that touches the database.
 * No namespace / no autoloader in production.
 */
require_once __DIR__ . '/../../Social/Services/SocialConnectionState.php';

class MiaJobAdapter
{
    public const MASTERED_AT = 5;
    public const MAX_FAILED = 5;
    public const MAX_DRAFTS = 3;
    public const POSTS_API = '/crm/api/social/posts.php';
    public const EDITOR = '/crm/marketing/social-post-editor.php';
    public const ACCOUNTS = '/crm/marketing/social-accounts.php';

    /** @return array<int, array> the cards, in order; [] when nothing is waiting or anything goes wrong */
    public static function jobs(PDO $db, array $user, bool $canEdit, ?DateTimeImmutable $now = null): array
    {
        try {
            $now = $now ?? new DateTimeImmutable('now');
            $state = SocialConnectionState::fromAccounts(SocialConnectionState::load($db));
            return self::queue($state, self::failedRows($db), self::waitingRows($db), $canEdit ? self::draftRows($db) : [],
                self::records($db), $canEdit, $now);
        } catch (Throwable $e) {
            error_log('MiaJobAdapter::jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array $state   SocialConnectionState::fromAccounts()
     * @param array $failed  rows: id, caption, platforms (csv), detail, thumb_path
     * @param array $waiting rows: id, caption, platforms, scheduled_at, auto_generated, thumb_path, created_by_name
     * @param array $drafts  rows: id, caption, platforms, auto_generated, thumb_path
     * @param array $records ['crew' => [called, agreed], 'hand' => [called, agreed]]
     */
    public static function queue(array $state, array $failed, array $waiting, array $drafts, array $records, bool $canEdit, DateTimeImmutable $now): array
    {
        $jobs = [];
        $reconnect = !empty($state['needs_reconnect']);
        if ($reconnect) $jobs[] = self::reconnectJob($state, count($failed));

        foreach (array_slice($failed, 0, self::MAX_FAILED) as $r) {
            $cause = self::classify((string)($r['detail'] ?? ''));
            if ($cause === 'login' && $reconnect) continue;     // the reconnect card above already asks for it
            $jobs[] = self::failedJob($r, $cause);
        }
        if (!$reconnect) {
            foreach ($waiting as $r) $jobs[] = self::approveJob($r, $records, $now);
        }
        foreach (array_slice($drafts, 0, self::MAX_DRAFTS) as $r) {
            if ($canEdit) $jobs[] = self::scheduleJob($r, $records, $now);
        }
        $total = count($jobs);
        foreach ($jobs as $i => &$j) { $j['index'] = $i + 1; $j['total'] = $total; }
        unset($j);
        return $jobs;
    }

    /** login | ratio | other, from the platform's own error text. */
    public static function classify(string $text): string
    {
        if (preg_match('/aspect ratio|\(code 36003\)/i', $text)) return 'ratio';
        if (preg_match('/page token|reconnect|could not be decrypted|\(code 190\)|OAuthException|token.{0,30}(expired|invalid)|session has expired/i', $text)) return 'login';
        return 'other';
    }

    /** One plain sentence for a post row; the platform's own text stays on hover. */
    public static function plainReason(string $text): string
    {
        if (trim($text) === '') return '';
        return ['ratio' => "Instagram can't use the picture's shape.", 'login' => 'The login needs reconnecting.', 'other' => 'It did not go out.'][self::classify($text)];
    }

    /** The four-state triangle from a record: none = forming, wrong once or one call = held, 2-4 right = known, 5+ = mastered. */
    public static function sure(array $record): string
    {
        $called = (int)($record[0] ?? $record['called'] ?? 0);
        $agreed = (int)($record[1] ?? $record['agreed'] ?? 0);
        if ($called <= 0) return 'forming';
        if ($agreed < $called) return 'held';
        return $called >= self::MASTERED_AT ? 'mastered' : ($called >= 2 ? 'known' : 'held');
    }

    /** "posts Fri Nov 13, 10:00 a.m." for a future time, otherwise "posts shortly" (the publisher cron picks an approved post up on its next run). */
    public static function goesOut(?string $scheduledAt, DateTimeImmutable $now): string
    {
        $t = $scheduledAt ? strtotime($scheduledAt) : false;
        if (!$t || $t <= $now->getTimestamp()) return 'posts shortly';
        return 'posts ' . self::when($t);
    }

    public static function when(int $ts): string
    {
        return date('D M j', $ts) . ', ' . str_replace(['AM', 'PM'], ['a.m.', 'p.m.'], date('g:i A', $ts));
    }

    /** Tomorrow 10:00 as a datetime-local value: the default for a draft with no time of its own. */
    public static function defaultTime(DateTimeImmutable $now): string
    {
        return $now->modify('+1 day')->setTime(10, 0)->format('Y-m-d\TH:i');
    }

    // ── cards ─────────────────────────────────────────────────────────────

    private static function reconnectJob(array $state, int $stuck): array
    {
        $reason = (string)preg_replace('/^Reconnect Facebook — /', '', (string)($state['message'] ?? ''));
        return [
            'head' => 'mia', 'kind' => 'reconnect', 'key' => 'reconnect',
            'title' => 'Facebook has stopped posting',
            'why' => $reason !== '' ? ucfirst($reason) : 'The saved login no longer works. Nothing is posting until you reconnect.',
            'sure' => 'forming', 'confidence' => '',
            'post' => ['id' => 0, 'caption' => $stuck > 0 ? $stuck . ' post' . ($stuck === 1 ? '' : 's') . ' did not go out.' : '', 'thumb' => '', 'platforms' => ['facebook', 'instagram'], 'when' => ''],
            'detail' => '', 'fields' => [],
            'primary' => ['label' => 'Reconnect Facebook', 'sub' => 'sign in again', 'href' => (string)($state['action_url'] ?? SocialConnectionState::RECONNECT_URL)],
            'secondary' => null,
            'anchor' => ['label' => 'Accounts', 'href' => self::ACCOUNTS],
        ];
    }

    private static function failedJob(array $r, string $cause): array
    {
        $id = (int)$r['id'];
        $platforms = self::platforms((string)($r['platforms'] ?? ''));
        $detail = trim((string)($r['detail'] ?? ''));
        $base = [
            'head' => 'mia', 'kind' => 'failed', 'key' => 'failed:' . $id, 'cause' => $cause,
            'sure' => 'forming', 'confidence' => '',
            'post' => self::postBlock($r, $platforms, ''), 'detail' => $detail, 'fields' => [],
            'anchor' => ['label' => 'Calendar', 'href' => '/crm/marketing/social-calendar.php'],
        ];
        $edit = ['label' => 'Edit', 'sub' => 'open the post', 'href' => self::EDITOR . '?id=' . $id];
        if ($cause === 'ratio') {
            return $base + [
                'title' => 'Instagram turned down the picture',
                'why' => 'Its shape is not accepted: Instagram takes 4:5 up to 1.91:1. Crop it, then send again.',
                'primary' => ['label' => 'Fix the picture', 'sub' => 'crop it in the editor', 'href' => self::EDITOR . '?id=' . $id],
                'secondary' => ['label' => 'Leave it', 'sub' => 'for now', 'skip' => true],
            ];
        }
        if ($cause === 'login') {
            $meta = (bool)preg_match('/meta|instagram|facebook/i', $detail);
            return $base + [
                'title' => ($meta ? 'Facebook' : 'The account') . ' turned the post down',
                'why' => 'The saved login was refused. Reconnect it, then the post can go out.',
                'primary' => ['label' => 'Reconnect', 'sub' => 'sign in again', 'href' => $meta ? SocialConnectionState::RECONNECT_URL : self::ACCOUNTS],
                'secondary' => $edit,
            ];
        }
        return $base + [
            'title' => 'A post did not go out',
            'why' => 'It stopped for a reason I do not know yet. Try once more, or open it. The full text is on hover.',
            'primary' => ['label' => 'Try again', 'sub' => 'send it once more', 'request' => ['url' => self::POSTS_API . '?action=retry', 'body' => ['id' => $id]]],
            'secondary' => $edit,
        ];
    }

    private static function approveJob(array $r, array $records, DateTimeImmutable $now): array
    {
        $id = (int)$r['id'];
        $kind = !empty($r['auto_generated']) ? 'crew' : 'hand';
        $rec = (array)($records[$kind] ?? [0, 0]);
        $sure = self::sure($rec);
        $out = self::goesOut($r['scheduled_at'] ?? null, $now);
        return [
            'head' => 'mia', 'kind' => 'approve', 'key' => 'approve:' . $id,
            'title' => 'Approve this post?',
            'why' => 'Waiting for you' . (($r['created_by_name'] ?? '') !== '' ? ', written by ' . $r['created_by_name'] : '') . '.',
            'sure' => $sure, 'confidence' => self::confidenceLine($kind, $rec),
            'post' => self::postBlock($r, self::platforms((string)($r['platforms'] ?? '')), $out),
            'detail' => '', 'fields' => [],
            'primary' => ['label' => 'Approve', 'sub' => $out, 'request' => ['url' => self::POSTS_API . '?action=approve', 'body' => ['id' => $id]]],
            'secondary' => ['label' => 'Change', 'sub' => 'open the editor', 'href' => self::EDITOR . '?id=' . $id],
            'anchor' => ['label' => 'Calendar', 'href' => '/crm/marketing/social-calendar.php'],
        ];
    }

    private static function scheduleJob(array $r, array $records, DateTimeImmutable $now): array
    {
        $id = (int)$r['id'];
        $kind = !empty($r['auto_generated']) ? 'crew' : 'hand';
        $rec = (array)($records[$kind] ?? [0, 0]);
        return [
            'head' => 'mia', 'kind' => 'schedule', 'key' => 'schedule:' . $id,
            'title' => 'Schedule this draft?',
            'why' => 'It has a picture, words and a place to go. Pick when it goes out.',
            'sure' => self::sure($rec), 'confidence' => self::confidenceLine($kind, $rec),
            'post' => self::postBlock($r, self::platforms((string)($r['platforms'] ?? '')), ''),
            'detail' => '', 'fields' => ['scheduled_at'],
            'right' => ['scheduled_at' => self::defaultTime($now), 'min' => $now->format('Y-m-d\TH:i')],
            'primary' => ['label' => 'Schedule', 'sub' => 'at the time shown', 'request' => ['url' => self::POSTS_API . '?action=schedule', 'body' => ['id' => $id]]],
            'secondary' => ['label' => 'Edit', 'sub' => 'open the post', 'href' => self::EDITOR . '?id=' . $id],
            'anchor' => ['label' => 'Calendar', 'href' => '/crm/marketing/social-calendar.php'],
        ];
    }

    private static function confidenceLine(string $kind, array $rec): string
    {
        $called = (int)($rec[0] ?? 0);
        if ($called <= 0) return '';
        return 'Approved ' . (int)($rec[1] ?? 0) . ' of ' . $called . ' ' . ($kind === 'crew' ? 'crew-photo drafts' : 'posts written by hand') . '.';
    }

    private static function postBlock(array $r, array $platforms, string $when): array
    {
        $cap = trim((string)($r['caption'] ?? ''));
        if (function_exists('mb_strlen') && mb_strlen($cap) > 160) $cap = rtrim(mb_substr($cap, 0, 160)) . '…';
        $path = trim((string)($r['thumb_path'] ?? ''), '/');
        return ['id' => (int)($r['id'] ?? 0), 'caption' => $cap, 'thumb' => $path !== '' ? '/' . $path : '', 'platforms' => $platforms, 'when' => $when];
    }

    private static function platforms(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), fn($p) => $p !== ''));
    }

    // ── reads (the page's own queries) ────────────────────────────────────

    private const THUMB = "(SELECT ma.file_path FROM social_post_media spm JOIN media_assets ma ON ma.id = spm.media_id WHERE spm.post_id = sp.id ORDER BY spm.sort_order LIMIT 1)";
    private const PLATS = "(SELECT GROUP_CONCAT(platform ORDER BY platform SEPARATOR ',') FROM social_post_platforms WHERE post_id = sp.id)";

    private static function failedRows(PDO $db): array
    {
        return self::rows($db, "
            SELECT sp.id, sp.caption, " . self::PLATS . " AS platforms, " . self::THUMB . " AS thumb_path,
                   COALESCE(NULLIF((SELECT GROUP_CONCAT(fail_reason ORDER BY platform SEPARATOR ' | ')
                        FROM social_post_platforms WHERE post_id = sp.id AND status <> 'published' AND fail_reason IS NOT NULL), ''), sp.last_fail_reason, '') AS detail
              FROM social_posts sp
             WHERE sp.status = 'failed'
                OR (sp.status IN ('publishing','published') AND EXISTS (
                        SELECT 1 FROM social_post_platforms WHERE post_id = sp.id AND status <> 'published' AND fail_reason IS NOT NULL))
             ORDER BY sp.updated_at DESC LIMIT " . (self::MAX_FAILED + 5));
    }

    private static function waitingRows(PDO $db): array
    {
        $auto = self::hasColumn($db, 'social_posts', 'auto_generated') ? 'sp.auto_generated' : '0';
        return self::rows($db, "
            SELECT sp.id, sp.caption, sp.scheduled_at, $auto AS auto_generated, u.full_name AS created_by_name,
                   " . self::PLATS . " AS platforms, " . self::THUMB . " AS thumb_path
              FROM social_posts sp LEFT JOIN users u ON u.id = sp.created_by
             WHERE sp.status = 'pending_approval' ORDER BY sp.created_at ASC LIMIT 5");
    }

    private static function draftRows(PDO $db): array
    {
        $auto = self::hasColumn($db, 'social_posts', 'auto_generated') ? 'sp.auto_generated' : '0';
        return self::rows($db, "
            SELECT sp.id, sp.caption, $auto AS auto_generated, " . self::PLATS . " AS platforms, " . self::THUMB . " AS thumb_path
              FROM social_posts sp
             WHERE sp.status = 'draft' AND TRIM(sp.caption) <> ''
               AND EXISTS (SELECT 1 FROM social_post_platforms WHERE post_id = sp.id)
               AND EXISTS (SELECT 1 FROM social_post_media WHERE post_id = sp.id)
             ORDER BY $auto DESC, sp.id DESC LIMIT " . self::MAX_DRAFTS);
    }

    /** @return array{crew: array{0:int,1:int}, hand: array{0:int,1:int}} called/agreed per kind of post */
    private static function records(PDO $db): array
    {
        $out = ['crew' => [0, 0], 'hand' => [0, 0]];
        $auto = self::hasColumn($db, 'social_posts', 'auto_generated') ? 'sp.auto_generated' : '0';
        foreach (self::rows($db, "
            SELECT $auto AS auto, COUNT(*) AS called, SUM(sa.action = 'approved') AS agreed
              FROM social_approvals sa JOIN social_posts sp ON sp.id = sa.post_id
             WHERE sa.action IN ('approved','rejected') GROUP BY auto") as $r) {
            $out[!empty($r['auto']) ? 'crew' : 'hand'] = [(int)$r['called'], (int)$r['agreed']];
        }
        return $out;
    }

    private static function hasColumn(PDO $db, string $table, string $col): bool
    {
        try {
            return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function rows(PDO $db, string $sql): array
    {
        try {
            $st = $db->query($sql);
            return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            error_log('MiaJobAdapter query: ' . $e->getMessage());
            return [];
        }
    }
}
