<?php
/**
 * Proof-of-Work Stamp Generator
 *
 * Creates a stamped derivative of a media asset with a semi-transparent
 * overlay band containing job/visit details: address, date, crew, service type,
 * GPS coords, and Mowology branding.
 *
 * Only meaningful for job_visit and quote_visit contexts.
 * NEVER modifies the original — creates a separate derivative file.
 *
 * @package Mowology CRM
 * @subpackage Media
 */

declare(strict_types=1);

/**
 * Generate a Proof-of-Work stamped derivative.
 *
 * @param int $mediaId    media_assets.id
 * @param string $contextType  'job_visit' or 'quote_visit'
 * @param int $contextId  The visit ID (job_visits.id or quote equivalent)
 * @return array ['success' => bool, 'derivative_path' => string|null, 'error' => string|null]
 */
function generatePowStamp(int $mediaId, string $contextType, int $contextId): array
{
    if (!extension_loaded('gd')) {
        return ['success' => false, 'derivative_path' => null, 'error' => 'GD library not available'];
    }

    $db = getDB();

    // Get media record
    $stmt = $db->prepare('SELECT * FROM media_assets WHERE id = ?');
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$media) {
        return ['success' => false, 'derivative_path' => null, 'error' => 'Media not found'];
    }

    $originalPath = PUBLIC_ROOT . $media['file_path'];
    if (!file_exists($originalPath)) {
        return ['success' => false, 'derivative_path' => null, 'error' => 'Original file not found'];
    }

    // Resolve context details (address, date, crew, service)
    $stampInfo = powResolveContextInfo($db, $contextType, $contextId, $media);

    // Load image
    $image = imagecreatefromstring(file_get_contents($originalPath));
    if (!$image) {
        return ['success' => false, 'derivative_path' => null, 'error' => 'Failed to load image'];
    }

    $imgW = imagesx($image);
    $imgH = imagesy($image);

    // --- Draw semi-transparent overlay band at bottom ---
    $bandHeight = max(60, (int)($imgH * 0.08)); // 8% of image height, minimum 60px
    $bandY = $imgH - $bandHeight;

    // Semi-transparent black overlay (~65% opaque)
    $overlay = imagecolorallocatealpha($image, 0, 0, 0, 45);
    imagefilledrectangle($image, 0, $bandY, $imgW, $imgH, $overlay);

    // Thin green accent line at top of band
    $green = imagecolorallocate($image, 45, 134, 89); // --mw-green #2D8659
    imagefilledrectangle($image, 0, $bandY, $imgW, $bandY + 2, $green);

    // White text
    $white = imagecolorallocate($image, 255, 255, 255);
    $lightGray = imagecolorallocate($image, 200, 200, 200);

    // Choose font size based on image width
    // GD built-in fonts: 1-5 (5 is largest at ~15px height)
    $fontSize = ($imgW >= 1200) ? 5 : (($imgW >= 800) ? 4 : 3);
    $smallFont = max(1, $fontSize - 1);

    $padding = 10;
    $lineHeight = ($fontSize === 5) ? 18 : (($fontSize === 4) ? 16 : 14);

    // Line 1: Address + Date (white, larger)
    $line1 = $stampInfo['address'];
    if (strlen($stampInfo['date']) > 0) {
        $line1 .= '  |  ' . $stampInfo['date'];
    }
    imagestring($image, $fontSize, $padding, $bandY + 8, $line1, $white);

    // Line 2: Service + Crew + Reference (light gray, smaller)
    $line2Parts = [];
    if (!empty($stampInfo['service'])) $line2Parts[] = $stampInfo['service'];
    if (!empty($stampInfo['crew'])) $line2Parts[] = $stampInfo['crew'];
    if (!empty($stampInfo['reference'])) $line2Parts[] = $stampInfo['reference'];
    $line2 = implode('  |  ', $line2Parts);
    imagestring($image, $smallFont, $padding, $bandY + 8 + $lineHeight, $line2, $lightGray);

    // Line 3 (right-aligned): GPS + "Mowology Proof of Work"
    $line3Parts = [];
    if (!empty($media['gps_lat']) && !empty($media['gps_lng'])) {
        $lat = number_format((float)$media['gps_lat'], 5);
        $lng = number_format((float)$media['gps_lng'], 5);
        $acc = !empty($media['gps_accuracy_m']) ? ' +/-' . round((float)$media['gps_accuracy_m']) . 'm' : '';
        $line3Parts[] = "GPS: {$lat}, {$lng}{$acc}";
    }
    $line3Parts[] = 'Mowology Proof of Work';
    $line3 = implode('  |  ', $line3Parts);

    // Right-align line 3
    $line3Width = strlen($line3) * imagefontwidth($smallFont);
    $line3X = max($padding, $imgW - $line3Width - $padding);
    imagestring($image, $smallFont, $line3X, $bandY + 8 + ($lineHeight * 2), $line3, $lightGray);

    // --- Save stamped derivative ---
    $yearMonth = date('Y/m', strtotime($media['created_at']));
    $stampDir = MEDIA_ROOT . '/stamped/' . $yearMonth;
    if (!is_dir($stampDir)) {
        mkdir($stampDir, 0755, true);
    }

    $stampFilename = $media['uuid'] . '_pow.jpg';
    $stampAbsPath = $stampDir . '/' . $stampFilename;
    $stampWebPath = '/_media/stamped/' . $yearMonth . '/' . $stampFilename;

    $saved = imagejpeg($image, $stampAbsPath, 92);
    imagedestroy($image);

    if (!$saved) {
        return ['success' => false, 'derivative_path' => null, 'error' => 'Failed to save stamped image'];
    }

    // --- Record derivative in database ---
    $stampData = json_encode($stampInfo);
    try {
        $insStmt = $db->prepare('
            INSERT INTO media_derivatives (media_id, derivative_type, file_path, file_size, width, height, stamp_data)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $insStmt->execute([
            $mediaId,
            'pow_stamp',
            $stampWebPath,
            filesize($stampAbsPath),
            $imgW,
            $imgH,
            $stampData,
        ]);

        // Mark media as PoW-stamped
        $db->prepare('UPDATE media_assets SET pow_stamp_applied = 1 WHERE id = ?')->execute([$mediaId]);
    } catch (PDOException $e) {
        error_log('PoW stamp DB error: ' . $e->getMessage());
    }

    return [
        'success' => true,
        'derivative_path' => $stampWebPath,
        'error' => null,
    ];
}

