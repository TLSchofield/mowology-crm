<?php
/**
 * TextBridgeService — customer texts for Sam, sent up by the messages bridge on Tim's Mac.
 *
 * The bridge (tools/messages-bridge/bridge.py) reads the Mac's Messages database read-only.
 * It never sends a phone number: it asks numbers() for a salt and the salted SHA-256 of
 * every customer's number (last 10 digits), hashes each one-to-one conversation's number
 * the same way, and posts only the matches to ingest(). Texts with anyone else never leave
 * the Mac. Stored rows are sales_messages with mailbox 'imessage', channel 'sms' and
 * from/to 'text' — the raw number is not stored either; contact_id says who it was.
 *
 * Every run also posts a heartbeat() (counts only), so status() can tell when the bridge
 * has gone silent (Mac asleep, Full Disk Access lost, wrong token).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class TextBridgeService
{
    public const MAILBOX = 'imessage';
    public const SNIPPET_MAX = 800;
    public const MAX_BATCH = 500;
    public const SALT_KEY = 'text_bridge_salt';
    public const HEARTBEAT_KEY = 'text_bridge_heartbeat';
    public const SILENT_MINUTES = 60;

    private PDO $db;
    /** @var array<string, int>|null hash => contact id */
    private ?array $map = null;
    private ?string $salt = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM sales_messages LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The server's salt — created once, kept in ops_settings. */
    public function salt(): string
    {
        if ($this->salt !== null) return $this->salt;
        $v = $this->setting(self::SALT_KEY);
        if ($v === null || strlen($v) < 16) {
            $v = bin2hex(random_bytes(16));
            $this->putSetting(self::SALT_KEY, $v, 'Salt for the messages bridge number hashes (not a secret on its own)');
            $v = $this->setting(self::SALT_KEY) ?? $v;   // another request may have won the race
        }
        return $this->salt = $v;
    }

    /**
     * [salt, [hash => contact_id]] for every active, non-test contact with a phone or mobile.
     * A number shared by two contacts goes to the newest one.
     * @return array{0: string, 1: array<string, int>}
     */
    public function numbers(): array
    {
        $salt = $this->salt();
        if ($this->map !== null) return [$salt, $this->map];
        $rows = [];
        foreach ([   // newest schema first; older ones lack is_active / mobile
            "SELECT id, first_name, last_name, phone, mobile FROM contacts WHERE COALESCE(is_active, 1) = 1 ORDER BY id",
            "SELECT id, first_name, last_name, phone, mobile FROM contacts ORDER BY id",
            "SELECT id, first_name, last_name, phone FROM contacts ORDER BY id",
        ] as $sql) {
            try {
                $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                break;
            } catch (Throwable $e) { continue; }
        }
        $this->map = self::hashMap($rows, $salt);
        return [$salt, $this->map];
    }

    /**
     * Store matched texts. Each: {hash, direction: in|out, sent_at: unix seconds, guid, text}.
     * Unknown hashes are rejected (never stored).
     * @return array{stored: int, dupe: int, unknown: int, invalid: int}
     */
    public function ingest(array $msgs): array
    {
        [, $map] = $this->numbers();
        $res = ['stored' => 0, 'dupe' => 0, 'unknown' => 0, 'invalid' => 0];
        $verb = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $s = $this->db->prepare("
            {$verb} INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, to_addr, subject, snippet, sent_at)
            VALUES (?, ?, ?, 'sms', ?, 'text', 'text', NULL, ?, ?)
        ");
        foreach ($msgs as $m) {
            $row = is_array($m) ? self::row($m, $map) : null;
            if ($row === null) { $res['invalid']++; continue; }
            if ($row === 'unknown') { $res['unknown']++; continue; }
            $s->execute([self::MAILBOX, $row['key'], $row['direction'], $row['contact_id'], $row['snippet'], $row['sent_at']]);
            $s->rowCount() > 0 ? $res['stored']++ : $res['dupe']++;
        }
        return $res;
    }

    /** Last-seen + counts from the Mac (numbers only). */
    public function heartbeat(array $counts): array
    {
        $hb = ['at' => date('Y-m-d H:i:s')];
        foreach (['scanned', 'one_to_one', 'matched_customers', 'sent', 'stored'] as $k) {
            $hb[$k] = max(0, (int)($counts[$k] ?? 0));
        }
        $hb['version'] = mb_substr(preg_replace('/[^0-9A-Za-z.\-]/', '', (string)($counts['version'] ?? '')), 0, 20);
        $this->putSetting(self::HEARTBEAT_KEY, json_encode($hb), 'Messages bridge: last run on Tim\'s Mac (counts only)');
        return $hb;
    }

    /**
     * null — the bridge has never run (not set up); else the last heartbeat + silent flag.
     * @return array{at: string, silent: bool, minutes: int}|null
     */
    public function status(?int $now = null): ?array
    {
        $v = $this->setting(self::HEARTBEAT_KEY);
        $hb = $v !== null ? json_decode($v, true) : null;
        if (!is_array($hb) || empty($hb['at'])) return null;
        $mins = (int)floor((($now ?? time()) - (strtotime((string)$hb['at']) ?: 0)) / 60);
        return ['at' => (string)$hb['at'], 'silent' => $mins > self::SILENT_MINUTES, 'minutes' => max(0, $mins)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested) — normalize() and hash() must match bridge.py exactly
    // ─────────────────────────────────────────────────────────────────────────

    /** Last 10 digits of a phone number, or null (emails, short codes, blanks). */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || strpos($phone, '@') !== false) return null;
        $d = preg_replace('/\D/', '', $phone);
        return strlen($d) >= 10 ? substr($d, -10) : null;
    }

    /** sha256(salt + 10 digits), lowercase hex. */
    public static function hash(string $tenDigits, string $salt): string
    {
        return hash('sha256', $salt . $tenDigits);
    }

    /**
     * @param array<int, array{id: int|string, first_name?: ?string, last_name?: ?string, phone?: ?string, mobile?: ?string}> $contacts ordered by id
     * @return array<string, int>
     */
    public static function hashMap(array $contacts, string $salt): array
    {
        $map = [];
        foreach ($contacts as $c) {
            if (stripos(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')), 'ZZTEST') !== false) continue;
            foreach (['phone', 'mobile'] as $f) {
                $n = self::normalize(isset($c[$f]) ? (string)$c[$f] : null);
                if ($n !== null) $map[self::hash($n, $salt)] = (int)$c['id'];
            }
        }
        return $map;
    }

    /**
     * One posted text → a sales_messages row; 'unknown' for a hash that isn't a customer,
     * null for anything malformed.
     * @return array{key: string, direction: string, contact_id: int, snippet: string, sent_at: string}|string|null
     */
    public static function row(array $m, array $map)
    {
        $hash = strtolower((string)($m['hash'] ?? ''));
        $guid = (string)($m['guid'] ?? '');
        $dir  = (string)($m['direction'] ?? '');
        $at   = $m['sent_at'] ?? null;
        $text = trim(str_replace("\u{FFFC}", '', (string)($m['text'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) return null;
        if (!preg_match('~^[A-Za-z0-9:_/\-]{1,150}$~', $guid)) return null;
        if (!in_array($dir, ['in', 'out', 'inbound', 'outbound'], true)) return null;
        if (!is_numeric($at) || (int)$at < 946684800 || (int)$at > time() + 86400) return null;   // 2000 → tomorrow
        if ($text === '') return null;
        if (!isset($map[$hash])) return 'unknown';
        return [
            'key'        => 'imsg-' . $guid,
            'direction'  => ($dir === 'in' || $dir === 'inbound') ? 'inbound' : 'outbound',
            'contact_id' => (int)$map[$hash],
            'snippet'    => mb_strlen($text) > self::SNIPPET_MAX ? rtrim(mb_substr($text, 0, self::SNIPPET_MAX - 1)) . '…' : $text,
            'sent_at'    => date('Y-m-d H:i:s', (int)$at),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function setting(string $key): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false || $v === null ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Update-then-insert: works on MySQL and on the SQLite used by the tests. */
    private function putSetting(string $key, string $value, string $description): void
    {
        $u = $this->db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?");
        $u->execute([$value, $key]);
        if ($u->rowCount() > 0 || $this->setting($key) !== null) return;
        try {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)")
                     ->execute([$key, $value, $description]);
        } catch (Throwable $e) { /* a parallel request inserted it first */ }
    }
}
