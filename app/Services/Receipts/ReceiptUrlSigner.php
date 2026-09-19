<?php
/**
 * /app/Services/Receipts/ReceiptUrlSigner.php
 *
 * HMAC-signed URLs for receipt images. Lets the iOS AsyncImage (or any
 * stateless image fetcher) load receipt thumbnails without a session
 * cookie or JWT Bearer header — the signature itself proves the URL
 * was minted by an authenticated request.
 *
 * URL format:  /crm/api/serve-receipt.php?id=N&exp=TS&sig=HEX
 *   id   media_assets.id
 *   exp  unix timestamp when this URL stops being valid
 *   sig  hex HMAC-SHA256 over "{id}|{exp}" keyed by the JWT secret
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

/**
 * Build a signed URL for a receipt image.
 *
 * @param int    $mediaId    media_assets.id
 * @param int    $ttlSeconds How long the URL should remain valid (default 1h)
 * @param string $baseUrl    Optional absolute URL prefix (e.g. https://mowology.ca)
 * @return string Absolute or root-relative URL
 */
function signReceiptUrl(int $mediaId, int $ttlSeconds = 3600, string $baseUrl = ''): string
{
    $exp = time() + max(60, $ttlSeconds);
    $sig = hash_hmac('sha256', $mediaId . '|' . $exp, jwtSecret());
    $path = '/crm/api/serve-receipt.php?id=' . $mediaId . '&exp=' . $exp . '&sig=' . $sig;
    return $baseUrl !== '' ? rtrim($baseUrl, '/') . $path : $path;
}

/**
 * Verify a signed URL.
 *
 * @return bool true if the signature is valid AND the URL has not expired
 */
function verifyReceiptUrlSignature(int $mediaId, int $exp, string $sig): bool
{
    if ($exp <= 0 || $exp < time()) {
        return false;
    }
    $expected = hash_hmac('sha256', $mediaId . '|' . $exp, jwtSecret());
    return hash_equals($expected, $sig);
}