/**
 * Resolve context information for the PoW stamp from the database.
 *
 * For job_visit: follow visit → plan → property chain for address, crew, service.
 * For quote_visit: follow similar chain (quotes → properties).
 *
 * @return array ['address' => string, 'date' => string, 'crew' => string, 'service' => string, 'reference' => string]
 */
function powResolveContextInfo(PDO $db, string $contextType, int $contextId, array $media): array
{
    $info = [
        'address' => $media['address_text'] ?? 'Address not available',
        'date' => '',
        'crew' => '',
        'service' => '',
        'reference' => '',
    ];

    // Use captured_at from EXIF if available, otherwise created_at
    $dateVal = $media['captured_at'] ?? $media['created_at'];
    if ($dateVal) {
        $info['date'] = date('M j, Y g:ia', strtotime($dateVal));
    }

    if ($contextType === 'job_visit') {
        try {
            $stmt = $db->prepare('
                SELECT
                    jv.visit_number,
                    jv.scheduled_date,
                    jv.started_at,
                    jv.completed_at,
                    jp.service_type,
                    jp.plan_number,
                    p.address AS property_address,
                    u.full_name AS crew_name
                FROM job_visits jv
                LEFT JOIN job_plans jp ON jp.id = jv.plan_id
                LEFT JOIN properties p ON p.id = jp.property_id
                LEFT JOIN users u ON u.id = jv.assigned_crew_id
                WHERE jv.id = ?
            ');
            $stmt->execute([$contextId]);
            $visit = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($visit) {
                if (!empty($visit['property_address'])) {
                    $info['address'] = $visit['property_address'];
                }
                // Prefer actual visit time over EXIF
                $visitDate = $visit['started_at'] ?? $visit['completed_at'] ?? $visit['scheduled_date'];
                if ($visitDate) {
                    $info['date'] = date('M j, Y g:ia', strtotime($visitDate));
                }
                $info['crew'] = $visit['crew_name'] ?? '';
                $info['service'] = ucwords(str_replace('_', ' ', $visit['service_type'] ?? ''));
                $info['reference'] = $visit['visit_number'] ?? $visit['plan_number'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('PoW context resolution error (job_visit): ' . $e->getMessage());
        }
    } elseif ($contextType === 'quote_visit') {
        try {
            $stmt = $db->prepare('
                SELECT
                    q.quote_number,
                    q.created_at AS quote_date,
                    p.address AS property_address,
                    c.first_name, c.last_name
                FROM quotes q
                LEFT JOIN properties p ON p.id = q.property_id
                LEFT JOIN contacts c ON c.id = q.contact_id
                WHERE q.id = ?
            ');
            $stmt->execute([$contextId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($quote) {
                if (!empty($quote['property_address'])) {
                    $info['address'] = $quote['property_address'];
                }
                if (!empty($quote['quote_date'])) {
                    $info['date'] = date('M j, Y', strtotime($quote['quote_date']));
                }
                $info['reference'] = $quote['quote_number'] ?? '';
                $clientName = trim(($quote['first_name'] ?? '') . ' ' . ($quote['last_name'] ?? ''));
                if ($clientName) $info['crew'] = 'Client: ' . $clientName;
            }
        } catch (PDOException $e) {
            error_log('PoW context resolution error (quote_visit): ' . $e->getMessage());
        }
    }

    return $info;
}

/**
 * Get the PoW stamp derivative path for a media asset.
 */
function getPowStampUrl(int $mediaId): ?string
{
    $db = getDB();
    $stmt = $db->prepare('SELECT file_path FROM media_derivatives WHERE media_id = ? AND derivative_type = ? LIMIT 1');
    $stmt->execute([$mediaId, 'pow_stamp']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['file_path'] : null;
}
