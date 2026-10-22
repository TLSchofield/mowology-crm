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
 * 2026-10-22 (bridge 1.2, migration 1396): a text from a number that is NOT a customer is no longer dropped
 * (a customer texting from a new number was invisible to Yui). The bridge also posts those, with the number's
 * last 10 digits; they wait in text_unmatched until Tim links the number to a customer (rows move into
 * sales_messages) or marks it personal (rows deleted; only a salted hash is kept in text_personal and
 * served back in numbers() so the bridge never sends that number again).
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
     * A hash that is not a customer is kept in text_unmatched when the message carries its number ('phone', last 10
     * digits, which must hash to it) and the number is not marked personal; otherwise it is dropped.
     * @return array{stored: int, dupe: int, unknown: int, invalid: int, unmatched: int, personal: int}
     */
    public function ingest(array $msgs): array
    {
        [, $map] = $this->numbers();
        $personal = array_flip($this->personalHashes());
        $res = ['stored' => 0, 'dupe' => 0, 'unknown' => 0, 'invalid' => 0, 'unmatched' => 0, 'personal' => 0];
        $verb = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $s = $this->db->prepare("
            {$verb} INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, to_addr, subject, snippet, sent_at)
            VALUES (?, ?, ?, 'sms', ?, 'text', 'text', NULL, ?, ?)
        ");
        foreach ($msgs as $m) {
            $row = is_array($m) ? self::row($m, $map) : null;
            if ($row === null) { $res['invalid']++; continue; }
            if ($row === 'unknown') {
                $res['unknown']++;
                $u = $this->inboxReady() ? self::unmatchedRow($m, $this->salt(), $personal) : null;
                if ($u === 'personal') { $res['personal']++; }
                elseif (is_array($u)) { $this->storeUnmatched($u) ? $res['unmatched']++ : $res['dupe']++; }
                continue;
            }
            $s->execute([self::MAILBOX, $row['key'], $row['direction'], $row['contact_id'], $row['snippet'], $row['sent_at']]);
            $s->rowCount() > 0 ? $res['stored']++ : $res['dupe']++;
        }
        return $res;
    }

    /** Stored Interac alert texts (mailbox 'interac'): who sent money and when, no amount. */
    public const INTERAC_MAILBOX = 'interac';

    /**
     * Interac alert texts from the bridge: {guid, sent_at, sender} each. Stored as sales_messages
     * with mailbox 'interac', no contact (the sender may not be one, or may be personal money);
     * Penny reads them as payer-name hints for a deposit (PaymentClueService::alertSendersFor).
     * @return array{stored: int, dupe: int, invalid: int}
     */
    public function ingestInterac(array $alerts): array
    {
        $res = ['stored' => 0, 'dupe' => 0, 'invalid' => 0];
        $verb = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $s = $this->db->prepare("
            {$verb} INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, from_name, to_addr, subject, snippet, sent_at)
            VALUES (?, ?, 'inbound', 'sms', NULL, 'interac', ?, 'text', NULL, ?, ?)
        ");
        foreach ($alerts as $a) {
            $row = is_array($a) ? self::interacRow($a) : null;
            if ($row === null) { $res['invalid']++; continue; }
            $s->execute([self::INTERAC_MAILBOX, $row['key'], $row['sender'], $row['sender'] . ' sent you money (Interac alert)', $row['sent_at']]);
            $s->rowCount() > 0 ? $res['stored']++ : $res['dupe']++;
        }
        return $res;
    }

    /** Pure (tested): one alert → a row, or null when malformed. */
    public static function interacRow(array $a): ?array
    {
        $guid = (string)($a['guid'] ?? '');
        $at = $a['sent_at'] ?? null;
        $sender = trim(preg_replace('/\s+/', ' ', (string)($a['sender'] ?? '')));
        if (!preg_match('~^[A-Za-z0-9:_/\-]{1,150}$~', $guid)) return null;
        if (!is_numeric($at) || (int)$at < 946684800 || (int)$at > time() + 86400) return null;
        if ($sender === '' || mb_strlen($sender) > 120 || preg_match('~https?:|www\.|/~i', $sender)) return null;
        return ['key' => 'interac-' . $guid, 'sender' => $sender, 'sent_at' => date('Y-m-d H:i:s', (int)$at)];
    }

    // ──────────────────────────────────────────────────────
    // Texts from numbers that are not a customer yet (migration 1396)
    // ──────────────────────────────────────────────────────

    private ?bool $inboxReady = null;

    /** Have the text_unmatched / text_personal tables been created (migration 1396)? */
    public function inboxReady(): bool
    {
        if ($this->inboxReady !== null) return $this->inboxReady;
        try {
            $this->db->query("SELECT 1 FROM text_unmatched LIMIT 1");
            $this->db->query("SELECT 1 FROM text_personal LIMIT 1");
            return $this->inboxReady = true;
        } catch (Throwable $e) {
            return $this->inboxReady = false;
        }
    }

    /** Salted hashes of the numbers marked personal: served to the bridge so it never sends them. @return string[] */
    public function personalHashes(): array
    {
        if (!$this->inboxReady()) return [];
        try { return $this->db->query("SELECT number_hash FROM text_personal")->fetchAll(PDO::FETCH_COLUMN) ?: []; }
        catch (Throwable $e) { return []; }
    }

    /**
     * Pure (tested): one posted text from a non-customer number → a text_unmatched row; 'personal' when the number
     * is on the blocklist; null when malformed or when 'phone' is missing or does not hash to 'hash' (so the server
     * never stores a number the bridge did not really have).
     * @param array<string, mixed> $personal hash => anything
     * @return array{phone10: string, key: string, direction: string, snippet: string, sent_at: string}|string|null
     */
    public static function unmatchedRow(array $m, string $salt, array $personal)
    {
        $hash  = strtolower((string)($m['hash'] ?? ''));
        $phone = (string)($m['phone'] ?? '');
        $guid  = (string)($m['guid'] ?? '');
        $dir   = (string)($m['direction'] ?? '');
        $at    = $m['sent_at'] ?? null;
        $text  = trim(str_replace("\u{FFFC}", '', (string)($m['text'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $hash) || !preg_match('/^\d{10}$/', $phone)) return null;
        if (!hash_equals(self::hash($phone, $salt), $hash)) return null;
        if (isset($personal[$hash])) return 'personal';
        if (!preg_match('~^[A-Za-z0-9:_/\-]{1,150}$~', $guid)) return null;
        if (!in_array($dir, ['in', 'out', 'inbound', 'outbound'], true)) return null;
        if (!is_numeric($at) || (int)$at < 946684800 || (int)$at > time() + 86400) return null;
        if ($text === '') return null;
        return [
            'phone10'   => $phone,
            'key'       => 'imsg-' . $guid,
            'direction' => ($dir === 'in' || $dir === 'inbound') ? 'inbound' : 'outbound',
            'snippet'   => mb_strlen($text) > self::SNIPPET_MAX ? rtrim(mb_substr($text, 0, self::SNIPPET_MAX - 1)) . '…' : $text,
            'sent_at'   => date('Y-m-d H:i:s', (int)$at),
        ];
    }

    /** Waiting texts the CRM will hold: a token holder cannot grow the table without limit. */
    public const UNMATCHED_CAP = 5000;

    /** @param array{phone10: string, key: string, direction: string, snippet: string, sent_at: string} $u true when new */
    private function storeUnmatched(array $u): bool
    {
        // The blocklist is read again here, not trusted from the start of the batch: a number marked personal while this
        // request was running must not slip in afterwards (Codex review 2026-10-22).
        $h = self::hash($u['phone10'], $this->salt());
        $p = $this->db->prepare("SELECT 1 FROM text_personal WHERE number_hash = ?");
        $p->execute([$h]);
        if ($p->fetchColumn()) return false;
        if ((int)$this->db->query("SELECT COUNT(*) FROM text_unmatched")->fetchColumn() >= self::UNMATCHED_CAP) return false;
        $verb = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $st = $this->db->prepare("{$verb} INTO text_unmatched (phone10, message_key, direction, snippet, sent_at) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$u['phone10'], $u['key'], $u['direction'], $u['snippet'], $u['sent_at']]);
        return $st->rowCount() > 0;
    }

    /**
     * Move waiting texts whose number now belongs to a customer into sales_messages (the number was added to a
     * customer's record). Run after every link and each time the bridge fetches the number list.
     * @return int texts moved
     */
    public function adoptUnmatched(): int
    {
        if (!$this->inboxReady()) return 0;
        $this->map = null;
        [$salt, $map] = $this->numbers();
        $moved = 0;
        $phones = $this->db->query("SELECT DISTINCT phone10 FROM text_unmatched")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $verb = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        foreach ($phones as $ph) {
            $h = self::hash((string)$ph, $salt);
            if (!isset($map[$h])) continue;
            $rows = $this->db->prepare("SELECT message_key, direction, snippet, sent_at FROM text_unmatched WHERE phone10 = ? ORDER BY sent_at");
            $rows->execute([$ph]);
            $ins = $this->db->prepare("
                {$verb} INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, to_addr, subject, snippet, sent_at)
                VALUES (?, ?, ?, 'sms', ?, 'text', 'text', NULL, ?, ?)
            ");
            $copied = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ins->execute([self::MAILBOX, $r['message_key'], $r['direction'], (int)$map[$h], $r['snippet'], $r['sent_at']]);
                $copied[] = (string)$r['message_key'];
                $moved++;
            }
            // Delete only what was copied: a text that arrived while this ran stays for the next pass (Codex review).
            $del = $this->db->prepare("DELETE FROM text_unmatched WHERE message_key = ?");
            foreach ($copied as $k) $del->execute([$k]);
        }
        return $moved;
    }

    /**
     * The waiting numbers for Tim's list, newest first, each with its latest few messages.
     * @return array<int, array{phone10: string, count: int, inbound: int, first_at: string, last_at: string, messages: array}>
     */
    public function unknownThreads(int $limit = 50, int $perThread = 5): array
    {
        if (!$this->inboxReady()) return [];
        $limit = max(1, min(200, $limit));
        $st = $this->db->query("SELECT phone10, COUNT(*) AS n, SUM(direction = 'inbound') AS ins, MIN(sent_at) AS first_at, MAX(sent_at) AS last_at
                                FROM text_unmatched GROUP BY phone10 ORDER BY last_at DESC LIMIT {$limit}");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = $this->db->prepare("SELECT direction, snippet, sent_at FROM text_unmatched WHERE phone10 = ? ORDER BY sent_at DESC, id DESC LIMIT " . max(1, $perThread));
            $m->execute([$r['phone10']]);
            $out[] = ['phone10' => (string)$r['phone10'], 'count' => (int)$r['n'], 'inbound' => (int)$r['ins'],
                      'first_at' => (string)$r['first_at'], 'last_at' => (string)$r['last_at'],
                      'messages' => array_reverse($m->fetchAll(PDO::FETCH_ASSOC))];
        }
        return $out;
    }

    /** How many different numbers are waiting (for a badge). */
    public function unknownCount(): int
    {
        if (!$this->inboxReady()) return 0;
        try { return (int)$this->db->query("SELECT COUNT(DISTINCT phone10) FROM text_unmatched")->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    /**
     * Customers to link a waiting number to: a name or part of a phone number. Newest-active first, at most $limit.
     * @return array<int, array{id: int, name: string, phone: string, mobile: string}>
     */
    public function searchContacts(string $q, int $limit = 8): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $digits = preg_replace('/\D/', '', $q);
        $dl = $digits !== '' ? '%' . $digits . '%' : $like;
        $limit = max(1, min(25, $limit));
        $sql = "SELECT id, first_name, last_name, phone, mobile FROM contacts
                WHERE (first_name || ' ' || last_name) LIKE ? ESCAPE '\\' OR phone LIKE ? OR mobile LIKE ?
                ORDER BY id DESC LIMIT {$limit}";
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql = str_replace("(first_name || ' ' || last_name)", "CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))", $sql);
        }
        $st = $this->db->prepare($sql);
        $st->execute([$like, $dl, $dl]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['id' => (int)$r['id'], 'name' => trim((string)$r['first_name'] . ' ' . (string)$r['last_name']),
                      'phone' => (string)($r['phone'] ?? ''), 'mobile' => (string)($r['mobile'] ?? '')];
        }
        return $out;
    }

    /**
     * Put a waiting number on a customer's record, then move its texts to that customer. Fills a blank mobile, else a
     * blank phone; when both are filled it replaces the one named in $replace ('phone' | 'mobile') and refuses
     * without it, so a number is never overwritten by accident.
     * @return array{ok: bool, error?: string, moved?: int, field?: string}
     */
    public function linkToContact(string $phone10, int $contactId, ?string $replace = null): array
    {
        if (!preg_match('/^\d{10}$/', $phone10)) return ['ok' => false, 'error' => 'That is not a 10-digit number.'];
        $w = $this->db->prepare("SELECT 1 FROM text_unmatched WHERE phone10 = ? LIMIT 1");
        $w->execute([$phone10]);
        if (!$w->fetchColumn()) return ['ok' => false, 'error' => 'No texts are waiting from that number.'];
        $this->map = null;
        [$salt0, $map0] = $this->numbers();
        $owner = $map0[self::hash($phone10, $salt0)] ?? null;
        if ($owner !== null && (int)$owner !== $contactId) return ['ok' => false, 'error' => 'That number already belongs to another customer.'];
        $st = $this->db->prepare("SELECT id, phone, mobile FROM contacts WHERE id = ?");
        $st->execute([$contactId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['ok' => false, 'error' => 'Customer not found.'];
        $have = fn($f) => self::normalize(isset($c[$f]) ? (string)$c[$f] : null);
        if ($have('mobile') === $phone10 || $have('phone') === $phone10) { $field = null; }       // already on the record
        elseif (trim((string)($c['mobile'] ?? '')) === '') $field = 'mobile';
        elseif (trim((string)($c['phone'] ?? '')) === '') $field = 'phone';
        elseif (in_array($replace, ['phone', 'mobile'], true)) $field = $replace;
        else return ['ok' => false, 'error' => 'This customer already has a phone and a mobile number: say which one the new number replaces.'];
        if ($field !== null) {
            $this->db->prepare("UPDATE contacts SET {$field} = ? WHERE id = ?")->execute([$phone10, $contactId]);
        }
        return ['ok' => true, 'moved' => $this->adoptUnmatched(), 'field' => $field];
    }

    /**
     * Mark a number personal: its waiting texts are deleted and only the salted hash is kept, so the bridge never
     * sends this number again. A customer's number is refused (take it off the customer first).
     * @return array{ok: bool, error?: string, deleted?: int}
     */
    public function markPersonal(string $phone10, ?int $userId = null): array
    {
        if (!$this->inboxReady()) return ['ok' => false, 'error' => 'Run migration 1396 first.'];
        if (!preg_match('/^\d{10}$/', $phone10)) return ['ok' => false, 'error' => 'That is not a 10-digit number.'];
        $this->map = null;
        [$salt, $map] = $this->numbers();
        $h = self::hash($phone10, $salt);
        if (isset($map[$h])) return ['ok' => false, 'error' => 'That number belongs to a customer.'];
        $verb = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $this->db->prepare("{$verb} INTO text_personal (number_hash, marked_by) VALUES (?, ?)")->execute([$h, $userId]);
        $del = $this->db->prepare("DELETE FROM text_unmatched WHERE phone10 = ?");
        $del->execute([$phone10]);
        return ['ok' => true, 'deleted' => $del->rowCount()];
    }

    /** Last-seen + counts from the Mac (numbers only). */
    public function heartbeat(array $counts): array
    {
        $hb = ['at' => date('Y-m-d H:i:s')];
        foreach (['scanned', 'one_to_one', 'matched_customers', 'sent', 'stored', 'unmatched'] as $k) {
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
