<?php
/**
 * PWA Splash Screen Generator
 * ────────────────────────────
 * Generates a branded splash/launch image for iOS "Add to Home Screen".
 * Returns a PNG image at the requested dimensions with the Mowology logo
 * centered on the brand gradient background.
 *
 * Usage: /crm/pwa-splash.php?w=1170&h=2532
 *
 * Caching: Sends far-future cache headers — the image is static.
 * No auth required — this is a static asset generator.
 */

$width  = isset($_GET['w']) ? (int) $_GET['w'] : 1170;
$height = isset($_GET['h']) ? (int) $_GET['h'] : 2532;

// Clamp to reasonable bounds (avoid memory abuse)
$width  = max(320, min($width, 3000));
$height = max(480, min($height, 3000));

// Cache for 30 days — these don't change
header('Content-Type: image/png');
header('Cache-Control: public, max-age=2592000, immutable');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT');

// Check if GD is available
if (!function_exists('imagecreatetruecolor')) {
    // Fallback: return a 1x1 transparent PNG
    header('Content-Length: 68');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
}

// Create image
$img = imagecreatetruecolor($width, $height);
imagealphablending($img, true);

// Brand gradient colors
$forest = ['r' => 13,  'g' => 59,  'b' => 46];  // --mw-forest #0D3B2E
$dark   = ['r' => 26,  'g' => 95,  'b' => 74];   // --mw-dark   #1A5F4A
$green  = ['r' => 45,  'g' => 134, 'b' => 89];   // --mw-green  #2D8659

// Draw gradient background (vertical: forest → dark → green)
for ($y = 0; $y < $height; $y++) {
    $pct = $y / max($height - 1, 1);
    if ($pct < 0.5) {
        // Forest → Dark
        $t = $pct / 0.5;
        $r = (int) ($forest['r'] + ($dark['r'] - $forest['r']) * $t);
        $g = (int) ($forest['g'] + ($dark['g'] - $forest['g']) * $t);
        $b = (int) ($forest['b'] + ($dark['b'] - $forest['b']) * $t);
    } else {
        // Dark → Green
        $t = ($pct - 0.5) / 0.5;
        $r = (int) ($dark['r'] + ($green['r'] - $dark['r']) * $t);
        $g = (int) ($dark['g'] + ($green['g'] - $dark['g']) * $t);
        $b = (int) ($dark['b'] + ($green['b'] - $dark['b']) * $t);
    }
    $color = imagecolorallocate($img, $r, $g, $b);
    imageline($img, 0, $y, $width - 1, $y, $color);
}

// Try to overlay the logo PNG centered
$logoPath = __DIR__ . '/../assets/favicon/android-chrome-512x512.png';
if (is_file($logoPath)) {
    $logo = imagecreatefrompng($logoPath);
    if ($logo) {
        $logoW = imagesx($logo);
        $logoH = imagesy($logo);

        // Scale logo to ~20% of the shorter dimension
        $targetSize = (int) (min($width, $height) * 0.20);
        $targetSize = max($targetSize, 80); // minimum 80px

        $dstX = (int) (($width - $targetSize) / 2);
        $dstY = (int) (($height - $targetSize) / 2) - (int) ($height * 0.05); // slightly above center

        imagealphablending($img, true);
        imagesavealpha($img, true);
        imagecopyresampled($img, $logo, $dstX, $dstY, 0, 0, $targetSize, $targetSize, $logoW, $logoH);
        imagedestroy($logo);

        // Add "Mowology" text below the logo
        $white = imagecolorallocate($img, 255, 255, 255);
        $fontSize = max(3, (int) ($targetSize / 12)); // GD font size 1-5
        $fontSize = min($fontSize, 5);
        $text = 'Mowology';
        $textW = imagefontwidth($fontSize) * strlen($text);
        $textX = (int) (($width - $textW) / 2);
        $textY = $dstY + $targetSize + (int) ($targetSize * 0.15);
        imagestring($img, $fontSize, $textX, $textY, $text, $white);
    }
}

// Output
imagepng($img, null, 6);
imagedestroy($img);
