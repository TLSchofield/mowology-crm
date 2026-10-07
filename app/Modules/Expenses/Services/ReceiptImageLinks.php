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
    public const APP_IMAGE_URL = 'https://mowology.ca/api/expenses/receipt-image';

    /** The app's signed photo URL — the same format expense-list.php has always produced. */
    public static function appUrl(int $mediaId, int $expiry, string $secret): string
    {
        $sig = hash_hmac('sha256', $mediaId . '.' . $expiry, $secret);
        return self::APP_IMAGE_URL . '?m=' . $mediaId . '&e=' . $expiry . '&s=' . $sig;
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
    public static function resign(?string $webUrl, int $expiry, string $secret): ?string
    {
        $id = self::mediaIdFrom($webUrl);
        return $id === null ? null : self::appUrl($id, $expiry, $secret);
    }

    /**
     * Penny's queue (image_url) and duplicate groups (members[].receipt_path), with every
     * photo link swapped for the app's signed link. Nothing else changes.
     * @return array{0: array, 1: array} [queue, dupes]
     */
    public static function resignDesk(array $queue, array $dupes, int $expiry, string $secret): array
    {
        foreach ($queue as &$item) {
            $item['image_url'] = self::resign($item['image_url'] ?? null, $expiry, $secret);
        }
        unset($item);
        foreach ($dupes as &$group) {
            foreach ($group['members'] ?? [] as $i => $m) {
                $mediaId = !empty($m['receipt_media_id']) ? (int)$m['receipt_media_id'] : self::mediaIdFrom($m['receipt_path'] ?? null);
                $group['members'][$i]['receipt_path'] = $mediaId ? self::appUrl($mediaId, $expiry, $secret) : null;
            }
        }
        unset($group);
        return [$queue, $dupes];
    }
}
