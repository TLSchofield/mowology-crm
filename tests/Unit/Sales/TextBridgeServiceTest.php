<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The messages bridge's CRM side: numbers are matched by salted hash (the same hash
 * bridge.py computes), only known customers are stored, and nothing stores a raw number.
 */
class TextBridgeServiceTest extends TestCase
{
    private const SALT = 'mowology-test-salt';
    // Shared test vector — the same one is asserted in tools/messages-bridge/tests/test_bridge.py
    private const VECTOR_HASH = 'e622d119b1c0a6d90294c6ff640ee4f688b234b4b443e9066c565ff8256b3762';

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT, mobile TEXT, is_active INTEGER DEFAULT 1)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, mailbox TEXT NOT NULL, message_key TEXT NOT NULL UNIQUE,
                   direction TEXT NOT NULL, channel TEXT NOT NULL DEFAULT 'email', contact_id INTEGER, from_addr TEXT, to_addr TEXT,
                   subject TEXT, snippet TEXT, sent_at TEXT NOT NULL)");
        $db->exec("INSERT INTO ops_settings VALUES ('text_bridge_salt', '" . self::SALT . "', '')");
        $db->exec("INSERT INTO contacts (id, first_name, last_name, phone, mobile, is_active) VALUES
                   (7, 'Jodi', 'Peacock', '+1 (604) 555-0101', NULL, 1),
                   (9, 'Ron', 'Smith', '604-555-0102', '778.555.0103', 1),
                   (11, 'Old', 'Customer', '6045550104', NULL, 0),
                   (12, 'ZZTEST', 'Person', '6045550105', NULL, 1)");
        return $db;
    }

    private function msg(array $extra = []): array
    {
        return $extra + ['hash' => self::VECTOR_HASH, 'direction' => 'in', 'sent_at' => strtotime('2026-10-05 09:30:00'),
                         'guid' => 'p:0/ABC-123', 'text' => 'Yes please, go ahead Monday'];
    }

    public function test_numbers_normalize_to_the_last_ten_digits(): void
    {
        $this->assertSame('6045550101', TextBridgeService::normalize('+1 (604) 555-0101'));
        $this->assertSame('6045550101', TextBridgeService::normalize('6045550101'));
        $this->assertSame('6045550101', TextBridgeService::normalize('604.555.0101'));
        $this->assertNull(TextBridgeService::normalize('someone@icloud.com'));
        $this->assertNull(TextBridgeService::normalize('72727'));
        $this->assertNull(TextBridgeService::normalize(''));
        $this->assertNull(TextBridgeService::normalize(null));
    }

    public function test_hash_matches_the_python_bridge(): void
    {
        $this->assertSame(self::VECTOR_HASH, TextBridgeService::hash('6045550101', self::SALT));
        $this->assertSame(self::VECTOR_HASH, TextBridgeService::hash(TextBridgeService::normalize('+1 (604) 555-0101'), self::SALT));
    }

    public function test_numbers_skip_test_and_inactive_contacts_and_cover_mobiles(): void
    {
        [$salt, $map] = (new TextBridgeService($this->db()))->numbers();
        $this->assertSame(self::SALT, $salt);
        $this->assertSame(7, $map[self::VECTOR_HASH]);
        $this->assertSame(9, $map[TextBridgeService::hash('7785550103', $salt)]);
        $this->assertSame(9, $map[TextBridgeService::hash('6045550102', $salt)]);
        $this->assertArrayNotHasKey(TextBridgeService::hash('6045550104', $salt), $map);   // inactive
        $this->assertArrayNotHasKey(TextBridgeService::hash('6045550105', $salt), $map);   // ZZTEST
        $this->assertCount(3, $map);
    }

    public function test_a_salt_is_created_once_when_missing(): void
    {
        $db = $this->db();
        $db->exec("DELETE FROM ops_settings");
        $a = (new TextBridgeService($db))->salt();
        $b = (new TextBridgeService($db))->salt();
        $this->assertSame(32, strlen($a));
        $this->assertSame($a, $b);
    }

    public function test_ingest_maps_a_text_to_the_contact_without_storing_the_number(): void
    {
        $db = $this->db();
        $res = (new TextBridgeService($db))->ingest([$this->msg(), $this->msg(['guid' => 'p:0/ABC-124', 'direction' => 'out', 'text' => 'See you then'])]);
        $this->assertSame(['stored' => 2, 'dupe' => 0, 'unknown' => 0, 'invalid' => 0], $res);
        $rows = $db->query("SELECT * FROM sales_messages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame('imessage', $rows[0]['mailbox']);
        $this->assertSame('sms', $rows[0]['channel']);
        $this->assertSame('imsg-p:0/ABC-123', $rows[0]['message_key']);
        $this->assertSame('inbound', $rows[0]['direction']);
        $this->assertSame('outbound', $rows[1]['direction']);
        $this->assertSame(7, (int)$rows[0]['contact_id']);
        $this->assertSame('Yes please, go ahead Monday', $rows[0]['snippet']);
        $this->assertSame(date('Y-m-d H:i:s', strtotime('2026-10-05 09:30:00')), $rows[0]['sent_at']);
        $this->assertSame(['text', 'text'], [$rows[0]['from_addr'], $rows[0]['to_addr']]);
        $this->assertStringNotContainsString('555', json_encode($rows));
    }

    public function test_a_resent_text_is_stored_once(): void
    {
        $svc = new TextBridgeService($this->db());
        $svc->ingest([$this->msg()]);
        $this->assertSame(['stored' => 0, 'dupe' => 1, 'unknown' => 0, 'invalid' => 0], $svc->ingest([$this->msg()]));
    }

    public function test_an_unknown_hash_is_rejected(): void
    {
        $db = $this->db();
        $res = (new TextBridgeService($db))->ingest([
            $this->msg(['hash' => TextBridgeService::hash('6045550199', self::SALT)]),
            $this->msg(['hash' => TextBridgeService::hash('6045550104', self::SALT)]),   // inactive contact
        ]);
        $this->assertSame(2, $res['unknown']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM sales_messages")->fetchColumn());
    }

    public function test_malformed_messages_are_refused(): void
    {
        $res = (new TextBridgeService($this->db()))->ingest([
            $this->msg(['hash' => 'not-a-hash']),
            $this->msg(['guid' => "x'; DROP TABLE"]),
            $this->msg(['direction' => 'sideways']),
            $this->msg(['sent_at' => 'yesterday']),
            $this->msg(['text' => "  \u{FFFC} "]),
            'just a string',
        ]);
        $this->assertSame(['stored' => 0, 'dupe' => 0, 'unknown' => 0, 'invalid' => 6], $res);
    }

    public function test_long_texts_are_cut_short(): void
    {
        $row = TextBridgeService::row($this->msg(['text' => str_repeat('word ', 400)]), [self::VECTOR_HASH => 7]);
        $this->assertLessThanOrEqual(TextBridgeService::SNIPPET_MAX, mb_strlen($row['snippet']));
    }

    public function test_heartbeat_keeps_counts_only_and_status_flags_silence(): void
    {
        $svc = new TextBridgeService($this->db());
        $this->assertNull($svc->status());
        $hb = $svc->heartbeat(['scanned' => 12, 'one_to_one' => 9, 'matched_customers' => 2, 'sent' => 3, 'stored' => 3, 'version' => '1.0', 'text' => 'leak?']);
        $this->assertArrayNotHasKey('text', $hb);
        $svc->heartbeat(['scanned' => 1]);                     // a second beat updates the same row
        $st = $svc->status();
        $this->assertFalse($st['silent']);
        $this->assertTrue($svc->status(time() + 2 * 3600)['silent']);
    }
}
