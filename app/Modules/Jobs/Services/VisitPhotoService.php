<?php
declare(strict_types=1);

/**
 * VisitPhotoService — the photos a crew member has taken on one visit.
 *
 * Uploads go through the unified media service (media_assets + media_links with
 * context_type 'job_visit'); the category on the link is the photo type. The mobile
 * list endpoint used to read the legacy visit_photos table, which new uploads never
 * touch — so a phone that reopened a visit saw no photos and re-locked the After slot.
 *
 * Categories:
 *   before / after  — proof pair (also imply visit start / completion timestamps)
 *   additional      — extra proof photos, any number (salting and snow routinely need 4+);
 *                     same category the web PoW screen and the Salt report already use
 *   during / other  — legacy extras, listed alongside 'additional'
 *   issue           — recommendation photos; NOT proof, never listed here
 */
class VisitPhotoService
{
    /** Categories a phone may upload. */
    public const UPLOAD_TYPES = ['before', 'after', 'additional', 'during', 'issue', 'other'];

    /** Categories shown as proof photos, in display order. */
    public const PROOF_TYPES = ['before', 'after', 'additional', 'during', 'other'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function isUploadType(string $type): bool
    {
        return in_array($type, self::UPLOAD_TYPES, true);
    }

    /** 'during' and 'other' are extras too — the phone shows one "more photos" strip. */
    public static function normalizeType(string $category): string
    {
        return in_array($category, ['before', 'after'], true) ? $category : 'additional';
    }

    /**
     * One API row from a media row. thumb_url falls back to the full photo.
     *
     * @param array{id:int|string,category:?string,file_path:?string,thumb_path?:?string,variant_thumb?:?string,captured_at?:?string,created_at?:?string} $row
     */
    public static function shape(array $row): array
    {
        $full  = (string)($row['file_path'] ?? '');
        $thumb = (string)($row['variant_thumb'] ?? '') ?: ((string)($row['thumb_path'] ?? '') ?: $full);

        return [
            'id'         => (int)$row['id'],
            'photo_type' => self::normalizeType((string)($row['category'] ?? '')),
            'photo_url'  => $full,
            'thumb_url'  => $thumb,
            'taken_at'   => $row['captured_at'] ?? ($row['created_at'] ?? null),
        ];
    }

    /**
     * Proof photos for a visit: before, then after, then extras, oldest first within each.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForVisit(int $visitId): array
    {
        $in = implode(',', array_fill(0, count(self::PROOF_TYPES), '?'));
        $stmt = $this->db->prepare("
            SELECT ma.id, ml.category, ma.file_path, ma.thumb_path, ma.captured_at, ma.created_at,
                   u.full_name AS taken_by,
                   (SELECT mv.file_path FROM media_variants mv
                     WHERE mv.media_id = ma.id AND mv.variant_type = 'thumb_square'
                     ORDER BY mv.id ASC LIMIT 1) AS variant_thumb
            FROM media_links ml
            JOIN media_assets ma ON ma.id = ml.media_id
            LEFT JOIN users u ON u.id = COALESCE(ml.linked_by, ma.created_by)
            WHERE ml.context_type = 'job_visit'
              AND ml.context_id = ?
              AND ml.category IN ($in)
            ORDER BY FIELD(ml.category, 'before', 'after') = 0, FIELD(ml.category, 'before', 'after'), ma.id ASC
        ");
        $stmt->execute(array_merge([$visitId], self::PROOF_TYPES));

        // In-house list: carries who took each photo (several crew can shoot one visit).
        return array_map(static function (array $row): array {
            $name = trim((string)($row['taken_by'] ?? ''));
            return self::shape($row) + ['taken_by' => $name !== '' ? $name : null];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Photo history for the property a visit is at: earlier visits that have proof
     * photos, newest visit first, each with its photos oldest first. The visit being
     * looked at is left out — its photos are already on the card.
     *
     * Carries who took each photo. This is an IN-HOUSE record — the office needs to
     * know whose work it is looking at. Never reuse this payload for anything a
     * client can see; client-facing proof stays anonymous.
     *
     * @return array<int,array{visit_id:int,date:string,service:string,status:string,crew:string[],photos:array}>
     */
    public function historyForVisit(int $visitId, int $maxVisits = 12): array
    {
        $stmt = $this->db->prepare("
            SELECT jp.property_id FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE jv.id = ?
        ");
        $stmt->execute([$visitId]);
        $propertyId = (int)$stmt->fetchColumn();

        return $propertyId > 0 ? $this->historyForProperty($propertyId, $visitId, $maxVisits) : [];
    }

    /**
     * @return array<int,array{visit_id:int,date:string,service:string,status:string,crew:string[],photos:array}>
     */
    public function historyForProperty(int $propertyId, int $excludeVisitId = 0, int $maxVisits = 12): array
    {
        $maxVisits = max(1, min(50, $maxVisits));
        $in        = implode(',', array_fill(0, count(self::PROOF_TYPES), '?'));

        // Step 1: the most recent visits at this property that have any proof photo.
        $stmt = $this->db->prepare("
            SELECT jv.id, jv.scheduled_date, jv.completed_at, jv.status, jp.service_type, jp.title
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE jp.property_id = ?
              AND jv.id != ?
              AND EXISTS (
                  SELECT 1 FROM media_links ml
                  WHERE ml.context_type = 'job_visit'
                    AND ml.context_id = jv.id
                    AND ml.category IN ($in)
              )
            ORDER BY jv.scheduled_date DESC, jv.id DESC
            LIMIT $maxVisits
        ");
        $stmt->execute(array_merge([$propertyId, $excludeVisitId], self::PROOF_TYPES));
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$visits) {
            return [];
        }

        // Step 2: every proof photo on those visits, in one read.
        $visitIds = array_map(static fn (array $v): int => (int)$v['id'], $visits);
        $vIn      = implode(',', array_fill(0, count($visitIds), '?'));
        $stmt = $this->db->prepare("
            SELECT ml.context_id AS visit_id, ma.id, ml.category, ma.file_path, ma.thumb_path,
                   ma.captured_at, ma.created_at, u.full_name AS taken_by,
                   (SELECT mv.file_path FROM media_variants mv
                     WHERE mv.media_id = ma.id AND mv.variant_type = 'thumb_square'
                     ORDER BY mv.id ASC LIMIT 1) AS variant_thumb
            FROM media_links ml
            JOIN media_assets ma ON ma.id = ml.media_id
            LEFT JOIN users u ON u.id = COALESCE(ml.linked_by, ma.created_by)
            WHERE ml.context_type = 'job_visit'
              AND ml.context_id IN ($vIn)
              AND ml.category IN ($in)
            ORDER BY ma.id ASC
        ");
        $stmt->execute(array_merge($visitIds, self::PROOF_TYPES));

        $byVisit = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string)($row['taken_by'] ?? ''));
            $byVisit[(int)$row['visit_id']][] = self::shape($row) + ['taken_by' => $name !== '' ? $name : null];
        }

        $order = ['before' => 0, 'after' => 1, 'additional' => 2];
        $out   = [];
        foreach ($visits as $v) {
            $photos = $byVisit[(int)$v['id']] ?? [];
            usort($photos, static fn (array $a, array $b): int =>
                [$order[$a['photo_type']] ?? 9, $a['id']] <=> [$order[$b['photo_type']] ?? 9, $b['id']]);

            $out[] = [
                'visit_id' => (int)$v['id'],
                'date'     => substr((string)($v['completed_at'] ?: $v['scheduled_date']), 0, 10),
                'service'  => self::serviceLabel($v['title'] ?? null, $v['service_type'] ?? null),
                'status'   => (string)$v['status'],
                'crew'     => array_values(array_unique(array_filter(array_column($photos, 'taken_by')))),
                'photos'   => $photos,
            ];
        }
        return $out;
    }

    /** The plan's own title when it has one, else the service type made readable. */
    public static function serviceLabel(?string $title, ?string $serviceType): string
    {
        $title = trim((string)$title);
        if ($title !== '') {
            return $title;
        }
        $type = trim((string)$serviceType);
        return $type !== '' ? ucwords(str_replace('_', ' ', $type)) : 'Service';
    }
}
