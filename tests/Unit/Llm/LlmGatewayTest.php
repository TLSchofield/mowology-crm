<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * LlmGateway — the door every model call goes through. Pins down: the request is passed through
 * untouched, one retry on a retryable failure and none on a 4xx, tokens and cost are recorded
 * (cache priced properly), the kill switch stops the call, and logging never breaks the answer.
 */
class LlmGatewayTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE llm_calls (id INTEGER PRIMARY KEY, site_id INT, head TEXT, skill TEXT, model TEXT,
            input_tokens INT, output_tokens INT, cache_read_tokens INT, cache_write_tokens INT, cost_usd REAL,
            http_code INT, outcome TEXT, attempts INT, duration_ms INT, error TEXT, created_at TEXT)");
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
    }

    private static function ok(array $usage = ['input_tokens' => 1000, 'output_tokens' => 200], string $stop = 'end_turn'): array
    {
        return ['code' => 200, 'body' => json_encode([
            'model' => 'claude-sonnet-5-5', 'stop_reason' => $stop, 'usage' => $usage,
            'content' => [['type' => 'text', 'text' => 'Hello']],
        ])];
    }

    private function ctx(callable $t, array $more = []): array
    {
        return $more + ['head' => 'charlie', 'skill' => 'ask', 'db' => $this->db, 'transport' => $t, 'sleep' => false];
    }

    public function testPassesBodyThroughAndRecordsUsageAndCost(): void
    {
        $seen = null;
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5', 'max_tokens' => 10], $this->ctx(function ($b) use (&$seen) {
            $seen = $b;
            return self::ok();
        }));
        $this->assertSame(['model' => 'claude-sonnet-5-5', 'max_tokens' => 10], $seen);
        $this->assertSame(200, $r['code']);
        $this->assertSame('ok', $r['outcome']);
        $this->assertSame(1, $r['attempts']);
        $this->assertSame('Hello', LlmGateway::text($r['body']));
        // 1000 in * $3/M + 200 out * $15/M = 0.003 + 0.003
        $this->assertEqualsWithDelta(0.006, $r['cost_usd'], 1e-9);
        $row = $this->db->query("SELECT * FROM llm_calls")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('charlie', $row['head']);
        $this->assertSame('ask', $row['skill']);
        $this->assertSame(1000, (int)$row['input_tokens']);
        $this->assertSame('ok', $row['outcome']);
    }

    public function testCacheTokensArePricedAtTheirOwnRates(): void
    {
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5'], $this->ctx(fn() => self::ok([
            'input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 1000000, 'cache_creation_input_tokens' => 1000000,
        ])));
        // read 0.1 * $3 + write 1.25 * $3 = 0.30 + 3.75
        $this->assertEqualsWithDelta(4.05, $r['cost_usd'], 1e-9);
    }

    public function testRetriesOnceOnOverloadThenSucceeds(): void
    {
        $n = 0;
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5'], $this->ctx(function () use (&$n) {
            return ++$n === 1 ? ['code' => 529, 'body' => '{"error":{"message":"Overloaded"}}'] : self::ok();
        }));
        $this->assertSame(2, $n);
        $this->assertSame(2, $r['attempts']);
        $this->assertSame('ok', $r['outcome']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM llm_calls")->fetchColumn(), 'one row per logical call');
    }

    public function testNoRetryOnClientError(): void
    {
        $n = 0;
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5'], $this->ctx(function () use (&$n) {
            $n++;
            return ['code' => 400, 'body' => '{"error":{"message":"bad request"}}'];
        }));
        $this->assertSame(1, $n);
        $this->assertSame('error', $r['outcome']);
        $this->assertSame('bad request', $r['error']);
    }

    public function testServerErrorAndTimeoutAreNotRetried(): void
    {
        foreach ([500, 502, 504, 0] as $code) {
            $n = 0;
            LlmGateway::send(['model' => 'x'], $this->ctx(function () use (&$n, $code) {
                $n++;
                return ['code' => $code, 'body' => ''];
            }));
            $this->assertSame(1, $n, "HTTP $code may already have been billed, so it is not retried");
        }
    }

    public function testRetriesWhenTheRequestNeverLeft(): void
    {
        $n = 0;
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5'], $this->ctx(function () use (&$n) {
            return ++$n === 1 ? ['code' => 0, 'body' => '', 'never_sent' => true] : self::ok();
        }));
        $this->assertSame(2, $n);
        $this->assertSame('ok', $r['outcome']);
    }

    public function testGivesUpAfterTwoAttempts(): void
    {
        $n = 0;
        $r = LlmGateway::send(['model' => 'x'], $this->ctx(function () use (&$n) {
            $n++;
            return ['code' => 503, 'body' => ''];
        }));
        $this->assertSame(2, $n);
        $this->assertSame('error', $r['outcome']);
        $this->assertNull($r['cost_usd'], 'unknown model has no guessed price');
    }

    public function testRefusalIsAnOutcome(): void
    {
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5'], $this->ctx(fn() => self::ok(['input_tokens' => 1, 'output_tokens' => 1], 'refusal')));
        $this->assertSame('refused', $r['outcome']);
        $this->assertSame('Declined', $r['error']);
    }

    public function testTransportExceptionBecomesNetworkFailure(): void
    {
        $r = LlmGateway::send(['model' => 'x'], $this->ctx(function () {
            throw new RuntimeException('boom');
        }));
        $this->assertSame(0, $r['code']);
        $this->assertSame('error', $r['outcome']);
        $this->assertStringContainsString('boom', (string)$r['error']);
    }

    public function testKillSwitchStopsTheCallBeforeItIsMade(): void
    {
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('agents_kill_charlie', '1')");
        $called = false;
        $r = LlmGateway::send(['model' => 'x'], $this->ctx(function () use (&$called) {
            $called = true;
            return self::ok();
        }));
        $this->assertFalse($called);
        $this->assertSame('killed', $r['outcome']);
        $this->assertSame(0, $r['code']);
    }

    public function testGlobalKillSwitchAndOtherHeadUnaffected(): void
    {
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('agents_kill_yui', '1')");
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5'], $this->ctx(fn() => self::ok()));
        $this->assertSame('ok', $r['outcome'], 'only Yui is off');
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('agents_kill_all', '1')");
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5'], $this->ctx(fn() => self::ok()));
        $this->assertSame('killed', $r['outcome']);
    }

    public function testLoggingFailureNeverBreaksTheAnswer(): void
    {
        $this->db->exec("DROP TABLE llm_calls");
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5'], $this->ctx(fn() => self::ok()));
        $this->assertSame('ok', $r['outcome']);
        $this->assertSame('Hello', LlmGateway::text($r['body']));
    }

    public function testWorksWithoutADatabase(): void
    {
        $r = LlmGateway::send(['model' => 'claude-sonnet-5-5'], ['transport' => fn() => self::ok(), 'sleep' => false]);
        $this->assertSame('ok', $r['outcome']);
    }

    public function testCapFailsOpenByDefaultAndClosedWhenAsked(): void
    {
        $broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertTrue(LlmGateway::takeCap($broken, 'x', 5));
        $this->assertFalse(LlmGateway::takeCap($broken, 'x', 5, true));
    }
}
