<?php
/**
 * API: image URLs for an article (Content Engine photo picker).
 *
 * GET /crm/api/article-image.php?media_id=123
 * Returns JSON: { success, id, hero, inline, inline_webp, thumb, alt, caption, width, height }
 *   hero   — for the article header / OG image (og_image_path)
 *   inline — for <figure> in the body, with an optional webp twin
 *
 * Requires: marketing.view permission
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (!userHasPermission('marketing.view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'marketing.view permission required']);
    exit;
}
require_once APP_ROOT . '/Modules/CMS/Services/ArticleService.php';

$mediaId = (int)($_GET['media_id'] ?? 0);
if ($mediaId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'media_id required']);
    exit;
}
try {
    $service = new ArticleService(getDB());
    $info    = $service->imageUrls($mediaId);

    // Older uploads have no responsive variants, so the picker would put a
    // multi-megabyte original into the article. Generate them once, on demand.
    if ($info && $info['inline'] === $info['hero'] && preg_match('#^/_media/original/#', (string)$info['inline'])) {
        $abs = PUBLIC_ROOT . $info['inline'];
        $gen = APP_ROOT . '/Services/Media/MediaVariantGenerator.php';
        if (is_file($abs) && is_file($gen)) {
            require_once $gen;
            // Only the two widths an article needs (inline 1024, hero 1600), JPEG + WebP.
            // The full generator makes 8 widths × 3 formats and blows past the request
            // time limit on a 12-megapixel phone photo.
            if (function_exists('variantResize') && function_exists('variantInsertRecord')) {
                @set_time_limit(90);
                $dims = @getimagesize($abs);
                $ow = (int)($dims[0] ?? 0);
                $oh = (int)($dims[1] ?? 0);
                if ($ow > 0 && $oh > 0) {
                    $rel   = dirname($info['inline']);                       // /_media/original/YYYY/MM
                    $ym    = basename(dirname($rel)) . '/' . basename($rel); // YYYY/MM
                    $uuid  = pathinfo($info['inline'], PATHINFO_FILENAME);
                    $vdir  = PUBLIC_ROOT . '/_media/variants/' . $ym;
                    if (!is_dir($vdir)) { @mkdir($vdir, 0755, true); }
                    $db = getDB();
                    foreach ([1024, 1600] as $w) {
                        if ($ow <= $w && $w !== 1024) { continue; }      // never upscale for the hero
                        $tw = min($w, $ow);
                        $th = (int)round($oh * $tw / $ow);
                        foreach (['jpeg' => 'jpg', 'webp' => 'webp'] as $fmt => $ext) {
                            $file = "{$uuid}_{$tw}w.{$ext}";
                            $dest = $vdir . '/' . $file;
                            $web  = '/_media/variants/' . $ym . '/' . $file;
                            if (is_file($dest) || variantResize($abs, $dest, $tw, $th, $fmt, $fmt === 'webp' ? 80 : 82)) {
                                variantInsertRecord($db, $mediaId, 'responsive', $fmt, $tw, $th, $web, (int)@filesize($dest), 82);
                            }
                        }
                    }
                    $info = $service->imageUrls($mediaId) ?: $info;
                }
            }
        }
    }
    if (!$info) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media not found']);
        exit;
    }
    echo json_encode(array_merge(['success' => true], $info), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('article-image: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Lookup failed']);
}
