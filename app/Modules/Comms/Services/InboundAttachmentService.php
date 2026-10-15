<?php
/**
 * InboundAttachmentService — the PDF / photo attachments of billing mail routed to Penny.
 *
 * Why (2026-10-08): Vancouver Management Ltd sent a direct-deposit form as a PDF. Penny shows
 * it to Tim as a task with the form one tap away; she never fills it in or sends it herself.
 *
 * Only attachments of INBOUND customer mail routed to Penny are kept (the mailbox readers call
 * store() for those alone — other customer mail attachments are never stored). Files go under
 * app/Storage/inbound-attachments/<yyyy>/<mm>/<sha256>.<ext> (app/ denies all web access) and
 * are served only to an admin session (/crm/api/inbound-route.php?mode=attachment&id=N) or by
 * a short-lived HMAC-signed link for the iOS app (/api/team/inbound-attachment), the same
 * pattern as ReceiptImageLinks.
 *
 * Migration 1283. No namespace / no autoloader in production: require_once and `new`.
 */
class InboundAttachmentService
{
    public const DIR = 'inbound-attachments';
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const MAX_PER_MESSAGE = 5;
    public const APP_URL = 'https://mowology.ca/api/team/inbound-attachment';
    public const WEB_URL = '/crm/api/inbound-route.php?mode=attachment&id=';
    public const EXT = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
                        'image/gif' => 'gif', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'];

    private PDO $db;
    private string $base;

    public function __construct(PDO $db, ?string $baseDir = null)
    {
        $this->db = $db;
        $this->base = rtrim($baseDir ?? ((defined('STORAGE_ROOT') ? STORAGE_ROOT : dirname(__DIR__, 3) . '/Storage') . '/' . self::DIR), '/');
    }

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM inbound_message_attachments LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Keep one attachment. Returns its id, or null (not a PDF/photo, too big, too many, or the
     * table isn't there yet). The same bytes on the same message are stored once.
     */
    public function store(string $messageKey, string $filename, string $mime, string $bytes, ?string $now = null): ?int
    {
        if ($messageKey === '' || $bytes === '' || strlen($bytes) > self::MAX_BYTES || !$this->ready()) return null;
        $mime = strtolower(trim($mime));
        $ext = self::EXT[$mime] ?? null;
        if ($ext === null) return null;
        $sha = hash('sha256', $bytes);

        $s = $this->db->prepare('SELECT id FROM inbound_message_attachments WHERE message_key = ? AND sha256 = ?');
        $s->execute([$messageKey, $sha]);
        $id = $s->fetchColumn();
        if ($id !== false) return (int)$id;

        $c = $this->db->prepare('SELECT COUNT(*) FROM inbound_message_attachments WHERE message_key = ?');
        $c->execute([$messageKey]);
        if ((int)$c->fetchColumn() >= self::MAX_PER_MESSAGE) return null;

        $now = $now ?? date('Y-m-d H:i:s');
        $rel = substr($now, 0, 4) . '/' . substr($now, 5, 2) . '/' . $sha . '.' . $ext;
        $abs = $this->base . '/' . $rel;
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) return null;
        if (!is_file($abs) && @file_put_contents($abs, $bytes) === false) return null;

        $this->db->prepare('INSERT INTO inbound_message_attachments (message_key, filename, mime, size_bytes, sha256, stored_path, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$messageKey, self::safeName($filename, $ext), $mime, strlen($bytes), $sha, $rel, $now]);
        return (int)$this->db->lastInsertId();
    }

    /** message_key => [{id, filename, mime, size}] */
    public function forKeys(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        if (!$keys || !$this->ready()) return [];
        $in = implode(',', array_fill(0, count($keys), '?'));
        $s = $this->db->prepare("SELECT id, message_key, filename, mime, size_bytes FROM inbound_message_attachments
                                 WHERE message_key IN ({$in}) ORDER BY id");
        $s->execute($keys);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['message_key']][] = ['id' => (int)$r['id'], 'filename' => (string)$r['filename'],
                                         'mime' => (string)$r['mime'], 'size' => (int)$r['size_bytes']];
        }
        return $out;
    }

    /** One attachment with its absolute path — only if the file is inside the attachments folder. */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !$this->ready()) return null;
        $s = $this->db->prepare('SELECT * FROM inbound_message_attachments WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $abs = realpath($this->base . '/' . $r['stored_path']);
        $root = realpath($this->base);
        if ($abs === false || $root === false || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) return null;
        return $r + ['abs_path' => $abs];
    }

    /** Stream it (inline: a PDF opens in the browser / Quick Look). */
    public static function stream(array $a): void
    {
        header('Content-Type: ' . $a['mime']);
        header('Content-Length: ' . filesize($a['abs_path']));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$a['filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($a['abs_path']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** A filename fit for a header: no path, no quotes / control characters, the right extension. */
    public static function safeName(string $name, string $ext): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string)preg_replace('/[^A-Za-z0-9 ._()\-]+/', '_', $name), ' ._');
        if ($name === '') $name = 'attachment';
        $name = mb_substr($name, 0, 120);
        return preg_match('/\.' . preg_quote($ext, '/') . '$/i', $name) ? $name : $name . '.' . $ext;
    }

    public static function webUrl(int $id): string
    {
        return self::WEB_URL . $id;
    }

    /** The app's signed link (the signature is the grant — as ReceiptImageLinks). */
    public static function appUrl(int $id, int $expiry, string $secret): string
    {
        return self::APP_URL . '?a=' . $id . '&e=' . $expiry . '&s=' . hash_hmac('sha256', 'inbound-attachment.' . $id . '.' . $expiry, $secret);
    }

    public static function validSignature(int $id, int $expiry, string $sig, string $secret, int $now): bool
    {
        if ($id <= 0 || $expiry < $now || $sig === '' || $secret === '') return false;
        return hash_equals(hash_hmac('sha256', 'inbound-attachment.' . $id . '.' . $expiry, $secret), $sig);
    }
}
