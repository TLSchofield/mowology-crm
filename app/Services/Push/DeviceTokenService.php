<?php
/**
 * DeviceTokenService — one place that registers and retires push device tokens.
 *
 * Fable review 2026-10-12: a phone's token stayed active for every user who had ever signed in on
 * it, so a crew phone handed to someone else (or signed out) kept getting the previous user's
 * pushes — quotes, payments, client names. Now:
 *   - register(): the token belongs to the user who registered it last; every other user's row
 *     for that token is switched off (and this user's other tokens on the platform, as before);
 *   - forget(): sign-out switches the token off for everyone (the next user re-registers).
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

final class DeviceTokenService
{
    /** Clean a token for its platform, or '' when it cannot be a real token. */
    public static function clean(string $token, string $platform): string
    {
        $token = trim($token);
        if ($platform === 'ios') {
            $token = (string)preg_replace('/[^a-f0-9]/i', '', $token);
            return strlen($token) >= 32 ? strtolower($token) : '';
        }
        $token = (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', $token);
        return strlen($token) >= 100 ? $token : '';
    }

    public static function register(PDO $db, int $userId, string $token, string $platform): void
    {
        $db->prepare("
            INSERT INTO device_tokens (user_id, device_token, platform, created_at, last_seen_at)
            VALUES (?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE platform = VALUES(platform), last_seen_at = NOW(), is_active = 1
        ")->execute([$userId, $token, $platform]);

        // The phone now belongs to this user: nobody else's pushes go to it.
        $db->prepare("UPDATE device_tokens SET is_active = 0 WHERE device_token = ? AND user_id <> ?")
           ->execute([$token, $userId]);

        // One active token per user per platform (a re-registered token means the old one is stale).
        $db->prepare("UPDATE device_tokens SET is_active = 0 WHERE user_id = ? AND platform = ? AND device_token <> ?")
           ->execute([$userId, $platform, $token]);
    }

    /** Sign-out on this phone: no pushes to it for anyone until someone signs in again. */
    /**
     * Switch a token off. With $userId (the API's "remove" — a caller signing out) only that user's
     * row is touched: any login could otherwise silence pushes to someone else's phone by posting
     * its token (2026-10-12 review). Without it (server-side logout of the session's own token) all rows.
     */
    public static function forget(PDO $db, string $token, ?int $userId = null): int
    {
        if ($token === '') return 0;
        if ($userId !== null) {
            $st = $db->prepare("UPDATE device_tokens SET is_active = 0 WHERE device_token = ? AND user_id = ? AND is_active = 1");
            $st->execute([$token, $userId]);
            return $st->rowCount();
        }
        $st = $db->prepare("UPDATE device_tokens SET is_active = 0 WHERE device_token = ? AND is_active = 1");
        $st->execute([$token]);
        return $st->rowCount();
    }
}
