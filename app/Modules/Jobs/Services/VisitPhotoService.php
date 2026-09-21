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
                   (SELECT mv.file_path FROM media_variants mv
                     WHERE mv.media_id = ma.id AND mv.variant_type = 'thumb_square'
                     ORDER BY mv.id ASC LIMIT 1) AS variant_thumb
            FROM media_links ml
            JOIN media_assets ma ON ma.id = ml.media_id
            WHERE ml.context_type = 'job_visit'
              AND ml.context_id = ?
              AND ml.category IN ($in)
            ORDER BY FIELD(ml.category, 'before', 'after') = 0, FIELD(ml.category, 'before', 'after'), ma.id ASC
        ");
        $stmt->execute(array_merge([$visitId], self::PROOF_TYPES));

        return array_map([self::class, 'shape'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
