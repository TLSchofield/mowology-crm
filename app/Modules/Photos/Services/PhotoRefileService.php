<?php
/**
 * PhotoRefileService — find visit photos filed against the wrong property, and move them (office approves).
 *
 * A visit photo belongs to whichever visit was open at upload (media_links job_visit → plan → property).
 * Nothing used to compare the photo's own GPS with that property. Measured on prod 2026-11-19: of 549 trustworthy
 * GPS photos, none sat between 150 and 500 m from their property, 19 sat beyond 500 m (the open-timer-at-the-previous-
 * stop case), so the rule below has a wide, clean gap to sit in. Plan: docs/plans/ittai-backlog.md.
 *
 * Rule (classify): only judge a photo whose fix is trustworthy — EXIF fix, or a browser fix taken on/after 2026-11-02
 * (before that one fix was reused all shift), and accuracy known and <= 50 m. WRONG = outside the filed property's
 * arrival border AND more than 250 m from its point. Target property = the one other property whose arrival border
 * holds the point (two or more = ambiguous, no suggestion).
 *
 * Flag only: move() runs when the office approves one group; every move is logged and undo() puts the link back.
 * A photo already used in a portfolio before/after pair (ba_pairs) is never moved.
 *
 * No autoloader: require_once this file and `new PhotoRefileService($db)`.
 */
declare(strict_types=1);

$__geoModel = dirname(__DIR__, 2) . '/Geofence/Models/GeofenceModel.php';
if (!function_exists('geofencePointInPolygon') && is_file($__geoModel)) {
    require_once $__geoModel;
}
unset($__geoModel);

