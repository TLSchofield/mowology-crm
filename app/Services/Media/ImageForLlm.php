<?php
/**
 * ImageForLlm — read a stored image as a base64 content block for a vision call.
 *
 * A copy of MediaTagService::loadImage() (2026-11-03) so the pruning planner shrinks
 * photos the same way as the media tagger: anything over MAX_BYTES is downscaled to 1568 px on its long edge and
 * re-encoded as JPEG 82, which is what the Anthropic API resizes to anyway. Paths are web-root
 * relative (/_media/…) as media_assets.file_path stores them.
 *
 * Global namespace; require_once it.
 */
declare(strict_types=1);

final class ImageForLlm
{
    public const MAX_BYTES = 3_500_000;
    public const LONG_EDGE = 1568;
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * First readable path wins.
     * @param list<string> $paths  web-root relative paths to try in order
     * @return array{media_type: string, data: string}|null
     */
    public static function load(array $paths, ?string $root = null): ?array
    {
        $root = $root ?? (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '');
        foreach ($paths as $p) {
            $p = (string)$p;
            if ($p === '') continue;
            $abs = $root . '/' . ltrim($p, '/');
            if (!is_file($abs)) continue;
            $type = (string)(mime_content_type($abs) ?: '');
            if (!in_array($type, self::TYPES, true)) continue;
            if (filesize($abs) <= self::MAX_BYTES) {
                return ['media_type' => $type, 'data' => base64_encode((string)file_get_contents($abs))];
            }
            if (!extension_loaded('gd')) continue;
            $src = @imagecreatefromstring((string)file_get_contents($abs));
            if (!$src) continue;
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = self::LONG_EDGE / max($w, $h);
            $dst = imagescale($src, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
            ob_start();
            imagejpeg($dst ?: $src, null, 82);
            $data = (string)ob_get_clean();
            return ['media_type' => 'image/jpeg', 'data' => base64_encode($data)];
        }
        return null;
    }

    /** The Anthropic content block for a loaded image. */
    public static function block(array $img): array
    {
        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['media_type'], 'data' => $img['data']]];
    }
}
