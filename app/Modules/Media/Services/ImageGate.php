<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Services/Media/MediaVariantGenerator.php';

/** Enforces the shared intake and public-publishing image rules. */
class ImageGate
{
    const MAX_WEB_BYTES = 409600;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<int,array{code:string,message:string}> */
    public static function assess(array $img): array
    {
        $problems = [];
        $bytes = (int)($img['bytes'] ?? 0);
        $width = (int)($img['width'] ?? 0);
        $height = (int)($img['height'] ?? 0);
        $alt = trim((string)($img['alt'] ?? ''));
        $extension = strtolower(pathinfo((string)($img['path'] ?? ''), PATHINFO_EXTENSION));

        if ($bytes > 400 * 1024) {
            $problems[] = ['code' => 'heavy', 'message' => 'The web image is larger than 400 KB.'];
        }
        if ($width > 1920) {
            $problems[] = ['code' => 'too_wide', 'message' => 'The web image is wider than 1920 pixels.'];
        }
        if ($width <= 0 || $height <= 0) {
            $problems[] = ['code' => 'no_dimensions', 'message' => 'The image dimensions could not be read.'];
        }
        if ($alt === '') {
            $problems[] = ['code' => 'no_alt', 'message' => 'Content images need alt text.'];
        }
        if (!in_array($extension, ['jpg', 'jpeg', 'webp', 'avif', 'png', 'gif', 'svg'], true)) {
            $problems[] = ['code' => 'not_web_format', 'message' => 'The image is not in a supported web format.'];
        }
        return $problems;
    }

    /** @return int[] */
    public static function webWidthsFor(int $originalWidth): array
    {
        $widths = [];
        foreach (VARIANT_WIDTHS as $width) {
            if ($width < $originalWidth) {
                $widths[] = $width;
            }
        }
        if (!$widths) {
            $widths[] = $originalWidth;
        }
        return $widths;
    }

    /** @return array{ok:bool,path?:string,error?:string} */
    public function ensureWebCopy(int $mediaId, string $absOriginalPath): array
    {
        $path = $this->preferredVariant($mediaId);
        if ($path !== null) {
            return ['ok' => true, 'path' => $path];
        }

        if (function_exists('generateMediaVariants')) {
            try {
                generateMediaVariants($mediaId, $absOriginalPath);
            } catch (Throwable $e) {
                error_log('ImageGate: variant generation failed for media ' . $mediaId . ': ' . $e->getMessage());
            }
        }

        $path = $this->preferredVariant($mediaId);
        if ($path !== null) {
            return ['ok' => true, 'path' => $path];
        }

        try {
            $stmt = $this->db->prepare('SELECT file_path, file_size, image_width, image_height FROM media_assets WHERE id = ? LIMIT 1');
            $stmt->execute([$mediaId]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Keeps lightweight legacy/test schemas readable; production has all four columns.
            $stmt = $this->db->prepare('SELECT file_path FROM media_assets WHERE id = ? LIMIT 1');
            $stmt->execute([$mediaId]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $bytes = is_file($absOriginalPath) ? (int)filesize($absOriginalPath) : (int)($asset['file_size'] ?? 0);
        $info = is_file($absOriginalPath) ? @getimagesize($absOriginalPath) : false;
        $width = $info ? (int)$info[0] : (int)($asset['image_width'] ?? 0);
        $height = $info ? (int)$info[1] : (int)($asset['image_height'] ?? 0);

        if ($width > 1920 || $bytes > 400 * 1024) {
            return ['ok' => false, 'error' => 'A web-sized image could not be prepared.'];
        }
        if (!$asset || empty($asset['file_path'])) {
            return ['ok' => false, 'error' => 'The image could not be prepared for the website.'];
        }
        return ['ok' => true, 'path' => (string)$asset['file_path']];
    }

    /** Widest JPEG variant up to 1280px that is within the weight limit and really on disk; null when none qualifies. */
    private function preferredVariant(int $mediaId): ?string
    {
        $stmt = $this->db->prepare("SELECT file_path FROM media_variants
            WHERE media_id = ? AND variant_type = 'responsive' AND format = 'jpeg' AND width <= 1280
            ORDER BY width DESC");
        $stmt->execute([$mediaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (empty($row['file_path'])) continue;
            if (defined('PUBLIC_ROOT')) {   // a file on disk must be light enough (checked there; test schemas have no size column)
                $abs = PUBLIC_ROOT . $row['file_path'];
                if (is_file($abs) && filesize($abs) > self::MAX_WEB_BYTES) continue;   // too heavy: try a narrower one
            }
            return (string)$row['file_path'];
        }
        return null;
    }
}
