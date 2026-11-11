<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The contract that a three-month outage bought.
 *
 * Facebook and Instagram published nothing from 2026-06-18 to 2026-09-25 because
 * SocialEncryption::decrypt() returned '' for every failure mode, so a token that
 * was present but encrypted under a retired key was reported as "Meta account has
 * no page token. Please reconnect" — the wrong cause and the wrong fix, which is
 * why nobody chased it.
 *
 * These tests exist to stop that collapse being reintroduced: "cannot decrypt"
 * and "nothing stored" must stay two different answers, all the way out to the
 * error message a human reads.
 */
final class SocialCredentialContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('SOCIAL_ENCRYPTION_KEY')) {
            define('SOCIAL_ENCRYPTION_KEY', base64_encode(str_repeat('k', 32)));
        }
    }

    public function testRoundTripSurvives(): void
    {
        $token = 'EAAG' . str_repeat('x', 180);
        $enc   = SocialEncryption::encrypt($token);

        $this->assertNotSame('', $enc, 'encrypt() must produce ciphertext');
        $this->assertSame($token, SocialEncryption::decrypt($enc));
    }

    public function testNothingStoredIsEmptyStringNotNull(): void
    {
        $this->assertSame('', SocialEncryption::decrypt(''));
    }

    public function testCiphertextFromAnotherKeyIsNullNotEmptyString(): void
    {
        // Exactly the 2026-06-18 situation: a real, well-formed token encrypted
        // under a key we no longer hold.
        $foreignKey = random_bytes(32);
        $iv         = random_bytes(16);
        $cipher     = openssl_encrypt('a-real-page-token', 'AES-256-CBC', $foreignKey, OPENSSL_RAW_DATA, $iv);
        $stored     = base64_encode($iv . $cipher);

        $this->assertNull(
            SocialEncryption::decrypt($stored),
            'A key mismatch must be null — returning \'\' makes it indistinguishable from an empty column'
        );
    }

    public function testGarbageIsNullNotEmptyString(): void
    {
        $this->assertNull(SocialEncryption::decrypt('not base64 at all !!!'));
        $this->assertNull(SocialEncryption::decrypt(base64_encode('tooshort')));
    }

    public function testRequireTokenBlamesTheKeyWhenTheKeyIsWrong(): void
    {
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt('tok', 'AES-256-CBC', random_bytes(32), OPENSSL_RAW_DATA, $iv);

        try {
            SocialEncryption::requireToken(base64_encode($iv . $cipher), 'Facebook page token');
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('could not be decrypted', $e->getMessage());
            $this->assertStringContainsString('still in the database', $e->getMessage());
            $this->assertStringNotContainsString('No Facebook page token is stored', $e->getMessage());
        }
    }

    public function testRequireTokenBlamesTheAbsenceWhenNothingIsStored(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No Facebook page token is stored/');
        SocialEncryption::requireToken('', 'Facebook page token');
    }

    // ── Health check: local failures must not cost a request ─────────

    public function testHealthReportsKeyMismatchWithoutCallingGraph(): void
    {
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt('tok', 'AES-256-CBC', random_bytes(32), OPENSSL_RAW_DATA, $iv);

        $health = MetaService::checkTokenHealth([
            'id'                  => 1,
            'platform'            => 'facebook',
            'account_id_external' => '310655189023724',
            'access_token_enc'    => base64_encode($iv . $cipher),
            'meta_json'           => '{}',
        ]);

        $this->assertFalse($health['ok']);
        $this->assertSame('decrypt_failed', $health['reason']);
    }

    public function testHealthReportsMissingTokenWithoutCallingGraph(): void
    {
        $health = MetaService::checkTokenHealth([
            'id'                  => 1,
            'platform'            => 'instagram',
            'account_id_external' => '310655189023724',
            'access_token_enc'    => '',
            'meta_json'           => '{}',
        ]);

        $this->assertFalse($health['ok']);
        $this->assertSame('no_token', $health['reason']);
    }

    // ── The version pin ─────────────────────────────────────────────

    public function testGraphVersionIsPinnedAndNotAnExpiredOne(): void
    {
        $version = MetaService::graphVersion();
        $this->assertMatchesRegularExpression('/^v\d+\.\d+$/', $version);

        // v20.0 expired 2026-09-24; everything below it is long gone. An expired
        // pin does not fail loudly — Graph reroutes it to the oldest live version
        // — so this assertion is the only thing that will ever complain.
        $major = (int)ltrim(explode('.', $version)[0], 'v');
        $this->assertGreaterThanOrEqual(
            21,
            $major,
            'Graph version pin has expired. Bump DEFAULT_GRAPH_VERSION (or set META_GRAPH_VERSION) '
            . 'and re-read the metric-deprecation note in MetaService.'
        );
    }
}
