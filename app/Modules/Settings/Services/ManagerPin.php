<?php
declare(strict_types=1);

/**
 * ManagerPin — the 4-digit pre-trip bypass PIN (ops_settings.manager_override_pin), stored hashed
 * (#10, Fable audit 2026-10-12). Before, it sat in plain text and was compared with hash_equals.
 *
 * - forStorage(): what the settings save path writes ('' clears the PIN = bypass disabled).
 * - verify(): password_verify against a hash; a legacy plaintext value (anything that is not a
 *   password_hash string) is accepted when equal and handed back re-hashed for the caller to store.
 *
 * Pure. Tested in tests/Unit/Settings/ManagerPinTest.php.
 */
final class ManagerPin
{
    public const KEY = 'manager_override_pin';

    public static function isHashed(string $stored): bool
    {
        $info = password_get_info($stored);
        return ($info['algo'] ?? null) !== null && ($info['algo'] ?? 0) !== 0 && ($info['algoName'] ?? 'unknown') !== 'unknown';
    }

    /** Returns the value to store, or throws InvalidArgumentException for a bad PIN. */
    public static function forStorage($value): string
    {
        $pin = trim((string)$value);
        if ($pin === '') return '';
        if (!preg_match('/^\d{4}$/', $pin)) throw new InvalidArgumentException('PIN must be exactly 4 digits.');
        return password_hash($pin, PASSWORD_DEFAULT);
    }

    /** @return array{ok: bool, rehash: ?string} */
    public static function verify(string $pin, string $stored): array
    {
        if ($pin === '' || $stored === '') return ['ok' => false, 'rehash' => null];
        if (self::isHashed($stored)) {
            return ['ok' => password_verify($pin, $stored), 'rehash' => null];
        }
        $ok = hash_equals($stored, $pin);
        return ['ok' => $ok, 'rehash' => $ok ? password_hash($pin, PASSWORD_DEFAULT) : null];
    }
}
