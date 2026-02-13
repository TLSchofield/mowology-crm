<?php
/**
 * Media Helpers — Responsive Image Rendering & UI Utilities
 *
 * Provides renderResponsiveImage() for outputting <picture> elements
 * with AVIF > WebP > JPEG source priority, and renderMediaDropzone()
 * for embedding the upload component on any page.
 *
 * @package Mowology CRM
 * @subpackage Media
 */

declare(strict_types=1);

/**
 * Render a responsive <picture> element for a media asset.
 *
 * Sources are ordered: AVIF (highest priority) > WebP > JPEG (fallback).
 * Pulls variant data from the media_variants table.
 *
 * @param int         $mediaId  media_assets.id
 * @param string|null $alt      Alt text (falls back to media record)
 * @param string      $class    CSS class for the <img> tag
 * @param string      $sizes    Sizes attribute for responsive hints
 * @param bool        $lazy     Whether to add loading="lazy"
 * @return string HTML <picture> element, or empty string if not found
 */
function renderResponsiveImage(
    int $mediaId,
    ?string $alt = null,
    string $class = '',
    string $sizes = '(max-width: 768px) 100vw, 768px',
    bool $lazy = true
): string {
    $db = getDB();

    // Get media record
    $stmt = $db->prepare('SELECT file_path, alt_text, image_width, image_height FROM media_assets WHERE id = ?');
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$media) return '';

    // Get all responsive variants
    $vStmt = $db->prepare('
        SELECT format, width, height, file_path
        FROM media_variants
        WHERE media_id = ? AND variant_type = ?
        ORDER BY width ASC
    ');
    $vStmt->execute([$mediaId, 'responsive']);
    $variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by format
    $byFormat = [];
    foreach ($variants as $v) {
        $byFormat[$v['format']][] = $v;
    }

    $altText = h($alt ?? $media['alt_text'] ?? '');
    $originalPath = h($media['file_path']);
    $width = (int)($media['image_width'] ?? 800);
    $height = (int)($media['image_height'] ?? 600);
    $lazyAttr = $lazy ? ' loading="lazy" decoding="async"' : '';
    $classAttr = $class ? ' class="' . h($class) . '"' : '';
    $sizesAttr = h($sizes);

    $html = '<picture>';

    // AVIF sources (highest priority — smallest files)
    if (!empty($byFormat['avif'])) {
        $srcset = [];
        foreach ($byFormat['avif'] as $v) {
            $srcset[] = h($v['file_path']) . ' ' . $v['width'] . 'w';
        }
        $html .= '<source type="image/avif" srcset="' . implode(', ', $srcset) . '" sizes="' . $sizesAttr . '">';
    }

    // WebP sources
    if (!empty($byFormat['webp'])) {
        $srcset = [];
        foreach ($byFormat['webp'] as $v) {
            $srcset[] = h($v['file_path']) . ' ' . $v['width'] . 'w';
        }
        $html .= '<source type="image/webp" srcset="' . implode(', ', $srcset) . '" sizes="' . $sizesAttr . '">';
    }

    // JPEG fallback sources
    if (!empty($byFormat['jpeg'])) {
        $srcset = [];
        foreach ($byFormat['jpeg'] as $v) {
            $srcset[] = h($v['file_path']) . ' ' . $v['width'] . 'w';
        }
        $html .= '<source type="image/jpeg" srcset="' . implode(', ', $srcset) . '" sizes="' . $sizesAttr . '">';
    }

    // Fallback <img>
    $html .= '<img src="' . $originalPath . '" alt="' . $altText . '" width="' . $width . '" height="' . $height . '"' . $classAttr . $lazyAttr . '>';
    $html .= '</picture>';

    return $html;
}

/**
 * Render the media dropzone upload component HTML.
 *
 * This outputs a div that the media-uploader.js script initializes.
 * Include /crm/js/media-uploader.js on the page for it to work.
 *
 * @param string $contextType  Context type (job_visit, quote_visit, marketing_general, etc.)
 * @param int    $contextId    Context ID (0 for general)
 * @param string $category     Default category (before, after, during, etc.)
 * @param string $visibility   Default visibility (internal, client_visible, marketing_eligible)
 * @param array  $options      Extra options: maxSize, allowedTypes, showPowToggle, showGpsToggle
 * @return string HTML for the dropzone component
 */
