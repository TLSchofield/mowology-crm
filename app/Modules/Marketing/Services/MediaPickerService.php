<?php
/**
 * MediaPickerService — Mia picks the photos for a post or a campaign.
 *
 * STABLE INTERFACE (the email campaign work calls this; see docs/crm/mia-channels.md):
 *
 *   (new MediaPickerService($pdo))->pick(?string $service, ?string $season, string $channel, int $n = 1): array
 *     $service  a service slug or free text ("spring-cleanup", "Hedge Trimming"); null = any
 *     $season   spring | summer | fall | winter; null = any
 *     $channel  email | instagram | facebook | gbp | web | social
 *     returns up to $n picks, best first:
 *       ['kind' => 'pair'|'single', 'media_id' => int (the after, or the single photo),
 *        'before' => ?variant, 'after' => ?variant, 'image' => variant (the after / single),
 *        'pair_id' => ?int (visit id), 'tags' => string[], 'score' => float]
 *     variant = ['media_id' => int, 'url' => '/_media/mia/…jpg', 'width' => int, 'height' => int]
 *   ->markUsed(array $pick, string $channel, string $refType, int $refId): void
 *     call once the pick is actually used (a draft saved, a campaign approved).
 *
 * Ranking: only photos that are safe for a public channel (privacy/ok from the vision pass AND
 * consent not 'no'), quality ≥ 4 (unknown quality is not picked), not used in the last 90 days.
 * Then: before/after pairs first, Tim's heroes, service match, season match, quality, and the
 * learned weight of the photo's service+subject+stage (media_tag_scores).
 *
 * Variants are made lazily with GD and recorded in media_variants (variant_type mia_<channel>):
 * email 1200 px wide (keeps its shape), Instagram 1080 square, Google post and Facebook 1200×900 (4:3).
 * No namespace / no autoloader in production.
 */
require_once __DIR__ . '/MediaTagRules.php';
require_once __DIR__ . '/MediaTagService.php';

