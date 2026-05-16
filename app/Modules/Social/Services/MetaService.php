<?php
/**
 * MetaService — Facebook + Instagram Business API (Phase 2)
 *
 * Handles:
 *   - OAuth 2.0 Authorization Code flow (Facebook login)
 *   - Short-lived → long-lived user token exchange (60-day tokens)
 *   - Facebook Page listing (page tokens are permanent — no refresh needed)
 *   - Facebook Page post publishing (text, single photo, multi-photo)
 *   - Instagram Business account publishing (single image, carousel)
 *   - Post metrics (insights) fetch for social_metrics_daily sync
 *
 * Required secrets.php constants:
 *   META_APP_ID       — From Meta Developer App
 *   META_APP_SECRET   — From Meta Developer App
 *   META_REDIRECT_URI — https://mowology.ca/crm/api/social/oauth-callback.php
 *
 * Token strategy:
 *   We store the Facebook PAGE token (not the user token) in social_accounts.
 *   Page tokens never expire — token_expires_at is NULL for Meta accounts.
 *   The user token obtained during OAuth is ephemeral (lives only in session
 *   during the page-picker step) and is discarded after page selection.
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class MetaService
{
    private const AUTH_URL  = 'https://www.facebook.com/v19.0/dialog/oauth';
    private const TOKEN_URL = 'https://graph.facebook.com/v19.0/oauth/access_token';
    private const PAGES_URL = 'https://graph.facebook.com/v19.0/me/accounts';
    private const GRAPH_URL = 'https://graph.facebook.com/v19.0/';

    // OAuth scopes for page management. Instagram publishing uses the page token
    // (not the user token) so instagram_content_publish is not needed here —
    // Instagram access flows through the linked Instagram Business account on the Page.
    private const FB_SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts';

    // ── OAuth ────────────────────────────────────────────────────────

    public static function getAuthUrl(string $state): string
    {
        if (!defined('META_APP_ID') || !META_APP_ID) {
            throw new RuntimeException('META_APP_ID not set in secrets.php. See MetaService.php for setup instructions.');
        }

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => META_APP_ID,
            'redirect_uri'  => META_REDIRECT_URI,
            'scope'         => self::FB_SCOPES,
            'state'         => $state,
            'response_type' => 'code',
        ]);
    }

    /**
     * Exchange an authorization code for a long-lived user token (60 days).
     *
     * Two-step: code → short-lived user token → long-lived user token.
     * Returns array with keys: access_token, token_type, expires_in (seconds).
     *
     * NOTE: This returns a USER token, not a page token.
     * The page token is fetched separately via listPages() and is what we store.
     */
    public static function exchangeCode(string $code): array
    {
        // Step 1: Exchange code for short-lived user token
        $short = self::httpPostForm(self::TOKEN_URL, [
            'client_id'     => META_APP_ID,
            'client_secret' => META_APP_SECRET,
            'redirect_uri'  => META_REDIRECT_URI,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($short['access_token'])) {
            throw new RuntimeException('Meta token exchange failed: ' . json_encode($short));
        }

        // Step 2: Extend to long-lived token (60 days ≈ 5,183,944 seconds)
        $longUrl = self::TOKEN_URL . '?' . http_build_query([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => META_APP_ID,
            'client_secret'     => META_APP_SECRET,
            'fb_exchange_token' => $short['access_token'],
        ]);

        $long = self::httpGet($longUrl);

        if (empty($long['access_token'])) {
            // Fallback: return the short-lived token — still functional for the page-picker step
            error_log('MetaService: long-lived token exchange failed, using short-lived: ' . json_encode($long));
            return $short;
        }

        return $long;
    }

    /**
     * List all Facebook Pages the authenticated user manages.
     *
     * Returns array of:
     *   ['page_id', 'page_name', 'page_token', 'ig_user_id' (may be null)]
     *
     * page_token is the permanent page access token.
     * ig_user_id is the Instagram Business Account ID linked to this page.
     */
    public static function listPages(string $userToken): array
    {
        // Step 1: get pages + their page tokens using the user token
        $url = self::PAGES_URL . '?' . http_build_query([
            'fields'       => 'id,name,access_token',
            'access_token' => $userToken,
        ]);

        $data = self::httpGet($url);

        if (isset($data['error'])) {
            throw new RuntimeException('Meta listPages failed: ' . ($data['error']['message'] ?? json_encode($data)));
        }

        $pages = $data['data'] ?? [];

        // Step 2: for each page, look up instagram_business_account using the
        // PAGE token (not user token) — page tokens have broader access and
        // don't require Instagram OAuth scopes to read this field.
        return array_map(static function (array $p): array {
            $pageToken = $p['access_token'] ?? '';
            $igUserId  = null;

            if ($pageToken) {
                try {
                    $igUrl  = self::GRAPH_URL . $p['id'] . '?' . http_build_query([
                        'fields'       => 'instagram_business_account',
                        'access_token' => $pageToken,
                    ]);
                    $igData = self::httpGet($igUrl);
                    $igUserId = $igData['instagram_business_account']['id'] ?? null;
                } catch (\Throwable $e) {
                    // Non-fatal — page may not have Instagram linked
                    error_log('listPages: could not fetch IG for page ' . $p['id'] . ': ' . $e->getMessage());
                }
            }

            return [
                'page_id'    => $p['id'],
                'page_name'  => $p['name'],
                'page_token' => $pageToken,
                'ig_user_id' => $igUserId,
            ];
        }, $pages);
    }

    // ── Token management ─────────────────────────────────────────────

    /**
     * Return the page access token for this account.
     *
     * Facebook page tokens never expire — no refresh logic needed.
     * token_expires_at is NULL for all Meta accounts.
     */
    public static function ensureFreshToken(array $account): string
    {
        $token = SocialEncryption::decrypt($account['access_token_enc'] ?? '');

        if (!$token) {
            throw new RuntimeException(
                'Meta account has no page token. Please reconnect the account at Social Accounts settings.'
            );
        }

        return $token;
    }

    // ── Facebook posting ─────────────────────────────────────────────

    /**
     * Publish a post to a Facebook Page.
     *
     * Handles three cases:
     *   - 0 media: plain text post to /feed
     *   - 1 media: single photo post to /photos
     *   - 2-10 media: multi-photo post (upload unpublished, then post to /feed)
     *
     * Returns ['success' => true, 'post_id' => '...', 'url' => '...', 'response' => [...]]
     */
    public static function publishToFacebook(array $account, array $post, array $mediaUrls = []): array
    {
        $pageToken = self::ensureFreshToken($account);
        $pageId    = $account['account_id_external'];

        if (!$pageId) {
            throw new RuntimeException('No Facebook Page ID configured for this account.');
        }

        $caption = self::buildCaption($post, 63206);

        $mediaCount = count($mediaUrls);

        if ($mediaCount === 0) {
            // Text-only post
            $result = self::graphPost("{$pageId}/feed", [
                'message'      => $caption,
                'access_token' => $pageToken,
            ]);

            self::assertNoError($result, 'Facebook feed post');

            $postId = $result['id'] ?? '';
            return [
                'success'  => true,
                'post_id'  => $postId,
                'url'      => "https://www.facebook.com/{$postId}",
                'response' => $result,
            ];
        }

        if ($mediaCount === 1) {
            // Single photo
            $result = self::graphPost("{$pageId}/photos", [
                'url'          => $mediaUrls[0],
                'message'      => $caption,
                'access_token' => $pageToken,
            ]);

            self::assertNoError($result, 'Facebook single photo post');

            // /photos returns {id, post_id} — post_id is the feed post ID (needed for insights)
            $postId = $result['post_id'] ?? $result['id'] ?? '';
            return [
                'success'  => true,
                'post_id'  => $postId,
                'url'      => "https://www.facebook.com/{$postId}",
                'response' => $result,
            ];
        }

        // Multi-photo: upload each unpublished, then attach to feed post
        $mediaFbids = [];
        foreach (array_slice($mediaUrls, 0, 10) as $imageUrl) {
            $uploadResult = self::graphPost("{$pageId}/photos", [
                'url'          => $imageUrl,
                'published'    => 'false',
                'access_token' => $pageToken,
            ]);
            self::assertNoError($uploadResult, 'Facebook unpublished photo upload');
            $mediaFbids[] = ['media_fbid' => $uploadResult['id']];
        }

        $result = self::graphPost("{$pageId}/feed", [
            'message'        => $caption,
            'attached_media' => json_encode($mediaFbids),
            'access_token'   => $pageToken,
        ]);

        self::assertNoError($result, 'Facebook multi-photo feed post');

        $postId = $result['id'] ?? '';
        return [
            'success'  => true,
            'post_id'  => $postId,
            'url'      => "https://www.facebook.com/{$postId}",
            'response' => $result,
        ];
    }

    // ── Instagram posting ────────────────────────────────────────────

    /**
     * Publish a post to an Instagram Business account.
     *
     * Instagram requires at least one image.
     * Uses the two-step container create → publish flow.
     * For multiple images, creates a carousel container.
     *
     * Returns ['success' => true, 'post_id' => '...', 'url' => '...', 'response' => [...]]
     */
    public static function publishToInstagram(array $account, array $post, array $mediaUrls = []): array
    {
        $pageToken = self::ensureFreshToken($account);
        $metaData  = json_decode($account['meta_json'] ?? '{}', true);
        $igUserId  = $metaData['ig_user_id'] ?? null;

        if (!$igUserId) {
            throw new RuntimeException(
                'No Instagram Business account linked to this Facebook Page. ' .
                'Link an Instagram Business or Creator account in your Facebook Page settings, then reconnect.'
            );
        }

        if (empty($mediaUrls)) {
            throw new RuntimeException('Instagram requires at least one image. Add media to this post before publishing to Instagram.');
        }

        $caption    = self::buildCaption($post, 2200);
        $mediaCount = count($mediaUrls);

        if ($mediaCount === 1) {
            // Single image: create container → publish
            $container = self::graphPost("{$igUserId}/media", [
                'image_url'    => $mediaUrls[0],
                'caption'      => $caption,
                'access_token' => $pageToken,
            ]);
            self::assertNoError($container, 'Instagram media container creation');

            $creationId = $container['id'] ?? '';
            $published  = self::graphPost("{$igUserId}/media_publish", [
                'creation_id'  => $creationId,
                'access_token' => $pageToken,
            ]);
            self::assertNoError($published, 'Instagram media publish');

            $mediaId = $published['id'] ?? '';
            return [
                'success'  => true,
                'post_id'  => $mediaId,
                'url'      => "https://www.instagram.com/p/{$mediaId}",
                'response' => $published,
            ];
        }

        // Carousel: create child containers → carousel container → publish
        $childIds = [];
        foreach (array_slice($mediaUrls, 0, 10) as $imageUrl) {
            $child = self::graphPost("{$igUserId}/media", [
                'image_url'        => $imageUrl,
                'is_carousel_item' => 'true',
                'access_token'     => $pageToken,
            ]);
            self::assertNoError($child, 'Instagram carousel child container');
            $childIds[] = $child['id'];
        }

        $carousel = self::graphPost("{$igUserId}/media", [
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $childIds),
            'caption'      => $caption,
            'access_token' => $pageToken,
        ]);
        self::assertNoError($carousel, 'Instagram carousel container creation');

        $published = self::graphPost("{$igUserId}/media_publish", [
            'creation_id'  => $carousel['id'],
            'access_token' => $pageToken,
        ]);
        self::assertNoError($published, 'Instagram carousel publish');

        $mediaId = $published['id'] ?? '';
        return [
            'success'  => true,
            'post_id'  => $mediaId,
            'url'      => "https://www.instagram.com/p/{$mediaId}",
            'response' => $published,
        ];
    }

    // ── Metrics ──────────────────────────────────────────────────────

    /**
     * Fetch engagement metrics for a published Facebook Page post.
     *
     * Returns ['impressions', 'reach', 'clicks', 'likes', 'comments_count', 'shares', 'saves']
     * On API error, logs and returns zeros — metric sync must be non-fatal.
     */
    public static function fetchFacebookMetrics(string $platformPostId, string $pageToken): array
    {
        $defaults = ['impressions' => 0, 'reach' => 0, 'clicks' => 0, 'likes' => 0, 'comments_count' => 0, 'shares' => 0, 'saves' => 0];

        $url = self::GRAPH_URL . $platformPostId . '/insights?' . http_build_query([
            'metric'       => 'post_impressions,post_impressions_unique,post_clicks_by_type_unique,post_reactions_by_type_total,post_activity_by_action_type',
            'access_token' => $pageToken,
        ]);

        try {
            $data = self::httpGet($url);

            if (isset($data['error'])) {
                error_log('MetaService fetchFacebookMetrics error for ' . $platformPostId . ': ' . json_encode($data['error']));
                return $defaults;
            }

            $metrics = [];
            foreach ($data['data'] ?? [] as $item) {
                $metrics[$item['name']] = $item['values'][0]['value'] ?? 0;
            }

            // post_reactions and post_activity return nested arrays — sum them
            $reactions = $metrics['post_reactions_by_type_total'] ?? 0;
            if (is_array($reactions)) {
                $reactions = (int)array_sum($reactions);
            }

            $activity = $metrics['post_activity_by_action_type'] ?? [];
            $comments = is_array($activity) ? (int)($activity['comment'] ?? 0) : 0;
            $shares   = is_array($activity) ? (int)($activity['share'] ?? 0) : 0;

            $clicks = $metrics['post_clicks_by_type_unique'] ?? 0;
            if (is_array($clicks)) {
                $clicks = (int)array_sum($clicks);
            }

            return [
                'impressions'   => (int)($metrics['post_impressions'] ?? 0),
                'reach'         => (int)($metrics['post_impressions_unique'] ?? 0),
                'clicks'        => $clicks,
                'likes'         => $reactions,
                'comments_count' => $comments,
                'shares'        => $shares,
                'saves'         => 0, // Not available via Facebook post insights
            ];
        } catch (\Throwable $e) {
            error_log('MetaService fetchFacebookMetrics exception for ' . $platformPostId . ': ' . $e->getMessage());
            return $defaults;
        }
    }

    /**
     * Fetch engagement metrics for a published Instagram media post.
     *
     * Returns ['impressions', 'reach', 'clicks', 'likes', 'comments_count', 'shares', 'saves']
     * On API error, logs and returns zeros — metric sync must be non-fatal.
     */
    public static function fetchInstagramMetrics(string $igMediaId, string $pageToken): array
    {
        $defaults = ['impressions' => 0, 'reach' => 0, 'clicks' => 0, 'likes' => 0, 'comments_count' => 0, 'shares' => 0, 'saves' => 0];

        $url = self::GRAPH_URL . $igMediaId . '/insights?' . http_build_query([
            'metric'       => 'impressions,reach,likes,comments,shares,saved',
            'access_token' => $pageToken,
        ]);

        try {
            $data = self::httpGet($url);

            if (isset($data['error'])) {
                error_log('MetaService fetchInstagramMetrics error for ' . $igMediaId . ': ' . json_encode($data['error']));
                return $defaults;
            }

            $metrics = [];
            foreach ($data['data'] ?? [] as $item) {
                $metrics[$item['name']] = $item['values'][0]['value'] ?? ($item['value'] ?? 0);
            }

            return [
                'impressions'    => (int)($metrics['impressions'] ?? 0),
                'reach'          => (int)($metrics['reach'] ?? 0),
                'clicks'         => 0, // IG media insights don't expose link clicks
                'likes'          => (int)($metrics['likes'] ?? 0),
                'comments_count' => (int)($metrics['comments'] ?? 0),
                'shares'         => (int)($metrics['shares'] ?? 0),
                'saves'          => (int)($metrics['saved'] ?? 0),
            ];
        } catch (\Throwable $e) {
            error_log('MetaService fetchInstagramMetrics exception for ' . $igMediaId . ': ' . $e->getMessage());
            return $defaults;
        }
    }

    /**
     * Generic metrics fetch — routes to platform-specific method.
     * Kept for backward compatibility with SocialPublisher dispatch.
     */
    public static function fetchPostMetrics(string $platformPostId, string $accessToken): array
    {
        return self::fetchFacebookMetrics($platformPostId, $accessToken);
    }

    // ── Configuration check ──────────────────────────────────────────

    public static function isConfigured(): bool
    {
        return defined('META_APP_ID') && META_APP_ID !== ''
            && defined('META_APP_SECRET') && META_APP_SECRET !== '';
    }

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * Build and sanitize post caption with hashtags and variable substitution.
     */
    private static function buildCaption(array $post, int $maxLen): string
    {
        $caption = trim($post['caption'] ?? '');

        if (!empty($post['hashtags'])) {
            $caption .= "\n\n" . trim($post['hashtags']);
        }

        $caption = self::renderVariables($caption, $post);

        if (mb_strlen($caption) > $maxLen) {
            $caption = mb_substr($caption, 0, $maxLen - 3) . '...';
        }

        return $caption;
    }

    /**
     * Replace {variable} placeholders in caption text.
     * Matches GoogleBusinessService::renderVariables() signature.
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

    /**
     * Assert that a Graph API response does not contain an error key.
     * Throws RuntimeException with the error message if it does.
     */
    private static function assertNoError(array $result, string $context): void
    {
        if (isset($result['error'])) {
            $msg  = $result['error']['message'] ?? json_encode($result['error']);
            $code = $result['error']['code'] ?? 0;
            throw new RuntimeException("Meta API error [{$context}] (code {$code}): {$msg}");
        }
    }

    /**
     * POST to the Graph API base URL + $path with form-encoded $data.
     * access_token must be included in $data.
     */
    private static function graphPost(string $path, array $data): array
    {
        $url = self::GRAPH_URL . ltrim($path, '/');
        return self::httpPostForm($url, $data);
    }

    /**
     * HTTP GET — token passed in query string (Meta's pattern, not Bearer header).
     */
    private static function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException('CURL GET error: ' . $err);
        }

        $decoded = json_decode($body, true);
        if ($decoded === null) {
            throw new RuntimeException('Meta API returned non-JSON: ' . substr($body, 0, 200));
        }

        return $decoded;
    }

    /**
     * HTTP POST with form-encoded body — access_token embedded in $data.
     */
    private static function httpPostForm(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException('CURL POST error: ' . $err);
        }

        $decoded = json_decode($body, true);
        if ($decoded === null) {
            throw new RuntimeException("Meta API returned non-JSON (HTTP {$code}): " . substr($body, 0, 200));
        }

        return $decoded;
    }
}
