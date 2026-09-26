<?php
/**
 * SocialEncryption — AES-256-CBC token encryption for stored OAuth credentials.
 *
 * Key source: SOCIAL_ENCRYPTION_KEY constant in secrets.php
 * Value must be a base64-encoded 32-byte random string.
 *
 * ── Why decrypt() distinguishes its failure modes ────────────────────
 * It used to return '' for everything: nothing stored, bad base64, wrong key.
 * Callers could only say "no token", so when the 2026-06-18 key rotation left
 * the Meta page tokens encrypted under the retired DB_PASS-derived key, every
 * publish failed with "Meta account has no page token. Please reconnect" — a
 * message that names the wrong cause and prescribes the wrong fix. Facebook and
 * Instagram posted nothing for three months and the tokens were in the table
 * the whole time. decrypt() now returns null for "present but undecryptable"
 * and '' for "nothing stored"; requireToken() turns each into its own error.
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
     *
     * @return string|null plaintext on success; '' when nothing was stored;
     *                     null when a value IS stored but cannot be decrypted
     *                     (wrong SOCIAL_ENCRYPTION_KEY, truncated or corrupt
     *                     ciphertext). Never conflate null with '' — see the
     *                     class docblock for what that cost.
     */
    public static function decrypt(string $encrypted): ?string
    {
        if ($encrypted === '') {
            return '';
        }

        $key  = self::getKey();
        $data = base64_decode($encrypted, true);

        if ($data === false || strlen($data) < 17) {
            error_log('SocialEncryption::decrypt — stored value is not valid base64/IV+ciphertext (len=' . strlen($encrypted) . ')');
            return null;
        }

        $iv     = substr($data, 0, 16);
        $cipher = substr($data, 16);

        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            error_log('SocialEncryption::decrypt — ciphertext present but would not decrypt under the current '
                . 'SOCIAL_ENCRYPTION_KEY. This is a KEY MISMATCH, not a missing credential.');
            return null;
        }

        return $plain;
    }

    /**
     * Decrypt a stored credential, or throw an error that says which of the two
     * things went wrong. Every caller that needs a usable token should use this
     * rather than interpreting decrypt()'s return value itself.
     *
     * @param string $stored The *_enc column value.
     * @param string $label  What the credential is, for the message
     *                       (e.g. "Facebook page token").
     */
    public static function requireToken(string $stored, string $label): string
    {
        $value = self::decrypt($stored);

        if ($value === null) {
            throw new RuntimeException(
                "Stored {$label} could not be decrypted — SOCIAL_ENCRYPTION_KEY does not match the key "
                . "it was encrypted with. The credential is still in the database; reconnecting the account "
                . "will re-encrypt it under the current key. Do NOT assume the platform revoked it."
            );
        }

        if ($value === '') {
            throw new RuntimeException(
                "No {$label} is stored for this account. Connect it at Social Accounts settings."
            );
        }

        return $value;
    }

    private static function getKey(): string
    {
        if (defined('SOCIAL_ENCRYPTION_KEY') && SOCIAL_ENCRYPTION_KEY !== '') {
            $decoded = base64_decode(SOCIAL_ENCRYPTION_KEY, true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        throw new \RuntimeException(
            'SOCIAL_ENCRYPTION_KEY is not configured in secrets.php. '
            . 'Generate one with: php -r "echo base64_encode(random_bytes(32));"'
        );
    }
}
