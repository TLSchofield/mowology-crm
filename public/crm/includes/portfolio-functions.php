<?php
/**
 * /crm/includes/portfolio-functions.php
 * Shared helper functions for portfolio, media, and ROI modules
 */

declare(strict_types=1);

/**
 * Image Processing Helper
 * Attempts to optimize image using GD library or stores as-is
 */
class ImageProcessor
{
    private const MAX_WIDTH = 2000;
    private const JPEG_QUALITY = 85;
    private const THUMB_SIZE = 300;

    public static function process(string $sourcePath, string $outputDir): array
    {
        $result = [
            'web_path' => null,
            'thumb_path' => null,
            'width' => 0,
            'height' => 0,
            'error' => null
        ];

        // Check if GD is available
        if (!extension_loaded('gd')) {
            $result['error'] = 'GD library not available';
            return $result;
        }

        try {
            // Get original dimensions
            $info = getimagesize($sourcePath);
            if (!$info) {
                $result['error'] = 'Cannot read image';
                return $result;
            }

            list($origWidth, $origHeight) = $info;
            $result['width'] = $origWidth;
            $result['height'] = $origHeight;

            // Load image
            $image = self::loadImage($sourcePath, $info[2]);
            if (!$image) {
                $result['error'] = 'Cannot load image';
                return $result;
            }

            // Resize if needed
            if ($origWidth > self::MAX_WIDTH) {
                $newHeight = (int)($origHeight * (self::MAX_WIDTH / $origWidth));
                $resized = imagecreatetruecolor(self::MAX_WIDTH, $newHeight);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, self::MAX_WIDTH, $newHeight, $origWidth, $origHeight);
                imagedestroy($image);
                $image = $resized;
                $result['width'] = self::MAX_WIDTH;
                $result['height'] = $newHeight;
            }

            // Save web-optimized JPEG
            $webFilename = self::generateFilename('web');
            $webPath = $outputDir . '/' . $webFilename;
            if (!imagejpeg($image, $webPath, self::JPEG_QUALITY)) {
                $result['error'] = 'Cannot save web image';
                imagedestroy($image);
                return $result;
            }
            $result['web_path'] = '/uploads/media/web/' . $webFilename;

            // Try to save WebP variant (PHP 7.1+)
            if (function_exists('imagewebp')) {
                $webpFilename = str_replace('.jpg', '.webp', $webFilename);
                $webpPath = $outputDir . '/' . $webpFilename;
                @imagewebp($image, $webpPath, self::JPEG_QUALITY);
            }

            // Create thumbnail
            $thumbImage = imagecreatetruecolor(self::THUMB_SIZE, self::THUMB_SIZE);
            $aspect = $result['width'] / $result['height'];
            if ($aspect > 1) {
                // Landscape: crop width
                $srcW = (int)($result['height']);
                $srcH = $result['height'];
                $srcX = (int)(($result['width'] - $srcW) / 2);
                $srcY = 0;
            } else {
                // Portrait: crop height
                $srcW = $result['width'];
                $srcH = (int)($result['width']);
                $srcX = 0;
                $srcY = (int)(($result['height'] - $srcH) / 2);
            }
            imagecopyresampled($thumbImage, $image, 0, 0, $srcX, $srcY, self::THUMB_SIZE, self::THUMB_SIZE, $srcW, $srcH);

            $thumbFilename = self::generateFilename('thumb');
            $thumbPath = $outputDir . '/' . $thumbFilename;
            if (!imagejpeg($thumbImage, $thumbPath, 90)) {
                $result['error'] = 'Cannot save thumbnail';
                imagedestroy($image);
                imagedestroy($thumbImage);
                return $result;
            }
            $result['thumb_path'] = '/uploads/media/thumbs/' . $thumbFilename;

            imagedestroy($image);
            imagedestroy($thumbImage);

        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private static function loadImage(string $path, int $type)
    {
        return match($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : null,
            default => null,
        };
    }

    private static function generateFilename(string $variant): string
    {
        return $variant . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
    }
}

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

        // DateTime
        if (!empty($data['DateTime'])) {
            $dt = DateTime::createFromFormat('Y:m:d H:i:s', $data['DateTime']);
            if ($dt) {
                $exif['taken_at'] = $dt->format('Y-m-d H:i:s');
            }
        }

        // Orientation
        if (!empty($data['Orientation'])) {
            $exif['orientation'] = (int)$data['Orientation'];
        }

    } catch (Throwable $e) {
        error_log("EXIF extraction error: " . $e->getMessage());
    }

    return $exif;
}

