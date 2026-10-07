<?php
/**
 * GbpService — Google Business Profile for Mia: reviews, replies, local posts, photos, business info.
 *
 * Builds on GoogleBusinessService, which already owns the OAuth connect/callback
 * (/crm/api/social/accounts.php?action=oauth-init&platform=gbp → oauth-callback.php) and stores
 * the tokens in social_accounts (platform 'gbp') encrypted by SocialEncryption under the CURRENT
 * SOCIAL_ENCRYPTION_KEY. A token that will not decrypt is reported as such (SocialEncryption::
 * requireToken) — reconnect re-encrypts it; nothing tries to read it with an old key.
 *
 * Endpoints (developers.google.com/my-business, checked 2026-10-06):
 *   reviews   GET  https://mybusiness.googleapis.com/v4/accounts/{a}/locations/{l}/reviews
 *   reply     PUT  https://mybusiness.googleapis.com/v4/accounts/{a}/locations/{l}/reviews/{r}/reply  {comment}
 *   posts     POST https://mybusiness.googleapis.com/v4/accounts/{a}/locations/{l}/localPosts
 *   photos    POST https://mybusiness.googleapis.com/v4/accounts/{a}/locations/{l}/media  {mediaFormat, locationAssociation.category, sourceUrl}
 *   info      GET  https://mybusinessbusinessinformation.googleapis.com/v1/locations/{l}?readMask=…
 *
 * API ACCESS NEEDS GOOGLE'S APPROVAL: the project's Business Profile API quota is 0 QPM until
 * the request at https://support.google.com/business/contact/api_default ("Application for
 * Basic API Access") is approved. Until then — and until Tim flips ops_settings
 * gbp_api_approved to 1 — the module runs in DRAFTS ONLY mode: Mia drafts posts and review
 * replies with Copy buttons and nothing calls Google.
 *
 * Nothing here posts on its own: every write is called from Tim's click in the API.
 * No namespace / no autoloader in production.
 */
require_once __DIR__ . '/SocialEncryption.php';
require_once __DIR__ . '/GoogleBusinessService.php';

