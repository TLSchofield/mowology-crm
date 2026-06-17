<?php
/**
 * SocialEncryption — AES-256-CBC token encryption for stored OAuth credentials.
 *
 * Key source: SOCIAL_ENCRYPTION_KEY constant in secrets.php
 * Value must be a base64-encoded 32-byte random string.
 *
 * Generate a new key:
 *   php -r "echo base64_encode(random_bytes(32));"
 *
 * Then add to secrets.php:
 *   define('SOCIAL_ENCRYPTION_KEY', '<base64-output-here>');
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class SocialEncryption
{
    private const CIPHER = 'AES-256-CBC';

    /**
     * Encrypt a plaintext string.
     * Returns base64( IV + ciphertext ) or empty string on failure.
     */
    public static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $key = self::getKey();
        $iv  = random_bytes(16);

        $cipher = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            error_log('SocialEncryption::encrypt failed');
            return '';
        }

        return base64_encode($iv . $cipher);
    }

    /**
     * Decrypt a previously encrypted string.
     * Returns plaintext or empty string on failure.
     */
    public static function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $key  = self::getKey();
        $data = base64_decode($encrypted, true);

        if ($data === false || strlen($data) < 17) {
            return '';
        }

        $iv     = substr($data, 0, 16);
        $cipher = substr($data, 16);

        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }

    private static function getKey(): string
    {
        if (defined('SOCIAL_ENCRYPTION_KEY') && SOCIAL_ENCRYPTION_KEY !== '') {
            $decoded = base64_decode(SOCIAL_ENCRYPTION_KEY, true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        // No safe fallback exists — a key derived from DB_PASS is guessable by
        // anyone with DB credentials. Fail loudly so the problem is caught in dev,
        // not silently in production with weak encryption.
        throw new \RuntimeException(
            'SOCIAL_ENCRYPTION_KEY is not configured in secrets.php. ' .
            'Generate one with: php -r "echo base64_encode(random_bytes(32));"'
        );
    }
}