class PhotoRefileService
{
    public const MAX_ACCURACY_M      = 50.0;
    public const WRONG_DISTANCE_M    = 250.0;
    public const BROWSER_TRUSTED_FROM = '2026-11-02';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Metres between two points. Own copy: haversineDistance() is declared in two other files. */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r  = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng2 - $lng1);
        $a  = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Is this fix good enough to judge a photo by? */
    public static function isTrusted(array $photo): bool
    {
        $lat = $photo['gps_lat'] ?? null;
        $lng = $photo['gps_lng'] ?? null;
        if ($lat === null || $lng === null || ((float)$lat == 0.0 && (float)$lng == 0.0)) {
            return false;
        }
        $acc = $photo['gps_accuracy_m'] ?? null;
        if ($acc === null || $acc === '' || (float)$acc <= 0.0 || (float)$acc > self::MAX_ACCURACY_M) {
            return false;
        }
        $src = (string)($photo['gps_source'] ?? '');
        if ($src === 'exif' || $src === 'manual') {
            return $src === 'exif';   // a hand-placed pin says where the office put it, not where the photo was taken
        }
        if ($src === 'browser') {
            $when = (string)($photo['captured_at'] ?? '') !== '' ? (string)$photo['captured_at'] : (string)($photo['created_at'] ?? '');
            return $when !== '' && substr($when, 0, 10) >= self::BROWSER_TRUSTED_FROM;
        }
        return false;
    }

    /**
     * Judge one photo. Pure — no database.
     *
     * @param array $photo  gps_lat, gps_lng, gps_accuracy_m, gps_source, captured_at, created_at
     * @param array $filed  lat, lng, polygon (list of [lat,lng], may be empty), bbox (or null)
     * @param array $others list of [id, lat, lng, polygon, bbox] for every other property
     * @return array{verdict: string, reason: string, distance_m: ?float, candidates: int[]}
     *         verdict: ok | skip | wrong
     */
    public static function classify(array $photo, array $filed, array $others): array
    {
        if (!self::isTrusted($photo)) {
            return ['verdict' => 'skip', 'reason' => 'no trustworthy position', 'distance_m' => null, 'candidates' => []];
        }
        $lat = (float)$photo['gps_lat'];
        $lng = (float)$photo['gps_lng'];
        if (!isset($filed['lat'], $filed['lng'])) {
            return ['verdict' => 'skip', 'reason' => 'property has no coordinates', 'distance_m' => null, 'candidates' => []];
        }
        $d = self::metres($lat, $lng, (float)$filed['lat'], (float)$filed['lng']);

        $poly = $filed['polygon'] ?? [];
        if (count($poly) >= 3 && function_exists('geofencePointInPolygon')
            && geofencePointInPolygon($lat, $lng, $poly, $filed['bbox'] ?? null)) {
            return ['verdict' => 'ok', 'reason' => 'inside the property border', 'distance_m' => $d, 'candidates' => []];
        }
        if ($d <= self::WRONG_DISTANCE_M) {
            return ['verdict' => 'ok', 'reason' => 'near the property (edge noise)', 'distance_m' => $d, 'candidates' => []];
        }

        $cands = [];
        foreach ($others as $o) {
            $op = $o['polygon'] ?? [];
            if (count($op) >= 3 && function_exists('geofencePointInPolygon')
                && geofencePointInPolygon($lat, $lng, $op, $o['bbox'] ?? null)) {
                $cands[] = (int)$o['id'];
            }
        }
        return ['verdict' => 'wrong', 'reason' => 'far from the property it is filed to', 'distance_m' => $d, 'candidates' => $cands];
    }

    // ------------------------------------------------------------------ data

    /** @return array<int, array{lat: float, lng: float, polygon: array, bbox: ?array, name: string}> keyed by property id */
    private function loadProperties(): array
    {
        $props = [];
        $q = $this->db->query("SELECT id, property_name, address, latitude, longitude FROM properties
                               WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND latitude <> 0");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $props[(int)$p['id']] = [
                'id'      => (int)$p['id'],
                'lat'     => (float)$p['latitude'],
                'lng'     => (float)$p['longitude'],
                'polygon' => [],
                'bbox'    => null,
                'name'    => trim((string)($p['property_name'] ?: $p['address'])),
            ];
        }
        $b = $this->db->query("SELECT property_id, polygon_json, bbox_lat_min, bbox_lat_max, bbox_lng_min, bbox_lng_max
                               FROM job_geofences WHERE zone_type = 'arrival_border' ORDER BY drawn_at ASC");
        foreach ($b->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $pid = (int)$r['property_id'];
            if (!isset($props[$pid])) {
                continue;
            }
            $props[$pid]['polygon'] = json_decode((string)$r['polygon_json'], true) ?: [];
            $props[$pid]['bbox']    = [
                'lat_min' => (float)$r['bbox_lat_min'], 'lat_max' => (float)$r['bbox_lat_max'],
                'lng_min' => (float)$r['bbox_lng_min'], 'lng_max' => (float)$r['bbox_lng_max'],
            ];
        }
        return $props;
    }

    /**
     * Groups of wrong-looking photos, one group per visit (a crew leaves one visit open and photographs the next
     * property into it), newest first. Photos already moved or dismissed are left out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findGroups(int $sinceDays = 0, int $limit = 100): array
    {
        $props = $this->loadProperties();
        $sql = "SELECT ma.id AS media_id, (SELECT MIN(mv.file_path) FROM media_variants mv WHERE mv.media_id = ma.id AND mv.variant_type = 'thumb_square' AND mv.format = 'jpeg') AS thumb_path, ma.file_path, ma.gps_lat, ma.gps_lng, ma.gps_accuracy_m,
                       ma.gps_source, ma.captured_at, ma.created_at, ml.id AS link_id, ml.context_id AS visit_id,
                       ml.category, jv.scheduled_date, jp.property_id
                FROM media_links ml
                JOIN media_assets ma ON ma.id = ml.media_id AND ma.status != 'deleted'
                JOIN job_visits jv   ON jv.id = ml.context_id
                JOIN job_plans jp    ON jp.id = jv.plan_id
                WHERE ml.context_type = 'job_visit'
                  AND ma.gps_lat IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM photo_refile_log l WHERE l.media_id = ma.id AND l.undone_at IS NULL)";
        $args = [];
        if ($sinceDays > 0) {
            $sql .= " AND ma.created_at >= (NOW() - INTERVAL " . (int)$sinceDays . " DAY)";
        }
        $sql .= " ORDER BY ma.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);

        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $pid = (int)$r['property_id'];
            if (!isset($props[$pid])) {
                continue;
            }
            $others = $props;
            unset($others[$pid]);
            $v = self::classify($r, $props[$pid], array_values($others));
            if ($v['verdict'] !== 'wrong') {
                continue;
            }
            $vid = (int)$r['visit_id'];
            if (!isset($groups[$vid])) {
                $groups[$vid] = [
                    'visit_id'       => $vid,
                    'visit_date'     => $r['scheduled_date'],
                    'filed_property' => ['id' => $pid, 'name' => $props[$pid]['name']],
                    'photos'         => [],
                    'candidates'     => [],
                ];
            }
            $groups[$vid]['photos'][] = [
                'media_id'   => (int)$r['media_id'],
                'thumb'      => $r['thumb_path'] ?: $r['file_path'],
                'category'   => $r['category'],
                'distance_m' => (int)round((float)$v['distance_m']),
                'accuracy_m' => (float)$r['gps_accuracy_m'],
                'candidates' => $v['candidates'],
            ];
        }

        foreach ($groups as &$g) {
            // Suggest a property only when every photo in the visit agrees on exactly one.
            $sets = array_map(static fn($p) => $p['candidates'], $g['photos']);
            $common = $sets ? array_values(array_intersect(...$sets)) : [];
            if (count($common) === 1) {
                $tid = $common[0];
                $g['target_property'] = ['id' => $tid, 'name' => $props[$tid]['name']];
                $g['target_visits']   = $this->visitsOn($tid, (string)$g['visit_date']);
            } else {
                $g['target_property'] = null;
                $g['target_visits']   = [];
            }
            $g['blocked'] = $this->inPortfolioPair(array_column($g['photos'], 'media_id'));
        }
        unset($g);

        $out = array_values($groups);
        usort($out, static fn($a, $b) => strcmp((string)$b['visit_date'], (string)$a['visit_date']) ?: $b['visit_id'] <=> $a['visit_id']);
        return array_slice($out, 0, max(1, $limit));
    }

    /** @return array<int, array{id: int, label: string}> */
    private function visitsOn(int $propertyId, string $date): array
    {
        $q = $this->db->prepare("SELECT jv.id, jp.service_type FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                                 WHERE jp.property_id = ? AND jv.scheduled_date = ? ORDER BY jv.id");
        $q->execute([$propertyId, $date]);
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'label' => '#' . $r['id'] . ' ' . ($r['service_type'] ?? '')],
            $q->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param int[] $mediaIds */
    private function inPortfolioPair(array $mediaIds): bool
    {
        if (!$mediaIds) {
            return false;
        }
        $ph = implode(',', array_fill(0, count($mediaIds), '?'));
        $q  = $this->db->prepare("SELECT COUNT(*) FROM ba_pairs WHERE before_id IN ($ph) OR after_id IN ($ph)");
        $q->execute(array_merge($mediaIds, $mediaIds));
        return (int)$q->fetchColumn() > 0;
    }

    // --------------------------------------------------------------- actions

    /**
     * Move the listed photos of one visit onto another visit. Re-judges every photo on the server; refuses unless
     * each is still wrong, the target visit is on the one property its fix falls inside, and none is in a portfolio pair.
     *
     * @param int[] $mediaIds
     * @return array{moved: int, refused: array<int, string>}
     */
    public function move(int $fromVisitId, int $toVisitId, array $mediaIds, int $userId): array
    {
        $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));
        $res = ['moved' => 0, 'refused' => []];
        if (!$mediaIds || $fromVisitId < 1 || $toVisitId < 1 || $fromVisitId === $toVisitId) {
            return $res;
        }
        $props = $this->loadProperties();

        $vq = $this->db->prepare("SELECT jp.property_id, jv.scheduled_date FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id WHERE jv.id = ?");
        $vq->execute([$toVisitId]);
        $tv = $vq->fetch(PDO::FETCH_ASSOC) ?: [];
        $vq->execute([$fromVisitId]);
        $fv = $vq->fetch(PDO::FETCH_ASSOC) ?: [];
        $targetProp = (int)($tv['property_id'] ?? 0);
        $fromProp   = (int)($fv['property_id'] ?? 0);
        $sameDay    = isset($tv['scheduled_date'], $fv['scheduled_date']) && $tv['scheduled_date'] === $fv['scheduled_date'];
        if ($targetProp < 1 || $fromProp < 1 || !$sameDay || !isset($props[$fromProp])) {
            foreach ($mediaIds as $m) { $res['refused'][$m] = 'visit not found or not on the same day'; }
            return $res;
        }

        $this->db->beginTransaction();
        try {
            foreach ($mediaIds as $mid) {
                $q = $this->db->prepare("SELECT ma.gps_lat, ma.gps_lng, ma.gps_accuracy_m, ma.gps_source, ma.captured_at, ma.created_at,
                                                ml.id AS link_id
                                         FROM media_links ml JOIN media_assets ma ON ma.id = ml.media_id AND ma.status != 'deleted'
                                         WHERE ml.media_id = ? AND ml.context_type = 'job_visit' AND ml.context_id = ? LIMIT 1 FOR UPDATE");
                $q->execute([$mid, $fromVisitId]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if (!$r) { $res['refused'][$mid] = 'not on that visit'; continue; }
                if ($this->inPortfolioPair([$mid])) { $res['refused'][$mid] = 'used in a portfolio before/after pair'; continue; }
                $others = $props;
                unset($others[$fromProp]);
                $v = self::classify($r, $props[$fromProp], array_values($others));
                if ($v['verdict'] !== 'wrong') { $res['refused'][$mid] = 'no longer looks wrong'; continue; }
                if (!in_array($targetProp, $v['candidates'], true)) { $res['refused'][$mid] = 'photo was not taken at the target property'; continue; }

                $u = $this->db->prepare("UPDATE media_links SET context_id = ? WHERE id = ? AND context_id = ?");
                $u->execute([$toVisitId, (int)$r['link_id'], $fromVisitId]);
                if ($u->rowCount() < 1) { $res['refused'][$mid] = 'moved by someone else just now'; continue; }
                $this->db->prepare("INSERT INTO photo_refile_log
                        (media_id, link_id, action, from_visit_id, to_visit_id, moved_by, distance_m, accuracy_m, moved_at)
                        VALUES (?, ?, 'move', ?, ?, ?, ?, ?, NOW())")
                    ->execute([$mid, (int)$r['link_id'], $fromVisitId, $toVisitId, $userId,
                        (int)round((float)$v['distance_m']), (float)$r['gps_accuracy_m']]);
                $res['moved']++;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('PhotoRefileService::move: ' . $e->getMessage());
            throw $e;
        }
        return $res;
    }

    /** "These are fine" — stop listing them. Undo of a dismissal = undo() on its log row. */
    public function dismiss(array $mediaIds, int $userId): int
    {
        $n = 0;
        foreach (array_values(array_unique(array_filter(array_map('intval', $mediaIds)))) as $mid) {
            $this->db->prepare("INSERT INTO photo_refile_log (media_id, action, moved_by, moved_at) VALUES (?, 'dismiss', ?, NOW())")
                ->execute([$mid, $userId]);
            $n++;
        }
        return $n;
    }

    /** Put a moved photo back on its old visit (only if it is still where the move left it). */
    public function undo(int $logId, int $userId): bool
    {
        $q = $this->db->prepare("SELECT * FROM photo_refile_log WHERE id = ? AND undone_at IS NULL");
        $q->execute([$logId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ($row['action'] === 'move') {
            $u = $this->db->prepare("UPDATE media_links SET context_id = ? WHERE id = ? AND context_id = ?");
            $u->execute([(int)$row['from_visit_id'], (int)$row['link_id'], (int)$row['to_visit_id']]);
            if ($u->rowCount() < 1) {
                return false;   // someone moved it again; do not guess
            }
        }
        $this->db->prepare("UPDATE photo_refile_log SET undone_at = NOW(), undone_by = ? WHERE id = ?")->execute([$userId, $logId]);
        return true;
    }

    /** Recent moves for the "Undo" list. @return array<int, array<string, mixed>> */
    public function recentMoves(int $limit = 30): array
    {
        $q = $this->db->prepare("SELECT id, media_id, from_visit_id, to_visit_id, moved_by, moved_at, distance_m
                                 FROM photo_refile_log WHERE action = 'move' AND undone_at IS NULL
                                 ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
