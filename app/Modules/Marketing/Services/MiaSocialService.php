<?php
/**
 * MiaSocialService — Mia drafts social posts from real crew job photos, matched to the season.
 *
 * Each week she looks at visits completed in the last LOOKBACK_DAYS that have both a before and
 * an after photo (visit_photos) and no social draft yet, scores them against this season's
 * themes (MiaSeasonThemes) and the crew's heart (job_visits.is_flagged), and turns the best
 * MAX_PER_WEEK into drafts through the existing SocialDraftPipeline (which imports the photos
 * into media_assets and renders the before/after card). Then she rewrites the caption in Tim's
 * voice — a before/after and one specific sentence, no hashtag wall (product-marketing-context
 * §8) — and records the draft in mia_channel_drafts.
 *
 * Only visits whose before/after photos passed the vision privacy check (privacy/ok) and whose
 * client hasn't opted out (consent/ok) are used — MediaTagRules::visitPublicOk().
 *
 * Drafts only: status stays 'draft' in social_posts. Tim edits, approves or schedules in the
 * Social Post editor, and the existing publisher posts it once Facebook is reconnected.
 *
 * Learning: rankThemes() reads social_metrics_daily (views/reach/likes/comments/shares/saves —
 * the metrics fixed in MetaService) per service type and nudges themes that did well.
 * No namespace / no autoloader in production.
 */
require_once __DIR__ . '/MiaSeasonThemes.php';
require_once __DIR__ . '/MediaTagService.php';

