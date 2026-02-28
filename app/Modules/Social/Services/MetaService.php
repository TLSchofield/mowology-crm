<?php
/**
 * MetaService — Facebook + Instagram Business API (Phase 2)
 *
 * Current status: SCAFFOLDED. Not production-ready.
 * All publish methods throw a clear "Phase 2" exception.
 * OAuth URL builder is ready for wiring when Meta app is approved.
 *
 * TODO (Phase 2):
 *   1. Create a Meta App at developers.facebook.com
 *   2. Add products: Facebook Login, Pages API, Instagram Graph API
 *   3. Get permissions approved: pages_manage_posts, instagram_content_publish
 *   4. Add to secrets.php:
 *        define('META_APP_ID',      '...');
 *        define('META_APP_SECRET',  '...');
 *        define('META_REDIRECT_URI','https://mowology.ca/crm/api/social/oauth-callback.php');
 *   5. Implement publishToFacebook() and publishToInstagram() below
 *   6. Remove the "Phase 2 stub" throw from each method
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class MetaService
{
    private const AUTH_URL   = 'https://www.facebook.com/v19.0/dialog/oauth';
    private const TOKEN_URL  = 'https://graph.facebook.com/v19.0/oauth/access_token';
    private const PAGES_URL  = 'https://graph.facebook.com/v19.0/me/accounts';
    private const GRAPH_URL  = 'https://graph.facebook.com/v19.0/';

    // Permissions needed for full posting capability
    private const FB_SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish';

    // ── OAuth ────────────────────────────────────────────────────────

    public static function getAuthUrl(string $state): string
    {
        if (!defined('META_APP_ID') || !META_APP_ID) {
            throw new RuntimeException('META_APP_ID not set in secrets.php. See MetaService.php TODO section.');
        }

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'    => META_APP_ID,
            'redirect_uri' => META_REDIRECT_URI,
            'scope'        => self::FB_SCOPES,
            'state'        => $state,
            'response_type' => 'code',
        ]);
    }

    public static function exchangeCode(string $code): array
    {
        // TODO (Phase 2): Implement code exchange
        throw new RuntimeException('Meta integration is Phase 2. See MetaService.php for setup instructions.');
    }

    public static function ensureFreshToken(array $account): string
    {
        // TODO (Phase 2): Facebook long-lived tokens (60 days), check expiry and extend
        throw new RuntimeException('Meta integration is Phase 2.');
    }

    // ── Facebook posting ─────────────────────────────────────────────

    /**
     * Post to a Facebook Page.
     *
     * TODO (Phase 2):
     * - POST to https://graph.facebook.com/v19.0/{page-id}/feed
     *   with message, link (optional), and attached_media[] for photos
     * - Photo upload: POST https://graph.facebook.com/v19.0/{page-id}/photos
     *   with published=false, url={photoUrl} → returns photo_id
     * - Then use attached_media=[{"media_fbid": photo_id}] in the feed post
     */
    public static function publishToFacebook(array $account, array $post, array $mediaUrls = []): array
    {
        // TODO (Phase 2): Remove this throw and implement
        throw new RuntimeException('Facebook posting is Phase 2. Coming soon.');
    }

    // ── Instagram posting ────────────────────────────────────────────

    /**
     * Post to Instagram Business account (via Facebook Graph API).
     *
     * TODO (Phase 2):
     * - Step 1: Create media container
     *   POST https://graph.facebook.com/v19.0/{ig-user-id}/media
     *   with image_url, caption
     * - Step 2: Publish container
     *   POST https://graph.facebook.com/v19.0/{ig-user-id}/media_publish
     *   with creation_id
     * - For carousels: create individual containers, then a carousel container
     */
    public static function publishToInstagram(array $account, array $post, array $mediaUrls = []): array
    {
        // TODO (Phase 2): Remove this throw and implement
        throw new RuntimeException('Instagram posting is Phase 2. Coming soon.');
    }

    // ── Metrics ──────────────────────────────────────────────────────

    /**
     * Fetch engagement metrics for a published Facebook post.
     * TODO (Phase 2): GET {post-id}/insights?metric=post_impressions,post_clicks,post_reactions_by_type_total
     */
    public static function fetchPostMetrics(string $platformPostId, string $accessToken): array
    {
        // TODO (Phase 2): Implement
        return [];
    }

    // ── Placeholder check ────────────────────────────────────────────

    public static function isConfigured(): bool
    {
        return defined('META_APP_ID') && META_APP_ID !== ''
            && defined('META_APP_SECRET') && META_APP_SECRET !== '';
    }
}
