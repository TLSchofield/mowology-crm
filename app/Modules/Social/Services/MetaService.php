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
    /**
     * Graph API version — a DELIBERATE pin, overridable as META_GRAPH_VERSION
     * in secrets.php so a bump needs no code deploy.
     *
     * v23.0 was released 2025-05-29 and is supported until roughly 2027-05.
     * **Check that date before assuming this is current.** The previous pin was
     * v19.0, which expired 2026-05-21 and kept working anyway: for the Graph API
     * (unlike the Marketing API) a call to an expired version is silently
     * rerouted to the oldest version still alive, so an expired pin never
     * announces itself — it just means you are running on a version you did not
     * choose, changing under you with no deploy.
     *
     * Before bumping past v25.0, note that post_impressions and
     * post_impressions_unique are deprecated above it. The insights fetch below
     * drops metrics the API rejects instead of failing the whole request, so a
     * bump degrades rather than breaks — but confirm what the Facebook numbers
     * mean afterwards rather than trusting that they still arrive.
     */
    private const DEFAULT_GRAPH_VERSION = 'v23.0';

    public static function graphVersion(): string
    {
        if (defined('META_GRAPH_VERSION') && preg_match('/^v\d+\.\d+$/', (string)META_GRAPH_VERSION)) {
            return (string)META_GRAPH_VERSION;
        }

        return self::DEFAULT_GRAPH_VERSION;
    }

    private static function authUrl(): string
    {
        return 'https://www.facebook.com/' . self::graphVersion() . '/dialog/oauth';
    }

    private static function tokenUrl(): string
    {
        return 'https://graph.facebook.com/' . self::graphVersion() . '/oauth/access_token';
    }

    private static function pagesUrl(): string
    {
        return 'https://graph.facebook.com/' . self::graphVersion() . '/me/accounts';
    }

    private static function graphUrl(): string
    {
        return 'https://graph.facebook.com/' . self::graphVersion() . '/';
    }

    // OAuth scopes for page + Instagram management.
    // instagram_content_publish is required to create media containers and publish
    // to an Instagram Business account linked to a Facebook Page.
    // The page access token inherits these scopes from the user token used to generate it,
    // so all required scopes must be requested during the initial OAuth flow.
    private const FB_SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_content_publish,instagram_basic,instagram_manage_comments';

    // ── OAuth ────────────────────────────────────────────────────────

    public static function getAuthUrl(string $state): string
    {
        if (!defined('META_APP_ID') || !META_APP_ID) {
            throw new RuntimeException('META_APP_ID not set in secrets.php. See MetaService.php for setup instructions.');
        }

        return self::authUrl() . '?' . http_build_query([
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
        $short = self::httpPostForm(self::tokenUrl(), [
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
        $longUrl = self::tokenUrl() . '?' . http_build_query([
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
        $url = self::pagesUrl() . '?' . http_build_query([
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
                    // Try both field names — Meta renamed the field in newer API versions.
                    // instagram_business_account = classic Graph API field
                    // connected_instagram_account = newer alias, accessible without Instagram OAuth scopes
                    $igUrl  = self::graphUrl() . $p['id'] . '?' . http_build_query([
                        'fields'       => 'instagram_business_account,connected_instagram_account',
                        'access_token' => $pageToken,
                    ]);
                    $igData   = self::httpGet($igUrl);
                    $igUserId = $igData['instagram_business_account']['id']
                             ?? $igData['connected_instagram_account']['id']
                             ?? null;
                    error_log('listPages IG lookup page=' . $p['id'] . ' result=' . json_encode($igData));
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

    /**
     * Look up the Instagram Business Account ID linked to a Facebook Page.
     * Uses the page token directly — no Instagram OAuth scope required.
     * Returns the ig_user_id string, or null if no Instagram account is linked.
     */
    public static function fetchInstagramUserId(string $pageId, string $pageToken): ?string
    {
        // Method 1: field on the page object
        $fields = ['instagram_business_account', 'connected_instagram_account'];
        foreach ($fields as $field) {
            try {
                $url  = self::graphUrl() . $pageId . '?' . http_build_query([
                    'fields'       => $field,
                    'access_token' => $pageToken,
                ]);
                $data = self::httpGet($url);
                error_log('fetchInstagramUserId field=' . $field . ' response=' . json_encode($data));
                $igId = $data[$field]['id'] ?? null;
                if ($igId) {
                    return (string)$igId;
                }
            } catch (\Throwable $e) {
                error_log('fetchInstagramUserId: ' . $field . ' failed: ' . $e->getMessage());
            }
        }

        // Method 2: instagram_accounts edge on the page
        try {
            $url  = self::graphUrl() . $pageId . '/instagram_accounts?' . http_build_query([
                'access_token' => $pageToken,
            ]);
            $data = self::httpGet($url);
            error_log('fetchInstagramUserId instagram_accounts edge response=' . json_encode($data));
            $igId = $data['data'][0]['id'] ?? null;
            if ($igId) {
                return (string)$igId;
            }
        } catch (\Throwable $e) {
            error_log('fetchInstagramUserId: instagram_accounts edge failed: ' . $e->getMessage());
        }

        return null;
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
        // requireToken() separates "nothing stored" from "stored but will not
        // decrypt". Collapsing those two into "has no page token" is what hid a
        // three-month publishing outage — see SocialEncryption's docblock.
        return SocialEncryption::requireToken($account['access_token_enc'] ?? '', 'Facebook page token');
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
        $pageId    = $account['account_id_external'] ?? '';

        // ig_user_id can be absent if the Instagram lookup failed during OAuth (timing issue,
        // permissions not yet propagated, etc.). Attempt recovery using the page token —
        // this works without any additional Instagram OAuth scopes.
        if (!$igUserId && $pageId && $pageToken) {
            error_log("MetaService: ig_user_id missing for account #{$account['id']}, attempting live lookup via page $pageId");
            $igUserId = self::fetchInstagramUserId($pageId, $pageToken);

            if ($igUserId) {
                // Persist so future publishes don't need to re-fetch
                $metaData['ig_user_id'] = $igUserId;
                try {
                    $db = getDB();
                    $db->prepare("UPDATE social_accounts SET meta_json = ? WHERE id = ?")
                        ->execute([json_encode($metaData), $account['id']]);
                    error_log("MetaService: ig_user_id $igUserId persisted for account #{$account['id']}");
                } catch (\Throwable $dbE) {
                    error_log("MetaService: could not persist ig_user_id: " . $dbE->getMessage());
                }
            }
        }

        if (!$igUserId) {
            throw new RuntimeException(
                'No Instagram Business account linked to this Facebook Page. ' .
                'In Facebook Page Settings → Instagram, link an Instagram Business or Creator account, then reconnect in Social Accounts.'
            );
        }

        if (empty($mediaUrls)) {
            throw new RuntimeException('Instagram requires at least one image. Add media to this post before publishing to Instagram.');
        }

        // When hashtags_in_comment is set, keep caption clean and post hashtags separately
        $firstComment    = !empty($post['hashtags_in_comment']) ? trim($post['hashtags'] ?? '') : '';
        $caption         = self::buildCaption($post, 2200, (bool)$firstComment);
        $mediaCount      = count($mediaUrls);

        if ($mediaCount === 1) {
            // Single image: create container → wait for processing → publish
            $container = self::graphPost("{$igUserId}/media", [
                'image_url'    => $mediaUrls[0],
                'caption'      => $caption,
                'access_token' => $pageToken,
            ]);
            self::assertNoError($container, 'Instagram media container creation');

            $creationId = $container['id'] ?? '';
            self::waitForContainerReady($creationId, $pageToken);

            $published  = self::graphPost("{$igUserId}/media_publish", [
                'creation_id'  => $creationId,
                'access_token' => $pageToken,
            ]);
            self::assertNoError($published, 'Instagram media publish');

            $mediaId = $published['id'] ?? '';

            // Post hashtags as first comment if flag is set
            if ($firstComment && $mediaId) {
                self::postInstagramComment($mediaId, $firstComment, $pageToken);
            }

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

        self::waitForContainerReady($carousel['id'], $pageToken);

        $published = self::graphPost("{$igUserId}/media_publish", [
            'creation_id'  => $carousel['id'],
            'access_token' => $pageToken,
        ]);
        self::assertNoError($published, 'Instagram carousel publish');

        $mediaId = $published['id'] ?? '';

        // Post hashtags as first comment if flag is set
        if ($firstComment && $mediaId) {
            self::postInstagramComment($mediaId, $firstComment, $pageToken);
        }

        return [
            'success'  => true,
            'post_id'  => $mediaId,
            'url'      => "https://www.instagram.com/p/{$mediaId}",
            'response' => $published,
        ];
    }

    /**
     * Post a comment on an Instagram media object (e.g. first-comment hashtags).
     * Non-fatal — logs on failure so a comment error never blocks the publish.
     * Requires instagram_manage_comments scope.
     */
    public static function postInstagramComment(string $igMediaId, string $text, string $pageToken): void
    {
        try {
            $result = self::graphPost("{$igMediaId}/comments", [
                'message'      => $text,
                'access_token' => $pageToken,
            ]);
            if (isset($result['error'])) {
                error_log('MetaService::postInstagramComment failed: ' . json_encode($result['error']));
            }
        } catch (\Throwable $e) {
            error_log('MetaService::postInstagramComment exception: ' . $e->getMessage());
        }
    }

    // ── Metrics ──────────────────────────────────────────────────────

    /**
     * Run an insights request and return name => value, or null if the request
     * could not be answered.
     *
     * Graph fails the ENTIRE request if any one metric in the list is invalid,
     * and Meta retires metric names on its own schedule (Instagram's
     * `impressions` on 2025-04-21; Facebook's `post_impressions` above v25). So
     * on failure this retries metric-by-metric and keeps whatever the live API
     * version still answers: a retired name costs you that one number instead of
     * the whole row.
     *
     * @param string[] $metrics
     * @return array<string,mixed>|null
     */
    private static function insights(string $objectId, array $metrics, string $token): ?array
    {
        $result = self::insightsRequest($objectId, $metrics, $token);
        if ($result !== null) {
            return $result;
        }

        if (count($metrics) <= 1) {
            return null;
        }

        $merged = [];
        $anyOk  = false;

        foreach ($metrics as $metric) {
            $one = self::insightsRequest($objectId, [$metric], $token);
            if ($one === null) {
                error_log("MetaService::insights — dropping metric '{$metric}' for {$objectId}; "
                    . 'the API version in use (' . self::graphVersion() . ') will not answer it.');
                continue;
            }
            $anyOk  = true;
            $merged = array_merge($merged, $one);
        }

        return $anyOk ? $merged : null;
    }

    /** @return array<string,mixed>|null */
    private static function insightsRequest(string $objectId, array $metrics, string $token): ?array
    {
        $url = self::graphUrl() . $objectId . '/insights?' . http_build_query([
            'metric'       => implode(',', $metrics),
            'access_token' => $token,
        ]);

        try {
            $data = self::httpGet($url);
        } catch (\Throwable $e) {
            error_log('MetaService insights transport error for ' . $objectId . ': ' . $e->getMessage());
            return null;
        }

        if (isset($data['error'])) {
            error_log('MetaService insights error for ' . $objectId . ' [' . implode(',', $metrics) . ']: '
                . json_encode($data['error']));
            return null;
        }

        $out = [];
        foreach ($data['data'] ?? [] as $item) {
            $name = $item['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $out[$name] = $item['values'][0]['value']
                ?? $item['total_value']['value']
                ?? $item['value']
                ?? 0;
        }

        return $out;
    }

    /** Sum a metric that Graph may return as either a scalar or a keyed array. */
    private static function flatten($value): int
    {
        return is_array($value) ? (int)array_sum($value) : (int)$value;
    }

    /**
     * Fetch engagement metrics for a published Facebook Page post.
     *
     * @return array|null null means the fetch FAILED. It must not be confused
     *         with a row of zeros, which means "published, no engagement" — the
     *         previous zero-on-error default is why Instagram metrics looked
     *         like a quiet audience for over a year instead of a broken call.
     */
    public static function fetchFacebookMetrics(string $platformPostId, string $pageToken): ?array
    {
        $metrics = self::insights($platformPostId, [
            'post_impressions',
            'post_impressions_unique',
            'post_clicks_by_type_unique',
            'post_reactions_by_type_total',
            'post_activity_by_action_type',
        ], $pageToken);

        if ($metrics === null) {
            return null;
        }

        $activity = $metrics['post_activity_by_action_type'] ?? [];

        return [
            'impressions'    => self::flatten($metrics['post_impressions'] ?? 0),
            'reach'          => self::flatten($metrics['post_impressions_unique'] ?? 0),
            'clicks'         => self::flatten($metrics['post_clicks_by_type_unique'] ?? 0),
            'likes'          => self::flatten($metrics['post_reactions_by_type_total'] ?? 0),
            'comments_count' => is_array($activity) ? (int)($activity['comment'] ?? 0) : 0,
            'shares'         => is_array($activity) ? (int)($activity['share'] ?? 0) : 0,
            'saves'          => 0, // Not exposed by Facebook post insights.
        ];
    }

    /**
     * Fetch engagement metrics for a published Instagram media post.
     *
     * `impressions` is NOT requested: Meta deprecated it for media insights on
     * 2025-04-21, and media created on/after 2024-07-02 returns an error for it.
     * `views` is the replacement and is what now lands in the impressions
     * column — the two are not the same measure (views runs ~25% higher), so
     * treat any Instagram impressions series as having a discontinuity here.
     *
     * @return array|null null means the fetch failed; see fetchFacebookMetrics().
     */
    public static function fetchInstagramMetrics(string $igMediaId, string $pageToken): ?array
    {
        $metrics = self::insights($igMediaId, [
            'views',
            'reach',
            'likes',
            'comments',
            'shares',
            'saved',
        ], $pageToken);

        if ($metrics === null) {
            return null;
        }

        return [
            'impressions'    => self::flatten($metrics['views'] ?? 0),
            'reach'          => self::flatten($metrics['reach'] ?? 0),
            'clicks'         => 0, // IG media insights do not expose link clicks.
            'likes'          => self::flatten($metrics['likes'] ?? 0),
            'comments_count' => self::flatten($metrics['comments'] ?? 0),
            'shares'         => self::flatten($metrics['shares'] ?? 0),
            'saves'          => self::flatten($metrics['saved'] ?? 0),
        ];
    }

    /**
     * Generic metrics fetch — routes to platform-specific method.
     * Kept for backward compatibility with SocialPublisher dispatch.
     */
    public static function fetchPostMetrics(string $platformPostId, string $accessToken): ?array
    {
        return self::fetchFacebookMetrics($platformPostId, $accessToken);
    }

    // ── Connection health ────────────────────────────────────────────

    /**
     * Can this account still reach the API with the credential we hold?
     *
     * Deliberately cheap (one Graph call) and ordered so the local failure is
     * reported without spending a request: a token that will not decrypt is a
     * key problem, and asking Meta about it tells you nothing.
     *
     * @return array{ok:bool,reason:string,detail:string}
     *         reason is one of: ok | no_token | decrypt_failed | api_error |
     *         instagram_unlinked
     */
    public static function checkTokenHealth(array $account): array
    {
        $platform = (string)($account['platform'] ?? 'facebook');

        try {
            $pageToken = self::ensureFreshToken($account);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            return [
                'ok'     => false,
                'reason' => str_contains($msg, 'could not be decrypted') ? 'decrypt_failed' : 'no_token',
                'detail' => $msg,
            ];
        }

        $pageId = (string)($account['account_id_external'] ?? '');
        if ($pageId === '') {
            return ['ok' => false, 'reason' => 'no_token', 'detail' => 'No Facebook Page ID stored for this account.'];
        }

        try {
            $data = self::httpGet(self::graphUrl() . $pageId . '?' . http_build_query([
                'fields'       => 'id,name',
                'access_token' => $pageToken,
            ]));
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'api_error', 'detail' => 'Graph unreachable: ' . $e->getMessage()];
        }

        if (isset($data['error'])) {
            return [
                'ok'     => false,
                'reason' => 'api_error',
                'detail' => 'Graph rejected the page token: ' . ($data['error']['message'] ?? json_encode($data['error'])),
            ];
        }

        if ($platform === 'instagram') {
            $meta = json_decode($account['meta_json'] ?? '{}', true) ?: [];
            if (empty($meta['ig_user_id']) && !self::fetchInstagramUserId($pageId, $pageToken)) {
                return [
                    'ok'     => false,
                    'reason' => 'instagram_unlinked',
                    'detail' => 'The page token works, but no Instagram Business account resolves from this Page.',
                ];
            }
        }

        return ['ok' => true, 'reason' => 'ok', 'detail' => 'Reached ' . ($data['name'] ?? $pageId) . '.'];
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
     *
     * @param bool $stripHashtags When true, hashtags are omitted (posted separately as first comment).
     */
    private static function buildCaption(array $post, int $maxLen, bool $stripHashtags = false): string
    {
        $caption = trim($post['caption'] ?? '');

        if (!$stripHashtags && !empty($post['hashtags'])) {
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
    /**
     * Poll an Instagram media container until status_code = FINISHED.
     *
     * Instagram processes the image asynchronously after container creation.
     * Publishing immediately (code 9007: Media ID is not available) is the
     * classic symptom of not waiting. We poll up to 6×5s = 30 seconds.
     * If the container errors out we throw; if it never finishes we proceed
     * anyway and let media_publish surface the real error.
     */
    private static function waitForContainerReady(string $containerId, string $accessToken): void
    {
        $maxAttempts = 6;
        $sleepSecs   = 5;

        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep($sleepSecs);

            $url    = self::graphUrl() . $containerId . '?' . http_build_query([
                'fields'       => 'status_code',
                'access_token' => $accessToken,
            ]);
            $status = self::httpGet($url);

            $code = $status['status_code'] ?? 'UNKNOWN';

            if ($code === 'FINISHED') {
                return;   // Ready to publish
            }

            if ($code === 'ERROR') {
                $errMsg = $status['status'] ?? 'unknown error';
                throw new RuntimeException(
                    "Instagram container processing failed (status=ERROR): {$errMsg}"
                );
            }

            // IN_PROGRESS / EXPIRED / UNKNOWN — keep waiting
            error_log("waitForContainerReady: attempt " . ($i + 1) . " status={$code} container={$containerId}");
        }

        // Timed out — proceed optimistically; media_publish will report the real issue
        error_log("waitForContainerReady: timed out after {$maxAttempts} attempts, proceeding anyway");
    }

    private static function graphPost(string $path, array $data): array
    {
        $url = self::graphUrl() . ltrim($path, '/');
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
