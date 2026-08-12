<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for FcmService's pure logic — isConfigured() and the private
 * error-code/base64url helpers (via reflection, no I/O involved). send()
 * itself makes real curl/OAuth2 calls — same hand-rolled house style as
 * ApnsService — and isn't mocked here; there's no credentialed environment
 * to exercise it against in CI.
 */
class FcmServiceTest extends TestCase
{
    public function testIsConfiguredFalseWithoutSecrets(): void
    {
        // Neither FCM_SERVICE_ACCOUNT_JSON nor FCM_PROJECT_ID is defined in
        // the test environment (secrets.php is never loaded here, per
        // Cardinal Rule #2) — isConfigured() must fail closed, not throw.
        $this->assertFalse(FcmService::isConfigured());
    }

    public function testExtractFcmErrorCodeFindsUnregistered(): void
    {
        $decoded = [
            'error' => [
                'code'    => 404,
                'status'  => 'NOT_FOUND',
                'details' => [
                    [
                        '@type'     => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ],
                ],
            ],
        ];

        $this->assertSame('UNREGISTERED', $this->invokePrivate('extractFcmErrorCode', [$decoded]));
    }

    public function testExtractFcmErrorCodeReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->invokePrivate('extractFcmErrorCode', [['error' => ['message' => 'oops']]]));
        $this->assertNull($this->invokePrivate('extractFcmErrorCode', [null]));
    }

    public function testBase64urlHasNoPaddingOrUnsafeChars(): void
    {
        $encoded = $this->invokePrivate('base64url', ["\xFF\xEE\xDD any \x00 bytes"]);
        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
    }

    /**
     * @param mixed[] $args
     * @return mixed
     */
    private function invokePrivate(string $method, array $args)
    {
        $ref = new ReflectionMethod(FcmService::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }
}
