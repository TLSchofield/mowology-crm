<?php
/**
 * GoogleBusinessService — Google Business Profile (GBP) API integration.
 *
 * Phase 1 platform. Handles:
 *   - OAuth 2.0 Authorization Code flow
 *   - Token exchange and refresh
 *   - Account and location listing
 *   - Local post creation (with optional photo via sourceUrl)
 *   - Token storage via SocialEncryption
 *
 * Required secrets.php constants:
 *   GOOGLE_CLIENT_ID       — OAuth 2.0 client ID
 *   GOOGLE_CLIENT_SECRET   — OAuth 2.0 client secret
 *   GOOGLE_REDIRECT_URI    — Must match exactly in Google Cloud Console
 *                            e.g. https://mowology.ca/crm/api/social/oauth-callback.php
 *
 * GBP API access: You need "Google Business Profile API" enabled in Google Cloud Console
 * and app verification for production use. Test with your own account without review.
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class GoogleBusinessService
{
    private const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL   = 'https://oauth2.googleapis.com/revoke';
    private const ACCOUNTS_URL = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
    private const LOCATIONS_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1/{account}/locations';
    private const POSTS_URL     = 'https://mybusiness.googleapis.com/v4/{locationName}/localPosts';
    private const SCOPE         = 'https://www.googleapis.com/auth/business.manage';

    // ── OAuth flow ───────────────────────────────────────────────────

    /**
     * Build the Google OAuth authorization URL.
     * $state should be a signed value to prevent CSRF.
     */
    public static function getAuthUrl(string $state): string
    {
        if (!defined('GOOGLE_CLIENT_ID') || !GOOGLE_CLIENT_ID) {
            throw new RuntimeException('GOOGLE_CLIENT_ID not set in secrets.php');
        }

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',    // Force refresh_token on every auth
            'state'         => $state,
        ]);
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     * Returns array with keys: access_token, refresh_token, expires_in, scope
     */
    public static function exchangeCode(string $code): array
    {
        $response = self::httpPost(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($response['access_token'])) {
            throw new RuntimeException('Token exchange failed: ' . json_encode($response));
        }

        return $response;
    }

    /**
     * Refresh the access token using the stored refresh token.
     * Returns the new token response array.
     */
    public static function refreshAccessToken(string $refreshToken): array
    {
        $response = self::httpPost(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'grant_type'    => 'refresh_token',
        ]);

        if (empty($response['access_token'])) {
            throw new RuntimeException('Token refresh failed: ' . json_encode($response));
        }

        return $response;
    }

    /**
     * Refresh token in DB if expiring within 10 minutes.
     * Returns the current valid access token.
     */
    public static function ensureFreshToken(array $account): string
    {
        $expiresAt = $account['token_expires_at'] ?? null;
        $refreshEnc = $account['refresh_token_enc'] ?? '';

        // Check if token is still valid (with 10-minute buffer)
        if ($expiresAt) {
            $expiresTs = strtotime($expiresAt);
            if ($expiresTs > time() + 600) {
                return SocialEncryption::decrypt($account['access_token_enc'] ?? '');
            }
        }

        // Need to refresh
        if (!$refreshEnc) {
            throw new RuntimeException('No refresh token available — user must re-authenticate');
        }

        $refreshToken = SocialEncryption::decrypt($refreshEnc);
        if (!$refreshToken) {
            throw new RuntimeException('Could not decrypt refresh token');
        }

        $tokenData = self::refreshAccessToken($refreshToken);

        // Update DB
        $db = getDB();
        $newExpiry = date('Y-m-d H:i:s', time() + (int)($tokenData['expires_in'] ?? 3600));
        $db->prepare("
            UPDATE social_accounts
            SET access_token_enc = ?,
                token_expires_at = ?,
                last_sync_at     = NOW()
            WHERE id = ?
        ")->execute([
            SocialEncryption::encrypt($tokenData['access_token']),
            $newExpiry,
            $account['id'],
        ]);

        // Audit log
        self::auditLog($account['connected_by'] ?? null, 'token_refreshed', 'account', (int)$account['id'], 'Access token refreshed successfully');

        return $tokenData['access_token'];
    }

    // ── Account / Location discovery ─────────────────────────────────

    /**
     * List all GBP accounts for the authenticated user.
     * Returns array of ['accountId' => ..., 'accountName' => ..., 'name' => ...]
     */
    public static function listAccounts(string $accessToken): array
    {
        $data = self::httpGet(self::ACCOUNTS_URL, $accessToken);

        if (isset($data['error'])) {
            $msg = $data['error']['message'] ?? json_encode($data['error']);
            throw new RuntimeException('GBP API error: ' . $msg);
        }

        $accounts = $data['accounts'] ?? [];

        return array_map(function ($a) {
            return [
                'name'        => $a['name'] ?? '',             // e.g. accounts/123456
                'accountName' => $a['accountName'] ?? '',
                'type'        => $a['type'] ?? '',
            ];
        }, $accounts);
    }

    /**
     * List all locations for a GBP account.
     * $accountName = "accounts/123456" (the resource name from listAccounts)
     * Returns array of ['name' => ..., 'title' => ..., 'address' => ...]
     */
    public static function listLocations(string $accessToken, string $accountName): array
    {
        $url = str_replace('{account}', $accountName, self::LOCATIONS_URL);
        $url .= '?readMask=name,title,storefrontAddress';

        $data = self::httpGet($url, $accessToken);
        $locations = $data['locations'] ?? [];

        return array_map(function ($l) {
            $addr = $l['storefrontAddress'] ?? [];
            $addressStr = implode(', ', array_filter([
                $addr['addressLines'][0] ?? '',
                $addr['locality'] ?? '',
                $addr['administrativeArea'] ?? '',
            ]));

            return [
                'name'    => $l['name'] ?? '',                  // e.g. accounts/123/locations/456
                'title'   => $l['title'] ?? '',
                'address' => $addressStr,
            ];
        }, $locations);
    }

    // ── Post creation ────────────────────────────────────────────────

    /**
     * Create a Google Business Profile Local Post.
     *
     * $account: row from social_accounts (with access_token_enc etc.)
     * $post:    row from social_posts
     * $mediaUrls: array of public image URLs (https://mowology.ca/_media/...)
     *
     * Returns: ['success' => true, 'post_id' => '...', 'url' => '...']
     */
    public static function createPost(array $account, array $post, array $mediaUrls = []): array
    {
        $accessToken = self::ensureFreshToken($account);
        $locationName = $account['location_id_external'];

        if (!$locationName) {
            throw new RuntimeException('No GBP location configured for this account');
        }

        // Build post body
        $caption = trim($post['caption']);
        if (!empty($post['hashtags'])) {
            $caption .= "\n\n" . $post['hashtags'];
        }

        // GBP summary max 1500 chars
        if (mb_strlen($caption) > 1500) {
            $caption = mb_substr($caption, 0, 1497) . '...';
        }

        // Variable substitution
        $caption = self::renderVariables($caption, $post);

        $body = [
            'languageCode' => 'en',
            'summary'      => $caption,
            'topicType'    => 'STANDARD',
        ];

        // Call to Action
        if (!empty($post['cta_action']) && !empty($post['cta_url'])) {
            $ctaUrl = $post['cta_url'];
            if (!empty($post['utm_campaign'])) {
                $sep = strpos($ctaUrl, '?') !== false ? '&' : '?';
                $ctaUrl .= $sep . 'utm_source=gbp&utm_medium=localpost&utm_campaign=' . urlencode($post['utm_campaign']);
            }
            $body['callToAction'] = [
                'actionType' => $post['cta_action'],
                'url'        => $ctaUrl,
            ];
        }

        // Media (up to 10 photos for GBP)
        if (!empty($mediaUrls)) {
            $body['media'] = [];
            foreach (array_slice($mediaUrls, 0, 10) as $url) {
                $body['media'][] = [
                    'mediaFormat' => 'PHOTO',
                    'sourceUrl'   => $url,
                ];
            }
        }

        $url = str_replace('{locationName}', $locationName, self::POSTS_URL);
        $result = self::httpPostJson($url, $body, $accessToken);

        if (empty($result['name'])) {
            $error = $result['error']['message'] ?? json_encode($result);
            throw new RuntimeException('GBP createPost failed: ' . $error);
        }

        // Extract post URL from the result (GBP provides a searchUrl)
        $postUrl = $result['searchUrl'] ?? '';

        return [
            'success'  => true,
            'post_id'  => $result['name'],
            'url'      => $postUrl,
            'response' => $result,
        ];
    }

    // ── Variable substitution ────────────────────────────────────────

    /**
     * Replace {variable} placeholders in caption text.
     */
    public static function renderVariables(string $caption, array $post, array $extra = []): string
    {
        $vars = array_merge([
            '{neighborhood}' => $post['neighborhood'] ?? 'your area',
            '{city}'         => $post['city'] ?? 'Vancouver',
            '{service}'      => $post['service_type'] ?? 'landscaping',
            '{date}'         => date('F j, Y'),
            '{month}'        => date('F'),
            '{year}'         => date('Y'),
        ], $extra);

        return str_replace(array_keys($vars), array_values($vars), $caption);
    }

    // ── Audit logging ────────────────────────────────────────────────

    public static function auditLog(
        ?int $userId,
        string $action,
        string $entityType,
        int $entityId,
        string $detail = ''
    ): void {
        try {
            $db = getDB();
            $db->prepare("
                INSERT INTO social_audit_log (user_id, action, entity_type, entity_id, detail, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $detail,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('social_audit_log write failed: ' . $e->getMessage());
        }
    }

    // ── HTTP helpers ─────────────────────────────────────────────────

    private static function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException('CURL error: ' . $err);
        }

        return json_decode($body, true) ?? ['error' => 'Invalid JSON response'];
    }

    private static function httpPostJson(string $url, array $data, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException('CURL error: ' . $err);
        }

        $decoded = json_decode($body, true);
        if ($decoded === null) {
            throw new RuntimeException("GBP API returned non-JSON (HTTP $code): " . substr($body, 0, 200));
        }

        return $decoded;
    }

    private static function httpGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException('CURL error: ' . $err);
        }

        return json_decode($body, true) ?? [];
    }
}
