<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A lost answer + the app's retry must not send a second SMS/email/quote or approve twice (2026-10-21).
 * Runs the real claim/replay SQL on in-memory SQLite (the guard switches dialect for it).
 */
class IdempotencyGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../app/Core/IdempotencyGuard.php';
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE idempotency_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT, key_hash TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL,
            endpoint TEXT NOT NULL, action TEXT NOT NULL, response_json TEXT NOT NULL,
            created_at TEXT NOT NULL, expires_at TEXT NOT NULL)');
        return $db;
    }

    private function claim(PDO $db, int $user, string $endpoint, string $key): array
    {
        return IdempotencyGuard::claimOrDecide($db, IdempotencyGuard::keyHash($user, $endpoint, $key), $user, $endpoint, 'send');
    }

    public function test_keys_must_look_like_the_apps_uuids(): void
    {
        $this->assertNull(IdempotencyGuard::normaliseKey(null));
        $this->assertNull(IdempotencyGuard::normaliseKey(''));
        $this->assertNull(IdempotencyGuard::normaliseKey('short'));
        $this->assertNull(IdempotencyGuard::normaliseKey(str_repeat('a', 129)));
        $this->assertNull(IdempotencyGuard::normaliseKey("abcdefgh'; DROP TABLE x;--"), 'odd characters are ignored, never trusted');
        $this->assertSame('3F2504E0-4F89-41D3-9A0C-0305E82C3301', IdempotencyGuard::normaliseKey(' 3F2504E0-4F89-41D3-9A0C-0305E82C3301 '));
    }

    public function test_the_same_uuid_never_collides_across_users_or_endpoints(): void
    {
        $k = '3F2504E0-4F89-41D3-9A0C-0305E82C3301';
        $this->assertNotSame(IdempotencyGuard::keyHash(1, 'sales-head-mobile', $k), IdempotencyGuard::keyHash(2, 'sales-head-mobile', $k));
        $this->assertNotSame(IdempotencyGuard::keyHash(1, 'sales-head-mobile', $k), IdempotencyGuard::keyHash(1, 'team-mobile', $k));
    }

    public function test_first_tap_claims_and_a_second_simultaneous_tap_is_held_off(): void
    {
        $db = $this->db();
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 7, 'sales-head-mobile', 'key-aaaa-1111')['result']);
        // the first request has not answered yet: the second must NOT run the action
        $this->assertSame(IdempotencyGuard::IN_PROGRESS, $this->claim($db, 7, 'sales-head-mobile', 'key-aaaa-1111')['result']);
    }

    public function test_a_finished_action_is_replayed_not_run_again(): void
    {
        $db = $this->db();
        $hash = IdempotencyGuard::keyHash(7, 'sales-head-mobile', 'key-aaaa-1111');
        $this->claim($db, 7, 'sales-head-mobile', 'key-aaaa-1111');
        IdempotencyGuard::finish($db, $hash, 200, '{"ok":true,"message":"Sent."}');

        $again = $this->claim($db, 7, 'sales-head-mobile', 'key-aaaa-1111');
        $this->assertSame(IdempotencyGuard::REPLAY, $again['result']);
        $stored = IdempotencyGuard::decodeStored($again['row']['response_json']);
        $this->assertSame(200, $stored['status']);
        $this->assertSame('{"ok":true,"message":"Sent."}', $stored['body']);
    }

    public function test_a_definite_refusal_is_replayed_too(): void
    {
        $db = $this->db();
        $hash = IdempotencyGuard::keyHash(7, 'receipt-actions', 'key-bbbb-2222');
        $this->claim($db, 7, 'receipt-actions', 'key-bbbb-2222');
        IdempotencyGuard::finish($db, $hash, 409, '{"success":false,"error":"Already approved"}');
        $this->assertSame(IdempotencyGuard::REPLAY, $this->claim($db, 7, 'receipt-actions', 'key-bbbb-2222')['result']);
    }

    public function test_a_server_error_releases_the_claim_so_the_retry_really_runs(): void
    {
        $db = $this->db();
        $hash = IdempotencyGuard::keyHash(7, 'sales-head-mobile', 'key-cccc-3333');
        $this->claim($db, 7, 'sales-head-mobile', 'key-cccc-3333');
        IdempotencyGuard::finish($db, $hash, 500, '{"ok":false}');
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 7, 'sales-head-mobile', 'key-cccc-3333')['result']);
    }

    public function test_an_empty_answer_is_never_remembered(): void
    {
        $this->assertFalse(IdempotencyGuard::shouldStore(200, ''));
        $this->assertFalse(IdempotencyGuard::shouldStore(503, '{"x":1}'));
        $this->assertTrue(IdempotencyGuard::shouldStore(200, '{"x":1}'));
        $this->assertTrue(IdempotencyGuard::shouldStore(403, '{"x":1}'));
    }

    public function test_another_user_or_endpoint_with_the_same_key_is_independent(): void
    {
        $db = $this->db();
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 7, 'sales-head-mobile', 'key-dddd-4444')['result']);
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 8, 'sales-head-mobile', 'key-dddd-4444')['result']);
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 7, 'team-mobile', 'key-dddd-4444')['result']);
    }

    public function test_a_claim_nobody_finished_is_taken_over_once_it_is_stale(): void
    {
        $db = $this->db();
        $this->claim($db, 7, 'sales-head-mobile', 'key-eeee-5555');
        $db->exec("UPDATE idempotency_keys SET created_at = datetime('now', '-11 minutes')");
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 7, 'sales-head-mobile', 'key-eeee-5555')['result']);
        // and the taker holds it fresh: a further tap is held off again
        $this->assertSame(IdempotencyGuard::IN_PROGRESS, $this->claim($db, 7, 'sales-head-mobile', 'key-eeee-5555')['result']);
    }

    public function test_an_expired_key_starts_over(): void
    {
        $db = $this->db();
        $hash = IdempotencyGuard::keyHash(7, 'sales-head-mobile', 'key-ffff-6666');
        $this->claim($db, 7, 'sales-head-mobile', 'key-ffff-6666');
        IdempotencyGuard::finish($db, $hash, 200, '{"ok":true}');
        $db->exec("UPDATE idempotency_keys SET expires_at = datetime('now', '-1 minute')");
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 7, 'sales-head-mobile', 'key-ffff-6666')['result']);
    }

    public function test_classify_is_pure(): void
    {
        $this->assertSame(IdempotencyGuard::CLAIM, IdempotencyGuard::classify(null));
        $this->assertSame(IdempotencyGuard::REPLAY, IdempotencyGuard::classify(['response_json' => '{"s":200,"b":"x"}', 'age' => 5]));
        $this->assertSame(IdempotencyGuard::IN_PROGRESS, IdempotencyGuard::classify(['response_json' => '', 'age' => 30]));
        $this->assertSame(IdempotencyGuard::TAKEOVER, IdempotencyGuard::classify(['response_json' => '', 'age' => IdempotencyGuard::STALE_PENDING_SECONDS + 1]));
    }

    public function test_a_stored_answer_we_did_not_write_is_ignored(): void
    {
        $this->assertNull(IdempotencyGuard::decodeStored('not json'));
        $this->assertNull(IdempotencyGuard::decodeStored('{"x":1}'));
    }
}
