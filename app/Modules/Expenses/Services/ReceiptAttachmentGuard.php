<?php
declare(strict_types=1);

/**
 * ReceiptAttachmentGuard — is this emailed attachment really a receipt file? (Tim, 2026-10-22)
 *
 * Why: ReceiptInboxService took the file's type from the email's own labels (the MIME header and the file
 * name) and saved it under /uploads/receipts/ with the extension from the file NAME. So "invoice.php" labelled
 * image/png was stored as invoice.php. This looks at the bytes themselves instead:
 *   - the file must start with a real PDF / JPEG / PNG / GIF / WebP / HEIC signature;
 *   - it must be non-empty and under MAX_BYTES;
 *   - the extension to store under comes from the detected type, never from the sender's file name.
 * It is not an antivirus: it cannot find malware inside a genuine PDF. It makes sure that what we store, and
 * serve back, is the kind of file we think it is.
 *
 * Pure (no database, no disk). Global namespace: require_once the file (no autoloader in production).
 */
class ReceiptAttachmentGuard
{
    public const MAX_BYTES = 15728640;   // 15 MB

    /**
     * @return array{ok: bool, kind: ?string, ext: ?string, problem: ?string}
     *   kind is 'pdf' or 'image'; ext is the only extension it may be stored under.
     */
    public static function inspect(string $bytes, int $maxBytes = self::MAX_BYTES): array
    {
        $len = strlen($bytes);
        if ($len === 0) return self::no('empty file');
        if ($len > $maxBytes) return self::no('file too large (' . (int)round($len / 1048576) . ' MB)');

        // PDF: "%PDF-" at the start (viewers tolerate a little junk first, so look in the first 1 KB).
        if (strpos(substr($bytes, 0, 1024), '%PDF-') !== false) {
            return ['ok' => true, 'kind' => 'pdf', 'ext' => 'pdf', 'problem' => null];
        }
        $head = substr($bytes, 0, 16);
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0)                         return self::img('jpg');
        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0)                    return self::img('png');
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) return self::img('gif');
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') return self::img('webp');
        // HEIC / HEIF: an ISO box "ftyp" at byte 4 with a heic-family brand.
        if (substr($head, 4, 4) === 'ftyp' && preg_match('/^(heic|heix|hevc|hevx|heim|heis|mif1|msf1)$/', substr($head, 8, 4))) {
            return self::img('heic');
        }
        return self::no('not a PDF or image');
    }

    private static function img(string $ext): array
    {
        return ['ok' => true, 'kind' => 'image', 'ext' => $ext, 'problem' => null];
    }

    private static function no(string $why): array
    {
        return ['ok' => false, 'kind' => null, 'ext' => null, 'problem' => $why];
    }
}
