<?php
/**
 * ReceiptThumb — small JPEG thumbnails for receipt photos (2026-10-14).
 *
 * Tim: "receipt images take a long time to appear and are sometimes missing". The receipts list
 * showed every row's FULL phone photo (median 1.6 MB, up to 2.7 MB, 366 receipts, no thumbnails
 * existed) through serve-receipt.php for a 40 px box — about 40 MB per page. serve-receipt.php
 * ?thumb=1 now serves a 320 px JPEG made once with GD and cached under /uploads/receipts/thumbs/ (no direct web access); the list
 * asks only for that, lazily. The full photo is still what the lightbox opens.
 *
 * The cache file is named by media id and the source's mtime + size, so a re-saved receipt gets
 * a new thumbnail. PDFs and emailed (HTML) receipts get no thumbnail; the list shows an icon.
 */
class ReceiptThumb
{
    public const WIDTH = 320;
    public const QUALITY = 72;
    public const DIR = '/uploads/receipts/thumbs';   // denied to direct HTTP like the originals (uploads/receipts/.htaccess)

    /** Pure: can this mime type be thumbnailed with GD? */
    public static function supports(string $mime): bool
    {
        return in_array(strtolower($mime), ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true);
    }

    /** Pure: the cached thumbnail's path (relative to the web root) for this media row's source file. */
    public static function cachePath(int $mediaId, int $mtime, int $size): string
    {
        return self::DIR . '/receipt-' . $mediaId . '-' . dechex($mtime) . '-' . dechex($size) . '.jpg';
    }

    /** Pure: the thumbnail's size, never larger than the original. */
    public static function fit(int $w, int $h, int $max = self::WIDTH): array
    {
        if ($w <= 0 || $h <= 0) return [0, 0];
        if ($w <= $max && $h <= $max) return [$w, $h];
        $r = $max / max($w, $h);
        return [max(1, (int)round($w * $r)), max(1, (int)round($h * $r))];
    }

    /**
     * The thumbnail on disk for $src (made now if it isn't cached), or null if it can't be made —
     * the caller then serves the original. $root is the web root (PUBLIC_ROOT).
     */
    public static function ensure(string $root, int $mediaId, string $src, string $mime): ?string
    {
        if (!self::supports($mime) || !is_file($src) || !function_exists('imagecreatetruecolor')) return null;
        $rel = self::cachePath($mediaId, (int)filemtime($src), (int)filesize($src));
        $dest = $root . $rel;
        if (is_file($dest)) return $dest;
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true) && !is_dir(dirname($dest))) return null;

        $info = @getimagesize($src);
        if (!$info) return null;
        $img = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($src); break;
            case IMAGETYPE_PNG:  $img = @imagecreatefrompng($src); break;
            case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : null; break;
        }
        if (!$img) return null;
        // Phone photos carry their rotation in EXIF; the browser honoured it, so must the thumbnail.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $ex = @exif_read_data($src);
            $o = is_array($ex) ? (int)($ex['Orientation'] ?? 1) : 1;
            $turn = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($turn) { $r = imagerotate($img, $turn, 0); if ($r) $img = $r; }
        }
        [$w, $h] = self::fit(imagesx($img), imagesy($img));
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // PNG transparency → white paper
        imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        $tmp = $dest . '.' . bin2hex(random_bytes(3)) . '.tmp';
        $ok = imagejpeg($out, $tmp, self::QUALITY);
        unset($img, $out);   // GD images free themselves (imagedestroy is deprecated in PHP 8.5)
        if (!$ok || !@rename($tmp, $dest)) { @unlink($tmp); return null; }
        return $dest;
    }
}
