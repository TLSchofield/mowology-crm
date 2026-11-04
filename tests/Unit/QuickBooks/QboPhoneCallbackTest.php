<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class QboPhoneCallbackTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)');
        return $db;
    }

    private function save(PDO $db): void
    {
        QboConnectionService::savePhoneState($db, ['state' => 'opaque-state', 'at' => 1000, 'site' => 1, 'user_id' => 7]);
    }

    public function test_matching_callback_finishes_as_the_stored_user_and_clears_state(): void
    {
        $db = $this->db(); $this->save($db); $call = [];
        $fake = new class($call) {
            /** @var array<int,mixed> */ public array $call;
            public function __construct(array &$call) { $this->call =& $call; }
            public function finish(array &$session, string $code, string $state, string $realm, int $userId): array
            { $this->call = [$session, $code, $state, $realm, $userId]; return ['ok' => true, 'message' => 'Connected.']; }
        };
        $handler = new QboPhoneCallbackService($db, static function () use ($fake) { return $fake; }, static function (): int { return 1001; });
        $result = $handler->handle(['code' => 'code', 'state' => 'opaque-state', 'realmId' => '123'], false);
        $this->assertTrue($result['ok']);
        $this->assertSame(7, $call[4]);
        $this->assertSame('opaque-state', $call[0]['qbo_oauth']['state']);
        $this->assertNull(QboConnectionService::phoneState($db));
    }

    public function test_matching_callback_clears_state_when_finish_throws(): void
    {
        $db = $this->db(); $this->save($db);
        $fake = new class {
            public function finish(array &$session, string $code, string $state, string $realm, int $userId): array
            { throw new RuntimeException('transport failed'); }
        };
        $handler = new QboPhoneCallbackService($db, static function () use ($fake) { return $fake; }, static function (): int { return 1001; });
        $result = $handler->handle(['code' => 'code', 'state' => 'opaque-state', 'realmId' => '123'], false);
        $this->assertFalse($result['ok']);
        $this->assertNull(QboConnectionService::phoneState($db));
    }

    public function test_unmatched_or_crm_state_falls_through_without_consuming_phone_state(): void
    {
        $db = $this->db(); $this->save($db);
        $handler = new QboPhoneCallbackService($db, null, static function (): int { return 1001; });
        $this->assertNull($handler->handle(['state' => 'wrong'], false));
        $this->assertNull($handler->handle(['state' => 'opaque-state'], true));
        $this->assertNotNull(QboConnectionService::phoneState($db));
    }

    public function test_matching_cancel_clears_state(): void
    {
        $db = $this->db(); $this->save($db); $cancelled = false;
        $fake = new class($cancelled) {
            public bool $cancelled;
            public function __construct(bool &$cancelled) { $this->cancelled =& $cancelled; }
            public function cancel(array &$session, string $state): bool { $this->cancelled = true; return true; }
        };
        $handler = new QboPhoneCallbackService($db, static function () use ($fake) { return $fake; }, static function (): int { return 1001; });
        $result = $handler->handle(['state' => 'opaque-state', 'error' => 'access_denied'], false);
        $this->assertFalse($result['ok']); $this->assertTrue($cancelled);
        $this->assertNull(QboConnectionService::phoneState($db));
    }
}
