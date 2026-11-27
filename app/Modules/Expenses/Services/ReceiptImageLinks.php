<?php
/**
 * ReceiptImageLinks — receipt photo URLs the iOS app can load.
 *
 * The app shows photos with a plain SwiftUI AsyncImage, which can't send a session
 * cookie or a Bearer header. So JWT endpoints hand it a short-lived HMAC-signed link
 * to /api/expenses/receipt-image (receipt-image.php checks the signature). The web
 * card's links point at /crm/api/serve-receipt.php, which needs a browser session —
 * useless to the app — so JWT endpoints re-sign them here.
 *
 * Pure: the secret and expiry are passed in (callers pass jwtSecret()), so this is
 * unit tested without the JWT stack. No namespace / no autoloader: require_once + static.
 */

class ReceiptImageLinks
{
    /** The original business's address — the default tenant keeps it, byte for byte. */
    public const DEFAULT_ORIGIN = 'https://mowology.ca';
    public const IMAGE_PATH = '/api/expenses/receipt-image';
    public const APP_IMAGE_URL = self::DEFAULT_ORIGIN . self::IMAGE_PATH;

    /** Links are bearer URLs: 15 minutes, not 6 hours (Fable audit 2026-10-12). */
    public const TTL = 900;

    /**
     * The signing key for receipt links, derived from the master secret so a receipt link can never be
     * replayed as — or reveal anything about — a JWT or another signature (Fable audit 2026-10-12).
     */
    public static function key(string $masterSecret): string
    {
        return hash_hmac('sha256', 'receipt-image', $masterSecret);
    }

    /** Is a receipt-image link (m, e, s) valid now? $key is key(jwtSecret()). */
    public static function verify(int $mediaId, int $expiry, string $sig, string $key, int $now): bool
    {
        if ($mediaId <= 0 || $expiry < $now || $sig === '' || $key === '') return false;
        if ($expiry > $now + self::TTL + 60) return false;   // never honour a longer-lived link
        return hash_equals(hash_hmac('sha256', $mediaId . '.' . $expiry, $key), $sig);
    }

    /**
     * Pure: which origin a link is built on. A business other than the original one gets its OWN registered
     * address (the Android/iOS apps only load photos from the business's own host); the original business keeps
     * mowology.ca. Never the request's Host header, which a caller controls. Anything that is not a plain
     * https origin falls back to the original address.
     */
    public static function originFor(bool $isDefaultTenant, string $appUrl, string $ownAddress): string
    {
        if ($isDefaultTenant) return self::DEFAULT_ORIGIN;
        foreach ([$appUrl, $ownAddress] as $u) {
            $u = rtrim($u, '/');
            if (preg_match('#^https://[a-z0-9.-]+$#i', $u)) return strtolower($u);
        }
        return self::DEFAULT_ORIGIN;
    }

    /** The origin for the current request/cron's business (Tenant is booted from the host, or the cron's tenant). */
    public static function origin(): string
    {
        if (!class_exists('Tenant')) return self::DEFAULT_ORIGIN;
        return self::originFor(Tenant::isDefault(), Tenant::appUrl(), Tenant::ownAddress());
    }

    /** The app's signed photo URL — the same format expense-list.php has always produced. $origin: null = this business's. */
    public static function appUrl(int $mediaId, int $expiry, string $secret, ?string $origin = null): string
    {
        $sig = hash_hmac('sha256', $mediaId . '.' . $expiry, $secret);
        return ($origin ?? self::origin()) . self::IMAGE_PATH . '?m=' . $mediaId . '&e=' . $expiry . '&s=' . $sig;
    }

    /** The media id in a /crm/api/serve-receipt.php?id=N… link (signed or not); null if none. */
    public static function mediaIdFrom(?string $url): ?int
    {
        if ($url === null || $url === '') return null;
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) return null;
        parse_str($query, $q);
        $id = isset($q['id']) && is_scalar($q['id']) ? (int)$q['id'] : 0;
        return $id > 0 ? $id : null;
    }

    /** A web receipt link → the app's signed link; null stays null, an unreadable link becomes null. */
    public static function resign(?string $webUrl, int $expiry, string $secret, ?string $origin = null): ?string
    {
        $id = self::mediaIdFrom($webUrl);
        return $id === null ? null : self::appUrl($id, $expiry, $secret, $origin);
    }

    /**
     * Penny's queue (image_url) and duplicate groups (members[].receipt_path), with every
     * photo link swapped for the app's signed link. Nothing else changes.
     * @return array{0: array, 1: array} [queue, dupes]
     */
    public static function resignDesk(array $queue, array $dupes, int $expiry, string $secret, ?string $origin = null): array
    {
        foreach ($queue as &$item) {
            $item['image_url'] = self::resign($item['image_url'] ?? null, $expiry, $secret, $origin);
        }
        unset($item);
        foreach ($dupes as &$group) {
            foreach ($group['members'] ?? [] as $i => $m) {
                $mediaId = !empty($m['receipt_media_id']) ? (int)$m['receipt_media_id'] : self::mediaIdFrom($m['receipt_path'] ?? null);
                $group['members'][$i]['receipt_path'] = $mediaId ? self::appUrl($mediaId, $expiry, $secret, $origin) : null;
            }
        }
        unset($group);
        return [$queue, $dupes];
    }
}
