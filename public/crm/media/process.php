<?php
/**
 * /crm/media/process.php
 * Background image optimization processor
 * Can be called via cron or manually to process uploaded images
 * Generates web-optimized JPEG and thumbnail
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/portfolio-functions.php';

// Allow CLI or authenticated admin users
if (php_sapi_name() !== 'cli') {
    requireLogin();
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Admin access required']));
    }
}

$db = getDB();

try {
    // Get media files that need processing
    $stmt = $db->query("
        SELECT id, original_path, owner_user_id
        FROM media_files
        WHERE status = 'uploaded' AND web_path IS NULL
        ORDER BY uploaded_at ASC
        LIMIT 50
    ");
    $toProcess = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $processed = 0;
    $failed = 0;

    foreach ($toProcess as $media) {
        $mediaId = $media['id'];
        $originalPath = $_SERVER['DOCUMENT_ROOT'] . $media['original_path'];
        $userId = $media['owner_user_id'];

        if (!file_exists($originalPath)) {
            // Mark as failed
            $upd = $db->prepare("UPDATE media_files SET status = 'rejected' WHERE id = ?");
            $upd->execute([$mediaId]);
            logMediaActivity($mediaId, $userId, 'processing_failed', 'Original file not found');
            $failed++;
            continue;
        }

        // Create output directory
        $outputDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/media/web';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Process image
        $result = ImageProcessor::process($originalPath, $outputDir);

        if ($result['error']) {
            // Mark as failed
            $upd = $db->prepare("UPDATE media_files SET status = 'rejected' WHERE id = ?");
            $upd->execute([$mediaId]);
            logMediaActivity($mediaId, $userId, 'processing_failed', $result['error']);
            $failed++;
            continue;
        }

        // Create thumbnail directory
        $thumbDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/media/thumbs';
        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        // Update media record with paths
        $upd = $db->prepare("
            UPDATE media_files
            SET web_path = ?, thumb_path = ?, status = 'ready', width = ?, height = ?
            WHERE id = ?
        ");
        $upd->execute([
            $result['web_path'],
            $result['thumb_path'],
            $result['width'],
            $result['height'],
            $mediaId
        ]);

        logMediaActivity($mediaId, $userId, 'processed', 'Image optimized');
        $processed++;
    }

    // Return response (for web requests)
    if (php_sapi_name() !== 'cli') {
        die(json_encode([
            'success' => true,
            'processed' => $processed,
            'failed' => $failed,
            'message' => "Processed $processed images, $failed failed"
        ]));
    } else {
        echo "Processed $processed images, $failed failed\n";
    }

} catch (Throwable $e) {
    error_log("Media processing error: " . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