class MediaPickerService
{
    public const RECENT_DAYS = 90;
    public const MIN_QUALITY = 4;
    /** channel => [width, height|null (keep aspect)] */
    public const SIZES = [
        'email' => [1200, null], 'web' => [1600, null],
        'instagram' => [1080, 1080], 'social' => [1080, 1080],
        'gbp' => [1200, 900], 'facebook' => [1200, 900],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function pick(?string $service, ?string $season, string $channel, int $n = 1): array
    {
        $channel = isset(self::SIZES[$channel]) ? $channel : 'web';
        try {
            $rows = $this->db->query("
                SELECT ma.id, ma.file_path, ma.tags_json, ma.is_favorite, ma.image_width, ma.image_height
                FROM media_assets ma
                WHERE ma.tags_json LIKE '%privacy/ok%'
                ORDER BY ma.id DESC LIMIT 1500
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $items = [];
        foreach ($rows as $r) {
            $items[] = ['id' => (int)$r['id'], 'file_path' => (string)$r['file_path'], 'tags' => MediaTagRules::decode($r['tags_json'])];
        }
        $lastUsed = [];
        try {
            $s = $this->db->prepare("SELECT media_id, MAX(used_at) FROM media_usage WHERE used_at >= ? GROUP BY media_id");
            $s->execute([date('Y-m-d', strtotime('-' . self::RECENT_DAYS . ' days'))]);
            $lastUsed = $s->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {}
        $scores = [];
        try {
            $scores = $this->db->query("SELECT combo, score FROM media_tag_scores")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {}

        $out = [];
        foreach (self::rank($items, $service, $season, array_map('floatval', $scores), array_map('strval', $lastUsed), $n) as $p) {
            $p['image'] = $this->variant($p['after_item'], $channel);
            if (!$p['image']) continue;
            $p['before'] = $p['before_item'] ? $this->variant($p['before_item'], $channel) : null;
            $p['after'] = $p['kind'] === 'pair' ? $p['image'] : null;
            unset($p['after_item'], $p['before_item']);
            $out[] = $p;
        }
        return $out;
    }

    /** Record that a pick was used (both photos of a pair). */
    public function markUsed(array $pick, string $channel, string $refType, int $refId): void
    {
        $tags = new MediaTagService($this->db);
        $ids = array_filter([(int)($pick['media_id'] ?? 0), (int)($pick['before']['media_id'] ?? 0)]);
        foreach (array_unique($ids) as $id) {
            try {
                $tags->markUsed($id, $channel, $refType, $refId);
            } catch (Throwable $e) {
                error_log('MediaPicker markUsed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Pure ranking.
     * @param array<int, array{id: int, file_path: string, tags: string[]}> $items
     * @param array<string, float> $comboScores
     * @param array<int, string> $lastUsed media id => Y-m-d (only uses inside RECENT_DAYS)
     * @return array<int, array{kind: string, media_id: int, pair_id: ?int, tags: string[], score: float, after_item: array, before_item: ?array}>
     */
    public static function rank(array $items, ?string $service, ?string $season, array $comboScores, array $lastUsed, int $n): array
    {
        $svc = $service !== null ? MediaTagRules::slug($service) : '';
        $ok = [];
        foreach ($items as $it) {
            $t = $it['tags'];
            if (!MediaTagRules::publicOk($t)) continue;
            if ((int)MediaTagRules::value($t, 'quality') < self::MIN_QUALITY) continue;
            if (isset($lastUsed[$it['id']])) continue;
            $ok[] = $it;
        }
        // Group pairs: a before and an after with the same pair/<visit>.
        $byPair = [];
        foreach ($ok as $it) {
            $pair = MediaTagRules::value($it['tags'], 'pair');
            if ($pair !== null) $byPair[$pair][MediaTagRules::value($it['tags'], 'stage')][] = $it;
        }
        $cands = [];
        $inPair = [];
        foreach ($byPair as $pair => $stages) {
            if (empty($stages['before']) || empty($stages['after'])) continue;
            $best = fn(array $list) => array_reduce($list, fn($c, $i) => $c === null || self::itemScore($i, $svc, $season, $comboScores) > self::itemScore($c, $svc, $season, $comboScores) ? $i : $c);
            $after = $best($stages['after']);
            $before = $best($stages['before']);
            $inPair[$after['id']] = $inPair[$before['id']] = true;
            $cands[] = ['kind' => 'pair', 'media_id' => $after['id'], 'pair_id' => (int)$pair, 'tags' => $after['tags'],
                        'score' => 5 + (self::itemScore($after, $svc, $season, $comboScores) + self::itemScore($before, $svc, $season, $comboScores)) / 2,
                        'after_item' => $after, 'before_item' => $before];
        }
        foreach ($ok as $it) {
            if (isset($inPair[$it['id']])) continue;
            $cands[] = ['kind' => 'single', 'media_id' => $it['id'], 'pair_id' => null, 'tags' => $it['tags'],
                        'score' => self::itemScore($it, $svc, $season, $comboScores), 'after_item' => $it, 'before_item' => null];
        }
        // A service was asked for and something matches it: only matching photos.
        if ($svc !== '') {
            $match = array_values(array_filter($cands, fn($c) => self::serviceMatch($c['tags'], $svc)));
            if ($match) $cands = $match;
        }
        usort($cands, fn($a, $b) => [$b['score'], $b['media_id']] <=> [$a['score'], $a['media_id']]);
        return array_slice($cands, 0, max(0, $n));
    }

    public static function itemScore(array $it, string $svc, ?string $season, array $comboScores): float
    {
        $t = $it['tags'];
        $s = 0.0;
        if (in_array('use/hero', $t, true)) $s += 3;
        if ($svc !== '' && self::serviceMatch($t, $svc)) $s += 4;
        if ($season && MediaTagRules::value($t, 'season') === $season) $s += 2;
        $s += (int)MediaTagRules::value($t, 'quality') - 3;
        $s += 2 * (float)($comboScores[MediaTagRules::combo($t)] ?? 0);
        return $s;
    }

    /** "hedge-trimming" matches service/hedge-trimming and service/hedge; "hedge" matches service/hedge-trimming. */
    public static function serviceMatch(array $tags, string $svc): bool
    {
        foreach (MediaTagRules::values($tags, 'service') as $v) {
            if ($v === $svc || strpos($v, $svc) !== false || strpos($svc, $v) !== false) return true;
        }
        return false;
    }

    /** Centre crop box for a target aspect ratio. @return array{0: int, 1: int, 2: int, 3: int} x, y, w, h */
    public static function cropBox(int $w, int $h, ?float $ratio): array
    {
        if (!$ratio || $w <= 0 || $h <= 0) return [0, 0, $w, $h];
        if ($w / $h > $ratio) {
            $cw = (int)round($h * $ratio);
            return [(int)(($w - $cw) / 2), 0, $cw, $h];
        }
        $ch = (int)round($w / $ratio);
        return [0, (int)(($h - $ch) / 2), $w, $ch];
    }

    /** Channel-sized copy of one photo, made on first use. */
    public function variant(array $item, string $channel): ?array
    {
        [$tw, $th] = self::SIZES[$channel] ?? self::SIZES['web'];
        $type = 'mia_' . $channel;
        try {
            $s = $this->db->prepare("SELECT file_path, width, height FROM media_variants WHERE media_id = ? AND variant_type = ? ORDER BY id DESC LIMIT 1");
            $s->execute([$item['id'], $type]);
            if ($v = $s->fetch(PDO::FETCH_ASSOC)) {
                return ['media_id' => $item['id'], 'url' => $v['file_path'], 'width' => (int)$v['width'], 'height' => (int)$v['height']];
            }
        } catch (Throwable $e) {}
        $root = defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '';
        $src = $root . '/' . ltrim($item['file_path'], '/');
        if (!is_file($src) || !extension_loaded('gd')) return null;
        $img = @imagecreatefromstring((string)file_get_contents($src));
        if (!$img) return null;
        $w = imagesx($img);
        $h = imagesy($img);
        [$x, $y, $cw, $ch] = self::cropBox($w, $h, $th ? $tw / $th : null);
        $dw = min($tw, $cw);
        $dh = $th ? (int)round($dw * $th / $tw) : (int)round($ch * $dw / $cw);
        $out = imagecreatetruecolor($dw, $dh);
        imagecopyresampled($out, $img, 0, 0, $x, $y, $dw, $dh, $cw, $ch);
        $rel = '/_media/mia/' . $item['id'] . '-' . $channel . '.jpg';
        if (!is_dir($root . '/_media/mia')) @mkdir($root . '/_media/mia', 0755, true);
        if (!imagejpeg($out, $root . $rel, 85)) return null;
        imagedestroy($out);
        imagedestroy($img);
        try {
            $this->db->prepare("INSERT INTO media_variants (media_id, variant_type, format, width, height, file_path, file_size, quality) VALUES (?, ?, 'jpeg', ?, ?, ?, ?, 85)
                                ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), height = VALUES(height), file_size = VALUES(file_size)")
                ->execute([$item['id'], $type, $dw, $dh, $rel, (int)filesize($root . $rel)]);
        } catch (Throwable $e) {}
        return ['media_id' => $item['id'], 'url' => $rel, 'width' => $dw, 'height' => $dh];
    }
}
