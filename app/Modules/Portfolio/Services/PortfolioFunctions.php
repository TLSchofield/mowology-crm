<?php
/**
 * Portfolio, Media, and ROI module helpers
 *
 * All media queries operate on the unified media_assets + media_variants tables.
 * The legacy media_files / media_metadata tables are no longer used.
 *
 * Migrated from: public/crm/includes/portfolio-functions.php
 */

declare(strict_types=1);

/**
 * Extract EXIF data (timestamp, orientation, etc.)
 */
function extractExifData(string $filePath): array
{
    $exif = [
        'taken_at' => null,
        'orientation' => 1
    ];

    if (!function_exists('exif_read_data')) {
        return $exif;
    }

    try {
        $data = @exif_read_data($filePath);
        if ($data === false) {
            return $exif;
        }

        if (!empty($data['DateTime'])) {
            $dt = DateTime::createFromFormat('Y:m:d H:i:s', $data['DateTime']);
            if ($dt) {
                $exif['taken_at'] = $dt->format('Y-m-d H:i:s');
            }
        }

        if (!empty($data['Orientation'])) {
            $exif['orientation'] = (int)$data['Orientation'];
        }

    } catch (Throwable $e) {
        error_log("EXIF extraction error: " . $e->getMessage());
    }

    return $exif;
}

/**
 * Toggle favorite status on media_assets
 */
function toggleMediaFavorite(int $mediaId, int $userId): bool
{
    try {
        $db = getDB();

        $stmt = $db->prepare("SELECT is_favorite FROM media_assets WHERE id = ?");
        $stmt->execute([$mediaId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        $newStatus = $row['is_favorite'] ? 0 : 1;

        $upd = $db->prepare("UPDATE media_assets SET is_favorite = ? WHERE id = ?");
        $upd->execute([$newStatus, $mediaId]);

        return true;
    } catch (Throwable $e) {
        error_log("toggleMediaFavorite error: " . $e->getMessage());
        return false;
    }
}

/**
 * Approve/reject media (set status on media_assets)
 */
function approveMedia(int $mediaId, int $userId, bool $approved, ?string $reason = null): bool
{
    try {
        $db = getDB();
        $newStatus = $approved ? 'ready' : 'rejected';

        $stmt = $db->prepare("UPDATE media_assets SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $mediaId]);

        return true;
    } catch (Throwable $e) {
        error_log("approveMedia error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get recent uploaded media (for upload queue / Recent Uploads)
 * Reads from media_assets. Thumbnail comes from media_variants.
 * Excludes expense receipts — those belong in the Expenses module, not Portfolio.
 */
function getRecentUploads(int $limit = 20): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
                ma.id, ma.created_by, ma.file_path,
                ma.status, ma.is_favorite, ma.created_at AS uploaded_at,
                ma.alt_text, ma.caption, ma.context_type,
                u.full_name AS uploader_name,
                mv.file_path AS thumb_path
            FROM media_assets ma
            LEFT JOIN users u ON ma.created_by = u.id
            LEFT JOIN media_variants mv ON mv.media_id = ma.id AND mv.variant_type = 'thumb'
            WHERE (ma.context_type IS NULL OR ma.context_type != 'expense')
            ORDER BY ma.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("getRecentUploads error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get favorite media (for marketing shortlist)
 * Excludes expense receipts.
 */
function getFavoriteMedia(int $limit = 100): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
                ma.id, ma.file_path AS web_path, ma.alt_text,
                ma.caption AS title, ma.image_width AS width, ma.image_height AS height,
                ma.tags_json,
                mv.file_path AS thumb_path
            FROM media_assets ma
            LEFT JOIN media_variants mv ON mv.media_id = ma.id AND mv.variant_type = 'thumb'
            WHERE ma.is_favorite = 1
              AND (ma.context_type IS NULL OR ma.context_type != 'expense')
            ORDER BY ma.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("getFavoriteMedia error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get portfolio statistics — counts from media_assets + portfolio_projects
 * Excludes expense receipts from all media counts.
 */
function getPortfolioStats(): array
{
    $stats = [
        'total_media' => 0,
        'favorite_media' => 0,
        'approved_media' => 0,
        'pending_review' => 0,
        'portfolio_items' => 0,
        'published_items' => 0
    ];

    $excludeExpense = "AND (context_type IS NULL OR context_type != 'expense')";

    try {
        $db = getDB();

        // Total media (excluding receipts)
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM media_assets WHERE 1=1 {$excludeExpense}");
        $stats['total_media'] = (int)$stmt->fetch()['cnt'];

        // Favorites
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM media_assets WHERE is_favorite = 1 {$excludeExpense}");
        $stats['favorite_media'] = (int)$stmt->fetch()['cnt'];

        // Ready / approved
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM media_assets WHERE status = 'ready' {$excludeExpense}");
        $stats['approved_media'] = (int)$stmt->fetch()['cnt'];

        // Pending review (status = 'uploaded' or 'processing')
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM media_assets WHERE status NOT IN ('ready', 'rejected') {$excludeExpense}");
        $stats['pending_review'] = (int)$stmt->fetch()['cnt'];

        // Portfolio project items
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM portfolio_projects");
        $stats['portfolio_items'] = (int)$stmt->fetch()['cnt'];

        // Published portfolio items
        $stmt = $db->query("SELECT COUNT(*) AS cnt FROM portfolio_projects WHERE status = 'published'");
        $stats['published_items'] = (int)$stmt->fetch()['cnt'];

        return $stats;
    } catch (Throwable $e) {
        error_log("getPortfolioStats error: " . $e->getMessage());
        return $stats;
    }
}

/**
 * Get media with variant details
 */
function getMediaWithMetadata(int $mediaId): ?array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT ma.*,
                   mv.file_path AS thumb_path
            FROM media_assets ma
            LEFT JOIN media_variants mv ON mv.media_id = ma.id AND mv.variant_type = 'thumb'
            WHERE ma.id = ?
        ");
        $stmt->execute([$mediaId]);
        $media = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($media && $media['tags_json']) {
            $media['tags'] = json_decode($media['tags_json'], true);
        }

        return $media ?: null;
    } catch (Throwable $e) {
        error_log("getMediaWithMetadata error: " . $e->getMessage());
        return null;
    }
}

/**
 * Create or update visit photo set
 */
function setVisitPhotoSet(int $visitId, array $beforeIds = [], array $afterIds = [], array $proofIds = []): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO visit_photo_sets (visit_id, before_media_ids, after_media_ids, proof_media_ids)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                before_media_ids = VALUES(before_media_ids),
                after_media_ids = VALUES(after_media_ids),
                proof_media_ids = VALUES(proof_media_ids),
                updated_at = NOW()
        ");

        $stmt->execute([
            $visitId,
            json_encode($beforeIds, JSON_UNESCAPED_SLASHES),
            json_encode($afterIds, JSON_UNESCAPED_SLASHES),
            json_encode($proofIds, JSON_UNESCAPED_SLASHES)
        ]);

        return true;
    } catch (Throwable $e) {
        error_log("setVisitPhotoSet error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get visit photo set
 */
function getVisitPhotoSet(int $visitId): ?array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM visit_photo_sets WHERE visit_id = ?");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['before_media_ids'] = json_decode($row['before_media_ids'] ?? '[]', true);
            $row['after_media_ids'] = json_decode($row['after_media_ids'] ?? '[]', true);
            $row['proof_media_ids'] = json_decode($row['proof_media_ids'] ?? '[]', true);
        }

        return $row;
    } catch (Throwable $e) {
        error_log("getVisitPhotoSet error: " . $e->getMessage());
        return null;
    }
}