class MiaSocialService
{
    public const LOOKBACK_DAYS = 21;
    public const MAX_PER_WEEK = 2;
    public const MAX_HASHTAGS = 3;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Visits worth a post, best first. */
    public function candidates(DateTimeImmutable $today, array $themes, int $limit = 10): array
    {
        $since = $today->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d');
        try {
            $s = $this->db->prepare("
                SELECT jv.id AS visit_id, jv.completed_at, jv.is_flagged, jp.service_type, p.city, p.postal_code,
                       SUM(vp.photo_type = 'before') AS befores, SUM(vp.photo_type = 'after') AS afters
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                JOIN properties p ON p.id = jp.property_id
                JOIN visit_photos vp ON vp.visit_id = jv.id AND vp.deleted_at IS NULL AND vp.photo_type IN ('before', 'after')
                WHERE jv.status = 'completed' AND jv.completed_at >= ? AND jv.social_draft_id IS NULL
                GROUP BY jv.id, jv.completed_at, jv.is_flagged, jp.service_type, p.city, p.postal_code
                HAVING befores > 0 AND afters > 0
                ORDER BY jv.completed_at DESC
                LIMIT 60
            ");
            $s->execute([$since]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Mia social candidates: ' . $e->getMessage());
            return [];
        }
        foreach ($rows as &$r) {
            [$r['score'], $r['theme']] = self::score($r, $themes);
        }
        unset($r);
        $rows = array_values(array_filter($rows, fn($r) => $r['theme'] !== null && $this->photosPublicOk((int)$r['visit_id'])));
        usort($rows, fn($a, $b) => [$b['score'], $b['completed_at']] <=> [$a['score'], $a['completed_at']]);
        return array_slice($rows, 0, $limit);
    }

    /**
     * The visit's before/after photos have been through the vision pass and are safe to publish
     * (no faces, house numbers, plates, children or street fronts; client hasn't opted out).
     * Unchecked photos are never auto-picked.
     */
    private function photosPublicOk(int $visitId): bool
    {
        try {
            $s = $this->db->prepare("SELECT ma.tags_json FROM media_tag_state t JOIN media_assets ma ON ma.id = t.media_id WHERE t.visit_id = ?");
            $s->execute([$visitId]);
            return MediaTagRules::visitPublicOk(array_map([MediaTagRules::class, 'decode'], $s->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * How good a visit is for this week's post. A visit that fits no theme in season scores 0
     * and gets no theme (Mia doesn't post leaf cleanups in May).
     * @return array{0: float, 1: ?array} [score, theme]
     */
    public static function score(array $visit, array $themes): array
    {
        foreach ($themes as $i => $t) {
            if (MiaSeasonThemes::matchesService($t, $visit['service_type'] ?? '')) {
                $s = 10 - $i;                                   // earlier theme = more time-sensitive
                if (!empty($visit['is_flagged'])) $s += 3;      // the crew hearted it
                $s += min(2, max(0, (int)($visit['afters'] ?? 0) - 1)) * 0.5;
                return [(float)$s, $t];
            }
        }
        return [0.0, null];
    }

    /** One honest, specific caption. No exclamation marks, no address. */
    public static function caption(array $theme, string $serviceLabel, string $neighbourhood): string
    {
        $svc = trim($serviceLabel) !== '' ? strtolower(trim($serviceLabel)) : 'the work';
        $where = trim($neighbourhood) !== '' ? ' in ' . trim($neighbourhood) : '';
        $text = 'Before and after: ' . $svc . $where . ' this week. ' . $theme['social_line'];
        return str_replace('!', '.', $text);
    }

    /** Keep the first few hashtags only. */
    public static function trimHashtags(string $tags): string
    {
        preg_match_all('/#[\p{L}\p{N}_]+/u', $tags, $m);
        return implode(' ', array_slice(array_unique($m[0]), 0, self::MAX_HASHTAGS));
    }

    /**
     * Engagement per post by service type → theme score (0..1.5) for MiaSeasonThemes::pick().
     * @param array<int, array{service_type: ?string, posts: int, reach: float, engagement: float}> $rows
     * @return array<string, float> theme key => score
     */
    public static function rankThemes(array $rows, array $allThemes): array
    {
        $rates = [];
        foreach ($rows as $r) {
            $posts = max(1, (int)$r['posts']);
            $reach = max(1.0, (float)$r['reach']);
            $rates[] = ['service' => $r['service_type'], 'rate' => (float)$r['engagement'] / $reach, 'posts' => $posts];
        }
        if (!$rates) return [];
        $avg = array_sum(array_column($rates, 'rate')) / count($rates);
        $out = [];
        foreach ($allThemes as $t) {
            foreach ($rates as $r) {
                if ($r['posts'] < 2 || $avg <= 0 || !MiaSeasonThemes::matchesService($t, $r['service'])) continue;
                $out[$t['key']] = max($out[$t['key']] ?? 0.0, round(min(1.5, max(0.0, ($r['rate'] / $avg) - 1.0)), 2));
            }
        }
        return array_filter($out, fn($v) => $v > 0);
    }

    /** Last 120 days of metrics per service type. */
    public function performance(): array
    {
        try {
            return $this->db->query("
                SELECT sp.service_type, COUNT(DISTINCT sp.id) AS posts, SUM(m.reach) AS reach,
                       SUM(m.likes + m.comments_count + m.shares + m.saves + m.clicks) AS engagement
                FROM social_metrics_daily m
                JOIN social_post_platforms pp ON pp.id = m.post_platform_id
                JOIN social_posts sp ON sp.id = pp.post_id
                WHERE m.metric_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)
                GROUP BY sp.service_type
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Best recent post (for the card): highest engagement, last 60 days. */
    public function bestRecent(): ?array
    {
        try {
            $r = $this->db->query("
                SELECT sp.id, sp.title, sp.service_type, SUM(m.reach) AS reach,
                       SUM(m.likes + m.comments_count + m.shares + m.saves) AS engagement
                FROM social_metrics_daily m
                JOIN social_post_platforms pp ON pp.id = m.post_platform_id
                JOIN social_posts sp ON sp.id = pp.post_id
                WHERE m.metric_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                GROUP BY sp.id, sp.title, sp.service_type
                ORDER BY engagement DESC, reach DESC LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * This week's drafts. Requires SocialDraftPipeline's dependencies; never throws.
     * @return int drafts created
     */
    public function draftWeek(DateTimeImmutable $today, MiaThemeSource $source): int
    {
        $week = $today->modify('monday this week')->format('Y-m-d');
        try {
            $s = $this->db->prepare("SELECT COUNT(*) FROM mia_channel_drafts WHERE kind = 'social' AND week_start = ?");
            $s->execute([$week]);
            $room = self::MAX_PER_WEEK - (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
        if ($room <= 0) return 0;

        $themes = $source->themesFor($today);
        if (!$themes) return 0;
        require_once dirname(__DIR__, 2) . '/Social/Services/SocialHashtagEngine.php';
        require_once dirname(__DIR__, 2) . '/Social/Services/SocialCardGenerator.php';
        require_once dirname(__DIR__, 2) . '/Social/Services/SocialDraftPipeline.php';

        $made = 0;
        foreach ($this->candidates($today, $themes) as $c) {
            if ($made >= $room) break;
            try {
                $postId = SocialDraftPipeline::triggerFromVisit((int)$c['visit_id'], $this->db);
                $p = $this->db->prepare("SELECT title, hashtags, neighborhood, service_type FROM social_posts WHERE id = ?");
                $p->execute([$postId]);
                $post = $p->fetch(PDO::FETCH_ASSOC) ?: [];
                $label = trim(explode(' — ', (string)($post['title'] ?? ''))[0]) ?: ucwords(str_replace(['_', '-'], ' ', (string)$c['service_type']));
                $caption = self::caption($c['theme'], $label, (string)($post['neighborhood'] ?? ''));
                $this->db->prepare("UPDATE social_posts SET caption = ?, hashtags = ? WHERE id = ? AND status = 'draft'")
                    ->execute([$caption, self::trimHashtags((string)($post['hashtags'] ?? '')), $postId]);
                $this->db->prepare("
                    INSERT INTO mia_channel_drafts (kind, theme_key, week_start, title, body, social_post_id, visit_id, status)
                    VALUES ('social', ?, ?, ?, ?, ?, ?, 'draft')
                ")->execute([$c['theme']['key'], $week, mb_substr((string)($post['title'] ?? $label), 0, 200), $caption, $postId, (int)$c['visit_id']]);
                // Record the photos this post uses (tag used/<month>-social, usage_count, media_usage).
                $tags = new MediaTagService($this->db);
                $m = $this->db->prepare("SELECT media_id FROM media_tag_state WHERE visit_id = ?");
                $m->execute([(int)$c['visit_id']]);
                foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                    $tags->markUsed((int)$mid, 'social', 'social_post', $postId, $today);
                }
                $made++;
            } catch (Throwable $e) {
                error_log('Mia social draft for visit ' . $c['visit_id'] . ': ' . $e->getMessage());
            }
        }
        return $made;
    }
}
