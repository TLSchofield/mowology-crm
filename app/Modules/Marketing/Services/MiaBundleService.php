<?php
/**
 * MiaBundleService — one decision, every channel (Tim 2026-10-26: "owner sees how it will look, hits approve or park, and it
 * hits the website, Instagram and Facebook"; built for the phone first).
 *
 * A finished job whose before/after photos the owner endorsed becomes ONE approval that covers, word for word:
 *   - the website portfolio card (published at once),
 *   - a Facebook post (before and after photos + the story text + a quote link),
 *   - an Instagram post (a two-photo carousel + the story text + at most three hashtags).
 * The text for every channel comes from the one story wording the owner edits and approves, so the owner reads one thing. The
 * approval is bound to the exact text, photos, channels and delay (approvalContent): change any of them and the approval no
 * longer counts.
 *
 * Posting is delayed (default 10 minutes, `mia_publish_delay_minutes`): the social posts are written to the existing social queue,
 * and the existing publisher cron sends them when due. Until then Undo cancels all of them, Instagram included. After a post has gone
 * out, Instagram cannot be deleted through its API, so the screen says so and asks for a stronger confirm first.
 *
 * Off unless `mia_publish_mode` = bundle. Channels start as website only: `mia_publish_channels` lists the social channels the
 * owner has switched on, one at a time, after seeing the one before work. A channel with no active connected account is skipped
 * and reported, never faked. Nothing here calls Facebook or Instagram: it only fills the queue.
 *
 * All photos are the approved pair's website copies (re-encoded, no location data), under /_media/. Global namespace, no
 * autoloader. PHP 7.4-compatible. Never throws to the caller: returns result arrays.
 */
declare(strict_types=1);

require_once __DIR__ . '/MiaActionService.php';
require_once __DIR__ . '/MiaStoryPublisher.php';
require_once __DIR__ . '/MiaRecommendationWriter.php';

final class MiaBundleService
{
    public const SOCIAL = ['facebook', 'instagram'];
    public const DEFAULT_DELAY_MINUTES = 10;
    public const MAX_HASHTAGS = 3;

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var MiaActionService */
    private $actions;
    /** @var MiaStoryPublisher */
    private $story;

    public function __construct(PDO $db, int $siteId, MiaActionService $actions, ?MiaStoryPublisher $story = null)
    {
        $this->db = $db;
        $this->siteId = $siteId;
        $this->actions = $actions;
        $this->story = $story ?: new MiaStoryPublisher($db, $siteId, $actions);
    }

    // ---- settings -----------------------------------------------------------------------------------