/**
 * Create/update media file record
 */
function createMediaFile(
    int $userId,
    ?int $jobId,
    ?int $propertyId,
    string $originalPath,
    ?string $webPath = null,
    ?string $thumbPath = null,
    string $mime = 'image/jpeg',
    int $size = 0,
    int $width = 0,
    int $height = 0,
    ?string $takenAt = null
): ?int {
    try {
        $db = getDB();
        $checksum = hash_file('sha256', $_SERVER['DOCUMENT_ROOT'] . $originalPath);

        $stmt = $db->prepare("
            INSERT INTO media_files (
                owner_user_id, job_id, property_id,
                original_path, web_path, thumb_path,
                mime, size, width, height,
                taken_at, status, checksum
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ready', ?)
        ");

        $stmt->execute([
            $userId,
            $jobId,
            $propertyId,
            $originalPath,
            $webPath,
            $thumbPath,
            $mime,
            $size,
            $width,
            $height,
            $takenAt,
            $checksum
        ]);

        $mediaId = (int)$db->lastInsertId();

        // Log activity
        logMediaActivity($mediaId, $userId, 'uploaded', null);

        return $mediaId;
    } catch (Throwable $e) {
        error_log("createMediaFile error: " . $e->getMessage());
        return null;
    }
}

/**
 * Add metadata to media file
 */
function setMediaMetadata(
    int $mediaId,
    ?string $title,
    ?string $altText,
    ?string $caption,
    ?array $tags,
    ?string $serviceType,
    ?string $area,
    ?string $beforeAfterGroupId,
    bool $isBefore
): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO media_metadata (
                media_id, title, alt_text, caption, tags,
                service_type, area, before_after_group_id, is_before
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                alt_text = VALUES(alt_text),
                caption = VALUES(caption),
                tags = VALUES(tags),
                service_type = VALUES(service_type),
                area = VALUES(area),
                before_after_group_id = VALUES(before_after_group_id),
                is_before = VALUES(is_before),
                updated_at = NOW()
        ");

        $stmt->execute([
            $mediaId,
            $title,
            $altText,
            $caption,
            $tags ? json_encode($tags, JSON_UNESCAPED_SLASHES) : null,
            $serviceType,
            $area,
            $beforeAfterGroupId,
            $isBefore ? 1 : 0
        ]);

        return true;
    } catch (Throwable $e) {
        error_log("setMediaMetadata error: " . $e->getMessage());
        return false;
    }
}

/**
 * Toggle favorite status
 */
function toggleMediaFavorite(int $mediaId, int $userId): bool
{
    try {
        $db = getDB();

        // Get current status
        $stmt = $db->prepare("SELECT is_favorite FROM media_files WHERE id = ?");
        $stmt->execute([$mediaId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        $newStatus = $row['is_favorite'] ? 0 : 1;

        // Update
        $upd = $db->prepare("UPDATE media_files SET is_favorite = ? WHERE id = ?");
        $upd->execute([$newStatus, $mediaId]);

        // Log
        $action = $newStatus ? 'favorited' : 'unfavorited';
        logMediaActivity($mediaId, $userId, $action, null);

        return true;
    } catch (Throwable $e) {
        error_log("toggleMediaFavorite error: " . $e->getMessage());
        return false;
    }
}

/**
 * Approve/reject media
 */
function approveMedia(int $mediaId, int $userId, bool $approved, ?string $reason = null): bool
{
    try {
        $db = getDB();
        $newStatus = $approved ? 'ready' : 'rejected';

        $stmt = $db->prepare("UPDATE media_files SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $mediaId]);

        $action = $approved ? 'approved' : 'rejected';
        $details = $reason ? "Reason: $reason" : null;
        logMediaActivity($mediaId, $userId, $action, $details);

        return true;
    } catch (Throwable $e) {
        error_log("approveMedia error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get media with metadata
 */
function getMediaWithMetadata(int $mediaId): ?array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
                mf.*,
                mm.title, mm.alt_text, mm.caption, mm.tags,
                mm.service_type, mm.area, mm.before_after_group_id, mm.is_before
            FROM media_files mf
            LEFT JOIN media_metadata mm ON mf.id = mm.media_id
            WHERE mf.id = ?
        ");
        $stmt->execute([$mediaId]);
        $media = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($media && $media['tags']) {
            $media['tags'] = json_decode($media['tags'], true);
        }

        return $media;
    } catch (Throwable $e) {
        error_log("getMediaWithMetadata error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get recent uploaded media (for queue)
 */
function getRecentUploads(int $limit = 20): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
                mf.id, mf.owner_user_id, mf.job_id,
                mf.thumb_path, mf.status, mf.is_favorite, mf.uploaded_at,
                u.full_name as uploader_name,
                mm.title, mm.service_type
            FROM media_files mf
            LEFT JOIN users u ON mf.owner_user_id = u.id
            LEFT JOIN media_metadata mm ON mf.id = mm.media_id
            ORDER BY mf.uploaded_at DESC
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
 */
function getFavoriteMedia(int $limit = 100, ?string $serviceType = null, ?string $area = null): array
{
    try {
        $db = getDB();
        $where = ['mf.is_favorite = 1', 'mf.is_public = 0'];
        $params = [];

        if ($serviceType) {
            $where[] = 'mm.service_type = ?';
            $params[] = $serviceType;
        }

        if ($area) {
            $where[] = 'mm.area = ?';
            $params[] = $area;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT
                mf.id, mf.web_path, mf.thumb_path, mf.width, mf.height,
                mm.title, mm.alt_text, mm.service_type, mm.area, mm.tags
            FROM media_files mf
            LEFT JOIN media_metadata mm ON mf.id = mm.media_id
            WHERE $whereClause
            ORDER BY mf.uploaded_at DESC
            LIMIT ?
        ");

        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("getFavoriteMedia error: " . $e->getMessage());
        return [];
    }
}

/**
 * Log media activity
 */
function logMediaActivity(int $mediaId, int $userId, string $action, ?string $details): void
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO media_activity_log (media_id, user_id, action, details, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $mediaId,
            $userId,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        error_log("logMediaActivity error: " . $e->getMessage());
    }
}

/**
 * Create before/after pair
 */
function createBeforeAfterPair(int $beforeMediaId, int $afterMediaId, string $groupId = ''): bool
{
    if (!$groupId) {
        $groupId = 'pair_' . time() . '_' . bin2hex(random_bytes(4));
    }

    try {
        $db = getDB();

        // Update before
        $stmt = $db->prepare("
            UPDATE media_metadata
            SET before_after_group_id = ?, is_before = 1
            WHERE media_id = ?
        ");
        $stmt->execute([$groupId, $beforeMediaId]);

        // Update after
        $stmt = $db->prepare("
            UPDATE media_metadata
            SET before_after_group_id = ?, is_before = 0
            WHERE media_id = ?
        ");
        $stmt->execute([$groupId, $afterMediaId]);

        return true;
    } catch (Throwable $e) {
        error_log("createBeforeAfterPair error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get portfolio statistics
 */
function getPortfolioStats(): array
{
    try {
        $db = getDB();

        $stats = [
            'total_media' => 0,
            'favorite_media' => 0,
            'approved_media' => 0,
            'pending_review' => 0,
            'portfolio_items' => 0,
            'published_items' => 0
        ];

        // Total media
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM media_files");
        $stats['total_media'] = (int)$stmt->fetch()['cnt'];

        // Favorites
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM media_files WHERE is_favorite = 1");
        $stats['favorite_media'] = (int)$stmt->fetch()['cnt'];

        // Approved
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM media_files WHERE status = 'ready'");
        $stats['approved_media'] = (int)$stmt->fetch()['cnt'];

        // Pending
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM media_files WHERE status = 'uploaded'");
        $stats['pending_review'] = (int)$stmt->fetch()['cnt'];

        // Portfolio items
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM portfolio_curation");
        $stats['portfolio_items'] = (int)$stmt->fetch()['cnt'];

        // Published
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM portfolio_curation WHERE is_published = 1");
        $stats['published_items'] = (int)$stmt->fetch()['cnt'];

        return $stats;
    } catch (Throwable $e) {
        error_log("getPortfolioStats error: " . $e->getMessage());
        return [];
    }
}

/**
 * Create or update visit photo set
 */
function setVisitPhotoSet(int $jobId, array $beforeIds = [], array $afterIds = [], array $proofIds = []): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO visit_photo_sets (job_id, before_media_ids, after_media_ids, proof_media_ids)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                before_media_ids = VALUES(before_media_ids),
                after_media_ids = VALUES(after_media_ids),
                proof_media_ids = VALUES(proof_media_ids),
                updated_at = NOW()
        ");

        $stmt->execute([
            $jobId,
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
function getVisitPhotoSet(int $jobId): ?array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM visit_photo_sets WHERE job_id = ?");
        $stmt->execute([$jobId]);
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
