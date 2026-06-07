<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for TrackimoService.
 *
 * HTTP is mocked via a subclass that intercepts httpRequest(). No real
 * network calls — the canned responses live in $this->responses keyed by
 * a "METHOD URL" prefix.
 *
 * Token cache writes are sandboxed to a per-test temp file.
 */
class TrackimoServiceTest extends TestCase
{
    private string $tokenCachePath;

    protected function setUp(): void
    {
        parent::setUp();
        // Define constants once across the whole test run.
        foreach ([
            'TRACKIMO_CLIENT_ID'     => 'test-client',
            'TRACKIMO_CLIENT_SECRET' => 'test-secret',
            'TRACKIMO_REDIRECT_URI'  => 'https://example.test/callback',
            'TRACKIMO_USERNAME'      => 'user@test',
            'TRACKIMO_PASSWORD'      => 'pw',
        ] as $name => $value) {
            if (!defined($name)) define($name, $value);
        }
        $this->tokenCachePath = tempnam(sys_get_temp_dir(), 'trackimo_test_') . '.json';
        @unlink($this->tokenCachePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->tokenCachePath);
        parent::tearDown();
    }

    public function testAuthenticateUsesCachedTokenWhenFresh(): void
    {
        file_put_contents($this->tokenCachePath, json_encode([
            'access_token' => 'cached-token-xyz',
            'expires_at'   => time() + 3600,
        ]));

        $svc = new FakeTrackimoService($this->tokenCachePath, []);
        $this->assertSame('cached-token-xyz', $svc->authenticate());
        $this->assertSame(0, $svc->callCount(), 'should not make HTTP calls when cache is fresh');
    }

    public function testAuthenticateRunsFullLoginWhenCacheMissing(): void
    {
        $svc = new FakeTrackimoService($this->tokenCachePath, [
            'POST https://app.trackimo.com/api/internal/v2/user/login' => ['status' => 200, 'body' => '{}'],
            'GET https://app.trackimo.com/api/v3/oauth2/auth' => [
                'status'  => 302,
                'headers' => ['location' => 'https://example.test/callback?code=auth-code-123'],
            ],
            'POST https://app.trackimo.com/api/v3/oauth2/token' => [
                'status' => 200,
                'json'   => ['access_token' => 'new-token-abc', 'refresh_token' => 'r', 'expires_in' => 3600],
            ],
        ]);

        $this->assertSame('new-token-abc', $svc->authenticate());
        $this->assertSame(3, $svc->callCount());

        // Verify it persisted the token to cache for next time.
        $cached = json_decode((string)file_get_contents($this->tokenCachePath), true);
        $this->assertSame('new-token-abc', $cached['access_token']);
        $this->assertGreaterThan(time(), $cached['expires_at']);
    }

