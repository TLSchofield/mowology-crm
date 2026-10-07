<?php
/**
 * MediaTagService — Mia keeps the media library tagged so she (and the email campaigns) can
 * pick the right photo. Rules are in MediaTagRules (pure, tested); this is the I/O.
 *
 *   onVisitPhoto()  hook from the crew upload (/crm/api/visit-photo-upload.php): copy the photo
 *                   into the library (media_assets + media_links job_visit) and tag it from
 *                   context. Never throws — an upload must never fail because of tagging.
 *   onUpload()      hook from MediaUploadService::mediaUploadFile(): tag a library upload.
 *   backfill()      cron: import + tag crew photos and library images not tagged yet.
 *   visionPass()    cron: Claude (claude-sonnet-5-5) looks at untagged photos — subject,
 *                   quality 1-5, and whether anything needs blurring (faces, house numbers,
 *                   licence plates, children, identifiable street fronts). Small daily cap
 *                   (ops_settings media_vision_daily_cap, default 40). Key: ANTHROPIC_API_KEY
 *                   in secrets.php. A blur-needed photo is never auto-picked for public use.
 *   setHero()       Tim's star (is_favorite → use/hero).
 *   setOptout()     a client's photo opt-out (contacts.photo_optout → consent/no on every
 *                   photo of their properties). Everyone else: "use them all" (Tim, 2026-10-06).
 *   markUsed()      each use: used/<yyyy-mm>-<channel>, usage_count + 1, a media_usage row
 *                   (what the post or campaign used — the learning reads it back).
 *   learn()         engagement of social posts → media_usage → media_tag_scores.
 *
 * Tables (migration 1202): media_tag_state, media_usage, media_tag_scores; contacts.photo_optout.
 * No namespace / no autoloader in production.
 */
require_once __DIR__ . '/MediaTagRules.php';

class MediaTagService
{
    public const VISION_MODEL = 'claude-sonnet-5-5';
    public const VISION_DAILY_CAP = 40;
    public const MAX_IMAGE_BYTES = 3_500_000;

    private PDO $db;
    /** @var callable|null test transport for the vision call: fn(array $body): array{code:int, body:string} */
    private $transport;

