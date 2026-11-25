<?php
/**
 * Social Card Generator
 *
 * GD-based compositor for creating branded social posting cards.
 * All text rendering uses imagettftext() with Montserrat TTF fonts —
 * never imagestring() / built-in GD bitmap fonts.
 *
 * Supported card types (V1):
 *   before_after  — Left/right B&A split with lime divider, brand strip bottom
 *   hero_after    — Full-bleed after photo with gradient brand strip bottom
 *
 * V2 (future): multi_grid, engagement_showcase
 *
 * Output: 1080×1080 JPEG at quality 90
 * Stored: PUBLIC_ROOT/_media/social-cards/YYYY/MM/{uuid}_{type}.jpg
 * DB row: media_derivatives (derivative_type='social_card', post_id=...)
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class SocialCardGenerator
{
    // Canvas dimensions
    const CANVAS_W = 1080;
    const CANVAS_H = 1080;

    // Brand strip height at bottom of card
    const STRIP_H = 80;

    // JPEG quality
    const JPEG_Q = 90;

    // Font paths (relative to PUBLIC_ROOT)
    const FONT_BOLD    = '/assets/fonts/Montserrat-Bold.ttf';
    const FONT_SEMIBOLD = '/assets/fonts/Montserrat-SemiBold.ttf';
    const FONT_REGULAR = '/assets/fonts/Montserrat-Regular.ttf';

    // Logo path (relative to PUBLIC_ROOT)
    const LOGO_PATH = '/assets/img/logo/mowology-logo.jpg';

    /**
     * Generate a social card for the given post.
     *
     * @param int    $postId       social_posts.id
     * @param string $templateType 'before_after' | 'hero_after'
     * @param PDO    $db
     * @param array  $stats        Optional engagement stats for engagement_showcase (V2)
     * @return array ['success' => bool, 'derivative_id' => int|null, 'file_path' => string|null, 'error' => string|null]
     */
    public static function generate(int $postId, string $templateType, PDO $db, array $stats = []): array
    {
        if (!extension_loaded('gd')) {
            return self::err('GD library not available on this server.');
        }

        // Verify TTF fonts exist
        $fontBold    = PUBLIC_ROOT . self::FONT_BOLD;
        $fontSemi    = PUBLIC_ROOT . self::FONT_SEMIBOLD;
        $fontReg     = PUBLIC_ROOT . self::FONT_REGULAR;

        if (!file_exists($fontBold)) {
            return self::err('Montserrat-Bold.ttf not found. Deploy font files first.');
        }

        // Load post data
        $stmt = $db->prepare("
            SELECT sp.id, sp.service_type, sp.neighborhood, sp.city, sp.caption
            FROM social_posts sp
            WHERE sp.id = ?
        ");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post) {
            return self::err('Post not found: ' . $postId);
        }

        // Load attached media (before photos first, then after)
        $mediaStmt = $db->prepare("
            SELECT spm.sort_order, spm.photo_type, ma.file_path, ma.id AS media_asset_id
            FROM social_post_media spm
            JOIN media_assets ma ON ma.id = spm.media_id
            WHERE spm.post_id = ?
            ORDER BY spm.sort_order ASC
        ");
        $mediaStmt->execute([$postId]);
        $allMedia = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($allMedia)) {
            return self::err('No media attached to post ' . $postId . ' — cannot generate card.');
        }

        // Separate before / after / any
        $beforeMedia = [];
        $afterMedia  = [];
        foreach ($allMedia as $m) {
            $type = strtolower((string)($m['photo_type'] ?? ''));
            if ($type === 'before') {
                $beforeMedia[] = $m;
            } else {
                $afterMedia[] = $m;
            }
        }

        // Primary media_asset_id for the media_derivatives FK
        $primaryMediaId = (int)$allMedia[0]['media_asset_id'];

        // Load service label
        $serviceLabel = self::resolveServiceLabel($post['service_type'] ?? '', $db);
        $neighborhood = $post['neighborhood'] ?? '';
        $city         = $post['city'] ?? 'Vancouver';

        // Location string for brand strip
        $locationStr = trim($neighborhood . ($neighborhood ? ', ' : '') . $city);

        // Dispatch to card renderer
        switch ($templateType) {
            case 'before_after':
                $result = self::renderBeforeAfter($beforeMedia, $afterMedia, $serviceLabel, $locationStr, $fontBold, $fontSemi);
                break;
            case 'hero_after':
            default:
                $photoMedia = !empty($afterMedia) ? $afterMedia : $allMedia;
                $result = self::renderHeroAfter($photoMedia, $serviceLabel, $locationStr, $fontBold, $fontSemi, $fontReg);
                break;
        }

        if (!$result['success']) {
            return $result;
        }

        $canvas = $result['canvas'];

        // Render brand strip onto canvas
        self::renderBrandStrip($canvas, $serviceLabel, $locationStr, $fontBold, $fontSemi);

        // Save to disk
        $yearMonth  = date('Y/m');
        $cardDir    = MEDIA_ROOT . '/social-cards/' . $yearMonth;
        if (!is_dir($cardDir)) {
            mkdir($cardDir, 0755, true);
        }

        $uuid      = self::generateUuid();
        $filename  = $uuid . '_' . $templateType . '.jpg';
        $absPath   = $cardDir . '/' . $filename;
        $webPath   = '/_media/social-cards/' . $yearMonth . '/' . $filename;

        $saved = imagejpeg($canvas, $absPath, self::JPEG_Q);
        imagedestroy($canvas);

        if (!$saved) {
            return self::err('imagejpeg() failed — check disk space and directory permissions.');
        }

        $fileSize = (int)filesize($absPath);

        // Insert into media_derivatives
        $stampData = json_encode([
            'post_id'       => $postId,
            'template_type' => $templateType,
            'service_label' => $serviceLabel,
            'location'      => $locationStr,
            'generated_at'  => date('Y-m-d H:i:s'),
        ]);

        // Remove any existing card derivative for this post (we're regenerating)
        $db->prepare("
            DELETE FROM media_derivatives
            WHERE post_id = ? AND derivative_type = 'social_card'
        ")->execute([$postId]);

        $insStmt = $db->prepare("
            INSERT INTO media_derivatives
                (media_id, derivative_type, file_path, file_size, width, height, stamp_data, post_id)
            VALUES (?, 'social_card', ?, ?, ?, ?, ?, ?)
        ");
        $insStmt->execute([
            $primaryMediaId,
            $webPath,
            $fileSize,
            self::CANVAS_W,
            self::CANVAS_H,
            $stampData,
            $postId,
        ]);

        $derivativeId = (int)$db->lastInsertId();

        // Update social_posts
        $db->prepare("
            UPDATE social_posts
            SET card_derivative_id = ?, card_template_type = ?
            WHERE id = ?
        ")->execute([$derivativeId, $templateType, $postId]);

        return [
            'success'       => true,
            'derivative_id' => $derivativeId,
            'file_path'     => $webPath,
            'error'         => null,
        ];
    }

    // ── Card Renderers ───────────────────────────────────────────────────────

    /**
     * Before / After split card (1080×1080).
     * Left half = before photo, right half = after photo.
     * Lime divider line in centre, BEFORE/AFTER labels top corners.
     */
    private static function renderBeforeAfter(
        array $beforeMedia,
        array $afterMedia,
        string $serviceLabel,
        string $locationStr,
        string $fontBold,
        string $fontSemi
    ): array {
        $canvas = imagecreatetruecolor(self::CANVAS_W, self::CANVAS_H);
        if (!$canvas) {
            return self::err('imagecreatetruecolor() failed.');
        }

        // Fill background with forest colour
        $forest = imagecolorallocate($canvas, 13, 59, 46);
        imagefill($canvas, 0, 0, $forest);

        $halfW = (int)(self::CANVAS_W / 2);
        $photoH = self::CANVAS_H - self::STRIP_H; // photo area above brand strip

        // Render left (before) photo
        if (!empty($beforeMedia)) {
            $src = self::loadImageFromPath($beforeMedia[0]['file_path']);
            if ($src) {
                self::fillRegion($canvas, $src, 0, 0, $halfW - 2, $photoH);
                imagedestroy($src);
            }
        } else {
            // Placeholder: dark green panel with "No Before Photo" text
            $darkGreen = imagecolorallocate($canvas, 13, 59, 46);
            imagefilledrectangle($canvas, 0, 0, $halfW - 2, $photoH, $darkGreen);
        }

        // Render right (after) photo
        $afterSrc = !empty($afterMedia) ? $afterMedia[0] : (!empty($beforeMedia) ? $beforeMedia[0] : null);
        if ($afterSrc) {
            $src = self::loadImageFromPath($afterSrc['file_path']);
            if ($src) {
                self::fillRegion($canvas, $src, $halfW + 2, 0, self::CANVAS_W - $halfW - 2, $photoH);
                imagedestroy($src);
            }
        }

        // Lime centre divider (4px)
        $lime = imagecolorallocate($canvas, 127, 216, 88);
        imagefilledrectangle($canvas, $halfW - 2, 0, $halfW + 2, $photoH, $lime);

        // BEFORE label (top-left)
        $white  = imagecolorallocate($canvas, 255, 255, 255);
        $shadow = imagecolorallocate($canvas, 0, 0, 0);
        self::drawTextWithShadow($canvas, 20, 0, 28, 28, $fontBold, 'BEFORE', $white, $shadow);

        // AFTER label (top-right, right-aligned)
        $bbox = imagettfbbox(20, 0, $fontBold, 'AFTER');
        $textW = abs($bbox[4] - $bbox[0]);
        $afterLabelX = self::CANVAS_W - $textW - 30;
        self::drawTextWithShadow($canvas, 20, 0, $afterLabelX, 28, $fontBold, 'AFTER', $white, $shadow);

        return ['success' => true, 'canvas' => $canvas, 'error' => null];
    }

    /**
     * Hero After card (1080×1080).
     * Full-bleed after photo, gradient brand strip at bottom,
     * orange "✓ Completed" badge top-right.
     */
    private static function renderHeroAfter(
        array $photoMedia,
        string $serviceLabel,
        string $locationStr,
        string $fontBold,
        string $fontSemi,
        string $fontReg
    ): array {
        $canvas = imagecreatetruecolor(self::CANVAS_W, self::CANVAS_H);
        if (!$canvas) {
            return self::err('imagecreatetruecolor() failed.');
        }

        // Fill background
        $forest = imagecolorallocate($canvas, 13, 59, 46);
        imagefill($canvas, 0, 0, $forest);

        // Full-bleed photo
        if (!empty($photoMedia)) {
            $src = self::loadImageFromPath($photoMedia[0]['file_path']);
            if ($src) {
                self::fillRegion($canvas, $src, 0, 0, self::CANVAS_W, self::CANVAS_H - self::STRIP_H);
                imagedestroy($src);
            }
        }

        // Gradient overlay at bottom (make brand strip text readable)
        self::drawGradientOverlay($canvas, self::CANVAS_H - self::STRIP_H - 60, self::CANVAS_H - self::STRIP_H);

        // Orange "✓ Completed" badge (top-right)
        $orange = imagecolorallocate($canvas, 232, 93, 4);
        $white  = imagecolorallocate($canvas, 255, 255, 255);
        $badgeText = 'Completed';
        $badgeFontSize = 16;
        $bbox = imagettfbbox($badgeFontSize, 0, $fontSemi, $badgeText);
        $badgeTextW = abs($bbox[4] - $bbox[0]);
        $badgePad = 12;
        $checkW = 20; // space for checkmark
        $badgeW = $badgeTextW + $badgePad * 2 + $checkW;
        $badgeH = 36;
        $badgeX = self::CANVAS_W - $badgeW - 20;
        $badgeY = 20;

        // Badge background (rounded via filled rect — GD doesn't do native border-radius)
        imagefilledrectangle($canvas, $badgeX, $badgeY, $badgeX + $badgeW, $badgeY + $badgeH, $orange);

        // Checkmark + text
        $textBaseline = $badgeY + $badgeH - (int)(($badgeH - $badgeFontSize) / 2) - 4;
        imagettftext($canvas, $badgeFontSize, 0, $badgeX + $badgePad, $textBaseline, $white, $fontSemi, $badgeText);

        return ['success' => true, 'canvas' => $canvas, 'error' => null];
    }

    // ── Brand Strip ──────────────────────────────────────────────────────────

    /**
     * Renders the brand strip onto the bottom STRIP_H pixels of the canvas.
     * Layout: [Logo left] [Service label centre] [Neighbourhood right]
     */
    private static function renderBrandStrip(
        $canvas,
        string $serviceLabel,
        string $locationStr,
        string $fontBold,
        string $fontSemi
    ): void {
        $stripY = self::CANVAS_H - self::STRIP_H;

        // Forest background
        $forest = imagecolorallocate($canvas, 13, 59, 46);
        imagefilledrectangle($canvas, 0, $stripY, self::CANVAS_W, self::CANVAS_H, $forest);

        // Green accent rule at top of strip (4px)
        $green = imagecolorallocate($canvas, 45, 134, 89);
        imagefilledrectangle($canvas, 0, $stripY, self::CANVAS_W, $stripY + 4, $green);

        $white  = imagecolorallocate($canvas, 255, 255, 255);
        $lime   = imagecolorallocate($canvas, 127, 216, 88);

        $textY = $stripY + (int)(self::STRIP_H / 2) + 8; // approx vertical centre baseline

        // Logo (left-aligned)
        $logoPath = PUBLIC_ROOT . self::LOGO_PATH;
        $logoPad  = 16;
        $logoMaxW = 120;
        $logoMaxH = self::STRIP_H - 16;
        if (file_exists($logoPath)) {
            $logoSrc = self::loadImageFromPath('/assets/img/logo/mowology-logo.jpg');
            if ($logoSrc) {
                $lw = imagesx($logoSrc);
                $lh = imagesy($logoSrc);
                // Scale to fit
                $scale = min($logoMaxW / $lw, $logoMaxH / $lh);
                $dstW  = max(1, (int)($lw * $scale));
                $dstH  = max(1, (int)($lh * $scale));
                $dstY  = $stripY + (int)((self::STRIP_H - $dstH) / 2);
                imagecopyresampled($canvas, $logoSrc, $logoPad, $dstY, 0, 0, $dstW, $dstH, $lw, $lh);
                imagedestroy($logoSrc);
                $logoRight = $logoPad + $dstW + 8;
            } else {
                $logoRight = $logoPad;
            }
        } else {
            // Fallback: "MOWOLOGY" text
            $logoRight = $logoPad;
            imagettftext($canvas, 14, 0, $logoPad, $textY, $white, $fontBold, 'MOWOLOGY');
            $logoRight = $logoPad + 100;
        }

        // Service label (centre)
        if ($serviceLabel) {
            $svcFontSize = 18;
            $bbox = imagettfbbox($svcFontSize, 0, $fontBold, $serviceLabel);
            $svcW = abs($bbox[4] - $bbox[0]);
            $svcX = (int)((self::CANVAS_W - $svcW) / 2);
            imagettftext($canvas, $svcFontSize, 0, $svcX, $textY, $white, $fontBold, $serviceLabel);
        }

        // Neighbourhood + city (right-aligned)
        if ($locationStr) {
            $locFontSize = 14;
            $bbox = imagettfbbox($locFontSize, 0, $fontSemi, $locationStr);
            $locW = abs($bbox[4] - $bbox[0]);
            $locX = self::CANVAS_W - $locW - 16;
            imagettftext($canvas, $locFontSize, 0, $locX, $textY, $lime, $fontSemi, $locationStr);
        }
    }

    // ── GD Helpers ───────────────────────────────────────────────────────────

    /**
     * Load an image from a web-root-relative path.
     * Supports JPEG, PNG, GIF, WEBP.
     * Returns false on failure (caller must handle).
     */
    private static function loadImageFromPath(string $webRelPath)
    {
        $absPath = PUBLIC_ROOT . $webRelPath;
        if (!file_exists($absPath)) {
            return false;
        }
        $ext = strtolower((string)pathinfo($absPath, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg':
            case 'jpeg': return @imagecreatefromjpeg($absPath);
            case 'png':  return @imagecreatefrompng($absPath);
            case 'gif':  return @imagecreatefromgif($absPath);
            case 'webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absPath) : false;
            default:     return @imagecreatefromstring(file_get_contents($absPath));
        }
    }

    /**
     * Fill a target region on the canvas with a source image (cover-style crop).
     * Centres and scales the source to fill exactly dstW × dstH.
     */
    private static function fillRegion($canvas, $src, int $dstX, int $dstY, int $dstW, int $dstH): void
    {
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $srcAspect = $srcW / max(1, $srcH);
        $dstAspect = $dstW / max(1, $dstH);

        if ($srcAspect > $dstAspect) {
            // Source wider than target — crop sides
            $scaledH = $dstH;
            $scaledW = (int)($srcW * ($dstH / $srcH));
            $cropX   = (int)(($scaledW - $dstW) / 2);
            $cropY   = 0;
        } else {
            // Source taller than target — crop top/bottom
            $scaledW = $dstW;
            $scaledH = (int)($srcH * ($dstW / $srcW));
            $cropX   = 0;
            $cropY   = (int)(($scaledH - $dstH) / 3); // slightly favour top third
        }

        // Intermediate scaled canvas
        $scaled = imagecreatetruecolor($scaledW, $scaledH);
        imagecopyresampled($scaled, $src, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

        // Crop from scaled into final canvas
        imagecopy($canvas, $scaled, $dstX, $dstY, $cropX, $cropY, $dstW, $dstH);
        imagedestroy($scaled);
    }

    /**
     * Draw text with a 1px drop shadow (improves readability over photos).
     */
    private static function drawTextWithShadow(
        $canvas,
        float $size,
        float $angle,
        int $x,
        int $y,
        string $font,
        string $text,
        $color,
        $shadowColor
    ): void {
        // Shadow at +1/+1
        imagettftext($canvas, $size, $angle, $x + 1, $y + 1, $shadowColor, $font, $text);
        // Foreground
        imagettftext($canvas, $size, $angle, $x, $y, $color, $font, $text);
    }

    /**
     * Draw a semi-transparent dark gradient from y1 to y2 (vertical, top-transparent to bottom-opaque).
     * Approximated by drawing horizontal lines with increasing alpha.
     */
    private static function drawGradientOverlay($canvas, int $y1, int $y2): void
    {
        $height = max(1, $y2 - $y1);
        for ($y = $y1; $y <= $y2; $y++) {
            $progress = ($y - $y1) / $height; // 0 at top, 1 at bottom
            // alpha: 127=fully transparent, 0=fully opaque
            $alpha = (int)(127 - ($progress * 80));
            $alpha = max(0, min(127, $alpha));
            $c = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
            imageline($canvas, 0, $y, self::CANVAS_W, $y, $c);
        }
    }

    /**
     * Resolve a human-readable service label from the DB or a fallback.
     */
    private static function resolveServiceLabel(string $serviceType, PDO $db): string
    {
        if (!$serviceType) {
            return 'Landscaping';
        }
        try {
            $s = $db->prepare("SELECT label FROM service_types WHERE slug = ? LIMIT 1");
            $s->execute([$serviceType]);
            $label = $s->fetchColumn();
            if ($label) {
                return (string)$label;
            }
        } catch (\Exception $e) {
            // Fall through to fallback
        }
        // Fallback: convert slug to title case
        return ucwords(str_replace(['_', '-'], ' ', $serviceType));
    }

    /**
     * Generate a simple UUID v4 (PHP 7.4 compatible).
     */
    private static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Return a standardised error array.
     */
    private static function err(string $msg): array
    {
        return ['success' => false, 'derivative_id' => null, 'file_path' => null, 'error' => $msg];
    }
}