    public function testAuthenticateThrowsWhenAuthCodeMissing(): void
    {
        $svc = new FakeTrackimoService($this->tokenCachePath, [
            'POST https://app.trackimo.com/api/internal/v2/user/login' => ['status' => 200],
            'GET https://app.trackimo.com/api/v3/oauth2/auth' => [
                'status'  => 302,
                'headers' => ['location' => 'https://example.test/callback?error=denied'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/auth code/i');
        $svc->authenticate();
    }

    public function testGetLastLocationNormalizesTrackimoShape(): void
    {
        file_put_contents($this->tokenCachePath, json_encode([
            'access_token' => 't', 'expires_at' => time() + 3600,
        ]));

        $svc = new FakeTrackimoService($this->tokenCachePath, [
            'GET https://app.trackimo.com/api/v3/user' => [
                'status' => 200, 'json' => ['account_id' => 42],
            ],
            'POST https://app.trackimo.com/api/v3/accounts/42/locations/filter' => [
                'status' => 200,
                'json'   => [[
                    'lat'     => 49.2827,
                    'lng'     => -123.1207,
                    'speed'   => 35.5,
                    'course'  => 270,
                    'battery' => 78,
                    'updated' => 1717693200000,   // 2024-06-06 something
                ]],
            ],
        ]);

        $loc = $svc->getLastLocation('372356216');
        $this->assertIsArray($loc);
        $this->assertSame(49.2827, $loc['lat']);
        $this->assertSame(-123.1207, $loc['lng']);
        $this->assertSame(35.5, $loc['speed_kph']);
        $this->assertSame(270, $loc['heading']);
        $this->assertSame(78, $loc['battery_pct']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $loc['recorded_at']);
    }

    public function testGetLastLocationReturnsNullOnEmptyResponse(): void
    {
        file_put_contents($this->tokenCachePath, json_encode([
            'access_token' => 't', 'expires_at' => time() + 3600,
        ]));

        $svc = new FakeTrackimoService($this->tokenCachePath, [
            'GET https://app.trackimo.com/api/v3/user' => ['status' => 200, 'json' => ['account_id' => 42]],
            'POST https://app.trackimo.com/api/v3/accounts/42/locations/filter' => [
                'status' => 200, 'json' => [],
            ],
        ]);

        $this->assertNull($svc->getLastLocation('372356216'));
    }

    public function test401TriggersTokenRefreshAndRetry(): void
    {
        file_put_contents($this->tokenCachePath, json_encode([
            'access_token' => 'stale', 'expires_at' => time() + 3600,
        ]));

        $svc = new FakeTrackimoService($this->tokenCachePath, [
            // user endpoint: first call 401, second call 200 (after re-auth)
            'GET https://app.trackimo.com/api/v3/user' => [
                ['status' => 401, 'body' => 'expired'],
                ['status' => 200, 'json' => ['account_id' => 99]],
            ],
            'POST https://app.trackimo.com/api/internal/v2/user/login' => ['status' => 200],
            'GET https://app.trackimo.com/api/v3/oauth2/auth' => [
                'status' => 302,
                'headers' => ['location' => 'https://example.test/callback?code=fresh-code'],
            ],
            'POST https://app.trackimo.com/api/v3/oauth2/token' => [
                'status' => 200,
                'json' => ['access_token' => 'fresh-token', 'expires_in' => 3600],
            ],
        ]);

        $this->assertSame(99, $svc->getAccountId());
    }
}

/**
 * Test double that records calls and returns canned responses keyed by "METHOD URL".
 * If a key maps to a list, responses are consumed in order.
 */
class FakeTrackimoService extends TrackimoService
{
    private array $responses;
    private int $calls = 0;

    public function __construct(string $tokenCachePath, array $responses)
    {
        parent::__construct($tokenCachePath);
        $this->responses = $responses;
    }

    public function callCount(): int { return $this->calls; }

    protected function httpRequest(string $method, string $url, array $opts = []): array
    {
        $this->calls++;
        // Fixtures key on "METHOD url-without-query" — keeps the test fixtures readable.
        $key = $method . ' ' . preg_replace('/\?.*$/', '', $url);
        if (!array_key_exists($key, $this->responses)) {
            // Fall back to exact match (some fixtures include the query intentionally)
            $key = $method . ' ' . $url;
        }
        if (!array_key_exists($key, $this->responses)) {
            throw new RuntimeException("Unexpected HTTP call: {$method} {$url}");
        }
        $raw = $this->responses[$key];

        // Sequenced responses: list-of-lists
        if (isset($raw[0]) && is_array($raw[0])) {
            $next = array_shift($this->responses[$key]);
            $raw = $next;
        }

        $body = $raw['body'] ?? (isset($raw['json']) ? json_encode($raw['json']) : '');
        return [
            'status'  => $raw['status'] ?? 200,
            'headers' => $raw['headers'] ?? [],
            'body'    => (string)$body,
            'json'    => $raw['json'] ?? (is_string($body) && $body !== '' ? json_decode($body, true) : null),
        ];
    }
}