function renderMediaDropzone(
    string $contextType = 'marketing_general',
    int $contextId = 0,
    string $category = '',
    string $visibility = 'internal',
    array $options = []
): string {
    $maxSize = $options['maxSize'] ?? 15 * 1024 * 1024;
    $allowedTypes = $options['allowedTypes'] ?? 'image/jpeg,image/png,image/webp';
    $showPowToggle = $options['showPowToggle'] ?? in_array($contextType, ['job_visit', 'quote_visit']);
    $showGpsToggle = $options['showGpsToggle'] ?? true;
    $csrf = generateCSRFToken();

    $maxSizeMB = round($maxSize / 1024 / 1024);

    $html = '
    <div class="mw-media-dropzone"
         data-context-type="' . h($contextType) . '"
         data-context-id="' . (int)$contextId . '"
         data-category="' . h($category) . '"
         data-visibility="' . h($visibility) . '"
         data-csrf="' . h($csrf) . '"
         data-upload-url="/crm/api/media-upload.php"
         data-max-size="' . (int)$maxSize . '"
         data-allowed-types="' . h($allowedTypes) . '">

        <div class="mw-dropzone-prompt">
            <i data-feather="upload-cloud" style="width:48px;height:48px;color:var(--mw-green);"></i>
            <p class="mt-2 mb-1">Drop photos here or
                <button type="button" class="btn btn-sm btn-outline-primary mw-dropzone-browse">Browse Files</button>
            </p>
            <p class="mb-0">
                <button type="button" class="btn btn-sm btn-outline-secondary mw-dropzone-camera">
                    <i data-feather="camera" style="width:14px;height:14px;"></i> Take Photo
                </button>
            </p>
            <small class="text-muted">JPEG, PNG, WebP &mdash; up to ' . $maxSizeMB . ' MB each</small>
        </div>';

    // Optional toggles row
    if ($showPowToggle || $showGpsToggle) {
        $html .= '<div class="mw-dropzone-options mt-2 text-left" style="font-size:0.85rem;">';
        if ($showGpsToggle) {
            $html .= '
            <label class="mr-3" style="cursor:pointer;">
                <input type="checkbox" class="mw-dropzone-gps-toggle" checked> Use my location
            </label>';
        }
        if ($showPowToggle) {
            $html .= '
            <label style="cursor:pointer;">
                <input type="checkbox" class="mw-dropzone-pow-toggle"> Add Proof-of-Work stamp
            </label>';
        }
        $html .= '</div>';
    }

    $html .= '
        <div class="mw-dropzone-queue"></div>

        <input type="file" class="mw-dropzone-input" multiple accept="' . h($allowedTypes) . '" style="display:none">
        <input type="file" class="mw-dropzone-camera-input" accept="image/*" capture="environment" style="display:none">
    </div>';

    return $html;
}

/**
 * Format bytes into human-readable string.
 */
function formatMediaBytes(int $bytes, int $precision = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float)$bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

/**
 * Get all context links for a media asset.
 */
function getMediaLinks(int $mediaId): array
{
    $db = getDB();
    $stmt = $db->prepare('
        SELECT ml.*, u.full_name AS linked_by_name
        FROM media_links ml
        LEFT JOIN users u ON u.id = ml.linked_by
        WHERE ml.media_id = ?
        ORDER BY ml.created_at DESC
    ');
    $stmt->execute([$mediaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a single media asset by ID with variant summary.
 */
function getMediaAssetById(int $mediaId): ?array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM media_assets WHERE id = ?');
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$media) return null;

    // Attach variant summary
    $vStmt = $db->prepare('
        SELECT format, COUNT(*) as count, COALESCE(SUM(file_size), 0) as total_bytes
        FROM media_variants WHERE media_id = ? GROUP BY format
    ');
    $vStmt->execute([$mediaId]);
    $media['variant_summary'] = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach thumb URL
    $tStmt = $db->prepare('SELECT file_path FROM media_variants WHERE media_id = ? AND variant_type = ? AND format = ? LIMIT 1');
    $tStmt->execute([$mediaId, 'thumb_square', 'jpeg']);
    $thumb = $tStmt->fetch(PDO::FETCH_ASSOC);
    $media['thumb_url'] = $thumb ? $thumb['file_path'] : null;

    // Attach PoW stamp URL
    $pStmt = $db->prepare('SELECT file_path FROM media_derivatives WHERE media_id = ? AND derivative_type = ? LIMIT 1');
    $pStmt->execute([$mediaId, 'pow_stamp']);
    $pow = $pStmt->fetch(PDO::FETCH_ASSOC);
    $media['pow_stamp_url'] = $pow ? $pow['file_path'] : null;

    return $media;
}
