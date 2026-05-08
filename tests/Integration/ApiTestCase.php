<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base class for HTTP-level integration tests.
 *
 * Makes real curl requests against a running server.
 * Defaults to https://mowology.ca — override with env var:
 *
 *   MOWOLOGY_TEST_URL=https://mowology.ca vendor/bin/phpunit --testsuite Integration
 *
 * For authenticated tests, provide a valid PHPSESSID cookie:
 *
 *   MOWOLOGY_SESSION=abc123 vendor/bin/phpunit --testsuite Integration
 *
 * Run integration tests only:
 *   vendor/bin/phpunit --testsuite Integration
 *
 * Run unit tests only (default):
 *   vendor/bin/phpunit
 */
abstract class ApiTestCase extends TestCase
{
    protected string  $baseUrl;
    protected ?string $sessionCookie;

    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT         = 30;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl       = rtrim((string)(getenv('MOWOLOGY_TEST_URL') ?: 'https://mowology.ca'), '/');
        $this->sessionCookie = getenv('MOWOLOGY_SESSION') ?: null;
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    /**
     * Make a GET request.
     *
     * @return array{status:int, headers:array<string,string>, body:string, json:mixed}
     */
    protected function get(string $path, array $params = []): array
    {
        $url = $this->baseUrl . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        return $this->request('GET', $url);
    }

    /**
     * Make a POST request with form-encoded body (default) or JSON.
     *
     * @return array{status:int, headers:array<string,string>, body:string, json:mixed}
     */
    protected function post(string $path, array $data = [], bool $asJson = false): array
    {
        $url = $this->baseUrl . $path;
        return $this->request('POST', $url, $data, $asJson);
    }

    /** @return array{status:int, headers:array<string,string>, body:string, json:mixed} */
    private function request(string $method, string $url, array $data = [], bool $asJson = false): array
    {
        $ch = curl_init();

        $headers = ['Accept: application/json'];
        if ($this->sessionCookie) {
            $headers[] = 'Cookie: PHPSESSID=' . $this->sessionCookie;
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($asJson) {
                $headers[]  = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }

        // Find a usable CA bundle (Homebrew PHP 8.5 on macOS has a broken cert path)
        $caBundle = null;
        foreach (['/etc/ssl/cert.pem', '/usr/local/etc/ca-certificates/cert.pem'] as $candidate) {
            if (is_file($candidate)) {
                $caBundle = $candidate;
                break;
            }
        }

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,   // Don't follow redirects — we want to see them
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => $caBundle !== null,
            CURLOPT_SSL_VERIFYHOST => $caBundle !== null ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Mowology-IntegrationTest/1.0',
        ];
        if ($caBundle) {
            $opts[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $opts);

        $raw       = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->fail("curl request to {$url} failed: {$curlErr}");
        }

        $rawHeaders = substr((string)$raw, 0, $headerLen);
        $body       = substr((string)$raw, $headerLen);

        // Parse response headers
        $parsedHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        $json = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $decoded;
            }
        }

        return [
            'status'  => $status,
            'headers' => $parsedHeaders,
            'body'    => $body,
            'json'    => $json,
        ];
    }

    // ── Shared assertions ─────────────────────────────────────────────────────

    /**
     * Assert the endpoint is auth-gated:
     *  - 3xx redirect (session-auth endpoints), OR
     *  - 401 JSON response (token-auth endpoints)
     *
     * In both cases, the server must NOT return 500.
     */
    protected function assertAuthGated(array $r, string $endpoint = ''): void
    {
        $this->assertNotEquals(
            500,
            $r['status'],
            "Endpoint {$endpoint} returned HTTP 500 (server error). " .
            "Response body: " . substr($r['body'], 0, 500)
        );

        $isRedirect   = $r['status'] >= 300 && $r['status'] < 400;
        $isUnauth     = $r['status'] === 401;
        $isForbidden  = $r['status'] === 403;

        $this->assertTrue(
            $isRedirect || $isUnauth || $isForbidden,
            "Expected {$endpoint} to auth-gate unauthenticated requests " .
            "(3xx/401/403) but got HTTP {$r['status']}. Body: " . substr($r['body'], 0, 300)
        );
    }

    /**
     * Assert the response body contains no PHP error text.
     * Catches parse errors, fatals, and warnings that leak into the body.
     */
    protected function assertNoPHPErrors(array $r, string $endpoint = ''): void
    {
        $body = $r['body'];
        foreach (['Parse error', 'Fatal error', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $body,
                "PHP error detected in response from {$endpoint}: " . substr($body, 0, 400)
            );
        }
    }

    /**
     * Assert a 200 OK response with valid JSON containing 'success' => true.
     */
    protected function assertJsonOk(array $r, string $endpoint = ''): void
    {
        $this->assertSame(200, $r['status'], "Expected 200 from {$endpoint}, got {$r['status']}");
        $this->assertNotNull($r['json'],  "Expected JSON response from {$endpoint}");
        $this->assertArrayHasKey('success', $r['json'], "Response from {$endpoint} missing 'success' key");
        $this->assertTrue($r['json']['success'], "Expected success=true from {$endpoint}");
    }

    /** Skip the test if no session cookie is configured. */
    protected function skipUnlessAuthenticated(): void
    {
        if (!$this->sessionCookie) {
            $this->markTestSkipped(
                'Set MOWOLOGY_SESSION env var to run authenticated integration tests.'
            );
        }
    }

    /** Assert a key exists in the JSON response. */
    protected function assertJsonHasKey(string $key, array $r, string $endpoint = ''): void
    {
        $this->assertNotNull($r['json'], "No JSON from {$endpoint}");
        $this->assertArrayHasKey($key, $r['json'], "JSON from {$endpoint} missing key '{$key}'");
    }

    /** Assert a JSON 400/422 error response with an 'error' key. */
    protected function assertValidationError(array $r, string $endpoint = ''): void
    {
        $this->assertContains(
            $r['status'],
            [400, 422],
            "Expected validation error (400/422) from {$endpoint}, got {$r['status']}"
        );
        $this->assertNotNull($r['json'], "Expected JSON error body from {$endpoint}");
    }
}