    private function setting(string $name): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            foreach (['mia_s' . $this->siteId . '_' . $name, 'mia_' . $name] as $k) {
                $s->execute([$k]);
                $v = $s->fetchColumn();
                if ($v !== false && $v !== null && $v !== '') { return (string)$v; }
            }
        } catch (Throwable $e) { /* no settings table: everything is off */ }
        return null;
    }

    public function isOn(): bool { return $this->setting('publish_mode') === 'bundle'; }

    /** Website always; plus the social channels the owner has switched on. @return string[] */
    public function channels(): array
    {
        $on = ['website'];
        foreach (array_map('trim', explode(',', (string)$this->setting('publish_channels'))) as $c) {
            if (in_array($c, self::SOCIAL, true) && !in_array($c, $on, true)) { $on[] = $c; }
        }
        return $on;
    }

    public function delayMinutes(): int
    {
        $v = $this->setting('publish_delay_minutes');
        return $v !== null && ctype_digit($v) ? max(0, min(60, (int)$v)) : self::DEFAULT_DELAY_MINUTES;
    }

    // ---- pure helpers -------------------------------------------------------------------------------

    /** #PointGrey, #HedgeTrimming: letters and digits only, at most MAX_HASHTAGS, no duplicates. @return string[] */
    public static function hashtags(string $area, string $service): array
    {
        $out = [];
        foreach ([$area, $service] as $phrase) {
            $tag = (string)preg_replace('/[^\p{L}\p{N}]+/u', '', ucwords(mb_strtolower($phrase, 'UTF-8')));
            if (mb_strlen($tag, 'UTF-8') >= 3 && mb_strlen($tag, 'UTF-8') <= 30) { $out['#' . $tag] = true; }
        }
        return array_slice(array_keys($out), 0, self::MAX_HASHTAGS);
    }

    /**
     * The exact words of each social post, built only from the owner-approved story text. No model call.
     * @return array{facebook:string,instagram:string}
     */
    public static function captions(string $desc, string $area, string $service, string $quoteUrl): array
    {
        $desc = trim($desc);
        $fb = $desc . ($quoteUrl !== '' ? "\n\nWant yours looked after? Get a free quote: " . $quoteUrl : '');
        $tags = self::hashtags($area, $service);
        $ig = $desc . "\n\nWant yours looked after? Get a free quote: link in our bio." . ($tags ? "\n\n" . implode(' ', $tags) : '');
        return ['facebook' => $fb, 'instagram' => $ig];
    }

    /** The one string an approval is bound to. */
    public static function approvalContent(string $title, string $desc, array $photos, array $channels, array $captions, int $delay): string
    {
        $t = MiaRecommendationWriter::draftText($title, $desc) . "\nPHOTOS: " . implode(' | ', $photos) . "\nCHANNELS: " . implode(',', $channels) . "\nDELAY: " . $delay;
        foreach (self::SOCIAL as $c) {
            if (in_array($c, $channels, true)) { $t .= "\n[" . $c . "]\n" . $captions[$c]; }
        }
        return $t;
    }

    // ---- preview ------------------------------------------------------------------------------------

    private function websiteBase(int $websiteId): string
    {
        try {
            $s = $this->db->prepare("SELECT base_url FROM mia_websites WHERE id = ? AND site_id = ?");
            $s->execute([$websiteId, $this->siteId]);
            $u = rtrim((string)$s->fetchColumn(), '/');
            return preg_match('#^https://[a-z0-9.-]+$#i', $u) ? $u : '';
        } catch (Throwable $e) { return ''; }
    }

    private function account(string $platform): ?array
    {
        try {
            $s = $this->db->prepare("SELECT id, platform, account_name FROM social_accounts WHERE platform = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
            $s->execute([$platform]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) { return null; }
    }

    /**
     * Exactly what the owner will approve.
     * @return array{ok:bool, code?:string, error?:string, channels?:array, content?:string, content_hash?:string, delay_minutes?:int, can_publish?:bool, why_not?:?string, undo_note?:string}
     */
    public function preview(int $actionId): array
    {
        $ctx = $this->story->inspect($actionId);
        if (!$ctx['ok']) { return $ctx; }
        $d = $ctx['draft']; $pair = $ctx['pair'];
        $base = $this->websiteBase((int)$d['website_id']);
        $photos = [];
        foreach (['web_before_path', 'web_after_path'] as $k) {
            if ($pair && MiaStoryPublisher::safeImage((string)$pair[$k])) { $photos[] = $base . $pair[$k]; }
        }
        $service = $pair ? $this->serviceName((int)$pair['id']) : '';
        $caps = self::captions((string)$d['meta_desc'], (string)($pair['area'] ?? ''), $service, $base !== '' ? $base . '/quote' : '');
        $channels = $this->channels();
        $delay = $this->delayMinutes();

        $out = ['website' => ['label' => 'Your portfolio page', 'ready' => true, 'text' => (string)$d['meta_desc'], 'name' => (string)$d['title']]];
        $skipped = [];
        foreach (self::SOCIAL as $c) {
            if (!in_array($c, $channels, true)) { continue; }
            $acc = $this->account($c);
            $out[$c] = ['label' => ucfirst($c), 'ready' => $acc !== null, 'text' => $caps[$c], 'photos' => $photos,
                        'account' => $acc ? (string)$acc['account_name'] : null, 'undoable_after_posting' => $c !== 'instagram'];
            if ($acc === null) { $skipped[] = $c; }
        }
        $content = self::approvalContent((string)$d['title'], (string)$d['meta_desc'], $photos, $channels, $caps, $delay);
        return ['ok' => true, 'channels' => $out, 'skipped' => $skipped, 'content' => $content, 'content_hash' => sha1($content), 'delay_minutes' => $delay,
                'can_publish' => $this->isOn() && $ctx['can_apply'] && count($photos) === 2, 'why_not' => !$this->isOn() ? 'Publishing is not switched on yet.' : ($ctx['can_apply'] ? (count($photos) === 2 ? null : 'The approved pair needs both a before and an after photo.') : $ctx['why_not']),
                'instagram_on' => in_array('instagram', $channels, true),
                'undo_note' => 'Undo works until ' . $delay . ' minutes after you approve. After that, Facebook posts can be removed from Facebook, and Instagram posts cannot be deleted from here.'];
    }

    private function serviceName(int $pairId): string
    {
        try {
            $s = $this->db->prepare("SELECT service FROM ba_pairs WHERE id = ?");
            $s->execute([$pairId]);
            return (string)$s->fetchColumn();
        } catch (Throwable $e) { return ''; }
    }

    // ---- publish ------------------------------------------------------------------------------------

    /**
     * Approve-and-publish in one go. The approval must already exist (MiaActionService::decide) for exactly this preview content.
     * @return array{ok:bool, code?:string, error?:string, scheduled_at?:string, channels?:string[], skipped?:string[], execution_id?:int, note?:string}
     */
    public function publish(int $actionId, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        if (!$this->isOn()) { return ['ok' => false, 'code' => 'off', 'error' => 'Publishing is not switched on yet.']; }
        $p = $this->preview($actionId);
        if (!$p['ok']) { return $p; }
        if (!$p['can_publish']) { return ['ok' => false, 'code' => 'cannot_publish', 'error' => (string)$p['why_not']]; }
        $ctx = $this->story->inspect($actionId);
        $d = $ctx['draft']; $pair = $ctx['pair'];
        $when = $now->modify('+' . (int)$p['delay_minutes'] . ' minutes');
        $service = $this->serviceName((int)$pair['id']);
        $queued = []; $skipped = [];

        $extra = function (int $pid, string $number, int $execId, array $c) use ($p, $d, $pair, $userId, $now, $when, $service, &$queued, &$skipped, $actionId): void {
            foreach (self::SOCIAL as $ch) {
                if (!isset($p['channels'][$ch])) { continue; }
                $acc = $this->account($ch);
                if ($acc === null) { $skipped[] = $ch; continue; }
                $postId = $this->createPost($ch, $acc, $p['channels'][$ch]['text'], (string)$d['title'], $pair, $service, $userId, $when, $now);
                $this->db->prepare("INSERT INTO mia_executions (site_id, action_id, draft_id, website_id, connector, target_ref, page_url, before_json, after_json, status, verify_note, applied_by, applied_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, '{}', ?, 'queued', ?, ?, ?)")
                    ->execute([$this->siteId, $actionId, (int)$d['id'], (int)$d['website_id'], $ch, 'social_posts:' . $postId, ucfirst($ch) . ' post', json_encode(['post_id' => $postId, 'scheduled_at' => $when->format('Y-m-d H:i:s')]),
                               'Waiting to post at ' . $when->format('H:i') . '. You can still undo it until then.', $userId, $now->format('Y-m-d H:i:s')]);
                $queued[] = $ch;
            }
        };
        $r = $this->story->apply($actionId, $userId, $now, ['publish' => true, 'content' => $p['content'], 'extra' => $extra]);
        if (empty($r['ok'])) { return $r; }
        $note = 'Published on your portfolio page.' . ($queued ? ' ' . implode(' and ', array_map('ucfirst', $queued)) . ' will post at ' . $when->format('H:i') . '.' : '')
              . ($skipped ? ' Skipped (no connected account): ' . implode(', ', $skipped) . '.' : '');
        return ['ok' => true, 'scheduled_at' => $when->format('Y-m-d H:i:s'), 'channels' => array_merge(['website'], $queued), 'skipped' => $skipped, 'execution_id' => (int)$r['execution_id'], 'note' => $note];
    }

    /** One social_posts row + its photos + platform row + queue row, exactly as the existing publisher expects. Runs inside the caller's transaction. */
    private function createPost(string $channel, array $acc, string $caption, string $title, array $pair, string $service, int $userId, DateTimeImmutable $when, DateTimeImmutable $now): int
    {
        $this->db->prepare("INSERT INTO social_posts (title, caption, hashtags, status, neighborhood, service_type, created_by, approved_by, auto_generated, scheduled_at, created_at, updated_at)
                            VALUES (?, ?, '', 'scheduled', ?, ?, ?, ?, 1, ?, ?, ?)")
            ->execute([mb_substr($title, 0, 255), $caption, mb_substr((string)$pair['area'], 0, 100), mb_substr($service, 0, 100), $userId, $userId,
                       $when->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]);
        $postId = (int)$this->db->lastInsertId();
        $sort = 0;
        foreach (['web_before_path', 'web_after_path'] as $k) {
            $mid = $this->mediaFor((string)$pair[$k], $userId, $now);
            $this->db->prepare("INSERT INTO social_post_media (post_id, media_id, sort_order) VALUES (?, ?, ?)")->execute([$postId, $mid, $sort++]);
        }
        $this->db->prepare("INSERT INTO social_post_platforms (post_id, account_id, platform, status) VALUES (?, ?, ?, 'pending')")->execute([$postId, (int)$acc['id'], $channel]);
        $this->db->prepare("INSERT INTO social_queue (post_id, account_id, platform, scheduled_at, status) VALUES (?, ?, ?, ?, 'pending')")
            ->execute([$postId, (int)$acc['id'], $channel, $when->format('Y-m-d H:i:s')]);
        return $postId;
    }

    /** A media_assets row pointing at the website copy (reused when it already exists). */
    private function mediaFor(string $path, int $userId, DateTimeImmutable $now): int
    {
        $s = $this->db->prepare("SELECT id FROM media_assets WHERE file_path = ? LIMIT 1");
        $s->execute([$path]);
        $id = $s->fetchColumn();
        if ($id !== false) { return (int)$id; }
        $name = basename($path);
        $this->db->prepare("INSERT INTO media_assets (original_filename, stored_filename, file_path, file_type, mime_type, context_type, status, created_by, created_at)
                            VALUES (?, ?, ?, 'image', ?, 'mia_pair', 'ready', ?, ?)")
            ->execute([$name, $name, $path, preg_match('/\.png$/i', $path) ? 'image/png' : (preg_match('/\.webp$/i', $path) ? 'image/webp' : 'image/jpeg'), $userId, $now->format('Y-m-d H:i:s')]);
        return (int)$this->db->lastInsertId();
    }

    // ---- undo ---------------------------------------------------------------------------------------

    /**
     * Take everything back that can still be taken back: queued posts are cancelled, the portfolio card is removed if untouched.
     * @return array{ok:bool, cancelled:string[], already_posted:string[], website:string, error?:string}
     */
    public function undo(int $actionId, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $res = ['ok' => false, 'cancelled' => [], 'already_posted' => [], 'website' => 'none'];
        $s = $this->db->prepare("SELECT * FROM mia_executions WHERE action_id = ? AND site_id = ? AND status IN ('queued','applied','verified','unverified') ORDER BY id");
        $s->execute([$actionId, $this->siteId]);
        $execs = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$execs) { $res['error'] = 'There is nothing to undo.'; return $res; }
        foreach ($execs as $x) {
            if (!in_array($x['connector'], self::SOCIAL, true)) { continue; }
            $postId = (int)preg_replace('/^social_posts:/', '', (string)$x['target_ref']);
            try {
                $this->db->beginTransaction();
                $q = $this->db->prepare("UPDATE social_queue SET status = 'cancelled' WHERE post_id = ? AND status = 'pending'");
                $q->execute([$postId]);
                if ($q->rowCount() > 0) {
                    $this->db->prepare("UPDATE social_posts SET status = 'cancelled' WHERE id = ? AND status IN ('scheduled','approved','draft')")->execute([$postId]);
                    $this->db->prepare("UPDATE social_post_platforms SET status = 'cancelled' WHERE post_id = ? AND status = 'pending'")->execute([$postId]);
                    $this->db->prepare("UPDATE mia_executions SET status = 'rolled_back', rolled_back_by = ?, rolled_back_at = ?, verify_note = 'Cancelled before it posted.' WHERE id = ? AND status = 'queued'")
                        ->execute([$userId, $now->format('Y-m-d H:i:s'), (int)$x['id']]);
                    $res['cancelled'][] = (string)$x['connector'];
                } else {
                    $res['already_posted'][] = (string)$x['connector'];
                }
                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                error_log('MiaBundleService undo: ' . $e->getMessage());
                $res['error'] = 'Mia could not cancel one of the posts. Nothing further was changed.';
                return $res;
            }
        }
        foreach ($execs as $x) {
            if ($x['connector'] !== 'portfolio') { continue; }
            $r = $this->story->rollback((int)$x['id'], $userId, $now);
            $res['website'] = !empty($r['ok']) ? 'removed' : 'kept';
            if (empty($r['ok'])) { $res['error'] = (string)($r['error'] ?? 'The card could not be removed.'); }
        }
        $res['ok'] = $res['website'] !== 'kept' && !isset($res['error']);
        return $res;
    }

    // ---- reconcile (called by the drain cron) --------------------------------------------------------

    /** Move queued executions to verified or failed once the existing publisher has done its work. @return int rows changed */
    public function reconcile(?DateTimeImmutable $now = null): int
    {
        $n = 0;
        $s = $this->db->prepare("SELECT id, target_ref FROM mia_executions WHERE site_id = ? AND status = 'queued' AND connector IN ('facebook','instagram')");
        $s->execute([$this->siteId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $postId = (int)preg_replace('/^social_posts:/', '', (string)$x['target_ref']);
            $p = $this->db->prepare("SELECT status, platform_url, fail_reason FROM social_post_platforms WHERE post_id = ? ORDER BY id DESC LIMIT 1");
            $p->execute([$postId]);
            $row = $p->fetch(PDO::FETCH_ASSOC);
            if (!$row) { continue; }
            if ($row['status'] === 'published') {
                $note = 'Posted.' . (!empty($row['platform_url']) && preg_match('#^https://#', (string)$row['platform_url']) ? ' ' . $row['platform_url'] : '');
                $u = $this->db->prepare("UPDATE mia_executions SET status = 'verified', verify_note = ? WHERE id = ? AND status = 'queued'");
                $u->execute([mb_substr($note, 0, 500), (int)$x['id']]);
                $n += $u->rowCount();
            } elseif ($row['status'] === 'failed') {
                $u = $this->db->prepare("UPDATE mia_executions SET status = 'failed', error = ?, verify_note = 'It could not be posted. Nothing went out.' WHERE id = ? AND status = 'queued'");
                $u->execute([mb_substr((string)$row['fail_reason'], 0, 500), (int)$x['id']]);
                $n += $u->rowCount();
            }
        }
        return $n;
    }
}