    public function __construct(PDO $db, ?callable $transport = null)
    {
        $this->db = $db;
        $this->transport = $transport;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'media_tag_state'")->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Hooks
    // ─────────────────────────────────────────────────────────────────────

    /** Crew upload hook. Safe to call from any upload path; swallows every error. */
    public static function onVisitPhoto(PDO $db, int $visitPhotoId): void
    {
        try {
            $svc = new self($db);
            if ($svc->ready()) $svc->importVisitPhoto($visitPhotoId);
        } catch (Throwable $e) {
            error_log('MediaTagService::onVisitPhoto ' . $visitPhotoId . ': ' . $e->getMessage());
        }
    }

    /** Library upload hook (MediaUploadService). */
    public static function onUpload(PDO $db, int $mediaId): void
    {
        try {
            $svc = new self($db);
            if ($svc->ready()) $svc->tagMedia($mediaId);
        } catch (Throwable $e) {
            error_log('MediaTagService::onUpload ' . $mediaId . ': ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Import + context tagging
    // ─────────────────────────────────────────────────────────────────────

    /** Copy one crew photo into the library (idempotent) and tag it. @return int media id (0 on failure) */
    public function importVisitPhoto(int $visitPhotoId): int
    {
        $s = $this->db->prepare("SELECT media_id FROM media_tag_state WHERE visit_photo_id = ?");
        $s->execute([$visitPhotoId]);
        $mid = (int)$s->fetchColumn();
        if ($mid) {
            $this->tagMedia($mid);
            return $mid;
        }
        $s = $this->db->prepare("SELECT * FROM visit_photos WHERE id = ?");
        $s->execute([$visitPhotoId]);
        $vp = $s->fetch(PDO::FETCH_ASSOC);
        if (!$vp || !empty($vp['deleted_at'])) return 0;

        $path = '/uploads/photos/' . $vp['filename'];
        $s = $this->db->prepare("SELECT id FROM media_assets WHERE file_path = ? ORDER BY id LIMIT 1");
        $s->execute([$path]);
        $mid = (int)$s->fetchColumn();
        if (!$mid) {
            $mid = $this->insertAsset($vp, $path);
            if (!$mid) return 0;
        }
        try {
            $l = $this->db->prepare("SELECT 1 FROM media_links WHERE media_id = ? AND context_type = 'job_visit' AND context_id = ?");
            $l->execute([$mid, (int)$vp['visit_id']]);
            if (!$l->fetchColumn()) {
                $this->db->prepare("INSERT INTO media_links (media_id, context_type, context_id, category, visibility, linked_by) VALUES (?, 'job_visit', ?, ?, 'internal', ?)")
                    ->execute([$mid, (int)$vp['visit_id'], (string)$vp['photo_type'], $vp['uploaded_by'] ?? null]);
            }
        } catch (Throwable $e) { /* media_links is optional for tagging */ }

        $this->db->prepare("INSERT INTO media_tag_state (media_id, visit_photo_id, visit_id) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE visit_photo_id = VALUES(visit_photo_id), visit_id = VALUES(visit_id)")
            ->execute([$mid, $visitPhotoId, (int)$vp['visit_id']]);
        $this->tagMedia($mid);
        return $mid;
    }

    private function insertAsset(array $vp, string $path): int
    {
        $by = (int)($vp['uploaded_by'] ?? 0) ?: (int)($this->db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
        $attempts = [
            ["INSERT INTO media_assets (original_filename, stored_filename, file_path, file_type, mime_type, file_size, created_by, context_type, captured_at, status)
              VALUES (?, ?, ?, 'image', ?, ?, ?, 'visit_photo', ?, 'ready')",
             [$vp['original_filename'] ?: $vp['filename'], $vp['filename'], $path, $vp['mime_type'] ?? 'image/jpeg', $vp['file_size'] ?? null, $by, $vp['uploaded_at'] ?? null]],
            // Older schema (what SocialDraftPipeline writes).
            ["INSERT INTO media_assets (filename, file_path, context_type, status, created_at) VALUES (?, ?, 'visit_photo', 'ready', NOW())",
             [$vp['filename'], $path]],
        ];
        foreach ($attempts as [$sql, $args]) {
            try {
                $this->db->prepare($sql)->execute($args);
                return (int)$this->db->lastInsertId();
            } catch (Throwable $e) {
                $last = $e->getMessage();
            }
        }
        error_log('MediaTagService: could not add visit photo ' . $vp['id'] . ' to the library: ' . ($last ?? ''));
        return 0;
    }

    /** (Re)write the context tags of one library item. @return string[] the tags */
    public function tagMedia(int $mediaId): array
    {
        $s = $this->db->prepare("SELECT * FROM media_assets WHERE id = ?");
        $s->execute([$mediaId]);
        $m = $s->fetch(PDO::FETCH_ASSOC);
        if (!$m) return [];
        $st = $this->db->prepare("SELECT * FROM media_tag_state WHERE media_id = ?");
        $st->execute([$mediaId]);
        $state = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        $ctx = [
            'service_types' => array_filter([(string)($m['service_type'] ?? '')]),
            'date' => ($m['captured_at'] ?? null) ?: ($m['created_at'] ?? null),
            'photo_type' => null, 'favorite' => !empty($m['is_favorite']),
        ];
        if ($state && !empty($state['visit_id'])) {
            $ctx = $this->visitContext((int)$state['visit_id'], (int)$state['visit_photo_id']) + $ctx;
        }
        $tags = MediaTagRules::merge(MediaTagRules::decode($m['tags_json'] ?? null), MediaTagRules::contextTags($ctx), MediaTagRules::CONTEXT_NS);
        $this->db->prepare("UPDATE media_assets SET tags_json = ? WHERE id = ?")->execute([json_encode($tags, JSON_UNESCAPED_SLASHES), $mediaId]);
        $this->db->prepare("INSERT INTO media_tag_state (media_id, tagged_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE tagged_at = NOW()")->execute([$mediaId]);
        return $tags;
    }

    /** Everything the rules need about a crew photo's visit. Address is never read. */
    private function visitContext(int $visitId, int $visitPhotoId): array
    {
        $v = null;
        foreach ([
            "SELECT jv.id, jv.completed_at, jp.id AS plan_id, jp.service_type, p.postal_code, p.city, p.latitude, p.longitude, p.property_type,
                    p.contact_id AS p_contact, jp.contact_id AS jp_contact
             FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id JOIN properties p ON p.id = jp.property_id WHERE jv.id = ?",
            "SELECT jv.id, jv.completed_at, jp.id AS plan_id, jp.service_type, p.postal_code, p.city, NULL AS latitude, NULL AS longitude, NULL AS property_type,
                    NULL AS p_contact, NULL AS jp_contact
             FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id JOIN properties p ON p.id = jp.property_id WHERE jv.id = ?",
        ] as $sql) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute([$visitId]);
                $v = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                break;
            } catch (Throwable $e) { /* try the narrower query */ }
        }
        if (!$v) return ['visit_id' => $visitId];

        $services = [];
        try {
            $s = $this->db->prepare("SELECT DISTINCT service_type FROM plan_line_items WHERE plan_id = ?");
            $s->execute([(int)$v['plan_id']]);
            $services = array_filter($s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {}
        if (!$services && !empty($v['service_type'])) $services = [$v['service_type']];

        $photoType = null;
        $vp = $this->db->prepare("SELECT photo_type, uploaded_at FROM visit_photos WHERE id = ?");
        $vp->execute([$visitPhotoId]);
        $vpr = $vp->fetch(PDO::FETCH_ASSOC) ?: [];
        $photoType = $vpr['photo_type'] ?? null;

        $pair = $this->db->prepare("SELECT SUM(photo_type = 'before') AS b, SUM(photo_type = 'after') AS a FROM visit_photos WHERE visit_id = ? AND deleted_at IS NULL");
        $pair->execute([$visitId]);
        $pr = $pair->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'service_types' => $services,
            'photo_type' => $photoType,
            'visit_id' => $visitId,
            'has_pair' => (int)($pr['b'] ?? 0) > 0 && (int)($pr['a'] ?? 0) > 0,
            'postal_code' => $v['postal_code'] ?? null, 'city' => $v['city'] ?? null,
            'lat' => $v['latitude'] ?? null, 'lng' => $v['longitude'] ?? null,
            'property_type' => $v['property_type'] ?? null,
            'optout' => $this->optedOut([(int)($v['p_contact'] ?? 0), (int)($v['jp_contact'] ?? 0)]),
        ] + (!empty($vpr['uploaded_at']) ? ['date_fallback' => $vpr['uploaded_at']] : []);
    }

    private function optedOut(array $contactIds): bool
    {
        $ids = array_values(array_filter($contactIds));
        if (!$ids) return false;
        try {
            $s = $this->db->prepare("SELECT MAX(photo_optout) FROM contacts WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $s->execute($ids);
            return (int)$s->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Import crew photos not yet in the library, then tag untagged library images. */
    public function backfill(int $limit = 200): array
    {
        $out = ['imported' => 0, 'tagged' => 0];
        try {
            $rows = $this->db->query("
                SELECT vp.id FROM visit_photos vp
                LEFT JOIN media_tag_state s ON s.visit_photo_id = vp.id
                WHERE s.media_id IS NULL AND vp.deleted_at IS NULL AND vp.photo_type IN ('before', 'after', 'during')
                ORDER BY vp.id DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $id) {
                if ($this->importVisitPhoto((int)$id)) $out['imported']++;
            }
        } catch (Throwable $e) {
            error_log('MediaTagService backfill (visit photos): ' . $e->getMessage());
        }
        try {
            $rows = $this->db->query("
                SELECT ma.id FROM media_assets ma
                LEFT JOIN media_tag_state s ON s.media_id = ma.id
                WHERE (s.tagged_at IS NULL) AND (ma.file_type = 'image' OR ma.file_type IS NULL)
                ORDER BY ma.id DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $id) {
                $this->tagMedia((int)$id);
                $out['tagged']++;
            }
        } catch (Throwable $e) {
            error_log('MediaTagService backfill (library): ' . $e->getMessage());
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Vision
    // ─────────────────────────────────────────────────────────────────────

    public const VISION_PROMPT = <<<'TXT'
You tag photos for a landscaping company's media library (Vancouver, BC). Crews take these on job sites, before and after the work.
Answer only from what is visible.
- subjects: which of lawn, beds, hedge, leaves, tree, hardscape, snow are a main subject (one to three).
- quality: 1-5 for use in marketing. 5 = sharp, well lit, the work is obvious and looks good. 3 = usable. 1 = blurry, dark, a thumb, or nothing to see.
- faces: any person's face recognisable. children: any child visible at all.
- house_numbers: a readable street or unit number. licence_plates: a readable plate.
- identifiable_street_front: the front of a house or building shown so that someone could recognise the address from the street.
TXT;

    public static function visionSchema(): array
    {
        $b = ['type' => 'boolean'];
        return [
            'type' => 'object',
            'properties' => [
                'subjects' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => MediaTagRules::SUBJECTS]],
                'quality' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5]],
                'faces' => $b, 'children' => $b, 'house_numbers' => $b, 'licence_plates' => $b, 'identifiable_street_front' => $b,
            ],
            'required' => ['subjects', 'quality', 'faces', 'children', 'house_numbers', 'licence_plates', 'identifiable_street_front'],
            'additionalProperties' => false,
        ];
    }

    /** Same request shape as ReceiptBookkeeperService (json_schema output). */
    public static function buildVisionRequest(array $image): array
    {
        return [
            'model' => self::VISION_MODEL,
            'max_tokens' => 1000,
            'system' => [['type' => 'text', 'text' => self::VISION_PROMPT, 'cache_control' => ['type' => 'ephemeral']]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::visionSchema()]],
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['media_type'], 'data' => $image['data']]],
                ['type' => 'text', 'text' => 'Tag this photo.'],
            ]]],
        ];
    }

    /** @return array{done: int, failed: int, cap_left: int} */
    public function visionPass(int $limit = 20): array
    {
        $out = ['done' => 0, 'failed' => 0, 'cap_left' => 0];
        if (!$this->transport && (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY)) return $out;
        $cap = (int)($this->setting('media_vision_daily_cap') ?: self::VISION_DAILY_CAP);
        $used = (int)$this->db->query("SELECT COUNT(*) FROM media_tag_state WHERE vision_at >= CURDATE()")->fetchColumn();
        $left = max(0, $cap - $used);
        $out['cap_left'] = $left;
        if ($left === 0) return $out;

        // Before/after crew photos first (they're what campaigns want), newest first.
        $rows = $this->db->query("
            SELECT s.media_id, ma.file_path, ma.tags_json, vp.view_path
            FROM media_tag_state s
            JOIN media_assets ma ON ma.id = s.media_id
            LEFT JOIN visit_photos vp ON vp.id = s.visit_photo_id
            WHERE s.vision_at IS NULL AND (s.vision_tries IS NULL OR s.vision_tries < 3)
            ORDER BY (s.visit_photo_id IS NULL), s.media_id DESC
            LIMIT " . (int)min($limit, $left))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $image = $this->loadImage([(string)($r['view_path'] ?? ''), (string)$r['file_path']]);
            if (!$image) {
                $this->visionFailed((int)$r['media_id'], 'image file not found');
                $out['failed']++;
                continue;
            }
            $resp = $this->send(self::buildVisionRequest($image));
            $json = json_decode($resp['body'], true) ?: [];
            $text = '';
            foreach ($json['content'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'text') $text .= $c['text'];
            }
            $v = json_decode($text, true);
            if ($resp['code'] !== 200 || !is_array($v)) {
                $this->visionFailed((int)$r['media_id'], 'HTTP ' . $resp['code'] . ' ' . mb_substr((string)($json['error']['message'] ?? $text), 0, 150));
                $out['failed']++;
                continue;
            }
            $tags = MediaTagRules::merge(MediaTagRules::decode($r['tags_json']), MediaTagRules::visionTags($v), MediaTagRules::VISION_NS);
            $this->db->prepare("UPDATE media_assets SET tags_json = ? WHERE id = ?")->execute([json_encode($tags, JSON_UNESCAPED_SLASHES), (int)$r['media_id']]);
            $this->db->prepare("UPDATE media_tag_state SET vision_at = NOW(), vision_json = ?, vision_error = NULL,
                                vision_tokens = ? WHERE media_id = ?")
                ->execute([json_encode($v), (int)($json['usage']['input_tokens'] ?? 0) + (int)($json['usage']['output_tokens'] ?? 0), (int)$r['media_id']]);
            $out['done']++;
        }
        $out['cap_left'] = max(0, $left - $out['done'] - $out['failed']);
        return $out;
    }

    private function visionFailed(int $mediaId, string $why): void
    {
        $this->db->prepare("UPDATE media_tag_state SET vision_error = ?, vision_tries = COALESCE(vision_tries, 0) + 1,
                            vision_at = CASE WHEN COALESCE(vision_tries, 0) + 1 >= 3 THEN NOW() ELSE NULL END WHERE media_id = ?")
            ->execute([mb_substr($why, 0, 250), $mediaId]);
    }

    /** First readable path, downscaled when too big for the API. */
    private function loadImage(array $paths): ?array
    {
        foreach ($paths as $p) {
            if ($p === '') continue;
            $abs = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '') . '/' . ltrim($p, '/');
            if (!is_file($abs)) continue;
            $type = (string)(mime_content_type($abs) ?: '');
            if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) continue;
            if (filesize($abs) <= self::MAX_IMAGE_BYTES) {
                return ['media_type' => $type, 'data' => base64_encode((string)file_get_contents($abs))];
            }
            if (!extension_loaded('gd')) continue;
            $src = @imagecreatefromstring((string)file_get_contents($abs));
            if (!$src) continue;
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = 1568 / max($w, $h);
            $dst = imagescale($src, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
            ob_start();
            imagejpeg($dst ?: $src, null, 82);
            $data = (string)ob_get_clean();
            return ['media_type' => 'image/jpeg', 'data' => base64_encode($data)];
        }
        return null;
    }

    private function send(array $body): array
    {
        if ($this->transport) return ($this->transport)($body);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$raw];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tim's controls
    // ─────────────────────────────────────────────────────────────────────

    public function setHero(int $mediaId, bool $on): array
    {
        $this->db->prepare("UPDATE media_assets SET is_favorite = ? WHERE id = ?")->execute([$on ? 1 : 0, $mediaId]);
        return ['ok' => true, 'tags' => $this->tagMedia($mediaId)];
    }

    /** @return array{ok: bool, retagged: int} */
    public function setOptout(int $contactId, bool $optout): array
    {
        $this->db->prepare("UPDATE contacts SET photo_optout = ? WHERE id = ?")->execute([$optout ? 1 : 0, $contactId]);
        $ids = [];
        foreach ([
            "SELECT s.media_id FROM media_tag_state s JOIN job_visits jv ON jv.id = s.visit_id JOIN job_plans jp ON jp.id = jv.plan_id
             JOIN properties p ON p.id = jp.property_id WHERE p.contact_id = ? OR jp.contact_id = ?",
            "SELECT s.media_id FROM media_tag_state s JOIN job_visits jv ON jv.id = s.visit_id JOIN job_plans jp ON jp.id = jv.plan_id
             WHERE jp.contact_id = ? OR jp.contact_id = ?",
        ] as $sql) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute([$contactId, $contactId]);
                $ids = $s->fetchAll(PDO::FETCH_COLUMN);
                break;
            } catch (Throwable $e) {}
        }
        foreach ($ids as $id) $this->tagMedia((int)$id);
        return ['ok' => true, 'retagged' => count($ids)];
    }

    /** Record one use: tag, usage_count, and a media_usage row for the learning. */
    public function markUsed(int $mediaId, string $channel, string $refType, int $refId, ?DateTimeImmutable $day = null): void
    {
        $day = $day ?? new DateTimeImmutable('today');
        $s = $this->db->prepare("SELECT tags_json FROM media_assets WHERE id = ?");
        $s->execute([$mediaId]);
        $tags = MediaTagRules::decode($s->fetchColumn() ?: null);
        $tags = array_values(array_unique(array_merge($tags, [MediaTagRules::usedTag($day, $channel)])));
        sort($tags);
        $this->db->prepare("UPDATE media_assets SET tags_json = ?, usage_count = COALESCE(usage_count, 0) + 1 WHERE id = ?")
            ->execute([json_encode($tags, JSON_UNESCAPED_SLASHES), $mediaId]);
        $this->db->prepare("INSERT INTO media_usage (media_id, channel, ref_type, ref_id, combo, used_at) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$mediaId, $channel, $refType, $refId, MediaTagRules::combo($tags), $day->format('Y-m-d')]);
    }

    /**
     * Engagement of the social posts Mia's photos were used in → media_usage → media_tag_scores.
     * Campaign senders can write media_usage.clicks themselves; this folds both in.
     */
    public function learn(): int
    {
        try {
            $this->db->exec("
                UPDATE media_usage u
                JOIN (SELECT pp.post_id, SUM(m.likes + m.comments_count + m.shares + m.saves) AS eng, SUM(m.clicks) AS clicks
                      FROM social_metrics_daily m JOIN social_post_platforms pp ON pp.id = m.post_platform_id GROUP BY pp.post_id) x
                  ON x.post_id = u.ref_id
                SET u.engagement = x.eng, u.clicks = GREATEST(u.clicks, x.clicks)
                WHERE u.ref_type = 'social_post'
            ");
        } catch (Throwable $e) { /* no metrics yet */ }
        $rows = $this->db->query("SELECT combo, COUNT(*) AS uses, SUM(engagement) AS engagement, SUM(clicks) AS clicks FROM media_usage GROUP BY combo")->fetchAll(PDO::FETCH_ASSOC);
        $scores = self::comboScores($rows);
        foreach ($rows as $r) {
            $this->db->prepare("INSERT INTO media_tag_scores (combo, uses, engagement, clicks, score) VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE uses = VALUES(uses), engagement = VALUES(engagement), clicks = VALUES(clicks), score = VALUES(score)")
                ->execute([$r['combo'], (int)$r['uses'], (int)$r['engagement'], (int)$r['clicks'], $scores[$r['combo']] ?? 0]);
        }
        return count($rows);
    }

    /**
     * Per-combo weight: (engagement + 3×clicks) per use, relative to the average, minus 1,
     * clamped to [-1, 2]. A combo needs 2 uses before it moves.
     * @return array<string, float>
     */
    public static function comboScores(array $rows): array
    {
        $per = [];
        foreach ($rows as $r) {
            if ((int)$r['uses'] < 2) continue;
            $per[$r['combo']] = ((float)$r['engagement'] + 3 * (float)$r['clicks']) / (int)$r['uses'];
        }
        if (!$per) return [];
        $avg = array_sum($per) / count($per);
        if ($avg <= 0) return array_map(fn() => 0.0, $per);
        return array_map(fn($v) => round(max(-1.0, min(2.0, $v / $avg - 1.0)), 2), $per);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Library view + Otto
    // ─────────────────────────────────────────────────────────────────────

    /** Tagged library items for the Media Library "Mia's tags" tab. */
    public function library(array $filter, int $limit = 60): array
    {
        $where = ["ma.tags_json IS NOT NULL"];
        $args = [];
        foreach (['service', 'season', 'stage'] as $ns) {
            $v = MediaTagRules::slug((string)($filter[$ns] ?? ''));
            if ($v === '') continue;
            // Tags are written with JSON_UNESCAPED_SLASHES (and MySQL's JSON type stores "a/b" canonically).
            $where[] = 'ma.tags_json LIKE ?';
            $args[] = '%"' . $ns . '/' . $v . '"%';
        }
        if (!empty($filter['hero'])) $where[] = 'ma.is_favorite = 1';
        $s = $this->db->prepare("SELECT ma.id, ma.file_path, ma.tags_json, ma.is_favorite, ma.usage_count, vp.thumb_path
                                 FROM media_assets ma
                                 LEFT JOIN media_tag_state s ON s.media_id = ma.id
                                 LEFT JOIN visit_photos vp ON vp.id = s.visit_photo_id
                                 WHERE " . implode(' AND ', $where) . " ORDER BY ma.id DESC LIMIT " . (int)$limit);
        $s->execute($args);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['tags'] = MediaTagRules::decode($r['tags_json']);
            unset($r['tags_json']);
        }
        return $rows;
    }

    /** Every tag value present, per namespace, for the filter dropdowns. */
    public function facets(): array
    {
        $out = ['service' => [], 'season' => [], 'stage' => []];
        try {
            foreach ($this->db->query("SELECT tags_json FROM media_assets WHERE tags_json IS NOT NULL ORDER BY id DESC LIMIT 2000")->fetchAll(PDO::FETCH_COLUMN) as $j) {
                foreach (MediaTagRules::decode($j) as $t) {
                    $ns = strstr($t, '/', true);
                    if (isset($out[$ns])) $out[$ns][substr($t, strlen($ns) + 1)] = true;
                }
            }
        } catch (Throwable $e) {}
        return array_map(function ($v) { $k = array_keys($v); sort($k); return $k; }, $out);
    }

    /**
     * Otto: visits completed in the last 7 days with no photos at all (priority 3 each, max 5).
     * Read-only; any error → no items.
     */
    public static function missingPhotoItems(PDO $db, string $today): array
    {
        try {
            $s = $db->prepare("
                SELECT jv.id, jv.visit_number, jv.completed_at, jp.service_type
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                WHERE jv.status = 'completed' AND jv.completed_at >= ? AND jv.completed_at < ?
                  AND NOT EXISTS (SELECT 1 FROM visit_photos vp WHERE vp.visit_id = jv.id AND vp.deleted_at IS NULL)
                ORDER BY jv.completed_at DESC LIMIT 5
            ");
            $s->execute([date('Y-m-d', strtotime($today . ' -7 days')), date('Y-m-d', strtotime($today . ' +1 day'))]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $label = trim((string)($r['visit_number'] ?: ('visit #' . $r['id'])));
                $svc = trim((string)$r['service_type']);
                $out[] = ['key' => 'otto:no-photos:' . $r['id'], 'kind' => 'no_photos',
                    'text' => $label . ($svc !== '' ? ' (' . $svc . ')' : '') . ' was completed with no photos. Ask the crew to add the before and after.',
                    'url' => '/crm/jobs/visit-detail.php?id=' . (int)$r['id'], 'priority' => 3, 'value' => null,
                    'since' => substr((string)$r['completed_at'], 0, 10)];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
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
}
