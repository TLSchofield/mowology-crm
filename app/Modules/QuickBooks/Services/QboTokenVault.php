<?php
/**
 * QboTokenVault — QuickBooks tokens encrypted at rest (AES-256-GCM, OpenSSL; production has no sodium).
 *
 * Why GCM and not the CBC SocialEncryption uses: GCM carries an authentication tag, so a value read
 * under the WRONG key fails outright (null) instead of decrypting to garbage that looks like a token.
 * The June 2026 social outage was exactly that: a re-keyed secret left tokens that "decrypted" to junk
 * and the error said "no page token". Here a wrong key is reported as a wrong key.
 *
 * Format: "q1:" . base64(iv[12] . tag[16] . ciphertext). Key: QBO_TOKEN_KEY in secrets.php,
 * 32 bytes as base64 (or 64 hex characters).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class QboTokenVault
{
    public const PREFIX = 'q1:';
    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    /** The key from secrets.php, or null when it is missing or not 32 bytes. */
    public static function keyFromSecrets(): ?string
    {
        if (!defined('QBO_TOKEN_KEY')) return null;
        return self::decodeKey((string)QBO_TOKEN_KEY);
    }

    /** Pure (tested): 64 hex characters or base64 of exactly 32 bytes → the raw key; anything else → null. */
    public static function decodeKey(string $k): ?string
    {
        $k = trim($k);
        if (preg_match('/^[0-9a-f]{64}$/i', $k)) return hex2bin($k);
        $raw = base64_decode($k, true);
        return ($raw !== false && strlen($raw) === 32) ? $raw : null;
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) throw new RuntimeException('QuickBooks token could not be encrypted');
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    /** The token, or null when the value is not ours or the key is wrong (never garbage). */
    public function decrypt(?string $stored): ?string
    {
        if ($stored === null || strpos($stored, self::PREFIX) !== 0) return null;
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) return null;
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
}
