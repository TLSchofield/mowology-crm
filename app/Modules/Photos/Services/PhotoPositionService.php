<?php
/**
 * PhotoPositionService — where on the property each visit photo was taken.
 *
 * Visit photos live in media_assets (linked through media_links with
 * context_type = 'job_visit'). The crew upload already stores a position per
 * photo (gps_lat / gps_lng / gps_source = exif|browser). This service reads
 * those positions for the portal visit report's map and lets a manager
 * correct one (gps_source = 'manual') when the phone's fix landed on the
 * wrong tree, or place a photo that arrived with no position at all.
 *
 * No autoloader: require_once this file and `new PhotoPositionService($db)`.
 */
declare(strict_types=1);

class PhotoPositionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Photos linked to a visit, gallery order, with whatever position each has.
     * Rows carry `pin` (1-based, gallery order, only for photos with a position)
     * so the gallery badge and the map marker always agree.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForVisit(int $visitId): array
    {
        if ($visitId < 1) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT ma.id,
                   ma.file_path,
                   ml.category    AS photo_type,
                   ma.caption,
                   mv.file_path   AS thumb_path,
                   ma.created_at  AS uploaded_at,
                   ma.captured_at,
                   ma.gps_lat,
                   ma.gps_lng,
                   ma.gps_source
            FROM media_links ml
            JOIN media_assets ma ON ma.id = ml.media_id
                      AND ma.status != 'deleted'
            LEFT JOIN media_variants mv ON mv.media_id = ma.id
                      AND mv.variant_type = 'thumb_square'
                      AND mv.format = 'jpeg'
            WHERE ml.context_type = 'job_visit'
              AND ml.context_id = ?
            ORDER BY ma.created_at ASC, ma.id ASC
        ");
        $stmt->execute([$visitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return self::numberPins($rows);
    }

    /**
     * Assign pin numbers to the rows that have a usable position. Pure, so the
     * numbering rule is testable without a database.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function numberPins(array $rows): array
    {
        $n = 0;
        foreach ($rows as &$row) {
            $row['pin'] = null;
            if (self::hasPosition($row)) {
                $row['pin'] = ++$n;
            }
        }
        unset($row);
        return $rows;
    }

    /** @param array<string, mixed> $row */
    public static function hasPosition(array $row): bool
    {
        if (!isset($row['gps_lat'], $row['gps_lng'])) {
            return false;
        }
        $lat = (float)$row['gps_lat'];
        $lng = (float)$row['gps_lng'];
        if ($lat === 0.0 && $lng === 0.0) {
            return false; // null island — a zeroed column, not a place
        }
        return self::isValidCoordinate($lat, $lng);
    }

    public static function isValidCoordinate(float $lat, float $lng): bool
    {
        return is_finite($lat) && is_finite($lng)
            && $lat >= -90.0 && $lat <= 90.0
            && $lng >= -180.0 && $lng <= 180.0;
    }

    /**
     * Save a corrected or hand-placed position for one photo of one visit.
     * Only media actually linked to that visit can be moved, so a forged id
     * cannot re-pin someone else's photo. Returns false when nothing changed.
     */
    public function setPosition(int $mediaId, int $visitId, float $lat, float $lng): bool
    {
        if ($mediaId < 1 || $visitId < 1 || !self::isValidCoordinate($lat, $lng)) {
            return false;
        }
        if ($lat === 0.0 && $lng === 0.0) {
            return false;
        }

        $chk = $this->db->prepare("
            SELECT 1 FROM media_links
            WHERE media_id = ? AND context_type = 'job_visit' AND context_id = ?
            LIMIT 1
        ");
        $chk->execute([$mediaId, $visitId]);
        if (!$chk->fetchColumn()) {
            return false;
        }

        $upd = $this->db->prepare("
            UPDATE media_assets
               SET gps_lat = ?, gps_lng = ?, gps_accuracy_m = NULL, gps_source = 'manual'
             WHERE id = ?
        ");
        $upd->execute([round($lat, 8), round($lng, 8), $mediaId]);
        return $upd->rowCount() > 0;
    }

    /**
     * Save several positions in one call (the report's "Save pin positions").
     *
     * @param array<int, array{media_id?: mixed, lat?: mixed, lng?: mixed}> $positions
     * @return array{saved: int, rejected: int[]}  rejected = media ids refused
     */
    public function setPositions(int $visitId, array $positions): array
    {
        $saved = 0;
        $rejected = [];
        foreach ($positions as $p) {
            $mediaId = (int)($p['media_id'] ?? 0);
            if (!is_numeric($p['lat'] ?? null) || !is_numeric($p['lng'] ?? null)) {
                $rejected[] = $mediaId;
                continue;
            }
            if ($this->setPosition($mediaId, $visitId, (float)$p['lat'], (float)$p['lng'])) {
                $saved++;
            } else {
                $rejected[] = $mediaId;
            }
        }
        return ['saved' => $saved, 'rejected' => $rejected];
    }
}
