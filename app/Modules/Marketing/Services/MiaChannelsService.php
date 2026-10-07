<?php
/**
 * MiaChannelsService — Mia's channels: Google Business Profile, social, the website and listings.
 *
 *   summary()      everything the "Channels" section of Mia's card shows.
 *   briefItems()   Charlie's items (merged into MiaDeskService::brief()):
 *                    a review waiting for a reply          priority 1
 *                    Facebook needs reconnecting           priority 1, until it's fixed
 *                    a Google post draft ready             priority 2
 *                    social drafts waiting                 priority 2
 *                    this week's website insight           priority 3
 *                    monthly "Check Houzz and Yelp details match"  priority 3
 *   prepareWeek()  once per week (cron, or the card): draft the Google post for this week's
 *                  theme, draft social posts from crew photos, import reviews (when the GBP API
 *                  is live), run the website watch.
 *   reviews, posts Tim's clicks. Mia NEVER posts on her own: posting to Google happens only
 *                  from Tim's "Post" click and only in live mode; otherwise he copies the draft.
 *
 * Tables (migration 1200): gbp_reviews, mia_channel_drafts, mia_web_reports; (1201)
 * marketing_listings. Every read is guarded so the card works before the migrations run.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/MiaSeasonThemes.php';
require_once __DIR__ . '/ReviewReplyDrafter.php';
require_once __DIR__ . '/WebsiteWatchService.php';
require_once __DIR__ . '/ListingsService.php';
require_once __DIR__ . '/MiaSocialService.php';
require_once __DIR__ . '/MediaTagService.php';
require_once __DIR__ . '/MediaPickerService.php';
require_once dirname(__DIR__, 2) . '/Social/Services/SocialConnectionState.php';
require_once dirname(__DIR__, 2) . '/Social/Services/GbpService.php';

class MiaChannelsService
{
    public const URL = '/crm/dashboard_appstack.php#mw-mia-chan';

    private PDO $db;
    private MiaThemeSource $themes;

    public function __construct(PDO $db, ?MiaThemeSource $themes = null)
    {
        $this->db = $db;
        $this->themes = $themes ?? new MiaSeasonThemes();
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'mia_channel_drafts'")->fetchColumn() !== false
                && $this->db->query("SHOW TABLES LIKE 'gbp_reviews'")->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // The card
    // ─────────────────────────────────────────────────────────────────────

    public function summary(?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        $social = SocialConnectionState::fromAccounts(SocialConnectionState::load($this->db));
        $gbp = (new GbpService($this->db))->mode();
        $ready = $this->ready();
        $theme = MiaSeasonThemes::pick($this->themes, $today, $this->recentThemes('gbp_post'));

        $listings = new ListingsService($this->db);
        $web = (new WebsiteWatchService($this->db))->latest();
        $bestPost = (new MiaSocialService($this->db))->bestRecent();

        return [
            'ready'  => $ready,
            'google' => [
                'mode'      => $gbp['mode'],
                'reason'    => $gbp['reason'],
                'steps'     => $gbp['steps'],
                'connected' => $social['gbp']['status'] === 'connected',
                'reviews'   => $ready ? $this->reviews('waiting', 5) : [],
                'waiting'   => $ready ? $this->count("SELECT COUNT(*) FROM gbp_reviews WHERE status = 'waiting'") : 0,
                'post'      => $ready ? $this->openPost() : null,
                'theme'     => $theme ? ['key' => $theme['key'], 'title' => $theme['title']] : null,
            ],
            'social' => [
                'facebook'  => $social['facebook'],
                'instagram' => $social['instagram'],
                'needs_reconnect' => $social['needs_reconnect'],
                'message'   => $social['message'],
                'reconnect_url' => $social['action_url'],
                'drafts'    => $this->socialDrafts(),
                'best'      => $bestPost,
            ],
            'website' => self::webView($web),
            'listings' => $listings->ready() ? $listings->counts() + ['url' => '/crm/marketing/listings.php'] : null,
        ];
    }

    /** The card's view of the latest website report. */
    public static function webView(?array $web): array
    {
        if (!$web) return ['status' => 'none', 'headline' => 'No website report yet — the first one runs on Monday morning.', 'suggestions' => [], 'top' => []];
        $r = $web['report'] ?? [];
        if ($web['status'] !== 'ok') {
            return ['status' => $web['status'], 'week' => $web['week_start'],
                    'headline' => $web['status'] === 'not_connected' ? 'Search Console isn\'t connected, so I can\'t watch the website yet.' : 'Last week\'s Search Console read failed: ' . ($web['error'] ?? 'unknown error'),
                    'suggestions' => [], 'top' => []];
        }
        return [
            'status' => 'ok', 'week' => $web['week_start'], 'headline' => (string)($r['headline'] ?? ''),
            'suggestions' => array_slice($r['suggestions'] ?? [], 0, 5),
            'top' => array_slice($r['top_queries'] ?? [], 0, 5),
            'totals' => $r['totals'] ?? null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Charlie's brief
    // ─────────────────────────────────────────────────────────────────────

    public function briefItems(?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        $items = [];
        $social = SocialConnectionState::fromAccounts(SocialConnectionState::load($this->db));
        if ($social['needs_reconnect']) {
            $items[] = ['key' => 'mia:fb-reconnect', 'text' => $social['message'], 'url' => '/crm/marketing/social-accounts.php',
                        'priority' => 1, 'kind' => 'channel_reconnect'];
        }
        if (!$this->ready()) return $items;

        foreach ($this->reviews('waiting', 3) as $r) {
            $who = $r['reviewer_name'] !== '' ? $r['reviewer_name'] : 'A reviewer';
            $items[] = ['key' => 'mia:review:' . $r['id'], 'text' => sprintf('%s left a %d-star Google review. My reply is drafted.', $who, (int)$r['rating']),
                        'url' => self::URL, 'priority' => 1, 'kind' => 'review_reply', 'since' => $r['review_date'] ?: null];
        }
        $post = $this->openPost();
        if ($post) {
            $items[] = ['key' => 'mia:gbp-post:' . $post['id'], 'text' => 'This week\'s Google post is drafted: ' . $post['title'] . '.',
                        'url' => self::URL, 'priority' => 2, 'kind' => 'gbp_post'];
        }
        $drafts = $this->socialDrafts();
        if ($drafts) {
            $n = count($drafts);
            $items[] = ['key' => 'mia:social-drafts:' . $drafts[0]['id'], 'text' => sprintf('%d social post%s drafted from crew photos, waiting for your OK.', $n, $n === 1 ? '' : 's'),
                        'url' => '/crm/marketing/social-post-editor.php?id=' . (int)$drafts[0]['social_post_id'], 'priority' => 2, 'kind' => 'social_draft'];
        }
        $web = self::webView((new WebsiteWatchService($this->db))->latest());
        if ($web['status'] === 'ok' && $web['headline'] !== '') {
            $items[] = ['key' => 'mia:web:' . $web['week'], 'text' => 'Website: ' . $web['headline'], 'url' => self::URL, 'priority' => 3, 'kind' => 'web_insight'];
        }
        if (self::listingsReminderDue($today)) {
            $items[] = ['key' => 'mia:listings:' . $today->format('Y-m'), 'text' => 'Check Houzz and Yelp details match.',
                        'url' => '/crm/marketing/listings.php', 'priority' => 3, 'kind' => 'listings'];
        }
        return $items;
    }

    /** First week of each month. Charlie's "Handled" settles it for the month (the key is per month). */
    public static function listingsReminderDue(DateTimeImmutable $today): bool
    {
        return (int)$today->format('j') <= 7;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Weekly preparation
    // ─────────────────────────────────────────────────────────────────────

    /** @return array{skipped?: bool, gbp_post: int, social: int, reviews: int, web: string} */
    public function prepareWeek(DateTimeImmutable $today, bool $force = false): array
    {
        $week = $today->modify('monday this week')->format('Y-m-d');
        $out = ['gbp_post' => 0, 'social' => 0, 'reviews' => 0, 'web' => 'skipped'];
        if (!$this->ready()) return $out + ['skipped' => true];
        if (!$force && $this->setting('mia_channels_week') === $week) return $out + ['skipped' => true];
        $this->setSetting('mia_channels_week', $week);

        // Library first, so this week's drafts can use freshly tagged photos.
        $media = new MediaTagService($this->db);
        if ($media->ready()) {
            try {
                $b = $media->backfill(300);
                $v = $media->visionPass(40);
                $media->learn();
                $out['media'] = $b['imported'] . ' imported, ' . $b['tagged'] . ' tagged, ' . $v['done'] . ' seen by vision';
            } catch (Throwable $e) {
                error_log('Mia media tagging: ' . $e->getMessage());
            }
        }
        try {
            $out['gbp_post'] = $this->draftGooglePost($today) ? 1 : 0;
        } catch (Throwable $e) {
            error_log('Mia GBP post draft: ' . $e->getMessage());
        }
        try {
            $social = new MiaSocialService($this->db);
            $out['social'] = $social->draftWeek($today, $this->themes);
        } catch (Throwable $e) {
            error_log('Mia social drafts: ' . $e->getMessage());
        }
        $gbp = new GbpService($this->db);
        if ($gbp->mode()['mode'] === 'live') {
            try {
                $out['reviews'] = $gbp->importReviews($this->replyContext());
            } catch (Throwable $e) {
                error_log('Mia GBP review import: ' . $e->getMessage());
            }
        }
        $watch = new WebsiteWatchService($this->db);
        if ($watch->ready()) $out['web'] = $watch->run($today)['status'];
        return $out;
    }

    /** Draft this week's Google post (one per week). @return int|null the draft id */
    public function draftGooglePost(DateTimeImmutable $today): ?int
    {
        $week = $today->modify('monday this week')->format('Y-m-d');
        $s = $this->db->prepare("SELECT id FROM mia_channel_drafts WHERE kind = 'gbp_post' AND week_start = ?");
        $s->execute([$week]);
        if ($s->fetchColumn()) return null;
        $perf = MiaSocialService::rankThemes((new MiaSocialService($this->db))->performance(), MiaSeasonThemes::all());
        $theme = MiaSeasonThemes::pick($this->themes, $today, $this->recentThemes('gbp_post'), $perf);
        if (!$theme) return null;
        // Older drafts nobody used make way for this week's.
        $this->db->prepare("UPDATE mia_channel_drafts SET status = 'expired' WHERE kind = 'gbp_post' AND status = 'draft' AND week_start < ?")->execute([$week]);
        $site = rtrim($this->website(), '/');
        // The photo: a 4:3 before/after (or single) that is safe to publish, from the library.
        $photo = null;
        $picker = new MediaPickerService($this->db);
        try {
            $photo = $picker->pick($theme['services'][0] ?? null, MediaTagRules::season($today->format('Y-m-d')), 'gbp', 1)[0] ?? null;
        } catch (Throwable $e) {
            error_log('Mia GBP photo pick: ' . $e->getMessage());
        }
        $this->db->prepare("
            INSERT INTO mia_channel_drafts (kind, theme_key, week_start, title, body, cta_type, cta_url, media_id, photo_url, status)
            VALUES ('gbp_post', ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
        ")->execute([$theme['key'], $week, $theme['title'], self::googlePostText($theme), $theme['cta'],
                     $theme['cta'] === 'CALL' ? null : $site . $theme['page'],
                     $photo['media_id'] ?? null, $photo['image']['url'] ?? null]);
        $id = (int)$this->db->lastInsertId();
        if ($photo) $picker->markUsed($photo, 'gbp', 'gbp_post', $id);
        return $id;
    }

    /**
     * Google's post text. No phone number and no link in the text — Google rejects local posts
     * that carry them; the call-to-action button carries the link or the call.
     */
    public static function googlePostText(array $theme): string
    {
        $t = trim($theme['google_post']);
        $t = preg_replace('#https?://\S+#', '', $t);
        $t = preg_replace('/\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/', '', $t);
        return trim(str_replace('!', '.', preg_replace('/\s{2,}/', ' ', $t)));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reviews — Tim's clicks
    // ─────────────────────────────────────────────────────────────────────

    public function reviews(string $status = 'waiting', int $limit = 5): array
    {
        try {
            $s = $this->db->prepare("SELECT id, source, reviewer_name, rating, comment, review_date, reply_draft, status
                                     FROM gbp_reviews WHERE status = ? ORDER BY rating ASC, COALESCE(review_date, created_at) DESC, id DESC LIMIT " . max(1, (int)$limit));
            $s->execute([$status]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as &$r) {
            $r['violations'] = ReviewReplyDrafter::violations((string)$r['reply_draft'], (string)$r['reviewer_name']);
        }
        return $rows;
    }

    /** A review Tim pasted in (or forwarded from the Google email). Mia drafts the reply at once. */
    public function addReview(array $in, int $userId): array
    {
        $comment = trim((string)($in['comment'] ?? ''));
        $rating = (int)($in['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) return ['ok' => false, 'error' => 'Pick the star rating (1–5).'];
        $name = mb_substr(trim((string)($in['reviewer_name'] ?? '')), 0, 120);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['review_date'] ?? '')) ? $in['review_date'] : date('Y-m-d');
        $draft = ReviewReplyDrafter::draft(['id' => $name . $date, 'reviewer_name' => $name, 'rating' => $rating, 'comment' => $comment],
            $this->replyContext() + ['service' => (string)($in['service'] ?? '')]);
        $this->db->prepare("
            INSERT INTO gbp_reviews (source, reviewer_name, rating, comment, review_date, reply_draft, status, created_by)
            VALUES ('pasted', ?, ?, ?, ?, ?, 'waiting', ?)
        ")->execute([$name, $rating, mb_substr($comment, 0, 4000), $date, $draft, $userId ?: null]);
        return ['ok' => true, 'id' => (int)$this->db->lastInsertId(), 'draft' => $draft];
    }

    public function saveReplyDraft(int $id, string $text): array
    {
        $this->db->prepare("UPDATE gbp_reviews SET reply_draft = ? WHERE id = ? AND status = 'waiting'")->execute([mb_substr(trim($text), 0, 4000), $id]);
        return ['ok' => true, 'violations' => ReviewReplyDrafter::violations($text, (string)$this->reviewField($id, 'reviewer_name'))];
    }

    /**
     * Tim's final reply. $post = true sends it to Google (live mode only); false records that he
     * pasted it into Google himself.
     */
    public function replyDone(int $id, string $text, bool $post, int $userId): array
    {
        $text = trim(str_replace('!', '.', $text));
        if ($text === '') return ['ok' => false, 'error' => 'The reply is empty.'];
        $row = $this->reviewRow($id);
        if (!$row || $row['status'] !== 'waiting') return ['ok' => false, 'error' => 'That review is already handled.'];
        if ($post) {
            if (($row['source'] ?? '') !== 'api' || empty($row['external_id'])) {
                return ['ok' => false, 'error' => 'This review was pasted in, so Google doesn\'t know its ID. Copy the reply and paste it into Google instead.'];
            }
            try {
                (new GbpService($this->db))->replyToReview((string)$row['external_id'], $text);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        $this->db->prepare("UPDATE gbp_reviews SET status = 'replied', reply_text = ?, replied_at = NOW(), replied_by = ?, replied_via = ? WHERE id = ?")
            ->execute([$text, $userId ?: null, $post ? 'api' : 'copied', $id]);
        return ['ok' => true, 'posted' => $post];
    }

    public function dismissReview(int $id, int $userId): array
    {
        $this->db->prepare("UPDATE gbp_reviews SET status = 'dismissed', replied_by = ?, replied_at = NOW() WHERE id = ? AND status = 'waiting'")->execute([$userId ?: null, $id]);
        return ['ok' => true];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Google post — Tim's clicks
    // ─────────────────────────────────────────────────────────────────────

    public function openPost(): ?array
    {
        try {
            $r = $this->db->query("SELECT id, theme_key, week_start, title, body, cta_type, cta_url, media_id, photo_url FROM mia_channel_drafts
                                   WHERE kind = 'gbp_post' AND status = 'draft' ORDER BY week_start DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function savePost(int $id, string $body): array
    {
        $body = trim(str_replace('!', '.', $body));
        if ($body === '') return ['ok' => false, 'error' => 'The post is empty.'];
        $this->db->prepare("UPDATE mia_channel_drafts SET body = ? WHERE id = ? AND kind = 'gbp_post' AND status = 'draft'")->execute([mb_substr($body, 0, 1500), $id]);
        return ['ok' => true];
    }

    /** $action: publish (live mode: posts to Google) | copied (Tim posted it himself) | dismiss */
    public function decidePost(int $id, string $action, int $userId, ?string $body = null): array
    {
        $s = $this->db->prepare("SELECT * FROM mia_channel_drafts WHERE id = ? AND kind = 'gbp_post' AND status = 'draft'");
        $s->execute([$id]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        if (!$d) return ['ok' => false, 'error' => 'That draft is already handled.'];
        if ($body !== null && trim($body) !== '') {
            $this->savePost($id, $body);
            $d['body'] = trim(str_replace('!', '.', $body));
        }
        $external = null;
        if ($action === 'publish') {
            try {
                $photo = !empty($d['photo_url']) ? rtrim($this->website(), '/') . $d['photo_url'] : null;
                $res = (new GbpService($this->db))->createLocalPost((string)$d['body'], $d['cta_type'] ?: null, $d['cta_url'] ?: null, $photo);
                $external = (string)($res['name'] ?? '');
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        } elseif (!in_array($action, ['copied', 'dismiss'], true)) {
            return ['ok' => false, 'error' => 'Unknown action'];
        }
        $status = ['publish' => 'posted', 'copied' => 'copied', 'dismiss' => 'dismissed'][$action];
        $this->db->prepare("UPDATE mia_channel_drafts SET status = ?, external_id = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")
            ->execute([$status, $external, $userId ?: null, $id]);
        return ['ok' => true, 'status' => $status];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /** Mia's social drafts still waiting in social_posts. */
    private function socialDrafts(): array
    {
        try {
            return $this->db->query("
                SELECT d.id, d.title, d.theme_key, d.social_post_id, d.week_start
                FROM mia_channel_drafts d
                JOIN social_posts sp ON sp.id = d.social_post_id
                WHERE d.kind = 'social' AND sp.status = 'draft'
                ORDER BY d.week_start DESC, d.id DESC LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Theme keys used in the last REPEAT_WEEKS weeks, newest first. */
    private function recentThemes(string $kind): array
    {
        try {
            $s = $this->db->prepare("SELECT theme_key FROM mia_channel_drafts WHERE kind = ? AND week_start >= ? ORDER BY week_start DESC, id DESC");
            $s->execute([$kind, date('Y-m-d', strtotime('-' . (MiaSeasonThemes::REPEAT_WEEKS * 7) . ' days'))]);
            return array_values(array_unique($s->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Owner's first name + office phone, from business_settings (never hardcoded). */
    private function replyContext(): array
    {
        $phone = '';
        try {
            $phone = (string)$this->db->query("SELECT company_phone FROM business_settings WHERE id = 1")->fetchColumn();
        } catch (Throwable $e) {}
        return ['owner' => 'Tim', 'phone' => trim($phone)];
    }

    private function website(): string
    {
        try {
            $w = trim((string)$this->db->query("SELECT company_website FROM business_settings WHERE id = 1")->fetchColumn());
            if ($w !== '') return preg_match('#^https?://#', $w) ? $w : 'https://' . $w;
        } catch (Throwable $e) {}
        return defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
    }

    private function reviewRow(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM gbp_reviews WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function reviewField(int $id, string $field)
    {
        $r = $this->reviewRow($id);
        return $r[$field] ?? null;
    }

    private function count(string $sql): int
    {
        try {
            return (int)$this->db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function setting(string $key): string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            return (string)($s->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return '';
        }
    }

    private function setSetting(string $key, string $value): void
    {
        try {
            $this->db->prepare("
                INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, 'Mia channels: internal')
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute([$key, $value]);
        } catch (Throwable $e) {}
    }
}
