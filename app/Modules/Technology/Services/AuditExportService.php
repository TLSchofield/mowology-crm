<?php
/**
 * AuditExportService — an append-only copy of activity_log outside the database
 * (Fable security audit #25c, 2026-10-12).
 *
 * Anyone who can write to the database (an SQL injection, a stolen admin session, a bad
 * script) could rewrite or erase activity_log, and with it the evidence. Once a day this
 * appends every activity_log row from before today that the copy does not yet hold, as one
 * JSON line each, to a file the web server never serves:
 *
 *   /home/mowology/audit/activity_log.jsonl          (outside public_html — preferred)
 *   STORAGE_ROOT/audit/activity_log.jsonl             (fallback; .htaccess deny + app/ deny)
 *
 * - The file is only ever opened in append mode ('ab') under an exclusive lock. Nothing here
 *   truncates, rewrites or rotates it.
 * - Where to resume is read from the file itself (the id on its last line), not from the
 *   database, so a database edit cannot make the copy skip rows.
 * - Each line carries "prev" (the previous line's hash) and "h" = sha256(prev + this row's
 *   JSON): editing or deleting a line in the middle breaks the chain from there on
 *   (verifyChain()).
 * - Rows changed later in the database (e.g. a company_id cleared when a company is deleted)
 *   keep, in the copy, the values they had when exported.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class AuditExportService
{
    public const FILE = 'activity_log.jsonl';
    public const BATCH = 5000;
    public const MAX_ROWS_PER_RUN = 200000;
    public const GENESIS = 'genesis';

    private PDO $db;
    private string $dir;

    public function __construct(PDO $db, ?string $dir = null)
    {
        $this->db = $db;
        $this->dir = $dir ?? self::resolveDir();
    }

    public function dir(): string { return $this->dir; }
    public function path(): string { return $this->dir . '/' . self::FILE; }

    /**
     * The first usable directory: AUDIT_EXPORT_DIR (secrets.php, optional), then the home
     * directory above the web root (/home/mowology/audit), then STORAGE_ROOT/audit.
     */
    public static function resolveDir(): string
    {
        $candidates = [];
        if (defined('AUDIT_EXPORT_DIR') && (string)AUDIT_EXPORT_DIR !== '') $candidates[] = rtrim((string)AUDIT_EXPORT_DIR, '/');
        if (defined('PUBLIC_ROOT')) $candidates[] = dirname(PUBLIC_ROOT) . '/audit';
        if (defined('STORAGE_ROOT')) $candidates[] = rtrim((string)STORAGE_ROOT, '/') . '/audit';
        foreach ($candidates as $d) {
            if (is_dir($d) ? is_writable($d) : is_writable(dirname($d))) return $d;
        }
        throw new RuntimeException('No writable audit directory (tried ' . implode(', ', $candidates) . ')');
    }

    /** True when $dir is inside the web root (then it must carry a deny-all .htaccess). */
    public static function insideWebRoot(string $dir): bool
    {
        if (!defined('PUBLIC_ROOT')) return false;
        $root = rtrim((string)PUBLIC_ROOT, '/') . '/';
        return strpos(rtrim($dir, '/') . '/', $root) === 0;
    }

    /**
     * Append every row before $today (Y-m-d, default today) that the copy lacks.
     * @return array{exported:int, first_id:?int, last_id:?int, file:string, inside_web_root:bool}
     */
    public function run(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $this->ensureDir();
        $path = $this->path();

        $fh = fopen($path, 'ab');   // append-only: never 'w', never ftruncate
        if ($fh === false) throw new RuntimeException("Cannot open {$path} for append");
        try {
            if (!flock($fh, LOCK_EX)) throw new RuntimeException("Cannot lock {$path}");
            @chmod($path, 0600);
            [$lastId, $prev] = self::tailState(self::readTail($path));

            $exported = 0;
            $first = null;
            // Stop at the first row (by id) written today: rows are copied strictly in id order,
            // so a back-dated row with a higher id waits for tomorrow instead of being skipped.
            $capSt = $this->db->prepare("SELECT MIN(id) FROM activity_log WHERE id > ? AND created_at >= ?");
            $capSt->execute([$lastId, $today . ' 00:00:00']);
            $cap = $capSt->fetchColumn();
            $cap = $cap === false || $cap === null ? PHP_INT_MAX : (int)$cap;
            $st = $this->db->prepare("
                SELECT id, user_id, contact_id, company_id, property_id, quote_request_id, quote_id,
                       invoice_id, plan_id, visit_id, action, details, ip_address, created_at
                FROM activity_log
                WHERE id > ? AND id < ?
                ORDER BY id
                LIMIT " . self::BATCH);
            while ($exported < self::MAX_ROWS_PER_RUN) {
                $st->execute([$lastId, $cap]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) break;
                $buf = '';
                foreach ($rows as $r) {
                    [$line, $prev] = self::line($r, $prev);
                    $buf .= $line . "\n";
                    $lastId = (int)$r['id'];
                    $first = $first ?? $lastId;
                    $exported++;
                }
                if (fwrite($fh, $buf) !== strlen($buf)) throw new RuntimeException("Short write to {$path}");
                fflush($fh);
                if (count($rows) < self::BATCH) break;
            }
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
        return ['exported' => $exported, 'first_id' => $first, 'last_id' => $lastId ?: null,
                'file' => $path, 'inside_web_root' => self::insideWebRoot($this->dir)];
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create {$this->dir}");
        }
        if (self::insideWebRoot($this->dir) && !is_file($this->dir . '/.htaccess')) {
            // Apache 2.2 and 2.4 syntax, like the other deny files in this repo.
            @file_put_contents($this->dir . '/.htaccess', "# Audit copy — never served (Fable audit #25c)\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
    }

    // ── Pure helpers (unit tested) ───────────────────────────────────────────

    /** One JSON line for a row, chained to $prev. @return array{0:string, 1:string} [line, its hash] */
    public static function line(array $row, string $prev): array
    {
        $rec = ['id' => (int)$row['id']];
        foreach (['user_id', 'contact_id', 'company_id', 'property_id', 'quote_request_id', 'quote_id', 'invoice_id', 'plan_id', 'visit_id'] as $k) {
            $rec[$k] = isset($row[$k]) && $row[$k] !== null ? (int)$row[$k] : null;
        }
        foreach (['action', 'details', 'ip_address', 'created_at'] as $k) {
            $rec[$k] = isset($row[$k]) ? (string)$row[$k] : null;
        }
        $body = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $hash = hash('sha256', $prev . $body);
        return ['{"prev":"' . $prev . '","h":"' . $hash . '","row":' . $body . '}', $hash];
    }

    /** From the file's last complete line: [last exported id, its hash] — [0, GENESIS] when empty. */
    public static function tailState(string $tail): array
    {
        $lines = preg_split('/\n/', rtrim($tail, "\n"));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $j = json_decode($lines[$i], true);
            if (is_array($j) && isset($j['h'], $j['row']['id'])) return [(int)$j['row']['id'], (string)$j['h']];
        }
        return [0, self::GENESIS];
    }

    /** Re-hash every line; returns the line number (1-based) of the first break, or 0 when intact. */
    public static function verifyChain(iterable $lines): int
    {
        $prev = self::GENESIS;
        $n = 0;
        foreach ($lines as $line) {
            $line = rtrim((string)$line, "\n");
            if ($line === '') continue;
            $n++;
            $j = json_decode($line, true);
            if (!is_array($j) || ($j['prev'] ?? null) !== $prev) return $n;
            $body = json_encode($j['row'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (hash('sha256', $prev . $body) !== ($j['h'] ?? null)) return $n;
            $prev = (string)$j['h'];
        }
        return 0;
    }

    private static function readTail(string $path, int $bytes = 65536): string
    {
        $size = @filesize($path);
        if (!$size) return '';
        $h = fopen($path, 'rb');
        if ($h === false) return '';
        fseek($h, max(0, $size - $bytes));
        $s = (string)stream_get_contents($h);
        fclose($h);
        return $s;
    }
}
