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

        // Backward-compatible fallback: tokens encrypted before SOCIAL_ENCRYPTION_KEY
        // was added to secrets.php used DB_PASS as the key source. This keeps the
        // social publisher cron operational until the key migration is complete.
        //
        // ACTION REQUIRED: run /crm/api/social-keymig.php to get the exact value
        // to add as define('SOCIAL_ENCRYPTION_KEY', ...) in secrets.php, then
        // this fallback can be removed.
        if (defined('DB_PASS') && DB_PASS !== '') {
            error_log('SocialEncryption: SOCIAL_ENCRYPTION_KEY not set — using legacy DB_PASS fallback. '
                . 'Run /crm/api/social-keymig.php to complete migration.');
            return str_pad(substr(hash('sha256', DB_PASS, true), 0, 32), 32, "\0");
        }

        throw new \RuntimeException(
            'SOCIAL_ENCRYPTION_KEY is not configured in secrets.php. '
            . 'Run /crm/api/social-keymig.php to generate the value, then add it to secrets.php.'
        );
    }
}
