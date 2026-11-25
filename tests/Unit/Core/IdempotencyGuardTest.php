<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A lost answer + the app's retry must not send a second SMS/email/quote or approve twice (2026-10-21).
 * Runs the real claim/settle SQL on in-memory SQLite (the guard switches dialect for it).
 */
class IdempotencyGuardTest extends TestCase
{
    private const KEY = '3F2504E0-4F89-41D3-9A0C-0305E82C3301';

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

    private function hash(int $user = 7, string $endpoint = 'sales-head-mobile', string $action = 'send', string $key = self::KEY): string
    {
        return IdempotencyGuard::keyHash($user, $endpoint, $action, $key);
    }

    private function claim(PDO $db, int $user = 7, string $endpoint = 'sales-head-mobile', string $action = 'send', string $key = self::KEY): array
    {
        return IdempotencyGuard::claimOrDecide($db, $this->hash($user, $endpoint, $action, $key), $user, $endpoint, $action);
    }

    public function test_keys_must_look_like_the_apps_uuids(): void
    {
        $this->assertNull(IdempotencyGuard::normaliseKey(null));
        $this->assertNull(IdempotencyGuard::normaliseKey(''));
        $this->assertNull(IdempotencyGuard::normaliseKey('short'));
        $this->assertNull(IdempotencyGuard::normaliseKey(str_repeat('a', 129)));
        $this->assertNull(IdempotencyGuard::normaliseKey("abcdefgh'; DROP TABLE x;--"), 'odd characters are ignored, never trusted');
        $this->assertSame(self::KEY, IdempotencyGuard::normaliseKey(' ' . self::KEY . ' '));
    }

    public function test_the_same_uuid_never_collides_across_users_endpoints_or_actions(): void
    {
        $base = $this->hash();
        $this->assertNotSame($base, $this->hash(8));
        $this->assertNotSame($base, $this->hash(7, 'team-mobile'));
        $this->assertNotSame($base, $this->hash(7, 'sales-head-mobile', 'park'), 'a key reused for a different action is a different claim, not a replay');
    }

    public function test_first_tap_claims_and_a_second_simultaneous_tap_is_held_off(): void
    {
        $db = $this->db();
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db)['result']);
        // the first request has not answered yet: the second must NOT run the action
        $this->assertSame(IdempotencyGuard::IN_PROGRESS, $this->claim($db)['result']);
    }

    public function test_a_finished_action_is_replayed_not_run_again(): void
    {
        $db = $this->db();
        $this->claim($db);
        IdempotencyGuard::finish($db, $this->hash(), 200, '{"ok":true,"message":"Sent."}');

        $again = $this->claim($db);
        $this->assertSame(IdempotencyGuard::REPLAY, $again['result']);
        $stored = IdempotencyGuard::decodeStored($again['row']['response_json']);
        $this->assertSame(200, $stored['status']);
        $this->assertSame('{"ok":true,"message":"Sent."}', $stored['body']);
    }

    public function test_a_refusal_releases_the_claim_so_the_retry_re_checks(): void
    {
        $db = $this->db();
        foreach ([[403, '{"success":false,"error":"Permission denied"}'], [409, '{"error":"x"}'], [200, '{"ok":false,"error":"Migration 1140 has not run yet"}'], [200, '{"success":false}']] as [$status, $body]) {
            $this->claim($db, 7, 'sales-head-mobile', 'send', self::KEY);
            IdempotencyGuard::finish($db, $this->hash(), $status, $body);
            $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db)['result'], "status $status $body must not be remembered");
            IdempotencyGuard::finish($db, $this->hash(), 403, '{"x":1}');   // clear for the next loop
        }
    }

    public function test_a_server_error_is_unconfirmed_and_is_not_re_run(): void
    {
        $db = $this->db();
        $this->claim($db);
        // the SMS may already have gone before the fatal: the retry must NOT send it again
        IdempotencyGuard::finish($db, $this->hash(), 500, '{"ok":false}');

        $again = $this->claim($db);
        $this->assertSame(IdempotencyGuard::REPLAY, $again['result']);
        $stored = IdempotencyGuard::decodeStored($again['row']['response_json']);
        $this->assertSame(409, $stored['status']);
        $this->assertStringContainsString('"unconfirmed"', $stored['body']);
    }

    public function test_an_empty_answer_is_unconfirmed_too(): void
    {
        $this->assertSame(IdempotencyGuard::UNCONFIRMED, IdempotencyGuard::settle(200, ''));
        $this->assertSame(IdempotencyGuard::UNCONFIRMED, IdempotencyGuard::settle(503, '{"x":1}'));
    }

    public function test_settle_rules(): void
    {
        $this->assertSame(IdempotencyGuard::STORE, IdempotencyGuard::settle(200, '{"ok":true}'));
        $this->assertSame(IdempotencyGuard::STORE, IdempotencyGuard::settle(200, '{"success":true,"id":4}'));
        $this->assertSame(IdempotencyGuard::STORE, IdempotencyGuard::settle(201, '[1,2]'));
        $this->assertSame(IdempotencyGuard::RELEASE, IdempotencyGuard::settle(404, '{"error":"gone"}'));
        $this->assertSame(IdempotencyGuard::RELEASE, IdempotencyGuard::settle(200, '{"ok":false}'));
    }

    public function test_another_user_or_endpoint_with_the_same_key_is_independent(): void
    {
        $db = $this->db();
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 7)['result']);
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 8)['result']);
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 7, 'team-mobile')['result']);
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db, 7, 'sales-head-mobile', 'park')['result']);
    }

    public function test_a_claim_nobody_settled_is_never_re_run_by_age(): void
    {
        $db = $this->db();
        $this->claim($db);
        $db->exec("UPDATE idempotency_keys SET created_at = datetime('now', '-11 minutes')");
        // the first request may still be running or may have sent: report unconfirmed, do NOT hand the claim to a second runner
        $this->assertSame(IdempotencyGuard::UNCONFIRMED, $this->claim($db)['result']);
        $this->assertSame(IdempotencyGuard::UNCONFIRMED, $this->claim($db)['result']);
    }

    public function test_an_expired_key_starts_over(): void
    {
        $db = $this->db();
        $this->claim($db);
        IdempotencyGuard::finish($db, $this->hash(), 200, '{"ok":true}');
        $db->exec("UPDATE idempotency_keys SET expires_at = datetime('now', '-1 minute')");
        $this->assertSame(IdempotencyGuard::CLAIM, $this->claim($db)['result']);
    }

    public function test_classify_is_pure(): void
    {
        $this->assertSame(IdempotencyGuard::CLAIM, IdempotencyGuard::classify(null));
        $this->assertSame(IdempotencyGuard::REPLAY, IdempotencyGuard::classify(['response_json' => '{"s":200,"b":"x"}', 'age' => 5]));
        $this->assertSame(IdempotencyGuard::IN_PROGRESS, IdempotencyGuard::classify(['response_json' => '', 'age' => 30]));
        $this->assertSame(IdempotencyGuard::UNCONFIRMED, IdempotencyGuard::classify(['response_json' => '', 'age' => IdempotencyGuard::STALE_PENDING_SECONDS + 1]));
    }

    public function test_a_stored_answer_we_did_not_write_is_ignored(): void
    {
        $this->assertNull(IdempotencyGuard::decodeStored('not json'));
        $this->assertNull(IdempotencyGuard::decodeStored('{"x":1}'));
    }
}