class GbpService
{
    public const ACCESS_FORM_URL = 'https://support.google.com/business/contact/api_default';
    public const CLOUD_CONSOLE_URL = 'https://console.cloud.google.com/apis/library/mybusinessbusinessinformation.googleapis.com';
    public const CONNECT_URL = '/crm/api/social/accounts.php?action=oauth-init&platform=gbp';
    private const V4 = 'https://mybusiness.googleapis.com/v4/';
    private const INFO = 'https://mybusinessbusinessinformation.googleapis.com/v1/';
    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The active GBP account row, or null. */
    public function account(): ?array
    {
        try {
            $r = $this->db->query("SELECT * FROM social_accounts WHERE platform = 'gbp' AND is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{mode: string, reason: string, steps: array<int, array{done: bool, text: string, url: ?string}>}
     *   mode: live (connected + approved) | drafts_only
     */
    public function mode(): array
    {
        $acct = $this->account();
        $approved = $this->setting('gbp_api_approved') === '1';
        $connected = $acct && !empty($acct['location_id_external']);
        $readable = false;
        if ($connected) {
            try {
                SocialEncryption::requireToken((string)($acct['refresh_token_enc'] ?: $acct['access_token_enc']), 'Google token');
                $readable = true;
            } catch (Throwable $e) {
                $readable = false;
            }
        }
        $steps = [
            ['done' => $approved, 'text' => "Request Business Profile API access from Google (choose 'Application for Basic API Access'). Approval usually takes a few days.", 'url' => self::ACCESS_FORM_URL],
            ['done' => $approved, 'text' => 'When Google approves, enable the Business Profile APIs in the Cloud project that holds the CRM\'s Google login, then tell Mia it is approved (Listings page).', 'url' => self::CLOUD_CONSOLE_URL],
            ['done' => $connected && $readable, 'text' => $connected && !$readable ? 'Reconnect Google Business Profile — the saved login can\'t be read.' : 'Connect Google Business Profile and pick the Mowology location.', 'url' => self::CONNECT_URL],
        ];
        if ($approved && $connected && $readable) {
            return ['mode' => 'live', 'reason' => 'Connected — replies and posts go to Google when you click Post.', 'steps' => $steps];
        }
        $why = !$approved ? 'Google has not approved API access yet' : (!$connected ? 'Google Business Profile is not connected' : 'the saved Google login can\'t be read');
        return ['mode' => 'drafts_only', 'reason' => 'Drafts only — ' . $why . '. Copy a draft and paste it into Google.', 'steps' => $steps];
    }

    // ── Pure helpers ──────────────────────────────────────────────────────

    /** "accounts/1/locations/2" → "locations/2" (Business Information v1 resource). */
    public static function infoName(string $locationName): string
    {
        return preg_match('#(locations/[^/]+)$#', $locationName, $m) ? $m[1] : $locationName;
    }

    public static function reviewsUrl(string $locationName): string
    {
        return self::V4 . trim($locationName, '/') . '/reviews?pageSize=50';
    }

    /** $reviewName is the review's full resource name: accounts/…/locations/…/reviews/… */
    public static function replyUrl(string $reviewName): string
    {
        return self::V4 . trim($reviewName, '/') . '/reply';
    }

    public static function mediaUrl(string $locationName): string
    {
        return self::V4 . trim($locationName, '/') . '/media';
    }

    public static function infoUrl(string $locationName): string
    {
        return self::INFO . self::infoName($locationName)
            . '?readMask=title,phoneNumbers,storefrontAddress,websiteUri,regularHours,categories';
    }

    public static function stars($v): int
    {
        return is_numeric($v) ? max(1, min(5, (int)$v)) : (self::STARS[strtoupper((string)$v)] ?? 0);
    }

    /** One API review → a gbp_reviews row. */
    public static function reviewRow(array $r): array
    {
        return [
            'external_id'   => (string)($r['name'] ?? ''),
            'reviewer_name' => (string)($r['reviewer']['displayName'] ?? ''),
            'rating'        => self::stars($r['starRating'] ?? 0),
            'comment'       => (string)($r['comment'] ?? ''),
            'review_date'   => substr((string)($r['createTime'] ?? ''), 0, 10) ?: null,
            'has_reply'     => !empty($r['reviewReply']['comment']),
            'reply_text'    => (string)($r['reviewReply']['comment'] ?? ''),
        ];
    }

    /** The local-post body Google wants. Summary capped at 1500 characters. */
    public static function postBody(string $summary, ?string $ctaType = null, ?string $ctaUrl = null, ?string $photoUrl = null): array
    {
        $summary = trim($summary);
        if (mb_strlen($summary) > 1500) $summary = mb_substr($summary, 0, 1497) . '...';
        $body = ['languageCode' => 'en', 'summary' => $summary, 'topicType' => 'STANDARD'];
        if ($ctaType === 'CALL') {
            $body['callToAction'] = ['actionType' => 'CALL'];
        } elseif ($ctaType && $ctaUrl) {
            $body['callToAction'] = ['actionType' => $ctaType, 'url' => $ctaUrl];
        }
        if ($photoUrl) $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $photoUrl]];
        return $body;
    }

    // ── Calls to Google (live mode only; each from Tim's click or the weekly import) ──

    /** @return array<int, array> reviewRow()s */
    public function listReviews(): array
    {
        $acct = $this->requireLive();
        $res = $this->call('GET', self::reviewsUrl((string)$acct['location_id_external']), $acct);
        return array_map([self::class, 'reviewRow'], $res['reviews'] ?? []);
    }

    public function replyToReview(string $reviewName, string $text): array
    {
        $acct = $this->requireLive();
        return $this->call('PUT', self::replyUrl($reviewName), $acct, ['comment' => $text]);
    }

    public function createLocalPost(string $summary, ?string $ctaType, ?string $ctaUrl, ?string $photoUrl = null): array
    {
        $acct = $this->requireLive();
        return $this->call('POST', self::V4 . trim((string)$acct['location_id_external'], '/') . '/localPosts', $acct,
            self::postBody($summary, $ctaType, $ctaUrl, $photoUrl));
    }

    /** $category: COVER | PROFILE | LOGO | EXTERIOR | INTERIOR | AT_WORK | TEAMS | ADDITIONAL … */
    public function uploadPhoto(string $publicUrl, string $category = 'AT_WORK'): array
    {
        $acct = $this->requireLive();
        return $this->call('POST', self::mediaUrl((string)$acct['location_id_external']), $acct,
            ['mediaFormat' => 'PHOTO', 'locationAssociation' => ['category' => $category], 'sourceUrl' => $publicUrl]);
    }

    public function businessInfo(): array
    {
        $acct = $this->requireLive();
        return $this->call('GET', self::infoUrl((string)$acct['location_id_external']), $acct);
    }

    /**
     * Pull reviews into gbp_reviews; new ones without a reply get Mia's draft.
     * @return int new reviews imported
     */
    public function importReviews(array $ctx): int
    {
        require_once dirname(__DIR__, 2) . '/Marketing/Services/ReviewReplyDrafter.php';
        $new = 0;
        foreach ($this->listReviews() as $r) {
            if ($r['external_id'] === '') continue;
            $s = $this->db->prepare("SELECT id FROM gbp_reviews WHERE external_id = ?");
            $s->execute([$r['external_id']]);
            if ($s->fetchColumn()) continue;
            $this->db->prepare("
                INSERT INTO gbp_reviews (source, external_id, reviewer_name, rating, comment, review_date, reply_draft, reply_text, status, replied_at)
                VALUES ('api', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $r['external_id'], $r['reviewer_name'], $r['rating'], $r['comment'], $r['review_date'],
                $r['has_reply'] ? null : ReviewReplyDrafter::draft(['id' => $r['external_id']] + $r, $ctx),
                $r['has_reply'] ? $r['reply_text'] : null,
                $r['has_reply'] ? 'replied' : 'waiting',
                $r['has_reply'] ? date('Y-m-d H:i:s') : null,
            ]);
            $new++;
        }
        return $new;
    }

    private function requireLive(): array
    {
        if ($this->mode()['mode'] !== 'live') {
            throw new RuntimeException('Google Business Profile is in drafts-only mode — copy the draft into Google instead.');
        }
        return (array)$this->account();
    }

    private function call(string $method, string $url, array $acct, ?array $body = null): array
    {
        $token = GoogleBusinessService::ensureFreshToken($acct);
        $ch = curl_init($url);
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                 CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json']];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Google unreachable: ' . $err);
        $data = json_decode((string)$raw, true) ?: [];
        if ($code >= 400 || isset($data['error'])) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $code);
            if ($code === 429 || stripos((string)$msg, 'quota') !== false) {
                $msg .= ' — the API quota is 0 until Google approves access (' . self::ACCESS_FORM_URL . ').';
            }
            throw new RuntimeException('Google Business Profile: ' . $msg);
        }
        return $data;
    }

    private function setting(string $key): string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            return (string)($s->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return '';
        }
    }
}
